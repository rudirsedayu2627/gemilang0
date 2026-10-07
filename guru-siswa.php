<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login(['guru', 'admin']);

$courses = db()->query("SELECT id, judul FROM courses WHERE " . ($u['role'] === 'admin' ? '1=1' : ('guru_id = ' . (int)$u['id'])) . ' ORDER BY judul')->fetchAll();
$allowed = array_map('intval', array_column($courses, 'id'));
$kursusId = num('kursus', (int)($courses[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $uid = (int)post('user_id');
    if ($aksi === 'enroll' && in_array((int)post('course_id'), $allowed, true)) {
        $cid = (int)post('course_id');
        $siswa = [];
        foreach ((array)post('siswa', []) as $s) {
            $siswa[] = (int)$s;
        }
        foreach ($siswa as $sid) {
            db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id, status) VALUES (?, ?, "aktif")')->execute([$sid, $cid]);
            recalc_course_progress($sid, $cid);
            notify($sid, 'Kamu terdaftar di kursus baru', 'Kamu ditambahkan ke kursus. Silakan mulai belajar!', 'materi.php?kursus=' . $cid);
        }
        flash(count($siswa) . ' siswa didaftarkan ke kursus.');
        audit('enroll_siswa', 'Mendaftarkan siswa ke kursus ' . $cid);
    }
    if ($aksi === 'unenroll') {
        db()->prepare('DELETE FROM enrollments WHERE user_id = ? AND course_id = ?')->execute([$uid, (int)post('course_id')]);
        flash('Siswa dikeluarkan dari kursus.');
    }
    if ($aksi === 'reset_password') {
        $baru = post('password_baru', 'siswa123');
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($baru, PASSWORD_DEFAULT), $uid]);
        notify($uid, 'Password direset', 'Password akunmu direset oleh pengajar. Password baru: ' . $baru, 'login.php');
        audit('reset_password', 'Reset password user ' . $uid);
        flash('Password siswa direset menjadi: ' . $baru);
    }
    if ($aksi === 'status') {
        db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([post('status'), $uid]);
        flash('Status siswa diperbarui.');
    }
    if ($aksi === 'tambah_siswa' && $u['role'] === 'admin') {
        $nama = post('nama');
        $email = strtolower(post('email'));
        if ($nama && $email) {
            $username = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $email)[0]));
            $cek = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ? OR username = ?');
            $cek->execute([$email, $username]);
            if ((int)$cek->fetchColumn() === 0) {
                db()->prepare('INSERT INTO users (nama, username, email, password, role, telepon) VALUES (?,?,?,?,"siswa",?)')
                    ->execute([$nama, $username, $email, password_hash(post('password', 'siswa123'), PASSWORD_DEFAULT), post('telepon')]);
                flash('Siswa baru dibuat. Password awal: ' . post('password', 'siswa123'));
            } else {
                flash('Email/username sudah digunakan.', 'danger');
            }
        }
    }
    redirect('guru-siswa.php?kursus=' . $kursusId);
}

if (get('export')) {
    $rows = [['Nama', 'Email', 'Username', 'Telepon', 'Status', 'Terdaftar']];
    foreach (db()->query("SELECT * FROM users WHERE role = 'siswa' ORDER BY nama")->fetchAll() as $s) {
        $rows[] = [$s['nama'], $s['email'], $s['username'], $s['telepon'], $s['status'], $s['created_at']];
    }
    audit('export_siswa', 'Export data siswa');
    xlsx_download('data-siswa-' . date('Ymd') . '.xlsx', $rows);
}

