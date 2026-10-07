<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login(['guru', 'admin']);
$courses = db()->query("SELECT id, judul FROM courses WHERE " . ($u['role'] === 'admin' ? '1=1' : ('guru_id = ' . (int)$u['id'])) . ' ORDER BY judul')->fetchAll();
$kursusId = num('kursus');
$siswaId = num('siswa');

if (get('export') === 'xlsx') {
    $rows = [['Kursus', 'Siswa', 'Email', 'Progress (%)', 'Materi Selesai', 'Total Materi', 'Quiz Dikerjakan', 'Rata Nilai', 'Status']];
    $data = db()->query("SELECT co.judul AS kursus, us.nama, us.email, ROUND(e.progress,1) AS progress, e.status,
            (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.user_id = us.id AND lp.course_id = co.id AND lp.selesai = 1) AS done,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = co.id) AS total,
            (SELECT COUNT(*) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.user_id = us.id AND qz.course_id = co.id AND a.status != 'in_progress') AS att,
            (SELECT COALESCE(ROUND(AVG(a.nilai),2),0) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.user_id = us.id AND qz.course_id = co.id AND a.status != 'in_progress') AS rata
        FROM enrollments e JOIN users us ON us.id = e.user_id JOIN courses co ON co.id = e.course_id
        WHERE co.guru_id " . ($u['role'] === 'admin' ? 'IS NOT NULL' : '= ' . (int)$u['id']) . ' ORDER BY co.judul, us.nama')->fetchAll();
    foreach ($data as $d) {
        $rows[] = [$d['kursus'], $d['nama'], $d['email'], $d['progress'], $d['done'], $d['total'], $d['att'], $d['rata'], $d['status']];
    }
    audit('export_progress', 'Export monitoring progress siswa');
    xlsx_download('progress-siswa-' . date('Ymd') . '.xlsx', $rows);
}

