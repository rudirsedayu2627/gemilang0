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
        $role = in_array(post('role'), ['admin', 'guru', 'siswa'], true) ? post('role') : 'siswa';
        $cek = db()->prepare('SELECT COUNT(*) FROM users WHERE (email = ? OR username = ?) AND id <> ?');
        $cek->execute([$email, $username, $id]);
        if ((int)$cek->fetchColumn() > 0) {
            flash('Email atau username sudah dipakai pengguna lain.', 'danger');
            redirect('admin-users.php');
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
            db()->prepare('UPDATE users SET nama=?, username=?, email=?, role=?, telepon=?, alamat=?, bio=?, status=?, foto=? WHERE id=?')
                ->execute([$nama, $username, $email, $role, post('telepon'), post('alamat'), post('bio'), post('status', 'aktif'), $foto, $id]);
            if (post('password') !== '') {
                db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash(post('password'), PASSWORD_DEFAULT), $id]);
            }
            audit('edit_user', 'Memperbarui user ' . $nama . ' (' . $role . ')');
            flash('Data pengguna diperbarui.');
        } else {
            $pass = post('password') !== '' ? post('password') : 'password123';
            db()->prepare('INSERT INTO users (nama, username, email, password, role, telepon, alamat, bio, status) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$nama, $username, $email, password_hash($pass, PASSWORD_DEFAULT), $role, post('telepon'), post('alamat'), post('bio'), post('status', 'aktif')]);
            $newId = (int)db()->lastInsertId();
            notify($newId, 'Akunmu sudah dibuat', 'Admin membuatkan akun untukmu. Password awal: ' . $pass . '. Segera ganti password di halaman profil.', 'siswa-profil.php');
            audit('tambah_user', 'Menambah user ' . $nama . ' (' . $role . ')');
            flash('Pengguna baru dibuat. Password awal: ' . $pass);
        }
    }
    if ($aksi === 'hapus' && $id !== (int)$u['id']) {
        // bersihkan semua data turunan lebih dulu agar tidak ada baris yatim
        lms_hapus_turunan('user', $id);
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        audit('hapus_user', 'Menghapus user id ' . $id);
        flash('Pengguna dihapus.');
    }
    if ($aksi === 'reset') {
        $pass = post('password', 'password123');
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        notify($id, 'Password direset admin', 'Password akunmu direset menjadi: ' . $pass, 'login.php');
        audit('reset_password', 'Reset password user ' . $id);
        flash('Password direset menjadi: ' . $pass);
    }
    if ($aksi === 'status') {
        db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([post('status'), $id]);
        audit('ubah_status', 'Status user ' . $id . ' menjadi ' . post('status'));
        flash('Status pengguna diperbarui.');
    }
    redirect('admin-users.php' . (get('role') ? '?role=' . urlencode(get('role')) : ''));
}

$roleFilter = get('role');
$q = get('q');
$where = ['1=1'];
$args = [];
if (in_array($roleFilter, ['admin', 'guru', 'siswa'], true)) {
    $where[] = 'role = ?';
    $args[] = $roleFilter;
}
if ($q !== '') {
    $where[] = '(nama LIKE ? OR email LIKE ? OR username LIKE ?)';
    $args[] = "%$q%";
    $args[] = "%$q%";
    $args[] = "%$q%";
}
$w = implode(' AND ', $where);

if (get('export') === 'xlsx') {
    $rows = [['Nama', 'Username', 'Email', 'Role', 'Status', 'Telepon', 'Terdaftar', 'Login Terakhir']];
    $st = db()->prepare("SELECT * FROM users WHERE $w ORDER BY id DESC");
    $st->execute($args);
    foreach ($st->fetchAll() as $r) {
        $rows[] = [$r['nama'], $r['username'], $r['email'], $r['role'], $r['status'], $r['telepon'], $r['created_at'], $r['last_login']];
    }
    audit('export_user', 'Export data user');
    xlsx_download('data-user-' . date('Ymd') . '.xlsx', $rows);
}

$page = max(1, num('page', 1));
$countStmt = db()->prepare("SELECT COUNT(*) FROM users WHERE $w");
$countStmt->execute($args);
$p = paginate((int)$countStmt->fetchColumn(), 20, $page);

$st = db()->prepare("SELECT us.*, 
        (SELECT COUNT(*) FROM enrollments WHERE user_id = us.id) AS jml_kursus,
        (SELECT COUNT(*) FROM courses WHERE guru_id = us.id) AS jml_ajar,
        (SELECT COUNT(*) FROM quiz_attempts WHERE user_id = us.id AND status != 'in_progress') AS jml_attempt
    FROM users us WHERE $w ORDER BY us.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}");
$st->execute($args);
$rows = $st->fetchAll();

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$ringkas = db()->query("SELECT role, COUNT(*) AS jml FROM users GROUP BY role")->fetchAll();
$perRole = [];
foreach ($ringkas as $r) {
    $perRole[$r['role']] = (int)$r['jml'];
}

