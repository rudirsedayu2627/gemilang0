<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login(['guru', 'admin']);
$courses = db()->query("SELECT id, judul FROM courses WHERE " . ($u['role'] === 'admin' ? '1=1' : ('guru_id = ' . (int)$u['id'])) . ' ORDER BY judul')->fetchAll();
$kursusId = num('kursus', (int)($courses[0]['id'] ?? 0));
$namaKursus = '';
foreach ($courses as $c) {
    if ((int)$c['id'] === $kursusId) {
        $namaKursus = $c['judul'];
    }
}

if (get('export') === 'xlsx') {
    $kolomSert = sertifikat_aktif();
    $judul = ['Kursus', 'Siswa', 'Progress (%)', 'Quiz Dikerjakan', 'Rata Nilai', 'Nilai Tertinggi', 'Tugas Dikumpul', 'Absensi Hadir', 'Absensi Alpa'];
    if ($kolomSert) {
        $judul[] = 'Sertifikat';
    }
    $rows = [$judul];
    $data = db()->query("SELECT us.nama, e.progress,
            (SELECT COUNT(*) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.user_id = us.id AND qz.course_id = e.course_id AND a.status != 'in_progress'),
            (SELECT COALESCE(ROUND(AVG(a.nilai),2),0) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.user_id = us.id AND qz.course_id = e.course_id AND a.status != 'in_progress'),
            (SELECT COALESCE(MAX(a.nilai),0) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.user_id = us.id AND qz.course_id = e.course_id AND a.status != 'in_progress'),
            (SELECT COUNT(*) FROM assignment_submissions s JOIN assignments asg ON asg.id = s.assignment_id WHERE s.user_id = us.id AND asg.course_id = e.course_id),
            (SELECT COUNT(*) FROM attendance at WHERE at.user_id = us.id AND at.course_id = e.course_id AND at.status = 'hadir'),
            (SELECT COUNT(*) FROM attendance at WHERE at.user_id = us.id AND at.course_id = e.course_id AND at.status = 'alpa'),
            (SELECT COUNT(*) FROM certificates c WHERE c.user_id = us.id AND c.course_id = e.course_id)
        FROM enrollments e JOIN users us ON us.id = e.user_id WHERE e.course_id = $kursusId ORDER BY e.progress DESC")->fetchAll();
    foreach ($data as $d) {
        $baris = array_values($d);
        if (!$kolomSert) {
            array_pop($baris); // buang kolom sertifikat
        }
        $rows[] = array_merge([$namaKursus], $baris);
    }
    audit('export_statistik', 'Export statistik kelas');
    xlsx_download('statistik-kelas-' . slugify($namaKursus) . '.xlsx', $rows);
}

$ringkas = db()->query("SELECT
        (SELECT COUNT(*) FROM enrollments WHERE course_id = $kursusId) AS siswa,
        (SELECT ROUND(AVG(progress),1) FROM enrollments WHERE course_id = $kursusId) AS rata_progress,
        (SELECT COUNT(*) FROM lessons WHERE course_id = $kursusId) AS materi,
        (SELECT COUNT(*) FROM quizzes WHERE course_id = $kursusId) AS quiz,
        (SELECT COUNT(*) FROM assignments WHERE course_id = $kursusId) AS tugas,
        (SELECT COUNT(*) FROM certificates WHERE course_id = $kursusId) AS sertifikat,
        (SELECT COALESCE(ROUND(AVG(a.nilai),2),0) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE qz.course_id = $kursusId AND a.status != 'in_progress') AS rata_nilai,
        (SELECT COUNT(*) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE qz.course_id = $kursusId AND a.status = 'menunggu_koreksi') AS perlu_koreksi")->fetch();

$perQuiz = db()->query("SELECT qz.judul, qz.passing_grade,
        COUNT(a.id) AS jml_attempt,
        COALESCE(ROUND(AVG(a.nilai),2),0) AS rata,
        COALESCE(MAX(a.nilai),0) AS max_nilai,
        COALESCE(MIN(a.nilai),0) AS min_nilai,
        SUM(CASE WHEN a.nilai >= qz.passing_grade THEN 1 ELSE 0 END) AS lulus
    FROM quizzes qz LEFT JOIN quiz_attempts a ON a.quiz_id = qz.id AND a.status != 'in_progress'
    WHERE qz.course_id = $kursusId GROUP BY qz.id ORDER BY qz.id")->fetchAll();

$perSoal = db()->query("SELECT q.pertanyaan, q.tipe, q.skor,
        COUNT(a.id) AS jml_jawab,
        SUM(CASE WHEN a.benar = 1 THEN 1 ELSE 0 END) AS jml_benar
    FROM questions q LEFT JOIN attempt_answers a ON a.question_id = q.id AND a.jawaban IS NOT NULL AND a.jawaban <> ''
    WHERE q.course_id = $kursusId GROUP BY q.id HAVING jml_jawab > 0 ORDER BY (CAST(jml_benar AS REAL) / jml_jawab) ASC LIMIT 8")->fetchAll();

$absensiRekap = db()->query("SELECT us.nama,
        SUM(CASE WHEN a.status = 'hadir' THEN 1 ELSE 0 END) AS hadir,
        SUM(CASE WHEN a.status = 'izin' THEN 1 ELSE 0 END) AS izin,
        SUM(CASE WHEN a.status = 'sakit' THEN 1 ELSE 0 END) AS sakit,
        SUM(CASE WHEN a.status = 'alpa' THEN 1 ELSE 0 END) AS alpa
    FROM attendance a JOIN users us ON us.id = a.user_id WHERE a.course_id = $kursusId GROUP BY us.id ORDER BY alpa DESC, hadir DESC LIMIT 12")->fetchAll();

$distribusi = ['0-59' => 0, '60-69' => 0, '70-79' => 0, '80-89' => 0, '90-100' => 0];
foreach (db()->query("SELECT a.nilai FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE qz.course_id = $kursusId AND a.status != 'in_progress'")->fetchAll(PDO::FETCH_COLUMN) as $n) {
    $n = (float)$n;
    if ($n < 60) {
        $distribusi['0-59']++;
    } elseif ($n < 70) {
        $distribusi['60-69']++;
    } elseif ($n < 80) {
        $distribusi['70-79']++;
    } elseif ($n < 90) {
        $distribusi['80-89']++;
    } else {
        $distribusi['90-100']++;
    }
}
$maxDist = max(1, max($distribusi));

page_start('Statistik Kelas', 'Analisis performa kelas: nilai, soal tersulit, dan kehadiran.', [
    'actions' => '<a class="btn btn-ghost" href="guru-statistik.php?kursus=' . (int)$kursusId . '&export=xlsx">' . icon('download', 17) . ' Export Statistik</a>',
]);
?>
<div class="card">
  <form class="inline-form">
    <div class="form-group" style="min-width:300px">
      <label>Kursus</label>
      <select name="kursus" data-autosubmit>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= (int)$ringkas['siswa'] ?></div><div class="stat-lab">Siswa terdaftar</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= number_format((float)$ringkas['rata_progress'], 1) ?>%</div><div class="stat-lab">Rata-rata progress</div></div></div>
  <div class="stat"><div class="stat-ic g4"><?= icon('trophy', 22) ?></div><div><div class="stat-num"><?= number_format((float)$ringkas['rata_nilai'], 1) ?></div><div class="stat-lab">Rata-rata nilai quiz</div></div></div>
  <?php if (sertifikat_aktif()): ?><div class="stat"><div class="stat-ic g5"><?= icon('award', 22) ?></div><div><div class="stat-num"><?= (int)$ringkas['sertifikat'] ?></div><div class="stat-lab">Sertifikat terbit</div></div></div><?php endif; ?>
</div>

<div class="split">
  <div>
    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('quiz', 20) ?> Performa per Quiz</h2></div>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Quiz</th><th>Dikerjakan</th><th>Rata</th><th>Tertinggi</th><th>Terendah</th><th>Lulus</th></tr></thead>
          <tbody>
          <?php foreach ($perQuiz as $q): ?>
            <tr>
              <td><?= e($q['judul']) ?><div class="muted small">min lulus <?= (int)$q['passing_grade'] ?></div></td>
              <td><?= (int)$q['jml_attempt'] ?>x</td>
              <td><strong><?= number_format((float)$q['rata'], 2) ?></strong></td>
              <td><?= number_format((float)$q['max_nilai'], 2) ?></td>
              <td><?= number_format((float)$q['min_nilai'], 2) ?></td>
              <td><?= (int)$q['lulus'] ?>/<?= (int)$q['jml_attempt'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$perQuiz): ?><tr><td colspan="6" class="muted">Belum ada quiz.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('list', 20) ?> Soal Tersulit (paling sering salah)</h2></div>
      <?php foreach ($perSoal as $s): $persen = (int)$s['jml_jawab'] ? round((int)$s['jml_benar'] / (int)$s['jml_jawab'] * 100) : 0; ?>
        <div class="mb2">
          <div class="progress-lab"><span><?= e(mb_strimwidth($s['pertanyaan'], 0, 80, '...')) ?></span><strong><?= $persen ?>% benar</strong></div>
          <div class="progress"><span style="width:<?= $persen ?>%"></span></div>
          <div class="muted small"><?= e(tipe_soal_label($s['tipe'])) ?> &middot; <?= (int)$s['jml_jawab'] ?> jawaban &middot; <?= (int)$s['jml_benar'] ?> benar</div>
        </div>
      <?php endforeach; ?>
      <?php if (!$perSoal): ?><p class="muted small">Belum ada data jawaban.</p><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('calendar', 20) ?> Rekap Kehadiran</h2></div>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Siswa</th><th>Hadir</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>Kehadiran</th></tr></thead>
          <tbody>
          <?php foreach ($absensiRekap as $a):
              $totalH = (int)$a['hadir'] + (int)$a['izin'] + (int)$a['sakit'] + (int)$a['alpa'];
              $persen = $totalH ? round((int)$a['hadir'] / $totalH * 100) : 0; ?>
            <tr>
              <td><?= e($a['nama']) ?></td>
              <td><?= (int)$a['hadir'] ?></td>
              <td><?= (int)$a['izin'] ?></td>
              <td><?= (int)$a['sakit'] ?></td>
              <td><?= (int)$a['alpa'] ?></td>
              <td style="min-width:140px">
                <div class="progress-lab"><span><?= $persen ?>%</span></div>
                <div class="progress thin"><span style="width:<?= $persen ?>%"></span></div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$absensiRekap): ?><tr><td colspan="6" class="muted">Belum ada data absensi.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <aside>
    <div class="card">
      <h3 class="mt0"><?= icon('chart', 18) ?> Distribusi Nilai</h3>
      <?php foreach ($distribusi as $label => $jml): ?>
        <div class="progress-lab"><span><?= e($label) ?></span><strong><?= $jml ?></strong></div>
        <div class="progress mb2"><span style="width:<?= round($jml / $maxDist * 100) ?>%"></span></div>
      <?php endforeach; ?>
    </div>
    <div class="card">
      <h3 class="mt0"><?= icon('layers', 18) ?> Ringkasan Konten</h3>
      <dl class="kv">
        <dt>Materi</dt><dd><?= (int)$ringkas['materi'] ?></dd>
        <dt>Quiz / ujian</dt><dd><?= (int)$ringkas['quiz'] ?></dd>
        <dt>Tugas</dt><dd><?= (int)$ringkas['tugas'] ?></dd>
        <dt>Perlu dikoreksi</dt><dd><?= (int)$ringkas['perlu_koreksi'] ?> jawaban</dd>
      </dl>
      <div class="divider"></div>
      <div class="stack">
        <a class="btn btn-ghost btn-sm" href="guru-koreksi.php"><?= icon('edit', 16) ?> Koreksi Jawaban</a>
        <a class="btn btn-ghost btn-sm" href="guru-progress.php?kursus=<?= (int)$kursusId ?>"><?= icon('chart', 16) ?> Progress Siswa</a>
        <a class="btn btn-ghost btn-sm" href="guru-nilai.php?kursus=<?= (int)$kursusId ?>"><?= icon('trophy', 16) ?> Rekap Nilai</a>
      </div>
    </div>
  </aside>
</div>
<?php page_end(); ?>
