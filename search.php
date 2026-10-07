<?php
require_once __DIR__ . '/includes/layout.php';
$u = current_user();
$q = get('q');
$kursus = $materi = $quiz = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $s = db()->prepare('SELECT co.*, c.nama AS kategori FROM courses co LEFT JOIN categories c ON c.id = co.kategori_id WHERE co.status = "aktif" AND (co.judul LIKE ? OR co.deskripsi LIKE ?) LIMIT 12');
    $s->execute([$like, $like]);
    $kursus = $s->fetchAll();

    $s = db()->prepare('SELECT l.*, co.judul AS kursus FROM lessons l JOIN courses co ON co.id = l.course_id WHERE l.judul LIKE ? OR l.isi_teks LIKE ? LIMIT 15');
    $s->execute([$like, $like]);
    $materi = $s->fetchAll();

    $s = db()->prepare('SELECT qz.*, co.judul AS kursus FROM quizzes qz LEFT JOIN courses co ON co.id = qz.course_id WHERE qz.judul LIKE ? OR qz.deskripsi LIKE ? LIMIT 12');
    $s->execute([$like, $like]);
    $quiz = $s->fetchAll();
}
$total = count($kursus) + count($materi) + count($quiz);

page_start('Pencarian', $q !== '' ? 'Hasil untuk "' . $q . '" — ' . $total . ' ditemukan' : 'Cari kursus, materi, atau quiz');
?>
<div class="card">
  <form method="get" class="inline-form">
    <div class="form-group" style="flex:1;min-width:260px">
      <label>Kata kunci</label>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Mis. simple past, TOEFL listening, quiz tenses" autofocus>
    </div>
    <button class="btn btn-primary" type="submit"><?= icon('search', 17) ?> Cari</button>
  </form>
</div>

<?php if ($q === ''): ?>
  <div class="card"><?= empty_state('Masukkan kata kunci untuk mulai mencari materi pembelajaran.', 'search') ?></div>
<?php else: ?>
  <div class="card">
    <div class="card-head"><h2><?= icon('book', 20) ?> Kursus (<?= count($kursus) ?>)</h2></div>
    <?php if (!$kursus): ?><p class="muted small">Tidak ada kursus yang cocok.</p><?php endif; ?>
    <?php foreach ($kursus as $c): ?>
      <div class="lesson-item mb2">
        <span class="lesson-ic"><?= icon('book', 18) ?></span>
        <span class="lesson-meta"><strong><?= e($c['judul']) ?></strong><small><?= e($c['kategori'] ?: 'Umum') ?> &middot; <?= e($c['level']) ?> &middot; <?= (float)$c['harga'] > 0 ? rupiah($c['harga']) : 'Gratis' ?></small></span>
        <span style="margin-left:auto"><a class="btn btn-primary btn-sm" href="kursus-detail.php?id=<?= (int)$c['id'] ?>">Detail</a></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-head"><h2><?= icon('layers', 20) ?> Materi (<?= count($materi) ?>)</h2></div>
    <?php if (!$materi): ?><p class="muted small">Tidak ada materi yang cocok.</p><?php endif; ?>
    <?php foreach ($materi as $m): ?>
      <div class="lesson-item mb2">
        <span class="lesson-ic <?= e($m['tipe']) ?>"><?= icon('file', 18) ?></span>
        <span class="lesson-meta"><strong><?= e($m['judul']) ?></strong><small><?= e($m['kursus']) ?> &middot; <?= e(tipe_materi_label($m['tipe'])) ?></small></span>
        <span style="margin-left:auto"><a class="btn btn-ghost btn-sm" href="<?= $u ? 'materi.php?id=' . (int)$m['id'] : 'kursus-detail.php?id=' . (int)$m['course_id'] ?>">Buka</a></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-head"><h2><?= icon('quiz', 20) ?> Quiz & Ujian (<?= count($quiz) ?>)</h2></div>
    <?php if (!$quiz): ?><p class="muted small">Tidak ada quiz yang cocok.</p><?php endif; ?>
    <?php foreach ($quiz as $z): ?>
      <div class="lesson-item mb2">
        <span class="lesson-ic"><?= icon('quiz', 18) ?></span>
        <span class="lesson-meta"><strong><?= e($z['judul']) ?></strong><small><?= e($z['kursus'] ?? '-') ?> &middot; <?= (int)$z['durasi_menit'] ?> menit</small></span>
        <span style="margin-left:auto"><?= $u && $u['role'] === 'siswa' ? '<a class="btn btn-primary btn-sm" href="ujian.php?quiz=' . (int)$z['id'] . '">Kerjakan</a>' : '' ?></span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_end(); ?>