page_start('Manajemen User', 'Kelola seluruh akun: siswa, guru, dan administrator.', [
    'actions' => '<a class="btn btn-ghost" href="admin-users.php?export=xlsx">' . icon('download', 17) . ' Export Excel</a>'
        . '<button class="btn btn-primary" data-modal-open="modal-user">' . icon('plus', 17) . ' User Baru</button>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= array_sum($perRole) ?></div><div class="stat-lab">Total pengguna</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('user', 22) ?></div><div><div class="stat-num"><?= (int)($perRole['guru'] ?? 0) ?></div><div class="stat-lab">Guru</div></div></div>
  <div class="stat"><div class="stat-ic g3"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= (int)($perRole['siswa'] ?? 0) ?></div><div class="stat-lab">Siswa</div></div></div>
  <div class="stat"><div class="stat-ic g4"><?= icon('shield', 22) ?></div><div><div class="stat-num"><?= (int)($perRole['admin'] ?? 0) ?></div><div class="stat-lab">Administrator</div></div></div>
</div>

<div class="card">
  <form class="toolbar" method="get">
    <div class="form-group" style="flex:1;min-width:200px">
      <label>Cari</label>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nama, email, atau username">
    </div>
    <div class="form-group">
      <label>Role</label>
      <select name="role" data-autosubmit>
        <option value="">Semua role</option>
        <?php foreach (['admin' => 'Administrator', 'guru' => 'Guru', 'siswa' => 'Siswa'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $roleFilter === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary" type="submit"><?= icon('search', 17) ?> Cari</button>
    <a class="btn btn-ghost" href="admin-users.php">Reset</a>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Pengguna</th><th>Username</th><th>Role</th><th>Aktivitas</th><th>Login Terakhir</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <div class="tbl-student">
              <span class="avatar sm" <?= $r['foto'] ? 'style="background-image:url(' . e($r['foto']) . ')"' : '' ?>><?= $r['foto'] ? '' : e(strtoupper(mb_substr($r['nama'], 0, 1))) ?></span>
              <div><strong><?= e($r['nama']) ?><?= (int)$r['id'] === (int)$u['id'] ? ' <span class="badge badge-info">Anda</span>' : '' ?></strong><div class="muted small"><?= e($r['email']) ?></div></div>
            </div>
          </td>
          <td class="small"><?= e($r['username']) ?></td>
          <td><?= status_badge($r['role']) ?></td>
          <td class="small"><?= $r['role'] === 'guru' ? ((int)$r['jml_ajar'] . ' kursus diampu') : ((int)$r['jml_kursus'] . ' kursus diikuti') ?><div class="muted small"><?= (int)$r['jml_attempt'] ?> quiz dikerjakan</div></td>
          <td class="small nowrap"><?= e(tgl_id($r['last_login'], true)) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="admin-users.php?role=<?= e($roleFilter) ?>&edit=<?= (int)$r['id'] ?>"><?= icon('edit', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="reset">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="password" value="password123">
              <button class="btn btn-ghost btn-sm" data-confirm="Reset password user ini menjadi 'password123'?" title="Reset password"><?= icon('lock', 14) ?></button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="status">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="status" value="<?= $r['status'] === 'aktif' ? 'nonaktif' : 'aktif' ?>">
              <button class="btn btn-ghost btn-sm" title="Aktif/Nonaktifkan"><?= icon($r['status'] === 'aktif' ? 'x' : 'check', 14) ?></button>
            </form>
            <?php if ((int)$r['id'] !== (int)$u['id']): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="hapus">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-danger btn-sm" data-confirm="Hapus pengguna ini permanen?"><?= icon('trash', 14) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted">Tidak ada pengguna yang cocok.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pager_html($p, 'admin-users.php?q=' . urlencode($q) . '&role=' . urlencode($roleFilter)) ?>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-user">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Pengguna' : 'Pengguna Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-grid">
        <div class="form-group"><label>Nama lengkap *</label><input type="text" name="nama" value="<?= e($edit['nama'] ?? '') ?>" required></div>
        <div class="form-group"><label>Email *</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>" required></div>
        <div class="form-group"><label>Username</label><input type="text" name="username" value="<?= e($edit['username'] ?? '') ?>"></div>
        <div class="form-group"><label>No. telepon</label><input type="text" name="telepon" value="<?= e($edit['telepon'] ?? '') ?>"></div>
        <div class="form-group">
          <label>Role *</label>
          <select name="role">
            <?php foreach (['siswa' => 'Siswa', 'guru' => 'Guru', 'admin' => 'Administrator'] as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($edit['role'] ?? 'siswa') === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="aktif" <?= ($edit['status'] ?? 'aktif') === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="nonaktif" <?= ($edit['status'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Password <?= $edit ? '(biarkan kosong bila tidak diubah)' : '' ?></label>
        <input type="text" name="password" placeholder="<?= $edit ? '••••••' : 'password123' ?>">
      </div>
      <div class="form-group"><label>Alamat</label><input type="text" name="alamat" value="<?= e($edit['alamat'] ?? '') ?>"></div>
      <div class="form-group"><label>Bio</label><textarea name="bio"><?= e($edit['bio'] ?? '') ?></textarea></div>
      <div class="form-grid">
        <div class="form-group"><label>Foto profil (upload)</label><input type="file" name="foto" accept="image/*"></div>
        <div class="form-group"><label>atau URL foto</label><input type="url" name="foto_url" value="<?= e($edit['foto'] ?? '') ?>"></div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-users.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
