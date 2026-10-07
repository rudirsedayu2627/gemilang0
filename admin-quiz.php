<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan') {
        $cid = post('course_id') ?: null;
        // lesson_id divalidasi: harus materi pada kursus yang sama (kalau tidak, null).
        $lessonId = quiz_lesson_id_valid((int)$cid, (int)post('lesson_id'));
        $args = [$cid, post('judul'), post('deskripsi'), post('tipe', 'quiz'), max(1, (int)post('durasi_menit')), (int)post('passing_grade'), post('random_soal') ? 1 : 0, post('random_opsi') ? 1 : 0, (int)post('jumlah_soal_tampil'), post('tampilkan_pembahasan') ? 1 : 0, (int)post('max_attempt'), post('status', 'aktif'), $lessonId, post('guru_id') ?: null];
        if ($id > 0) {
            $args[] = $id;
            db()->prepare('UPDATE quizzes SET course_id=?, judul=?, deskripsi=?, tipe=?, durasi_menit=?, passing_grade=?, random_soal=?, random_opsi=?, jumlah_soal_tampil=?, tampilkan_pembahasan=?, max_attempt=?, status=?, lesson_id=?, guru_id=? WHERE id=?')->execute($args);
            flash('Quiz diperbarui.');
        } else {
            db()->prepare('INSERT INTO quizzes (course_id, judul, deskripsi, tipe, durasi_menit, passing_grade, random_soal, random_opsi, jumlah_soal_tampil, tampilkan_pembahasan, max_attempt, status, lesson_id, guru_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($args);
            flash('Quiz baru dibuat.');
        }
        audit('simpan_quiz', 'Admin menyimpan quiz ' . post('judul'));
    }
    if ($aksi === 'hapus') {
        // helper: hapus hasil pengerjaan + lepas kepemilikan soal (soal tetap di bank soal)
        lms_hapus_turunan('quiz', $id);
        audit('hapus_quiz', 'Admin menghapus quiz id ' . $id);
        flash('Quiz dihapus beserta hasil pengerjaannya. Soalnya tetap di bank soal kursus dan tidak lagi dimiliki quiz mana pun.');
    }
    if ($aksi === 'status') {
        db()->prepare('UPDATE quizzes SET status = ? WHERE id = ?')->execute([post('status'), $id]);
        flash('Status quiz diperbarui.');
    }
    if ($aksi === 'reset_attempt') {
        db()->prepare('DELETE FROM attempt_answers WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE quiz_id = ?)')->execute([$id]);
        db()->prepare('DELETE FROM quiz_attempts WHERE quiz_id = ?')->execute([$id]);
        audit('reset_attempt', 'Menghapus hasil pengerjaan quiz ' . $id);
        flash('Seluruh hasil pengerjaan quiz ini dihapus (siswa bisa mengerjakan ulang).');
    }
    redirect('admin-quiz.php');
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM quizzes WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$rows = db()->query("SELECT qz.*, co.judul AS kursus, us.nama AS guru,
        (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = qz.id) AS jml_attempt,
        (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = qz.id AND status = 'menunggu_koreksi') AS jml_koreksi,
        (SELECT COALESCE(ROUND(AVG(nilai),2),0) FROM quiz_attempts WHERE quiz_id = qz.id AND status != 'in_progress') AS rata
    FROM quizzes qz LEFT JOIN courses co ON co.id = qz.course_id LEFT JOIN users us ON us.id = qz.guru_id
    WHERE " . (num('kursus') ? ('qz.course_id = ' . num('kursus')) : '1=1') . ' ORDER BY qz.id DESC')->fetchAll();
$jmlSoalMap = quiz_jml_soal_map();
foreach ($rows as $i => $qz) {
    $rows[$i]['jml_soal'] = (int)($jmlSoalMap[(int)$qz['id']] ?? 0);
}
$jmlBank = (int)db()->query('SELECT COUNT(*) FROM questions')->fetchColumn();
$courses = db()->query('SELECT id, judul FROM courses ORDER BY judul')->fetchAll();
$guruList = db()->query("SELECT id, nama FROM users WHERE role = 'guru' ORDER BY nama")->fetchAll();

// Materi tiap kursus untuk dropdown "tampilkan di dalam materi" + info di tabel.
$lessonsSemua = db()->query('SELECT l.id, l.judul, l.course_id FROM lessons l ORDER BY l.urutan, l.id')->fetchAll();
$lessonsPerKursus = [];
foreach ($lessonsSemua as $ls) {
    $lessonsPerKursus[(int)$ls['course_id']][] = $ls;
}
$judulKursus = [];
foreach ($courses as $c) {
    $judulKursus[(int)$c['id']] = $c['judul'];
}

page_start('Manajemen Quiz', 'Semua quiz & ujian dari seluruh kursus. Bank soal: ' . $jmlBank . ' soal (soal dimiliki kursus dan hanya boleh dimiliki satu quiz).', [
    'actions' => '<a class="btn btn-ghost" href="admin-soal.php">' . icon('list', 17) . ' Bank Soal</a>'
        . '<button class="btn btn-primary" data-modal-open="modal-quiz">' . icon('plus', 17) . ' Quiz Baru</button>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('quiz', 22) ?></div><div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lab">Total quiz</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('list', 22) ?></div><div><div class="stat-num"><?= array_sum(array_column($rows, 'jml_soal')) ?></div><div class="stat-lab">Total soal</div></div></div>
  <div class="stat"><div class="stat-ic g3"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= array_sum(array_column($rows, 'jml_attempt')) ?></div><div class="stat-lab">Pengerjaan tercatat</div></div></div>
  <div class="stat"><div class="stat-ic g6"><?= icon('edit', 22) ?></div><div><div class="stat-num"><?= array_sum(array_column($rows, 'jml_koreksi')) ?></div><div class="stat-lab">Menunggu koreksi</div></div></div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Quiz</th><th>Kursus</th><th>Pengajar</th><th>Soal</th><th>Durasi</th><th>Min</th><th>Random</th><th>Pengerjaan</th><th>Rata</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $qz): ?>
        <tr>
          <td>
            <strong><?= e($qz['judul']) ?></strong>
            <div class="muted small"><?= e($qz['tipe']) ?></div>
            <?php $induk = quiz_materi_induk($qz, $lessonsPerKursus[(int)$qz['course_id']] ?? []); ?>
            <div class="muted small">
              <?php if ($induk): ?>
                <?= icon('file', 12) ?> Di materi: <?= e($induk['judul']) ?><?= $induk['otomatis'] ? ' <em>(otomatis)</em>' : '' ?>
              <?php else: ?>
                <?= icon('grid', 12) ?> Di luar materi
              <?php endif; ?>
            </div>
          </td>
          <td class="small"><?= e($qz['kursus'] ?: '-') ?></td>
          <td class="small"><?= e($qz['guru'] ?: '-') ?></td>
          <td><?= (int)$qz['jml_soal'] ?></td>
          <td><?= (int)$qz['durasi_menit'] ?>m</td>
          <td><?= (int)$qz['passing_grade'] ?></td>
          <td class="small"><?= (int)$qz['random_soal'] ? 'soal ✓' : 'soal ✗' ?><?= (int)$qz['random_opsi'] ? ' / opsi ✓' : ' / opsi ✗' ?></td>
          <td><?= (int)$qz['jml_attempt'] ?><?= (int)$qz['jml_koreksi'] ? ' <span class="badge badge-warning">' . (int)$qz['jml_koreksi'] . ' koreksi</span>' : '' ?></td>
          <td><?= number_format((float)$qz['rata'], 2) ?></td>
          <td><?= status_badge($qz['status']) ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="admin-soal.php?quiz=<?= (int)$qz['id'] ?>"><?= icon('list', 14) ?> Soal</a>
            <a class="btn btn-ghost btn-sm" href="guru-koreksi.php?quiz=<?= (int)$qz['id'] ?>"><?= icon('check', 14) ?></a>
            <a class="btn btn-ghost btn-sm" href="admin-quiz.php?edit=<?= (int)$qz['id'] ?>"><?= icon('edit', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="reset_attempt">
              <input type="hidden" name="id" value="<?= (int)$qz['id'] ?>">
              <button class="btn btn-ghost btn-sm" data-confirm="Hapus semua hasil pengerjaan quiz ini? Siswa bisa mengerjakan ulang dari nol." title="Reset hasil"><?= icon('refresh', 14) ?></button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$qz['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus quiz beserta hasilnya? Soal tetap ada di bank soal kursus (tidak lagi dimiliki quiz mana pun)."><?= icon('trash', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="11" class="muted">Belum ada quiz.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-quiz">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Quiz' : 'Quiz Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-grid">
        <div class="form-group">
          <label>Kursus</label>
          <select name="course_id">
            <option value="">- pilih kursus -</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($edit['course_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Pengajar</label>
          <select name="guru_id">
            <option value="">- pilih guru -</option>
            <?php foreach ($guruList as $g): ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)($edit['guru_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nama']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Judul *</label><input type="text" name="judul" value="<?= e($edit['judul'] ?? '') ?>" required></div>
      <div class="form-group"><label>Deskripsi</label><textarea name="deskripsi"><?= e($edit['deskripsi'] ?? '') ?></textarea></div>
      <?= form_pilih_materi_quiz($edit, $lessonsPerKursus, $judulKursus) ?>
      <div class="form-grid three">
        <div class="form-group">
          <label>Tipe</label>
          <select name="tipe">
            <option value="quiz" <?= ($edit['tipe'] ?? '') === 'quiz' ? 'selected' : '' ?>>Quiz</option>
            <option value="ujian" <?= ($edit['tipe'] ?? '') === 'ujian' ? 'selected' : '' ?>>Ujian</option>
          </select>
        </div>
        <div class="form-group"><label>Durasi (menit)</label><input type="number" name="durasi_menit" value="<?= (int)($edit['durasi_menit'] ?? 15) ?>" min="1"></div>
        <div class="form-group"><label>Passing grade</label><input type="number" name="passing_grade" value="<?= (int)($edit['passing_grade'] ?? 70) ?>" min="0" max="100"></div>
        <div class="form-group"><label>Jumlah soal tampil (0=semua)</label><input type="number" name="jumlah_soal_tampil" value="<?= (int)($edit['jumlah_soal_tampil'] ?? 0) ?>" min="0"></div>
        <div class="form-group"><label>Maks. percobaan (0=bebas)</label><input type="number" name="max_attempt" value="<?= (int)($edit['max_attempt'] ?? 0) ?>" min="0"></div>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <?php foreach (['aktif' => 'Aktif', 'draft' => 'Draft', 'nonaktif' => 'Nonaktif'] as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($edit['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row-gap mb3">
        <label class="check"><input type="checkbox" name="random_soal" value="1" <?= (int)($edit['random_soal'] ?? 1) ? 'checked' : '' ?>> Acak soal</label>
        <label class="check"><input type="checkbox" name="random_opsi" value="1" <?= (int)($edit['random_opsi'] ?? 1) ? 'checked' : '' ?>> Acak pilihan</label>
        <label class="check"><input type="checkbox" name="tampilkan_pembahasan" value="1" <?= (int)($edit['tampilkan_pembahasan'] ?? 1) ? 'checked' : '' ?>> Tampilkan pembahasan</label>
      </div>
      <div class="dark-box mb3">
        Soal quiz diambil dari <strong>bank soal kursus</strong> (bukan disimpan di quiz) dan setiap soal hanya dimiliki satu quiz. Jadikan soal sebagai milik quiz ini di
        <a href="admin-soal.php">Manajemen Bank Soal</a> — pilih manual atau ambil acak dari soal yang belum dimiliki quiz.
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
        <a class="btn btn-ghost" href="admin-quiz.php">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
