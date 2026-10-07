<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$id = num('id');
$topic = db()->query('SELECT t.*, us.nama, us.role, us.foto, co.judul AS kursus, u2.nama AS guru FROM forum_topics t
    LEFT JOIN users us ON us.id = t.user_id LEFT JOIN courses co ON co.id = t.course_id LEFT JOIN users u2 ON u2.id = co.guru_id WHERE t.id = ' . $id)->fetch();
if (!$topic) {
    http_response_code(404);
    page_start('Topik tidak ditemukan');
    echo '<div class="card">' . empty_state('Topik diskusi tidak ditemukan.', 'chat') . '</div>';
    page_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('aksi') === 'balas' && !(int)$topic['closed']) {
        $isi = post('isi');
        $bolehBalas = in_array($u['role'], ['guru', 'admin'], true)
            || (int)$topic['course_id'] === 0
            || cek_akses_kursus((int)$u['id'], (int)$topic['course_id'])['boleh'];
        if (!$bolehBalas) {
            flash('Akses kursus ini belum terbuka, jadi belum bisa ikut berdiskusi.', 'warning');
            redirect('siswa-pembayaran.php?kursus=' . (int)$topic['course_id']);
        }
        if ($isi !== '') {
            db()->prepare('INSERT INTO forum_replies (topic_id, user_id, isi) VALUES (?, ?, ?)')->execute([$id, $u['id'], $isi]);
            if ((int)$topic['user_id'] !== (int)$u['id']) {
                notify($topic['user_id'], 'Balasan baru di topikmu', $u['nama'] . ': ' . mb_strimwidth($isi, 0, 90, '...'), 'forum-topik.php?id=' . $id);
            }
            foreach (db()->query('SELECT user_id FROM forum_replies WHERE topic_id = ' . $id . ' GROUP BY user_id')->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                if ((int)$uid !== (int)$u['id'] && (int)$uid !== (int)$topic['user_id']) {
                    notify($uid, 'Balasan baru di topik ' . $topic['judul'], $u['nama'] . ': ' . mb_strimwidth($isi, 0, 90, '...'), 'forum-topik.php?id=' . $id);
                }
            }
            audit('balas_topik', 'Membalas topik ' . $topic['judul']);
            flash('Balasan terkirim.');
        }
        redirect('forum-topik.php?id=' . $id . '#balasan');
    }
    if (in_array(post('aksi'), ['pin', 'tutup', 'hapus'], true) && in_array($u['role'], ['guru', 'admin'], true)) {
        if (post('aksi') === 'pin') {
            db()->prepare('UPDATE forum_topics SET pinned = 1 - pinned WHERE id = ?')->execute([$id]);
        } elseif (post('aksi') === 'tutup') {
            db()->prepare('UPDATE forum_topics SET closed = 1 - closed WHERE id = ?')->execute([$id]);
        } else {
            db()->prepare('DELETE FROM forum_replies WHERE topic_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM forum_topics WHERE id = ?')->execute([$id]);
            audit('hapus_topik', 'Menghapus topik ' . $topic['judul']);
            flash('Topik dihapus.');
            redirect('siswa-forum.php');
        }
        audit('moderasi_topik', post('aksi') . ' topik ' . $topic['judul']);
        redirect('forum-topik.php?id=' . $id);
    }
    if (post('aksi') === 'hapus_balasan' && in_array($u['role'], ['guru', 'admin'], true)) {
        db()->prepare('DELETE FROM forum_replies WHERE id = ?')->execute([(int)post('reply_id')]);
        flash('Balasan dihapus.');
        redirect('forum-topik.php?id=' . $id);
    }
}

$replies = db()->query('SELECT r.*, us.nama, us.role, us.foto FROM forum_replies r LEFT JOIN users us ON us.id = r.user_id WHERE r.topic_id = ' . $id . ' ORDER BY r.created_at ASC')->fetchAll();

