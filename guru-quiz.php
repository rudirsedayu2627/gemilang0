<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login(['guru', 'admin']);
$scope = $u['role'] === 'admin' ? '1=1' : ('co.guru_id = ' . (int)$u['id']);

$courses = db()->query("SELECT id, judul FROM courses co WHERE $scope ORDER BY judul")->fetchAll();
$kursusId = num('kursus', (int)($courses[0]['id'] ?? 0));

// Daftar materi (semua kursus milik guru/admin) untuk dropdown "tampilkan di
// dalam materi". Materi di luar kursus quiz ditolak saat validasi.
$lessonsGuru = db()->query("SELECT l.id, l.judul, l.course_id, co.judul AS kursus FROM lessons l
    JOIN courses co ON co.id = l.course_id WHERE $scope ORDER BY co.judul, l.urutan, l.id")->fetchAll();
$lessonsPerKursus = [];
foreach ($lessonsGuru as $ls) {
    $lessonsPerKursus[(int)$ls['course_id']][] = $ls;
}
$judulKursus = [];
foreach ($courses as $c) {
    $judulKursus[(int)$c['id']] = $c['judul'];
}
$lessonsKursus = $lessonsPerKursus[$kursusId] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    $cid = (int)post('course_id');
    $allowed = array_map('intval', array_column($courses, 'id'));
    if ($aksi === 'simpan' && in_array($cid, $allowed, true)) {
        $args = [
            $cid,
            post('judul'),
            post('deskripsi'),
            post('tipe', 'quiz'),
            max(1, (int)post('durasi_menit')),
            (int)post('passing_grade'),
            post('random_soal') ? 1 : 0,
            post('random_opsi') ? 1 : 0,
            (int)post('jumlah_soal_tampil'),
            post('tampilkan_pembahasan') ? 1 : 0,
            (int)post('max_attempt'),
            post('status', 'aktif'),
        ];
        // Materi induk quiz (opsional): quiz tampil di bawah isi materi itu.
        $args[] = quiz_lesson_id_valid($cid, (int)post('lesson_id'));
        if ($id > 0) {
            $args[] = $id;
            db()->prepare('UPDATE quizzes SET course_id=?, judul=?, deskripsi=?, tipe=?, durasi_menit=?, passing_grade=?, random_soal=?, random_opsi=?, jumlah_soal_tampil=?, tampilkan_pembahasan=?, max_attempt=?, status=?, lesson_id=? WHERE id=?')->execute($args);
            audit('edit_quiz', 'Memperbarui quiz ' . post('judul'));
            flash('Quiz diperbarui.');
        } else {
            $args[] = $u['id'];
            db()->prepare('INSERT INTO quizzes (course_id, judul, deskripsi, tipe, durasi_menit, passing_grade, random_soal, random_opsi, jumlah_soal_tampil, tampilkan_pembahasan, max_attempt, status, lesson_id, guru_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($args);
            $newId = (int)db()->lastInsertId();
            notify_course($cid, 'Quiz baru: ' . post('judul'), 'Quiz baru sudah tersedia di kursusmu.', 'kursus-detail.php?id=' . $cid);
            audit('tambah_quiz', 'Membuat quiz ' . post('judul'));
            flash('Quiz baru dibuat. Tambahkan soal sekarang.');
            redirect('guru-soal.php?quiz=' . $newId);
        }
    }
    if ($aksi === 'hapus' && $id > 0) {
        // helper: hapus hasil pengerjaan + lepas kepemilikan soal (soal tetap di bank soal)
        lms_hapus_turunan('quiz', $id);
        audit('hapus_quiz', 'Menghapus quiz id ' . $id);
        flash('Quiz dihapus. Soalnya tetap tersimpan di bank soal kursus dan tidak lagi dimiliki quiz mana pun.');
    }
    redirect('guru-quiz.php?kursus=' . ($cid ?: $kursusId));
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM quizzes WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$rows = db()->query("SELECT qz.*, co.judul AS kursus,
        (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = qz.id AND status != 'in_progress') AS jml_attempt
    FROM quizzes qz JOIN courses co ON co.id = qz.course_id
    WHERE " . ($kursusId ? 'qz.course_id = ' . (int)$kursusId : '0') . " ORDER BY qz.id DESC")->fetchAll();
$jmlSoalMap = quiz_jml_soal_map($kursusId);
$jmlBankKursus = $kursusId ? (int)db()->query('SELECT COUNT(*) FROM questions WHERE course_id = ' . (int)$kursusId)->fetchColumn() : 0;
$jmlBankDipakai = $kursusId ? (int)db()->query('SELECT COUNT(*) FROM questions q JOIN quizzes qz ON qz.id = q.quiz_id WHERE qz.course_id = ' . (int)$kursusId)->fetchColumn() : 0;

page_start('Quiz & Ujian', 'Atur timer, passing grade, randomisasi soal & pilihan, serta pembahasan. Soal diambil dari bank soal kursus, dan setiap soal hanya dimiliki satu quiz.', [
    'actions' => '<a class="btn btn-ghost" href="guru-soal.php?kursus=' . (int)$kursusId . '">' . icon('list', 17) . ' Bank Soal Kursus</a>'
        . '<button class="btn btn-primary" data-modal-open="modal-quiz">' . icon('plus', 17) . ' Quiz Baru</button>',
]);
?>
<div class="card">
  <form class="inline-form" style="align-items:flex-end">
    <div class="form-group" style="min-width:280px">
      <label>Kursus</label>
      <select name="kursus" data-autosubmit>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Bank soal kursus ini</label>
      <a class="btn btn-ghost" href="guru-soal.php?kursus=<?= (int)$kursusId ?>"><?= icon('list', 16) ?> Bank soal: <?= $jmlBankKursus ?> soal (<?= $jmlBankDipakai ?> dimiliki quiz)</a>
    </div>
  </form>
  <div class="form-hint">Soal disimpan di bank soal KURSUS dan setiap soal hanya boleh dimiliki SATU quiz — soal yang belum dimiliki quiz mana pun tetap ada di bank soal kursus.</div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Quiz</th><th>Tipe</th><th>Soal</th><th>Durasi</th><th>Min. Nilai</th><th>Random</th><th>Percobaan</th><th>Dikerjakan</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $qz): ?>
        <tr>
          <td>
            <strong><?= e($qz['judul']) ?></strong>
            <div class="muted small"><?= e(mb_strimwidth((string)$qz['deskripsi'], 0, 70, '...')) ?></div>
            <?php $induk = quiz_materi_induk($qz, $lessonsKursus); ?>
            <div class="muted small">
              <?php if ($induk): ?>
                <?= icon('file', 12) ?> Tampil di materi: <?= e($induk['judul']) ?><?= $induk['otomatis'] ? ' <em>(otomatis dari judul quiz)</em>' : '' ?>
              <?php else: ?>
                <?= icon('grid', 12) ?> Belum tampil di dalam materi
              <?php endif; ?>
            </div>
          </td>
          <td><span class="badge <?= $qz['tipe'] === 'ujian' ? 'badge-warning' : 'badge-info' ?>"><?= e($qz['tipe']) ?></span></td>
          <td><?= (int)($jmlSoalMap[(int)$qz['id']] ?? 0) ?></td>
          <td><?= (int)$qz['durasi_menit'] ?> mnt</td>
          <td><?= (int)$qz['passing_grade'] ?></td>
          <td class="small">
            <?= (int)$qz['random_soal'] ? 'soal ✓' : 'soal ✗' ?>
            <?= (int)$qz['random_opsi'] ? '<br>pilihan ✓' : '<br>pilihan ✗' ?>
            <?= (int)$qz['jumlah_soal_tampil'] ? '<br>' . (int)$qz['jumlah_soal_tampil'] . ' soal tampil' : '' ?>
          </td>
          <td><?= (int)$qz['max_attempt'] ? (int)$qz['max_attempt'] . 'x' : 'bebas' ?></td>
          <td><?= (int)$qz['jml_attempt'] ?></td>
          <td><?= status_badge($qz['status']) ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="guru-soal.php?quiz=<?= (int)$qz['id'] ?>"><?= icon('list', 14) ?> Soal</a>
            <a class="btn btn-ghost btn-sm" href="guru-koreksi.php?quiz=<?= (int)$qz['id'] ?>"><?= icon('check', 14) ?></a>
            <a class="btn btn-ghost btn-sm" href="guru-quiz.php?kursus=<?= (int)$kursusId ?>&edit=<?= (int)$qz['id'] ?>"><?= icon('edit', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$qz['id'] ?>">
              <input type="hidden" name="course_id" value="<?= (int)$kursusId ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus quiz ini beserta hasil pengerjaannya? Soal tetap ada di bank soal kursus (tidak lagi dimiliki quiz mana pun)."><?= icon('trash', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10" class="muted">Belum ada quiz pada kursus ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-quiz">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Quiz' : 'Quiz / Ujian Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-grid">
        <div class="form-group">
          <label>Kursus *</label>
          <select name="course_id" required>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($edit['course_id'] ?? $kursusId) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Tipe</label>
          <select name="tipe">
            <option value="quiz" <?= ($edit['tipe'] ?? '') === 'quiz' ? 'selected' : '' ?>>Quiz (latihan)</option>
            <option value="ujian" <?= ($edit['tipe'] ?? '') === 'ujian' ? 'selected' : '' ?>>Ujian</option>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Judul *</label><input type="text" name="judul" value="<?= e($edit['judul'] ?? '') ?>" required></div>
      <div class="form-group"><label>Deskripsi</label><textarea name="deskripsi"><?= e($edit['deskripsi'] ?? '') ?></textarea></div>
      <?= form_pilih_materi_quiz($edit, $lessonsPerKursus, $judulKursus) ?>
      <div class="form-grid three">
        <div class="form-group"><label>Durasi (menit) *</label><input type="number" name="durasi_menit" value="<?= (int)($edit['durasi_menit'] ?? 15) ?>" min="1" required></div>
        <div class="form-group"><label>Passing grade</label><input type="number" name="passing_grade" value="<?= (int)($edit['passing_grade'] ?? 70) ?>" min="0" max="100"></div>
        <div class="form-group"><label>Maks. percobaan (0 = bebas)</label><input type="number" name="max_attempt" value="<?= (int)($edit['max_attempt'] ?? 0) ?>" min="0"></div>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label>Jumlah soal ditampilkan (0 = semua)</label>
          <input type="number" name="jumlah_soal_tampil" value="<?= (int)($edit['jumlah_soal_tampil'] ?? 0) ?>" min="0">
          <div class="form-hint">Dipakai bersama randomisasi soal dari bank soal.</div>
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="aktif" <?= ($edit['status'] ?? '') === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="draft" <?= ($edit['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="nonaktif" <?= ($edit['status'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
          </select>
        </div>
      </div>
      <div class="row-gap mb3">
        <label class="check"><input type="checkbox" name="random_soal" value="1" <?= (int)($edit['random_soal'] ?? 1) ? 'checked' : '' ?>> Acak urutan soal</label>
        <label class="check"><input type="checkbox" name="random_opsi" value="1" <?= (int)($edit['random_opsi'] ?? 1) ? 'checked' : '' ?>> Acak urutan pilihan jawaban</label>
        <label class="check"><input type="checkbox" name="tampilkan_pembahasan" value="1" <?= (int)($edit['tampilkan_pembahasan'] ?? 1) ? 'checked' : '' ?>> Tampilkan pembahasan setelah dikerjakan</label>
      </div>
      <div class="dark-box mb3">
        Soal quiz diambil dari <strong>bank soal kursus</strong>, bukan disimpan di quiz ini. Setelah quiz disimpan, jadikan soal sebagai milik quiz ini di halaman
        <a href="guru-soal.php?kursus=<?= (int)$kursusId ?>">Bank Soal</a> — bisa dipilih manual atau diambil acak dari soal yang belum dimiliki quiz (mis. 20 dari 100 soal).
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Quiz</button>
        <a class="btn btn-ghost" href="guru-quiz.php?kursus=<?= (int)$kursusId ?>">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
