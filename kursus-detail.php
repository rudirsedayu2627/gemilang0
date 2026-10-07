<?php
require_once __DIR__ . '/includes/layout.php';
$u = current_user();
$id = num('id');

$stmt = db()->prepare('SELECT co.*, u.nama AS guru, u.bio AS guru_bio, u.foto AS guru_foto, c.nama AS kategori FROM courses co
    LEFT JOIN users u ON u.id = co.guru_id LEFT JOIN categories c ON c.id = co.kategori_id WHERE co.id = ?');
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c) {
    http_response_code(404);
    page_start('Kursus tidak ditemukan');
    echo '<div class="card">' . empty_state('Kursus yang kamu cari tidak ditemukan.', 'search') . '</div>';
    page_end();
    exit;
}

$enrolled = false;
$progress = 0;
$akses = ['status' => 'belum_daftar', 'boleh' => false, 'berbayar' => kursus_berbayar($c), 'pesan' => ''];
$staf = $u && in_array($u['role'], ['guru', 'admin'], true);
if ($u) {
    $akses = $staf
        ? ['status' => 'staf', 'boleh' => true, 'berbayar' => kursus_berbayar($c), 'pesan' => '']
        : cek_akses_kursus((int)$u['id'], $c);
    $enrolled = in_array($akses['status'], ['aktif', 'selesai', 'staf'], true);
    $e = db()->prepare('SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?');
    $e->execute([$u['id'], $c['id']]);
    $en = $e->fetch();
    $progress = $en ? (float)$en['progress'] : 0;
}
$berbayar = kursus_berbayar($c);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_logged_in()) {
        flash('Silakan masuk untuk mengikuti kursus ini.', 'warning');
        redirect('login.php');
    }
    if (post('aksi') === 'ikuti' && !$enrolled && !$staf) {
        if ($berbayar) {
            // kursus berbayar: daftar dulu (terkunci) lalu arahkan ke pembayaran
            db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id, status) VALUES (?, ?, "menunggu_bayar")')->execute([$u['id'], $c['id']]);
            notify($c['guru_id'], 'Pendaftar baru di kursus ' . $c['judul'], $u['nama'] . ' mendaftar kursusmu dan sedang menunggu pembayaran.', 'guru-progress.php?kursus=' . $c['id']);
            audit('enroll_berbayar', 'Mendaftar kursus berbayar ' . $c['judul'] . ' (menunggu pembayaran)');
            flash('Pendaftaran berhasil. Selesaikan pembayaran untuk membuka materi & quiz.');
            redirect('siswa-pembayaran.php?kursus=' . (int)$c['id']);
        }
        db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id, status) VALUES (?, ?, "aktif")')->execute([$u['id'], $c['id']]);
        recalc_course_progress($u['id'], $c['id']);
        notify($c['guru_id'], 'Siswa baru di kursus ' . $c['judul'], $u['nama'] . ' baru saja mengikuti kursusmu.', 'guru-progress.php?kursus=' . $c['id']);
        audit('enroll', 'Mengikuti kursus ' . $c['judul']);
        flash('Berhasil mengikuti kursus. Selamat belajar!');
        redirect('kursus-detail.php?id=' . (int)$c['id']);
    }
}

$modules = db()->query('SELECT * FROM modules WHERE course_id = ' . (int)$c['id'] . ' ORDER BY urutan, id')->fetchAll();
$lessons = db()->query('SELECT * FROM lessons WHERE course_id = ' . (int)$c['id'] . ' ORDER BY urutan, id')->fetchAll();
$byModule = [];
foreach ($lessons as $l) {
    $byModule[(int)$l['module_id']][] = $l;
}
$doneIds = [];
if ($u) {
    $doneIds = db()->query('SELECT lesson_id FROM lesson_progress WHERE selesai = 1 AND user_id = ' . (int)$u['id'])->fetchAll(PDO::FETCH_COLUMN);
}
$doneIds = array_map('intval', $doneIds);
$tugas = db()->query('SELECT * FROM assignments WHERE course_id = ' . (int)$c['id'] . ' ORDER BY deadline')->fetchAll();
$jmlSiswa = (int)db()->query('SELECT COUNT(*) FROM enrollments WHERE course_id = ' . (int)$c['id'])->fetchColumn();

