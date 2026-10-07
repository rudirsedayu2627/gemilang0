<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $args = [post('course_id') ?: null, post('guru_id') ?: null, post('nama'), strtoupper(post('kode')), post('jadwal'), (int)post('kapasitas'), post('status', 'aktif')];
        if ($id > 0) {
            $args[] = $id;
            db()->prepare('UPDATE classes SET course_id=?, guru_id=?, nama=?, kode=?, jadwal=?, kapasitas=?, status=? WHERE id=?')->execute($args);
            flash('Kelas diperbarui.');
        } else {
            db()->prepare('INSERT INTO classes (course_id, guru_id, nama, kode, jadwal, kapasitas, status) VALUES (?,?,?,?,?,?,?)')->execute($args);
            flash('Kelas baru dibuat.');
        }
        audit('simpan_kelas', 'Menyimpan kelas ' . post('nama'));
    }
    if ($aksi === 'hapus') {
        lms_hapus_turunan('class', $id);
        audit('hapus_kelas', 'Menghapus kelas id ' . $id);
        flash('Kelas dihapus.');
    }
    if ($aksi === 'anggota') {
        db()->prepare('DELETE FROM class_students WHERE class_id = ?')->execute([$id]);
        foreach ((array)post('siswa', []) as $sid) {
            db()->prepare('INSERT OR IGNORE INTO class_students (class_id, user_id) VALUES (?, ?)')->execute([$id, (int)$sid]);
        }
        $cls = db()->query('SELECT course_id, nama FROM classes WHERE id = ' . $id)->fetch();
        if ($cls && $cls['course_id'] && post('daftarkan_kursus')) {
            foreach ((array)post('siswa', []) as $sid) {
                db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id, status) VALUES (?, ?, "aktif")')->execute([(int)$sid, (int)$cls['course_id']]);
                recalc_course_progress((int)$sid, (int)$cls['course_id']);
            }
        }
        flash('Anggota kelas diperbarui.');
    }
    redirect('admin-kelas.php' . ($id ? '?kelas=' . $id : ''));
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM classes WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$kelasId = num('kelas');
$detail = $kelasId ? db()->query('SELECT c.*, co.judul AS kursus, us.nama AS guru FROM classes c LEFT JOIN courses co ON co.id = c.course_id LEFT JOIN users us ON us.id = c.guru_id WHERE c.id = ' . $kelasId)->fetch() : null;
$anggotaIds = [];
$anggota = [];
if ($detail) {
    foreach (db()->query('SELECT user_id FROM class_students WHERE class_id = ' . $kelasId)->fetchAll(PDO::FETCH_COLUMN) as $x) {
        $anggotaIds[] = (int)$x;
    }
    $anggota = db()->query('SELECT us.id, us.nama, us.email FROM users us WHERE us.id IN (' . (implode(',', $anggotaIds ?: [0])) . ') ORDER BY us.nama')->fetchAll();
}
$rows = db()->query("SELECT c.*, co.judul AS kursus, us.nama AS guru,
        (SELECT COUNT(*) FROM class_students WHERE class_id = c.id) AS jml_siswa
    FROM classes c LEFT JOIN courses co ON co.id = c.course_id LEFT JOIN users us ON us.id = c.guru_id ORDER BY c.id DESC")->fetchAll();
$courses = db()->query("SELECT id, judul FROM courses ORDER BY judul")->fetchAll();
$guruList = db()->query("SELECT id, nama FROM users WHERE role = 'guru' ORDER BY nama")->fetchAll();
$siswaList = db()->query("SELECT id, nama, email FROM users WHERE role = 'siswa' ORDER BY nama")->fetchAll();

page_start('Manajemen Kelas', count($rows) . ' kelas terjadwal di seluruh kursus.', [
    'actions' => '<button class="btn btn-primary" data-modal-open="modal-kelas">' . icon('plus', 17) . ' Kelas Baru</button>',
]);
?>
<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Kelas</th><th>Kursus</th><th>Pengajar</th><th>Jadwal</th><th>Anggota</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr>
          <td><strong><?= e($c['nama']) ?></strong><div class="muted small">kode <code><?= e($c['kode']) ?></code></div></td>
          <td class="small"><?= e($c['kursus'] ?: '-') ?></td>
          <td class="small"><?= e($c['guru'] ?: '-') ?></td>
          <td class="small"><?= e($c['jadwal'] ?: '-') ?></td>
          <td><?= (int)$c['jml_siswa'] ?>/<?= (int)$c['kapasitas'] ?></td>
          <td><?= status_badge($c['status']) ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="admin-kelas.php?kelas=<?= (int)$c['id'] ?>"><?= icon('users', 14) ?> Anggota</a>
            <a class="btn btn-ghost btn-sm" href="admin-kelas.php?edit=<?= (int)$c['id'] ?>"><?= icon('edit', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus kelas ini?"><?= icon('trash', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted">Belum ada kelas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($detail): ?>
<div class="card">
  <div class="card-head">
    <div><h2 class="mt0 mb0"><?= icon('users', 20) ?> Anggota: <?= e($detail['nama']) ?></h2>
    <div class="muted small"><?= e($detail['kursus'] ?: '-') ?> &middot; <?= e($detail['jadwal'] ?: 'tanpa jadwal') ?></div></div>
    <a class="btn btn-ghost" href="admin-kelas.php">Tutup</a>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="anggota">
    <input type="hidden" name="id" value="<?= (int)$kelasId ?>">
    <div class="form-group">
      <label>Pilih anggota kelas (tahan Ctrl/Cmd untuk memilih banyak)</label>
      <select name="siswa[]" multiple size="12" style="height:auto">
        <?php foreach ($siswaList as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $anggotaIds, true) ? 'selected' : '' ?>><?= e($s['nama']) ?> — <?= e($s['email']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint">Saat ini <?= count($anggota) ?> anggota terdaftar di kelas ini.</div>
    </div>
    <label class="check mb3"><input type="checkbox" name="daftarkan_kursus" value="1" checked> Sekaligus daftarkan anggota baru ke kursus kelas ini</label>
    <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Anggota</button>
  </form>
</div>
<?php endif; ?>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-kelas">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Kelas' : 'Kelas Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-grid">
        <div class="form-group">
          <label>Kursus</label>
          <select name="course_id">
            <option value="">- pilih kursus -</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($edit['course_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Pengajar</label>
          <select name="guru_id">
            <option value="">- pilih guru -</option>
            <?php foreach ($guruList as $g): ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)($edit['guru_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nama']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Nama kelas *</label><input type="text" name="nama" value="<?= e($edit['nama'] ?? '') ?>" required></div>
        <div class="form-group"><label>Kode kelas *</label><input type="text" name="kode" value="<?= e($edit['kode'] ?? '') ?>" required></div>
        <div class="form-group"><label>Jadwal</label><input type="text" name="jadwal" value="<?= e($edit['jadwal'] ?? '') ?>" placeholder="Senin & Rabu, 19:00-20:30"></div>
        <div class="form-group"><label>Kapasitas</label><input type="number" name="kapasitas" value="<?= (int)($edit['kapasitas'] ?? 30) ?>" min="1"></div>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <?php foreach (['aktif' => 'Aktif', 'selesai' => 'Selesai'] as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($edit['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-kelas.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
