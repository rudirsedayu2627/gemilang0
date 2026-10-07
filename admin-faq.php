<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $args = [post('pertanyaan'), post('jawaban'), post('kategori', 'Umum'), (int)post('urutan')];
        if ($id > 0) {
            $args[] = $id;
            db()->prepare('UPDATE faqs SET pertanyaan=?, jawaban=?, kategori=?, urutan=? WHERE id=?')->execute($args);
            flash('FAQ diperbarui.');
        } else {
            db()->prepare('INSERT INTO faqs (pertanyaan, jawaban, kategori, urutan) VALUES (?,?,?,?)')->execute($args);
            flash('FAQ baru ditambahkan.');
        }
        audit('simpan_faq', 'Menyimpan FAQ');
    }
    if ($aksi === 'hapus') {
        db()->prepare('DELETE FROM faqs WHERE id = ?')->execute([$id]);
        flash('FAQ dihapus.');
    }
    redirect('admin-faq.php');
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM faqs WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$rows = db()->query('SELECT * FROM faqs ORDER BY kategori, urutan, id')->fetchAll();
$kategori = [];
foreach ($rows as $r) {
    $kategori[$r['kategori'] ?: 'Umum'][] = $r;
}

page_start('Manajemen FAQ', count($rows) . ' pertanyaan tersimpan.', [
    'actions' => '<button class="btn btn-primary" data-modal-open="modal-faq">' . icon('plus', 17) . ' FAQ Baru</button>',
]);
?>
<div class="grid cols-2">
  <?php foreach ($kategori as $kat => $items): ?>
    <div class="card">
      <div class="card-head"><h3 class="mt0"><?= icon('chat', 18) ?> <?= e($kat) ?></h3><span class="chip"><?= count($items) ?> item</span></div>
      <?php foreach ($items as $f): ?>
        <div class="mb3" style="border-bottom:1px solid var(--border-soft);padding-bottom:12px">
          <div class="row-between mb1">
            <strong class="small"><?= e($f['pertanyaan']) ?></strong>
            <div class="row-gap">
              <a class="btn btn-ghost btn-sm" href="admin-faq.php?edit=<?= (int)$f['id'] ?>"><?= icon('edit', 13) ?></a>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="hapus">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button class="btn btn-danger btn-sm" data-confirm="Hapus FAQ ini?"><?= icon('trash', 13) ?></button>
              </form>
            </div>
          </div>
          <p class="muted small mb0"><?= nl2br(e($f['jawaban'])) ?></p>
          <div class="muted small mt1">urutan <?= (int)$f['urutan'] ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="card"><?= empty_state('Belum ada FAQ.', 'chat') ?></div><?php endif; ?>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-faq">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah FAQ' : 'FAQ Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-group"><label>Pertanyaan *</label><input type="text" name="pertanyaan" value="<?= e($edit['pertanyaan'] ?? '') ?>" required></div>
      <div class="form-group"><label>Jawaban *</label><textarea name="jawaban" style="min-height:130px" required><?= e($edit['jawaban'] ?? '') ?></textarea></div>
      <div class="form-grid">
        <div class="form-group"><label>Kategori</label><input type="text" name="kategori" value="<?= e($edit['kategori'] ?? 'Umum') ?>" placeholder="Pendaftaran, Sertifikat, dll."></div>
        <div class="form-group"><label>Urutan</label><input type="number" name="urutan" value="<?= (int)($edit['urutan'] ?? 0) ?>"></div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-faq.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
