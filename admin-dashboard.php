<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');

$stat = db()->query("SELECT
    (SELECT COUNT(*) FROM users) AS users,
    (SELECT COUNT(*) FROM users WHERE role = 'siswa') AS siswa,
    (SELECT COUNT(*) FROM users WHERE role = 'guru') AS guru,
    (SELECT COUNT(*) FROM courses) AS kursus,
    (SELECT COUNT(*) FROM courses WHERE status = 'aktif') AS kursus_aktif,
    (SELECT COUNT(*) FROM lessons) AS materi,
    (SELECT COUNT(*) FROM quizzes) AS quiz,
    (SELECT COUNT(*) FROM questions) AS soal,
    (SELECT COUNT(*) FROM enrollments) AS enrollment,
    (SELECT COUNT(*) FROM forum_topics) AS topik,
    (SELECT COUNT(*) FROM classes) AS kelas,
    (SELECT COALESCE(ROUND(AVG(nilai),2),0) FROM quiz_attempts WHERE status != 'in_progress') AS rata_nilai,
    (SELECT COUNT(*) FROM quiz_attempts WHERE status = 'menunggu_koreksi') AS perlu_koreksi,
    (SELECT COUNT(*) FROM payments WHERE status = 'menunggu') AS bayar_menunggu,
    (SELECT COALESCE(SUM(jumlah),0) FROM payments WHERE status = 'terverifikasi') AS bayar_total,
    (SELECT COUNT(*) FROM enrollments WHERE status NOT IN ('aktif','selesai')) AS akses_terkunci")->fetch();

$topKursus = db()->query("SELECT co.judul, co.level,
        (SELECT COUNT(*) FROM enrollments WHERE course_id = co.id) AS siswa,
        (SELECT COALESCE(ROUND(AVG(progress),1),0) FROM enrollments WHERE course_id = co.id) AS progress,
        (SELECT COUNT(*) FROM lessons WHERE course_id = co.id) AS materi
    FROM courses co ORDER BY siswa DESC LIMIT 6")->fetchAll();

$guruAktif = db()->query("SELECT us.nama, us.foto,
        (SELECT COUNT(*) FROM courses WHERE guru_id = us.id) AS jml_kursus,
        (SELECT COUNT(*) FROM enrollments WHERE course_id IN (SELECT id FROM courses WHERE guru_id = us.id)) AS jml_siswa
    FROM users us WHERE us.role = 'guru' ORDER BY jml_siswa DESC LIMIT 5")->fetchAll();

$aktivitas = db()->query("SELECT a.*, us.nama, us.role FROM audit_logs a LEFT JOIN users us ON us.id = a.user_id ORDER BY a.id DESC LIMIT 10")->fetchAll();
$pendaftarBaru = db()->query("SELECT nama, email, role, created_at FROM users ORDER BY id DESC LIMIT 6")->fetchAll();
$dbFile = lms_db_path();
$ukuranDb = is_file($dbFile) ? filesize($dbFile) : 0;

page_start('Dashboard Admin', 'Ringkasan seluruh aktivitas ' . setting('site_name', 'LMS') . '.', [
    'actions' => '<a class="btn btn-ghost" href="admin-pengaturan.php">' . icon('settings', 17) . ' Pengaturan</a>'
        . '<a class="btn btn-primary" href="admin-users.php">' . icon('users', 17) . ' Manajemen User</a>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= (int)$stat['users'] ?></div><div class="stat-lab"><?= (int)$stat['siswa'] ?> siswa &middot; <?= (int)$stat['guru'] ?> guru</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('book', 22) ?></div><div><div class="stat-num"><?= (int)$stat['kursus'] ?></div><div class="stat-lab"><?= (int)$stat['kursus_aktif'] ?> kursus aktif</div></div></div>
  <div class="stat"><div class="stat-ic g3"><?= icon('layers', 22) ?></div><div><div class="stat-num"><?= (int)$stat['materi'] ?></div><div class="stat-lab">materi &middot; <?= (int)$stat['quiz'] ?> quiz &middot; <?= (int)$stat['soal'] ?> soal</div></div></div>
  <?php if (sertifikat_aktif()): ?><div class="stat"><div class="stat-ic g4"><?= icon('award', 22) ?></div><div><div class="stat-num"><?= (int)$stat['sertifikat'] ?></div><div class="stat-lab">sertifikat diterbitkan</div></div></div><?php endif; ?>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= (int)$stat['enrollment'] ?></div><div class="stat-lab">pendaftaran kursus</div></div></div>
  <div class="stat"><div class="stat-ic g6"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= number_format((float)$stat['rata_nilai'], 1) ?></div><div class="stat-lab">rata-rata nilai quiz</div></div></div>
  <div class="stat"><div class="stat-ic g1"><?= icon('chat', 22) ?></div><div><div class="stat-num"><?= (int)$stat['topik'] ?></div><div class="stat-lab">topik forum</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('grid', 22) ?></div><div><div class="stat-num"><?= (int)$stat['kelas'] ?></div><div class="stat-lab">kelas terjadwal</div></div></div>
</div>

<?php if ((int)$stat['perlu_koreksi'] > 0): ?>
  <div class="flash flash-warning mb3"><?= icon('edit', 17) ?><span><?= (int)$stat['perlu_koreksi'] ?> jawaban essay masih menunggu koreksi guru.</span></div>
<?php endif; ?>

<?php if ((int)$stat['bayar_menunggu'] > 0): ?>
  <div class="card" style="border-left:4px solid var(--green)">
    <div class="row-between">
      <div class="row-gap">
        <div class="stat-ic g5"><?= icon('wallet', 22) ?></div>
        <div>
          <h3 class="mt0 mb0"><?= (int)$stat['bayar_menunggu'] ?> pembayaran menunggu verifikasi</h3>
          <div class="muted small">Total terverifikasi <?= rupiah($stat['bayar_total']) ?> &middot; <?= (int)$stat['akses_terkunci'] ?> pendaftaran masih terkunci</div>
        </div>
      </div>
      <a class="btn btn-primary" href="admin-pembayaran.php?status=menunggu"><?= icon('check', 17) ?> Verifikasi Sekarang</a>
    </div>
  </div>
<?php elseif (pembayaran_aktif() && (int)$stat['akses_terkunci'] > 0): ?>
  <div class="flash flash-info mb3"><?= icon('wallet', 17) ?><span><?= (int)$stat['akses_terkunci'] ?> pendaftaran masih menunggu pembayaran. Total terverifikasi <?= rupiah($stat['bayar_total']) ?> — <a href="admin-pembayaran.php">buka Manajemen Pembayaran</a>.</span></div>
<?php endif; ?>

<div class="split">
  <div>
    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('trophy', 20) ?> Kursus Terpopuler</h2><a class="small" href="admin-kursus.php">Kelola kursus</a></div>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Kursus</th><th>Level</th><th>Materi</th><th>Siswa</th><th>Rata Progress</th></tr></thead>
          <tbody>
          <?php foreach ($topKursus as $c): ?>
            <tr>
              <td><strong><?= e($c['judul']) ?></strong></td>
              <td><?= e($c['level']) ?></td>
              <td><?= (int)$c['materi'] ?></td>
              <td><?= (int)$c['siswa'] ?></td>
              <td style="min-width:150px">
                <div class="progress-lab"><span><?= number_format((float)$c['progress'], 1) ?>%</span></div>
                <div class="progress thin"><span style="width:<?= round((float)$c['progress']) ?>%"></span></div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$topKursus): ?><tr><td colspan="5" class="muted">Belum ada kursus.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('clock', 20) ?> Audit Log Terbaru</h2><a class="small" href="admin-audit.php">Semua log</a></div>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Detail</th></tr></thead>
          <tbody>
          <?php foreach ($aktivitas as $a): ?>
            <tr>
              <td class="small nowrap"><?= e(tgl_id($a['created_at'], true)) ?></td>
              <td class="small"><?= e($a['nama'] ?? 'Sistem') ?><div class="muted small"><?= e(role_label($a['role'] ?? '-')) ?></div></td>
              <td><span class="chip"><?= e($a['aksi']) ?></span></td>
              <td class="small muted"><?= e(mb_strimwidth((string)$a['detail'], 0, 80, '...')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <aside>
    <div class="card">
      <h3 class="mt0"><?= icon('user', 18) ?> Pengajar Teraktif</h3>
      <?php foreach ($guruAktif as $g): ?>
        <div class="row-gap mb2">
          <span class="avatar sm" <?= $g['foto'] ? 'style="background-image:url(' . e($g['foto']) . ')"' : '' ?>><?= $g['foto'] ? '' : e(strtoupper(mb_substr($g['nama'], 0, 1))) ?></span>
          <div><strong class="small"><?= e($g['nama']) ?></strong><div class="muted small"><?= (int)$g['jml_kursus'] ?> kursus &middot; <?= (int)$g['jml_siswa'] ?> siswa</div></div>
        </div>
      <?php endforeach; ?>
      <a class="btn btn-ghost btn-sm btn-block" href="admin-guru.php"><?= icon('users', 16) ?> Manajemen Guru</a>
    </div>

    <div class="card">
      <h3 class="mt0"><?= icon('bell', 18) ?> Pendaftar Terbaru</h3>
      <?php foreach ($pendaftarBaru as $p): ?>
        <div class="post">
          <span class="avatar sm"><?= e(strtoupper(mb_substr($p['nama'], 0, 1))) ?></span>
          <div class="post-body">
            <div class="post-meta"><strong><?= e($p['nama']) ?></strong><?= status_badge($p['role']) ?></div>
            <div class="muted small"><?= e($p['email']) ?> &middot; <?= e(tgl_id($p['created_at'])) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h3 class="mt0"><?= icon('database', 18) ?> Status Sistem</h3>
      <dl class="kv">
        <dt>Ukuran database</dt><dd><?= number_format($ukuranDb / 1024, 1) ?> KB</dd>
        <dt>Media token</dt><dd><?= media_token() !== '' ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-warning">Belum tersedia</span>' ?></dd>
        <dt>SMTP</dt><dd><?= setting('smtp_enabled') === '1' ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-muted">Nonaktif</span>' ?></dd>
        <dt>Pendaftaran</dt><dd><?= setting('registrasi_terbuka', '1') === '1' ? 'Terbuka' : 'Ditutup' ?></dd>
        <dt>Folder media</dt><dd><code><?= e(setting('storage_folder', 'uploads')) ?></code></dd>
      </dl>
      <div class="divider"></div>
      <div class="stack">
        <a class="btn btn-ghost btn-sm" href="admin-backup.php"><?= icon('database', 16) ?> Backup Database</a>
        <a class="btn btn-ghost btn-sm" href="admin-pengaturan.php#smtp"><?= icon('mail', 16) ?> Pengaturan SMTP</a>
        <a class="btn btn-ghost btn-sm" href="admin-role.php"><?= icon('shield', 16) ?> Manajemen Role</a>
      </div>
    </div>
  </aside>
</div>
<?php page_end(); ?>
