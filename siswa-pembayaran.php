<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$kursusFokus = num('kursus');

/* ------------------------------------------------------------ aksi siswa */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $courseId = (int)post('course_id');

    if ($aksi === 'upload_bukti' && $courseId > 0) {
        $stmt = db()->prepare('SELECT * FROM courses WHERE id = ?');
        $stmt->execute([$courseId]);
        $c = $stmt->fetch();
        if (!$c || !kursus_berbayar($c)) {
            flash('Kursus ini tidak memerlukan pembayaran.', 'warning');
            redirect('siswa-pembayaran.php');
        }
        // pastikan siswa terdaftar sebagai menunggu bayar
        $e = db()->prepare('SELECT status FROM enrollments WHERE user_id = ? AND course_id = ?');
        $e->execute([$u['id'], $courseId]);
        $st = $e->fetchColumn();
        if (!$st) {
            db()->prepare('INSERT INTO enrollments (user_id, course_id, status) VALUES (?, ?, "menunggu_bayar")')->execute([$u['id'], $courseId]);
            $st = 'menunggu_bayar';
        }
        if (in_array($st, ['aktif', 'selesai'], true)) {
            flash('Kamu sudah punya akses ke kursus ini.', 'info');
            redirect('kursus-detail.php?id=' . $courseId);
        }
        // jangan sampai ada dua pengajuan menunggu untuk kursus yang sama
        $m = db()->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND course_id = ? AND status = 'menunggu'");
        $m->execute([$u['id'], $courseId]);
        if ((int)$m->fetchColumn() > 0) {
            flash('Pengajuan pembayaranmu untuk kursus ini masih menunggu verifikasi admin.', 'warning');
            redirect('siswa-pembayaran.php?kursus=' . $courseId);
        }
        if (empty($_FILES['bukti']['name'])) {
            flash('Unggah bukti transfer terlebih dahulu.', 'warning');
            redirect('siswa-pembayaran.php?kursus=' . $courseId);
        }
        try {
            $bukti = handle_upload('bukti');
        } catch (Throwable $ex) {
            flash('Upload bukti gagal: ' . $ex->getMessage(), 'danger');
            redirect('siswa-pembayaran.php?kursus=' . $courseId);
        }
        $jumlah = (float)str_replace(['.', ','], ['', '.'], post('jumlah', (string)$c['harga']));
        if ($jumlah <= 0) {
            $jumlah = (float)$c['harga'];
        }
        db()->prepare('INSERT INTO payments (user_id, course_id, jumlah, metode, bukti, status, metode_bayar, nomor_referensi, catatan) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$u['id'], $courseId, $jumlah, 'transfer', $bukti, 'menunggu', post('metode_bayar', 'Transfer bank'), post('nomor_referensi'), post('catatan')]);
        $pid = (int)db()->lastInsertId();
        // beri tahu semua admin
        $admins = db()->query("SELECT id FROM users WHERE role = 'admin' AND status = 'aktif'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $aid) {
            notify((int)$aid, 'Pembayaran baru menunggu verifikasi', $u['nama'] . ' mengirim bukti pembayaran untuk kursus "' . $c['judul'] . '" sebesar ' . rupiah($jumlah) . '.', 'admin-pembayaran.php?status=menunggu');
        }
        audit('kirim_pembayaran', 'Mengirim bukti pembayaran kursus ' . $c['judul'] . ' sebesar ' . rupiah($jumlah));
        flash('Bukti pembayaran terkirim. Tunggu verifikasi admin, ya!');
        redirect('siswa-pembayaran.php');
    }

    if ($aksi === 'hapus_pengajuan') {
        $pid = (int)post('id');
        $p = db()->prepare("SELECT * FROM payments WHERE id = ? AND user_id = ? AND status = 'menunggu'");
        $p->execute([$pid, $u['id']]);
        $bayar = $p->fetch();
        if ($bayar) {
            db()->prepare('DELETE FROM payments WHERE id = ?')->execute([$pid]);
            audit('hapus_pengajuan_bayar', 'Menghapus pengajuan pembayaran #' . $pid);
            flash('Pengajuan pembayaran dibatalkan. Kamu bisa mengunggah bukti baru.');
        }
        redirect('siswa-pembayaran.php');
    }
    redirect('siswa-pembayaran.php');
}

