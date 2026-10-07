<?php
$u = current_user();
$assetVer = function_exists('asset_ver') ? asset_ver() : '';
?><!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Akses Ditolak &middot; <?= e(setting('site_name')) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= e($assetVer) ?>">
</head>
<body class="is-public">
<div class="bg-orbs" aria-hidden="true"><span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span></div>
<main class="main public-main">
  <div class="card denied-card">
    <div class="denied-ic"><?= icon('lock', 40) ?></div>
    <h1>Akses Ditolak</h1>
    <p class="muted">Halaman ini hanya bisa dibuka oleh pengguna dengan role tertentu. Akun kamu saat ini: <strong><?= e($u ? role_label($u['role']) : 'belum masuk') ?></strong>.</p>
    <div class="row-gap">
      <a class="btn btn-primary" href="<?= $u ? ($u['role'] === 'admin' ? 'admin-dashboard.php' : ($u['role'] === 'guru' ? 'guru-dashboard.php' : 'siswa-dashboard.php')) : 'login.php' ?>">Kembali ke Dashboard</a>
      <a class="btn btn-ghost" href="index.php">Halaman Publik</a>
    </div>
  </div>
</main>
</body>
</html>
