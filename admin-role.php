<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $nama = strtolower(preg_replace('/[^a-z_]/i', '', post('nama')));
        $perms = (array)post('permissions', []);
        if ($nama === '') {
            flash('Nama role wajib diisi.', 'danger');
        } else {
            $args = [$nama, post('label'), json_encode(array_values($perms))];
            if ($id > 0) {
                $args[] = $id;
                db()->prepare('UPDATE roles SET nama=?, label=?, permissions=? WHERE id=?')->execute($args);
                flash('Role diperbarui.');
            } else {
                db()->prepare('INSERT INTO roles (nama, label, permissions) VALUES (?,?,?)')->execute($args);
                flash('Role baru dibuat.');
            }
            audit('simpan_role', 'Menyimpan role ' . $nama);
        }
    }
    if ($aksi === 'hapus') {
        $r = db()->query('SELECT nama FROM roles WHERE id = ' . $id)->fetch();
        if ($r && in_array($r['nama'], ['admin', 'guru', 'siswa'], true)) {
            flash('Role inti (admin/guru/siswa) tidak dapat dihapus.', 'warning');
        } else {
            db()->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
            flash('Role dihapus.');
        }
    }
    if ($aksi === 'ganti_role') {
        $target = (int)post('user_id');
        $role = post('role');
        if ($target === (int)$u['id']) {
            flash('Kamu tidak bisa mengubah role akunmu sendiri.', 'warning');
        } else {
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $target]);
            notify($target, 'Role akunmu berubah', 'Role akunmu sekarang: ' . role_label($role) . '.', 'index.php');
            audit('ganti_role', 'Mengubah role user ' . $target . ' menjadi ' . $role);
            flash('Role pengguna diperbarui.');
        }
    }
    redirect('admin-role.php');
}

$roles = db()->query('SELECT r.*, (SELECT COUNT(*) FROM users WHERE role = r.nama) AS jml_user FROM roles r ORDER BY r.id')->fetchAll();
$permissionList = ['kursus', 'kelas', 'materi', 'quiz', 'soal', 'nilai', 'absensi', 'tugas', 'forum', 'pengumuman', 'sertifikat', 'pengguna', 'pengaturan', 'laporan'];
if (!sertifikat_aktif()) {
    // Fitur sertifikat dimatikan: jangan tawarkan hak akses yang tak ada gunanya.
    $permissionList = array_values(array_filter($permissionList, function ($p) {
        return $p !== 'sertifikat';
    }));
}
$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM roles WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$perEdit = $edit && $edit['permissions'] ? json_decode($edit['permissions'], true) : [];
$userRoles = db()->query('SELECT id, nama, email, role FROM users ORDER BY role, nama')->fetchAll();

page_start('Manajemen Role', 'Atur role dan hak akses dasar pengguna sistem.', [
    'actions' => '<button class="btn btn-primary" data-modal-open="modal-role">' . icon('plus', 17) . ' Role Baru</button>',
]);
?>
<div class="split">
  <div>
    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('shield', 20) ?> Daftar Role</h2></div>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Role</th><th>Label</th><th>Hak Akses</th><th>Jumlah User</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($roles as $r): $perms = $r['permissions'] ? json_decode($r['permissions'], true) : []; ?>
            <tr>
              <td><code><?= e($r['nama']) ?></code></td>
              <td><?= e($r['label']) ?></td>
              <td class="small"><?= in_array('*', $perms, true) ? '<span class="badge badge-danger">Semua akses</span>' : implode(', ', array_map('e', $perms)) ?></td>
              <td><?= (int)$r['jml_user'] ?></td>
              <td class="actions">
                <a class="btn btn-ghost btn-sm" href="admin-role.php?edit=<?= (int)$r['id'] ?>"><?= icon('edit', 14) ?></a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="hapus">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-danger btn-sm" data-confirm="Hapus role ini?"><?= icon('trash', 14) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('users', 20) ?> Role Pengguna</h2></div>
      <p class="muted small">Ubah role pengguna bila ada guru yang menjadi admin, atau sebaliknya.</p>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Pengguna</th><th>Email</th><th>Role Saat Ini</th><th>Ubah Role</th></tr></thead>
          <tbody>
          <?php foreach ($userRoles as $ur): ?>
            <tr>
              <td><?= e($ur['nama']) ?></td>
              <td class="small"><?= e($ur['email']) ?></td>
              <td><?= e(role_label($ur['role'])) ?></td>
              <td>
                <?php if ((int)$ur['id'] !== (int)$u['id']): ?>
                  <form method="post" class="row-gap" style="gap:8px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="ganti_role">
                    <input type="hidden" name="user_id" value="<?= (int)$ur['id'] ?>">
                    <select name="role" data-autosubmit>
                      <?php foreach ($roles as $r): ?>
                        <option value="<?= e($r['nama']) ?>" <?= $ur['role'] === $r['nama'] ? 'selected' : '' ?>><?= e($r['label']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                <?php else: ?><span class="badge badge-info">Akun Anda</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <aside>
    <div class="card">
      <h3 class="mt0"><?= icon('lock', 18) ?> Catatan Hak Akses</h3>
      <p class="muted small">Role <strong>admin</strong> selalu memiliki seluruh akses. Role guru membuka menu pengajaran (kursus, materi, quiz, nilai, absensi, tugas, forum), sedangkan siswa hanya mengakses menu belajar.</p>
      <div class="dark-box">Perubahan hak akses di atas tercatat di Audit Log untuk keperluan penelusuran.</div>
    </div>
  </aside>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-role">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Role' : 'Role Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-grid">
        <div class="form-group"><label>Nama role (huruf kecil) *</label><input type="text" name="nama" value="<?= e($edit['nama'] ?? '') ?>" placeholder="asisten_guru" required></div>
        <div class="form-group"><label>Label tampilan *</label><input type="text" name="label" value="<?= e($edit['label'] ?? '') ?>" placeholder="Asisten Guru" required></div>
      </div>
      <div class="form-group">
        <label>Hak akses</label>
        <div class="grid cols-2" style="gap:10px">
          <?php foreach ($permissionList as $p): ?>
            <label class="check"><input type="checkbox" name="permissions[]" value="<?= $p ?>" <?= in_array($p, $perEdit, true) ? 'checked' : '' ?>> <?= e(ucfirst($p)) ?></label>
          <?php endforeach; ?>
          <label class="check"><input type="checkbox" name="permissions[]" value="*" <?= in_array('*', $perEdit, true) ? 'checked' : '' ?>> Semua akses</label>
        </div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-role.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
