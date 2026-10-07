<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai.php';

function nav_items($role)
{
    $siswa = [
        ['siswa-dashboard.php', 'Dashboard', 'dashboard'],
        ['kursus.php', 'Katalog Kursus', 'grid'],
        ['siswa-kursus.php', 'Kursus Saya', 'book'],
        ['siswa-pembayaran.php', 'Pembayaran', 'wallet'],
        ['siswa-nilai.php', 'Nilai & Riwayat', 'chart'],
        ['siswa-sertifikat.php', 'Sertifikat', 'award'],
        ['siswa-tugas.php', 'Tugas', 'clipboard'],
        ['siswa-kalender.php', 'Kalender Belajar', 'calendar'],
        ['siswa-forum.php', 'Forum Diskusi', 'chat'],
        ['siswa-bookmark.php', 'Bookmark Materi', 'bookmark'],
        ['siswa-offline.php', 'Materi Offline', 'download'],
        ['siswa-leaderboard.php', 'Ranking', 'trophy'],
        ['notifikasi.php', 'Notifikasi', 'bell'],
        ['siswa-profil.php', 'Profil Saya', 'user'],
    ];
    $guru = [
        ['guru-dashboard.php', 'Dashboard Guru', 'dashboard'],
        ['guru-kursus.php', 'Kelola Kursus', 'book'],
        ['guru-materi.php', 'Modul & Materi', 'layers'],
        ['guru-quiz.php', 'Quiz & Ujian', 'quiz'],
        ['guru-soal.php', 'Bank Soal', 'list'],
        ['guru-koreksi.php', 'Koreksi & Nilai', 'check'],
        ['guru-kelas.php', 'Kelola Kelas', 'grid'],
        ['guru-siswa.php', 'Kelola Siswa', 'users'],
        ['guru-progress.php', 'Progress Siswa', 'chart'],
        ['guru-absensi.php', 'Absensi', 'calendar'],
        ['guru-tugas.php', 'Tugas', 'clipboard'],
        ['guru-pengumuman.php', 'Pengumuman', 'mail'],
        ['notifikasi.php', 'Notifikasi', 'bell'],
        ['guru-forum.php', 'Forum & Diskusi', 'chat'],
        ['guru-statistik.php', 'Statistik Kelas', 'trophy'],
        ['siswa-profil.php', 'Profil Saya', 'user'],
    ];
    $admin = [
        ['admin-dashboard.php', 'Dashboard Admin', 'dashboard'],
        ['admin-users.php', 'Manajemen User', 'users'],
        ['admin-guru.php', 'Manajemen Guru', 'user'],
        ['admin-siswa.php', 'Manajemen Siswa', 'users'],
        ['admin-kursus.php', 'Manajemen Kursus', 'book'],
        ['admin-kelas.php', 'Manajemen Kelas', 'grid'],
        ['admin-kategori.php', 'Manajemen Kategori', 'folder'],
        ['admin-quiz.php', 'Manajemen Quiz', 'quiz'],
        ['admin-soal.php', 'Manajemen Bank Soal', 'list'],
        ['admin-pembayaran.php', 'Manajemen Pembayaran', 'wallet'],
        ['admin-sertifikat.php', 'Manajemen Sertifikat', 'award'],
        ['admin-banner.php', 'Manajemen Banner', 'image'],
        ['admin-pengumuman.php', 'Manajemen Pengumuman', 'mail'],
        ['notifikasi.php', 'Notifikasi', 'bell'],
        ['admin-faq.php', 'Manajemen FAQ', 'chat'],
        ['admin-role.php', 'Manajemen Role', 'shield'],
        ['admin-audit.php', 'Audit Log', 'clock'],
        ['admin-pengaturan.php', 'Pengaturan Website', 'settings'],
        ['admin-backup.php', 'Backup Database', 'database'],
        ['siswa-profil.php', 'Profil Saya', 'user'],
    ];
    if (!sertifikat_aktif()) {
        // Fitur sertifikat sedang dimatikan (Pengaturan → Website): sembunyikan
        // menu sertifikat dari sidebar siswa & admin.
        $siswa = array_values(array_filter($siswa, function ($i) {
            return $i[0] !== 'siswa-sertifikat.php';
        }));
        $admin = array_values(array_filter($admin, function ($i) {
            return $i[0] !== 'admin-sertifikat.php';
        }));
    }
    return $role === 'admin' ? $admin : ($role === 'guru' ? $guru : $siswa);
}

