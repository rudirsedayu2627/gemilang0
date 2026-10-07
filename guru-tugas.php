<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login(['guru', 'admin']);
$courses = db()->query("SELECT id, judul FROM courses WHERE " . ($u['role'] === 'admin' ? '1=1' : ('guru_id = ' . (int)$u['id'])) . ' ORDER BY judul')->fetchAll();
$allowed = array_map('intval', array_column($courses, 'id'));
$kursusId = num('kursus', (int)($courses[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'simpan' && in_array((int)post('course_id'), $allowed, true)) {
        $lampiran = post('lampiran_url');
        if (!empty($_FILES['lampiran']['name'])) {
            try {
                $lampiran = handle_upload('lampiran');
            } catch (Throwable $e) {
                flash('Upload lampiran gagal: ' . $e->getMessage(), 'danger');
            }
        }
        $deadline = post('deadline') !== '' ? str_replace('T', ' ', post('deadline')) . ':00' : null;
        $args = [post('course_id'), $u['id'], post('judul'), post('deskripsi'), $deadline, (float)post('max_skor', '100'), $lampiran];
        if ($id > 0) {
            $args[] = $id;
            db()->prepare('UPDATE assignments SET course_id=?, guru_id=?, judul=?, deskripsi=?, deadline=?, max_skor=?, lampiran=? WHERE id=?')->execute($args);
            flash('Tugas diperbarui.');
        } else {
            db()->prepare('INSERT INTO assignments (course_id, guru_id, judul, deskripsi, deadline, max_skor, lampiran) VALUES (?,?,?,?,?,?,?)')->execute($args);
            notify_course((int)post('course_id'), 'Tugas baru: ' . post('judul'), 'Deadline ' . tgl_id($deadline, true) . '. Segera kerjakan!', 'siswa-tugas.php');
            flash('Tugas baru dibuat dan siswa sudah dinotifikasi.');
        }
        audit('simpan_tugas', 'Menyimpan tugas ' . post('judul'));
    }
    if ($aksi === 'hapus') {
        lms_hapus_turunan('assignment', $id);
        flash('Tugas dihapus.');
    }
    if ($aksi === 'nilai') {
        $subId = (int)post('submission_id');
        db()->prepare('UPDATE assignment_submissions SET skor = ?, feedback = ?, dinilai_at = CURRENT_TIMESTAMP, dinilai_oleh = ? WHERE id = ?')
            ->execute([(float)post('skor'), post('feedback'), $u['id'], $subId]);
        $s = db()->query('SELECT s.user_id, a.judul FROM assignment_submissions s JOIN assignments a ON a.id = s.assignment_id WHERE s.id = ' . $subId)->fetch();
        if ($s) {
            notify($s['user_id'], 'Tugas sudah dinilai', 'Tugas "' . $s['judul'] . '" dinilai ' . post('skor') . ' poin. Lihat feedback guru.', 'siswa-tugas.php');
        }
        audit('nilai_tugas', 'Menilai pengumpulan tugas ' . $subId);
        flash('Nilai & feedback tersimpan.');
    }
    redirect('guru-tugas.php?kursus=' . $kursusId . ($id ? '&tugas=' . $id : ''));
}

$edit = null;
if (num('edit') > 0) {
    $s = db()->prepare('SELECT * FROM assignments WHERE id = ?');
    $s->execute([num('edit')]);
    $edit = $s->fetch();
}
$tugasList = $kursusId ? db()->query("SELECT a.*, co.judul AS kursus,
        (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) AS jml_kumpul,
        (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND skor IS NOT NULL) AS jml_dinilai,
        (SELECT COUNT(*) FROM enrollments WHERE course_id = a.course_id) AS jml_siswa
    FROM assignments a LEFT JOIN courses co ON co.id = a.course_id WHERE a.course_id = $kursusId ORDER BY a.deadline")->fetchAll() : [];
$tugasId = num('tugas');
$pengumpulan = $tugasId ? db()->query("SELECT s.*, us.nama, us.email, us.foto FROM assignment_submissions s JOIN users us ON us.id = s.user_id
    WHERE s.assignment_id = $tugasId ORDER BY (s.skor IS NULL) DESC, s.submitted_at")->fetchAll() : [];
$tugasAktif = null;
foreach ($tugasList as $t) {
    if ((int)$t['id'] === $tugasId) {
        $tugasAktif = $t;
    }
}
$belumKumpul = [];
if ($tugasAktif) {
    $belumKumpul = db()->query("SELECT us.nama, us.email FROM enrollments e JOIN users us ON us.id = e.user_id
        WHERE e.course_id = " . (int)$tugasAktif['course_id'] . ' AND us.id NOT IN (SELECT user_id FROM assignment_submissions WHERE assignment_id = ' . (int)$tugasAktif['id'] . ') ORDER BY us.nama')->fetchAll();
}

page_start('Kelola Tugas', 'Buat tugas, pantau pengumpulan, beri nilai dan feedback.', [
    'actions' => '<button class="btn btn-primary" data-modal-open="modal-tugas">' . icon('plus', 17) . ' Tugas Baru</button>',
]);
?>
<div class="card">
  <form class="inline-form">
    <div class="form-group" style="min-width:280px">
      <label>Kursus</label>
      <select name="kursus" data-autosubmit>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<div class="grid cols-2 mb3">
  <?php foreach ($tugasList as $t): ?>
    <div class="card tight">
      <div class="row-between mb1">
        <h3 class="mt0 mb0" style="font-size:1rem"><?= e($t['judul']) ?></h3>
        <?php if ($t['deadline'] && strtotime($t['deadline']) < time()): ?><span class="badge badge-danger">Lewat deadline</span><?php else: ?><span class="badge badge-info">Aktif</span><?php endif; ?>
      </div>
      <div class="muted small mb2"><?= icon('clock', 13) ?> <?= e($t['deadline'] ? tgl_id($t['deadline'], true) : 'tanpa deadline') ?> &middot; maks <?= (int)$t['max_skor'] ?> poin</div>
      <div class="progress-lab"><span>Dikumpulkan <?= (int)$t['jml_kumpul'] ?>/<?= (int)$t['jml_siswa'] ?> &middot; dinilai <?= (int)$t['jml_dinilai'] ?></span></div>
      <div class="progress"><span style="width:<?= (int)$t['jml_siswa'] ? round((int)$t['jml_kumpul'] / (int)$t['jml_siswa'] * 100) : 0 ?>%"></span></div>
      <div class="row-gap mt3">
        <a class="btn btn-primary btn-sm" href="guru-tugas.php?kursus=<?= (int)$kursusId ?>&tugas=<?= (int)$t['id'] ?>">Lihat Pengumpulan</a>
        <a class="btn btn-ghost btn-sm" href="guru-tugas.php?kursus=<?= (int)$kursusId ?>&edit=<?= (int)$t['id'] ?>"><?= icon('edit', 14) ?></a>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="hapus">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="btn btn-danger btn-sm" data-confirm="Hapus tugas beserta pengumpulannya?"><?= icon('trash', 14) ?></button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$tugasList): ?><div class="card"><?= empty_state('Belum ada tugas pada kursus ini.', 'clipboard') ?></div><?php endif; ?>
</div>

<?php if ($tugasAktif): ?>
<div class="card">
  <div class="card-head">
    <div>
      <h2 class="mt0 mb0"><?= icon('clipboard', 20) ?> Pengumpulan: <?= e($tugasAktif['judul']) ?></h2>
      <div class="muted small"><?= count($pengumpulan) ?> dari <?= (int)$tugasAktif['jml_siswa'] ?> siswa mengumpulkan</div>
    </div>
    <a class="btn btn-ghost" href="guru-tugas.php?kursus=<?= (int)$kursusId ?>">Tutup</a>
  </div>

  <?php foreach ($pengumpulan as $s): ?>
    <div class="q-block">
      <div class="row-between mb2">
        <div class="row-gap">
          <span class="avatar sm" <?= $s['foto'] ? 'style="background-image:url(' . e($s['foto']) . ')"' : '' ?>><?= $s['foto'] ? '' : e(strtoupper(mb_substr($s['nama'], 0, 1))) ?></span>
          <div><strong><?= e($s['nama']) ?></strong><div class="muted small"><?= e($s['email']) ?> &middot; dikumpulkan <?= e(tgl_id($s['submitted_at'], true)) ?></div></div>
        </div>
        <?= $s['skor'] !== null ? '<span class="badge badge-success">Nilai ' . number_format((float)$s['skor'], 2) . '</span>' : '<span class="badge badge-warning">Belum dinilai</span>' ?>
      </div>
      <div class="dark-box mb2"><?= nl2br(e((string)$s['isi'] ?: '(tanpa teks)')) ?>
        <?php if ($s['file_url']): ?><div class="mt1"><a href="<?= e($s['file_url']) ?>" target="_blank" rel="noopener"><?= icon('file', 15) ?> Buka lampiran siswa</a></div><?php endif; ?>
      </div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="nilai">
        <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
        <div class="form-grid">
          <div class="form-group">
            <label>Skor (maks <?= (int)$tugasAktif['max_skor'] ?>)</label>
            <input type="number" name="skor" step="0.5" min="0" max="<?= (int)$tugasAktif['max_skor'] ?>" value="<?= e((string)($s['skor'] ?? '')) ?>" required>
          </div>
          <div class="form-group">
            <label>Feedback</label>
            <input type="text" name="feedback" value="<?= e((string)$s['feedback']) ?>" placeholder="Mis. Struktur kalimat sudah bagus, perbaiki ejaan.">
          </div>
        </div>
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('check', 15) ?> Simpan Nilai</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$pengumpulan): ?><p class="muted small">Belum ada siswa yang mengumpulkan tugas ini.</p><?php endif; ?>

  <?php if ($belumKumpul): ?>
    <div class="divider"></div>
    <h3><?= icon('bell', 18) ?> Belum mengumpulkan (<?= count($belumKumpul) ?>)</h3>
    <div class="row-gap">
      <?php foreach ($belumKumpul as $b): ?><span class="chip"><?= e($b['nama']) ?></span><?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="modal-back <?= $edit ? 'open' : '' ?>" id="modal-tugas">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $edit ? 'Ubah Tugas' : 'Tugas Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="form-group">
        <label>Kursus *</label>
        <select name="course_id" required>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)($edit['course_id'] ?? $kursusId) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Judul tugas *</label><input type="text" name="judul" value="<?= e($edit['judul'] ?? '') ?>" required></div>
      <div class="form-group"><label>Instruksi</label><textarea name="deskripsi"><?= e($edit['deskripsi'] ?? '') ?></textarea></div>
      <div class="form-grid">
        <div class="form-group"><label>Deadline</label><input type="datetime-local" name="deadline" value="<?= e(!empty($edit['deadline']) ? date('Y-m-d\TH:i', strtotime($edit['deadline'])) : '') ?>"></div>
        <div class="form-group"><label>Skor maksimal</label><input type="number" name="max_skor" value="<?= (int)($edit['max_skor'] ?? 100) ?>" min="1"></div>
      </div>
      <div class="form-group">
        <label>Lampiran (opsional)</label>
        <input type="file" name="lampiran" accept=".pdf,.docx,.doc,.xlsx,.pptx,.csv,.txt,.zip,image/*">
        <input type="url" name="lampiran_url" class="mt1" value="<?= e($edit['lampiran'] ?? '') ?>" placeholder="atau tempel URL lampiran">
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Tugas</button>
        <a class="btn btn-ghost" href="guru-tugas.php?kursus=<?= (int)$kursusId ?>">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
