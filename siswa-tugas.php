<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aid = (int)post('assignment_id');
    $stmt = db()->prepare('SELECT a.* FROM assignments a JOIN enrollments e ON e.course_id = a.course_id AND e.user_id = ? WHERE a.id = ?');
    $stmt->execute([$u['id'], $aid]);
    $a = $stmt->fetch();
    if ($a) {
        $file = null;
        if (!empty($_FILES['file']['name'])) {
            try {
                $file = handle_upload('file');
            } catch (Throwable $e) {
                flash('Upload lampiran gagal: ' . $e->getMessage(), 'danger');
                redirect('siswa-tugas.php');
            }
        }
        $cek = db()->prepare('SELECT id FROM assignment_submissions WHERE assignment_id = ? AND user_id = ?');
        $cek->execute([$aid, $u['id']]);
        $existing = $cek->fetch();
        if ($existing) {
            if ($file) {
                db()->prepare('UPDATE assignment_submissions SET isi = ?, file_url = ?, submitted_at = CURRENT_TIMESTAMP, skor = NULL, feedback = NULL, dinilai_at = NULL WHERE id = ?')
                    ->execute([post('isi'), $file, $existing['id']]);
            } else {
                db()->prepare('UPDATE assignment_submissions SET isi = ?, submitted_at = CURRENT_TIMESTAMP, skor = NULL, feedback = NULL, dinilai_at = NULL WHERE id = ?')
                    ->execute([post('isi'), $existing['id']]);
            }
        } else {
            db()->prepare('INSERT INTO assignment_submissions (assignment_id, user_id, isi, file_url) VALUES (?, ?, ?, ?)')
                ->execute([$aid, $u['id'], post('isi'), $file]);
        }
        if ($a['guru_id']) {
            notify($a['guru_id'], 'Pengumpulan tugas baru', $u['nama'] . ' mengumpulkan tugas "' . $a['judul'] . '".', 'guru-tugas.php?tugas=' . $aid);
        }
        audit('kumpul_tugas', 'Mengumpulkan tugas ' . $a['judul']);
        flash('Tugas berhasil dikumpulkan.');
    }
    redirect('siswa-tugas.php');
}

