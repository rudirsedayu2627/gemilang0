<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login('admin');

/* ------------------------------------------------------------- aksi admin */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');

    if (($aksi === 'verifikasi' || $aksi === 'tolak') && $id > 0) {
        $stmt = db()->prepare('SELECT p.*, us.nama, c.judul AS kursus, c.harga FROM payments p
            LEFT JOIN users us ON us.id = p.user_id LEFT JOIN courses c ON c.id = p.course_id WHERE p.id = ?');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) {
            flash('Data pembayaran tidak ditemukan.', 'danger');
            redirect('admin-pembayaran.php');
        }
        $catatan = post('catatan');
        if ($aksi === 'verifikasi') {
            db()->prepare("UPDATE payments SET status = 'terverifikasi', verified_by = ?, verified_at = CURRENT_TIMESTAMP, catatan = ? WHERE id = ?")
                ->execute([$u['id'], $catatan, $id]);
            // buka akses: pendaftaran jadi aktif (+ pastikan terdaftar)
            db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id, status) VALUES (?, ?, "aktif")')->execute([$p['user_id'], $p['course_id']]);
            db()->prepare("UPDATE enrollments SET status = 'aktif' WHERE user_id = ? AND course_id = ? AND status NOT IN ('aktif','selesai')")->execute([$p['user_id'], $p['course_id']]);
            recalc_course_progress((int)$p['user_id'], (int)$p['course_id']);
            notify($p['user_id'], 'Pembayaran diverifikasi', 'Pembayaranmu untuk kursus "' . $p['kursus'] . '" sudah diverifikasi. Akses materi & quiz kini terbuka!', 'kursus-detail.php?id=' . (int)$p['course_id']);
            audit('verifikasi_pembayaran', 'Verifikasi pembayaran #' . $id . ' (' . $p['nama'] . ' - ' . $p['kursus'] . ')');
            flash('Pembayaran diverifikasi. Akses kursus untuk ' . $p['nama'] . ' sudah dibuka.');
        } else {
            db()->prepare("UPDATE payments SET status = 'ditolak', verified_by = ?, verified_at = CURRENT_TIMESTAMP, catatan = ? WHERE id = ?")
                ->execute([$u['id'], $catatan, $id]);
            notify($p['user_id'], 'Pembayaran ditolak', 'Bukti pembayaranmu untuk kursus "' . $p['kursus'] . '" ditolak' . ($catatan !== '' ? ': ' . $catatan : '.') . ' Silakan unggah ulang bukti yang benar.', 'siswa-pembayaran.php?kursus=' . (int)$p['course_id']);
            audit('tolak_pembayaran', 'Menolak pembayaran #' . $id . ' (' . $p['nama'] . ' - ' . $p['kursus'] . ')');
            flash('Pembayaran ditolak. Siswa sudah dinotifikasi untuk mengunggah ulang.');
        }
        redirect('admin-pembayaran.php?status=' . ($aksi === 'verifikasi' ? 'terverifikasi' : 'ditolak'));
    }

    if ($aksi === 'hapus' && $id > 0) {
        db()->prepare('DELETE FROM payments WHERE id = ?')->execute([$id]);
        audit('hapus_pembayaran', 'Menghapus data pembayaran #' . $id);
        flash('Data pembayaran dihapus.');
        redirect('admin-pembayaran.php');
    }

    if ($aksi === 'tambah_manual') {
        $uid = (int)post('user_id');
        $cid = (int)post('course_id');
        if ($uid && $cid) {
            $c = db()->prepare('SELECT judul, harga FROM courses WHERE id = ?');
            $c->execute([$cid]);
            $kursus = $c->fetch();
            $jumlah = (float)str_replace(['.', ','], ['', '.'], post('jumlah', (string)($kursus['harga'] ?? 0)));
            $langsung = post('langsung_verifikasi') ? 1 : 0;
            db()->prepare('INSERT INTO payments (user_id, course_id, jumlah, metode, bukti, status, metode_bayar, nomor_referensi, catatan, verified_by, verified_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $uid, $cid, $jumlah, 'manual', post('bukti_url') ?: null,
                    $langsung ? 'terverifikasi' : 'menunggu',
                    post('metode_bayar', 'Tunai'), post('nomor_referensi'), post('catatan'),
                    $langsung ? $u['id'] : null, $langsung ? date('Y-m-d H:i:s') : null,
                ]);
            $pid = (int)db()->lastInsertId();
            if ($langsung) {
                db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id, status) VALUES (?, ?, "aktif")')->execute([$uid, $cid]);
                db()->prepare("UPDATE enrollments SET status = 'aktif' WHERE user_id = ? AND course_id = ? AND status NOT IN ('aktif','selesai')")->execute([$uid, $cid]);
                recalc_course_progress($uid, $cid);
                notify($uid, 'Pembayaran diterima', 'Pembayaranmu untuk kursus "' . ($kursus['judul'] ?? '') . '" dicatat admin dan sudah diverifikasi. Akses terbuka!', 'kursus-detail.php?id=' . $cid);
                flash('Pembayaran manual dicatat & akses kursus langsung dibuka.');
            } else {
                db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id, status) VALUES (?, ?, "menunggu_bayar")')->execute([$uid, $cid]);
                notify($uid, 'Tagihan pembayaran dibuat', 'Admin membuat tagihan untuk kursus "' . ($kursus['judul'] ?? '') . '". Silakan selesaikan pembayaran.', 'siswa-pembayaran.php?kursus=' . $cid);
                flash('Tagihan manual dibuat (status menunggu).');
            }
            audit('tambah_pembayaran_manual', 'Mencatat pembayaran #' . $pid . ' untuk user ' . $uid . ' kursus ' . $cid);
        } else {
            flash('Pilih siswa dan kursus terlebih dahulu.', 'warning');
        }
        redirect('admin-pembayaran.php');
    }

    if ($aksi === 'buka_akses' && $id > 0) {
        // buka akses tanpa pembayaran (beasiswa/promo)
        $p = db()->prepare('SELECT * FROM payments WHERE id = ?');
        $p->execute([$id]);
        $row = $p->fetch();
        if ($row) {
            db()->prepare("UPDATE enrollments SET status = 'aktif' WHERE user_id = ? AND course_id = ?")->execute([$row['user_id'], $row['course_id']]);
            db()->prepare("UPDATE payments SET status = 'terverifikasi', verified_by = ?, verified_at = CURRENT_TIMESTAMP, catatan = COALESCE(NULLIF(catatan,''), 'Dibuka manual oleh admin') WHERE id = ?")->execute([$u['id'], $id]);
            recalc_course_progress((int)$row['user_id'], (int)$row['course_id']);
            notify($row['user_id'], 'Akses kursus dibuka', 'Admin membuka akses kursusmu tanpa menunggu verifikasi ulang.', 'kursus-detail.php?id=' . (int)$row['course_id']);
            audit('buka_akses_manual', 'Membuka akses manual untuk pembayaran #' . $id);
            flash('Akses kursus dibuka secara manual.');
        }
        redirect('admin-pembayaran.php');
    }
    redirect('admin-pembayaran.php');
}

