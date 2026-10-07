<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    if ($aksi === 'profil') {
        $nama = post('nama');
        $email = strtolower(post('email'));
        if ($nama === '' || $email === '') {
            $error = 'Nama dan email wajib diisi.';
        } else {
            $foto = $u['foto'];
            if (!empty($_FILES['foto']['name'])) {
                try {
                    $foto = handle_upload('foto');
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
            if (!$error) {
                db()->prepare('UPDATE users SET nama = ?, email = ?, telepon = ?, alamat = ?, bio = ?, foto = ? WHERE id = ?')
                    ->execute([$nama, $email, post('telepon'), post('alamat'), post('bio'), $foto, $u['id']]);
                audit('update_profil', 'Memperbarui profil');
                flash('Profil berhasil diperbarui.');
                redirect('siswa-profil.php');
            }
        }
    }
    if ($aksi === 'password') {
        $lama = post('lama');
        $baru = post('baru');
        $ulang = post('ulang');
        if (!password_verify($lama, $u['password'])) {
            $error = 'Password lama tidak sesuai.';
        } elseif (strlen($baru) < 6) {
            $error = 'Password baru minimal 6 karakter.';
        } elseif ($baru !== $ulang) {
            $error = 'Konfirmasi password baru tidak sama.';
        } else {
            db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($baru, PASSWORD_DEFAULT), $u['id']]);
            audit('ganti_password', 'Mengubah password akun');
            flash('Password berhasil diganti.');
            redirect('siswa-profil.php');
        }
    }
}

$riwayat = db()->query('SELECT * FROM audit_logs WHERE user_id = ' . (int)$u['id'] . ' ORDER BY id DESC LIMIT 8')->fetchAll();
$jumlahKursus = (int)db()->query('SELECT COUNT(*) FROM enrollments WHERE user_id = ' . (int)$u['id'])->fetchColumn();
$jumlahSertifikat = sertifikat_aktif() ? (int)db()->query('SELECT COUNT(*) FROM certificates WHERE user_id = ' . (int)$u['id'])->fetchColumn() : 0;

page_start('Profil Saya', 'Kelola data akun, foto profil, dan password.');
?>
<?php if ($error): ?><div class="flash flash-danger mb3"><?= icon('x', 17) ?><span><?= e($error) ?></span></div><?php endif; ?>

<div class="split">
  <div>
    <div class="card">
      <div class="tabs">
        <button class="tab active" data-tab="data">Data Profil</button>
        <button class="tab" data-tab="pass">Ganti Password</button>
      </div>

      <div class="tab-panel active" id="panel-data">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="profil">
          <div class="row-gap mb3">
            <span class="avatar xl" <?= $u['foto'] ? 'style="background-image:url(' . e($u['foto']) . ')"' : '' ?>><?= $u['foto'] ? '' : e(strtoupper(mb_substr($u['nama'], 0, 1))) ?></span>
            <div>
              <label for="foto">Foto Profil</label>
              <input type="file" id="foto" name="foto" accept="image/*">
              <div class="form-hint">Format JPG/PNG/WebP, maksimal 15MB.</div>
            </div>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label for="nama">Nama Lengkap</label>
              <input type="text" id="nama" name="nama" value="<?= e($u['nama']) ?>" required>
            </div>
            <div class="form-group">
              <label for="email">Email</label>
              <input type="email" id="email" name="email" value="<?= e($u['email']) ?>" required>
            </div>
            <div class="form-group">
              <label for="telepon">No. Telepon / WA</label>
              <input type="text" id="telepon" name="telepon" value="<?= e($u['telepon']) ?>">
            </div>
            <div class="form-group">
              <label>Username</label>
              <input type="text" value="<?= e($u['username']) ?>" disabled>
              <div class="form-hint">Username tidak dapat diubah sendiri. Hubungi admin bila perlu.</div>
            </div>
          </div>
          <div class="form-group">
            <label for="alamat">Alamat</label>
            <input type="text" id="alamat" name="alamat" value="<?= e($u['alamat']) ?>">
          </div>
          <div class="form-group">
            <label for="bio">Bio Singkat</label>
            <textarea id="bio" name="bio" placeholder="Ceritakan tujuan belajarmu..."><?= e($u['bio']) ?></textarea>
          </div>
          <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Perubahan</button>
        </form>
      </div>

      <div class="tab-panel" id="panel-pass">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="password">
          <div class="form-group">
            <label for="lama">Password Lama</label>
            <input type="password" id="lama" name="lama" required>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label for="baru">Password Baru</label>
              <input type="password" id="baru" name="baru" required>
            </div>
            <div class="form-group">
              <label for="ulang">Ulangi Password Baru</label>
              <input type="password" id="ulang" name="ulang" required>
            </div>
          </div>
          <div class="form-hint mb3">Minimal 6 karakter. Setelah diganti kamu tetap login di perangkat ini.</div>
          <button class="btn btn-primary" type="submit"><?= icon('lock', 17) ?> Ganti Password</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('clock', 20) ?> Aktivitas Terakhir</h2></div>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Waktu</th><th>Aksi</th><th>Detail</th></tr></thead>
          <tbody>
          <?php foreach ($riwayat as $r): ?>
            <tr><td class="nowrap"><?= e(tgl_id($r['created_at'], true)) ?></td><td><span class="chip"><?= e($r['aksi']) ?></span></td><td class="muted small"><?= e($r['detail']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$riwayat): ?><tr><td colspan="3" class="muted">Belum ada aktivitas.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <aside>
    <div class="card">
      <h3 class="mt0"><?= icon('user', 18) ?> Ringkasan Akun</h3>
      <dl class="kv">
        <dt>Nama</dt><dd><?= e($u['nama']) ?></dd>
        <dt>Username</dt><dd><?= e($u['username']) ?></dd>
        <dt>Email</dt><dd><?= e($u['email']) ?></dd>
        <dt>Role</dt><dd><?= e(role_label($u['role'])) ?></dd>
        <dt>Status</dt><dd><?= status_badge($u['status']) ?></dd>
        <dt>Terdaftar</dt><dd><?= e(tgl_id($u['created_at'])) ?></dd>
        <dt>Login terakhir</dt><dd><?= e(tgl_id($u['last_login'], true)) ?></dd>
      </dl>
      <div class="divider"></div>
      <div class="grid cols-2" style="gap:12px">
        <div class="card tight flat center"><div class="stat-num"><?= $jumlahKursus ?></div><div class="muted small">Kursus</div></div>
        <?php if (sertifikat_aktif()): ?><div class="card tight flat center"><div class="stat-num"><?= $jumlahSertifikat ?></div><div class="muted small">Sertifikat</div></div><?php endif; ?>
      </div>
    </div>
    <div class="card">
      <h3 class="mt0"><?= icon('shield', 18) ?> Keamanan</h3>
      <p class="muted small mb0">Gunakan password yang unik dan jangan bagikan akunmu. Semua aktivitas penting tercatat di audit log sistem.</p>
    </div>
  </aside>
</div>
<?php page_end(); ?>
