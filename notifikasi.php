<?php
/**
 * Kotak notifikasi (semua peran: siswa, guru, admin).
 * Lonceng di topbar mengarah ke halaman ini. Sebelumnya lonceng guru/admin
 * diarahkan ke halaman Pengumuman, padahal angka merahnya berasal dari tabel
 * `notifications` — jadi guru/admin melihat "Belum ada pengumuman" sementara
 * lonceng tetap merah dan tidak ada cara membacanya.
 */
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('aksi') === 'baca') {
        db()->prepare('UPDATE notifications SET dibaca = 1 WHERE id = ? AND user_id = ?')->execute([(int)post('id'), $u['id']]);
        $url = post('url');
        redirect($url !== '' ? $url : 'notifikasi.php');
    }
    if (post('aksi') === 'baca_semua') {
        db()->prepare('UPDATE notifications SET dibaca = 1 WHERE user_id = ?')->execute([$u['id']]);
        flash('Semua notifikasi ditandai sudah dibaca.');
        redirect('notifikasi.php');
    }
    if (post('aksi') === 'hapus_semua') {
        db()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$u['id']]);
        flash('Semua notifikasi dihapus.');
        redirect('notifikasi.php');
    }
}

$rows = db()->query('SELECT * FROM notifications WHERE user_id = ' . (int)$u['id'] . ' ORDER BY dibaca ASC, id DESC LIMIT 100')->fetchAll();
$belum = unread_notifications();

page_start('Notifikasi', $belum > 0 ? $belum . ' notifikasi belum dibaca' : 'Tidak ada notifikasi baru.', [
    'actions' => '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="aksi" value="baca_semua"><button class="btn btn-ghost">' . icon('check', 17) . ' Tandai Semua Dibaca</button></form>'
        . '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="aksi" value="hapus_semua"><button class="btn btn-danger" data-confirm="Hapus semua notifikasi?">' . icon('trash', 17) . ' Hapus Semua</button></form>',
]);
?>
<div class="card">
  <?php if (!$rows): ?><?= empty_state('Belum ada notifikasi untukmu.', 'bell') ?><?php endif; ?>
  <?php foreach ($rows as $n): ?>
    <div class="post" style="<?= (int)$n['dibaca'] === 0 ? 'background:var(--grad-soft);border-radius:14px;padding:15px' : '' ?>">
      <span class="lesson-ic"><?= icon((int)$n['dibaca'] === 0 ? 'bell' : 'check', 18) ?></span>
      <div class="post-body">
        <div class="post-meta"><strong><?= e($n['judul']) ?></strong><span><?= e(tgl_id($n['created_at'], true)) ?></span><?= (int)$n['dibaca'] === 0 ? '<span class="badge badge-warning">Baru</span>' : '' ?></div>
        <p style="margin:0" class="muted"><?= e($n['pesan']) ?></p>
      </div>
      <form method="post" style="align-self:center">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="baca">
        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
        <input type="hidden" name="url" value="<?= e($n['url']) ?>">
        <button class="btn btn-primary btn-sm" type="submit"><?= $n['url'] ? 'Buka' : 'Tandai dibaca' ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php page_end(); ?>