$rows = db()->query('SELECT a.*, co.judul AS kursus, us.nama AS guru, s.id AS sub_id, s.isi, s.file_url, s.submitted_at, s.skor, s.feedback, s.dinilai_at
    FROM assignments a
    JOIN enrollments e ON e.course_id = a.course_id AND e.user_id = ' . (int)$u['id'] . " AND e.status IN ('aktif','selesai')" . '
    LEFT JOIN courses co ON co.id = a.course_id
    LEFT JOIN users us ON us.id = a.guru_id
    LEFT JOIN assignment_submissions s ON s.assignment_id = a.id AND s.user_id = ' . (int)$u['id'] . '
    ORDER BY (s.id IS NOT NULL), a.deadline')->fetchAll();

$belum = 0;
$dinilai = 0;
foreach ($rows as $r) {
    if (!$r['sub_id']) {
        $belum++;
    } elseif ($r['skor'] !== null) {
        $dinilai++;
    }
}

page_start('Tugas Saya', 'Kumpulkan tugas tepat waktu dan lihat feedback dari guru.', [
    'actions' => '<a class="btn btn-ghost" href="siswa-kursus.php">' . icon('book', 17) . ' Kursus Saya</a>',
]);
?>
<div class="grid cols-3 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('clipboard', 22) ?></div><div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lab">Total tugas</div></div></div>
  <div class="stat"><div class="stat-ic g6"><?= icon('bell', 22) ?></div><div><div class="stat-num"><?= $belum ?></div><div class="stat-lab">Belum dikumpulkan</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= $dinilai ?></div><div class="stat-lab">Sudah dinilai</div></div></div>
</div>

<?php if (!$rows): ?>
  <div class="card"><?= empty_state('Belum ada tugas pada kursus yang kamu ikuti.', 'clipboard') ?></div>
<?php endif; ?>

<div class="stack" style="gap:20px">
<?php foreach ($rows as $r):
    $lewat = $r['deadline'] && strtotime($r['deadline']) < time();
    ?>
  <div class="card">
    <div class="row-between mb2">
      <div>
        <div class="row-gap mb1">
          <span class="chip"><?= e($r['kursus']) ?></span>
          <?php if ($r['sub_id']): ?><span class="badge badge-info">Sudah dikumpulkan</span><?php else: ?><span class="badge <?= $lewat ? 'badge-danger' : 'badge-warning' ?>"><?= $lewat ? 'Terlambat' : 'Belum dikumpulkan' ?></span><?php endif; ?>
          <?php if ($r['skor'] !== null): ?><span class="badge badge-success">Nilai: <?= number_format((float)$r['skor'], 2) ?></span><?php endif; ?>
        </div>
        <h3 class="mt0 mb1"><?= e($r['judul']) ?></h3>
        <div class="muted small"><?= icon('clock', 13) ?> Deadline <?= e(tgl_id($r['deadline'], true)) ?> &middot; maks <?= (int)$r['max_skor'] ?> poin &middot; <?= icon('user', 13) ?> <?= e($r['guru'] ?: '-') ?></div>
      </div>
      <button class="btn btn-primary" data-modal-open="modal-tugas-<?= (int)$r['id'] ?>"><?= icon($r['sub_id'] ? 'edit' : 'upload', 17) ?> <?= $r['sub_id'] ? 'Kumpulkan Ulang' : 'Kumpulkan' ?></button>
    </div>
    <p class="muted"><?= nl2br(e($r['deskripsi'])) ?></p>
    <?php if ($r['lampiran']): ?><a class="btn btn-ghost btn-sm mb2" href="<?= e($r['lampiran']) ?>" target="_blank" rel="noopener"><?= icon('download', 15) ?> Lampiran dari guru</a><?php endif; ?>

    <?php if ($r['sub_id']): ?>
      <div class="dark-box">
        <strong>Jawabanmu</strong> <span class="muted small">(<?= e(tgl_id($r['submitted_at'], true)) ?>)</span>
        <div class="mt1"><?= nl2br(e($r['isi'] ?: '(tanpa teks)')) ?></div>
        <?php if ($r['file_url']): ?><div class="mt1"><a href="<?= e($r['file_url']) ?>" target="_blank" rel="noopener"><?= icon('file', 15) ?> Lihat lampiran saya</a></div><?php endif; ?>
      </div>
      <?php if ($r['feedback']): ?>
        <div class="flash flash-info mt2"><?= icon('chat', 17) ?><span><strong>Feedback guru<?= $r['dinilai_at'] ? ' (' . e(tgl_id($r['dinilai_at'])) . ')' : '' ?>:</strong> <?= e($r['feedback']) ?></span></div>
      <?php elseif ($r['skor'] === null): ?>
        <p class="muted small mt2 mb0">Menunggu penilaian guru.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="modal-back" id="modal-tugas-<?= (int)$r['id'] ?>">
    <div class="modal">
      <div class="modal-head">
        <h3 class="mt0">Kumpulkan: <?= e($r['judul']) ?></h3>
        <button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button>
      </div>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="assignment_id" value="<?= (int)$r['id'] ?>">
        <div class="form-group">
          <label>Jawaban / Catatan</label>
          <textarea name="isi" placeholder="Tulis jawabanmu di sini..."><?= e($r['isi']) ?></textarea>
        </div>
        <div class="form-group">
          <label>Lampiran (pdf, docx, gambar, audio, video)</label>
          <input type="file" name="file" accept=".pdf,.docx,.doc,.xlsx,.pptx,.csv,.txt,.zip,image/*,audio/*,video/*">
          <div class="form-hint">Maksimal 15MB (gambar/dokumen) atau 50MB (dokumen besar). Untuk video gunakan tautan pada kolom jawaban.</div>
        </div>
        <div class="row-gap">
          <button class="btn btn-primary" type="submit"><?= icon('upload', 17) ?> Kirim Tugas</button>
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php page_end(); ?>
