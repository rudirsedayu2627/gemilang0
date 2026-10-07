<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login(['guru', 'admin']);

$scope = $u['role'] === 'admin' ? '1=1' : ('co.guru_id = ' . (int)$u['id']);
$myCourses = db()->query("SELECT co.*, (SELECT COUNT(*) FROM enrollments WHERE course_id = co.id) AS jml_siswa,
        (SELECT COUNT(*) FROM lessons WHERE course_id = co.id) AS jml_lesson,
        (SELECT COUNT(*) FROM quizzes WHERE course_id = co.id) AS jml_quiz
    FROM courses co WHERE $scope ORDER BY co.id DESC")->fetchAll();
$courseIds = array_column($myCourses, 'id');
$inCourses = $courseIds ? implode(',', array_map('intval', $courseIds)) : '0';

$jmlSiswa = (int)db()->query("SELECT COUNT(DISTINCT user_id) FROM enrollments WHERE course_id IN ($inCourses)")->fetchColumn();
$jmlMateri = (int)db()->query("SELECT COUNT(*) FROM lessons WHERE course_id IN ($inCourses)")->fetchColumn();
$perluKoreksi = (int)db()->query("SELECT COUNT(*) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.status = 'menunggu_koreksi' AND qz.course_id IN ($inCourses)")->fetchColumn();
$tugasBaru = (int)db()->query("SELECT COUNT(*) FROM assignment_submissions s JOIN assignments a ON a.id = s.assignment_id WHERE s.skor IS NULL AND a.course_id IN ($inCourses)")->fetchColumn();
$rataNilai = (float)db()->query("SELECT AVG(a.nilai) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.status != 'in_progress' AND qz.course_id IN ($inCourses)")->fetchColumn();

$progressSiswa = db()->query("SELECT us.id, us.nama, us.foto, COUNT(e.id) AS jml_kursus, ROUND(AVG(e.progress),1) AS progress
    FROM users us JOIN enrollments e ON e.user_id = us.id WHERE e.course_id IN ($inCourses)
    GROUP BY us.id ORDER BY progress DESC LIMIT 6")->fetchAll();
$attemptTerbaru = db()->query("SELECT a.*, qz.judul AS quiz, qz.passing_grade, us.nama, us.foto FROM quiz_attempts a
    JOIN quizzes qz ON qz.id = a.quiz_id LEFT JOIN users us ON us.id = a.user_id
    WHERE qz.course_id IN ($inCourses) ORDER BY a.id DESC LIMIT 6")->fetchAll();
$jadwalHariIni = db()->query("SELECT s.*, co.judul AS kursus FROM schedules s LEFT JOIN courses co ON co.id = s.course_id
    WHERE s.course_id IN ($inCourses) AND s.tanggal >= date('now') ORDER BY s.tanggal, s.jam_mulai LIMIT 5")->fetchAll();

page_start('Dashboard Guru', 'Ringkasan kelas, materi, dan hasil siswa yang kamu ampu.', [
    'actions' => '<a class="btn btn-ghost" href="guru-materi.php">' . icon('layers', 17) . ' Modul & Materi</a>'
        . '<a class="btn btn-primary" href="guru-kursus.php">' . icon('plus', 17) . ' Kelola Kursus</a>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('book', 22) ?></div><div><div class="stat-num"><?= count($myCourses) ?></div><div class="stat-lab">Kursus diampu</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= $jmlSiswa ?></div><div class="stat-lab">Siswa terdaftar</div></div></div>
  <div class="stat"><div class="stat-ic g3"><?= icon('layers', 22) ?></div><div><div class="stat-num"><?= $jmlMateri ?></div><div class="stat-lab">Materi pembelajaran</div></div></div>
  <div class="stat"><div class="stat-ic g4"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= number_format($rataNilai, 1) ?></div><div class="stat-lab">Rata-rata nilai</div></div></div>
</div>

<div class="grid cols-2 mb3">
  <a class="card tight" href="guru-koreksi.php" style="text-decoration:none;color:inherit">
    <div class="row-gap">
      <div class="stat-ic g6"><?= icon('edit', 22) ?></div>
      <div><h3 class="mt0 mb0"><?= $perluKoreksi ?> jawaban perlu dikoreksi</h3><span class="muted small">Soal essay menunggu penilaianmu</span></div>
    </div>
  </a>
  <a class="card tight" href="guru-tugas.php" style="text-decoration:none;color:inherit">
    <div class="row-gap">
      <div class="stat-ic g5"><?= icon('clipboard', 22) ?></div>
      <div><h3 class="mt0 mb0"><?= $tugasBaru ?> tugas belum dinilai</h3><span class="muted small">Beri skor dan feedback untuk siswa</span></div>
    </div>
  </a>
</div>

<div class="split">
  <div>
    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('book', 20) ?> Kursus Saya</h2><a class="small" href="guru-kursus.php">Kelola</a></div>
      <?php if (!$myCourses): ?><?= empty_state('Belum ada kursus. Buat kursus pertamamu.', 'book') ?>
        <div class="center"><a class="btn btn-primary" href="guru-kursus.php">Buat Kursus</a></div><?php endif; ?>
      <?php foreach ($myCourses as $c): ?>
        <div class="lesson-item mb2">
          <span class="lesson-ic"><?= icon('book', 18) ?></span>
          <span class="lesson-meta">
            <strong><?= e($c['judul']) ?></strong>
            <small><?= (int)$c['jml_lesson'] ?> materi &middot; <?= (int)$c['jml_quiz'] ?> quiz &middot; <?= (int)$c['jml_siswa'] ?> siswa &middot; <?= e($c['level']) ?></small>
          </span>
          <span style="margin-left:auto" class="row-gap">
            <a class="btn btn-ghost btn-sm" href="guru-materi.php?kursus=<?= (int)$c['id'] ?>">Materi</a>
            <a class="btn btn-ghost btn-sm" href="guru-quiz.php?kursus=<?= (int)$c['id'] ?>">Quiz</a>
            <a class="btn btn-ghost btn-sm" href="guru-progress.php?kursus=<?= (int)$c['id'] ?>">Progress</a>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('quiz', 20) ?> Pengerjaan Quiz Terbaru</h2><a class="small" href="guru-nilai.php">Semua hasil</a></div>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Siswa</th><th>Quiz</th><th>Nilai</th><th>Status</th><th>Waktu</th></tr></thead>
          <tbody>
          <?php foreach ($attemptTerbaru as $a): ?>
            <tr>
              <td><div class="tbl-student"><span class="avatar sm" <?= $a['foto'] ? 'style="background-image:url(' . e($a['foto']) . ')"' : '' ?>><?= $a['foto'] ? '' : e(strtoupper(mb_substr((string)$a['nama'], 0, 1))) ?></span> <?= e((string)$a['nama']) ?></div></td>
              <td><?= e($a['quiz']) ?></td>
              <td><strong><?= number_format((float)$a['nilai'], 2) ?></strong></td>
              <td><?= status_badge($a['status']) ?></td>
              <td class="small nowrap"><?= e(tgl_id($a['selesai'] ?: $a['created_at'], true)) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$attemptTerbaru): ?><tr><td colspan="5" class="muted">Belum ada pengerjaan quiz.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card-head"><h3 class="mt0"><?= icon('trophy', 18) ?> Progress Tertinggi</h3><a class="small" href="guru-progress.php">Detail</a></div>
      <?php foreach ($progressSiswa as $p): ?>
        <div class="row-gap mb2">
          <span class="avatar sm" <?= $p['foto'] ? 'style="background-image:url(' . e($p['foto']) . ')"' : '' ?>><?= $p['foto'] ? '' : e(strtoupper(mb_substr($p['nama'], 0, 1))) ?></span>
          <div style="flex:1">
            <strong class="small"><?= e($p['nama']) ?></strong>
            <div class="progress thin"><span style="width:<?= round((float)$p['progress']) ?>%"></span></div>
          </div>
          <span class="chip"><?= round((float)$p['progress']) ?>%</span>
        </div>
      <?php endforeach; ?>
      <?php if (!$progressSiswa): ?><p class="muted small">Belum ada siswa terdaftar.</p><?php endif; ?>
    </div>
    <div class="card">
      <h3 class="mt0"><?= icon('calendar', 18) ?> Jadwal Mendatang</h3>
      <?php foreach ($jadwalHariIni as $j): ?>
        <div class="row-gap mb2">
          <div class="leader-rank"><?= date('d', strtotime($j['tanggal'])) ?></div>
          <div><strong class="small"><?= e($j['judul']) ?></strong><div class="muted small"><?= e((string)$j['kursus']) ?> &middot; <?= e(substr((string)$j['jam_mulai'], 0, 5)) ?></div></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$jadwalHariIni): ?><p class="muted small">Belum ada jadwal.</p><?php endif; ?>
      <a class="btn btn-ghost btn-sm btn-block" href="guru-kelas.php"><?= icon('grid', 16) ?> Kelola Kelas & Jadwal</a>
    </div>
    <div class="card">
      <h3 class="mt0"><?= icon('bookmark', 18) ?> Aksi Cepat</h3>
      <div class="stack">
        <a class="btn btn-ghost btn-sm" href="guru-soal.php"><?= icon('list', 16) ?> Bank Soal & Import Excel</a>
        <a class="btn btn-ghost btn-sm" href="guru-absensi.php"><?= icon('check', 16) ?> Isi Absensi</a>
        <a class="btn btn-ghost btn-sm" href="guru-pengumuman.php"><?= icon('bell', 16) ?> Buat Pengumuman</a>
        <a class="btn btn-ghost btn-sm" href="guru-statistik.php"><?= icon('chart', 16) ?> Statistik Kelas</a>
      </div>
    </div>
  </aside>
</div>
<?php page_end(); ?>
