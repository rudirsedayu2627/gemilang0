<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$kursusId = num('kursus');
$where = 'a.user_id = ' . (int)$u['id'] . ' AND a.status != "in_progress"';
if ($kursusId > 0) {
    $where .= ' AND qz.course_id = ' . $kursusId;
}

if (get('export') === 'csv') {
    $rows = db()->query("SELECT a.id, co.judul AS kursus, qz.judul AS quiz, a.nilai, a.benar, a.salah, a.kosong, a.status, a.selesai
        FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id JOIN courses co ON co.id = qz.course_id WHERE $where ORDER BY a.id DESC")->fetchAll();
    foreach ($rows as &$r) {
        $r = [$r['kursus'], $r['quiz'], number_format((float)$r['nilai'], 2), $r['benar'], $r['salah'], $r['kosong'], $r['status'], $r['selesai']];
    }
    audit('export_nilai', 'Mengunduh riwayat nilai (CSV)');
    csv_download('riwayat-nilai-' . date('Ymd') . '.csv', ['Kursus', 'Quiz', 'Nilai', 'Benar', 'Salah', 'Kosong', 'Status', 'Selesai'], $rows);
}

$rows = db()->query("SELECT a.*, qz.judul AS quiz, qz.passing_grade, qz.tipe, co.judul AS kursus, co.id AS course_id
    FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id JOIN courses co ON co.id = qz.course_id
    WHERE $where ORDER BY a.id DESC")->fetchAll();

$perKursus = [];
foreach ($rows as $r) {
    $perKursus[$r['kursus']][] = $r;
}
$nilaiAll = array_map(function ($r) { return (float)$r['nilai']; }, $rows);
$rata = $nilaiAll ? round(array_sum($nilaiAll) / count($nilaiAll), 2) : 0;
$tertinggi = $nilaiAll ? max($nilaiAll) : 0;
$lulus = 0;
foreach ($rows as $r) {
    if ((float)$r['nilai'] >= (float)$r['passing_grade']) {
        $lulus++;
    }
}
$daftarKursus = db()->query('SELECT DISTINCT co.id, co.judul FROM enrollments e JOIN courses co ON co.id = e.course_id WHERE e.user_id = ' . (int)$u['id'])->fetchAll();

page_start('Nilai & Riwayat', 'Semua hasil quiz dan ujian yang kamu kerjakan.', [
    'actions' => '<a class="btn btn-ghost" href="siswa-nilai.php?export=csv' . ($kursusId ? '&kursus=' . $kursusId : '') . '">' . icon('download', 17) . ' Export CSV</a>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('quiz', 22) ?></div><div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lab">Total pengerjaan</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= number_format($rata, 1) ?></div><div class="stat-lab">Rata-rata nilai</div></div></div>
  <div class="stat"><div class="stat-ic g4"><?= icon('trophy', 22) ?></div><div><div class="stat-num"><?= number_format($tertinggi, 1) ?></div><div class="stat-lab">Nilai tertinggi</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= $lulus ?></div><div class="stat-lab">Dinyatakan lulus</div></div></div>
</div>

<div class="card">
  <form class="toolbar" method="get">
    <div class="form-group">
      <label>Filter kursus</label>
      <select name="kursus" data-autosubmit>
        <option value="0">Semua kursus</option>
        <?php foreach ($daftarKursus as $dk): ?>
          <option value="<?= (int)$dk['id'] ?>" <?= $kursusId === (int)$dk['id'] ? 'selected' : '' ?>><?= e($dk['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <a class="btn btn-ghost" href="siswa-nilai.php">Reset</a>
  </form>

  <?php if (!$rows): ?>
    <?= empty_state('Belum ada nilai. Kerjakan quiz pertamamu untuk melihat riwayat di sini.', 'chart') ?>
  <?php endif; ?>

  <?php foreach ($perKursus as $namaKursus => $items): ?>
    <h3 class="mt3"><?= icon('book', 18) ?> <?= e($namaKursus) ?></h3>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Quiz / Ujian</th><th>Nilai</th><th>Min. Lulus</th><th>Benar</th><th>Salah</th><th>Status</th><th>Tanggal</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $r): ?>
          <tr>
            <td><strong><?= e($r['quiz']) ?></strong> <?= $r['tipe'] === 'ujian' ? '<span class="badge badge-warning">Ujian</span>' : '' ?></td>
            <td><strong><?= number_format((float)$r['nilai'], 2) ?></strong></td>
            <td><?= (int)$r['passing_grade'] ?></td>
            <td><?= (int)$r['benar'] ?></td>
            <td><?= (int)$r['salah'] ?></td>
            <td><?= (float)$r['nilai'] >= (float)$r['passing_grade'] ? '<span class="badge badge-success">Lulus</span>' : '<span class="badge badge-danger">Belum lulus</span>' ?> <?= status_badge($r['status']) ?></td>
            <td class="nowrap small"><?= e(tgl_id($r['selesai'] ?: $r['created_at'], true)) ?></td>
            <td><a class="btn btn-ghost btn-sm" href="ujian-hasil.php?attempt=<?= (int)$r['id'] ?>">Pembahasan</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
</div>
<?php page_end(); ?>
