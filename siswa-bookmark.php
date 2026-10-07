<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('aksi') === 'hapus') {
    csrf_check();
    db()->prepare('DELETE FROM bookmarks WHERE id = ? AND user_id = ?')->execute([(int)post('id'), $u['id']]);
    flash('Bookmark dihapus.');
    redirect('siswa-bookmark.php');
}

$rows = db()->query('SELECT b.id AS bid, b.created_at AS ditandai, l.*, co.judul AS kursus, co.id AS course_id, m.judul AS modul,
        COALESCE(lp.selesai, 0) AS selesai
    FROM bookmarks b JOIN lessons l ON l.id = b.lesson_id JOIN courses co ON co.id = l.course_id
    LEFT JOIN modules m ON m.id = l.module_id
    LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = ' . (int)$u['id'] . '
    WHERE b.user_id = ' . (int)$u['id'] . ' ORDER BY b.created_at DESC')->fetchAll();

page_start('Bookmark Materi', count($rows) . ' materi kamu simpan untuk dibuka kembali nanti.');
?>
<a class="btn btn-ghost mb3" href="siswa-kursus.php"><?= icon('arrow-left', 17) ?> Kembali ke Kursus Saya</a>

<?php if (!$rows): ?>
  <div class="card">
    <?= empty_state('Belum ada materi yang di-bookmark. Buka materi lalu klik tombol Bookmark.', 'bookmark') ?>
    <div class="center"><a class="btn btn-primary" href="kursus.php">Jelajahi Kursus</a></div>
  </div>
<?php endif; ?>

<div class="grid cols-2">
<?php foreach ($rows as $r): ?>
  <div class="card tight">
    <div class="row-gap mb2" style="justify-content:space-between">
      <span class="lesson-ic <?= e($r['tipe']) ?>"><?= icon(tipe_materi_ikon($r['tipe']), 18) ?></span>
      <div class="row-gap">
        <?php if ((int)$r['selesai']): ?><span class="badge badge-success">Selesai</span><?php endif; ?>
        <span class="chip"><?= e(tipe_materi_label($r['tipe'])) ?></span>
      </div>
    </div>
    <strong><?= e($r['judul']) ?></strong>
    <div class="muted small mb2"><?= e($r['kursus']) ?><?= $r['modul'] ? ' &middot; ' . e($r['modul']) : '' ?> &middot; ditandai <?= e(tgl_id($r['ditandai'])) ?></div>
    <div class="row-gap">
      <a class="btn btn-primary btn-sm" href="materi.php?id=<?= (int)$r['id'] ?>"><?= icon('play', 15) ?> Buka Materi</a>
      <?php if ($r['media_url']): ?><a class="btn btn-ghost btn-sm" href="<?= e($r['media_url']) ?>" target="_blank" rel="noopener"><?= icon('download', 15) ?> Unduh</a><?php endif; ?>
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="hapus">
        <input type="hidden" name="id" value="<?= (int)$r['bid'] ?>">
        <button class="btn btn-danger btn-sm" data-confirm="Hapus bookmark ini?"><?= icon('trash', 15) ?></button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php page_end(); ?>
