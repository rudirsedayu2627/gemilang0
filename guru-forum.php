<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login(['guru', 'admin']);
$courses = db()->query("SELECT id, judul FROM courses WHERE " . ($u['role'] === 'admin' ? '1=1' : ('guru_id = ' . (int)$u['id'])) . ' ORDER BY judul')->fetchAll();
$allowed = array_map('intval', array_column($courses, 'id'));
$kursusId = num('kursus');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $tid = (int)post('topic_id');
    if (in_array($aksi, ['pin', 'tutup', 'hapus'], true) && $tid > 0) {
        if ($aksi === 'pin') {
            db()->prepare('UPDATE forum_topics SET pinned = 1 - pinned WHERE id = ?')->execute([$tid]);
        } elseif ($aksi === 'tutup') {
            db()->prepare('UPDATE forum_topics SET closed = 1 - closed WHERE id = ?')->execute([$tid]);
        } else {
            db()->prepare('DELETE FROM forum_replies WHERE topic_id = ?')->execute([$tid]);
            db()->prepare('DELETE FROM forum_topics WHERE id = ?')->execute([$tid]);
            flash('Topik dihapus.');
        }
        audit('moderasi_forum', $aksi . ' topik ' . $tid);
    }
    if ($aksi === 'balas' && $tid > 0) {
        $isi = post('isi');
        if ($isi !== '') {
            db()->prepare('INSERT INTO forum_replies (topic_id, user_id, isi) VALUES (?, ?, ?)')->execute([$tid, $u['id'], $isi]);
            $t = db()->query('SELECT user_id, judul FROM forum_topics WHERE id = ' . $tid)->fetch();
            if ($t && (int)$t['user_id'] !== (int)$u['id']) {
                notify($t['user_id'], 'Guru membalas topikmu', $u['nama'] . ': ' . mb_strimwidth($isi, 0, 90, '...'), 'forum-topik.php?id=' . $tid);
            }
            flash('Balasan terkirim.');
        }
        redirect('guru-forum.php?topik=' . $tid);
    }
    redirect('guru-forum.php' . ($kursusId ? '?kursus=' . $kursusId : ''));
}

