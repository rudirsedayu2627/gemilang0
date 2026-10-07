<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $cid = post('course_id') ? (int)post('course_id') : null;
        $args = [$cid, post('guru_id') ?: null, post('judul'), post('isi'), post('audience', 'semua'), post('pinned') ? 1 : 0];
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
                    notify((int)$sid, 'Pengumuman: ' . post('judul'), mb_strimwidth(post('isi'), 0, 120, '...'), 'siswa-dashboard.php');
                }
            }
            flash('Pengumuman dibuat & notifikasi terkirim.');
        }
        audit('simpan_pengumuman', 'Admin menyimpan pengumuman ' . post('judul'));
    }
    if ($aksi === 'hapus') {
        db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        flash('Pengumuman dihapus.');
    }
    if ($aksi === 'broadcast_email') {
        $penerima = db()->query('SELECT email, nama FROM users WHERE role = "siswa" AND status = "aktif"')->fetchAll();
        $n = 0;
        foreach ($penerima as $p) {
            smtp_send($p['email'], post('subjek'), '<p>Halo ' . e($p['nama']) . ',</p><p>' . nl2br(e(post('isi'))) . '</p>');
            $n++;
        }
        audit('broadcast_email', 'Broadcast email ke ' . $n . ' siswa');
        flash('Broadcast email diproses untuk ' . $n . ' siswa. Cek Log Email untuk status pengiriman.');
    }
    redirect('admin-pengumuman.php');
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM announcements WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$rows = db()->query("SELECT a.*, co.judul AS kursus, us.nama AS guru FROM announcements a
    LEFT JOIN courses co ON co.id = a.course_id LEFT JOIN users us ON us.id = a.guru_id
    ORDER BY a.pinned DESC, a.created_at DESC")->fetchAll();
$courses = db()->query('SELECT id, judul FROM courses ORDER BY judul')->fetchAll();
$guruList = db()->query("SELECT id, nama FROM users WHERE role = 'guru' ORDER BY nama")->fetchAll();
$emailLog = db()->query('SELECT * FROM email_log ORDER BY id DESC LIMIT 12')->fetchAll();

page_start('Manajemen Pengumuman', count($rows) . ' pengumuman di sistem.', [
    'actions' => '<button class="btn btn-ghost" data-modal-open="modal-broadcast">' . icon('mail', 17) . ' Broadcast Email</button>'
        . '<button class="btn btn-primary" data-modal-open="modal-ann">' . icon('plus', 17) . ' Pengumuman Baru</button>',
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
            <span class="badge badge-muted">oleh <?= e($a['guru'] ?: 'Admin') ?></span>
          </div>
          <div class="row-gap">
            <span class="muted small"><?= e(tgl_id($a['created_at'], true)) ?></span>
            <a class="btn btn-ghost btn-sm" href="admin-pengumuman.php?edit=<?= (int)$a['id'] ?>"><?= icon('edit', 14) ?></a>
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
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="card"><?= empty_state('Belum ada pengumuman.', 'bell') ?></div><?php endif; ?>
  </div>
  <aside>
    <div class="card">
      <div class="card-head"><h3 class="mt0"><?= icon('mail', 18) ?> Log Email</h3><a class="small" href="admin-pengaturan.php#smtp">SMTP</a></div>
      <div class="scroll-y">
        <?php foreach ($emailLog as $e): ?>
          <div class="post">
            <span class="lesson-ic"><?= icon('mail', 14) ?></span>
            <div class="post-body">
              <div class="post-meta"><strong><?= e(mb_strimwidth((string)$e['subjek'], 0, 30, '...')) ?></strong><?= status_badge($e['status']) ?></div>
              <div class="muted small"><?= e($e['penerima']) ?><br><?= e(tgl_id($e['created_at'], true)) ?></div>
              <?php if ($e['error']): ?><div class="muted small"><?= e($e['error']) ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$emailLog): ?><p class="muted small">Belum ada email tercatat.</p><?php endif; ?>
      </div>
    </div>
  </aside>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-ann">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Pengumuman' : 'Pengumuman Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-group"><label>Judul *</label><input type="text" name="judul" value="<?= e($edit['judul'] ?? '') ?>" required></div>
      <div class="form-grid">
        <div class="form-group">
          <label>Kursus</label>
          <select name="course_id">
            <option value="">Semua siswa (global)</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($edit['course_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Penulis</label>
          <select name="guru_id">
            <?php foreach ($guruList as $g): ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)($edit['guru_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nama']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Target</label>
          <select name="audience">
            <option value="semua" <?= ($edit['audience'] ?? '') === 'semua' ? 'selected' : '' ?>>Semua pengguna</option>
            <option value="siswa" <?= ($edit['audience'] ?? '') === 'siswa' ? 'selected' : '' ?>>Siswa</option>
            <option value="kursus" <?= ($edit['audience'] ?? '') === 'kursus' ? 'selected' : '' ?>>Peserta kursus</option>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Isi *</label><textarea name="isi" style="min-height:150px" required><?= e($edit['isi'] ?? '') ?></textarea></div>
      <label class="check mb3"><input type="checkbox" name="pinned" value="1" <?= (int)($edit['pinned'] ?? 0) ? 'checked' : '' ?>> Tandai penting (pinned)</label>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-pengumuman.php">Batal</a>
      </div>
    </form>
  </div>
</div>

<div class="modal-back" id="modal-broadcast">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0">Broadcast Email ke Semua Siswa</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="broadcast_email">
      <div class="form-group"><label>Subjek *</label><input type="text" name="subjek" required></div>
      <div class="form-group"><label>Isi email *</label><textarea name="isi" style="min-height:160px" required></textarea></div>
      <div class="dark-box mb3">Email akan dikirim via SMTP bila sudah diaktifkan di Pengaturan. Jika belum, email tetap tercatat pada Log Email dengan status "dilewati".</div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('mail', 17) ?> Kirim Broadcast</button>
        <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
