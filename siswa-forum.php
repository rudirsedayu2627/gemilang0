<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$kursusFilter = num('kursus');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('aksi') === 'topik') {
    csrf_check();
    $judul = post('judul');
    $isi = post('isi');
    $cid = (int)post('course_id');
    if ($judul !== '' && $cid > 0) {
        $bolehForum = in_array($u['role'], ['guru', 'admin'], true) || cek_akses_kursus((int)$u['id'], $cid)['boleh'];
        if ($bolehForum) {
            db()->prepare('INSERT INTO forum_topics (course_id, user_id, judul, isi) VALUES (?, ?, ?, ?)')->execute([$cid, $u['id'], $judul, $isi]);
            $tid = (int)db()->lastInsertId();
            notify_course($cid, 'Topik diskusi baru', $u['nama'] . ' membuka topik "' . $judul . '"', 'forum-topik.php?id=' . $tid, $u['id']);
            audit('buat_topik', 'Membuat topik forum ' . $judul);
            flash('Topik diskusi berhasil dibuat.');
            redirect('forum-topik.php?id=' . $tid);
        }
    }
    flash('Topik gagal dibuat. Pastikan kamu mengikuti kursusnya.', 'danger');
    redirect('siswa-forum.php');
}

$kursus = db()->query('SELECT co.id, co.judul FROM enrollments e JOIN courses co ON co.id = e.course_id WHERE e.user_id = ' . (int)$u['id'] . '
    UNION SELECT co.id, co.judul FROM courses co WHERE co.guru_id = ' . (int)$u['id'] . ' ORDER BY judul')->fetchAll();

$where = '1=1';
if ($kursusFilter > 0) {
    $where = 't.course_id = ' . $kursusFilter;
} elseif ($u['role'] === 'siswa') {
    $where = 't.course_id IN (SELECT course_id FROM enrollments WHERE user_id = ' . (int)$u['id'] . ')';
} elseif ($u['role'] === 'guru') {
    $where = 't.course_id IN (SELECT id FROM courses WHERE guru_id = ' . (int)$u['id'] . ')';
}

$topics = db()->query("SELECT t.*, us.nama, us.role, us.foto, co.judul AS kursus,
        (SELECT COUNT(*) FROM forum_replies r WHERE r.topic_id = t.id) AS jml_balasan,
        (SELECT MAX(created_at) FROM forum_replies r WHERE r.topic_id = t.id) AS balasan_terakhir
    FROM forum_topics t LEFT JOIN users us ON us.id = t.user_id LEFT JOIN courses co ON co.id = t.course_id
    WHERE $where ORDER BY t.pinned DESC, COALESCE(balasan_terakhir, t.created_at) DESC")->fetchAll();

page_start('Forum Diskusi', 'Tanya jawab antar siswa dan guru untuk setiap kursus.', [
    'actions' => $kursus ? '<button class="btn btn-primary" data-modal-open="modal-topik">' . icon('plus', 17) . ' Buat Topik Baru</button>' : '',
]);
?>
<div class="card">
  <form class="toolbar" method="get">
    <div class="form-group">
      <label>Filter kursus</label>
      <select name="kursus" data-autosubmit>
        <option value="0">Semua kursus saya</option>
        <?php foreach ($kursus as $k): ?>
          <option value="<?= (int)$k['id'] ?>" <?= $kursusFilter === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <a class="btn btn-ghost" href="siswa-forum.php">Reset</a>
  </form>

  <?php if (!$topics): ?><?= empty_state('Belum ada diskusi. Jadilah yang pertama membuka topik!', 'chat') ?><?php endif; ?>
  <?php foreach ($topics as $t): ?>
    <a class="topic" href="forum-topik.php?id=<?= (int)$t['id'] ?>" style="text-decoration:none;color:inherit">
      <span class="avatar" <?= $t['foto'] ? 'style="background-image:url(' . e($t['foto']) . ')"' : '' ?>><?= $t['foto'] ? '' : e(strtoupper(mb_substr($t['nama'] ?? 'U', 0, 1))) ?></span>
      <div class="topic-main">
        <div class="row-gap mb1">
          <?php if ((int)$t['pinned']): ?><span class="badge badge-warning">Pinned</span><?php endif; ?>
          <?php if ((int)$t['closed']): ?><span class="badge badge-muted">Ditutup</span><?php endif; ?>
          <span class="chip"><?= e($t['kursus'] ?: 'Umum') ?></span>
        </div>
        <h3><?= e($t['judul']) ?></h3>
        <p class="muted small" style="margin:0"><?= e(mb_strimwidth($t['isi'], 0, 160, '...')) ?></p>
        <div class="post-meta mt1">
          <strong><?= e($t['nama'] ?? 'Pengguna') ?></strong>
          <span><?= e(role_label($t['role'] ?? 'siswa')) ?></span>
          <span><?= e(tgl_id($t['created_at'], true)) ?></span>
          <span><?= icon('chat', 13) ?> <?= (int)$t['jml_balasan'] ?> balasan</span>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($kursus): ?>
<div class="modal-back" id="modal-topik">
  <div class="modal">
    <div class="modal-head">
      <h3 class="mt0">Buat Topik Diskusi</h3>
      <button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="topik">
      <div class="form-group">
        <label>Kursus</label>
        <select name="course_id" required>
          <?php foreach ($kursus as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['judul']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Judul topik</label>
        <input type="text" name="judul" placeholder="Mis. Beda present perfect dan past simple?" required>
      </div>
      <div class="form-group">
        <label>Isi pertanyaan</label>
        <textarea name="isi" placeholder="Tuliskan pertanyaan atau pendapatmu sedetail mungkin..." required></textarea>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('chat', 17) ?> Buat Topik</button>
        <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php page_end(); ?>
