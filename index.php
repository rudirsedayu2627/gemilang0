<?php
require_once __DIR__ . '/includes/layout.php';

$u = current_user();
if ($u) {
    redirect($u['role'] === 'admin' ? 'admin-dashboard.php' : ($u['role'] === 'guru' ? 'guru-dashboard.php' : 'siswa-dashboard.php'));
}

$kategori = db()->query('SELECT c.*, (SELECT COUNT(*) FROM courses WHERE kategori_id = c.id AND status = "aktif") AS jml FROM categories c ORDER BY c.urutan')->fetchAll();
$kursus = db()->query('SELECT co.*, u.nama AS guru, c.nama AS kategori, c.warna,
        (SELECT COUNT(*) FROM lessons WHERE course_id = co.id) AS jml_lesson,
        (SELECT COUNT(*) FROM enrollments WHERE course_id = co.id) AS jml_siswa
    FROM courses co
    LEFT JOIN users u ON u.id = co.guru_id
    LEFT JOIN categories c ON c.id = co.kategori_id
    WHERE co.status = "aktif" ORDER BY co.id DESC LIMIT 6')->fetchAll();
$banners = db()->query('SELECT * FROM banners WHERE aktif = 1 ORDER BY urutan LIMIT 3')->fetchAll();
$faqs = faq_publik(4);
$pengumuman = db()->query('SELECT * FROM announcements ORDER BY pinned DESC, created_at DESC LIMIT 3')->fetchAll();
$totalSiswa = (int)db()->query('SELECT COUNT(*) FROM users WHERE role = "siswa"')->fetchColumn();
$totalKursus = (int)db()->query('SELECT COUNT(*) FROM courses WHERE status = "aktif"')->fetchColumn();
$totalLesson = (int)db()->query('SELECT COUNT(*) FROM lessons')->fetchColumn();
$totalSertifikat = sertifikat_aktif() ? (int)db()->query('SELECT COUNT(*) FROM certificates')->fetchColumn() : 0;

page_start('Beranda');
?>
<div class="hero">
  <span class="chip solid mb2"><?= icon('star', 15) ?> Portal Gemilang Language Corner</span>
  <h1><?= e(setting('site_tagline')) ?></h1>
  <p><?= e(setting('site_deskripsi')) ?></p>
  <div class="hero-actions">
    <a class="btn btn-light btn-lg" href="kursus.php"><?= icon('grid', 18) ?> Lihat Katalog Kursus</a>
    <a class="btn btn-ghost btn-lg" href="register.php"><?= icon('user', 18) ?> Daftar Sekarang</a>
  </div>
  <div class="hero-stats">
    <div><b><?= number_format($totalSiswa) ?></b><span>Siswa terdaftar</span></div>
    <div><b><?= number_format($totalKursus) ?></b><span>Kursus aktif</span></div>
    <div><b><?= number_format($totalLesson) ?></b><span>Materi pembelajaran</span></div>
    <?php if (sertifikat_aktif()): ?><div><b><?= number_format($totalSertifikat) ?></b><span>Sertifikat diterbitkan</span></div><?php endif; ?>
  </div>
</div>

<?php if ($banners): ?>
<div class="banner-strip mb4">
  <?php foreach ($banners as $i => $b): ?>
    <a class="card banner-item" href="<?= e($b['link'] ?: 'kursus.php') ?>" style="margin:0">
      <div class="row-gap" style="align-items:flex-start">
        <div class="stat-ic g<?= ($i % 3) + 1 ?>"><?= icon('trophy', 22) ?></div>
        <div>
          <h3 class="mt0"><?= e($b['judul']) ?></h3>
          <p class="muted mb0 small"><?= e($b['subjudul']) ?></p>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-title"><h2>Kategori Kursus</h2><a href="kursus.php" class="small">Lihat semua <?= icon('arrow-right', 15) ?></a></div>
<div class="grid cols-3">
  <?php foreach ($kategori as $k): ?>
    <a class="card tight" href="kursus.php?kategori=<?= (int)$k['id'] ?>" style="text-decoration:none;color:inherit">
      <div class="row-gap">
        <div class="stat-ic" style="background:<?= e($k['warna'] ?: '#4F46E5') ?>"><?= icon($k['ikon'] ?: 'book', 22) ?></div>
        <div>
          <h3 class="mt0 mb0" style="font-size:.98rem"><?= e($k['nama']) ?></h3>
          <span class="muted small"><?= (int)$k['jml'] ?> kursus</span>
        </div>
      </div>
      <p class="muted small mb0 mt2"><?= e($k['deskripsi']) ?></p>
    </a>
  <?php endforeach; ?>
</div>

<div class="section-title"><h2>Kursus Terbaru</h2><a href="kursus.php" class="small">Semua kursus <?= icon('arrow-right', 15) ?></a></div>
<div class="grid cols-3">
  <?php foreach ($kursus as $i => $c): ?>
    <article class="course-card">
      <div class="course-thumb t<?= ($i % 4) + 1 ?>">
        <?php if ($c['thumbnail']): ?><img src="<?= e($c['thumbnail']) ?>" alt=""><?php else: ?><?= icon('book', 44, 'th-ic') ?><?php endif; ?>
      </div>
      <div class="course-body">
        <div class="row-gap" style="gap:8px">
          <span class="chip"><?= e($c['kategori'] ?: 'Umum') ?></span>
          <span class="chip"><?= e($c['level']) ?></span>
        </div>
        <h3><?= e($c['judul']) ?></h3>
        <p class="muted small" style="margin:0"><?= e(mb_strimwidth($c['deskripsi'], 0, 110, '...')) ?></p>
        <div class="course-meta">
          <span><?= icon('layers', 15) ?> <?= (int)$c['jml_lesson'] ?> materi</span>
          <span><?= icon('clock', 15) ?> <?= (int)$c['durasi_jam'] ?> jam</span>
          <span><?= icon('users', 15) ?> <?= (int)$c['jml_siswa'] ?></span>
        </div>
        <div class="course-foot">
          <span class="price"><?= (float)$c['harga'] > 0 ? rupiah($c['harga']) : 'Gratis' ?></span>
          <a class="btn btn-primary btn-sm" href="kursus-detail.php?id=<?= (int)$c['id'] ?>">Detail <?= icon('arrow-right', 15) ?></a>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<div class="split mt4">
  <div>
    <div class="section-title" style="margin-top:0"><h2>Pengumuman Terbaru</h2></div>
    <?php foreach ($pengumuman as $p): ?>
      <div class="notice">
        <div class="notice-ic"><?= icon('bell', 18) ?></div>
        <div><h4><?= e($p['judul']) ?></h4><p><?= e(mb_strimwidth($p['isi'], 0, 170, '...')) ?></p>
        <span class="muted small"><?= e(tgl_id($p['created_at'])) ?></span></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$pengumuman): ?><?= empty_state('Belum ada pengumuman.', 'bell') ?><?php endif; ?>
  </div>
  <div>
    <div class="section-title" style="margin-top:0"><h2>FAQ</h2><a href="faq.php" class="small">Semua</a></div>
    <?php foreach ($faqs as $f): ?>
      <div class="card tight">
        <strong><?= e($f['pertanyaan']) ?></strong>
        <p class="muted small mb0 mt1"><?= e($f['jawaban']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card center mt4">
  <h2 class="mt0">Siap mulai belajar bahasa Inggris?</h2>
  <p class="muted">Buat akun gratis, ikuti kursus, dan kerjakan quiz<?= sertifikat_aktif() ? ', dan dapatkan sertifikat resmi' : '' ?>.</p>
  <div class="row-gap" style="justify-content:center">
    <a class="btn btn-primary btn-lg" href="register.php"><?= icon('user', 18) ?> Daftar Gratis</a>
    <a class="btn btn-ghost btn-lg" href="kursus.php">Jelajahi Kursus</a>
  </div>
</div>
<?php page_end(); ?>
