<?php
require_once __DIR__ . '/includes/layout.php';
$u = current_user();

$q = get('q');
$kat = num('kategori');
$level = get('level');
$urut = get('urut', 'terbaru');
$page = max(1, num('page', 1));

$where = ['co.status = "aktif"'];
$args = [];
if ($q !== '') {
    $where[] = '(co.judul LIKE ? OR co.deskripsi LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}
if ($kat > 0) {
    $where[] = 'co.kategori_id = ?';
    $args[] = $kat;
}
if ($level !== '') {
    $where[] = 'co.level = ?';
    $args[] = $level;
}
$w = implode(' AND ', $where);
$order = 'co.id DESC';
if ($urut === 'judul') {
    $order = 'co.judul ASC';
} elseif ($urut === 'populer') {
    $order = 'jml_siswa DESC';
} elseif ($urut === 'murah') {
    $order = 'co.harga ASC';
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM courses co WHERE $w");
$countStmt->execute($args);
$total = (int)$countStmt->fetchColumn();
$p = paginate($total, 9, $page);

$sql = "SELECT co.*, u.nama AS guru, c.nama AS kategori,
        (SELECT COUNT(*) FROM lessons WHERE course_id = co.id) AS jml_lesson,
        (SELECT COUNT(*) FROM enrollments WHERE course_id = co.id) AS jml_siswa
    FROM courses co
    LEFT JOIN users u ON u.id = co.guru_id
    LEFT JOIN categories c ON c.id = co.kategori_id
    WHERE $w ORDER BY $order LIMIT {$p['per_page']} OFFSET {$p['offset']}";
$stmt = db()->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$kategori = db()->query('SELECT c.*, (SELECT COUNT(*) FROM courses WHERE kategori_id = c.id AND status = "aktif") AS jml FROM categories c ORDER BY c.urutan')->fetchAll();
$diikuti = [];
if ($u) {
    $diikuti = db()->query('SELECT course_id FROM enrollments WHERE user_id = ' . (int)$u['id'])->fetchAll(PDO::FETCH_COLUMN);
}
$levels = ['Pemula', 'Menengah', 'Lanjutan'];

page_start('Katalog Kursus', 'Pilih kursus yang sesuai dengan tujuan belajarmu.');
?>
<div class="card">
  <form class="toolbar" method="get">
    <div class="form-group" style="flex:1;min-width:220px">
      <label>Cari kursus</label>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Mis. grammar, TOEFL, speaking">
    </div>
    <div class="form-group">
      <label>Kategori</label>
      <select name="kategori" data-autosubmit>
        <option value="0">Semua kategori</option>
        <?php foreach ($kategori as $k): ?>
          <option value="<?= (int)$k['id'] ?>" <?= $kat === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['nama']) ?> (<?= (int)$k['jml'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Level</label>
      <select name="level" data-autosubmit>
        <option value="">Semua level</option>
        <?php foreach ($levels as $l): ?>
          <option value="<?= e($l) ?>" <?= $level === $l ? 'selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Urutkan</label>
      <select name="urut" data-autosubmit>
        <?php foreach (['terbaru' => 'Terbaru', 'populer' => 'Terpopuler', 'judul' => 'Judul A-Z', 'murah' => 'Harga termurah'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $urut === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary" type="submit"><?= icon('search', 17) ?> Cari</button>
  </form>
</div>

<?php if (!$rows): ?>
  <div class="card"><?= empty_state('Tidak ada kursus yang cocok dengan pencarianmu.', 'search') ?></div>
<?php else: ?>
<div class="grid cols-3">
  <?php foreach ($rows as $i => $c): ?>
    <article class="course-card">
      <div class="course-thumb t<?= ($i % 4) + 1 ?>">
        <?php if ($c['thumbnail']): ?><img src="<?= e($c['thumbnail']) ?>" alt=""><?php else: ?><?= icon('book', 44, 'th-ic') ?><?php endif; ?>
      </div>
      <div class="course-body">
        <div class="row-gap" style="gap:8px">
          <span class="chip"><?= e($c['kategori'] ?: 'Umum') ?></span>
          <span class="chip"><?= e($c['level']) ?></span>
          <?php if (in_array((int)$c['id'], array_map('intval', $diikuti), true)): ?><span class="badge badge-success">Sudah diikuti</span><?php endif; ?>
        </div>
        <h3><?= e($c['judul']) ?></h3>
        <p class="muted small" style="margin:0"><?= e(mb_strimwidth($c['deskripsi'], 0, 120, '...')) ?></p>
        <div class="course-meta">
          <span><?= icon('user', 15) ?> <?= e($c['guru'] ?: 'Pengajar') ?></span>
          <span><?= icon('layers', 15) ?> <?= (int)$c['jml_lesson'] ?></span>
          <span><?= icon('clock', 15) ?> <?= (int)$c['durasi_jam'] ?> jam</span>
        </div>
        <div class="course-foot">
          <span class="price"><?= (float)$c['harga'] > 0 ? rupiah($c['harga']) : 'Gratis' ?></span>
          <a class="btn btn-primary btn-sm" href="kursus-detail.php?id=<?= (int)$c['id'] ?>">Lihat Detail</a>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?= pager_html($p, 'kursus.php?q=' . urlencode($q) . '&kategori=' . $kat . '&level=' . urlencode($level) . '&urut=' . urlencode($urut)) ?>
<?php endif; ?>
<?php page_end(); ?>