page_start($topic['judul'], ($topic['kursus'] ?: 'Umum') . ' · ' . count($replies) . ' balasan', [
    'actions' => '<a class="btn btn-ghost" href="siswa-forum.php">' . icon('arrow-left', 17) . ' Semua Topik</a>'
        . (in_array($u['role'], ['guru', 'admin'], true)
            ? '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="aksi" value="pin"><button class="btn btn-ghost">' . icon('star', 16) . ' ' . ((int)$topic['pinned'] ? 'Unpin' : 'Pin') . '</button></form>'
              . '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="aksi" value="tutup"><button class="btn btn-ghost">' . icon('lock', 16) . ' ' . ((int)$topic['closed'] ? 'Buka' : 'Tutup') . '</button></form>'
              . '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="aksi" value="hapus"><button class="btn btn-danger" data-confirm="Hapus topik ini beserta balasannya?">' . icon('trash', 16) . '</button></form>'
            : ''),
]);
?>
<div class="card">
  <div class="row-gap mb2">
    <?php if ((int)$topic['pinned']): ?><span class="badge badge-warning">Pinned</span><?php endif; ?>
    <?php if ((int)$topic['closed']): ?><span class="badge badge-muted">Topik ditutup</span><?php endif; ?>
    <span class="chip"><?= e($topic['kursus'] ?: 'Umum') ?></span>
  </div>
  <div class="post" style="border-bottom:0">
    <span class="avatar" <?= $topic['foto'] ? 'style="background-image:url(' . e($topic['foto']) . ')"' : '' ?>><?= $topic['foto'] ? '' : e(strtoupper(mb_substr($topic['nama'] ?? 'U', 0, 1))) ?></span>
    <div class="post-body">
      <div class="post-meta"><strong><?= e($topic['nama'] ?? 'Pengguna') ?></strong><span><?= e(role_label($topic['role'] ?? 'siswa')) ?></span><span><?= e(tgl_id($topic['created_at'], true)) ?></span></div>
      <div class="reading"><?= nl2br(e($topic['isi'])) ?></div>
    </div>
  </div>
</div>

<div class="card" id="balasan">
  <div class="card-head"><h2 class="mt0"><?= icon('chat', 20) ?> <?= count($replies) ?> Balasan</h2></div>
  <?php if (!$replies): ?><p class="muted small">Belum ada balasan. Berikan pendapat atau bantuanmu!</p><?php endif; ?>
  <?php foreach ($replies as $r): ?>
    <div class="post">
      <span class="avatar sm" <?= $r['foto'] ? 'style="background-image:url(' . e($r['foto']) . ')"' : '' ?>><?= $r['foto'] ? '' : e(strtoupper(mb_substr($r['nama'] ?? 'U', 0, 1))) ?></span>
      <div class="post-body">
        <div class="post-meta">
          <strong><?= e($r['nama'] ?? 'Pengguna') ?></strong>
          <?php if ($r['role'] === 'guru'): ?><span class="badge badge-info">Guru</span><?php endif; ?>
          <?php if ($r['role'] === 'admin'): ?><span class="badge badge-warning">Admin</span><?php endif; ?>
          <span><?= e(tgl_id($r['created_at'], true)) ?></span>
          <?php if (in_array($u['role'], ['guru', 'admin'], true)): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus_balasan">
              <input type="hidden" name="reply_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus balasan ini?"><?= icon('trash', 13) ?></button>
            </form>
          <?php endif; ?>
        </div>
        <p style="margin:0"><?= nl2br(e($r['isi'])) ?></p>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ((int)$topic['closed']): ?>
    <div class="flash flash-warning mt3"><?= icon('lock', 17) ?><span>Topik ini sudah ditutup. Kamu tidak bisa menambahkan balasan baru.</span></div>
  <?php else: ?>
    <form method="post" class="reply-box">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="balas">
      <span class="avatar sm"><?= e(strtoupper(mb_substr($u['nama'], 0, 1))) ?></span>
      <div style="flex:1">
        <textarea name="isi" placeholder="Tulis balasanmu..." required></textarea>
        <button class="btn btn-primary btn-sm mt1" type="submit"><?= icon('chat', 16) ?> Kirim Balasan</button>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php page_end(); ?>