$siswaKursus = db()->query("SELECT e.*, us.nama, us.email, us.foto, us.status AS status_user, us.last_login,
        ROUND(e.progress, 1) AS progress,
        (SELECT COUNT(*) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.user_id = us.id AND qz.course_id = e.course_id AND a.status != 'in_progress') AS jml_attempt,
        (SELECT COALESCE(ROUND(AVG(a.nilai),1),0) FROM quiz_attempts a JOIN quizzes qz ON qz.id = a.quiz_id WHERE a.user_id = us.id AND qz.course_id = e.course_id AND a.status != 'in_progress') AS rata_nilai
    FROM enrollments e JOIN users us ON us.id = e.user_id
    WHERE e.course_id = " . (int)$kursusId . ' ORDER BY e.progress DESC, us.nama')->fetchAll();

$semuaSiswa = db()->query('SELECT id, nama, email FROM users WHERE role = "siswa" AND status = "aktif" ORDER BY nama')->fetchAll();
$belumTerdaftar = [];
foreach ($semuaSiswa as $s) {
    $found = false;
    foreach ($siswaKursus as $sk) {
        if ((int)$sk['user_id'] === (int)$s['id']) {
            $found = true;
        }
    }
    if (!$found) {
        $belumTerdaftar[] = $s;
    }
}
$totalSiswa = (int)db()->query('SELECT COUNT(*) FROM users WHERE role = "siswa"')->fetchColumn();
$rataProgress = $siswaKursus ? round(array_sum(array_column($siswaKursus, 'progress')) / count($siswaKursus), 1) : 0;

page_start('Kelola Siswa', 'Daftar siswa pada kursus, progress, dan nilai; serta kelola data akun siswa.', [
    'actions' => '<a class="btn btn-ghost" href="guru-siswa.php?export=xlsx">' . icon('download', 17) . ' Export Data Siswa</a>'
        . ($u['role'] === 'admin' ? '<button class="btn btn-primary" data-modal-open="modal-siswa-baru">' . icon('plus', 17) . ' Siswa Baru</button>' : ''),
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= $totalSiswa ?></div><div class="stat-lab">Total siswa sistem</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('book', 22) ?></div><div><div class="stat-num"><?= count($siswaKursus) ?></div><div class="stat-lab">Siswa di kursus ini</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= number_format($rataProgress, 1) ?>%</div><div class="stat-lab">Rata-rata progress</div></div></div>
  <?php if (sertifikat_aktif()): ?><div class="stat"><div class="stat-ic g4"><?= icon('award', 22) ?></div><div><div class="stat-num"><?= (int)db()->query('SELECT COUNT(*) FROM certificates c JOIN enrollments e ON e.course_id = c.course_id AND e.user_id = c.user_id WHERE e.course_id = ' . (int)$kursusId)->fetchColumn() ?></div><div class="stat-lab">Sertifikat kursus ini</div></div></div><?php endif; ?>
</div>

<div class="card">
  <form class="inline-form">
    <div class="form-group" style="min-width:280px">
      <label>Pilih kursus</label>
      <select name="kursus" data-autosubmit>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1;min-width:200px">
      <label>Cari siswa</label>
      <input type="search" data-filter-input="#tbl-siswa" placeholder="Nama atau email...">
    </div>
    <button class="btn btn-primary" data-modal-open="modal-enroll"><?= icon('plus', 17) ?> Daftarkan Siswa</button>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data" id="tbl-siswa">
      <thead><tr><th>Siswa</th><th>Progress</th><th>Quiz</th><th>Rata Nilai</th><th>Login Terakhir</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($siswaKursus as $s): ?>
        <tr>
          <td><div class="tbl-student"><span class="avatar sm" <?= $s['foto'] ? 'style="background-image:url(' . e($s['foto']) . ')"' : '' ?>><?= $s['foto'] ? '' : e(strtoupper(mb_substr($s['nama'], 0, 1))) ?></span><div><strong><?= e($s['nama']) ?></strong><div class="muted small"><?= e($s['email']) ?></div></div></div></td>
          <td style="min-width:170px">
            <div class="progress-lab"><span><?= round((float)$s['progress']) ?>%</span><span><?= status_badge($s['status']) ?></span></div>
            <div class="progress thin"><span style="width:<?= round((float)$s['progress']) ?>%"></span></div>
          </td>
          <td><?= (int)$s['jml_attempt'] ?>x</td>
          <td><?= number_format((float)$s['rata_nilai'], 1) ?></td>
          <td class="small"><?= e(tgl_id($s['last_login'], true)) ?></td>
          <td><?= status_badge($s['status_user']) ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="guru-progress.php?siswa=<?= (int)$s['user_id'] ?>&kursus=<?= (int)$kursusId ?>"><?= icon('chart', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="reset_password">
              <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
              <input type="hidden" name="password_baru" value="siswa123">
              <button class="btn btn-ghost btn-sm" data-confirm="Reset password siswa ini menjadi 'siswa123'?" title="Reset password"><?= icon('lock', 14) ?></button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="unenroll">
              <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
              <input type="hidden" name="course_id" value="<?= (int)$kursusId ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Keluarkan siswa dari kursus ini?"><?= icon('x', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$siswaKursus): ?><tr><td colspan="7" class="muted">Belum ada siswa pada kursus ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="modal-enroll">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0">Daftarkan Siswa ke Kursus</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="enroll">
      <div class="form-group">
        <label>Kursus *</label>
        <select name="course_id" required>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Pilih siswa</label>
        <select name="siswa[]" multiple size="10" style="height:auto">
          <?php foreach ($belumTerdaftar as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['nama']) ?> — <?= e($s['email']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Tahan Ctrl/Cmd untuk memilih lebih dari satu. Siswa yang sudah terdaftar tidak muncul di sini.</div>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Daftarkan</button>
        <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
      </div>
    </form>
  </div>
</div>

<?php if ($u['role'] === 'admin'): ?>
<div class="modal-back" id="modal-siswa-baru">
  <div class="modal" style="max-width:520px">
    <div class="modal-head"><h3 class="mt0">Tambah Siswa Baru</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="tambah_siswa">
      <div class="form-group"><label>Nama lengkap *</label><input type="text" name="nama" required></div>
      <div class="form-group"><label>Email *</label><input type="email" name="email" required></div>
      <div class="form-grid">
        <div class="form-group"><label>Telepon</label><input type="text" name="telepon"></div>
        <div class="form-group"><label>Password awal</label><input type="text" name="password" value="siswa123"></div>
      </div>
      <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Siswa</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php page_end(); ?>
