<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$kursus = db()->query('SELECT e.*, co.judul, co.level, co.id AS cid, c.nama AS kategori, us.nama AS guru,
        (SELECT COUNT(*) FROM lessons WHERE course_id = co.id) AS jml_lesson
    FROM enrollments e JOIN courses co ON co.id = e.course_id
    LEFT JOIN categories c ON c.id = co.kategori_id LEFT JOIN users us ON us.id = co.guru_id
    WHERE e.user_id = ' . (int)$u['id'] . ' ORDER BY e.enrolled_at DESC')->fetchAll();

$tugasBelum = (int)db()->query('SELECT COUNT(*) FROM assignments a JOIN enrollments e ON e.course_id = a.course_id AND e.user_id = ' . (int)$u['id'] . '
    LEFT JOIN assignment_submissions s ON s.assignment_id = a.id AND s.user_id = ' . (int)$u['id'] . ' WHERE s.id IS NULL')->fetchColumn();
$nilaiRata = course_average_score((int)$u['id'], 0);
$nilaiRows = db()->query('SELECT a.nilai FROM quiz_attempts a WHERE a.user_id = ' . (int)$u['id'] . ' AND a.status != "in_progress"')->fetchAll(PDO::FETCH_COLUMN);
$rata = $nilaiRows ? round(array_sum($nilaiRows) / count($nilaiRows), 2) : 0;
$sertifikat = sertifikat_aktif() ? (int)db()->query('SELECT COUNT(*) FROM certificates WHERE user_id = ' . (int)$u['id'])->fetchColumn() : 0;
$progressRata = $kursus ? round(array_sum(array_column($kursus, 'progress')) / count($kursus), 1) : 0;
$quizTersedia = db()->query('SELECT qz.*, co.judul AS kursus FROM quizzes qz JOIN courses co ON co.id = qz.course_id
    JOIN enrollments e ON e.course_id = co.id AND e.user_id = ' . (int)$u['id'] . '
    WHERE qz.status = "aktif" AND NOT EXISTS (SELECT 1 FROM quiz_attempts a WHERE a.quiz_id = qz.id AND a.user_id = ' . (int)$u['id'] . ' AND a.status != "in_progress")
    ORDER BY qz.id LIMIT 4')->fetchAll();
$jadwal = db()->query('SELECT * FROM schedules WHERE user_id = ' . (int)$u['id'] . ' AND tanggal >= date("now") ORDER BY tanggal, jam_mulai LIMIT 5')->fetchAll();
$pengumuman = db()->query('SELECT * FROM announcements WHERE course_id IS NULL OR course_id IN (SELECT course_id FROM enrollments WHERE user_id = ' . (int)$u['id'] . ') ORDER BY pinned DESC, created_at DESC LIMIT 3')->fetchAll();

// Tagihan pembayaran yang belum lunas (kursus berbayar dengan akses terkunci)
$tagihanSaya = db()->query("SELECT co.id, co.judul, co.harga, e.status AS status_daftar,
        (SELECT p.status FROM payments p WHERE p.user_id = e.user_id AND p.course_id = co.id ORDER BY p.id DESC LIMIT 1) AS status_bayar,
        (SELECT COUNT(*) FROM payments p WHERE p.user_id = e.user_id AND p.course_id = co.id AND p.status = 'menunggu') AS ada_menunggu
    FROM enrollments e JOIN courses co ON co.id = e.course_id
    WHERE e.user_id = " . (int)$u['id'] . " AND e.status NOT IN ('aktif','selesai') AND co.harga > 0
    ORDER BY co.judul")->fetchAll();
$nominalTagihan = 0;
$belumBayar = 0;
foreach ($tagihanSaya as $t) {
    if ((int)$t['ada_menunggu'] === 0) {
        $nominalTagihan += (float)$t['harga'];
        $belumBayar++;
    }
}

page_start('Dashboard Siswa', 'Halo ' . $u['nama'] . ', lanjutkan belajar bahasa Inggrismu hari ini!', [
    'actions' => '<a class="btn btn-ghost" href="kursus.php">' . icon('grid', 17) . ' Katalog Kursus</a>'
        . '<a class="btn btn-primary" href="siswa-kursus.php">' . icon('play', 17) . ' Lanjut Belajar</a>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('book', 22) ?></div><div><div class="stat-num"><?= count($kursus) ?></div><div class="stat-lab">Kursus diikuti</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= number_format($rata, 1) ?></div><div class="stat-lab">Rata-rata nilai</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= number_format($progressRata, 0) ?>%</div><div class="stat-lab">Progress belajar</div></div></div>
  <?php if (sertifikat_aktif()): ?><div class="stat"><div class="stat-ic g4"><?= icon('award', 22) ?></div><div><div class="stat-num"><?= $sertifikat ?></div><div class="stat-lab">Sertifikat</div></div></div><?php endif; ?>
</div>

<?php if ($tagihanSaya): ?>
  <div class="card" style="border-left:4px solid var(--amber)">
    <div class="row-between mb2">
      <div class="row-gap">
        <div class="stat-ic g4"><?= icon('wallet', 22) ?></div>
        <div>
          <h3 class="mt0 mb0"><?= count($tagihanSaya) ?> kursus menunggu pembayaran</h3>
          <div class="muted small">Materi &amp; quiz kursus berbayar terbuka otomatis setelah pembayaran diverifikasi admin.</div>
        </div>
      </div>
      <a class="btn btn-primary" href="siswa-pembayaran.php"><?= icon('wallet', 17) ?> Buka Halaman Pembayaran</a>
    </div>
    <?php foreach ($tagihanSaya as $t): ?>
      <div class="lesson-item mb1">
        <span class="lesson-ic"><?= icon('lock', 17) ?></span>
        <span class="lesson-meta">
          <strong><?= e($t['judul']) ?></strong>
          <small><?= rupiah($t['harga']) ?> &middot; <?= (int)$t['ada_menunggu'] > 0 ? 'bukti sudah dikirim, menunggu verifikasi admin' : 'belum dibayar' ?></small>
        </span>
        <span style="margin-left:auto"><a class="btn btn-ghost btn-sm" href="siswa-pembayaran.php?kursus=<?= (int)$t['id'] ?>"><?= (int)$t['ada_menunggu'] > 0 ? 'Lihat status' : 'Bayar' ?></a></span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="split">
  <div>
    <div class="card">
      <div class="card-head"><h2><?= icon('book', 20) ?> Kursus Saya</h2><a class="btn btn-ghost btn-sm" href="siswa-kursus.php">Lihat semua</a></div>
      <?php if (!$kursus): ?>
        <?= empty_state('Kamu belum mengikuti kursus apa pun. Yuk pilih kursus pertamamu!', 'book') ?>
        <div class="center"><a class="btn btn-primary" href="kursus.php">Jelajahi Katalog Kursus</a></div>
      <?php endif; ?>
      <div class="grid cols-2">
        <?php foreach (array_slice($kursus, 0, 4) as $k): ?>
          <div class="card tight flat">
            <div class="row-gap mb2" style="justify-content:space-between">
              <span class="chip"><?= e($k['kategori'] ?: 'Umum') ?></span>
              <span class="badge <?= (float)$k['progress'] >= 100 ? 'badge-success' : 'badge-info' ?>"><?= round((float)$k['progress']) ?>%</span>
            </div>
            <strong><?= e($k['judul']) ?></strong>
            <div class="muted small mb2"><?= icon('user', 13) ?> <?= e($k['guru'] ?: '-') ?> &middot; <?= (int)$k['jml_lesson'] ?> materi</div>
            <div class="progress"><span style="width:<?= round((float)$k['progress']) ?>%"></span></div>
            <a class="btn btn-primary btn-sm mt2 btn-block" href="materi.php?kursus=<?= (int)$k['cid'] ?>">Lanjut Belajar</a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= icon('quiz', 20) ?> Quiz Menunggu Dikerjakan</h2></div>
      <?php if (!$quizTersedia): ?>
        <p class="muted small">Semua quiz untuk kursusmu sudah dikerjakan. Kerja bagus! 🎉</p>
      <?php endif; ?>
      <div class="lesson-stack">
        <?php foreach ($quizTersedia as $qz): ?>
          <div class="lesson-item">
            <span class="lesson-ic"><?= icon('quiz', 18) ?></span>
            <span class="lesson-meta"><strong><?= e($qz['judul']) ?></strong><small><?= e($qz['kursus']) ?> &middot; <?= (int)$qz['durasi_menit'] ?> menit &middot; minimal <?= (int)$qz['passing_grade'] ?></small></span>
            <span style="margin-left:auto"><a class="btn btn-primary btn-sm" href="ujian.php?quiz=<?= (int)$qz['id'] ?>">Kerjakan</a></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card-head"><h3 class="mt0"><?= icon('calendar', 18) ?> Jadwal Terdekat</h3><a class="small" href="siswa-kalender.php">Kalender</a></div>
      <?php if (!$jadwal): ?><p class="muted small">Belum ada agenda belajar.</p><?php endif; ?>
      <?php foreach ($jadwal as $j): ?>
        <div class="row-gap mb2" style="align-items:flex-start">
          <div class="leader-rank" style="background:<?= e($j['color'] ?: '#4F46E5') ?>;color:#fff"><?= date('d', strtotime($j['tanggal'])) ?></div>
          <div><strong class="small"><?= e($j['judul']) ?></strong><div class="muted small"><?= e(hari_id($j['tanggal'])) ?>, <?= e(substr((string)$j['jam_mulai'], 0, 5)) ?>-<?= e(substr((string)$j['jam_selesai'], 0, 5)) ?></div></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h3 class="mt0"><?= icon('clipboard', 18) ?> Tugas Belum Dikumpul</h3>
      <div class="stat">
        <div class="stat-ic g6"><?= icon('clipboard', 22) ?></div>
        <div><div class="stat-num"><?= $tugasBelum ?></div><div class="stat-lab">tugas menunggu</div></div>
      </div>
      <a class="btn btn-ghost btn-block mt2" href="siswa-tugas.php">Buka Halaman Tugas</a>
    </div>

    <div class="card">
      <div class="card-head"><h3 class="mt0"><?= icon('bell', 18) ?> Pengumuman</h3></div>
      <?php foreach ($pengumuman as $p): ?>
        <div class="notice">
          <div class="notice-ic"><?= icon('bell', 16) ?></div>
          <div><h4><?= e($p['judul']) ?></h4><p><?= e(mb_strimwidth($p['isi'], 0, 120, '...')) ?></p></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$pengumuman): ?><p class="muted small">Belum ada pengumuman.</p><?php endif; ?>
    </div>

    <div class="card">
      <h3 class="mt0"><?= icon('bookmark', 18) ?> Aksi Cepat</h3>
      <div class="stack">
        <a class="btn btn-ghost btn-sm" href="siswa-pembayaran.php"><?= icon('wallet', 16) ?> Pembayaran & Tagihan</a>
        <a class="btn btn-ghost btn-sm" href="siswa-nilai.php"><?= icon('chart', 16) ?> Riwayat Nilai</a>
        <?php if (sertifikat_aktif()): ?><a class="btn btn-ghost btn-sm" href="siswa-sertifikat.php"><?= icon('award', 16) ?> Sertifikat Saya</a><?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="siswa-forum.php"><?= icon('chat', 16) ?> Forum Diskusi</a>
        <a class="btn btn-ghost btn-sm" href="siswa-leaderboard.php"><?= icon('trophy', 16) ?> Ranking Siswa</a>
        <a class="btn btn-ghost btn-sm" href="siswa-bookmark.php"><?= icon('bookmark', 16) ?> Bookmark Materi</a>
      </div>
    </div>
  </aside>
</div>
<?php page_end(); ?>
