<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $judul = post('judul');
        $thumb = post('thumbnail_url');
        if (!empty($_FILES['thumbnail']['name'])) {
            try {
                $thumb = handle_upload('thumbnail');
            } catch (Throwable $e) {
                flash('Upload thumbnail gagal: ' . $e->getMessage(), 'danger');
            }
        }
        $args = [$judul, post('deskripsi'), post('kategori_id') ?: null, post('guru_id') ?: null, post('level', 'Pemula'), (float)str_replace(['.', ','], ['', '.'], post('harga', '0')), (int)post('durasi_jam'), post('bahasa', 'Indonesia'), post('status', 'aktif')];
        if ($id > 0) {
            if ($thumb !== '') {
                $args[] = $thumb;
            }
            $args[] = $id;
            if ($thumb !== '') {
                db()->prepare('UPDATE courses SET judul=?, deskripsi=?, kategori_id=?, guru_id=?, level=?, harga=?, durasi_jam=?, bahasa=?, status=?, thumbnail=? WHERE id=?')->execute($args);
            } else {
                db()->prepare('UPDATE courses SET judul=?, deskripsi=?, kategori_id=?, guru_id=?, level=?, harga=?, durasi_jam=?, bahasa=?, status=? WHERE id=?')->execute($args);
            }
            audit('edit_kursus', 'Admin memperbarui kursus ' . $judul);
            flash('Kursus diperbarui.');
        } else {
            $args[] = slugify($judul) . '-' . substr(md5((string)microtime(true)), 0, 4);
            $args[] = $thumb;
            db()->prepare('INSERT INTO courses (judul, deskripsi, kategori_id, guru_id, level, harga, durasi_jam, bahasa, status, slug, thumbnail) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute($args);
            audit('tambah_kursus', 'Admin membuat kursus ' . $judul);
            flash('Kursus baru dibuat.');
        }
    }
    if ($aksi === 'hapus') {
        // hapus seluruh isi kursus + data siswa yang menempel padanya
        lms_hapus_turunan('course', $id);
        audit('hapus_kursus', 'Admin menghapus kursus id ' . $id);
        flash('Kursus dihapus beserta materi, quiz, dan data pendaftarannya.');
    }
    if ($aksi === 'status') {
        db()->prepare('UPDATE courses SET status = ? WHERE id = ?')->execute([post('status'), $id]);
        flash('Status kursus diperbarui.');
    }
    if ($aksi === 'guru') {
        db()->prepare('UPDATE courses SET guru_id = ? WHERE id = ?')->execute([post('guru_id') ?: null, $id]);
        audit('assign_guru', 'Menugaskan guru ke kursus ' . $id);
        flash('Pengajar kursus diperbarui.');
    }
    redirect('admin-kursus.php');
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM courses WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$guruFilter = num('guru');
$katFilter = num('kategori');
$where = ['1=1'];
if ($guruFilter) {
    $where[] = 'co.guru_id = ' . $guruFilter;
}
if ($katFilter) {
    $where[] = 'co.kategori_id = ' . $katFilter;
}
$w = implode(' AND ', $where);

if (get('export') === 'xlsx') {
    $rows = [['Kursus', 'Kategori', 'Pengajar', 'Level', 'Harga', 'Durasi (jam)', 'Materi', 'Quiz', 'Siswa', 'Status']];
    foreach (db()->query("SELECT co.*, c.nama AS kategori, us.nama AS guru, (SELECT COUNT(*) FROM lessons WHERE course_id = co.id) AS m, (SELECT COUNT(*) FROM quizzes WHERE course_id = co.id) AS z, (SELECT COUNT(*) FROM enrollments WHERE course_id = co.id) AS s FROM courses co LEFT JOIN categories c ON c.id = co.kategori_id LEFT JOIN users us ON us.id = co.guru_id WHERE $w")->fetchAll() as $r) {
        $rows[] = [$r['judul'], $r['kategori'], $r['guru'], $r['level'], $r['harga'], $r['durasi_jam'], $r['m'], $r['z'], $r['s'], $r['status']];
    }
    audit('export_kursus', 'Export data kursus');
    xlsx_download('data-kursus-' . date('Ymd') . '.xlsx', $rows);
}

