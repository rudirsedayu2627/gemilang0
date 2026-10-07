<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $nama = post('nama');
        $email = strtolower(post('email'));
        $username = strtolower(preg_replace('/[^a-z0-9_]/i', '', post('username') ?: explode('@', $email)[0]));
        $cek = db()->prepare('SELECT COUNT(*) FROM users WHERE (email = ? OR username = ?) AND id <> ?');
        $cek->execute([$email, $username, $id]);
        if ((int)$cek->fetchColumn() > 0) {
            flash('Email atau username sudah dipakai.', 'danger');
            redirect('admin-siswa.php');
        }
        $foto = post('foto_url');
        if (!empty($_FILES['foto']['name'])) {
            try {
                $foto = handle_upload('foto');
            } catch (Throwable $e) {
                flash('Upload foto gagal: ' . $e->getMessage(), 'danger');
            }
        }
        if ($id > 0) {
            db()->prepare('UPDATE users SET nama=?, username=?, email=?, telepon=?, alamat=?, status=?, foto=? WHERE id=?')
                ->execute([$nama, $username, $email, post('telepon'), post('alamat'), post('status', 'aktif'), $foto, $id]);
            if (post('password') !== '') {
                db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash(post('password'), PASSWORD_DEFAULT), $id]);
            }
            audit('edit_siswa', 'Memperbarui data siswa ' . $nama);
            flash('Data siswa diperbarui.');
        } else {
            $pass = post('password') !== '' ? post('password') : 'siswa123';
            db()->prepare('INSERT INTO users (nama, username, email, password, role, telepon, alamat, status) VALUES (?,?,?,?,"siswa",?,?,?)')
                ->execute([$nama, $username, $email, password_hash($pass, PASSWORD_DEFAULT), post('telepon'), post('alamat'), post('status', 'aktif')]);
            notify((int)db()->lastInsertId(), 'Akunmu sudah dibuat', 'Admin membuatkan akun siswa untukmu. Password awal: ' . $pass, 'kursus.php');
            audit('tambah_siswa', 'Menambah siswa ' . $nama);
            flash('Siswa baru dibuat. Password awal: ' . $pass);
        }
    }
    if ($aksi === 'hapus' && $id > 0) {
        lms_hapus_turunan('user', $id);
        db()->prepare('DELETE FROM users WHERE id = ? AND role = "siswa"')->execute([$id]);
        audit('hapus_siswa', 'Menghapus siswa id ' . $id);
        flash('Siswa dihapus.');
    }
    if ($aksi === 'reset') {
        $pass = post('password', 'siswa123');
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        audit('reset_password', 'Reset password siswa ' . $id);
        flash('Password siswa direset menjadi: ' . $pass);
    }
    if ($aksi === 'status') {
        db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([post('status'), $id]);
        flash('Status siswa diperbarui.');
    }
    if ($aksi === 'enroll') {
        foreach ((array)post('siswa', []) as $sid) {
            db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id, status) VALUES (?, ?, "aktif")')->execute([(int)$sid, (int)post('course_id')]);
            recalc_course_progress((int)$sid, (int)post('course_id'));
            notify((int)$sid, 'Kamu terdaftar di kursus baru', 'Admin mendaftarkanmu ke kursus baru.', 'siswa-kursus.php');
        }
        flash('Siswa didaftarkan ke kursus.');
    }
    if ($aksi === 'unenroll') {
        db()->prepare('DELETE FROM enrollments WHERE user_id = ? AND course_id = ?')->execute([$id, (int)post('course_id')]);
        flash('Siswa dikeluarkan dari kursus.');
    }
    redirect('admin-siswa.php');
}

