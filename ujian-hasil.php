<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$attemptId = num('attempt');
$quizId = num('quiz');

if ($attemptId > 0) {
    $stmt = db()->prepare('SELECT a.*, qz.judul AS quiz, qz.tipe, qz.passing_grade, qz.tampilkan_pembahasan, qz.course_id, qz.id AS quiz_id, co.judul AS kursus, us.nama AS siswa, us.id AS siswa_id FROM quiz_attempts a
        JOIN quizzes qz ON qz.id = a.quiz_id JOIN courses co ON co.id = qz.course_id JOIN users us ON us.id = a.user_id WHERE a.id = ?');
    $stmt->execute([$attemptId]);
    $a = $stmt->fetch();
    if (!$a) {
        http_response_code(404);
        page_start('Hasil tidak ditemukan');
        echo '<div class="card">' . empty_state('Data hasil ujian tidak ditemukan.', 'search') . '</div>';
        page_end();
        exit;
    }
    $mine = (int)$a['user_id'] === (int)$u['id'];
    $isGuru = in_array($u['role'], ['guru', 'admin'], true);
    if (!$mine && !$isGuru) {
        http_response_code(403);
        include __DIR__ . '/includes/denied.php';
        exit;
    }
} else {
    // daftar hasil untuk siswa pada satu quiz
    $stmt = db()->prepare('SELECT a.*, qz.judul AS quiz, qz.passing_grade, co.judul AS kursus FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id LEFT JOIN courses co ON co.id = qz.course_id WHERE a.quiz_id = ? AND a.user_id = ? ORDER BY a.id DESC');
    $stmt->execute([$quizId, $u['id']]);
    $list = $stmt->fetchAll();
    page_start('Hasil Quiz', 'Riwayat pengerjaan quiz');
    echo '<div class="card"><div class="card-head"><h2 class="mt0">' . icon('chart', 20) . ' Riwayat Percobaan</h2></div>';
    if (!$list) {
        echo empty_state('Belum ada percobaan untuk quiz ini.', 'quiz');
    } else {
        echo '<div class="table-wrap"><table class="data"><thead><tr><th>Tanggal</th><th>Nilai</th><th>Benar</th><th>Salah</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($list as $r) {
            echo '<tr><td>' . e(tgl_id($r['selesai'] ?: $r['created_at'], true)) . '</td><td><strong>' . number_format((float)$r['nilai'], 2) . '</strong></td><td>' . (int)$r['benar'] . '</td><td>' . (int)$r['salah'] . '</td><td>' . status_badge($r['status']) . '</td><td><a class="btn btn-ghost btn-sm" href="ujian-hasil.php?attempt=' . (int)$r['id'] . '">Detail</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    page_end();
    exit;
}

$ansStmt = db()->prepare('SELECT aa.*, q.tipe, q.pertanyaan, q.opsi, q.jawaban AS kunci, q.pembahasan, q.skor AS skor_maks, q.penilaian, q.opsi_kategori FROM attempt_answers aa JOIN questions q ON q.id = aa.question_id WHERE aa.attempt_id = ? ORDER BY aa.id');
$ansStmt->execute([$a['id']]);
$jawaban = $ansStmt->fetchAll();
$lulus = (float)$a['nilai'] >= (float)$a['passing_grade'];
$tampilPembahasan = (int)$a['tampilkan_pembahasan'] === 1 || in_array($u['role'], ['guru', 'admin'], true);

page_start('Hasil: ' . $a['quiz'], $a['kursus'] . ' · ' . tgl_id($a['selesai'] ?: $a['created_at'], true));
?>
<div class="card">
  <div class="row-between">
    <div class="row-gap">
      <div class="ring" style="--p:<?= min(100, (float)$a['nilai']) ?>"><b><?= number_format((float)$a['nilai'], 0) ?></b></div>
      <div>
        <h2 class="mt0 mb1"><?= $lulus ? 'Selamat, kamu lulus! 🎉' : 'Belum lulus, ayo coba lagi!' ?></h2>
        <p class="muted mb2">Nilai minimal kelulusan <?= (int)$a['passing_grade'] ?>. <?= $a['status'] === 'menunggu_koreksi' ? 'Nilai masih sementara karena menunggu koreksi essay dari guru.' : '' ?></p>
        <div class="row-gap">
          <span class="chip"><?= icon('check', 14) ?> Benar: <?= (int)$a['benar'] ?></span>
          <span class="chip"><?= icon('x', 14) ?> Salah: <?= (int)$a['salah'] ?></span>
          <span class="chip"><?= icon('file', 14) ?> Kosong: <?= (int)$a['kosong'] ?></span>
          <span class="chip"><?= icon('clock', 14) ?> <?= (int)$a['waktu_habis'] ? 'Waktu habis' : 'Selesai normal' ?></span>
          <?= status_badge($a['status']) ?>
        </div>
      </div>
    </div>
    <div class="row-gap">
      <?php if ($u['role'] === 'siswa'): ?>
        <a class="btn btn-primary" href="ujian.php?quiz=<?= (int)$a['quiz_id'] ?>"><?= icon('refresh', 17) ?> Kerjakan Lagi</a>
      <?php else: ?>
        <a class="btn btn-ghost" href="guru-koreksi.php?quiz=<?= (int)$a['quiz_id'] ?>"><?= icon('edit', 17) ?> Koreksi Jawaban</a>
      <?php endif; ?>
      <a class="btn btn-ghost" href="kursus-detail.php?id=<?= (int)$a['course_id'] ?>"><?= icon('book', 17) ?> Detail Kursus</a>
    </div>
  </div>
  <?php if (!$mine): ?>
    <div class="divider"></div>
    <p class="muted small mb0">Menampilkan hasil milik <strong><?= e($a['siswa']) ?></strong> (mode guru/admin).</p>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head"><h2 class="mt0"><?= icon('list', 20) ?> Pembahasan Jawaban</h2><span class="muted small"><?= count($jawaban) ?> soal</span></div>
  <?php if (!$tampilPembahasan): ?>
    <div class="flash flash-info"><?= icon('bell', 17) ?><span>Pembahasan tidak ditampilkan untuk quiz ini.</span></div>
  <?php endif; ?>
  <?php foreach ($jawaban as $i => $j):
      $opsi = $j['opsi'] ? json_decode($j['opsi'], true) : [];
      $jwb = $j['jawaban'];
      $kunci = $j['kunci'];
      ?>
    <div class="q-block">
      <div class="q-num">Soal <?= $i + 1 ?> &middot; <span class="muted"><?= e(tipe_soal_label($j['tipe'])) ?></span>
        <?php if ($j['tipe'] !== 'essay'): ?><?php
          $kosongJwb = ($j['jawaban'] === null || trim((string)$j['jawaban']) === '' || $j['jawaban'] === '[]' || $j['jawaban'] === '{}');
          $skorPart = (float)$j['skor'];
          $maksPart = (float)$j['skor_maks'];
          if ($kosongJwb) {
              echo '<span class="badge badge-muted">Tidak dijawab</span>';
          } elseif ((int)$j['benar'] === 1 || ($maksPart > 0 && $skorPart >= $maksPart)) {
              echo '<span class="badge badge-success">Benar</span>';
          } elseif ($skorPart > 0) {
              echo '<span class="badge badge-warning">Sebagian benar &middot; ' . number_format($skorPart, 2) . '/' . number_format($maksPart, 2) . '</span>';
          } else {
              echo '<span class="badge badge-danger">Salah</span>';
          }
          ?><?php else: ?><?= $j['skor'] !== null && (float)$j['skor'] > 0 ? '<span class="badge badge-info">Skor ' . number_format((float)$j['skor'], 2) . '</span>' : status_badge('menunggu_koreksi') ?><?php endif; ?>
        <span class="muted">&middot; <?= number_format((float)$j['skor'], 2) ?>/<?= number_format((float)$j['skor_maks'], 2) ?> poin</span>
      </div>
      <div class="q-text"><?= nl2br(e($j['pertanyaan'])) ?></div>

      <?php if ($j['tipe'] === 'mc'): ?>
        <?php foreach ($opsi as $idx => $o):
            $cls = '';
            if ((string)$idx === (string)$kunci) { $cls = 'correct'; }
            elseif ((string)$idx === (string)$jwb) { $cls = 'wrong'; } ?>
          <div class="opt <?= $cls ?>">
            <span><?= e($o) ?><?= (string)$idx === (string)$kunci ? ' <strong>&#10003; kunci</strong>' : '' ?><?= (string)$idx === (string)$jwb && (string)$idx !== (string)$kunci ? ' <em>(jawabanmu)</em>' : '' ?></span>
          </div>
        <?php endforeach; ?>
      <?php elseif ($j['tipe'] === 'tf'): ?>
        <div class="opt <?= normalize_answer($jwb) === normalize_answer($kunci) ? 'correct' : 'wrong' ?>"><span>Jawabanmu: <strong><?= e($jwb ?: '-') ?></strong> &middot; Kunci: <strong><?= e($kunci) ?></strong></span></div>
      <?php elseif ($j['tipe'] === 'mcma'): ?>
        <?php
        $pilih = $jwb ? array_map('strval', json_decode($jwb, true) ?: []) : [];
        $kunciArr = array_map('strval', json_decode((string)$kunci, true) ?: []);
        ?>
        <?php foreach ($opsi as $idx => $o):
            $isKunci = in_array((string)$idx, $kunciArr, true);
            $isPilih = in_array((string)$idx, $pilih, true);
            // hijau = bagian dari kunci, merah = dipilih tapi bukan kunci
            $cls = $isKunci ? 'correct' : ($isPilih ? 'wrong' : ''); ?>
          <div class="opt <?= $cls ?>">
            <span>
              <?= e($o) ?>
              <?= $isKunci ? ' <strong>&#10003; kunci</strong>' : '' ?>
              <?= $isPilih ? ' <em>(kamu pilih)</em>' : '' ?>
              <?= $isKunci && !$isPilih ? ' <em>— belum kamu pilih</em>' : '' ?>
            </span>
          </div>
        <?php endforeach; ?>
        <div class="muted small mt1">Pilihanmu: <strong><?= $pilih ? e(implode(', ', array_map(function ($x) { return chr(65 + (int)$x); }, $pilih))) : '-' ?></strong> &middot; Kunci: <strong><?= e(implode(', ', array_map(function ($x) { return chr(65 + (int)$x); }, $kunciArr))) ?></strong><?= ($j['penilaian'] ?? 'penuh') === 'parsial' ? ' &middot; penilaian sebagian' : ' &middot; harus tepat semua' ?></div>

      <?php elseif ($j['tipe'] === 'kategori'): ?>
        <?php
        $kats = soal_kategori_list($j);
        $items = soal_item_list($j);
        $jw = $jwb ? (json_decode($jwb, true) ?: []) : [];
        $kunciArr = json_decode((string)$kunci, true) ?: [];
        ?>
        <div class="table-wrap mb2">
          <table class="data">
            <thead><tr><th>Item</th><th>Jawabanmu</th><th>Kunci</th><th></th></tr></thead>
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

      <?php elseif ($j['tipe'] === 'matching'): ?>
        <?php $jw = $jwb ? json_decode($jwb, true) : []; ?>
        <div class="table-wrap mb2">
          <table class="data">
            <thead><tr><th>Kiri</th><th>Jawabanmu</th><th>Kunci</th></tr></thead>
            <tbody>
            <?php foreach ($opsi as $idx => $p):
                $parts = explode('|', (string)$p); ?>
              <tr>
                <td><?= e($parts[0] ?? '') ?></td>
                <td><?= e($jw[(string)$idx] ?? ($jw[$idx] ?? '-')) ?></td>
                <td><strong><?= e($parts[1] ?? '') ?></strong></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php elseif ($j['tipe'] === 'essay'): ?>
        <div class="dark-box mb2"><?= nl2br(e($jwb ?: '(tidak dijawab)')) ?></div>
        <?php if ($j['feedback']): ?><div class="flash flash-info"><?= icon('chat', 17) ?><span>Feedback guru: <?= e($j['feedback']) ?></span></div><?php endif; ?>
      <?php else: ?>
        <div class="opt <?= normalize_answer($jwb) === normalize_answer($kunci) ? 'correct' : 'wrong' ?>"><span>Jawabanmu: <strong><?= e($jwb ?: '-') ?></strong> &middot; Kunci: <strong><?= e($kunci) ?></strong></span></div>
      <?php endif; ?>

      <?php if ($tampilPembahasan && $j['pembahasan']): ?>
        <div class="notice mt2" style="margin-bottom:0">
          <div class="notice-ic"><?= icon('chat', 17) ?></div>
          <div><h4>Pembahasan</h4><p><?= nl2br(e($j['pembahasan'])) ?></p></div>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php page_end(); ?>