if (get('export') === 'csv') {
    $rows = db()->query("SELECT p.created_at, co.judul, p.jumlah, p.metode_bayar, p.nomor_referensi, p.status, p.catatan, p.verified_at
        FROM payments p LEFT JOIN courses co ON co.id = p.course_id WHERE p.user_id = " . (int)$u['id'] . ' ORDER BY p.id DESC')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [tgl_id($r['created_at'], true), $r['judul'], number_format((float)$r['jumlah'], 0, ',', '.'), $r['metode_bayar'], $r['nomor_referensi'], $r['status'], $r['catatan'], $r['verified_at'] ? tgl_id($r['verified_at'], true) : ''];
    }
    audit('export_pembayaran_siswa', 'Mengunduh riwayat pembayaran (CSV)');
    csv_download('riwayat-pembayaran-' . date('Ymd') . '.csv', ['Tanggal', 'Kursus', 'Jumlah', 'Metode', 'Referensi', 'Status', 'Catatan Admin', 'Diverifikasi'], $out);
}

/* --------------------------------------------------------------- data */
// Tagihan: kursus berbayar yang diikuti siswa tapi belum terbuka
$tagihan = db()->query("SELECT e.status AS status_daftar, co.*, c.nama AS kategori,
        (SELECT p.status FROM payments p WHERE p.user_id = e.user_id AND p.course_id = co.id ORDER BY p.id DESC LIMIT 1) AS status_bayar,
        (SELECT p.catatan FROM payments p WHERE p.user_id = e.user_id AND p.course_id = co.id ORDER BY p.id DESC LIMIT 1) AS catatan_bayar,
        (SELECT p.jumlah FROM payments p WHERE p.user_id = e.user_id AND p.course_id = co.id ORDER BY p.id DESC LIMIT 1) AS jumlah_bayar
    FROM enrollments e JOIN courses co ON co.id = e.course_id
    LEFT JOIN categories c ON c.id = co.kategori_id
    WHERE e.user_id = " . (int)$u['id'] . " AND e.status NOT IN ('aktif','selesai') AND co.harga > 0
    ORDER BY (SELECT p.id FROM payments p WHERE p.user_id = e.user_id AND p.course_id = co.id ORDER BY p.id DESC LIMIT 1) DESC, co.judul")->fetchAll();

// Kursus berbayar yang tersedia dan belum diikuti sama sekali
$belumDaftar = db()->query("SELECT co.*, c.nama AS kategori FROM courses co
    LEFT JOIN categories c ON c.id = co.kategori_id
    WHERE co.status = 'aktif' AND co.harga > 0
      AND co.id NOT IN (SELECT course_id FROM enrollments WHERE user_id = " . (int)$u['id'] . ")
    ORDER BY co.judul")->fetchAll();

// Kursus berbayar yang aksesnya sudah terbuka (pembayaran terverifikasi)
$sudahAktif = db()->query("SELECT co.id, co.judul, co.level, e.progress,
        (SELECT p.jumlah FROM payments p WHERE p.user_id = e.user_id AND p.course_id = co.id AND p.status = 'terverifikasi' ORDER BY p.id DESC LIMIT 1) AS dibayar,
        (SELECT p.verified_at FROM payments p WHERE p.user_id = e.user_id AND p.course_id = co.id AND p.status = 'terverifikasi' ORDER BY p.id DESC LIMIT 1) AS tgl_verifikasi
    FROM enrollments e JOIN courses co ON co.id = e.course_id
    WHERE e.user_id = " . (int)$u['id'] . " AND e.status IN ('aktif','selesai') AND co.harga > 0
    ORDER BY co.judul")->fetchAll();

$riwayat = db()->query("SELECT p.*, co.judul AS kursus, us.nama AS verifikator
    FROM payments p LEFT JOIN courses co ON co.id = p.course_id LEFT JOIN users us ON us.id = p.verified_by
    WHERE p.user_id = " . (int)$u['id'] . " ORDER BY p.id DESC")->fetchAll();

$menungguVerifikasi = 0;
$totalTerverifikasi = 0;
foreach ($riwayat as $r) {
    if ($r['status'] === 'menunggu') {
        $menungguVerifikasi++;
    }
    if ($r['status'] === 'terverifikasi') {
        $totalTerverifikasi += (float)$r['jumlah'];
    }
}
$rekening = rekening_list();
$kursusFokusData = null;
$kursusSudahTerbuka = null;
foreach ($tagihan as $t) {
    if ((int)$t['id'] === $kursusFokus) {
        $kursusFokusData = $t;
    }
}
if (!$kursusFokusData && $kursusFokus > 0) {
    // ?kursus=N boleh menunjuk kursus berbayar; kalau aksesnya sudah terbuka,
    // tampilkan keterangan saja (tanpa instruksi transfer & form unggah).
    $s = db()->prepare('SELECT * FROM courses WHERE id = ? AND harga > 0');
    $s->execute([$kursusFokus]);
    $kandidat = $s->fetch();
    if ($kandidat) {
        if (cek_akses_kursus((int)$u['id'], $kandidat)['boleh']) {
            $kursusSudahTerbuka = $kandidat;
        } else {
            $kandidat['status_daftar'] = 'menunggu_bayar';
            $kandidat['status_bayar'] = null;
            $kandidat['catatan_bayar'] = null;
            $kursusFokusData = $kandidat;
        }
    }
}
if (!$kursusFokusData && $tagihan) {
    $kursusFokusData = $tagihan[0];
}

page_start('Pembayaran Kursus', 'Selesaikan pembayaran untuk membuka akses materi dan quiz.', [
    'actions' => '<a class="btn btn-ghost" href="kursus.php">' . icon('grid', 17) . ' Katalog Kursus</a>',
]);
?>
<?php if (!pembayaran_aktif()): ?>
  <div class="flash flash-info mb3"><?= icon('bell', 17) ?><span>Modul pembayaran sedang dinonaktifkan admin, sehingga semua kursus dapat diakses tanpa pembayaran.</span></div>
<?php endif; ?>

<div class="grid cols-3 mb3">
  <div class="stat"><div class="stat-ic g6"><?= icon('receipt', 22) ?></div><div><div class="stat-num"><?= count($tagihan) ?></div><div class="stat-lab">Tagihan belum lunas</div></div></div>
  <div class="stat"><div class="stat-ic g4"><?= icon('clock', 22) ?></div><div><div class="stat-num"><?= $menungguVerifikasi ?></div><div class="stat-lab">Menunggu verifikasi admin</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= rupiah($totalTerverifikasi) ?></div><div class="stat-lab">Total pembayaran terverifikasi</div></div></div>
</div>

<?php if ($tagihan): ?>
<div class="card">
  <div class="card-head"><h2 class="mt0"><?= icon('wallet', 20) ?> Tagihan Kamu</h2><span class="muted small"><?= count($tagihan) ?> kursus menunggu pembayaran</span></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Kursus</th><th>Harga</th><th>Status</th><th>Catatan admin</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tagihan as $t): ?>
        <tr>
          <td><strong><?= e($t['judul']) ?></strong><div class="muted small"><?= e($t['kategori'] ?: 'Umum') ?> &middot; <?= e($t['level']) ?></div></td>
          <td class="price nowrap"><?= rupiah($t['harga']) ?></td>
          <td>
            <?php if ($t['status_bayar'] === 'menunggu'): ?><span class="badge badge-warning">Menunggu verifikasi</span>
            <?php elseif ($t['status_bayar'] === 'terverifikasi'): ?><span class="badge badge-success">Terverifikasi</span>
            <?php elseif ($t['status_bayar'] === 'ditolak'): ?><span class="badge badge-danger">Ditolak — unggah ulang</span>
            <?php else: ?><span class="badge badge-muted">Belum bayar</span><?php endif; ?>
          </td>
          <td class="small muted"><?= e($t['catatan_bayar'] ?: '-') ?></td>
          <td class="actions">
            <?php if ($t['status_bayar'] === 'menunggu'): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="hapus_pengajuan">
                <input type="hidden" name="id" value="<?= (int)db()->query('SELECT id FROM payments WHERE user_id = ' . (int)$u['id'] . ' AND course_id = ' . (int)$t['id'] . ' ORDER BY id DESC LIMIT 1')->fetchColumn() ?>">
                <button class="btn btn-ghost btn-sm" data-confirm="Batalkan pengajuan pembayaran ini?"><?= icon('refresh', 14) ?> Ganti bukti</button>
              </form>
            <?php else: ?>
              <button class="btn btn-primary btn-sm" data-modal-open="modal-bayar-<?= (int)$t['id'] ?>"><?= icon('upload', 14) ?> <?= $t['status_bayar'] === 'ditolak' ? 'Unggah Ulang Bukti' : 'Bayar Sekarang' ?></button>
            <?php endif; ?>
            <a class="btn btn-ghost btn-sm" href="kursus-detail.php?id=<?= (int)$t['id'] ?>">Detail</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="split">
  <div>
    <?php if ($kursusSudahTerbuka): ?>
      <div class="card">
        <div class="card-head"><h2 class="mt0"><?= icon('check', 20) ?> Akses Sudah Terbuka</h2></div>
        <div class="flash flash-success mb3"><?= icon('check', 17) ?><span>Kursus <strong><?= e($kursusSudahTerbuka['judul']) ?></strong> sudah bisa kamu akses. Tidak perlu pembayaran lagi.</span></div>
        <div class="row-gap">
          <a class="btn btn-primary" href="materi.php?kursus=<?= (int)$kursusSudahTerbuka['id'] ?>"><?= icon('play', 17) ?> Mulai Belajar</a>
          <a class="btn btn-ghost" href="kursus-detail.php?id=<?= (int)$kursusSudahTerbuka['id'] ?>">Lihat Silabus</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($kursusFokusData): ?>
      <div class="card">
        <div class="card-head">
          <h2 class="mt0"><?= icon('bank', 20) ?> Cara Pembayaran — <?= e($kursusFokusData['judul']) ?></h2>
          <span class="price"><?= rupiah($kursusFokusData['harga']) ?></span>
        </div>
        <?php if ($tagihan): ?>
          <div class="toolbar">
            <div class="form-group" style="min-width:260px">
              <label>Pilih tagihan</label>
              <select data-autosubmit-js>
                <?php foreach ($tagihan as $t): ?>
                  <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === (int)$kursusFokusData['id'] ? 'selected' : '' ?>><?= e($t['judul']) ?> — <?= rupiah($t['harga']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        <?php endif; ?>

        <div class="stack mb3">
          <?php foreach ($rekening as $r): ?>
            <div class="notice">
              <div class="notice-ic"><?= icon('bank', 17) ?></div>
              <div style="flex:1">
                <h4><?= e($r['bank']) ?></h4>
                <p><strong style="font-size:1.05rem;letter-spacing:.02em"><?= e($r['nomor']) ?></strong><br>
                  a.n. <?= e($r['nama'] ?: setting('site_name')) ?></p>
              </div>
              <button class="btn btn-ghost btn-sm" data-copy="<?= e($r['nomor']) ?>"><?= icon('file', 14) ?> Salin</button>
            </div>
          <?php endforeach; ?>
          <?php if (!$rekening): ?>
            <div class="flash flash-warning"><?= icon('bell', 17) ?><span>Admin belum mengisi nomor rekening tujuan transfer. Hubungi <?= e(setting('site_email')) ?> untuk info pembayaran.</span></div>
          <?php endif; ?>
        </div>

        <?php if (setting('bayar_instruksi') !== ''): ?>
          <h3><?= icon('list', 18) ?> Instruksi</h3>
          <div class="reading small"><?= nl2br(e(setting('bayar_instruksi'))) ?></div>
        <?php endif; ?>

        <?php if (setting('bayar_kontak') !== ''): ?>
          <div class="dark-box mt2">Butuh bantuan atau mau konfirmasi transfer? Hubungi <strong><?= e(setting('bayar_kontak')) ?></strong>.</div>
        <?php endif; ?>

        <div class="divider"></div>
        <h3><?= icon('upload', 18) ?> Unggah Bukti Transfer</h3>
        <p class="muted small">Setelah transfer, unggah foto/screenshot bukti transfer (JPG, PNG, atau PDF — maks 15MB). Admin akan memverifikasi, lalu akses materi terbuka otomatis.</p>
        <button class="btn btn-primary" data-modal-open="modal-bayar-<?= (int)$kursusFokusData['id'] ?>"><?= icon('upload', 17) ?> Unggah Bukti Pembayaran</button>
      </div>
    <?php elseif (!$tagihan): ?>
      <div class="card">
        <?= empty_state('Tidak ada tagihan pembayaran. Semua kursus yang kamu ikuti sudah lunas. Terima kasih!', 'wallet') ?>
      </div>
    <?php endif; ?>

    <?php if ($sudahAktif): ?>
      <div class="card">
        <div class="card-head"><h2 class="mt0"><?= icon('check', 20) ?> Kursus Berbayar yang Sudah Aktif</h2><span class="muted small"><?= count($sudahAktif) ?> kursus</span></div>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Kursus</th><th>Dibayar</th><th>Diverifikasi</th><th>Progress</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sudahAktif as $sa): ?>
              <tr>
                <td><strong><?= e($sa['judul']) ?></strong><div class="muted small"><?= e($sa['level']) ?></div></td>
                <td class="nowrap"><?= $sa['dibayar'] !== null ? rupiah($sa['dibayar']) : 'Ditandai admin' ?></td>
                <td class="small nowrap"><?= $sa['tgl_verifikasi'] ? e(tgl_id($sa['tgl_verifikasi'])) : '-' ?></td>
                <td style="min-width:140px">
                  <div class="progress-lab"><span><?= round((float)$sa['progress']) ?>%</span></div>
                  <div class="progress thin"><span style="width:<?= round((float)$sa['progress']) ?>%"></span></div>
                </td>
                <td><a class="btn btn-primary btn-sm" href="materi.php?kursus=<?= (int)$sa['id'] ?>"><?= icon('play', 14) ?> Belajar</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head">
        <h2 class="mt0"><?= icon('receipt', 20) ?> Riwayat Pembayaran</h2>
        <a class="btn btn-ghost btn-sm" href="siswa-pembayaran.php?export=csv"><?= icon('download', 15) ?> Export CSV</a>
      </div>
      <?php if (!$riwayat): ?>
        <p class="muted small mb0">Belum ada riwayat pembayaran.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Tanggal</th><th>Kursus</th><th>Jumlah</th><th>Metode</th><th>Status</th><th>Catatan admin</th><th>Bukti</th></tr></thead>
          <tbody>
          <?php foreach ($riwayat as $r): ?>
            <tr>
              <td class="small nowrap"><?= e(tgl_id($r['created_at'], true)) ?></td>
              <td><?= e($r['kursus'] ?: '-') ?></td>
              <td class="nowrap"><?= rupiah($r['jumlah']) ?></td>
              <td class="small"><?= e($r['metode_bayar'] ?: $r['metode'] ?: 'Transfer') ?><?= $r['nomor_referensi'] ? '<div class="muted small">Ref: ' . e($r['nomor_referensi']) . '</div>' : '' ?></td>
              <td><?= status_badge($r['status']) ?><?= $r['verified_at'] ? '<div class="muted small">' . e(tgl_id($r['verified_at'])) . '</div>' : '' ?></td>
              <td class="small muted"><?= e($r['catatan'] ?: '-') ?></td>
              <td><?php if ($r['bukti']): ?><a class="btn btn-ghost btn-sm" href="<?= e($r['bukti']) ?>" target="_blank" rel="noopener"><?= icon('file', 14) ?> Lihat</a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <aside>
    <?php if ($belumDaftar): ?>
      <div class="card">
        <h3 class="mt0"><?= icon('book', 18) ?> Kursus Berbayar Lainnya</h3>
        <p class="muted small">Pilih kursus untuk melihat detail dan mulai pembayaran.</p>
        <?php foreach ($belumDaftar as $b): ?>
          <div class="lesson-item mb2">
            <span class="lesson-ic"><?= icon('book', 17) ?></span>
            <span class="lesson-meta"><strong><?= e($b['judul']) ?></strong><small><?= rupiah($b['harga']) ?> &middot; <?= e($b['level']) ?></small></span>
            <span style="margin-left:auto"><a class="btn btn-primary btn-sm" href="kursus-detail.php?id=<?= (int)$b['id'] ?>">Lihat</a></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="card">
      <h3 class="mt0"><?= icon('shield', 18) ?> Catatan Penting</h3>
      <ul class="muted small">
        <li>Akses materi &amp; quiz terbuka <strong>otomatis</strong> begitu admin memverifikasi pembayaranmu.</li>
        <li>Simpan bukti transfer asli sampai akses terbuka.</li>
        <li>Nominal transfer harus sama dengan harga kursus agar cepat diverifikasi.</li>
        <li>Kesalahan nominal tidak otomatis ditolak — admin akan memberi catatan bila perlu.</li>
      </ul>
    </div>
    <div class="card">
      <h3 class="mt0"><?= icon('chat', 18) ?> Butuh Bantuan?</h3>
      <div class="kv">
        <dt>Email</dt><dd><?= e(setting('site_email')) ?></dd>
        <dt>Telepon/WA</dt><dd><?= e(setting('site_telepon')) ?></dd>
      </div>
    </div>
  </aside>
</div>

<?php
// modal unggah bukti untuk setiap tagihan
$modalKursus = $tagihan;
if ($kursusFokusData && !in_array((int)$kursusFokusData['id'], array_map(function ($t) { return (int)$t['id']; }, $modalKursus), true)) {
    $modalKursus[] = $kursusFokusData;
}
foreach ($modalKursus as $m):
    if (in_array($m['status_daftar'] ?? '', ['aktif', 'selesai'], true)) {
        continue;
    }
    if (cek_akses_kursus((int)$u['id'], (int)$m['id'])['boleh']) {
        continue; // sudah punya akses, tidak perlu unggah bukti
    }
    if (($m['status_bayar'] ?? null) === 'menunggu') {
        continue; // sedang menunggu verifikasi
    } ?>
  <div class="modal-back" id="modal-bayar-<?= (int)$m['id'] ?>">
    <div class="modal" style="max-width:560px">
      <div class="modal-head">
        <h3 class="mt0">Unggah Bukti Pembayaran</h3>
        <button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button>
      </div>
      <p class="muted small">Kursus: <strong><?= e($m['judul']) ?></strong> &middot; tagihan <strong><?= rupiah($m['harga']) ?></strong></p>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="upload_bukti">
        <input type="hidden" name="course_id" value="<?= (int)$m['id'] ?>">
        <div class="form-grid">
          <div class="form-group">
            <label>Nominal transfer *</label>
            <input type="text" name="jumlah" value="<?= e((string)(int)$m['harga']) ?>" required>
            <div class="form-hint">Isi sesuai jumlah yang kamu transfer.</div>
          </div>
          <div class="form-group">
            <label>Metode</label>
            <select name="metode_bayar">
              <option value="Transfer bank">Transfer bank</option>
              <option value="Transfer e-wallet">Transfer e-wallet</option>
              <option value="Tunai">Tunai (bayar di tempat)</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Nomor referensi / berita transfer</label>
          <input type="text" name="nomor_referensi" placeholder="mis. TRF20260925XXXX">
        </div>
        <div class="form-group">
          <label>Bukti transfer (JPG/PNG/PDF) *</label>
          <input type="file" name="bukti" accept="image/*,.pdf" required>
          <div class="form-hint">Screenshot/foto struk yang jelas agar cepat diverifikasi.</div>
        </div>
        <div class="form-group">
          <label>Keterangan tambahan</label>
          <textarea name="catatan" placeholder="mis. ditransfer oleh orang tua atas nama ..."></textarea>
        </div>
        <div class="row-gap">
          <button class="btn btn-primary" type="submit"><?= icon('upload', 17) ?> Kirim Bukti</button>
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
<script>
// dropdown pilih tagihan -> pindah fokus kursus
document.querySelectorAll('[data-autosubmit-js]').forEach(function (sel) {
  sel.addEventListener('change', function () {
    window.location.href = 'siswa-pembayaran.php?kursus=' + sel.value;
  });
});
</script>
<?php page_end(); ?>
