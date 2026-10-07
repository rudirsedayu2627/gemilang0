<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login('admin');

$courses = db()->query('SELECT id, judul FROM courses ORDER BY judul')->fetchAll();
$allowedCourses = array_map('intval', array_column($courses, 'id'));

/* Filter: kursus (0 = semua, -1 = tanpa kursus) dan quiz (0 = semua). */
$kursusFilter = num('kursus');
if ($kursusFilter !== 0 && $kursusFilter !== -1 && !in_array($kursusFilter, $allowedCourses, true)) {
    $kursusFilter = 0;
}
$quizList = db()->query('SELECT qz.id, qz.judul, qz.course_id, co.judul AS kursus FROM quizzes qz
    LEFT JOIN courses co ON co.id = qz.course_id ORDER BY co.judul, qz.judul')->fetchAll();
$allowedQuiz = array_map('intval', array_column($quizList, 'id'));
$quizFilter = num('quiz');
if ($quizFilter > 0 && !in_array($quizFilter, $allowedQuiz, true)) {
    $quizFilter = 0;
}
if ($quizFilter > 0 && $kursusFilter > 0) {
    foreach ($quizList as $qz) {
        if ((int)$qz['id'] === $quizFilter) {
            $kursusFilter = (int)$qz['course_id'];
        }
    }
}
$tipeFilter = get('tipe');
if (!in_array($tipeFilter, tipe_soal_valid(), true)) {
    $tipeFilter = '';
}
$baseQuery = 'kursus=' . $kursusFilter . '&quiz=' . $quizFilter . '&tipe=' . urlencode($tipeFilter);
$viewUrl = 'admin-soal.php?' . $baseQuery;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $targetQuiz = (int)post('target_quiz');
    $targetQuiz = in_array($targetQuiz, $allowedQuiz, true) ? $targetQuiz : 0;
    $judulQuiz = '';
    foreach ($quizList as $qz) {
        if ((int)$qz['id'] === $targetQuiz) {
            $judulQuiz = (string)$qz['judul'];
        }
    }

    if ($aksi === 'hapus' && $id > 0) {
        lms_hapus_turunan('question', $id);
        audit('hapus_soal', 'Admin menghapus soal bank soal id ' . $id);
        flash('Soal dihapus dari bank soal.');
    }

    if ($aksi === 'hapus_massal' && $ids) {
        $n = 0;
        foreach ($ids as $qid) {
            lms_hapus_turunan('question', (int)$qid);
            $n++;
        }
        flash($n . ' soal dihapus dari bank soal.');
        audit('hapus_soal_massal', 'Menghapus ' . $n . ' soal dari bank soal');
    }

    if ($aksi === 'tambah_ke_quiz' && $ids) {
        if (!$targetQuiz) {
            flash('Pilih quiz terlebih dahulu.', 'warning');
        } else {
            $n = quiz_assign_soal($targetQuiz, $ids);
            flash($n . ' soal sekarang dimiliki quiz "' . $judulQuiz . '".' . ($n < count($ids) ? ' Soal dari kursus lain atau yang sudah dimiliki quiz itu dilewati.' : ''));
            audit('tambah_soal_quiz', 'Admin menjadikan ' . $n . ' soal milik quiz ' . $judulQuiz);
        }
    }

    if ($aksi === 'keluarkan_dari_quiz' && $ids) {
        if (!$targetQuiz) {
            flash('Pilih quiz terlebih dahulu.', 'warning');
        } else {
            $n = quiz_unassign_soal($targetQuiz, $ids);
            flash($n . ' soal dilepas dari quiz "' . $judulQuiz . '" dan kembali ke bank soal kursusnya.');
            audit('lepas_soal_quiz', 'Admin melepas ' . $n . ' soal dari quiz ' . $judulQuiz);
        }
    }

    if ($aksi === 'pindah_kursus' && $ids) {
        $targetKursus = (int)post('target_kursus');
        if (!in_array($targetKursus, $allowedCourses, true)) {
            flash('Pilih kursus tujuan terlebih dahulu.', 'warning');
        } else {
            $upd = db()->prepare('UPDATE questions SET course_id = ?, quiz_id = NULL WHERE id = ?');
            $n = 0;
            foreach ($ids as $qid) {
                $upd->execute([$targetKursus, $qid]);
                $n++;
            }
            $nama = '';
            foreach ($courses as $c) {
                if ((int)$c['id'] === $targetKursus) {
                    $nama = (string)$c['judul'];
                }
            }
            flash($n . ' soal dipindahkan ke bank soal kursus "' . $nama . '" dan dilepas dari quiz lamanya (soal tetap ada).');
            audit('pindah_soal_kursus', 'Admin memindahkan ' . $n . ' soal ke kursus #' . $targetKursus);
        }
    }

    if ($aksi === 'acak') {
        $jumlah = (int)post('jumlah_acak');
        if (!$targetQuiz) {
            flash('Pilih quiz tujuan untuk pengambilan soal acak.', 'warning');
        } elseif ($jumlah < 1) {
            flash('Jumlah soal acak minimal 1.', 'warning');
        } else {
            $n = quiz_ambil_soal_acak($targetQuiz, $jumlah);
            if ($n > 0) {
                flash($n . ' soal acak dari bank kursus sekarang dimiliki quiz "' . $judulQuiz . '".');
                audit('ambil_soal_acak', 'Admin menjadikan ' . $n . ' soal acak milik quiz ' . $judulQuiz);
            } else {
                flash('Tidak ada soal bebas yang bisa diambil — semua soal bank kursus itu sudah dimiliki quiz.', 'warning');
            }
        }
    }

    if ($aksi === 'import') {
        try {
            $quizId = (int)post('quiz_id');
            $courseId = (int)post('course_id');
            $quiz = null;
            if ($quizId > 0 && in_array($quizId, $allowedQuiz, true)) {
                $s = db()->prepare('SELECT qz.*, co.judul AS kursus FROM quizzes qz LEFT JOIN courses co ON co.id = qz.course_id WHERE qz.id = ?');
                $s->execute([$quizId]);
                $quiz = $s->fetch() ?: null;
                if ($quiz) {
                    $courseId = (int)$quiz['course_id'];
                }
            }
            if ($courseId <= 0 || !in_array($courseId, $allowedCourses, true)) {
                throw new RuntimeException('Pilih kursus tujuan (bank soal dimiliki kursus) atau pilih quiz pemilik soalnya.');
            }
            if (empty($_FILES['file']['name'])) {
                throw new RuntimeException('Pilih berkas Excel/CSV.');
            }
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $rows = $ext === 'csv' ? csv_read($_FILES['file']['tmp_name']) : xlsx_read($_FILES['file']['tmp_name']);
            $n = 0;
            $lewati = [];
            $gambarTolak = [];
            $ins = db()->prepare('INSERT INTO questions (course_id, quiz_id, tipe, pertanyaan, opsi, jawaban, pembahasan, skor, penilaian, opsi_kategori, gambar, opsi_gambar) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $i => $r) {
                if ($i === 0) {
                    continue; // header
                }
                $hasil = soal_from_row($r);
                if (!$hasil['ok']) {
                    $lewati[] = 'baris ' . ($i + 1) . ' (' . $hasil['alasan'] . ')';
                    continue;
                }
                $d = $hasil['data'];
                if (!empty($d['peringatan'])) {
                    $gambarTolak[] = 'baris ' . ($i + 1) . ' ' . implode(', ', $d['peringatan']);
                }
                $ins->execute([$courseId, $quiz ? (int)$quiz['id'] : null, $d['tipe'], $d['pertanyaan'], $d['opsi'], $d['jawaban'], $d['pembahasan'], $d['skor'], $d['penilaian'], $d['opsi_kategori'], $d['gambar'] ?: null, $d['opsi_gambar']]);
                $n++;
            }
            $tujuan = $quiz ? ('quiz "' . $quiz['judul'] . '"') : ('bank soal kursus #' . $courseId);
            $lapor = soal_import_pesan($n, $tujuan, $lewati, $gambarTolak);
            flash($lapor['pesan'], $lapor['tipe']);
            audit('import_soal', 'Admin import ' . $n . ' soal ke ' . $tujuan);
        } catch (Throwable $e) {
            flash('Import gagal: ' . $e->getMessage(), 'danger');
        }
    }

    redirect($viewUrl);
}

