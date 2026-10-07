<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login(['guru', 'admin']);
$scope = $u['role'] === 'admin' ? '1=1' : ('co.guru_id = ' . (int)$u['id']);

$quizzes = db()->query("SELECT qz.id, qz.judul, qz.course_id, qz.passing_grade, co.judul AS kursus FROM quizzes qz JOIN courses co ON co.id = qz.course_id
    WHERE $scope ORDER BY co.judul, qz.judul")->fetchAll();
$ids = array_map('intval', array_column($quizzes, 'id'));
$quizId = num('quiz');

/* ---- simpan koreksi essay / nilai manual ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $attemptId = (int)post('attempt_id');
    $cek = db()->prepare('SELECT a.*, qz.passing_grade FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.id = ?');
    $cek->execute([$attemptId]);
    $attempt = $cek->fetch();
    if ($attempt && (in_array((int)$attempt['quiz_id'], $ids, true) || $u['role'] === 'admin')) {
        $skor = (array)post('skor', []);
        $fb = (array)post('feedback', []);
        $upd = db()->prepare('UPDATE attempt_answers SET skor = ?, benar = ?, feedback = ? WHERE id = ? AND attempt_id = ?');
        foreach ($skor as $answerId => $val) {
            $val = (float)$val;
            $upd->execute([$val, $val > 0 ? 1 : 0, (string)($fb[$answerId] ?? ''), (int)$answerId, $attemptId]);
        }
        $max = attempt_max_score($attemptId);
        $got = attempt_score($attemptId);
        $nilai = $max > 0 ? round($got / $max * 100, 2) : 0;
        db()->prepare("UPDATE quiz_attempts SET nilai = ?, status = 'dinilai', dinilai_oleh = ?, benar = (SELECT COUNT(*) FROM attempt_answers WHERE attempt_id = ? AND benar = 1) WHERE id = ?")
            ->execute([$nilai, $u['id'], $attemptId, $attemptId]);
        $a = db()->query('SELECT user_id, quiz_id FROM quiz_attempts WHERE id = ' . $attemptId)->fetch();
        notify($a['user_id'], 'Nilai quiz diperbarui', 'Guru sudah mengoreksi jawabanmu. Nilai: ' . number_format($nilai, 2), 'ujian-hasil.php?attempt=' . $attemptId);
        audit('koreksi_essay', 'Mengoreksi attempt ' . $attemptId . ' nilai ' . $nilai);
        flash('Koreksi tersimpan. Nilai: ' . number_format($nilai, 2));
        redirect('guru-koreksi.php?quiz=' . (int)$a['quiz_id'] . '&attempt=' . $attemptId);
    }
    redirect('guru-koreksi.php');
}

/* ---- daftar attempt ---- */
$attemptId = num('attempt');
$attempt = null;
if ($attemptId > 0) {
    $s = db()->prepare('SELECT a.*, qz.judul AS quiz, qz.passing_grade, qz.tipe AS tipe_quiz, qz.course_id, co.judul AS kursus, us.nama, us.email, us.foto
        FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id JOIN courses co ON co.id = qz.course_id LEFT JOIN users us ON us.id = a.user_id WHERE a.id = ?');
    $s->execute([$attemptId]);
    $attempt = $s->fetch();
    if ($attempt && $u['role'] !== 'admin') {
        $own = db()->prepare('SELECT COUNT(*) FROM courses WHERE id = ? AND guru_id = ?');
        $own->execute([$attempt['course_id'], $u['id']]);
        if (!(int)$own->fetchColumn()) {
            $attempt = null;
            flash('Kamu tidak mengampu kursus ini.', 'danger');
        }
    }
}

$where = $quizId > 0 ? ('AND a.quiz_id = ' . (int)$quizId) : '';
$list = db()->query("SELECT a.*, qz.judul AS quiz, qz.passing_grade, qz.course_id, co.judul AS kursus, us.nama, us.foto,
        (SELECT COUNT(*) FROM attempt_answers aa JOIN questions q ON q.id = aa.question_id WHERE aa.attempt_id = a.id AND q.tipe = 'essay') AS jml_essay,
        (SELECT COUNT(*) FROM attempt_answers aa JOIN questions q ON q.id = aa.question_id WHERE aa.attempt_id = a.id AND q.tipe = 'essay' AND aa.skor IS NULL) AS essay_belum
    FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id JOIN courses co ON co.id = qz.course_id LEFT JOIN users us ON us.id = a.user_id
    WHERE a.status != 'in_progress' AND co.guru_id " . ($u['role'] === 'admin' ? 'IS NOT NULL' : '= ' . (int)$u['id']) . " $where
    ORDER BY essay_belum DESC, a.id DESC LIMIT 100")->fetchAll();

$jawaban = [];
if ($attempt) {
    $q = db()->prepare("SELECT aa.*, q.pertanyaan, q.tipe, q.opsi, q.jawaban AS kunci, q.pembahasan, q.skor AS skor_maks, q.penilaian, q.opsi_kategori, q.gambar, q.opsi_gambar
        FROM attempt_answers aa JOIN questions q ON q.id = aa.question_id WHERE aa.attempt_id = ? ORDER BY aa.id");
    $q->execute([$attempt['id']]);
    $jawaban = $q->fetchAll();
}
$belumKoreksi = 0;
foreach ($list as $l) {
    $belumKoreksi += (int)$l['essay_belum'];
}

page_start('Koreksi & Nilai', 'Koreksi jawaban essay, beri skor dan feedback untuk siswa.', [
    'actions' => '<a class="btn btn-ghost" href="guru-nilai.php">' . icon('chart', 17) . ' Rekap Nilai & Export</a>',
]);
?>
<div class="grid cols-3 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('quiz', 22) ?></div><div><div class="stat-num"><?= count($list) ?></div><div class="stat-lab">Pengerjaan tercatat</div></div></div>
  <div class="stat"><div class="stat-ic g6"><?= icon('edit', 22) ?></div><div><div class="stat-num"><?= $belumKoreksi ?></div><div class="stat-lab">Jawaban essay belum dinilai</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= count($list) - ($belumKoreksi > 0 ? count(array_filter($list, function ($x) { return (int)$x['essay_belum'] > 0; })) : 0) ?></div><div class="stat-lab">Sudah dinilai lengkap</div></div></div>
</div>

<div class="card">
  <form class="inline-form">
    <div class="form-group" style="min-width:320px">
      <label>Filter quiz</label>
      <select name="quiz" data-autosubmit>
        <option value="0">Semua quiz saya</option>
        <?php foreach ($quizzes as $q): ?>
          <option value="<?= (int)$q['id'] ?>" <?= $quizId === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['kursus']) ?> — <?= e($q['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1;min-width:200px">
      <label>Cari siswa</label>
      <input type="search" data-filter-input="#tbl-koreksi" placeholder="Nama siswa...">
    </div>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data" id="tbl-koreksi">
      <thead><tr><th>Siswa</th><th>Quiz</th><th>Nilai</th><th>Benar/Salah</th><th>Essay</th><th>Status</th><th>Waktu</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $a): ?>
        <tr>
          <td><div class="tbl-student"><span class="avatar sm" <?= $a['foto'] ? 'style="background-image:url(' . e($a['foto']) . ')"' : '' ?>><?= $a['foto'] ? '' : e(strtoupper(mb_substr((string)$a['nama'], 0, 1))) ?></span> <?= e((string)$a['nama']) ?></div></td>
          <td><?= e($a['quiz']) ?><div class="muted small"><?= e($a['kursus']) ?></div></td>
          <td><strong><?= number_format((float)$a['nilai'], 2) ?></strong><div class="muted small">min <?= (int)$a['passing_grade'] ?></div></td>
          <td><?= (int)$a['benar'] ?> / <?= (int)$a['salah'] ?></td>
          <td><?= (int)$a['jml_essay'] ?> soal<?= (int)$a['essay_belum'] ? ' <span class="badge badge-warning">' . (int)$a['essay_belum'] . ' belum</span>' : '' ?></td>
          <td><?= status_badge($a['status']) ?></td>
          <td class="small nowrap"><?= e(tgl_id($a['selesai'] ?: $a['created_at'], true)) ?></td>
          <td><a class="btn btn-primary btn-sm" href="guru-koreksi.php?quiz=<?= (int)$quizId ?>&attempt=<?= (int)$a['id'] ?>">Koreksi</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="8" class="muted">Belum ada pengerjaan pada quiz ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($attempt): ?>
<div class="card">
  <div class="card-head">
    <div>
      <h2 class="mt0 mb0">Koreksi: <?= e($attempt['quiz']) ?></h2>
      <div class="muted small"><?= e((string)$attempt['nama']) ?> &middot; <?= e($attempt['kursus']) ?> &middot; dikumpulkan <?= e(tgl_id($attempt['selesai'] ?: $attempt['created_at'], true)) ?></div>
    </div>
    <div class="row-gap">
      <span class="chip">Nilai sekarang: <?= number_format((float)$attempt['nilai'], 2) ?></span>
      <span class="chip">Min. lulus: <?= (int)$attempt['passing_grade'] ?></span>
      <?= status_badge($attempt['status']) ?>
    </div>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>">
    <?php foreach ($jawaban as $i => $j):
        $opsi = $j['opsi'] ? json_decode($j['opsi'], true) : []; ?>
      <div class="q-block">
        <div class="q-num">Soal <?= $i + 1 ?> &middot; <span class="muted"><?= e(tipe_soal_label($j['tipe'])) ?> &middot; maks <?= (float)$j['skor_maks'] ?> poin</span>
          <?php if ($j['tipe'] !== 'essay'): ?><?= (int)$j['benar'] === 1 ? '<span class="badge badge-success">Benar</span>' : '<span class="badge badge-danger">Salah</span>' ?><?php endif; ?>
        </div>
        <div class="q-text"><?= nl2br(e($j['pertanyaan'])) ?></div>
        <?= gambar_img(soal_gambar($j), 'q-img', 'Gambar soal') ?>
        <?php $gambarOpsi = soal_gambar_opsi($j); ?>
        <?php if ($j['tipe'] === 'mc'): ?>
          <div class="muted small mb1">Jawaban siswa: <strong><?= e($opsi[(int)$j['jawaban']] ?? '-') ?></strong> &middot; Kunci: <strong><?= e($opsi[(int)$j['kunci']] ?? '-') ?></strong></div>
          <?php if ($gambarOpsi): ?>
            <div class="opt-gallery">
              <?php foreach ($gambarOpsi as $gi => $gu): ?>
                <figure class="opt-thumb"><img src="<?= e($gu) ?>" alt="Gambar pilihan <?= chr(65 + (int)$gi) ?>" data-lightbox loading="lazy"><figcaption>Pilihan <?= chr(65 + (int)$gi) ?></figcaption></figure>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php elseif ($j['tipe'] === 'mcma'): ?>
          <?php
          $pilih = $j['jawaban'] ? array_map('strval', json_decode($j['jawaban'], true) ?: []) : [];
          $kunciArr = array_map('strval', json_decode((string)$j['kunci'], true) ?: []);
          ?>
          <div class="mb1">
            <?php foreach ($opsi as $idx => $o):
                $isKunci = in_array((string)$idx, $kunciArr, true);
                $isPilih = in_array((string)$idx, $pilih, true);
                $cls = $isKunci ? 'correct' : ($isPilih ? 'wrong' : ''); ?>
              <div class="opt <?= $cls ?>" style="padding:8px 12px;margin-bottom:6px"><span class="opt-body"><span><?= e($o) ?><?= $isKunci ? ' <strong>&#10003; kunci</strong>' : '' ?><?= $isPilih ? ' <em>(dipilih siswa)</em>' : '' ?></span><?= gambar_img($gambarOpsi[(string)$idx] ?? '', 'opt-img', 'Gambar pilihan') ?></span></div>
            <?php endforeach; ?>
          </div>
          <div class="muted small mb1">Skor otomatis: <strong><?= number_format((float)$j['skor'], 2) ?></strong> / <?= (float)$j['skor_maks'] ?> &middot; penilaian <?= e($j['penilaian'] === 'parsial' ? 'sebagian' : 'harus tepat semua') ?></div>
        <?php elseif ($j['tipe'] === 'kategori'): ?>
          <?php
          $kats = soal_kategori_list($j);
          $items = soal_item_list($j);
          $jw = $j['jawaban'] ? (json_decode($j['jawaban'], true) ?: []) : [];
          $kunciArr = json_decode((string)$j['kunci'], true) ?: [];
          ?>
          <div class="table-wrap mb2">
            <table class="data">
              <thead><tr><th>Item</th><th>Jawaban siswa</th><th>Kunci</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($items as $idx => $item):
                  $pilihKat = $jw[(string)$idx] ?? ($jw[$idx] ?? null);
                  $kunciKat = $kunciArr[(string)$idx] ?? ($kunciArr[$idx] ?? null);
                  $benarKat = $pilihKat !== null && $pilihKat !== '' && $kunciKat !== null && (string)$pilihKat === (string)$kunciKat; ?>
                <tr>
                  <td><?= e($item) ?></td>
                  <td><?= $pilihKat === null || $pilihKat === '' ? '<span class="muted">-</span>' : e($kats[(int)$pilihKat] ?? '-') ?></td>
                  <td><strong><?= e($kunciKat === null ? '-' : ($kats[(int)$kunciKat] ?? '-')) ?></strong></td>
                  <td><?= $benarKat ? icon('check', 15) : icon('x', 15) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="muted small mb1">Skor otomatis: <strong><?= number_format((float)$j['skor'], 2) ?></strong> / <?= (float)$j['skor_maks'] ?> &middot; <?= count($items) ?> item (skor dibagi rata per item)</div>
        <?php elseif ($j['tipe'] === 'matching'): ?>
          <?php $jw = $j['jawaban'] ? json_decode($j['jawaban'], true) : []; ?>
          <div class="muted small mb1">
            <?php foreach ($opsi as $idx => $p): $x = explode('|', (string)$p); ?>
              <div><?= e($x[0] ?? '') ?> &rarr; jawaban: <strong><?= e((string)($jw[(string)$idx] ?? ($jw[$idx] ?? '-'))) ?></strong> (kunci: <?= e($x[1] ?? '') ?>)</div>
            <?php endforeach; ?>
          </div>
        <?php elseif ($j['tipe'] !== 'essay'): ?>
          <div class="muted small mb1">Jawaban siswa: <strong><?= e((string)$j['jawaban']) ?></strong> &middot; Kunci: <strong><?= e((string)$j['kunci']) ?></strong></div>
        <?php else: ?>
          <div class="dark-box mb2"><?= nl2br(e((string)$j['jawaban'] ?: '(tidak dijawab)')) ?></div>
        <?php endif; ?>

        <?php if ($j['tipe'] === 'essay'): ?>
          <div class="form-grid">
            <div class="form-group">
              <label>Skor (maks <?= (float)$j['skor_maks'] ?>)</label>
              <input type="number" name="skor[<?= (int)$j['id'] ?>]" step="0.5" min="0" max="<?= (float)$j['skor_maks'] ?>" value="<?= e((string)($j['skor'] ?? '')) ?>">
            </div>
            <div class="form-group">
              <label>Feedback untuk siswa</label>
              <input type="text" name="feedback[<?= (int)$j['id'] ?>]" value="<?= e((string)$j['feedback']) ?>" placeholder="Mis. Grammar bagus, perhatikan ejaan.">
            </div>
          </div>
        <?php else: ?>
          <div class="muted small">Skor otomatis: <strong><?= number_format((float)$j['skor'], 2) ?></strong> / <?= (float)$j['skor_maks'] ?><?= $j['feedback'] ? ' &middot; feedback: ' . e($j['feedback']) : '' ?></div>
        <?php endif; ?>

        <?php if ($j['pembahasan']): ?><div class="muted small mt1"><?= icon('chat', 13) ?> Pembahasan: <?= e($j['pembahasan']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="row-gap">
      <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Koreksi & Hitung Nilai</button>
      <a class="btn btn-ghost" href="ujian-hasil.php?attempt=<?= (int)$attempt['id'] ?>"><?= icon('chart', 17) ?> Lihat hasil lengkap</a>
    </div>
  </form>
</div>
<?php endif; ?>
<?php page_end(); ?>
