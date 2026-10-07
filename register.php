<?php
require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (setting('registrasi_terbuka', '1') !== '1') {
        $error = 'Pendaftaran sedang ditutup. Hubungi admin untuk informasi.';
    } else {
        $nama = post('nama');
        $username = strtolower(preg_replace('/[^a-z0-9_]/i', '', post('username')));
        $email = strtolower(post('email'));
        $pass = post('password');
        $pass2 = post('password2');
        $telepon = post('telepon');
        if ($nama === '' || $username === '' || $email === '' || $pass === '') {
            $error = 'Semua kolom bertanda * wajib diisi.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Format email tidak valid.';
        } elseif (strlen($pass) < 6) {
            $error = 'Password minimal 6 karakter.';
        } elseif ($pass !== $pass2) {
            $error = 'Konfirmasi password tidak sama.';
        } elseif (strlen($username) < 3) {
            $error = 'Username minimal 3 karakter (huruf/angka).';
        } else {
            $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ? OR username = ?');
            $stmt->execute([$email, $username]);
            if ((int)$stmt->fetchColumn() > 0) {
                $error = 'Email atau username sudah terdaftar.';
            } else {
                $ins = db()->prepare('INSERT INTO users (nama, username, email, password, role, telepon) VALUES (?, ?, ?, ?, "siswa", ?)');
                $ins->execute([$nama, $username, $email, password_hash($pass, PASSWORD_DEFAULT), $telepon]);
                $uid = (int)db()->lastInsertId();
                $roles = db()->query("SELECT id FROM users WHERE role IN ('admin','guru')")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($roles as $rid) {
                    notify($rid, 'Siswa baru mendaftar', $nama . ' (' . $email . ') baru membuat akun siswa.', 'admin-siswa.php');
                }
                notify($uid, 'Selamat datang di ' . setting('site_name'), 'Akunmu berhasil dibuat. Mulai dengan memilih kursus di katalog.', 'kursus.php');
                $u = db()->query('SELECT * FROM users WHERE id = ' . $uid)->fetch();
                login_user($u);
                smtp_send($email, 'Pendaftaran Berhasil', '<p>Halo ' . e($nama) . ', akunmu di ' . e(setting('site_name')) . ' sudah aktif. Selamat belajar!</p>');
                flash('Akun berhasil dibuat. Selamat belajar!');
                redirect('siswa-dashboard.php');
            }
        }
    }
}

page_start('Daftar Akun');
?>
<div class="auth-wrap">
  <div class="card auth-card">
    <div class="auth-logo">
      <?= site_logo(60) ?>
      <div>
        <h1 class="mb0">Buat Akun Siswa</h1>
        <p class="muted small mb0">Gratis, langsung bisa ikut kursus</p>
      </div>
    </div>

    <?php if ($error): ?><div class="flash flash-danger mb2"><?= icon('x', 17) ?><span><?= e($error) ?></span></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="nama">Nama Lengkap *</label>
        <input type="text" id="nama" name="nama" value="<?= e(post('nama')) ?>" required>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label for="username">Username *</label>
          <input type="text" id="username" name="username" value="<?= e(post('username')) ?>" required>
        </div>
        <div class="form-group">
          <label for="telepon">No. WhatsApp</label>
          <input type="text" id="telepon" name="telepon" value="<?= e(post('telepon')) ?>">
        </div>
      </div>
      <div class="form-group">
        <label for="email">Email *</label>
        <input type="email" id="email" name="email" value="<?= e(post('email')) ?>" required>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label for="password">Password *</label>
          <input type="password" id="password" name="password" required>
        </div>
        <div class="form-group">
          <label for="password2">Ulangi Password *</label>
          <input type="password" id="password2" name="password2" required>
        </div>
      </div>
      <div class="form-hint mb3">Password minimal 6 karakter. Gunakan kombinasi huruf dan angka.</div>
      <button class="btn btn-primary btn-block btn-lg" type="submit"><?= icon('check', 18) ?> Daftar Sekarang</button>
    </form>

    <div class="auth-foot">Sudah punya akun? <a href="login.php">Masuk di sini</a></div>
  </div>
</div>
<?php page_end(); ?>
