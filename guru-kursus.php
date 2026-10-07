<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login(['guru', 'admin']);
$scope = $u['role'] === 'admin' ? '1=1' : ('co.guru_id = ' . (int)$u['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $judul = post('judul');
        $slug = slugify($judul) . '-' . substr(md5((string)microtime(true)), 0, 4);
        $data = [
            $judul,
            post('deskripsi'),
            post('kategori_id') ?: null,
            $u['role'] === 'admin' ? (post('guru_id') ?: null) : $u['id'],
            post('level', 'Pemula'),
            (float)str_replace(['.', ','], ['', '.'], post('harga', '0')),
            (int)post('durasi_jam'),
            post('bahasa', 'Indonesia'),
            post('status', 'aktif'),
        ];
        $thumb = post('thumbnail_url');
        if (!empty($_FILES['thumbnail']['name'])) {
            try {
                $thumb = handle_upload('thumbnail');
            } catch (Throwable $e) {
                flash('Upload thumbnail gagal: ' . $e->getMessage(), 'danger');
            }
        }
        if ($id > 0) {
            if ($thumb !== '') {
                $data[] = $thumb;
                db()->prepare('UPDATE courses SET judul=?, deskripsi=?, kategori_id=?, guru_id=?, level=?, harga=?, durasi_jam=?, bahasa=?, status=?, thumbnail=? WHERE id=?')
                    ->execute(array_merge($data, [$id]));
            } else {
                db()->prepare('UPDATE courses SET judul=?, deskripsi=?, kategori_id=?, guru_id=?, level=?, harga=?, durasi_jam=?, bahasa=?, status=? WHERE id=?')
                    ->execute(array_merge($data, [$id]));
            }
            audit('edit_kursus', 'Memperbarui kursus ' . $judul);
            flash('Kursus berhasil diperbarui.');
        } else {
            $data[] = $slug;
            $data[] = $thumb;
            db()->prepare('INSERT INTO courses (judul, deskripsi, kategori_id, guru_id, level, harga, durasi_jam, bahasa, status, slug, thumbnail) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute($data);
            audit('tambah_kursus', 'Membuat kursus ' . $judul);
            flash('Kursus baru berhasil dibuat.');
        }
        redirect('guru-kursus.php');
    }
    if ($aksi === 'hapus') {
        $cek = db()->prepare('SELECT * FROM courses WHERE id = ?');
        $cek->execute([$id]);
        $c = $cek->fetch();
        if ($c && ($u['role'] === 'admin' || (int)$c['guru_id'] === (int)$u['id'])) {
            lms_hapus_turunan('course', $id);
            audit('hapus_kursus', 'Menghapus kursus ' . $c['judul']);
            flash('Kursus dihapus beserta materi, quiz, dan data pendaftarannya.');
        }
        redirect('guru-kursus.php');
    }
    if ($aksi === 'status') {
        db()->prepare('UPDATE courses SET status = ? WHERE id = ?')->execute([post('status'), $id]);
        flash('Status kursus diperbarui.');
        redirect('guru-kursus.php');
    }
}

$edit = null;
if (num('edit') > 0) {
    $stmt = db()->prepare("SELECT co.* FROM courses co WHERE co.id = ? AND $scope");
    $stmt->execute([num('edit')]);
    $edit = $stmt->fetch();
}
$rows = db()->query("SELECT co.*, c.nama AS kategori, us.nama AS guru,
        (SELECT COUNT(*) FROM lessons WHERE course_id = co.id) AS jml_lesson,
        (SELECT COUNT(*) FROM quizzes WHERE course_id = co.id) AS jml_quiz,
        (SELECT COUNT(*) FROM enrollments WHERE course_id = co.id) AS jml_siswa
    FROM courses co LEFT JOIN categories c ON c.id = co.kategori_id LEFT JOIN users us ON us.id = co.guru_id
    WHERE $scope ORDER BY co.id DESC")->fetchAll();
$kategori = db()->query('SELECT * FROM categories ORDER BY urutan')->fetchAll();
$daftarGuru = $u['role'] === 'admin' ? db()->query("SELECT id, nama FROM users WHERE role = 'guru' ORDER BY nama")->fetchAll() : [];

page_start('Kelola Kursus', 'Buat, ubah, dan atur status kursus yang kamu ampu.', [
    'actions' => '<button class="btn btn-primary" data-modal-open="modal-kursus">' . icon('plus', 17) . ' Kursus Baru</button>',
]);
?>
<div class="card">
  <form class="toolbar">
    <div class="form-group" style="flex:1;min-width:220px">
      <label>Cari kursus</label>
      <input type="search" data-filter-input="#tbl-kursus" placeholder="Ketik judul kursus...">
    </div>
  </form>
  <div class="table-wrap">
    <table class="data" id="tbl-kursus">
      <thead><tr><th>Kursus</th><th>Kategori</th><th>Pengajar</th><th>Materi</th><th>Quiz</th><th>Siswa</th><th>Harga</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr>
          <td><strong><?= e($c['judul']) ?></strong><div class="muted small"><?= e($c['level']) ?> &middot; <?= (int)$c['durasi_jam'] ?> jam</div></td>
          <td><?= e($c['kategori'] ?: '-') ?></td>
          <td class="small"><?= e($c['guru'] ?: '-') ?></td>
          <td><?= (int)$c['jml_lesson'] ?></td>
          <td><?= (int)$c['jml_quiz'] ?></td>
          <td><?= (int)$c['jml_siswa'] ?></td>
          <td class="nowrap"><?= (float)$c['harga'] > 0 ? rupiah($c['harga']) : 'Gratis' ?></td>
          <td><?= status_badge($c['status']) ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="guru-kursus.php?edit=<?= (int)$c['id'] ?>"><?= icon('edit', 14) ?></a>
            <a class="btn btn-ghost btn-sm" href="guru-materi.php?kursus=<?= (int)$c['id'] ?>"><?= icon('layers', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus kursus ini beserta materi & quiznya?"><?= icon('trash', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9" class="muted">Belum ada kursus.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-kursus">
  <div class="modal">
    <div class="modal-head">
      <h3 class="mt0"><?= $edit ? 'Ubah Kursus' : 'Kursus Baru' ?></h3>
      <button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-group">
        <label>Judul kursus *</label>
        <input type="text" name="judul" value="<?= e($edit['judul'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Deskripsi</label>
        <textarea name="deskripsi"><?= e($edit['deskripsi'] ?? '') ?></textarea>
      </div>
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
          <label>Level</label>
          <select name="level">
            <?php foreach (['Pemula', 'Menengah', 'Lanjutan'] as $l): ?>
              <option value="<?= $l ?>" <?= ($edit['level'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Harga (Rp)</label>
          <input type="text" name="harga" value="<?= e((string)($edit['harga'] ?? 0)) ?>">
          <div class="form-hint">Isi 0 untuk kursus gratis.</div>
        </div>
        <div class="form-group">
          <label>Durasi (jam)</label>
          <input type="number" name="durasi_jam" value="<?= (int)($edit['durasi_jam'] ?? 0) ?>" min="0">
        </div>
        <div class="form-group">
          <label>Bahasa pengantar</label>
          <input type="text" name="bahasa" value="<?= e($edit['bahasa'] ?? 'Indonesia') ?>">
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="aktif" <?= ($edit['status'] ?? '') === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="draft" <?= ($edit['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="nonaktif" <?= ($edit['status'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
          </select>
        </div>
      </div>
      <?php if ($u['role'] === 'admin'): ?>
        <div class="form-group">
          <label>Pengajar</label>
          <select name="guru_id">
            <option value="">- pilih guru -</option>
            <?php foreach ($daftarGuru as $g): ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)($edit['guru_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nama']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="form-grid">
        <div class="form-group">
          <label>Thumbnail (upload gambar)</label>
          <input type="file" name="thumbnail" accept="image/*">
        </div>
        <div class="form-group">
          <label>atau URL thumbnail</label>
          <input type="url" name="thumbnail_url" value="<?= e($edit['thumbnail'] ?? '') ?>" placeholder="https://...">
        </div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="guru-kursus.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
