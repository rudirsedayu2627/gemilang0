<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$kursus = db()->query('SELECT e.*, co.judul, co.level, co.id AS cid, co.durasi_jam, co.harga, c.nama AS kategori, us.nama AS guru,
        (SELECT COUNT(*) FROM lessons WHERE course_id = co.id) AS jml_lesson,
        (SELECT COUNT(*) FROM lesson_progress WHERE course_id = co.id AND user_id = e.user_id AND selesai = 1) AS jml_selesai,
        (SELECT COUNT(*) FROM quizzes WHERE course_id = co.id) AS jml_quiz,
        (SELECT COUNT(*) FROM assignments WHERE course_id = co.id) AS jml_tugas
    FROM enrollments e JOIN courses co ON co.id = e.course_id
    LEFT JOIN categories c ON c.id = co.kategori_id LEFT JOIN users us ON us.id = co.guru_id
    WHERE e.user_id = ' . (int)$u['id'] . ' ORDER BY e.status DESC, e.progress DESC')->fetchAll();

$nilaiPerKursus = [];
foreach (db()->query('SELECT qz.course_id, MAX(a.nilai) AS best FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id
    WHERE a.user_id = ' . (int)$u['id'] . ' AND a.status != "in_progress" GROUP BY qz.course_id')->fetchAll() as $r) {
    $nilaiPerKursus[(int)$r['course_id']] = (float)$r['best'];
}

page_start('Kursus Saya', count($kursus) . ' kursus sedang kamu ikuti.');
?>
<?php if (!$kursus): ?>
  <div class="card">
    <?= empty_state('Kamu belum mengikuti kursus apa pun.', 'book') ?>
    <div class="center"><a class="btn btn-primary" href="kursus.php"><?= icon('grid', 17) ?> Pilih Kursus Pertamamu</a></div>
  </div>
<?php endif; ?>

<div class="stack" style="gap:22px">
<?php foreach ($kursus as $k):
    $progress = (float)$k['progress'];
    $terkunci = !in_array($k['status'], ['aktif', 'selesai'], true);
    $cert = sertifikat_aktif() ? db()->query('SELECT * FROM certificates WHERE user_id = ' . (int)$u['id'] . ' AND course_id = ' . (int)$k['cid'])->fetch() : null;
    ?>
  <div class="card">
    <div class="row-between mb3">
      <div>
        <div class="row-gap mb1">
          <span class="chip solid"><?= e($k['kategori'] ?: 'Umum') ?></span>
          <span class="chip"><?= e($k['level']) ?></span>
          <?= $k['status'] === 'menunggu_bayar' ? akses_badge('menunggu_bayar') : status_badge($k['status']) ?>
          <?php if ($cert): ?><span class="badge badge-success"><?= icon('award', 13) ?> Bersertifikat</span><?php endif; ?>
        </div>
        <h2 class="mt0 mb1"><?= e($k['judul']) ?></h2>
        <div class="muted small"><?= icon('user', 13) ?> <?= e($k['guru'] ?: '-') ?> &middot; <?= (int)$k['jml_lesson'] ?> materi &middot; <?= (int)$k['jml_quiz'] ?> quiz &middot; <?= (int)$k['jml_tugas'] ?> tugas</div>
      </div>
      <div class="row-gap">
        <div class="ring" style="--p:<?= round($progress) ?>;width:88px;height:88px"><b><?= round($progress) ?>%</b></div>
      </div>
    </div>

    <div class="progress-lab"><span>Materi selesai: <?= (int)$k['jml_selesai'] ?>/<?= (int)$k['jml_lesson'] ?></span><span>Nilai terbaik: <?= isset($nilaiPerKursus[(int)$k['cid']]) ? number_format($nilaiPerKursus[(int)$k['cid']], 2) : '-' ?></span></div>
    <div class="progress"><span style="width:<?= round($progress) ?>%"></span></div>

    <?php if ($terkunci): ?>
      <div class="flash flash-warning mt2"><?= icon('lock', 17) ?><span>Kursus ini terkunci. Selesaikan pembayaran <?= rupiah($k['harga'] ?? 0) ?> lalu tunggu verifikasi admin untuk membuka materi &amp; quiz.</span></div>
    <?php endif; ?>
    <div class="row-gap mt3">
      <?php if ($terkunci): ?>
        <a class="btn btn-primary" href="siswa-pembayaran.php?kursus=<?= (int)$k['cid'] ?>"><?= icon('wallet', 17) ?> Bayar & Buka Akses</a>
      <?php else: ?>
        <a class="btn btn-primary" href="materi.php?kursus=<?= (int)$k['cid'] ?>"><?= icon('play', 17) ?> <?= $progress > 0 ? 'Lanjut Belajar' : 'Mulai Belajar' ?></a>
      <?php endif; ?>
      <a class="btn btn-ghost" href="kursus-detail.php?id=<?= (int)$k['cid'] ?>"><?= icon('list', 17) ?> Silabus</a>
      <?php if (isset($nilaiPerKursus[(int)$k['cid']])): ?>
        <a class="btn btn-ghost" href="siswa-nilai.php?kursus=<?= (int)$k['cid'] ?>"><?= icon('chart', 17) ?> Nilai</a>
      <?php endif; ?>
      <?php if ($cert): ?>
        <a class="btn btn-success" href="siswa-sertifikat.php?id=<?= (int)$cert['id'] ?>"><?= icon('award', 17) ?> Sertifikat</a>
      <?php elseif ($progress >= 100 && sertifikat_aktif()): ?>
        <span class="badge badge-warning">Selesaikan quiz dengan nilai minimal untuk membuka sertifikat</span>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php page_end(); ?>
