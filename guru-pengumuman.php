<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login(['guru', 'admin']);
$courses = db()->query("SELECT id, judul FROM courses WHERE " . ($u['role'] === 'admin' ? '1=1' : ('guru_id = ' . (int)$u['id'])) . ' ORDER BY judul')->fetchAll();
$allowed = array_map('intval', array_column($courses, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $cid = post('course_id') ? (int)post('course_id') : null;
        if ($cid !== null && !in_array($cid, $allowed, true)) {
            $cid = null;
        }
        $args = [$cid, $u['id'], post('judul'), post('isi'), post('audience', 'siswa'), post('pinned') ? 1 : 0];
        if ($id > 0) {
            $args[] = $id;
            db()->prepare('UPDATE announcements SET course_id=?, guru_id=?, judul=?, isi=?, audience=?, pinned=? WHERE id=?')->execute($args);
            flash('Pengumuman diperbarui.');
        } else {
            db()->prepare('INSERT INTO announcements (course_id, guru_id, judul, isi, audience, pinned) VALUES (?,?,?,?,?,?)')->execute($args);
            if ($cid) {
                notify_course($cid, 'Pengumuman: ' . post('judul'), mb_strimwidth(post('isi'), 0, 120, '...'), 'siswa-dashboard.php');
            } else {
                foreach (db()->query('SELECT id FROM users WHERE role = "siswa"')->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                    notify($sid, 'Pengumuman: ' . post('judul'), mb_strimwidth(post('isi'), 0, 120, '...'), 'siswa-dashboard.php');
                }
            }
            flash('Pengumuman dikirim & siswa dinotifikasi.');
        }
        audit('simpan_pengumuman', 'Menyimpan pengumuman ' . post('judul'));
    }
    if ($aksi === 'hapus') {
        db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        flash('Pengumuman dihapus.');
    }
    redirect('guru-pengumuman.php');
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM announcements WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$rows = db()->query("SELECT a.*, co.judul AS kursus, us.nama AS guru,
        (SELECT COUNT(*) FROM users WHERE role = 'siswa' AND (a.course_id IS NULL OR a.course_id IN (SELECT course_id FROM enrollments WHERE user_id = users.id))) AS jml_penerima
    FROM announcements a LEFT JOIN courses co ON co.id = a.course_id LEFT JOIN users us ON us.id = a.guru_id
    WHERE a.guru_id " . ($u['role'] === 'admin' ? 'IS NOT NULL' : '= ' . (int)$u['id']) . ' ORDER BY a.pinned DESC, a.created_at DESC')->fetchAll();
$emailLog = db()->query('SELECT * FROM email_log ORDER BY id DESC LIMIT 10')->fetchAll();

page_start('Pengumuman', 'Kirim informasi penting ke seluruh siswa atau siswa pada kursus tertentu.', [
    'actions' => '<button class="btn btn-primary" data-modal-open="modal-pengumuman">' . icon('plus', 17) . ' Pengumuman Baru</button>',
]);
?>
<div class="split">
  <div>
    <?php foreach ($rows as $a): ?>
      <div class="card">
        <div class="row-between mb2">
          <div class="row-gap">
            <span class="chip"><?= e($a['kursus'] ?: 'Semua siswa') ?></span>
            <?php if ((int)$a['pinned']): ?><span class="badge badge-warning">Pinned</span><?php endif; ?>
            <span class="badge badge-muted"><?= (int)$a['jml_penerima'] ?> penerima</span>
          </div>
          <div class="row-gap">
            <span class="muted small"><?= e(tgl_id($a['created_at'], true)) ?></span>
            <a class="btn btn-ghost btn-sm" href="guru-pengumuman.php?edit=<?= (int)$a['id'] ?>"><?= icon('edit', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus pengumuman ini?"><?= icon('trash', 14) ?></button>
            </form>
          </div>
        </div>
        <h3 class="mt0"><?= e($a['judul']) ?></h3>
        <p class="mb0"><?= nl2br(e($a['isi'])) ?></p>
        <div class="muted small mt2"><?= icon('user', 13) ?> <?= e($a['guru'] ?: 'Pengajar') ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="card"><?= empty_state('Belum ada pengumuman.', 'bell') ?></div><?php endif; ?>
  </div>

  <aside>
    <div class="card">
      <h3 class="mt0"><?= icon('mail', 18) ?> Log Email Sistem</h3>
      <p class="muted small">Email notifikasi dicatat di sini. Aktifkan SMTP di menu Pengaturan (admin) agar email benar-benar terkirim.</p>
      <div class="scroll-y">
        <?php foreach ($emailLog as $e): ?>
          <div class="post">
            <span class="lesson-ic"><?= icon('mail', 15) ?></span>
            <div class="post-body">
              <div class="post-meta"><strong><?= e($e['subjek']) ?></strong><?= status_badge($e['status']) ?></div>
              <div class="muted small"><?= e($e['penerima']) ?> &middot; <?= e(tgl_id($e['created_at'], true)) ?></div>
              <?php if ($e['error']): ?><div class="muted small"><?= e($e['error']) ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$emailLog): ?><p class="muted small">Belum ada email.</p><?php endif; ?>
      </div>
    </div>
  </aside>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-pengumuman">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Pengumuman' : 'Pengumuman Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-group"><label>Judul *</label><input type="text" name="judul" value="<?= e($edit['judul'] ?? '') ?>" required></div>
      <div class="form-grid">
        <div class="form-group">
          <label>Kirim ke</label>
          <select name="course_id">
            <option value="">Semua siswa (global)</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($edit['course_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Target pembaca</label>
          <select name="audience">
            <option value="siswa" <?= ($edit['audience'] ?? '') === 'siswa' ? 'selected' : '' ?>>Siswa</option>
            <option value="semua" <?= ($edit['audience'] ?? '') === 'semua' ? 'selected' : '' ?>>Semua pengguna</option>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Isi pengumuman *</label><textarea name="isi" style="min-height:150px" required><?= e($edit['isi'] ?? '') ?></textarea></div>
      <label class="check mb3"><input type="checkbox" name="pinned" value="1" <?= (int)($edit['pinned'] ?? 0) ? 'checked' : '' ?>> Tandai sebagai penting (pinned)</label>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Kirim Pengumuman</button>
        <a class="btn btn-ghost" href="guru-pengumuman.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
