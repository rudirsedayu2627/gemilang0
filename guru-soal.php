<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login(['guru', 'admin']);
$isAdmin = $u['role'] === 'admin';

/* Bank soal dimiliki KURSUS dan setiap soal hanya boleh dimiliki SATU quiz.
   Halaman ini bisa dibuka dalam dua konteks:
   - guru-soal.php?kursus=N  -> seluruh bank soal kursus tersebut (termasuk soal
                                yang belum dimiliki quiz mana pun)
   - guru-soal.php?quiz=N    -> hanya soal milik quiz N (kursusnya diturunkan) */
$courses = db()->query('SELECT id, judul FROM courses' . ($isAdmin ? '' : (' WHERE guru_id = ' . (int)$u['id'])) . ' ORDER BY judul')->fetchAll();
$allowedCourses = array_map('intval', array_column($courses, 'id'));

$quizId = num('quiz');
$ctxQuiz = null;
if ($quizId > 0) {
    $s = db()->prepare('SELECT qz.id, qz.judul, qz.course_id FROM quizzes qz WHERE qz.id = ?');
    $s->execute([$quizId]);
    $ctxQuiz = $s->fetch() ?: null;
}
if ($ctxQuiz && in_array((int)$ctxQuiz['course_id'], $allowedCourses, true)) {
    $kursusId = (int)$ctxQuiz['course_id'];
} else {
    $ctxQuiz = null;
    $quizId = 0;
    $kursusId = num('kursus');
}
if (!in_array($kursusId, $allowedCourses, true)) {
    $kursusId = (int)($allowedCourses[0] ?? 0);
}
$kursus = null;
foreach ($courses as $c) {
    if ((int)$c['id'] === $kursusId) {
        $kursus = $c;
    }
}
$quizzes = $kursusId
    ? db()->query('SELECT id, judul FROM quizzes WHERE course_id = ' . (int)$kursusId . ' ORDER BY judul')->fetchAll()
    : [];
$allowedQuiz = array_map('intval', array_column($quizzes, 'id'));
$filterQuizId = ($ctxQuiz && in_array((int)$ctxQuiz['id'], $allowedQuiz, true)) ? (int)$ctxQuiz['id'] : 0;
$viewUrl = 'guru-soal.php?' . ($filterQuizId ? ('quiz=' . $filterQuizId) : ('kursus=' . $kursusId));

