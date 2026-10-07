<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $gambar = post('gambar_url');
        if (!empty($_FILES['gambar']['name'])) {
            try {
                $gambar = handle_upload('gambar');
            } catch (Throwable $e) {
                flash('Upload gambar gagal: ' . $e->getMessage(), 'danger');
            }
        }
        $args = [post('judul'), post('subjudul'), $gambar, post('link'), (int)post('urutan'), post('aktif') ? 1 : 0];
        if ($id > 0) {
            $args[] = $id;
            db()->prepare('UPDATE banners SET judul=?, subjudul=?, gambar=?, link=?, urutan=?, aktif=? WHERE id=?')->execute($args);
            flash('Banner diperbarui.');
        } else {
            db()->prepare('INSERT INTO banners (judul, subjudul, gambar, link, urutan, aktif) VALUES (?,?,?,?,?,?)')->execute($args);
            flash('Banner baru dibuat.');
        }
        audit('simpan_banner', 'Menyimpan banner ' . post('judul'));
    }
    if ($aksi === 'hapus') {
        db()->prepare('DELETE FROM banners WHERE id = ?')->execute([$id]);
        flash('Banner dihapus.');
    }
    if ($aksi === 'toggle') {
        db()->prepare('UPDATE banners SET aktif = 1 - aktif WHERE id = ?')->execute([$id]);
        flash('Status banner diperbarui.');
    }
    redirect('admin-banner.php');
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM banners WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$rows = db()->query('SELECT * FROM banners ORDER BY urutan, id')->fetchAll();

page_start('Manajemen Banner', 'Atur banner promosi yang tampil di halaman beranda.');
?>
<div class="card">
  <div class="card-head">
    <h2 class="mt0"><?= icon('image', 20) ?> Daftar Banner</h2>
    <button class="btn btn-primary" data-modal-open="modal-banner"><?= icon('plus', 17) ?> Banner Baru</button>
  </div>
  <div class="grid cols-2">
    <?php foreach ($rows as $b): ?>
      <div class="card tight">
        <div class="course-thumb t2 mb2" style="height:130px;border-radius:12px">
          <?php if ($b['gambar']): ?><img src="<?= e($b['gambar']) ?>" alt=""><?php else: ?><?= icon('image', 36, 'th-ic') ?><?php endif; ?>
        </div>
        <div class="row-between mb2">
          <strong><?= e($b['judul']) ?></strong>
          <?= $b['aktif'] ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-muted">Nonaktif</span>' ?>
        </div>
        <p class="muted small"><?= e($b['subjudul']) ?></p>
        <div class="muted small mb2">Link: <code><?= e($b['link'] ?: '-') ?></code> &middot; urutan <?= (int)$b['urutan'] ?></div>
        <div class="row-gap">
          <a class="btn btn-ghost btn-sm" href="admin-banner.php?edit=<?= (int)$b['id'] ?>"><?= icon('edit', 14) ?></a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button class="btn btn-ghost btn-sm"><?= icon($b['aktif'] ? 'lock' : 'check', 14) ?> <?= $b['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
          </form>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="hapus">
            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button class="btn btn-danger btn-sm" data-confirm="Hapus banner ini?"><?= icon('trash', 14) ?></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="card"><?= empty_state('Belum ada banner.', 'image') ?></div><?php endif; ?>
  </div>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-banner">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Banner' : 'Banner Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-group"><label>Judul *</label><input type="text" name="judul" value="<?= e($edit['judul'] ?? '') ?>" required></div>
      <div class="form-group"><label>Subjudul</label><input type="text" name="subjudul" value="<?= e($edit['subjudul'] ?? '') ?>"></div>
      <div class="form-grid">
        <div class="form-group"><label>Gambar (upload)</label><input type="file" name="gambar" accept="image/*"></div>
        <div class="form-group"><label>atau URL gambar</label><input type="url" name="gambar_url" value="<?= e($edit['gambar'] ?? '') ?>"></div>
        <div class="form-group"><label>Link tujuan</label><input type="text" name="link" value="<?= e($edit['link'] ?? '') ?>" placeholder="kursus.php?kategori=4"></div>
        <div class="form-group"><label>Urutan</label><input type="number" name="urutan" value="<?= (int)($edit['urutan'] ?? 0) ?>"></div>
      </div>
      <label class="check mb3"><input type="checkbox" name="aktif" value="1" <?= (int)($edit['aktif'] ?? 1) ? 'checked' : '' ?>> Tampilkan banner ini</label>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-banner.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