$rows = db()->query("SELECT co.*, c.nama AS kategori, us.nama AS guru,
        (SELECT COUNT(*) FROM lessons WHERE course_id = co.id) AS jml_lesson,
        (SELECT COUNT(*) FROM quizzes WHERE course_id = co.id) AS jml_quiz,
        (SELECT COUNT(*) FROM enrollments WHERE course_id = co.id) AS jml_siswa,
        (SELECT COALESCE(ROUND(AVG(progress),1),0) FROM enrollments WHERE course_id = co.id) AS rata_progress
    FROM courses co LEFT JOIN categories c ON c.id = co.kategori_id LEFT JOIN users us ON us.id = co.guru_id
    WHERE $w ORDER BY co.id DESC")->fetchAll();
$kategori = db()->query('SELECT * FROM categories ORDER BY urutan')->fetchAll();
$guruList = db()->query("SELECT id, nama FROM users WHERE role = 'guru' ORDER BY nama")->fetchAll();
$totalSiswa = array_sum(array_column($rows, 'jml_siswa'));

page_start('Manajemen Kursus', count($rows) . ' kursus terdaftar di sistem.', [
    'actions' => '<a class="btn btn-ghost" href="admin-kursus.php?export=xlsx">' . icon('download', 17) . ' Export Excel</a>'
        . '<button class="btn btn-primary" data-modal-open="modal-kursus">' . icon('plus', 17) . ' Kursus Baru</button>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('book', 22) ?></div><div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lab">Total kursus</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= count(array_filter($rows, function ($r) { return $r['status'] === 'aktif'; })) ?></div><div class="stat-lab">Kursus aktif</div></div></div>
  <div class="stat"><div class="stat-ic g3"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= $totalSiswa ?></div><div class="stat-lab">Total siswa terdaftar</div></div></div>
  <div class="stat"><div class="stat-ic g4"><?= icon('layers', 22) ?></div><div><div class="stat-num"><?= array_sum(array_column($rows, 'jml_lesson')) ?></div><div class="stat-lab">Total materi</div></div></div>
</div>

<div class="card">
  <form class="toolbar">
    <div class="form-group" style="min-width:220px">
      <label>Filter pengajar</label>
      <select name="guru" data-autosubmit>
        <option value="0">Semua pengajar</option>
        <?php foreach ($guruList as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= $guruFilter === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nama']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="min-width:200px">
      <label>Filter kategori</label>
      <select name="kategori" data-autosubmit>
        <option value="0">Semua kategori</option>
        <?php foreach ($kategori as $k): ?>
          <option value="<?= (int)$k['id'] ?>" <?= $katFilter === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['nama']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1;min-width:180px">
      <label>Cari kursus</label>
      <input type="search" data-filter-input="#tbl-kursus" placeholder="Judul kursus...">
    </div>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data" id="tbl-kursus">
      <thead><tr><th>Kursus</th><th>Kategori</th><th>Pengajar</th><th>Materi/Quiz</th><th>Siswa</th><th>Progress</th><th>Harga</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr>
          <td><strong><?= e($c['judul']) ?></strong><div class="muted small"><?= e($c['level']) ?> &middot; <?= (int)$c['durasi_jam'] ?> jam</div></td>
          <td class="small"><?= e($c['kategori'] ?: '-') ?></td>
          <td class="small">
            <form method="post" class="row-gap" style="gap:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="guru">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <select name="guru_id" data-autosubmit style="min-width:150px">
                <option value="">- tanpa pengajar -</option>
                <?php foreach ($guruList as $g): ?>
                  <option value="<?= (int)$g['id'] ?>" <?= (int)$c['guru_id'] === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nama']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td><?= (int)$c['jml_lesson'] ?> / <?= (int)$c['jml_quiz'] ?></td>
          <td><?= (int)$c['jml_siswa'] ?></td>
          <td style="min-width:140px">
            <div class="progress-lab"><span><?= number_format((float)$c['rata_progress'], 1) ?>%</span></div>
            <div class="progress thin"><span style="width:<?= round((float)$c['rata_progress']) ?>%"></span></div>
          </td>
          <td class="nowrap"><?= (float)$c['harga'] > 0 ? rupiah($c['harga']) : 'Gratis' ?></td>
          <td><?= status_badge($c['status']) ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="kursus-detail.php?id=<?= (int)$c['id'] ?>" title="Lihat di situs"><?= icon('grid', 14) ?></a>
            <a class="btn btn-ghost btn-sm" href="admin-kursus.php?edit=<?= (int)$c['id'] ?>"><?= icon('edit', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="status">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="status" value="<?= $c['status'] === 'aktif' ? 'draft' : 'aktif' ?>">
              <button class="btn btn-ghost btn-sm" title="Aktif/Draft"><?= icon($c['status'] === 'aktif' ? 'lock' : 'check', 14) ?></button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus kursus beserta materi & quiznya?"><?= icon('trash', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9" class="muted">Tidak ada kursus.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-kursus">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Kursus' : 'Kursus Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-group"><label>Judul kursus *</label><input type="text" name="judul" value="<?= e($edit['judul'] ?? '') ?>" required></div>
      <div class="form-group"><label>Deskripsi</label><textarea name="deskripsi"><?= e($edit['deskripsi'] ?? '') ?></textarea></div>
      <div class="form-grid">
        <div class="form-group">
          <label>Kategori</label>
          <select name="kategori_id">
            <option value="">- pilih -</option>
            <?php foreach ($kategori as $k): ?>
              <option value="<?= (int)$k['id'] ?>" <?= (int)($edit['kategori_id'] ?? 0) === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['nama']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Pengajar</label>
          <select name="guru_id">
            <option value="">- pilih -</option>
            <?php foreach ($guruList as $g): ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)($edit['guru_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nama']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Level</label>
          <select name="level">
            <?php foreach (['Pemula', 'Menengah', 'Lanjutan'] as $l): ?>
              <option value="<?= $l ?>" <?= ($edit['level'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Harga (Rp)</label><input type="text" name="harga" value="<?= e((string)($edit['harga'] ?? 0)) ?>"></div>
        <div class="form-group"><label>Durasi (jam)</label><input type="number" name="durasi_jam" value="<?= (int)($edit['durasi_jam'] ?? 0) ?>" min="0"></div>
        <div class="form-group"><label>Bahasa</label><input type="text" name="bahasa" value="<?= e($edit['bahasa'] ?? 'Indonesia') ?>"></div>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <?php foreach (['aktif' => 'Aktif', 'draft' => 'Draft', 'nonaktif' => 'Nonaktif'] as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($edit['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Thumbnail (upload)</label><input type="file" name="thumbnail" accept="image/*"></div>
      </div>
      <div class="form-group"><label>atau URL thumbnail</label><input type="url" name="thumbnail_url" value="<?= e($edit['thumbnail'] ?? '') ?>"></div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-kursus.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
