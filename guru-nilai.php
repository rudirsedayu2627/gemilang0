<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login(['guru', 'admin']);
$scope = $u['role'] === 'admin' ? '' : (' AND co.guru_id = ' . (int)$u['id']);

$kursusId = num('kursus');
$quizId = num('quiz');
$where = '1=1' . $scope;
if ($kursusId > 0) {
    $where .= ' AND co.id = ' . $kursusId;
}
if ($quizId > 0) {
    $where .= ' AND qz.id = ' . $quizId;
}

$rows = db()->query("SELECT a.id, a.nilai, a.benar, a.salah, a.kosong, a.status, a.selesai, a.waktu_habis,
        us.nama, us.email, co.judul AS kursus, qz.judul AS quiz, qz.passing_grade, qz.tipe AS tipe_quiz
    FROM quiz_attempts a
    JOIN quizzes qz ON qz.id = a.quiz_id
    JOIN courses co ON co.id = qz.course_id
    LEFT JOIN users us ON us.id = a.user_id
    WHERE $where AND a.status != 'in_progress'
    ORDER BY a.id DESC")->fetchAll();

if (get('export')) {
    $fmt = get('export');
    $head = ['Nama Siswa', 'Email', 'Kursus', 'Quiz', 'Tipe', 'Nilai', 'Min. Lulus', 'Benar', 'Salah', 'Kosong', 'Status', 'Waktu Selesai', 'Lulus'];
    $data = [$head];
    foreach ($rows as $r) {
        $data[] = [
            (string)$r['nama'], (string)$r['email'], (string)$r['kursus'], (string)$r['quiz'], (string)$r['tipe_quiz'],
            number_format((float)$r['nilai'], 2), (string)$r['passing_grade'], (string)$r['benar'], (string)$r['salah'],
            (string)$r['kosong'], (string)$r['status'], (string)$r['selesai'],
            (float)$r['nilai'] >= (float)$r['passing_grade'] ? 'Lulus' : 'Belum lulus',
        ];
    }
    audit('export_hasil', 'Export hasil ujian (' . count($rows) . ' baris, ' . $fmt . ')');
    if ($fmt === 'xlsx') {
        xlsx_download('hasil-ujian-' . date('Ymd-His') . '.xlsx', $data);
    }
    csv_download('hasil-ujian-' . date('Ymd-His') . '.csv', array_shift($data), $data);
}

$rekap = db()->query("SELECT us.id, us.nama, us.email, us.foto,
        COUNT(a.id) AS jml_quiz,
        ROUND(AVG(a.nilai), 2) AS rata,
        MAX(a.nilai) AS tertinggi,
        SUM(CASE WHEN a.nilai >= qz.passing_grade THEN 1 ELSE 0 END) AS lulus_pos,
        COUNT(a.id) AS total_pos
    FROM users us
    JOIN quiz_attempts a ON a.user_id = us.id AND a.status != 'in_progress'
    JOIN quizzes qz ON qz.id = a.quiz_id
    JOIN courses co ON co.id = qz.course_id
    WHERE $where
    GROUP BY us.id ORDER BY rata DESC")->fetchAll();

$daftarKursus = db()->query("SELECT co.id, co.judul FROM courses co WHERE co.status = 'aktif'" . ($u['role'] === 'admin' ? '' : (' AND co.guru_id = ' . (int)$u['id'])) . ' ORDER BY co.judul')->fetchAll();
$daftarQuiz = db()->query("SELECT qz.id, qz.judul, co.judul AS kursus FROM quizzes qz JOIN courses co ON co.id = qz.course_id
    WHERE co.status = 'aktif'" . $scope . ' ORDER BY co.judul, qz.judul')->fetchAll();

$nilaiAll = array_map(function ($r) { return (float)$r['nilai']; }, $rows);
$rataAll = $nilaiAll ? round(array_sum($nilaiAll) / count($nilaiAll), 2) : 0;
$lulusAll = count(array_filter($rows, function ($r) { return (float)$r['nilai'] >= (float)$r['passing_grade']; }));

page_start('Nilai & Hasil Ujian', count($rows) . ' pengerjaan tercatat pada filter ini.', [
    'actions' => '<a class="btn btn-ghost" href="' . e($_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') === false ? '?' : '&') . 'export=csv') . '">' . icon('download', 17) . ' Export CSV</a>'
        . '<a class="btn btn-primary" href="' . e($_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') === false ? '?' : '&') . 'export=xlsx') . '">' . icon('download', 17) . ' Export Excel</a>'
        . '<button class="btn btn-ghost" data-print>' . icon('file', 17) . ' Cetak</button>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('quiz', 22) ?></div><div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lab">Total pengerjaan</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= number_format($rataAll, 1) ?></div><div class="stat-lab">Rata-rata nilai</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= $lulusAll ?></div><div class="stat-lab">Dinyatakan lulus</div></div></div>
  <div class="stat"><div class="stat-ic g6"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= count($rekap) ?></div><div class="stat-lab">Siswa sudah mengerjakan</div></div></div>
</div>

<div class="card">
  <form class="toolbar">
    <div class="form-group" style="min-width:240px">
      <label>Kursus</label>
      <select name="kursus" data-autosubmit>
        <option value="0">Semua kursus saya</option>
        <?php foreach ($daftarKursus as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="min-width:240px">
      <label>Quiz</label>
      <select name="quiz" data-autosubmit>
        <option value="0">Semua quiz</option>
        <?php foreach ($daftarQuiz as $q): ?>
          <option value="<?= (int)$q['id'] ?>" <?= $quizId === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['kursus']) ?> — <?= e($q['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1;min-width:180px">
      <label>Cari siswa</label>
      <input type="search" data-filter-input="#tbl-hasil" placeholder="Nama atau email...">
    </div>
  </form>
</div>

<div class="card">
  <div class="card-head"><h2 class="mt0"><?= icon('trophy', 20) ?> Rekap Nilai per Siswa</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Siswa</th><th>Pengerjaan</th><th>Rata-rata</th><th>Tertinggi</th><th>Kelulusan</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rekap as $r): ?>
        <tr>
          <td><div class="tbl-student"><span class="avatar sm" <?= $r['foto'] ? 'style="background-image:url(' . e($r['foto']) . ')"' : '' ?>><?= $r['foto'] ? '' : e(strtoupper(mb_substr($r['nama'], 0, 1))) ?></span><div><strong><?= e($r['nama']) ?></strong><div class="muted small"><?= e($r['email']) ?></div></div></div></td>
          <td><?= (int)$r['jml_quiz'] ?> quiz</td>
          <td><strong><?= number_format((float)$r['rata'], 2) ?></strong></td>
          <td><?= number_format((float)$r['tertinggi'], 2) ?></td>
          <td><?= (int)$r['lulus_pos'] ?>/<?= (int)$r['total_pos'] ?> lulus</td>
          <td><a class="btn btn-ghost btn-sm" href="guru-progress.php?siswa=<?= (int)$r['id'] ?>">Progress</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rekap): ?><tr><td colspan="6" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2 class="mt0"><?= icon('list', 20) ?> Detail Pengerjaan</h2></div>
  <div class="table-wrap">
    <table class="data" id="tbl-hasil">
      <thead><tr><th>Siswa</th><th>Quiz</th><th>Nilai</th><th>Benar</th><th>Salah</th><th>Kosong</th><th>Status</th><th>Waktu</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e((string)$r['nama']) ?><div class="muted small"><?= e((string)$r['kursus']) ?></div></td>
          <td><?= e($r['quiz']) ?></td>
          <td><strong><?= number_format((float)$r['nilai'], 2) ?></strong><?= (float)$r['nilai'] >= (float)$r['passing_grade'] ? ' <span class="badge badge-success">Lulus</span>' : ' <span class="badge badge-danger">Tidak lulus</span>' ?></td>
          <td><?= (int)$r['benar'] ?></td>
          <td><?= (int)$r['salah'] ?></td>
          <td><?= (int)$r['kosong'] ?></td>
          <td><?= status_badge($r['status']) ?><?= (int)$r['waktu_habis'] ? ' <span class="badge badge-warning">Waktu habis</span>' : '' ?></td>
          <td class="small nowrap"><?= e(tgl_id($r['selesai'], true)) ?></td>
          <td><a class="btn btn-ghost btn-sm" href="ujian-hasil.php?attempt=<?= (int)$r['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9" class="muted">Belum ada pengerjaan.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_end(); ?>