/* ---------------------------------------------------------------- data */
$statusF = get('status');
$kursusF = num('kursus');
$q = get('q');

$where = ['1=1'];
$args = [];
if (in_array($statusF, ['menunggu', 'terverifikasi', 'ditolak'], true)) {
    $where[] = 'p.status = ?';
    $args[] = $statusF;
}
if ($kursusF > 0) {
    $where[] = 'p.course_id = ?';
    $args[] = $kursusF;
}
if ($q !== '') {
    $where[] = '(us.nama LIKE ? OR us.email LIKE ? OR co.judul LIKE ? OR p.nomor_referensi LIKE ?)';
    for ($i = 0; $i < 4; $i++) {
        $args[] = '%' . $q . '%';
    }
}
$w = implode(' AND ', $where);

if (get('export')) {
    $st = db()->prepare("SELECT p.*, us.nama, us.email, co.judul AS kursus FROM payments p
        LEFT JOIN users us ON us.id = p.user_id LEFT JOIN courses co ON co.id = p.course_id
        WHERE $w ORDER BY p.id DESC");
    $st->execute($args);
    $head = ['Tanggal', 'Nama Siswa', 'Email', 'Kursus', 'Jumlah', 'Metode', 'Referensi', 'Status', 'Catatan', 'Diverifikasi pada'];
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [tgl_id($r['created_at'], true), $r['nama'], $r['email'], $r['kursus'], number_format((float)$r['jumlah'], 0, ',', '.'), $r['metode_bayar'] ?: $r['metode'], $r['nomor_referensi'], $r['status'], $r['catatan'], $r['verified_at']];
    }
    audit('export_pembayaran', 'Export data pembayaran (' . count($rows) . ' baris)');
    if (get('export') === 'xlsx') {
        xlsx_download('data-pembayaran-' . date('Ymd-His') . '.xlsx', array_merge([$head], $rows));
    }
    csv_download('data-pembayaran-' . date('Ymd-His') . '.csv', $head, $rows);
}

