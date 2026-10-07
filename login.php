<?php
require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = post('email');
    $pass = post('password');
    if ($email === '' || $pass === '') {
        $error = 'Email dan password wajib diisi.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR username = ?');
        $stmt->execute([$email, $email]);
        $u = $stmt->fetch();
        if ($u && password_verify($pass, $u['password'])) {
            if ($u['status'] !== 'aktif') {
                $error = 'Akun kamu sedang tidak aktif. Hubungi admin.';
            } else {
                login_user($u);
                $to = $_SESSION['redirect_after_login'] ?? '';
                unset($_SESSION['redirect_after_login']);
                flash('Selamat datang kembali, ' . explode(' ', $u['nama'])[0] . '!');
                if ($to && strpos($to, 'login.php') === false) {
                    redirect($to);
                }
                redirect($u['role'] === 'admin' ? 'admin-dashboard.php' : ($u['role'] === 'guru' ? 'guru-dashboard.php' : 'siswa-dashboard.php'));
            }
        } else {
            $error = 'Email atau password salah.';
        }
    }
}

page_start('Masuk');
?>
<div class="auth-wrap">
  <div class="card auth-card">
    <div class="auth-logo auth-logo-lg">
      <?= site_logo(256) ?>
      <div>
        <h1 class="mb0">Masuk ke Akun</h1>
        <p class="muted small mb0"><?= e(setting('site_name')) ?></p>
      </div>
    </div>

    <?php if ($error): ?><div class="flash flash-danger mb2"><?= icon('x', 17) ?><span><?= e($error) ?></span></div><?php endif; ?>
    <?php if (get('nonaktif')): ?><div class="flash flash-warning mb2"><?= icon('bell', 17) ?><span>Akun kamu dinonaktifkan. Silakan hubungi admin.</span></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="email">Email atau Username</label>
        <input type="text" id="email" name="email" value="<?= e(post('email')) ?>" placeholder="nama@email.com" required autofocus>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="••••••••" required>
      </div>
      <button class="btn btn-primary btn-block btn-lg" type="submit"><?= icon('logout', 18) ?> Masuk</button>
    </form>

    <div class="auth-foot">
      Belum punya akun? <a href="register.php">Daftar sekarang</a>
    </div>
  </div>
</div>
<?php page_end(); ?>