if (get('template') === 'soal') {
    xlsx_download('template-bank-soal.xlsx', xlsx_template_soal());
}

$where = [];
$args = [];
if ($kursusFilter === -1) {
    $where[] = '(q.course_id IS NULL OR q.course_id = 0)';
} elseif ($kursusFilter > 0) {
    $where[] = 'q.course_id = ?';
    $args[] = $kursusFilter;
}
if ($quizFilter > 0) {
    $where[] = 'q.quiz_id = ' . $quizFilter;
}
if ($tipeFilter !== '') {
    $where[] = 'q.tipe = ?';
    $args[] = $tipeFilter;
}
$w = $where ? implode(' AND ', $where) : '1=1';

if (get('export') === 'xlsx') {
    $rows = [['KURSUS', 'TIPE', 'PERTANYAAN', 'A', 'B', 'C', 'D', 'E', 'JAWABAN', 'PEMBAHASAN', 'SKOR', 'PENILAIAN', 'GAMBAR', 'GAMBAR_A', 'GAMBAR_B', 'GAMBAR_C', 'GAMBAR_D', 'GAMBAR_E']];
    $st = db()->prepare("SELECT q.*, co.judul AS kursus FROM questions q LEFT JOIN courses co ON co.id = q.course_id WHERE $w ORDER BY q.course_id, q.id");
    $st->execute($args);
    foreach ($st->fetchAll() as $q) {
        $rows[] = array_merge([$q['kursus'] ?: '(tanpa kursus)'], soal_ke_baris($q));
    }
    audit('export_soal', 'Export bank soal (filter kursus=' . $kursusFilter . ', quiz=' . $quizFilter . ')');
    xlsx_download('bank-soal-' . date('Ymd') . '.xlsx', $rows);
}

