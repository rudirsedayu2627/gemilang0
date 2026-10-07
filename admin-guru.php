<?php
require_once __DIR__ . '/includes/layout.php';
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
            redirect('admin-guru.php');
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
            db()->prepare('UPDATE users SET nama=?, username=?, email=?, telepon=?, alamat=?, bio=?, status=?, foto=? WHERE id=?')
                ->execute([$nama, $username, $email, post('telepon'), post('alamat'), post('bio'), post('status', 'aktif'), $foto, $id]);
            if (post('password') !== '') {
                db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash(post('password'), PASSWORD_DEFAULT), $id]);
            }
            audit('edit_guru', 'Memperbarui data guru ' . $nama);
            flash('Data guru diperbarui.');
        } else {
            $pass = post('password') !== '' ? post('password') : 'guru123';
            db()->prepare('INSERT INTO users (nama, username, email, password, role, telepon, alamat, bio, status) VALUES (?,?,?,?,"guru",?,?,?,?)')
                ->execute([$nama, $username, $email, password_hash($pass, PASSWORD_DEFAULT), post('telepon'), post('alamat'), post('bio'), post('status', 'aktif')]);
            $newId = (int)db()->lastInsertId();
            notify($newId, 'Akun guru dibuat', 'Admin membuatkan akun guru untukmu. Password awal: ' . $pass, 'guru-dashboard.php');
            audit('tambah_guru', 'Menambah guru ' . $nama);
            flash('Guru baru dibuat. Password awal: ' . $pass);
        }
    }
    if ($aksi === 'hapus' && $id > 0) {
        // penugasan dilepas (kursus/kelas/quiz/tugas/pengumuman tetap ada)
        lms_hapus_turunan('user', $id);
        db()->prepare('DELETE FROM users WHERE id = ? AND role = "guru"')->execute([$id]);
        audit('hapus_guru', 'Menghapus guru id ' . $id);
        flash('Data guru dihapus. Kursus yang diampu dipindah ke status tanpa pengajar.');
    }
    if ($aksi === 'reset') {
        $pass = post('password', 'guru123');
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        audit('reset_password', 'Reset password guru ' . $id);
        flash('Password guru direset menjadi: ' . $pass);
    }
    if ($aksi === 'status') {
        db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([post('status'), $id]);
        flash('Status guru diperbarui.');
    }
    redirect('admin-guru.php');
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'guru'");
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$rows = db()->query("SELECT us.*,
        (SELECT COUNT(*) FROM courses WHERE guru_id = us.id) AS jml_kursus,
        (SELECT COUNT(*) FROM lessons WHERE course_id IN (SELECT id FROM courses WHERE guru_id = us.id)) AS jml_materi,
        (SELECT COUNT(*) FROM quizzes WHERE guru_id = us.id) AS jml_quiz,
        (SELECT COUNT(*) FROM enrollments WHERE course_id IN (SELECT id FROM courses WHERE guru_id = us.id)) AS jml_siswa,
        (SELECT COUNT(*) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE qz.guru_id = us.id AND a.status = 'menunggu_koreksi') AS perlu_koreksi
    FROM users us WHERE us.role = 'guru' ORDER BY us.nama")->fetchAll();
$tanpaKursus = count(array_filter($rows, function ($r) { return (int)$r['jml_kursus'] === 0; }));

page_start('Manajemen Guru', 'Kelola akun pengajar, penugasan kursus, dan beban mengajarnya.', [
    'actions' => '<button class="btn btn-primary" data-modal-open="modal-guru">' . icon('plus', 17) . ' Guru Baru</button>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('user', 22) ?></div><div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lab">Total guru</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('book', 22) ?></div><div><div class="stat-num"><?= array_sum(array_column($rows, 'jml_kursus')) ?></div><div class="stat-lab">Kursus diampu</div></div></div>
  <div class="stat"><div class="stat-ic g3"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= array_sum(array_column($rows, 'jml_siswa')) ?></div><div class="stat-lab">Siswa diajar</div></div></div>
  <div class="stat"><div class="stat-ic g4"><?= icon('bell', 22) ?></div><div><div class="stat-num"><?= $tanpaKursus ?></div><div class="stat-lab">Guru tanpa kursus</div></div></div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Guru</th><th>Kontak</th><th>Kursus</th><th>Materi</th><th>Quiz</th><th>Siswa</th><th>Perlu Koreksi</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><div class="tbl-student"><span class="avatar sm" <?= $r['foto'] ? 'style="background-image:url(' . e($r['foto']) . ')"' : '' ?>><?= $r['foto'] ? '' : e(strtoupper(mb_substr($r['nama'], 0, 1))) ?></span><div><strong><?= e($r['nama']) ?></strong><div class="muted small"><?= e($r['username']) ?></div></div></div></td>
          <td class="small"><?= e($r['email']) ?><div class="muted small"><?= e($r['telepon'] ?: '-') ?></div></td>
          <td><?= (int)$r['jml_kursus'] ?></td>
          <td><?= (int)$r['jml_materi'] ?></td>
          <td><?= (int)$r['jml_quiz'] ?></td>
          <td><?= (int)$r['jml_siswa'] ?></td>
          <td><?= (int)$r['perlu_koreksi'] ? '<span class="badge badge-warning">' . (int)$r['perlu_koreksi'] . '</span>' : '-' ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="admin-guru.php?edit=<?= (int)$r['id'] ?>"><?= icon('edit', 14) ?></a>
            <a class="btn btn-ghost btn-sm" href="admin-kursus.php?guru=<?= (int)$r['id'] ?>" title="Lihat kursus yang diampu"><?= icon('book', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="reset">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="password" value="guru123">
              <button class="btn btn-ghost btn-sm" data-confirm="Reset password guru ini menjadi 'guru123'?" title="Reset password"><?= icon('lock', 14) ?></button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus guru ini? Kursusnya akan kehilangan pengajar."><?= icon('trash', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9" class="muted">Belum ada guru.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-guru">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Data Guru' : 'Guru Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-grid">
        <div class="form-group"><label>Nama lengkap *</label><input type="text" name="nama" value="<?= e($edit['nama'] ?? '') ?>" required></div>
        <div class="form-group"><label>Email *</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>" required></div>
        <div class="form-group"><label>Username</label><input type="text" name="username" value="<?= e($edit['username'] ?? '') ?>"></div>
        <div class="form-group"><label>Telepon</label><input type="text" name="telepon" value="<?= e($edit['telepon'] ?? '') ?>"></div>
        <div class="form-group"><label>Password <?= $edit ? '(kosongkan bila tidak diubah)' : '' ?></label><input type="text" name="password" placeholder="<?= $edit ? '••••••' : 'guru123' ?>"></div>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="aktif" <?= ($edit['status'] ?? 'aktif') === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="nonaktif" <?= ($edit['status'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Alamat</label><input type="text" name="alamat" value="<?= e($edit['alamat'] ?? '') ?>"></div>
      <div class="form-group"><label>Bio / keahlian</label><textarea name="bio"><?= e($edit['bio'] ?? '') ?></textarea></div>
      <div class="form-grid">
        <div class="form-group"><label>Foto (upload)</label><input type="file" name="foto" accept="image/*"></div>
        <div class="form-group"><label>atau URL foto</label><input type="url" name="foto_url" value="<?= e($edit['foto'] ?? '') ?>"></div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-guru.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