$where = 'co.guru_id ' . ($u['role'] === 'admin' ? 'IS NOT NULL' : '= ' . (int)$u['id']);
if ($kursusId > 0) {
    $where .= ' AND co.id = ' . $kursusId;
}
$rows = db()->query("SELECT e.*, us.nama, us.email, us.foto, us.last_login,
        co.judul AS kursus, co.id AS course_id,
        (SELECT COUNT(*) FROM lessons l WHERE l.course_id = co.id) AS total_materi,
        (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.user_id = us.id AND lp.course_id = co.id AND lp.selesai = 1) AS materi_selesai,
        (SELECT COUNT(*) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.user_id = us.id AND qz.course_id = co.id AND a.status != 'in_progress') AS jml_attempt,
        (SELECT COALESCE(ROUND(AVG(a.nilai),1),0) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.user_id = us.id AND qz.course_id = co.id AND a.status != 'in_progress') AS rata,
        (SELECT COUNT(*) FROM assignments asg WHERE asg.course_id = co.id) AS total_tugas,
        (SELECT COUNT(*) FROM assignment_submissions s JOIN assignments asg ON asg.id = s.assignment_id WHERE s.user_id = us.id AND asg.course_id = co.id) AS tugas_dikumpul
    FROM enrollments e JOIN users us ON us.id = e.user_id JOIN courses co ON co.id = e.course_id
    WHERE $where" . ($siswaId > 0 ? (' AND us.id = ' . $siswaId) : '') . '
    ORDER BY e.progress DESC, us.nama LIMIT 300')->fetchAll();

$detail = null;
if ($siswaId > 0) {
    $detail = db()->query('SELECT us.* FROM users us WHERE us.id = ' . $siswaId)->fetch();
}
$aktivitas = [];
if ($detail) {
    $aktivitas = db()->query("SELECT lp.updated_at AS waktu, 'Menyelesaikan materi: ' || l.judul AS teks, co.judul AS kursus
        FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id JOIN courses co ON co.id = lp.course_id
        WHERE lp.user_id = " . $siswaId . ' AND lp.selesai = 1 AND co.guru_id ' . ($u['role'] === 'admin' ? 'IS NOT NULL' : '= ' . (int)$u['id']) . "
        UNION ALL
        SELECT a.selesai, 'Mengerjakan ' || qz.judul || ' (nilai ' || ROUND(a.nilai,2) || ')', co.judul
        FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id JOIN courses co ON co.id = qz.course_id
        WHERE a.user_id = " . $siswaId . " AND a.status != 'in_progress' AND co.guru_id " . ($u['role'] === 'admin' ? 'IS NOT NULL' : '= ' . (int)$u['id']) . "
        ORDER BY waktu DESC LIMIT 20")->fetchAll();
}

$rataSemua = $rows ? round(array_sum(array_column($rows, 'progress')) / count($rows), 1) : 0;
$selesaiPenuh = count(array_filter($rows, function ($r) { return (float)$r['progress'] >= 100; }));

page_start('Monitoring Progress', $detail ? ('Aktivitas belajar ' . $detail['nama']) : 'Pantau penyelesaian materi, quiz, dan tugas setiap siswa.', [
    'actions' => '<a class="btn btn-ghost" href="guru-progress.php?export=xlsx">' . icon('download', 17) . ' Export Excel</a>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lab">Data progress</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= number_format($rataSemua, 1) ?>%</div><div class="stat-lab">Rata-rata penyelesaian</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= $selesaiPenuh ?></div><div class="stat-lab">Selesai 100%</div></div></div>
  <?php if (sertifikat_aktif()): ?><div class="stat"><div class="stat-ic g4"><?= icon('award', 22) ?></div><div><div class="stat-num"><?= count(array_filter($rows, function ($r) { return (int)$r['punya_sertifikat'] > 0; })) ?></div><div class="stat-lab">Punya sertifikat</div></div></div><?php endif; ?>
</div>

<div class="card">
  <form class="toolbar">
    <div class="form-group" style="min-width:240px">
      <label>Kursus</label>
      <select name="kursus" data-autosubmit>
        <option value="0">Semua kursus saya</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1;min-width:200px">
      <label>Cari siswa</label>
      <input type="search" data-filter-input="#tbl-progress" placeholder="Nama atau email...">
    </div>
    <?php if ($siswaId): ?><a class="btn btn-ghost" href="guru-progress.php?kursus=<?= (int)$kursusId ?>">Tampilkan semua siswa</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data" id="tbl-progress">
      <thead><tr><th>Siswa</th><th>Kursus</th><th>Materi</th><th>Progress</th><th>Quiz</th><th>Tugas</th><?php if (sertifikat_aktif()): ?><th>Sertifikat</th><?php endif; ?><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><div class="tbl-student"><span class="avatar sm" <?= $r['foto'] ? 'style="background-image:url(' . e($r['foto']) . ')"' : '' ?>><?= $r['foto'] ? '' : e(strtoupper(mb_substr($r['nama'], 0, 1))) ?></span><div><strong><?= e($r['nama']) ?></strong><div class="muted small">Login: <?= e(tgl_id($r['last_login'])) ?></div></div></div></td>
          <td class="small"><?= e($r['kursus']) ?></td>
          <td><?= (int)$r['materi_selesai'] ?>/<?= (int)$r['total_materi'] ?></td>
          <td style="min-width:170px">
            <div class="progress-lab"><span><?= round((float)$r['progress']) ?>%</span><span class="muted"><?= status_badge($r['status']) ?></span></div>
            <div class="progress thin"><span style="width:<?= round((float)$r['progress']) ?>%"></span></div>
          </td>
          <td><?= (int)$r['jml_attempt'] ?>x<div class="muted small">rata <?= number_format((float)$r['rata'], 1) ?></div></td>
          <td><?= (int)$r['tugas_dikumpul'] ?>/<?= (int)$r['total_tugas'] ?></td>
          <?php if (sertifikat_aktif()): ?><td><?= (int)$r['punya_sertifikat'] ? '<span class="badge badge-success">Ada</span>' : '<span class="badge badge-muted">Belum</span>' ?></td><?php endif; ?>
          <td><a class="btn btn-ghost btn-sm" href="guru-progress.php?siswa=<?= (int)$r['user_id'] ?>&kursus=<?= (int)$r['course_id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="muted">Belum ada data progress.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($detail): ?>
<div class="card">
  <div class="card-head">
    <div class="row-gap">
      <span class="avatar lg" <?= $detail['foto'] ? 'style="background-image:url(' . e($detail['foto']) . ')"' : '' ?>><?= $detail['foto'] ? '' : e(strtoupper(mb_substr($detail['nama'], 0, 1))) ?></span>
      <div>
        <h2 class="mt0 mb0"><?= e($detail['nama']) ?></h2>
        <div class="muted small"><?= e($detail['email']) ?> &middot; <?= e($detail['telepon'] ?: 'tanpa telepon') ?> &middot; status <?= e($detail['status']) ?></div>
      </div>
    </div>
  </div>
  <h3><?= icon('clock', 18) ?> Aktivitas Terakhir</h3>
  <?php foreach ($aktivitas as $a): ?>
    <div class="post">
      <span class="lesson-ic"><?= icon('check', 16) ?></span>
      <div class="post-body">
        <div class="post-meta"><strong><?= e($a['kursus']) ?></strong><span><?= e(tgl_id($a['waktu'], true)) ?></span></div>
        <p style="margin:0"><?= e($a['teks']) ?></p>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$aktivitas): ?><p class="muted small">Belum ada aktivitas belajar tercatat.</p><?php endif; ?>
</div>
<?php endif; ?>
<?php page_end(); ?>