$page = max(1, num('page', 1));
$cs = db()->prepare("SELECT COUNT(*) FROM questions q WHERE $w");
$cs->execute($args);
$p = paginate((int)$cs->fetchColumn(), 25, $page);
$st = db()->prepare("SELECT q.*, co.judul AS kursus,
        (SELECT COUNT(*) FROM attempt_answers a WHERE a.question_id = q.id AND a.jawaban IS NOT NULL AND a.jawaban <> '') AS jml_jawab,
        (SELECT COUNT(*) FROM attempt_answers a WHERE a.question_id = q.id AND a.benar = 1) AS jml_benar
    FROM questions q LEFT JOIN courses co ON co.id = q.course_id
    WHERE $w ORDER BY q.course_id, q.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}");
$st->execute($args);
$rows = $st->fetchAll();

$pakaiMap = [];
if ($rows) {
    $in = implode(',', array_map('intval', array_column($rows, 'id')));
    foreach (db()->query("SELECT q.id AS question_id, qz.id, qz.judul FROM questions q
        JOIN quizzes qz ON qz.id = q.quiz_id WHERE q.id IN ($in) ORDER BY qz.judul")->fetchAll() as $r) {
        $pakaiMap[(int)$r['question_id']][] = ['id' => (int)$r['id'], 'judul' => $r['judul']];
    }
}
$perTipe = [];
$sqlTipe = 'SELECT tipe, COUNT(*) AS jml FROM questions q WHERE ' . ($kursusFilter === -1
    ? '(q.course_id IS NULL OR q.course_id = 0)'
    : ($kursusFilter > 0 ? ('q.course_id = ' . $kursusFilter) : '1=1')) . ' GROUP BY tipe';
foreach (db()->query($sqlTipe)->fetchAll() as $t) {
    $perTipe[$t['tipe']] = (int)$t['jml'];
}
$jmlBankFilter = array_sum($perTipe);

page_start('Manajemen Bank Soal', $jmlBankFilter . ' soal pada filter ini (soal dimiliki kursus dan hanya boleh dimiliki satu quiz).', [
    'actions' => '<a class="btn btn-ghost" href="admin-soal.php?template=soal">' . icon('download', 17) . ' Template</a>'
        . '<a class="btn btn-ghost" href="' . e($viewUrl) . '&export=xlsx">' . icon('download', 17) . ' Export</a>'
        . '<button class="btn btn-primary" data-modal-open="modal-import">' . icon('upload', 17) . ' Import Soal</button>',
]);
?>
<div class="grid cols-5 mb3">
  <?php $tipeStat = ['mc' => 'Pilihan Ganda', 'mcma' => 'PG Kompleks MCMA', 'tf' => 'Benar/Salah', 'essay' => 'Essay', 'matching' => 'Matching', 'fill' => 'Fill Blank', 'kategori' => 'PG Kompleks Kategori'];
  $no = 0;
  foreach ($tipeStat as $k => $v):
      $no++;
      $jml = $perTipe[$k] ?? 0; ?>
    <div class="stat"><div class="stat-ic g<?= (($no - 1) % 6) + 1 ?>"><?= icon('list', 20) ?></div><div><div class="stat-num"><?= $jml ?></div><div class="stat-lab"><?= $v ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="card">
  <form class="toolbar" method="get" style="align-items:flex-end">
    <div class="form-group" style="min-width:220px">
      <label>Kursus</label>
      <select name="kursus" data-autosubmit>
        <option value="0" <?= $kursusFilter === 0 ? 'selected' : '' ?>>Semua kursus</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $kursusFilter === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
        <option value="-1" <?= $kursusFilter === -1 ? 'selected' : '' ?>>Tanpa kursus</option>
      </select>
    </div>
    <div class="form-group" style="min-width:260px">
      <label>Quiz</label>
      <select name="quiz" data-autosubmit>
        <option value="0">Semua quiz</option>
        <?php foreach ($quizList as $q): ?>
          <?php if ($kursusFilter > 0 && (int)$q['course_id'] !== $kursusFilter) { continue; } ?>
          <option value="<?= (int)$q['id'] ?>" <?= $quizFilter === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['kursus'] ?: '(tanpa kursus)') ?> — <?= e($q['judul']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint">Memilih quiz = hanya soal yang dimiliki quiz tersebut.</div>
    </div>
    <div class="form-group">
      <label>Tipe soal</label>
      <select name="tipe" data-autosubmit>
        <option value="">Semua tipe</option>
        <?php foreach (tipe_soal_valid() as $t): ?>
          <option value="<?= $t ?>" <?= $tipeFilter === $t ? 'selected' : '' ?>><?= e(tipe_soal_label($t)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1;min-width:160px">
      <label>Cari soal</label>
      <input type="search" data-filter-input="#tbl-soal" placeholder="Kata kunci pertanyaan...">
    </div>
    <a class="btn btn-ghost" href="admin-soal.php">Reset</a>
  </form>
</div>

<?php if ($quizList): ?>
<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="acak">
    <div class="toolbar" style="align-items:flex-end">
      <div class="form-group" style="min-width:220px">
        <label>Ambil soal ACAK dari bank kursus quiz</label>
        <input type="number" name="jumlah_acak" min="1" value="10" style="max-width:120px">
      </div>
      <div class="form-group" style="min-width:260px">
        <label>Tambahkan ke quiz</label>
        <select name="target_quiz">
          <?php foreach ($quizList as $q): ?>
            <option value="<?= (int)$q['id'] ?>" <?= $quizFilter === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['kursus'] ?: '(tanpa kursus)') ?> — <?= e($q['judul']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <button class="btn btn-primary btn-sm"><?= icon('shuffle', 15) ?> Ambil &amp; tambahkan</button>
      </div>
    </div>
    <div class="form-hint">Soal yang diambil selalu berasal dari kursus milik quiz tujuan dan hanya yang belum dimiliki quiz mana pun (soal milik quiz lain tidak dirampas). Butuh set soal berbeda tiap siswa? Tambahkan soal yang cukup banyak lalu atur &ldquo;acak urutan soal&rdquo; + &ldquo;jumlah soal tampil&rdquo; di pengaturan quiz.</div>
  </form>
</div>
<?php endif; ?>

<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="toolbar" style="align-items:flex-end">
    <?php if ($quizList): ?>
    <div class="form-group" style="min-width:280px">
      <label>Quiz (aksi massal soal yang ditandai)</label>
      <div class="row-gap">
        <select name="target_quiz" style="min-width:190px">
          <option value="">- pilih quiz -</option>
          <?php foreach ($quizList as $q): ?>
            <option value="<?= (int)$q['id'] ?>"><?= e($q['kursus'] ?: '(tanpa kursus)') ?> — <?= e($q['judul']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" name="aksi" value="tambah_ke_quiz" data-confirm="Jadikan soal yang ditandai milik quiz ini? Bila soal masih milik quiz lain di kursus yang sama, kepemilikannya berpindah."><?= icon('plus', 14) ?> Jadikan milik quiz</button>
        <button class="btn btn-ghost btn-sm" name="aksi" value="keluarkan_dari_quiz" data-confirm="Lepas soal yang ditandai dari quiz ini? Soal tetap ada di bank soal kursus."><?= icon('x', 14) ?> Lepas dari quiz</button>
      </div>
    </div>
    <?php endif; ?>
    <div class="form-group">
      <label>Pindah kursus</label>
      <div class="row-gap">
        <select name="target_kursus" style="min-width:170px">
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['judul']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" name="aksi" value="pindah_kursus" data-confirm="Pindahkan soal yang ditandai ke kursus lain? Soal akan dilepas dari quiznya (soalnya tetap ada)."><?= icon('arrow-right', 14) ?> Pindahkan</button>
      </div>
    </div>
    <div class="form-group">
      <label>Hapus</label>
      <button class="btn btn-danger btn-sm" name="aksi" value="hapus_massal" data-confirm="Hapus semua soal yang ditandai dari bank soal?"><?= icon('trash', 15) ?> Hapus yang ditandai</button>
    </div>
    <span class="muted small">Total <?= $p['total'] ?> soal pada filter ini</span>
  </div>
  <div class="table-wrap">
    <table class="data" id="tbl-soal">
      <thead><tr><th style="width:34px"><input type="checkbox" onclick="document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked)"></th><th>Soal</th><th>Tipe</th><th>Kursus</th><th>Milik quiz</th><th>Skor</th><th>Statistik Jawaban</th><th>ID</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $q):
          $opsi = $q['opsi'] ? json_decode($q['opsi'], true) : [];
          $persen = (int)$q['jml_jawab'] ? round((int)$q['jml_benar'] / (int)$q['jml_jawab'] * 100) : 0;
          $pakai = $pakaiMap[(int)$q['id']] ?? []; ?>
        <tr>
          <td><input type="checkbox" class="chk" name="ids[]" value="<?= (int)$q['id'] ?>"></td>
          <td style="max-width:340px">
            <?php $gSoal = soal_gambar($q); $gOpsi = soal_gambar_opsi($q); ?>
            <?php if ($gSoal): ?><?= gambar_img($gSoal, 'thumb-soal', 'Gambar soal') ?> <?php endif; ?>
            <strong><?= e(mb_strimwidth($q['pertanyaan'], 0, 130, '...')) ?></strong>
            <?php if ($gOpsi): ?><div class="thumb-flag"><?= icon('image', 13) ?> <?= count($gOpsi) ?> gambar pilihan</div><?php endif; ?>
            <?php if ($opsi): ?><div class="muted small"><?= e(implode(' · ', array_slice(array_map(function ($x) { return mb_strimwidth((string)$x, 0, 24, ''); }, $opsi), 0, 5))) ?></div><?php endif; ?>
          </td>
          <td><span class="badge badge-info"><?= e(tipe_soal_label($q['tipe'])) ?></span></td>
          <td class="small"><?= e($q['kursus'] ?: '(tanpa kursus)') ?></td>
          <td class="small">
            <?php if ($pakai): ?>
              <?php foreach ($pakai as $pp): ?>
                <a class="badge badge-info" href="admin-soal.php?quiz=<?= (int)$pp['id'] ?>"><?= e(mb_strimwidth($pp['judul'], 0, 22, '...')) ?></a>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="muted small">belum dimiliki quiz</span>
            <?php endif; ?>
          </td>
          <td><?= (float)$q['skor'] ?></td>
          <td class="small"><?= (int)$q['jml_jawab'] ?>x dijawab<?= (int)$q['jml_jawab'] ? '<div class="muted small">' . $persen . '% benar</div>' : '' ?></td>
          <td class="muted small">#<?= (int)$q['id'] ?></td>
          <td>
            <button class="btn btn-danger btn-sm" name="aksi" value="hapus" formnovalidate onclick="document.getElementById('hapus-id').value='<?= (int)$q['id'] ?>'" data-confirm="Hapus soal ini?"><?= icon('trash', 14) ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9" class="muted">Tidak ada soal pada filter ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <input type="hidden" name="id" id="hapus-id" value="0">
  <?= pager_html($p, 'admin-soal.php?' . $baseQuery) ?>
</form>

<div class="modal-back" id="modal-import">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0">Import Bank Soal</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="import">
      <div class="form-grid">
        <div class="form-group">
          <label>Kursus tujuan (bank soal) *</label>
          <select name="course_id">
            <option value="">- pilih kursus -</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $kursusFilter === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Langsung dimiliki quiz (opsional)</label>
          <select name="quiz_id">
            <option value="0">- hanya masuk bank soal kursus -</option>
            <?php foreach ($quizList as $q): ?>
              <option value="<?= (int)$q['id'] ?>"><?= e($q['kursus'] ?: '(tanpa kursus)') ?> — <?= e($q['judul']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">Bila quiz dipilih, kursus tujuan otomatis mengikuti kursus quiz itu (satu soal hanya boleh dimiliki satu quiz).</div>
        </div>
      </div>
      <div class="form-group">
        <label>Berkas .xlsx / .csv *</label>
        <input type="file" name="file" accept=".xlsx,.xls,.csv" required>
      </div>
      <div class="dark-box mb3">
        <strong>Format kolom:</strong> TIPE, PERTANYAAN, A, B, C, D, E, JAWABAN, PEMBAHASAN, SKOR, PENILAIAN, GAMBAR, GAMBAR_A…E<br>
        <span class="muted">TIPE: mc / mcma / kategori / tf / essay / matching / fill.<br>
        <strong>mcma</strong> → JAWABAN ditulis huruf dipisah koma, mis. <code>A,C,E</code>; PENILAIAN boleh <code>parsial</code>.<br>
        <strong>kategori</strong> → kolom A–E = nama kategori, JAWABAN = <code>Item=HurufKategori</code> dipisah <code>;</code>, mis. <code>Apple=A;Carrot=B</code>.<br>
        <strong>gambar</strong> (opsional) → GAMBAR = URL gambar pertanyaan, GAMBAR_A…E = URL gambar tiap pilihan. Kolomnya boleh dikosongkan.<br>
        ⚠ Kolom gambar hanya bisa diisi <strong>URL</strong> (diawali <code>http://</code> atau <code>https://</code>) — mis. URL hasil tombol <strong>Upload</strong> di form soal. Berkas gambar di komputer kamu <strong>tidak bisa dibaca langsung</strong> dari Excel; kalau diisi path seperti <code>C:\...</code> atau nama berkas lokal, isinya diabaikan dan akan kami beri tahu saat import.</span>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('upload', 17) ?> Import</button>
        <a class="btn btn-ghost" href="admin-soal.php?template=soal"><?= icon('download', 16) ?> Unduh Template</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
