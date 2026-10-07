<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $nama = post('nama');
        $ikon = post('ikon', 'book');
        $warna = post('warna', '#4F46E5');
        $slug = slugify(post('slug') ?: $nama);
        $args = [$nama, $slug, post('deskripsi'), $ikon, $warna, (int)post('urutan')];
        if ($id > 0) {
            $args[] = $id;
            db()->prepare('UPDATE categories SET nama=?, slug=?, deskripsi=?, ikon=?, warna=?, urutan=? WHERE id=?')->execute($args);
            flash('Kategori diperbarui.');
        } else {
            db()->prepare('INSERT INTO categories (nama, slug, deskripsi, ikon, warna, urutan) VALUES (?,?,?,?,?,?)')->execute($args);
            flash('Kategori baru dibuat.');
        }
        audit('simpan_kategori', 'Menyimpan kategori ' . $nama);
    }
    if ($aksi === 'hapus') {
        db()->prepare('UPDATE courses SET kategori_id = NULL WHERE kategori_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        audit('hapus_kategori', 'Menghapus kategori id ' . $id);
        flash('Kategori dihapus. Kursus terkait menjadi tanpa kategori.');
    }
    redirect('admin-kategori.php');
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$rows = db()->query('SELECT c.*,
        (SELECT COUNT(*) FROM courses WHERE kategori_id = c.id) AS jml_kursus,
        (SELECT COUNT(*) FROM courses WHERE kategori_id = c.id AND status = "aktif") AS jml_aktif,
        (SELECT COUNT(*) FROM enrollments WHERE course_id IN (SELECT id FROM courses WHERE kategori_id = c.id)) AS jml_siswa
    FROM categories c ORDER BY c.urutan, c.nama')->fetchAll();
$ikonTersedia = ['book', 'mic', 'audio', 'trophy', 'smile', 'briefcase', 'grid', 'layers', 'chat', 'star', 'chart', 'folder', 'video', 'pdf', 'users', 'award'];

page_start('Manajemen Kategori', 'Kelompokkan kursus agar siswa mudah menemukan kelas yang sesuai.', [
    'actions' => '<button class="btn btn-primary" data-modal-open="modal-kategori">' . icon('plus', 17) . ' Kategori Baru</button>',
]);
?>
<div class="grid cols-3 mb3">
  <?php foreach ($rows as $c): ?>
    <div class="card tight">
      <div class="row-gap mb2">
        <div class="stat-ic" style="background:<?= e($c['warna'] ?: '#4F46E5') ?>"><?= icon($c['ikon'] ?: 'book', 20) ?></div>
        <div><h3 class="mt0 mb0" style="font-size:1rem"><?= e($c['nama']) ?></h3><div class="muted small">slug: <?= e($c['slug']) ?></div></div>
      </div>
      <p class="muted small"><?= e($c['deskripsi'] ?: 'Tanpa deskripsi') ?></p>
      <div class="row-gap mb2">
        <span class="chip"><?= (int)$c['jml_aktif'] ?> aktif / <?= (int)$c['jml_kursus'] ?> kursus</span>
        <span class="chip"><?= (int)$c['jml_siswa'] ?> siswa</span>
      </div>
      <div class="row-gap">
        <a class="btn btn-primary btn-sm" href="kursus.php?kategori=<?= (int)$c['id'] ?>"><?= icon('grid', 14) ?> Lihat</a>
        <a class="btn btn-ghost btn-sm" href="admin-kategori.php?edit=<?= (int)$c['id'] ?>"><?= icon('edit', 14) ?></a>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="hapus">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-danger btn-sm" data-confirm="Hapus kategori ini?"><?= icon('trash', 14) ?></button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="card"><?= empty_state('Belum ada kategori.', 'folder') ?></div><?php endif; ?>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-kategori">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Kategori' : 'Kategori Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-group"><label>Nama kategori *</label><input type="text" name="nama" value="<?= e($edit['nama'] ?? '') ?>" required></div>
      <div class="form-group"><label>Slug (opsional)</label><input type="text" name="slug" value="<?= e($edit['slug'] ?? '') ?>" placeholder="otomatis dari nama"></div>
      <div class="form-group"><label>Deskripsi</label><textarea name="deskripsi"><?= e($edit['deskripsi'] ?? '') ?></textarea></div>
      <div class="form-grid">
        <div class="form-group">
          <label>Ikon</label>
          <select name="ikon">
            <?php foreach ($ikonTersedia as $ik): ?>
              <option value="<?= $ik ?>" <?= ($edit['ikon'] ?? '') === $ik ? 'selected' : '' ?>><?= $ik ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Warna</label><input type="text" name="warna" value="<?= e($edit['warna'] ?? '#4F46E5') ?>" placeholder="#4F46E5"></div>
        <div class="form-group"><label>Urutan tampil</label><input type="number" name="urutan" value="<?= (int)($edit['urutan'] ?? 0) ?>"></div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-kategori.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