$page = max(1, num('page', 1));
$cs = db()->prepare("SELECT COUNT(*) FROM payments p LEFT JOIN users us ON us.id = p.user_id LEFT JOIN courses co ON co.id = p.course_id WHERE $w");
$cs->execute($args);
$pager = paginate((int)$cs->fetchColumn(), 25, $page);

$st = db()->prepare("SELECT p.*, us.nama, us.email, us.foto, co.judul AS kursus, v.nama AS verifikator,
        e.status AS status_akses
    FROM payments p LEFT JOIN users us ON us.id = p.user_id LEFT JOIN courses co ON co.id = p.course_id
    LEFT JOIN users v ON v.id = p.verified_by
    LEFT JOIN enrollments e ON e.user_id = p.user_id AND e.course_id = p.course_id
    WHERE $w ORDER BY (p.status = 'menunggu') DESC, p.id DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}");
$st->execute($args);
$rows = $st->fetchAll();

$ringkas = db()->query("SELECT
    SUM(CASE WHEN status = 'menunggu' THEN 1 ELSE 0 END) AS menunggu,
    SUM(CASE WHEN status = 'terverifikasi' THEN 1 ELSE 0 END) AS terverifikasi,
    SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak,
    COALESCE(SUM(CASE WHEN status = 'terverifikasi' THEN jumlah ELSE 0 END), 0) AS total,
    COALESCE(SUM(CASE WHEN status = 'terverifikasi' AND strftime('%Y-%m', COALESCE(verified_at, created_at)) = strftime('%Y-%m','now') THEN jumlah ELSE 0 END), 0) AS total_bulan,
    COALESCE(SUM(CASE WHEN status = 'menunggu' THEN jumlah ELSE 0 END), 0) AS nominal_menunggu
    FROM payments")->fetch();

$kursusList = db()->query("SELECT id, judul, harga FROM courses WHERE harga > 0 ORDER BY judul")->fetchAll();
$siswaList = db()->query("SELECT id, nama, email FROM users WHERE role = 'siswa' AND status = 'aktif' ORDER BY nama")->fetchAll();
$rekening = rekening_list();

page_start('Manajemen Pembayaran', 'Verifikasi bukti transfer siswa dan kelola tagihan kursus.', [
    'actions' => '<a class="btn btn-ghost" href="admin-pembayaran.php?export=xlsx' . ($statusF ? '&status=' . urlencode($statusF) : '') . '">' . icon('download', 17) . ' Export Excel</a>'
        . '<button class="btn btn-ghost" data-modal-open="modal-manual">' . icon('plus', 17) . ' Catat Manual</button>'
        . '<a class="btn btn-primary" href="admin-pengaturan.php#pembayaran">' . icon('settings', 17) . ' Pengaturan Pembayaran</a>',
]);
?>
<?php if (!pembayaran_aktif()): ?>
  <div class="flash flash-warning mb3"><?= icon('bell', 17) ?><span>Modul pembayaran sedang <strong>nonaktif</strong>. Semua kursus dapat diakses tanpa pembayaran. Aktifkan di Pengaturan → Pembayaran.</span></div>
<?php endif; ?>

<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g4"><?= icon('clock', 22) ?></div><div><div class="stat-num"><?= (int)$ringkas['menunggu'] ?></div><div class="stat-lab">Menunggu verifikasi (<?= rupiah($ringkas['nominal_menunggu']) ?>)</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= (int)$ringkas['terverifikasi'] ?></div><div class="stat-lab">Terverifikasi</div></div></div>
  <div class="stat"><div class="stat-ic g6"><?= icon('x', 22) ?></div><div><div class="stat-num"><?= (int)$ringkas['ditolak'] ?></div><div class="stat-lab">Ditolak</div></div></div>
  <div class="stat"><div class="stat-ic g1"><?= icon('wallet', 22) ?></div><div><div class="stat-num"><?= rupiah($ringkas['total']) ?></div><div class="stat-lab">Total terverifikasi &middot; bulan ini <?= rupiah($ringkas['total_bulan']) ?></div></div></div>
</div>

<?php if (!$kursusList): ?>
  <div class="flash flash-info mb3"><?= icon('bell', 17) ?><span>Belum ada kursus berharga di atas 0, jadi tidak ada tagihan yang dibuat. Isi harga kursus di <a href="admin-kursus.php">Manajemen Kursus</a> untuk mengaktifkan pembayaran.</span></div>
<?php endif; ?>

<div class="card">
  <form class="toolbar" method="get">
    <div class="form-group">
      <label>Status</label>
      <select name="status" data-autosubmit>
        <option value="">Semua status</option>
        <?php foreach (['menunggu' => 'Menunggu verifikasi', 'terverifikasi' => 'Terverifikasi', 'ditolak' => 'Ditolak'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="min-width:220px">
      <label>Kursus</label>
      <select name="kursus" data-autosubmit>
        <option value="0">Semua kursus</option>
        <?php foreach ($kursusList as $k): ?>
          <option value="<?= (int)$k['id'] ?>" <?= $kursusF === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1;min-width:190px">
      <label>Cari</label>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nama, email, kursus, atau no. referensi">
    </div>
    <button class="btn btn-primary" type="submit"><?= icon('search', 17) ?> Cari</button>
    <a class="btn btn-ghost" href="admin-pembayaran.php">Reset</a>
  </form>
</div>

<div class="card">
  <div class="card-head"><h2 class="mt0"><?= icon('wallet', 20) ?> Daftar Pembayaran</h2><span class="muted small"><?= $pager['total'] ?> data</span></div>
  <?php if (!$rows): ?><?= empty_state('Belum ada data pembayaran pada filter ini.', 'wallet') ?><?php endif; ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Siswa</th><th>Kursus</th><th>Jumlah</th><th>Metode / Referensi</th><th>Bukti</th><th>Diajukan</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><div class="tbl-student"><span class="avatar sm" <?= $r['foto'] ? 'style="background-image:url(' . e($r['foto']) . ')"' : '' ?>><?= $r['foto'] ? '' : e(strtoupper(mb_substr((string)$r['nama'], 0, 1))) ?></span><div><strong><?= e((string)$r['nama']) ?></strong><div class="muted small"><?= e((string)$r['email']) ?></div></div></div></td>
          <td><?= e((string)$r['kursus']) ?><div class="muted small">akses: <?= e($r['status_akses'] ?: 'belum terdaftar') ?></div></td>
          <td class="nowrap"><strong><?= rupiah($r['jumlah']) ?></strong></td>
          <td class="small"><?= e($r['metode_bayar'] ?: $r['metode'] ?: '-') ?><?= $r['nomor_referensi'] ? '<div class="muted small">Ref: ' . e($r['nomor_referensi']) . '</div>' : '' ?></td>
          <td><?php if ($r['bukti']): ?><a class="btn btn-ghost btn-sm" href="<?= e($r['bukti']) ?>" target="_blank" rel="noopener"><?= icon('image', 14) ?> Lihat</a><?php else: ?><span class="muted small">-</span><?php endif; ?></td>
          <td class="small nowrap"><?= e(tgl_id($r['created_at'], true)) ?><?= $r['catatan'] ? '<div class="muted small">' . e(mb_strimwidth((string)$r['catatan'], 0, 40, '...')) . '</div>' : '' ?></td>
          <td><?= status_badge($r['status']) ?><?= $r['verifikator'] ? '<div class="muted small">oleh ' . e($r['verifikator']) . '</div>' : '' ?></td>
          <td class="actions">
            <?php if ($r['status'] === 'menunggu'): ?>
              <button class="btn btn-success btn-sm" data-modal-open="modal-verif-<?= (int)$r['id'] ?>"><?= icon('check', 14) ?> Verifikasi</button>
              <button class="btn btn-danger btn-sm" data-modal-open="modal-tolak-<?= (int)$r['id'] ?>"><?= icon('x', 14) ?> Tolak</button>
            <?php elseif ($r['status'] === 'ditolak'): ?>
              <button class="btn btn-success btn-sm" data-modal-open="modal-verif-<?= (int)$r['id'] ?>"><?= icon('check', 14) ?> Verifikasi</button>
            <?php endif; ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-ghost btn-sm" data-confirm="Hapus data pembayaran ini? Riwayat tidak bisa dikembalikan."><?= icon('trash', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager_html($pager, 'admin-pembayaran.php?status=' . urlencode($statusF) . '&kursus=' . $kursusF . '&q=' . urlencode($q)) ?>
</div>

<div class="split">
  <div class="card">
    <h3 class="mt0"><?= icon('bank', 18) ?> Rekening Tujuan Transfer</h3>
    <?php if (!$rekening): ?><p class="muted small">Belum ada rekening. Isi di <a href="admin-pengaturan.php#pembayaran">Pengaturan → Pembayaran</a>.</p><?php endif; ?>
    <?php foreach ($rekening as $r): ?>
      <div class="notice">
        <div class="notice-ic"><?= icon('bank', 16) ?></div>
        <div><h4><?= e($r['bank']) ?></h4><p><?= e($r['nomor']) ?> — a.n. <?= e($r['nama']) ?></p></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <h3 class="mt0"><?= icon('shield', 18) ?> Alur Verifikasi</h3>
    <ol class="muted small">
      <li>Siswa memilih kursus berbayar → pendaftaran masuk status <em>menunggu pembayaran</em> (materi terkunci).</li>
      <li>Siswa transfer sesuai nominal lalu mengunggah bukti di halaman Pembayaran.</li>
      <li>Admin memeriksa bukti di sini, lalu klik <strong>Verifikasi</strong> (akses terbuka otomatis) atau <strong>Tolak</strong> dengan catatan.</li>
      <li>Siswa menerima notifikasi di setiap perubahan status.</li>
    </ol>
  </div>
</div>

<?php foreach ($rows as $r): ?>
  <div class="modal-back" id="modal-verif-<?= (int)$r['id'] ?>">
    <div class="modal" style="max-width:520px">
      <div class="modal-head"><h3 class="mt0">Verifikasi Pembayaran</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
      <dl class="kv mb3">
        <dt>Siswa</dt><dd><?= e((string)$r['nama']) ?></dd>
        <dt>Kursus</dt><dd><?= e((string)$r['kursus']) ?></dd>
        <dt>Nominal</dt><dd><?= rupiah($r['jumlah']) ?></dd>
        <dt>Metode</dt><dd><?= e($r['metode_bayar'] ?: '-') ?><?= $r['nomor_referensi'] ? ' (ref ' . e($r['nomor_referensi']) . ')' : '' ?></dd>
        <dt>Bukti</dt><dd><?= $r['bukti'] ? '<a href="' . e($r['bukti']) . '" target="_blank" rel="noopener">Buka bukti transfer</a>' : '-' ?></dd>
      </dl>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="verifikasi">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <div class="form-group">
          <label>Catatan untuk siswa (opsional)</label>
          <input type="text" name="catatan" value="<?= e((string)$r['catatan']) ?>" placeholder="mis. pembayaran diterima, terima kasih">
        </div>
        <div class="flash flash-info mb2"><?= icon('check', 17) ?><span>Akses materi &amp; quiz kursus ini akan otomatis terbuka setelah diverifikasi.</span></div>
        <div class="row-gap">
          <button class="btn btn-success" type="submit"><?= icon('check', 17) ?> Verifikasi &amp; Buka Akses</button>
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-back" id="modal-tolak-<?= (int)$r['id'] ?>">
    <div class="modal" style="max-width:520px">
      <div class="modal-head"><h3 class="mt0">Tolak Pembayaran</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
      <p class="muted small">Tolak bukti pembayaran dari <strong><?= e((string)$r['nama']) ?></strong> untuk kursus <strong><?= e((string)$r['kursus']) ?></strong>.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="tolak">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <div class="form-group">
          <label>Alasan penolakan *</label>
          <textarea name="catatan" placeholder="mis. nominal tidak sesuai / bukti tidak terbaca" required></textarea>
          <div class="form-hint">Alasan ini dikirim ke siswa agar bisa mengunggah bukti yang benar.</div>
        </div>
        <div class="row-gap">
          <button class="btn btn-danger" type="submit"><?= icon('x', 17) ?> Tolak Pembayaran</button>
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<div class="modal-back" id="modal-manual">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0">Catat Pembayaran Manual</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <p class="muted small">Untuk pembayaran tunai / transfer yang dicatat admin tanpa unggahan siswa.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="tambah_manual">
      <div class="form-group">
        <label>Siswa *</label>
        <select name="user_id" required>
          <option value="">- pilih siswa -</option>
          <?php foreach ($siswaList as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['nama']) ?> — <?= e($s['email']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Kursus *</label>
        <select name="course_id" required>
          <option value="">- pilih kursus -</option>
          <?php foreach ($kursusList as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['judul']) ?> — <?= rupiah($k['harga']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label>Jumlah dibayar</label>
          <input type="text" name="jumlah" placeholder="kosongkan = harga kursus">
        </div>
        <div class="form-group">
          <label>Metode</label>
          <select name="metode_bayar">
            <option value="Tunai">Tunai</option>
            <option value="Transfer bank">Transfer bank</option>
            <option value="Transfer e-wallet">Transfer e-wallet</option>
            <option value="Beasiswa">Beasiswa / gratis</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Nomor referensi</label>
        <input type="text" name="nomor_referensi" placeholder="opsional">
      </div>
      <div class="form-group">
        <label>Bukti / tautan bukti</label>
        <input type="url" name="bukti_url" placeholder="opsional — tempel URL bukti">
      </div>
      <div class="form-group">
        <label>Catatan</label>
        <textarea name="catatan" placeholder="mis. dibayar tunai di kantor"></textarea>
      </div>
      <label class="check mb3"><input type="checkbox" name="langsung_verifikasi" value="1" checked> Langsung verifikasi &amp; buka akses kursus</label>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
