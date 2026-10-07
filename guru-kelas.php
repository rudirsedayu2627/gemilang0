<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login(['guru', 'admin']);
$scope = $u['role'] === 'admin' ? '1=1' : ('co.guru_id = ' . (int)$u['id']);

$courses = db()->query("SELECT id, judul FROM courses co WHERE $scope ORDER BY judul")->fetchAll();
$allowed = array_map('intval', array_column($courses, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan' && in_array((int)post('course_id'), $allowed, true)) {
        $args = [post('course_id'), $u['role'] === 'admin' ? (post('guru_id') ?: null) : $u['id'], post('nama'), strtoupper(post('kode')), post('jadwal'), (int)post('kapasitas'), post('status', 'aktif')];
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
        flash('Kelas dihapus.');
    }
    if ($aksi === 'tambah_siswa') {
        $cid = (int)post('class_id');
        foreach ((array)post('siswa', []) as $sid) {
            db()->prepare('INSERT OR IGNORE INTO class_students (class_id, user_id) VALUES (?, ?)')->execute([$cid, (int)$sid]);
            $cls = db()->query('SELECT course_id, nama FROM classes WHERE id = ' . $cid)->fetch();
            if ($cls && $cls['course_id']) {
                db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id, status) VALUES (?, ?, "aktif")')->execute([(int)$sid, (int)$cls['course_id']]);
                notify($sid, 'Kamu ditambahkan ke kelas', 'Kamu terdaftar pada kelas ' . $cls['nama'] . '.', 'siswa-kalender.php');
            }
        }
        flash('Siswa ditambahkan ke kelas & otomatis terdaftar pada kursusnya.');
    }
    if ($aksi === 'keluarkan') {
        db()->prepare('DELETE FROM class_students WHERE class_id = ? AND user_id = ?')->execute([(int)post('class_id'), (int)post('user_id')]);
        flash('Siswa dikeluarkan dari kelas.');
    }
    redirect('guru-kelas.php');
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM classes WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$rows = db()->query("SELECT c.*, co.judul AS kursus, us.nama AS guru,
        (SELECT COUNT(*) FROM class_students WHERE class_id = c.id) AS jml_siswa
    FROM classes c LEFT JOIN courses co ON co.id = c.course_id LEFT JOIN users us ON us.id = c.guru_id
    WHERE " . ($u['role'] === 'admin' ? '1=1' : ('co.guru_id = ' . (int)$u['id'])) . ' ORDER BY c.id DESC')->fetchAll();
$detail = null;
$anggota = [];
$kandidat = [];
if (num('kelas') > 0) {
    $detail = db()->query('SELECT c.*, co.judul AS kursus FROM classes c LEFT JOIN courses co ON co.id = c.course_id WHERE c.id = ' . num('kelas'))->fetch();
    if ($detail) {
        $anggota = db()->query('SELECT us.*, ' . ($detail['course_id'] ? '(SELECT progress FROM enrollments WHERE user_id = us.id AND course_id = ' . (int)$detail['course_id'] . ')' : '0') . ' AS progress
            FROM class_students cs JOIN users us ON us.id = cs.user_id WHERE cs.class_id = ' . (int)$detail['id'] . ' ORDER BY us.nama')->fetchAll();
        $kandidat = db()->query('SELECT id, nama, email FROM users WHERE role = "siswa" AND status = "aktif"
            AND id NOT IN (SELECT user_id FROM class_students WHERE class_id = ' . (int)$detail['id'] . ') ORDER BY nama')->fetchAll();
    }
}
$guruList = $u['role'] === 'admin' ? db()->query("SELECT id, nama FROM users WHERE role = 'guru' ORDER BY nama")->fetchAll() : [];

page_start('Kelola Kelas', 'Atur kelas, jadwal, dan anggota siswa.', [
    'actions' => '<button class="btn btn-primary" data-modal-open="modal-kelas">' . icon('plus', 17) . ' Kelas Baru</button>',
]);
?>
<div class="grid cols-2 mb3">
  <?php foreach ($rows as $c): ?>
    <div class="card tight">
      <div class="row-between mb2">
        <div>
          <h3 class="mt0 mb0"><?= e($c['nama']) ?></h3>
          <div class="muted small"><?= e($c['kursus'] ?: '-') ?> &middot; kode <code><?= e($c['kode']) ?></code></div>
        </div>
        <?= status_badge($c['status']) ?>
      </div>
      <div class="muted small mb2"><?= icon('calendar', 13) ?> <?= e($c['jadwal'] ?: 'Jadwal belum diatur') ?></div>
      <div class="progress-lab"><span>Kapasitas</span><strong><?= (int)$c['jml_siswa'] ?>/<?= (int)$c['kapasitas'] ?></strong></div>
      <div class="progress"><span style="width:<?= (int)$c['kapasitas'] ? min(100, round((int)$c['jml_siswa'] / (int)$c['kapasitas'] * 100)) : 0 ?>%"></span></div>
      <div class="row-gap mt3">
        <a class="btn btn-primary btn-sm" href="guru-kelas.php?kelas=<?= (int)$c['id'] ?>"><?= icon('users', 15) ?> Anggota</a>
        <a class="btn btn-ghost btn-sm" href="guru-kelas.php?edit=<?= (int)$c['id'] ?>"><?= icon('edit', 15) ?></a>
        <a class="btn btn-ghost btn-sm" href="guru-absensi.php?kelas=<?= (int)$c['id'] ?>"><?= icon('check', 15) ?> Absensi</a>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="hapus">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-danger btn-sm" data-confirm="Hapus kelas ini?"><?= icon('trash', 15) ?></button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="card"><?= empty_state('Belum ada kelas. Buat kelas untuk mengelompokkan siswa dan jadwal.', 'grid') ?></div><?php endif; ?>
</div>

<?php if ($detail): ?>
<div class="card">
  <div class="card-head">
    <div><h2 class="mt0 mb0"><?= icon('users', 20) ?> Anggota: <?= e($detail['nama']) ?></h2>
    <div class="muted small"><?= count($anggota) ?> siswa &middot; <?= e($detail['jadwal'] ?: 'jadwal belum diatur') ?></div></div>
    <button class="btn btn-primary" data-modal-open="modal-anggota"><?= icon('plus', 17) ?> Tambah Siswa</button>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Siswa</th><th>Email</th><th>Progress Kursus</th><th>Terdaftar</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($anggota as $a): ?>
        <tr>
          <td><div class="tbl-student"><span class="avatar sm" <?= $a['foto'] ? 'style="background-image:url(' . e($a['foto']) . ')"' : '' ?>><?= $a['foto'] ? '' : e(strtoupper(mb_substr($a['nama'], 0, 1))) ?></span> <?= e($a['nama']) ?></div></td>
          <td class="small"><?= e($a['email']) ?></td>
          <td style="min-width:160px">
            <div class="progress-lab"><span><?= $a['progress'] !== null ? round((float)$a['progress']) . '%' : 'belum terdaftar' ?></span></div>
            <div class="progress thin"><span style="width:<?= round((float)$a['progress']) ?>%"></span></div>
          </td>
          <td class="small"><?= e(tgl_id($a['created_at'])) ?></td>
          <td>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="keluarkan">
              <input type="hidden" name="class_id" value="<?= (int)$detail['id'] ?>">
              <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Keluarkan siswa dari kelas?"><?= icon('x', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$anggota): ?><tr><td colspan="5" class="muted">Belum ada anggota kelas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="modal-anggota">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0">Tambah Siswa ke <?= e($detail['nama']) ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="tambah_siswa">
      <input type="hidden" name="class_id" value="<?= (int)$detail['id'] ?>">
      <div class="form-group">
        <label>Pilih siswa (bisa lebih dari satu)</label>
        <select name="siswa[]" multiple size="10" style="height:auto">
          <?php foreach ($kandidat as $k): ?>
            <option value="<?= (int)$k['id'] ?>"><?= e($k['nama']) ?> — <?= e($k['email']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Tahan Ctrl/Cmd untuk memilih beberapa siswa.</div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('plus', 17) ?> Tambahkan</button>
        <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-kelas">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Kelas' : 'Kelas Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-group">
        <label>Kursus *</label>
        <select name="course_id" required>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)($edit['course_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid">
        <div class="form-group"><label>Nama kelas *</label><input type="text" name="nama" value="<?= e($edit['nama'] ?? '') ?>" required></div>
        <div class="form-group"><label>Kode kelas *</label><input type="text" name="kode" value="<?= e($edit['kode'] ?? '') ?>" placeholder="GLC-GRM-02" required></div>
      </div>
      <div class="form-grid">
        <div class="form-group"><label>Jadwal</label><input type="text" name="jadwal" value="<?= e($edit['jadwal'] ?? '') ?>" placeholder="Senin & Rabu, 19:00-20:30"></div>
        <div class="form-group"><label>Kapasitas</label><input type="number" name="kapasitas" value="<?= (int)($edit['kapasitas'] ?? 30) ?>" min="1"></div>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="aktif" <?= ($edit['status'] ?? '') === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="selesai" <?= ($edit['status'] ?? '') === 'selesai' ? 'selected' : '' ?>>Selesai</option>
          </select>
        </div>
        <?php if ($u['role'] === 'admin'): ?>
          <div class="form-group">
            <label>Pengajar</label>
            <select name="guru_id">
              <?php foreach ($guruList as $g): ?>
                <option value="<?= (int)$g['id'] ?>" <?= (int)($edit['guru_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nama']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Kelas</button>
        <a class="btn btn-ghost" href="guru-kelas.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
