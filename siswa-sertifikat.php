<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

if (!sertifikat_aktif()) {
    // Fitur sertifikat dimatikan: halaman ini disembunyikan, termasuk untuk
    // siswa yang masih punya data sertifikat lama (data tetap tersimpan).
    flash('Fitur sertifikat sedang dinonaktifkan.', 'warning');
    redirect('siswa-dashboard.php');
}

$id = num('id');
$sql = 'SELECT ct.*, co.judul AS kursus, co.level, us.nama, us.id AS uid FROM certificates ct
    JOIN courses co ON co.id = ct.course_id JOIN users us ON us.id = ct.user_id WHERE ';
if ($id > 0) {
    $sql .= 'ct.id = ' . $id;
} elseif ($u['role'] === 'siswa') {
    $sql .= 'ct.user_id = ' . (int)$u['id'] . ' ORDER BY ct.id DESC LIMIT 1';
} else {
    $sql .= '0';
}
$cert = db()->query($sql)->fetch();
if ($cert && $u['role'] === 'siswa' && (int)$cert['uid'] !== (int)$u['id']) {
    $cert = null;
}
$semua = db()->query('SELECT ct.*, co.judul AS kursus FROM certificates ct JOIN courses co ON co.id = ct.course_id WHERE ct.user_id = ' . (int)$u['id'] . ' ORDER BY ct.id DESC')->fetchAll();

page_start('Sertifikat Saya', 'Sertifikat diterbitkan otomatis setelah kursus selesai dan evaluasi lulus.');
?>
<?php if ($cert): ?>
  <div class="card">
    <div class="row-between mb3 no-print">
      <div>
        <h2 class="mt0 mb1"><?= icon('award', 20) ?> <?= e($cert['kursus']) ?></h2>
        <div class="muted small">Nomor: <strong><?= e($cert['nomor']) ?></strong> &middot; diterbitkan <?= e(tgl_id($cert['tanggal'])) ?></div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" data-print><?= icon('download', 17) ?> Cetak / Simpan PDF</button>
        <a class="btn btn-ghost" href="sertifikat-verifikasi.php?nomor=<?= urlencode($cert['nomor']) ?>"><?= icon('shield', 17) ?> Verifikasi</a>
      </div>
    </div>

    <div class="cert">
      <div class="cert-inner">
        <div style="display:flex;justify-content:center;margin-bottom:8px"><?= logo_svg(78) ?></div>
        <div style="letter-spacing:.32em;font-size:.76rem;color:#8a6a20">SERTIFIKAT PENYELESAIAN</div>
        <h2>Certificate of Completion</h2>
        <p style="margin:0;color:#5c4a22">Diberikan kepada</p>
        <div class="cert-name"><?= e($cert['nama']) ?></div>
        <p style="margin:0;color:#5c4a22">atas keberhasilan menyelesaikan kursus</p>
        <div class="cert-course"><?= e($cert['kursus']) ?></div>
        <p style="max-width:560px;margin:16px auto 0;color:#5c4a22;font-size:.92rem"><?= e(setting('cert_catatan')) ?></p>
        <div class="cert-foot">
          <div>
            <div class="cert-seal"><?= e(setting('cert_prefix', 'GLC')) ?><br>OFFICIAL<br>SEAL</div>
          </div>
          <div style="text-align:center">
            <div class="muted" style="font-size:.85rem">Nilai akhir: <strong><?= number_format((float)$cert['nilai_akhir'], 2) ?></strong></div>
            <div class="cert-num">No. <?= e($cert['nomor']) ?></div>
          </div>
          <div class="sig">
            <?= e(setting('cert_penandatangan')) ?><br>
            <span style="font-size:.78rem;color:#6b4f14"><?= e(setting('site_name')) ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <?= empty_state('Belum ada sertifikat. Selesaikan seluruh materi kursus dan lulus quiz untuk membuka sertifikat.', 'award') ?>
  </div>
<?php endif; ?>

<div class="card no-print">
  <div class="card-head"><h2 class="mt0"><?= icon('list', 20) ?> Daftar Sertifikat</h2></div>
  <?php if (!$semua): ?><p class="muted small mb0">Belum ada sertifikat pada akunmu.</p><?php endif; ?>
  <?php if ($semua): ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Nomor</th><th>Kursus</th><th>Nilai</th><th>Tanggal</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($semua as $s): ?>
        <tr>
          <td><code><?= e($s['nomor']) ?></code></td>
          <td><?= e($s['kursus']) ?></td>
          <td><?= number_format((float)$s['nilai_akhir'], 2) ?></td>
          <td class="nowrap small"><?= e(tgl_id($s['tanggal'])) ?></td>
          <td><?= status_badge($s['status']) ?></td>
          <td class="right"><a class="btn btn-ghost btn-sm" href="siswa-sertifikat.php?id=<?= (int)$s['id'] ?>">Lihat</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php page_end(); ?>
