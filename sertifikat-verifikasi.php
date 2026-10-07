<?php
require_once __DIR__ . '/includes/layout.php';

if (!sertifikat_aktif()) {
    // Verifikasi publik dimatikan bersama fitur sertifikat (Pengaturan → Website).
    // Tidak ada data sertifikat yang ditampilkan di halaman ini selama nonaktif.
    page_start('Verifikasi Sertifikat');
    echo '<div class="card">' . empty_state('Fitur verifikasi sertifikat sedang dinonaktifkan oleh pengelola. Hubungi ' . e(setting('site_email')) . ' bila kamu perlu memverifikasi sebuah sertifikat.', 'award') . '</div>';
    page_end();
    exit;
}

$nomor = get('nomor');
$cert = null;
if ($nomor !== '') {
    $stmt = db()->prepare('SELECT ct.*, us.nama, co.judul AS kursus FROM certificates ct
        JOIN users us ON us.id = ct.user_id JOIN courses co ON co.id = ct.course_id WHERE ct.nomor = ?');
    $stmt->execute([$nomor]);
    $cert = $stmt->fetch();
}
page_start('Verifikasi Sertifikat', 'Masukkan nomor sertifikat untuk memeriksa keasliannya.');
?>
<div class="split">
  <div class="card">
    <form method="get" class="inline-form">
      <div class="form-group" style="flex:1;min-width:240px">
        <label>Nomor Sertifikat</label>
        <input type="text" name="nomor" value="<?= e($nomor) ?>" placeholder="GLC-<?= date('Y') ?>-001-00004" required>
      </div>
      <button class="btn btn-primary" type="submit"><?= icon('shield', 17) ?> Verifikasi</button>
    </form>
    <div class="divider"></div>
    <?php if ($nomor !== '' && !$cert): ?>
      <div class="flash flash-danger"><?= icon('x', 17) ?><span>Sertifikat dengan nomor tersebut tidak ditemukan. Periksa kembali penulisannya.</span></div>
    <?php elseif ($cert): ?>
      <div class="flash flash-success mb3"><?= icon('check', 17) ?><span>Sertifikat <strong>valid</strong> dan tercatat resmi di sistem <?= e(setting('site_name')) ?>.</span></div>
      <dl class="kv">
        <dt>Nomor</dt><dd><?= e($cert['nomor']) ?></dd>
        <dt>Nama</dt><dd><?= e($cert['nama']) ?></dd>
        <dt>Kursus</dt><dd><?= e($cert['kursus']) ?></dd>
        <dt>Nilai akhir</dt><dd><?= number_format((float)$cert['nilai_akhir'], 2) ?></dd>
        <dt>Tanggal terbit</dt><dd><?= e(tgl_id($cert['tanggal'])) ?></dd>
        <dt>Status</dt><dd><?= status_badge($cert['status']) ?></dd>
      </dl>
    <?php else: ?>
      <p class="muted small mb0">Setiap sertifikat yang diterbitkan sistem memiliki nomor unik. Verifikasi berguna untuk instansi atau perusahaan yang ingin memastikan keaslian sertifikat.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <h3 class="mt0"><?= icon('award', 18) ?> Cara Membaca Nomor Sertifikat</h3>
    <p class="muted small">Format nomor: <code><?= e(setting('cert_prefix', 'GLC')) ?>-TAHUN-KODEKURSUS-KODEUSER</code></p>
    <div class="dark-box">
      <strong>Contoh:</strong> <?= e(setting('cert_prefix', 'GLC')) ?>-<?= date('Y') ?>-001-00004<br>
      <span class="muted">Artinya kursus ID 1 dan siswa ID 4.</span>
    </div>
    <div class="divider"></div>
    <?php if (is_logged_in()): ?>
      <a class="btn btn-primary btn-block" href="siswa-sertifikat.php"><?= icon('award', 17) ?> Lihat Sertifikat Saya</a>
    <?php else: ?>
      <a class="btn btn-ghost btn-block" href="login.php">Masuk untuk melihat sertifikatmu</a>
    <?php endif; ?>
  </div>
</div>
<?php page_end(); ?>