$where = 't.course_id IN (SELECT id FROM courses WHERE ' . ($u['role'] === 'admin' ? '1=1' : ('guru_id = ' . (int)$u['id'])) . ')';
if ($kursusId > 0) {
    $where = 't.course_id = ' . $kursusId;
}
$topics = db()->query("SELECT t.*, us.nama, us.role, us.foto, co.judul AS kursus,
        (SELECT COUNT(*) FROM forum_replies r WHERE r.topic_id = t.id) AS jml_balasan
    FROM forum_topics t LEFT JOIN users us ON us.id = t.user_id LEFT JOIN courses co ON co.id = t.course_id
    WHERE $where ORDER BY t.pinned DESC, t.created_at DESC LIMIT 100")->fetchAll();
$topikAktif = num('topik') ? db()->query('SELECT t.*, us.nama FROM forum_topics t LEFT JOIN users us ON us.id = t.user_id WHERE t.id = ' . num('topik'))->fetch() : null;
$balasan = $topikAktif ? db()->query('SELECT r.*, us.nama, us.role, us.foto FROM forum_replies r LEFT JOIN users us ON us.id = r.user_id WHERE r.topic_id = ' . (int)$topikAktif['id'] . ' ORDER BY r.created_at')->fetchAll() : [];
$tanpaJawaban = count(array_filter($topics, function ($t) { return (int)$t['jml_balasan'] === 0; }));

page_start('Forum & Diskusi', 'Moderasi diskusi siswa, jawab pertanyaan, pin atau tutup topik.', [
    'actions' => '<a class="btn btn-ghost" href="guru-forum.php">' . icon('refresh', 17) . ' Muat Ulang</a>',
]);
?>
<div class="grid cols-3 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('chat', 22) ?></div><div><div class="stat-num"><?= count($topics) ?></div><div class="stat-lab">Total topik</div></div></div>
  <div class="stat"><div class="stat-ic g6"><?= icon('bell', 22) ?></div><div><div class="stat-num"><?= $tanpaJawaban ?></div><div class="stat-lab">Belum dijawab</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('star', 22) ?></div><div><div class="stat-num"><?= count(array_filter($topics, function ($t) { return (int)$t['pinned']; })) ?></div><div class="stat-lab">Topik di-pin</div></div></div>
</div>

<div class="split">
  <div>
    <div class="card">
      <form class="inline-form mb2">
        <div class="form-group" style="min-width:260px">
          <label>Filter kursus</label>
          <select name="kursus" data-autosubmit>
            <option value="0">Semua kursus saya</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
      <?php foreach ($topics as $t): ?>
        <a class="topic" href="guru-forum.php?kursus=<?= (int)$kursusId ?>&topik=<?= (int)$t['id'] ?>" style="text-decoration:none;color:inherit">
          <span class="avatar" <?= $t['foto'] ? 'style="background-image:url(' . e($t['foto']) . ')"' : '' ?>><?= $t['foto'] ? '' : e(strtoupper(mb_substr((string)$t['nama'], 0, 1))) ?></span>
          <div class="topic-main">
            <div class="row-gap mb1">
              <?php if ((int)$t['pinned']): ?><span class="badge badge-warning">Pinned</span><?php endif; ?>
              <?php if ((int)$t['closed']): ?><span class="badge badge-muted">Ditutup</span><?php endif; ?>
              <?php if ((int)$t['jml_balasan'] === 0): ?><span class="badge badge-danger">Belum dijawab</span><?php endif; ?>
              <span class="chip"><?= e($t['kursus'] ?: 'Umum') ?></span>
            </div>
            <h3><?= e($t['judul']) ?></h3>
            <p class="muted small mb0"><?= e(mb_strimwidth((string)$t['isi'], 0, 140, '...')) ?></p>
            <div class="post-meta mt1"><strong><?= e((string)$t['nama']) ?></strong><span><?= e(role_label($t['role'] ?? 'siswa')) ?></span><span><?= e(tgl_id($t['created_at'], true)) ?></span><span><?= icon('chat', 13) ?> <?= (int)$t['jml_balasan'] ?></span></div>
          </div>
          <div class="row-gap" style="align-self:center">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="pin">
              <input type="hidden" name="topic_id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-ghost btn-sm" title="Pin"><?= icon('star', 14) ?></button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="tutup">
              <input type="hidden" name="topic_id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-ghost btn-sm" title="Tutup/buka"><?= icon('lock', 14) ?></button>
            </form>
          </div>
        </a>
      <?php endforeach; ?>
      <?php if (!$topics): ?><?= empty_state('Belum ada topik diskusi pada kursusmu.', 'chat') ?><?php endif; ?>
    </div>
  </div>

  <aside>
    <?php if ($topikAktif): ?>
      <div class="card">
        <div class="card-head"><h3 class="mt0"><?= e($topikAktif['judul']) ?></h3><a class="small" href="guru-forum.php?kursus=<?= (int)$kursusId ?>">tutup</a></div>
        <div class="muted small mb2">Oleh <?= e((string)$topikAktif['nama']) ?> &middot; <?= e(tgl_id($topikAktif['created_at'], true)) ?></div>
        <p><?= nl2br(e((string)$topikAktif['isi'])) ?></p>
        <div class="divider"></div>
        <div class="scroll-y">
          <?php foreach ($balasan as $b): ?>
            <div class="post">
              <span class="avatar sm" <?= $b['foto'] ? 'style="background-image:url(' . e($b['foto']) . ')"' : '' ?>><?= $b['foto'] ? '' : e(strtoupper(mb_substr((string)$b['nama'], 0, 1))) ?></span>
              <div class="post-body">
                <div class="post-meta"><strong><?= e((string)$b['nama']) ?></strong><?= $b['role'] === 'guru' ? '<span class="badge badge-info">Guru</span>' : '' ?><span><?= e(tgl_id($b['created_at'], true)) ?></span></div>
                <p style="margin:0"><?= nl2br(e((string)$b['isi'])) ?></p>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$balasan): ?><p class="muted small">Belum ada balasan.</p><?php endif; ?>
        </div>
        <form method="post" class="mt2">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="balas">
          <input type="hidden" name="topic_id" value="<?= (int)$topikAktif['id'] ?>">
          <textarea name="isi" placeholder="Tulis jawaban sebagai guru..." required></textarea>
          <button class="btn btn-primary btn-sm mt1" type="submit"><?= icon('chat', 16) ?> Kirim Balasan</button>
        </form>
      </div>
    <?php else: ?>
      <div class="card"><?= empty_state('Pilih topik di sebelah kiri untuk membaca & menjawab.', 'chat') ?></div>
    <?php endif; ?>
  </aside>
</div>
<?php page_end(); ?>