function current_page()
{
    return basename($_SERVER['PHP_SELF'] ?? 'index.php');
}

/**
 * Versi aset = mtime berkas app.js/app.css, dipakai sebagai query string
 * (?v=...) supaya siswa tidak memakai JS/CSS lama dari cache browser setelah
 * aplikasi di-deploy ulang — mesin ujian "satu soal per halaman" bergantung
 * pada app.js terbaru.
 */
function asset_ver()
{
    static $ver = null;
    if ($ver === null) {
        $ver = (string)max(
            (int)@filemtime(__DIR__ . '/../assets/app.js'),
            (int)@filemtime(__DIR__ . '/../assets/app.css'),
            (int)@filemtime(__DIR__ . '/../assets/app-native.js')
        );
    }
    return $ver;
}

function page_start($title, $subtitle = '', $opts = [])
{
    $u = current_user();
    $site = setting('site_name', 'Marelan English Course');
    $assetVer = asset_ver();
    if (setting('mode_pemeliharaan') === '1' && (!$u || $u['role'] !== 'admin') && !in_array(current_page(), ['login.php', 'logout.php'], true)) {
        lms_maintenance_page($site);
        exit;
    }
    $dark = get('theme', '');
    ?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> &middot; <?= e($site) ?></title>
<meta name="description" content="<?= e(setting('site_deskripsi')) ?>">
<?php /* favicon = logo website (logo unggahan admin, atau seal bawaan) */ ?>
<link rel="icon" href="favicon.php?v=<?= e(substr(md5(setting('site_logo') . '|' . $assetVer), 0, 8)) ?>">
<?php if ($u): ?><?php /* token CSRF untuk skrip halaman (mode offline): dipakai app.js saat
   mengirim jawaban yang tersimpan di perangkat. Hanya ada di halaman pengguna
   yang sudah masuk, sama seperti token di dalam form. */ ?>
<meta name="lms-csrf" content="<?= e(csrf_token()) ?>">
<meta name="lms-masuk" content="1">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= e($assetVer) ?>">
<script>
(function () {
  try {
    var t = localStorage.getItem('lms-theme');
    if (!t) { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
    document.documentElement.setAttribute('data-theme', t);
  } catch (e) {}
})();
</script>
</head>
<body class="<?= $u ? 'has-sidebar' : 'is-public' ?>">
<div class="bg-orbs" aria-hidden="true"><span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span><span class="orb o4"></span></div>
<?php
    if ($u) {
        lms_sidebar($u);
        echo '<main class="main">';
        lms_topbar($u, $title);
    } else {
        lms_public_nav($site);
        echo '<main class="main public-main">';
    }
    // Pita status jaringan (muncul saat offline / ada jawaban menunggu dikirim).
    // Dikendalikan assets/app.js; tombolnya hanya untuk pengguna yang sudah masuk.
    echo lms_pita_luring((bool)$u);
    $fl = flash();
    if ($fl) {
        echo '<div class="flash-wrap">';
        foreach ($fl as $f) {
            echo '<div class="flash flash-' . e($f['type']) . '">' . icon($f['type'] === 'danger' ? 'x' : ($f['type'] === 'warning' ? 'bell' : 'check'), 18) . '<span>' . e($f['msg']) . '</span></div>';
        }
        echo '</div>';
    }
    if ($title && $u) {
        echo '<div class="page-head"><div><h1>' . e($title) . '</h1>';
        if ($subtitle) {
            echo '<p class="muted">' . e($subtitle) . '</p>';
        }
        echo '</div>';
        if (!empty($opts['actions'])) {
            echo '<div class="page-actions">' . $opts['actions'] . '</div>';
        }
        echo '</div>';
    }
}

/**
 * Pita status jaringan. Bawaannya tersembunyi (CSS `display:none`) dan baru
 * ditampilkan assets/app.js saat perangkat offline atau ada jawaban yang masih
 * menunggu dikirim. Jangan pakai atribut `hidden` di sini: aturan display milik
 * kelasnya sendiri akan mengalahkannya (lihat catatan .btn[hidden] / .q-block[hidden]).
 */
function lms_pita_luring($masuk)
{
    $tautan = $masuk
        ? '<a class="btn btn-ghost btn-sm pita-tautan" href="siswa-offline.php">' . icon('download', 15) . ' Materi Offline</a>'
        : '';
    return '<div class="pita-luring" id="pita-luring" role="status" aria-live="polite">'
        . '<span class="pita-ic">' . icon('cloud-off', 17) . '</span>'
        . '<span class="pita-teks" data-luring-teks>Kamu sedang offline.</span>'
        . '<span class="pita-aksi" data-luring-aksi></span>'
        . $tautan
        . '</div>';
}

function lms_maintenance_page($site)
{
    http_response_code(503);
    $assetVer = asset_ver();
    ?><!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pemeliharaan &middot; <?= e($site) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= e($assetVer) ?>">
</head>
<body class="is-public">
<div class="bg-orbs" aria-hidden="true"><span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span></div>
<main class="main public-main">
  <div class="card denied-card">
    <div class="denied-ic"><?= icon('settings', 40) ?></div>
    <h1>Situs Sedang Pemeliharaan</h1>
    <p class="muted"><?= e($site) ?> sedang dalam perbaikan singkat. Silakan coba beberapa saat lagi, atau hubungi <?= e(setting('site_email')) ?> bila perlu.</p>
    <div class="row-gap" style="justify-content:center">
      <a class="btn btn-primary" href="login.php">Masuk sebagai Admin</a>
    </div>
  </div>
</main>
</body>
</html><?php
}

function lms_sidebar($u)
{
    $items = nav_items($u['role']);
    $cur = current_page();
    ?>
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <a href="<?= $u['role'] === 'admin' ? 'admin-dashboard.php' : ($u['role'] === 'guru' ? 'guru-dashboard.php' : 'siswa-dashboard.php') ?>" class="brand-link">
      <?= site_logo(38) ?>
      <span class="brand-text">
        <strong><?= e(setting('site_name', 'Marelan English Course')) ?></strong>
        <small>Learning Management System</small>
      </span>
    </a>
  </div>
  <div class="sidebar-profile">
    <span class="avatar" <?= $u['foto'] ? 'style="background-image:url(' . e($u['foto']) . ')"' : '' ?>><?= $u['foto'] ? '' : e(strtoupper(mb_substr($u['nama'], 0, 1))) ?></span>
    <span class="sp-meta">
      <strong><?= e($u['nama']) ?></strong>
      <small><?= e(role_label($u['role'])) ?></small>
    </span>
  </div>
  <nav class="nav">
    <?php foreach ($items as $it): ?>
      <a class="nav-item <?= $cur === $it[0] ? 'active' : '' ?>" href="<?= e($it[0]) ?>"><?= icon($it[2], 19) ?><span><?= e($it[1]) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-foot">
    <a class="nav-item" href="index.php"><?= icon('home', 19) ?><span>Halaman Publik</span></a>
    <a class="nav-item danger" href="logout.php"><?= icon('logout', 19) ?><span>Keluar</span></a>
  </div>
</aside>
<div class="sidebar-overlay" data-close-sidebar></div>
<?php
}

function lms_topbar($u, $title)
{
    $unread = unread_notifications();
    ?>
<header class="topbar">
  <button class="icon-btn only-mobile" data-toggle-sidebar aria-label="Menu"><?= icon('menu', 22) ?></button>
  <form class="topsearch" action="search.php" method="get">
    <?= icon('search', 18) ?>
    <input type="search" name="q" placeholder="Cari kursus, materi, atau quiz..." value="<?= e(get('q')) ?>">
  </form>
  <div class="topbar-right">
    <button class="icon-btn" data-toggle-theme aria-label="Ganti tema"><?= icon('sun', 20, 'ic-sun') ?><?= icon('moon', 20, 'ic-moon') ?></button>
    <a class="icon-btn bell" href="notifikasi.php" aria-label="Notifikasi">
      <?= icon('bell', 20) ?><?php if ($unread > 0): ?><span class="dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
    </a>
    <div class="user-menu">
      <button class="user-btn" data-toggle-user>
        <span class="avatar sm" <?= $u['foto'] ? 'style="background-image:url(' . e($u['foto']) . ')"' : '' ?>><?= $u['foto'] ? '' : e(strtoupper(mb_substr($u['nama'], 0, 1))) ?></span>
        <span class="um-name"><?= e(explode(' ', $u['nama'])[0]) ?></span>
      </button>
      <div class="user-drop">
        <div class="ud-head"><strong><?= e($u['nama']) ?></strong><small><?= e($u['email']) ?></small></div>
        <a href="siswa-profil.php"><?= icon('user', 18) ?> Profil & Password</a>
        <?php if ($u['role'] === 'siswa'): ?><a href="siswa-nilai.php"><?= icon('chart', 18) ?> Nilai Saya</a><?php endif; ?>
        <?php if ($u['role'] === 'admin'): ?><a href="admin-pengaturan.php"><?= icon('settings', 18) ?> Pengaturan</a><?php endif; ?>
        <a href="logout.php" class="danger"><?= icon('logout', 18) ?> Keluar</a>
      </div>
    </div>
  </div>
</header>
<?php
}

function lms_public_nav($site)
{
    $cur = current_page();
    ?>
<header class="pub-nav">
  <div class="pub-inner">
    <a href="index.php" class="brand-link">
      <?= site_logo(40) ?>
      <span class="brand-text"><strong><?= e($site) ?></strong><small>Learning Management System</small></span>
    </a>
    <nav class="pub-links">
      <a href="index.php" class="<?= $cur === 'index.php' ? 'active' : '' ?>">Beranda</a>
      <a href="kursus.php" class="<?= $cur === 'kursus.php' ? 'active' : '' ?>">Katalog Kursus</a>
      <a href="faq.php" class="<?= $cur === 'faq.php' ? 'active' : '' ?>">FAQ</a>
      <?php if (sertifikat_aktif()): ?><a href="sertifikat-verifikasi.php" class="<?= $cur === 'sertifikat-verifikasi.php' ? 'active' : '' ?>">Cek Sertifikat</a><?php endif; ?>
    </nav>
    <div class="pub-actions">
      <button class="icon-btn" data-toggle-theme aria-label="Ganti tema"><?= icon('sun', 20, 'ic-sun') ?><?= icon('moon', 20, 'ic-moon') ?></button>
      <a class="btn btn-ghost" href="login.php">Masuk</a>
      <a class="btn btn-primary" href="register.php">Daftar Gratis</a>
    </div>
  </div>
</header>
<?php
}

function page_end()
{
    $u = current_user();
    ?>
</main>
<footer class="site-foot">
  <div class="foot-inner">
    <div class="foot-brand">
      <?= site_logo(42) ?>
      <div>
        <strong><?= e(setting('site_name', 'Marelan English Course')) ?></strong>
        <p><?= e(setting('site_tagline')) ?></p>
      </div>
    </div>
    <div class="foot-cols">
      <div>
        <h4>Navigasi</h4>
        <a href="index.php">Beranda</a>
        <a href="kursus.php">Katalog Kursus</a>
        <a href="faq.php">FAQ</a>
        <?php if (sertifikat_aktif()): ?><a href="sertifikat-verifikasi.php">Verifikasi Sertifikat</a><?php endif; ?>
      </div>
      <div>
        <h4>Kontak</h4>
        <span><?= e(setting('site_alamat')) ?></span>
        <span><?= e(setting('site_telepon')) ?></span>
        <span><?= e(setting('site_email')) ?></span>
      </div>
      <div>
        <h4>Akun</h4>
        <?php if ($u): ?>
          <a href="<?= $u['role'] === 'admin' ? 'admin-dashboard.php' : ($u['role'] === 'guru' ? 'guru-dashboard.php' : 'siswa-dashboard.php') ?>">Dashboard</a>
          <a href="siswa-profil.php">Profil</a>
          <a href="logout.php">Keluar</a>
        <?php else: ?>
          <a href="login.php">Masuk</a>
          <a href="register.php">Daftar</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="foot-bottom">
    <span>&copy; <?= date('Y') ?> <?= e(setting('site_name', 'Marelan English Course')) ?>. Seluruh hak cipta dilindungi.</span>
    <span>Dibuat untuk pembelajaran daring yang menyenangkan.</span>
  </div>
<div class="lightbox" id="lightbox">
  <button type="button" class="lightbox-close" aria-label="Tutup">&times;</button>
  <img src="" alt="Gambar diperbesar">
  <div class="lightbox-cap">Klik di luar gambar atau tekan Esc untuk menutup</div>
</div>
<script src="assets/app-native.js?v=<?= e(asset_ver()) ?>"></script>
<script src="assets/app.js?v=<?= e(asset_ver()) ?>"></script>
</body>
</html>
<?php
}

function empty_state($text, $ic = 'folder')
{
    return '<div class="empty">' . icon($ic, 34) . '<p>' . e($text) . '</p></div>';
}
