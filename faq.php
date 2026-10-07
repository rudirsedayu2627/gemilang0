<?php
require_once __DIR__ . '/includes/layout.php';
$faqs = faq_publik();
$grouped = [];
foreach ($faqs as $f) {
    $grouped[$f['kategori'] ?: 'Umum'][] = $f;
}
page_start('Pertanyaan yang Sering Diajukan', 'Semua yang perlu kamu tahu tentang kursus' . (sertifikat_aktif() ? ' dan sertifikat.' : '.'));
?>
<div class="grid cols-3 mb4">
  <?php foreach ($grouped as $kat => $items): ?>
    <div class="card">
      <h3 class="mt0"><?= icon('chat', 18) ?> <?= e($kat) ?></h3>
      <?php foreach ($items as $f): ?>
        <details class="mb2">
          <summary style="cursor:pointer;font-weight:600"><?= e($f['pertanyaan']) ?></summary>
          <p class="muted small mt1 mb0"><?= nl2br(e($f['jawaban'])) ?></p>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<div class="card">
  <h2 class="mt0">Belum menemukan jawabannya?</h2>
  <p class="muted">Hubungi tim <?= e(setting('site_name')) ?> melalui kontak berikut, atau tanyakan langsung di forum diskusi kursus setelah kamu masuk.</p>
  <div class="grid cols-3">
    <div class="card tight flat"><strong>Email</strong><div class="muted small"><?= e(setting('site_email')) ?></div></div>
    <div class="card tight flat"><strong>Telepon / WA</strong><div class="muted small"><?= e(setting('site_telepon')) ?></div></div>
    <div class="card tight flat"><strong>Alamat</strong><div class="muted small"><?= e(setting('site_alamat')) ?></div></div>
  </div>
</div>
<?php page_end(); ?>