/* Soal mana yang boleh dikelola user ini (kursusnya dalam cakupan). */
$scopeCek = $isAdmin ? '1=1' : ('co.guru_id = ' . (int)$u['id']);
$stBoleh = db()->prepare("SELECT COUNT(*) FROM questions q JOIN courses co ON co.id = q.course_id WHERE $scopeCek AND q.id = ?");
$boleh = function ($id) use ($stBoleh) {
    $stBoleh->execute([(int)$id]);
    return (bool)(int)$stBoleh->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $targetQuiz = (int)post('target_quiz');
    $targetQuiz = in_array($targetQuiz, $allowedQuiz, true) ? $targetQuiz : 0;
    $judulQuiz = '';
    foreach ($quizzes as $qz) {
        if ((int)$qz['id'] === $targetQuiz) {
            $judulQuiz = (string)$qz['judul'];
        }
    }

    if ($aksi === 'simpan' && $kursusId) {
        $lama = null;
        if ($id > 0) {
            $s = db()->prepare('SELECT * FROM questions WHERE id = ?');
            $s->execute([$id]);
            $lama = $s->fetch() ?: null;
            if ($lama && !$boleh($id)) {
                $lama = null;
            }
        }
        $simpanKursus = $lama ? (int)$lama['course_id'] : $kursusId;
        $tipe = post('tipe', 'mc');
        if (!in_array($tipe, tipe_soal_valid(), true)) {
            $tipe = 'mc';
        }
        $opsi = null;
        $opsiKategori = null;
        $penilaian = 'penuh';
        // Gambar soal: hanya soal mc/mcma yang punya gambar per pilihan.
        $gambar = gambar_url_valid(post('gambar'));
        $opsiGambar = null;
        $jawaban = post('jawaban');
        if ($tipe === 'mc' || $tipe === 'mcma') {
            $list = [];
            $slotKeIndex = [];
            foreach ((array)post('opsi', []) as $slot => $o) {
                if (trim((string)$o) !== '') {
                    $slotKeIndex[(string)$slot] = count($list);
                    $list[] = trim((string)$o);
                }
            }
            $opsi = json_encode($list);
            // Gambar tiap pilihan ikut digeser seperti teks pilihannya (slot
            // kosong tidak menjadi opsi). Disimpan sebagai peta {"index": "url"}.
            $imgMasuk = (array)post('opsi_gambar', []);
            $imgOpsi = [];
            foreach ($slotKeIndex as $slot => $idx) {
                $url = gambar_url_valid($imgMasuk[$slot] ?? '');
                if ($url !== '') {
                    $imgOpsi[$idx] = $url;
                }
            }
            $opsiGambar = $imgOpsi ? json_encode((object)$imgOpsi) : null;
            if ($tipe === 'mc') {
                $jawaban = (string)(int)post('jawaban_mc');
            } else {
                $kunci = [];
                foreach ((array)post('kunci_mcma', []) as $k) {
                    $k = (int)$k;
                    if (isset($list[$k])) {
                        $kunci[] = (string)$k;
                    }
                }
                sort($kunci);
                $jawaban = json_encode(array_values(array_unique($kunci)));
                $penilaian = post('penilaian_mcma') === 'parsial' ? 'parsial' : 'penuh';
            }
            if ($tipe === 'mcma' && count((array)json_decode((string)$jawaban, true)) === 0) {
                flash('Soal MCMA harus punya minimal satu jawaban benar.', 'warning');
                redirect($viewUrl);
            }
        } elseif ($tipe === 'kategori') {
            // Kategori dinamis: slot kosong dibuang, index kunci dirapikan.
            $slotToIndex = [];
            $kats = [];
            foreach ((array)post('opsi_kategori', []) as $slot => $nama) {
                $nama = trim((string)$nama);
                if ($nama === '') {
                    continue;
                }
                $slotToIndex[(string)$slot] = count($kats);
                $kats[] = $nama;
            }
            $items = [];
            $kunci = [];
            foreach ((array)post('item', []) as $slot => $teks) {
                $teks = trim((string)$teks);
                $katSlot = isset($_POST['kunci_kat'][$slot]) ? (string)$_POST['kunci_kat'][$slot] : '';
                if ($teks === '' || $katSlot === '' || !isset($slotToIndex[$katSlot])) {
                    continue;
                }
                $kunci[(string)count($items)] = $slotToIndex[$katSlot];
                $items[] = $teks;
            }
            if (count($kats) < 2) {
                flash('Soal kategori butuh minimal 2 kategori.', 'warning');
                redirect($viewUrl);
            }
            if (count($items) < 2) {
                flash('Soal kategori butuh minimal 2 item, masing-masing dengan kategori benar.', 'warning');
                redirect($viewUrl);
            }
            $opsi = json_encode($items);
            $opsiKategori = json_encode($kats);
            // Di-cast (object) agar tersimpan sebagai peta {"0":0,"1":1} bukan array.
            $jawaban = json_encode((object)$kunci);
        } elseif ($tipe === 'matching') {
            $pairs = [];
            foreach ((array)post('pasangan', []) as $p) {
                $kiri = trim((string)($p['kiri'] ?? ''));
                $kanan = trim((string)($p['kanan'] ?? ''));
                if ($kiri !== '' && $kanan !== '') {
                    $pairs[] = $kiri . '|' . $kanan;
                }
            }
            $opsi = json_encode($pairs);
            $jawaban = 'semua';
        } elseif ($tipe === 'tf') {
            $jawaban = post('jawaban_tf', 'benar');
        }
        // Pemilik soal: SATU quiz pada kursus yang sama (sejak skema 10 satu soal
        // tidak boleh dipakai beberapa quiz). `quiz_present` menandai field pemilih
        // quiz benar-benar ikut terkirim, supaya "sengaja dikosongkan" (soal hanya
        // ada di bank soal kursus) bisa dibedakan dari form lama tanpa field itu.
        // Bentuk lama (checkbox quiz_ids[]) tetap diterima demi kompatibilitas.
        $pemilik = 0;
        if (post('quiz_present') !== '' || post('quiz_ids_present') !== '') {
            $pemilik = (int)post('quiz_id');
            if (!$pemilik && post('quiz_ids_present') !== '') {
                foreach ((array)post('quiz_ids', []) as $qz) {
                    $qz = (int)$qz;
                    if (in_array($qz, $allowedQuiz, true)) {
                        $pemilik = $qz;
                        break;
                    }
                }
            }
        } elseif ($filterQuizId) {
            $pemilik = $filterQuizId;
        }
        $assign = in_array($pemilik, $allowedQuiz, true) ? $pemilik : 0;
        $args = [$simpanKursus, $tipe, post('pertanyaan'), $opsi, (string)$jawaban, post('pembahasan'), (float)post('skor', '1'), $penilaian, $opsiKategori, $gambar ?: null, $opsiGambar];
        if ($lama) {
            $args[] = (int)$lama['id'];
            db()->prepare('UPDATE questions SET course_id=?, tipe=?, pertanyaan=?, opsi=?, jawaban=?, pembahasan=?, skor=?, penilaian=?, opsi_kategori=?, gambar=?, opsi_gambar=? WHERE id=?')->execute($args);
            $soalId = (int)$lama['id'];
            flash('Soal diperbarui.');
        } else {
            $args[] = $assign ?: null;
            db()->prepare('INSERT INTO questions (course_id, tipe, pertanyaan, opsi, jawaban, pembahasan, skor, penilaian, opsi_kategori, gambar, opsi_gambar, quiz_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($args);
            $soalId = (int)db()->lastInsertId();
            flash('Soal baru ditambahkan ke bank soal.');
        }
        // Kepemilikan tunggal: soal ini hanya boleh dimiliki satu quiz.
        if ($simpanKursus === $kursusId) {
            if ($assign) {
                quiz_assign_soal($assign, [$soalId]);
            } else {
                foreach ($allowedQuiz as $q) {
                    quiz_unassign_soal($q, [$soalId]);
                }
            }
        }
        audit('simpan_soal', 'Menyimpan soal ' . $tipe . ' pada bank soal kursus #' . $kursusId);
    }

    if ($aksi === 'hapus' && $id > 0 && $boleh($id)) {
        lms_hapus_turunan('question', $id);
        flash('Soal dihapus dari bank soal.');
        audit('hapus_soal', 'Menghapus soal bank soal id ' . $id);
    }

    if ($aksi === 'hapus_massal' && $ids) {
        $n = 0;
        foreach ($ids as $qid) {
            if (!$boleh($qid)) {
                continue;
            }
            lms_hapus_turunan('question', (int)$qid);
            $n++;
        }
        flash($n . ' soal dihapus dari bank soal.');
        audit('hapus_soal_massal', 'Menghapus ' . $n . ' soal dari bank soal kursus #' . $kursusId);
    }

    if ($aksi === 'tambah_ke_quiz' && $ids) {
        if (!$targetQuiz) {
            flash('Pilih quiz terlebih dahulu.', 'warning');
        } else {
            $n = quiz_assign_soal($targetQuiz, $ids);
            flash($n . ' soal sekarang dimiliki quiz "' . $judulQuiz . '".' . ($n < count($ids) ? ' Soal dari kursus lain atau yang sudah dimiliki quiz itu dilewati.' : ''));
            audit('tambah_soal_quiz', 'Menjadikan ' . $n . ' soal milik quiz ' . $judulQuiz);
        }
    }

    if ($aksi === 'keluarkan_dari_quiz' && $ids) {
        if (!$targetQuiz) {
            flash('Pilih quiz terlebih dahulu.', 'warning');
        } else {
            $n = quiz_unassign_soal($targetQuiz, $ids);
            flash($n . ' soal dilepas dari quiz "' . $judulQuiz . '" dan kembali ke bank soal kursus.', 'success');
            audit('lepas_soal_quiz', 'Melepas ' . $n . ' soal dari quiz ' . $judulQuiz);
        }
    }

    if ($aksi === 'pindah_kursus' && $ids) {
        $targetKursus = (int)post('target_kursus');
        if (!in_array($targetKursus, $allowedCourses, true) || $targetKursus === $kursusId) {
            flash('Pilih kursus tujuan yang berbeda terlebih dahulu.', 'warning');
        } else {
            $n = 0;
            foreach ($ids as $qid) {
                if (!$boleh($qid)) {
                    continue;
                }
                db()->prepare('UPDATE questions SET course_id = ?, quiz_id = NULL WHERE id = ?')->execute([$targetKursus, $qid]);
                $n++;
            }
            $nama = '';
            foreach ($courses as $c) {
                if ((int)$c['id'] === $targetKursus) {
                    $nama = (string)$c['judul'];
                }
            }
            flash($n . ' soal dipindahkan ke bank soal kursus "' . $nama . '" dan dilepas dari quiz lamanya (soal tetap ada).');
            audit('pindah_soal_kursus', 'Memindahkan ' . $n . ' soal ke kursus #' . $targetKursus);
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
                audit('ambil_soal_acak', 'Menjadikan ' . $n . ' soal acak milik quiz ' . $judulQuiz);
            } else {
                flash('Tidak ada soal bebas yang bisa diambil — semua soal bank kursus ini sudah dimiliki quiz. Lepas dulu soal dari quiz lain, atau tambah soal baru.', 'warning');
            }
        }
    }

    if ($aksi === 'import' && $kursusId) {
        try {
            if (empty($_FILES['file']['name'])) {
                throw new RuntimeException('Pilih berkas Excel/CSV terlebih dahulu.');
            }
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $rows = $ext === 'csv' ? csv_read($_FILES['file']['tmp_name']) : xlsx_read($_FILES['file']['tmp_name']);
            $n = 0;
            $lewati = [];
            $gambarTolak = [];
            $ins = db()->prepare('INSERT INTO questions (course_id, quiz_id, tipe, pertanyaan, opsi, jawaban, pembahasan, skor, penilaian, opsi_kategori, gambar, opsi_gambar) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $i => $r) {
                if ($i === 0) {
                    continue; // baris pertama = header
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
                $ins->execute([$kursusId, $filterQuizId ?: null, $d['tipe'], $d['pertanyaan'], $d['opsi'], $d['jawaban'], $d['pembahasan'], $d['skor'], $d['penilaian'], $d['opsi_kategori'], $d['gambar'] ?: null, $d['opsi_gambar']]);
                $n++;
            }
            // Kalau halaman sedang fokus ke satu quiz, soal hasil import langsung
            // menjadi milik quiz tersebut (lewat kolom quiz_id saat INSERT).
            $tujuan = $filterQuizId ? ('quiz "' . ($ctxQuiz['judul'] ?? '') . '"') : ('bank soal kursus "' . ($kursus['judul'] ?? '') . '"');
            $lapor = soal_import_pesan($n, $tujuan, $lewati, $gambarTolak);
            flash($lapor['pesan'], $lapor['tipe']);
            audit('import_soal', 'Import ' . $n . ' soal ke bank soal kursus #' . $kursusId);
        } catch (Throwable $e) {
            flash('Import gagal: ' . $e->getMessage(), 'danger');
        }
    }

    redirect($viewUrl);
}

if (get('template') === 'soal') {
    xlsx_download('template-bank-soal.xlsx', xlsx_template_soal());
}
if (get('export') === 'xlsx' && $kursus) {
    $rows = [['TIPE', 'PERTANYAAN', 'A', 'B', 'C', 'D', 'E', 'JAWABAN', 'PEMBAHASAN', 'SKOR', 'PENILAIAN', 'GAMBAR', 'GAMBAR_A', 'GAMBAR_B', 'GAMBAR_C', 'GAMBAR_D', 'GAMBAR_E']];
    $sql = 'SELECT * FROM questions WHERE course_id = ' . (int)$kursusId;
    if ($filterQuizId) {
        $sql .= ' AND quiz_id = ' . $filterQuizId;
    }
    foreach (db()->query($sql . ' ORDER BY id')->fetchAll() as $q) {
        $rows[] = soal_ke_baris($q);
    }
    $nama = 'bank-soal-' . slugify($kursus['judul']);
    if ($filterQuizId) {
        $nama .= '-' . slugify($ctxQuiz['judul'] ?? 'quiz');
    }
    audit('export_soal', 'Export bank soal kursus #' . $kursusId);
    xlsx_download($nama . '.xlsx', $rows);
}

/* ---------------- data tampilan ---------------- */
$where = $kursusId ? ('q.course_id = ' . (int)$kursusId) : '0';
if ($filterQuizId) {
    $where .= ' AND q.quiz_id = ' . $filterQuizId;
}
$rows = db()->query("SELECT q.*, (SELECT COUNT(*) FROM attempt_answers a WHERE a.question_id = q.id AND a.jawaban IS NOT NULL AND a.jawaban <> '') AS jml_dijawab
    FROM questions q WHERE $where ORDER BY q.id")->fetchAll();
$pakaiMap = bank_soal_pakai_map($kursusId);
$jmlQuizSoal = quiz_jml_soal_map($kursusId);
$jmlBank = $kursusId ? (int)db()->query('SELECT COUNT(*) FROM questions WHERE course_id = ' . (int)$kursusId)->fetchColumn() : 0;
$dipakaiIds = [];
foreach ($pakaiMap as $qid => $_) {
    $dipakaiIds[$qid] = true;
}
$jmlBelumDipakai = $jmlBank - count($dipakaiIds);
$totalSkor = array_sum(array_map(function ($q) {
    return (float)$q['skor'];
}, $rows));
$editSoal = null;
if (num('edit') > 0 && $boleh(num('edit'))) {
    $s = db()->prepare('SELECT * FROM questions WHERE id = ?');
    $s->execute([num('edit')]);
    $editSoal = $s->fetch() ?: null;
}

if (!$kursus) {
    page_start('Bank Soal', 'Belum ada kursus yang bisa dikelola.');
    echo '<div class="card">' . empty_state('Bank soal menempel pada kursus, jadi buat kursus dulu sebelum menyusun soal.', 'book') . '</div>';
    page_end();
    exit;
}

page_start('Bank Soal — ' . $kursus['judul'], $filterQuizId
    ? ('Soal milik quiz "' . ($ctxQuiz['judul'] ?? '') . '" — ' . count($rows) . ' soal, total ' . $totalSkor . ' poin')
    : ('Bank soal kursus ini: ' . $jmlBank . ' soal (' . $jmlBelumDipakai . ' belum dimiliki quiz) — tampil ' . count($rows) . ' soal, total ' . $totalSkor . ' poin'), [
    'actions' => '<a class="btn btn-ghost" href="guru-soal.php?template=soal">' . icon('download', 17) . ' Template Excel</a>'
        . '<button class="btn btn-ghost" data-modal-open="modal-import">' . icon('upload', 17) . ' Import Soal</button>'
        . '<a class="btn btn-ghost" href="' . e($viewUrl) . '&export=xlsx">' . icon('download', 17) . ' Export</a>'
        . '<button class="btn btn-primary" data-modal-open="modal-soal">' . icon('plus', 17) . ' Soal Baru</button>',
]);
?>
<div class="card">
  <form class="toolbar" method="get" style="align-items:flex-end">
    <div class="form-group" style="min-width:260px">
      <label>Kursus (bank soal)</label>
      <select name="kursus" data-autosubmit>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="min-width:300px">
      <label>Tampilkan</label>
      <select name="quiz" data-autosubmit>
        <option value="0" <?= $filterQuizId === 0 ? 'selected' : '' ?>>Seluruh bank soal kursus (<?= $jmlBank ?> soal)</option>
        <?php foreach ($quizzes as $qz): ?>
          <option value="<?= (int)$qz['id'] ?>" <?= $filterQuizId === (int)$qz['id'] ? 'selected' : '' ?>>Milik quiz: <?= e($qz['judul']) ?> (<?= (int)($jmlQuizSoal[(int)$qz['id']] ?? 0) ?> soal)</option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint">Soal dimiliki kursus dan hanya boleh dimiliki <strong>satu</strong> quiz. Memilih quiz = hanya menampilkan soal milik quiz itu.</div>
    </div>
    <?php if (!$quizzes): ?>
      <div class="form-group">
        <label>Belum ada quiz</label>
        <a class="btn btn-primary" href="guru-quiz.php?kursus=<?= (int)$kursusId ?>"><?= icon('plus', 16) ?> Buat Quiz</a>
      </div>
    <?php endif; ?>
  </form>
</div>

<?php if ($quizzes): ?>
<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="acak">
    <div class="toolbar" style="align-items:flex-end">
      <div class="form-group" style="min-width:220px">
        <label>Ambil soal ACAK dari bank kursus</label>
        <input type="number" name="jumlah_acak" min="1" value="10" style="max-width:120px">
      </div>
      <div class="form-group" style="min-width:240px">
        <label>Tambahkan ke quiz</label>
        <select name="target_quiz">
          <?php foreach ($quizzes as $qz): ?>
            <option value="<?= (int)$qz['id'] ?>" <?= $filterQuizId === (int)$qz['id'] ? 'selected' : '' ?>><?= e($qz['judul']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <button class="btn btn-primary btn-sm"><?= icon('shuffle', 15) ?> Ambil &amp; tambahkan</button>
      </div>
    </div>
    <div class="form-hint">Hanya soal yang <strong>belum dimiliki quiz mana pun</strong> yang diambil, dan tidak ada soal yang terhapus dari bank. Bank kursus ini: <?= $jmlBank ?> soal, <?= $jmlBelumDipakai ?> belum dimiliki quiz. Butuh set soal berbeda tiap siswa? Tambahkan soal yang cukup banyak ke quiz, lalu atur &ldquo;acak urutan soal&rdquo; + &ldquo;jumlah soal tampil&rdquo; di pengaturan quiz.</div>
  </form>
</div>
<?php endif; ?>

<?php if ($rows): ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="toolbar" style="align-items:flex-end">
    <div class="form-group" style="flex:1;min-width:220px">
      <label>Cari soal</label>
      <input type="search" data-filter-input="#tbl-soal" placeholder="Cari teks soal...">
    </div>
    <?php if ($quizzes): ?>
    <div class="form-group" style="min-width:260px">
      <label>Quiz (untuk aksi massal)</label>
      <div class="row-gap">
        <select name="target_quiz" style="min-width:170px">
          <option value="">- pilih quiz -</option>
          <?php foreach ($quizzes as $qz): ?>
            <option value="<?= (int)$qz['id'] ?>" <?= $filterQuizId === (int)$qz['id'] ? 'selected' : '' ?>><?= e($qz['judul']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" name="aksi" value="tambah_ke_quiz" data-confirm="Jadikan soal yang ditandai milik quiz ini? Bila soal itu masih milik quiz lain di kursus ini, kepemilikannya berpindah."><?= icon('plus', 14) ?> Jadikan milik quiz</button>
        <button class="btn btn-ghost btn-sm" name="aksi" value="keluarkan_dari_quiz" data-confirm="Lepas soal yang ditandai dari quiz ini? Soal tetap ada di bank soal kursus."><?= icon('x', 14) ?> Lepas dari quiz</button>
      </div>
    </div>
    <?php endif; ?>
    <?php if (count($courses) > 1): ?>
    <div class="form-group">
      <label>Pindah kursus</label>
      <div class="row-gap">
        <select name="target_kursus" style="min-width:150px">
          <?php foreach ($courses as $c): ?>
            <?php if ((int)$c['id'] !== $kursusId): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['judul']) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" name="aksi" value="pindah_kursus" data-confirm="Pindahkan soal yang ditandai ke kursus lain? Soal akan dilepas dari quiznya (soalnya tetap ada)."><?= icon('arrow-right', 14) ?> Pindahkan</button>
      </div>
    </div>
    <?php endif; ?>
    <div class="form-group">
      <label>Hapus</label>
      <button class="btn btn-danger btn-sm" name="aksi" value="hapus_massal" data-confirm="Hapus soal-soal yang ditandai dari bank soal?"><?= icon('trash', 14) ?> Hapus yang ditandai</button>
    </div>
  </div>
  <div class="table-wrap">
    <table class="data" id="tbl-soal">
      <thead><tr><th style="width:34px"><input type="checkbox" onclick="document.querySelectorAll('.chk-soal').forEach(c=>c.checked=this.checked)"></th><th>Soal</th><th>Tipe</th><th>Kunci</th><th>Poin</th><th>Milik quiz</th><th>Dijawab</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $q):
          $opsi = $q['opsi'] ? json_decode($q['opsi'], true) : [];
          $kunci = $q['jawaban'];
          if ($q['tipe'] === 'mc' && isset($opsi[(int)$kunci])) {
              $kunci = chr(65 + (int)$kunci) . '. ' . $opsi[(int)$kunci];
          } elseif ($q['tipe'] === 'mcma') {
              $arr = json_decode((string)$kunci, true) ?: [];
              $huruf = array_map(function ($x) { return chr(65 + (int)$x); }, $arr);
              $kunci = ($huruf ? implode(', ', $huruf) : '-') . ' (' . count($arr) . ' jawaban benar)' . (($q['penilaian'] ?? 'penuh') === 'parsial' ? ' · skor sebagian' : '');
          } elseif ($q['tipe'] === 'kategori') {
              $kats = soal_kategori_list($q);
              $kunci = count($kats) . ' kategori: ' . implode(' / ', $kats);
          } elseif ($q['tipe'] === 'matching') {
              $kunci = count($opsi) . ' pasangan';
          }
          $pakai = $pakaiMap[(int)$q['id']] ?? [];
          ?>
        <tr>
          <td><input type="checkbox" class="chk-soal" name="ids[]" value="<?= (int)$q['id'] ?>"></td>
          <td style="max-width:400px">
            <?php $gSoal = soal_gambar($q); $gOpsi = soal_gambar_opsi($q); ?>
            <?php if ($gSoal): ?><?= gambar_img($gSoal, 'thumb-soal', 'Gambar soal') ?> <?php endif; ?>
            <strong><?= e(mb_strimwidth($q['pertanyaan'], 0, 120, '...')) ?></strong>
            <?php if ($gOpsi): ?>
              <div class="thumb-flag"><?= icon('image', 13) ?> <?= count($gOpsi) ?> gambar pilihan</div>
            <?php endif; ?>
            <?php if ($opsi && in_array($q['tipe'], ['mc', 'matching'], true)): ?>
              <div class="muted small"><?= e(implode(' · ', array_slice(array_map(function ($x) { return mb_strimwidth((string)$x, 0, 26, ''); }, $opsi), 0, 5))) ?></div>
            <?php endif; ?>
            <?php if ($q['pembahasan']): ?><div class="muted small"><?= icon('chat', 12) ?> <?= e(mb_strimwidth($q['pembahasan'], 0, 70, '...')) ?></div><?php endif; ?>
          </td>
          <td><span class="badge badge-info"><?= e(tipe_soal_label($q['tipe'])) ?></span></td>
          <td class="small"><?= e(mb_strimwidth((string)$kunci, 0, 40, '...')) ?></td>
          <td><?= (float)$q['skor'] ?></td>
          <td class="small">
            <?php if ($pakai): ?>
              <?php foreach ($pakai as $p): ?>
                <a class="badge badge-info" href="guru-soal.php?quiz=<?= (int)$p['id'] ?>"><?= e(mb_strimwidth($p['judul'], 0, 24, '...')) ?></a>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="muted small">belum dimiliki quiz</span>
            <?php endif; ?>
          </td>
          <td><?= (int)$q['jml_dijawab'] ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="<?= e($viewUrl) ?>&edit=<?= (int)$q['id'] ?>"><?= icon('edit', 14) ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</form>
<?php elseif ($quizzes): ?>
  <div class="card"><?= empty_state($filterQuizId ? 'Quiz ini belum punya soal. Tandai soal di seluruh bank soal lalu klik "Jadikan milik quiz", atau ambil beberapa soal acak.' : 'Bank soal kursus ini masih kosong. Tambahkan manual atau import dari Excel.', 'list') ?>
  <div class="center row-gap" style="justify-content:center">
    <button class="btn btn-primary" data-modal-open="modal-soal"><?= icon('plus', 17) ?> Soal Baru</button>
    <button class="btn btn-ghost" data-modal-open="modal-import"><?= icon('upload', 17) ?> Import Excel</button>
    <?php if ($filterQuizId): ?><a class="btn btn-ghost" href="guru-soal.php?kursus=<?= (int)$kursusId ?>">Buka seluruh bank soal</a><?php endif; ?>
  </div></div>
<?php else: ?>
  <div class="card"><?= empty_state('Tambahkan soal dulu — tiap soal nanti bisa dijadikan milik salah satu quiz di kursus ini.', 'list') ?></div>
<?php endif; ?>

<div class="modal-back <?= $editSoal ? 'open' : '' ?>" id="modal-soal">
  <div class="modal">
    <div class="modal-head"><h3 class="mt0"><?= $editSoal ? 'Ubah Soal' : 'Soal Baru' ?></h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <?php
    $eOpsi = $editSoal && $editSoal['opsi'] ? json_decode($editSoal['opsi'], true) : [];
    $eTipe = $editSoal['tipe'] ?? 'mc';
    $eKategori = $editSoal ? soal_kategori_list($editSoal) : [];
    $eItems = ($editSoal && $editSoal['tipe'] === 'kategori') ? soal_item_list($editSoal) : [];
    $eKunciKategori = ($editSoal && $editSoal['tipe'] === 'kategori' && $editSoal['jawaban']) ? (json_decode($editSoal['jawaban'], true) ?: []) : [];
    $eKunciMcma = ($editSoal && $editSoal['tipe'] === 'mcma' && $editSoal['jawaban']) ? array_map('strval', json_decode($editSoal['jawaban'], true) ?: []) : [];
    $eQuizId = 0;
    if ($editSoal) {
        foreach (($pakaiMap[(int)$editSoal['id']] ?? []) as $p) {
            $eQuizId = (int)$p['id'];
        }
    } elseif ($filterQuizId) {
        $eQuizId = $filterQuizId;
    }
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= $editSoal ? (int)$editSoal['id'] : 0 ?>">
      <input type="hidden" name="quiz_present" value="1">
      <div class="form-grid">
        <div class="form-group">
          <label>Tipe soal</label>
          <select name="tipe" id="tipe-soal">
            <?php foreach (tipe_soal_options() as $k => $v): ?>
              <option value="<?= $k ?>" <?= $eTipe === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">MCMA = banyak jawaban benar. Kategori = item dikelompokkan ke beberapa kategori.</div>
        </div>
        <div class="form-group">
          <label>Poin / skor</label>
          <input type="number" name="skor" step="0.5" min="0.5" value="<?= e((string)($editSoal['skor'] ?? 1)) ?>">
        </div>
      </div>
      <div class="form-group">
        <label>Pertanyaan *</label>
        <textarea name="pertanyaan" required><?= e($editSoal['pertanyaan'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label>Gambar pertanyaan (opsional)</label>
        <?= gambar_slot('gambar', $editSoal ? soal_gambar($editSoal) : '') ?>
        <div class="form-hint">Bisa diunggah dari komputer (JPG/PNG/GIF/WEBP/SVG, maks 15 MB) atau ditempel sebagai URL. Siswa bisa mengklik gambar untuk memperbesarnya.</div>
      </div>
      <div class="form-group">
        <label>Opsi / pilihan</label>
        <div id="opsi-wrap"></div>
      </div>
      <div class="form-grid">
        <div class="form-group" id="wrap-jawaban-mc">
          <label>Jawaban benar (kunci)</label>
          <select name="jawaban_mc">
            <?php foreach (['A', 'B', 'C', 'D', 'E'] as $i => $L): ?>
              <option value="<?= $i ?>" <?= (int)($editSoal['jawaban'] ?? 0) === $i ? 'selected' : '' ?>><?= $L ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">Untuk pilihan ganda biasa.</div>
        </div>
        <div class="form-group" id="wrap-mcma">
          <label>Kunci MCMA (pilih semua yang benar)</label>
          <div class="row-gap">
            <?php foreach (['A', 'B', 'C', 'D', 'E'] as $i => $L): ?>
              <label class="check"><input type="checkbox" name="kunci_mcma[]" value="<?= $i ?>" <?= in_array((string)$i, $eKunciMcma, true) ? 'checked' : '' ?>> <?= $L ?></label>
            <?php endforeach; ?>
          </div>
          <div class="form-hint">Cukup centang huruf yang benar; teks pilihannya diambil dari daftar opsi di atas.</div>
        </div>
        <div class="form-group" id="wrap-penilaian">
          <label>Penilaian MCMA</label>
          <select name="penilaian_mcma">
            <option value="penuh" <?= ($editSoal['penilaian'] ?? 'penuh') === 'penuh' ? 'selected' : '' ?>>Semua benar saja (penuh)</option>
            <option value="parsial" <?= ($editSoal['penilaian'] ?? '') === 'parsial' ? 'selected' : '' ?>>Skor sebagian per pilihan tepat</option>
          </select>
          <div class="form-hint">Pilihan "parsial" memberi sebagian poin dan mengurangi poin bila salah pilih.</div>
        </div>
        <div class="form-group" id="wrap-tf">
          <label>Kunci untuk Benar/Salah</label>
          <select name="jawaban_tf">
            <option value="benar" <?= ($editSoal['jawaban'] ?? '') === 'benar' ? 'selected' : '' ?>>Benar</option>
            <option value="salah" <?= ($editSoal['jawaban'] ?? '') === 'salah' ? 'selected' : '' ?>>Salah</option>
          </select>
        </div>
        <div class="form-group" id="wrap-fill">
          <label>Kunci untuk Fill in the Blank</label>
          <input type="text" name="jawaban" value="<?= e($eTipe === 'fill' ? (string)($editSoal['jawaban'] ?? '') : '') ?>" placeholder="are|is (pisahkan varian dengan |)">
          <div class="form-hint">Boleh beberapa varian jawaban, pisahkan dengan tanda |</div>
        </div>
      </div>

      <div class="card tight flat" id="wrap-kategori">
        <h4 class="mt0">Kategori (untuk tipe PG Kompleks — Kategori)</h4>
        <p class="muted small">Tulis nama tiap kategori, lalu tentukan kategori benar untuk setiap item di bawah. Kategori yang dikosongkan akan diabaikan.</p>
        <div class="form-grid" style="grid-template-columns:repeat(2,1fr)">
          <?php foreach (['A', 'B', 'C', 'D'] as $i => $L): ?>
            <div class="form-group">
              <label>Kategori <?= $L ?></label>
              <input type="text" name="opsi_kategori[<?= $i ?>]" class="kat-nama" data-slot="<?= $i ?>" value="<?= e($eKategori[$i] ?? '') ?>" placeholder="Mis. <?= $i === 0 ? 'Fruit (Buah)' : ($i === 1 ? 'Vegetable (Sayur)' : 'Nama kategori') ?>">
            </div>
          <?php endforeach; ?>
        </div>
        <div class="divider"></div>
        <div class="form-group">
          <label>Daftar item &amp; kategorinya</label>
          <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="q-row" style="grid-template-columns:28px 1fr 1fr">
              <span class="opt-key"><?= $i + 1 ?></span>
              <input type="text" name="item[<?= $i ?>]" value="<?= e($eItems[$i] ?? '') ?>" placeholder="Item ke-<?= $i + 1 ?> (mis. Apple)">
              <select name="kunci_kat[<?= $i ?>]" data-kat-select>
                <option value="">-- kategori benar --</option>
                <?php foreach (['A', 'B', 'C', 'D'] as $j => $L): ?>
                  <option value="<?= $j ?>" <?= (string)($eKunciKategori[$i] ?? '') === (string)$j ? 'selected' : '' ?>>Kategori <?= $L ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endfor; ?>
        </div>
        <div class="form-hint">Minimal 2 kategori dan 2 item. Baris yang itemnya kosong atau kategorinya belum dipilih akan dilewati.</div>
      </div>
      <?php if ($quizzes): ?>
      <div class="card tight flat">
        <h4 class="mt0">Dimiliki quiz (opsional)</h4>
        <p class="muted small">Soal ini tersimpan di bank soal kursus <strong><?= e($kursus['judul']) ?></strong> dan hanya boleh dimiliki <strong>satu</strong> quiz. Pilih quiz pemiliknya, atau biarkan kosong supaya soal tetap ada di bank soal kursus.</p>
        <div class="form-group">
          <label>Quiz pemilik soal</label>
          <select name="quiz_id">
            <option value="0" <?= $eQuizId === 0 ? 'selected' : '' ?>>- belum dimiliki quiz mana pun -</option>
            <?php foreach ($quizzes as $qz): ?>
              <option value="<?= (int)$qz['id'] ?>" <?= $eQuizId === (int)$qz['id'] ? 'selected' : '' ?>><?= e($qz['judul']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-hint">Kepemilikan juga bisa diatur dari tabel lewat aksi massal &ldquo;Jadikan milik quiz&rdquo;. Bila quiz pemiliknya dipindah, soal itu berpindah ke quiz yang baru (tidak bisa dimiliki dua quiz sekaligus).</div>
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Pembahasan</label>
        <textarea name="pembahasan" placeholder="Penjelasan jawaban yang ditampilkan setelah siswa mengerjakan."><?= e($editSoal['pembahasan'] ?? '') ?></textarea>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Soal</button>
        <a class="btn btn-ghost" href="<?= e($viewUrl) ?>">Batal</a>
      </div>
    </form>
    <script>
      window.OLD_OPSI = <?= json_encode(array_values($eOpsi)) ?>;
      window.OLD_OPSI_GAMBAR = <?= json_encode((object)soal_gambar_opsi($editSoal ?: [])) ?>;
      window.OLD_KATEGORI = <?= json_encode(array_values($eKategori)) ?>;
      window.OLD_ITEMS = <?= json_encode(array_values($eItems)) ?>;
      window.OLD_KUNCI_KATEGORI = <?= json_encode((object)array_map('strval', $eKunciKategori)) ?>;
      window.OLD_PASANGAN = <?= json_encode(array_map(function ($p) {
          $x = explode('|', (string)$p);
          return ['kiri' => $x[0] ?? '', 'kanan' => $x[1] ?? ''];
      }, $editSoal && $editSoal['tipe'] === 'matching' ? $eOpsi : [])) ?>;
    </script>
  </div>
</div>

<div class="modal-back" id="modal-import">
  <div class="modal" style="max-width:600px">
    <div class="modal-head"><h3 class="mt0">Import Bank Soal (Excel/CSV)</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="import">
      <div class="form-group">
        <label>Berkas .xlsx atau .csv *</label>
        <input type="file" name="file" accept=".xlsx,.xls,.csv" required>
      </div>
      <div class="dark-box mb3">
        <strong>Masuk ke:</strong> <?= $filterQuizId ? ('quiz "' . e($ctxQuiz['judul'] ?? '') . '" (langsung menjadi milik quiz ini)') : ('bank soal kursus "' . e($kursus['judul']) . '" (belum dimiliki quiz)') ?><br>
        <strong>Format kolom:</strong><br>
        TIPE, PERTANYAAN, A, B, C, D, E, JAWABAN, PEMBAHASAN, SKOR, PENILAIAN, GAMBAR, GAMBAR_A…E<br>
        <span class="muted">TIPE: mc / mcma / kategori / tf / essay / matching / fill. Baris pertama = header.<br>
        <strong>mcma</strong> → jawaban benar ditulis huruf dipisah koma, mis. <code>A,C,E</code>; kolom PENILAIAN boleh <code>parsial</code>.<br>
        <strong>kategori</strong> → kolom A–E = nama kategori, JAWABAN = <code>Item=HurufKategori</code> dipisah <code>;</code>, mis. <code>Apple=A;Carrot=B</code>.<br>
        <strong>matching</strong> → tiap kolom A–E berisi <code>Kiri|Kanan</code>.<br>
        <strong>gambar</strong> (opsional) → GAMBAR = URL gambar pertanyaan, GAMBAR_A…E = URL gambar tiap pilihan. Hanya <strong>URL</strong> (<code>http://</code> / <code>https://</code>) yang bisa dibaca — mis. URL hasil tombol Upload di form soal. Berkas gambar di komputer kamu tidak bisa dibaca langsung dari Excel.</span>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('upload', 17) ?> Import</button>
        <a class="btn btn-ghost" href="guru-soal.php?template=soal"><?= icon('download', 16) ?> Unduh Template</a>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