page_start($c['judul'], ($c['kategori'] ?: 'Kursus') . ' · ' . $c['level'] . ' · ' . $jmlSiswa . ' siswa');
?>
<div class="split">
  <div>
    <div class="card">
      <div class="row-gap mb3">
        <span class="chip solid"><?= e($c['kategori'] ?: 'Umum') ?></span>
        <span class="chip"><?= e($c['level']) ?></span>
        <span class="chip"><?= (float)$c['harga'] > 0 ? ($berbayar ? icon('lock', 13) . ' ' : '') . rupiah($c['harga']) : 'Gratis' ?></span>
        <span class="chip"><?= icon('clock', 14) ?> <?= (int)$c['durasi_jam'] ?> jam</span>
      </div>
      <h2 class="mt0">Deskripsi Kursus</h2>
      <p><?= nl2br(e($c['deskripsi'])) ?></p>
      <?php if ($enrolled): ?>
        <div class="divider"></div>
        <div class="progress-lab"><span>Progress belajarmu</span><strong><?= round($progress) ?>%</strong></div>
        <div class="progress"><span style="width:<?= round($progress) ?>%"></span></div>
        <a class="btn btn-primary mt3" href="materi.php?kursus=<?= (int)$c['id'] ?>"><?= icon('play', 17) ?> Lanjut Belajar</a>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= icon('layers', 20) ?> Kurikulum (<?= count($lessons) ?> materi)</h2></div>
      <?php if (!$modules && !$lessons): ?>
        <?= empty_state('Materi kursus ini sedang disiapkan.', 'folder') ?>
      <?php endif; ?>
      <?php foreach ($modules as $m): ?>
        <div class="module-block">
          <header>
            <h3><?= icon('folder', 17) ?> <?= e($m['judul']) ?></h3>
            <span class="muted small"><?= count($byModule[(int)$m['id']] ?? []) ?> materi</span>
          </header>
          <div class="lesson-stack">
            <?php foreach ($byModule[(int)$m['id']] ?? [] as $l): ?>
              <?php $locked = !$akses['boleh'] && !(int)$l['is_preview']; ?>
              <a class="lesson-item <?= in_array((int)$l['id'], $doneIds, true) ? 'done' : '' ?>" href="<?= $locked ? (is_logged_in() ? 'siswa-pembayaran.php?kursus=' . (int)$c['id'] : 'login.php') : 'materi.php?id=' . (int)$l['id'] ?>">
                <span class="lesson-ic <?= e($l['tipe']) ?>"><?= icon(tipe_materi_ikon($l['tipe']), 18) ?></span>
                <span class="lesson-meta">
                  <strong><?= e($l['judul']) ?></strong>
                  <small><?= e(tipe_materi_label($l['tipe'])) ?><?= $l['durasi_menit'] ? ' &middot; ' . (int)$l['durasi_menit'] . ' menit' : '' ?><?= (int)$l['is_preview'] ? ' &middot; bisa dicoba gratis' : '' ?></small>
                </span>
                <span style="margin-left:auto"><?= $locked ? icon('lock', 17) : (in_array((int)$l['id'], $doneIds, true) ? icon('check', 17) : icon('arrow-right', 17)) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php
      $orphan = $byModule[0] ?? [];
      if (!$orphan) {
          foreach ($lessons as $l) {
              if (!$l['module_id']) {
                  $orphan[] = $l;
              }
          }
      }
      foreach ($orphan as $l): ?>
        <a class="lesson-item" href="<?= $enrolled || (int)$l['is_preview'] ? 'materi.php?id=' . (int)$l['id'] : 'login.php' ?>">
          <span class="lesson-ic <?= e($l['tipe']) ?>"><?= icon(tipe_materi_ikon($l['tipe']), 18) ?></span>
          <span class="lesson-meta"><strong><?= e($l['judul']) ?></strong><small><?= e(tipe_materi_label($l['tipe'])) ?></small></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php /* Kartu "Quiz & Ujian" dihilangkan atas permintaan pemilik (2 Okt 2026):
             daftar quiz tidak lagi ditampilkan di halaman kursus. Quiz tetap ada
             datanya dan dikerjakan lewat halaman belajar materi (materi.php),
             tempat quiz tampil di dalam materinya masing-masing; quiz yang belum
             ditautkan ke materi juga terdaftar di kartu sidebar materi.php. */ ?>

    <?php if ($tugas): ?>
    <div class="card">
      <div class="card-head"><h2><?= icon('clipboard', 20) ?> Tugas Kursus</h2></div>
      <?php foreach ($tugas as $t): ?>
        <div class="lesson-item">
          <span class="lesson-ic"><?= icon('clipboard', 18) ?></span>
          <span class="lesson-meta"><strong><?= e($t['judul']) ?></strong><small>Deadline: <?= e(tgl_id($t['deadline'], true)) ?> &middot; maks <?= (int)$t['max_skor'] ?> poin</small></span>
        </div>
      <?php endforeach; ?>
      <div class="mt3"><a class="btn btn-ghost btn-sm" href="siswa-tugas.php">Buka halaman tugas</a></div>
    </div>
    <?php endif; ?>
  </div>

  <aside>
    <div class="card">
      <h3 class="mt0"><?= icon('play', 18) ?> <?= $berbayar ? 'Akses Kursus Berbayar' : 'Ikuti Kursus Ini' ?></h3>
      <?php if ($enrolled): ?>
        <p class="muted small">Kursus ini sudah kamu ikuti. Progress saat ini <strong><?= round($progress) ?>%</strong>.</p>
        <a class="btn btn-primary btn-block" href="materi.php?kursus=<?= (int)$c['id'] ?>">Lanjut Belajar</a>
      <?php elseif (in_array($akses['status'], ['menunggu_bayar', 'ditolak'], true)): ?>
        <div class="flash <?= $akses['status'] === 'ditolak' ? 'flash-danger' : 'flash-warning' ?>"><?= icon($akses['status'] === 'ditolak' ? 'x' : 'clock', 17) ?><span><?= e($akses['pesan']) ?></span></div>
        <div class="progress-lab"><span>Status pendaftaran</span><?= akses_badge($akses['status']) ?></div>
        <a class="btn btn-primary btn-block mb2" href="siswa-pembayaran.php?kursus=<?= (int)$c['id'] ?>"><?= icon('wallet', 17) ?> Buka Halaman Pembayaran</a>
        <?php if ($akses['status'] === 'menunggu_bayar'): ?>
          <div class="muted small mb2">Tagihan: <strong><?= rupiah($c['harga']) ?></strong>. Akses materi terbuka otomatis setelah admin memverifikasi.</div>
        <?php endif; ?>
        <a class="btn btn-ghost btn-block" href="kursus-detail.php?id=<?= (int)$c['id'] ?>">Muat ulang status</a>
      <?php else: ?>
        <p class="muted small"><?= $berbayar
            ? 'Kursus ini berbayar. Daftar dulu, lalu transfer ' . rupiah($c['harga']) . ' dan unggah bukti untuk membuka seluruh materi' . (sertifikat_aktif() ? ', quiz, dan sertifikat.' : ' dan quiz.')
            : 'Daftar sekarang untuk membuka seluruh materi' . (sertifikat_aktif() ? ', quiz, dan sertifikat kursus ini.' : ' dan quiz kursus ini.') ?></p>
        <form method="post" class="mb2">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="ikuti">
          <button class="btn btn-primary btn-block btn-lg" type="submit"><?= $berbayar ? icon('wallet', 18) . ' Daftar & Bayar ' . rupiah($c['harga']) : icon('check', 18) . ' Ikuti Kursus' ?></button>
        </form>
        <?php if (!is_logged_in()): ?><a class="btn btn-ghost btn-block" href="login.php">Masuk dulu</a><?php endif; ?>
      <?php endif; ?>
      <?php if ($berbayar && !$enrolled && setting('bayar_aktif', '1') === '1'): ?>
        <div class="dark-box mt2">Pembayaran diverifikasi admin maksimal 1x24 jam pada hari kerja. Ada pertanyaan? Hubungi <?= e(setting('site_email')) ?>.</div>
      <?php endif; ?>
      <div class="divider"></div>
      <div class="kv">
        <dt>Kategori</dt><dd><?= e($c['kategori'] ?: '-') ?></dd>
        <dt>Level</dt><dd><?= e($c['level']) ?></dd>
        <dt>Materi</dt><dd><?= count($lessons) ?> pelajaran</dd>
        <dt>Siswa</dt><dd><?= $jmlSiswa ?> orang</dd>
        <dt>Bahasa</dt><dd><?= e($c['bahasa']) ?></dd>
        <dt>Harga</dt><dd><?= (float)$c['harga'] > 0 ? rupiah($c['harga']) : 'Gratis' ?></dd>
      </div>
    </div>
    <div class="card">
      <h3 class="mt0"><?= icon('user', 18) ?> Pengajar</h3>
      <div class="row-gap">
        <span class="avatar" <?= $c['guru_foto'] ? 'style="background-image:url(' . e($c['guru_foto']) . ')"' : '' ?>><?= $c['guru_foto'] ? '' : e(strtoupper(mb_substr($c['guru'] ?? 'G', 0, 1))) ?></span>
        <div><strong><?= e($c['guru'] ?: 'Pengajar') ?></strong><div class="muted small"><?= e($c['guru_bio'] ? mb_strimwidth($c['guru_bio'], 0, 90, '...') : 'Pengajar ' . setting('site_name')) ?></div></div>
      </div>
    </div>
    <?php if (sertifikat_aktif()): ?>
    <div class="card">
      <h3 class="mt0"><?= icon('bookmark', 18) ?> Status Sertifikat</h3>
      <p class="muted small">Sertifikat otomatis diterbitkan setelah seluruh materi selesai dan quiz/ujian lulus dengan nilai minimal yang ditentukan. Verifikasi keaslian di <a href="sertifikat-verifikasi.php">halaman verifikasi</a>.</p>
    </div>
    <?php endif; ?>
  </aside>
</div>
<?php page_end(); ?>