if (get('export') === 'xlsx') {
    $kolomSert = sertifikat_aktif();
    $rows = [$kolomSert
        ? ['Nama', 'Email', 'Username', 'Telepon', 'Kursus Diikuti', 'Progress Rata-rata', 'Sertifikat', 'Status']
        : ['Nama', 'Email', 'Username', 'Telepon', 'Kursus Diikuti', 'Progress Rata-rata', 'Status']];
    foreach (db()->query("SELECT us.*, (SELECT COUNT(*) FROM enrollments WHERE user_id = us.id) AS jml,
            (SELECT COALESCE(ROUND(AVG(progress),1),0) FROM enrollments WHERE user_id = us.id) AS prog,
            (SELECT COUNT(*) FROM certificates WHERE user_id = us.id) AS cert
        FROM users us WHERE us.role = 'siswa' ORDER BY us.nama")->fetchAll() as $r) {
        $baris = [$r['nama'], $r['email'], $r['username'], $r['telepon'], $r['jml'], $r['prog']];
        if ($kolomSert) {
            $baris[] = $r['cert'];
        }
        $baris[] = $r['status'];
        $rows[] = $baris;
    }
    audit('export_siswa', 'Export data siswa (admin)');
    xlsx_download('data-siswa-' . date('Ymd') . '.xlsx', $rows);
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'siswa'");
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$rows = db()->query("SELECT us.*,
        (SELECT COUNT(*) FROM enrollments WHERE user_id = us.id) AS jml_kursus,
        (SELECT COALESCE(ROUND(AVG(progress),1),0) FROM enrollments WHERE user_id = us.id) AS rata_progress,
        (SELECT COUNT(*) FROM certificates WHERE user_id = us.id) AS jml_sertifikat,
        (SELECT COUNT(*) FROM quiz_attempts WHERE user_id = us.id AND status != 'in_progress') AS jml_attempt
    FROM users us WHERE us.role = 'siswa' ORDER BY us.nama")->fetchAll();
$kursus = db()->query("SELECT id, judul FROM courses WHERE status = 'aktif' ORDER BY judul")->fetchAll();
$detail = null;
$enrollSiswa = [];
if (num('siswa') > 0) {
    $detail = db()->query('SELECT * FROM users WHERE id = ' . num('siswa'))->fetch();
    if ($detail) {
        $enrollSiswa = db()->query("SELECT e.*, co.judul, co.level, us.nama AS guru FROM enrollments e JOIN courses co ON co.id = e.course_id
            LEFT JOIN users us ON us.id = co.guru_id WHERE e.user_id = " . (int)$detail['id'] . ' ORDER BY e.enrolled_at DESC')->fetchAll();
    }
}
$tanpaKursus = count(array_filter($rows, function ($r) { return (int)$r['jml_kursus'] === 0; }));

page_start('Manajemen Siswa', 'Kelola akun siswa, pendaftaran kursus, dan capaian belajarnya.', [
    'actions' => '<a class="btn btn-ghost" href="admin-siswa.php?export=xlsx">' . icon('download', 17) . ' Export Excel</a>'
        . '<button class="btn btn-ghost" data-modal-open="modal-enroll">' . icon('plus', 17) . ' Daftarkan ke Kursus</button>'
        . '<button class="btn btn-primary" data-modal-open="modal-siswa">' . icon('plus', 17) . ' Siswa Baru</button>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lab">Total siswa</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('book', 22) ?></div><div><div class="stat-num"><?= array_sum(array_column($rows, 'jml_kursus')) ?></div><div class="stat-lab">Total pendaftaran kursus</div></div></div>
  <?php if (sertifikat_aktif()): ?><div class="stat"><div class="stat-ic g5"><?= icon('award', 22) ?></div><div><div class="stat-num"><?= array_sum(array_column($rows, 'jml_sertifikat')) ?></div><div class="stat-lab">Sertifikat siswa</div></div></div><?php endif; ?>
  <div class="stat"><div class="stat-ic g6"><?= icon('bell', 22) ?></div><div><div class="stat-num"><?= $tanpaKursus ?></div><div class="stat-lab">Belum ikut kursus</div></div></div>
</div>

<div class="card">
  <form class="toolbar">
    <div class="form-group" style="flex:1;min-width:220px">
      <label>Cari siswa</label>
      <input type="search" data-filter-input="#tbl-siswa" placeholder="Nama, email, atau username">
    </div>
  </form>
  <div class="table-wrap">
    <table class="data" id="tbl-siswa">
      <thead><tr><th>Siswa</th><th>Kontak</th><th>Kursus</th><th>Progress</th><th>Quiz</th><?php if (sertifikat_aktif()): ?><th>Sertifikat</th><?php endif; ?><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><div class="tbl-student"><span class="avatar sm" <?= $r['foto'] ? 'style="background-image:url(' . e($r['foto']) . ')"' : '' ?>><?= $r['foto'] ? '' : e(strtoupper(mb_substr($r['nama'], 0, 1))) ?></span><div><strong><?= e($r['nama']) ?></strong><div class="muted small"><?= e($r['username']) ?> &middot; daftar <?= e(tgl_id($r['created_at'])) ?></div></div></div></td>
          <td class="small"><?= e($r['email']) ?><div class="muted small"><?= e($r['telepon'] ?: '-') ?></div></td>
          <td><?= (int)$r['jml_kursus'] ?></td>
          <td style="min-width:150px">
            <div class="progress-lab"><span><?= number_format((float)$r['rata_progress'], 1) ?>%</span></div>
            <div class="progress thin"><span style="width:<?= round((float)$r['rata_progress']) ?>%"></span></div>
          </td>
          <td><?= (int)$r['jml_attempt'] ?>x</td>
          <?php if (sertifikat_aktif()): ?><td><?= (int)$r['jml_sertifikat'] ?></td><?php endif; ?>
          <td><?= status_badge($r['status']) ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="admin-siswa.php?siswa=<?= (int)$r['id'] ?>" title="Detail kursus"><?= icon('book', 14) ?></a>
            <a class="btn btn-ghost btn-sm" href="admin-siswa.php?edit=<?= (int)$r['id'] ?>"><?= icon('edit', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="reset">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="password" value="siswa123">
              <button class="btn btn-ghost btn-sm" data-confirm="Reset password siswa ini menjadi 'siswa123'?" title="Reset password"><?= icon('lock', 14) ?></button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="status">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="status" value="<?= $r['status'] === 'aktif' ? 'nonaktif' : 'aktif' ?>">
              <button class="btn btn-ghost btn-sm" title="Aktif/Nonaktifkan"><?= icon($r['status'] === 'aktif' ? 'x' : 'check', 14) ?></button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus siswa ini beserta riwayat pendaftarannya?"><?= icon('trash', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="muted">Belum ada siswa.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($detail): ?>
<div class="card">
  <div class="card-head">
    <div><h2 class="mt0 mb0"><?= icon('book', 20) ?> Kursus: <?= e($detail['nama']) ?></h2>
    <div class="muted small"><?= e($detail['email']) ?> &middot; <?= count($enrollSiswa) ?> kursus diikuti</div></div>
    <a class="btn btn-ghost" href="admin-siswa.php">Tutup</a>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Kursus</th><th>Pengajar</th><th>Progress</th><th>Status</th><th>Terdaftar</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($enrollSiswa as $e): ?>
        <tr>
          <td><strong><?= e($e['judul']) ?></strong><div class="muted small"><?= e($e['level']) ?></div></td>
          <td class="small"><?= e($e['guru'] ?: '-') ?></td>
          <td style="min-width:160px">
            <div class="progress-lab"><span><?= round((float)$e['progress']) ?>%</span></div>
            <div class="progress thin"><span style="width:<?= round((float)$e['progress']) ?>%"></span></div>
          </td>
          <td><?= status_badge($e['status']) ?></td>
          <td class="small"><?= e(tgl_id($e['enrolled_at'])) ?></td>
          <td>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="unenroll">
              <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
              <input type="hidden" name="course_id" value="<?= (int)$e['course_id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Keluarkan siswa dari kursus ini?"><?= icon('x', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$enrollSiswa): ?><tr><td colspan="6" class="muted">Siswa belum mengikuti kursus apa pun.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="modal-back" id="modal-enroll">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0">Daftarkan Siswa ke Kursus</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="enroll">
      <div class="form-group">
        <label>Kursus *</label>
        <select name="course_id" required>
          <?php foreach ($kursus as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['judul']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Pilih siswa</label>
        <select name="siswa[]" multiple size="10" style="height:auto">
          <?php foreach ($rows as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['nama']) ?> — <?= e($r['email']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Daftarkan</button>
        <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-siswa">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Data Siswa' : 'Siswa Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-grid">
        <div class="form-group"><label>Nama lengkap *</label><input type="text" name="nama" value="<?= e($edit['nama'] ?? '') ?>" required></div>
        <div class="form-group"><label>Email *</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>" required></div>
        <div class="form-group"><label>Username</label><input type="text" name="username" value="<?= e($edit['username'] ?? '') ?>"></div>
        <div class="form-group"><label>Telepon / WA</label><input type="text" name="telepon" value="<?= e($edit['telepon'] ?? '') ?>"></div>
        <div class="form-group"><label>Password <?= $edit ? '(kosongkan bila tidak diubah)' : '' ?></label><input type="text" name="password" placeholder="<?= $edit ? '••••••' : 'siswa123' ?>"></div>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="aktif" <?= ($edit['status'] ?? 'aktif') === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="nonaktif" <?= ($edit['status'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Alamat</label><input type="text" name="alamat" value="<?= e($edit['alamat'] ?? '') ?>"></div>
      <div class="form-grid">
        <div class="form-group"><label>Foto (upload)</label><input type="file" name="foto" accept="image/*"></div>
        <div class="form-group"><label>atau URL foto</label><input type="url" name="foto_url" value="<?= e($edit['foto'] ?? '') ?>"></div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-siswa.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
