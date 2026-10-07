<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

// Sesi ujian yang sedang berjalan dibuka lewat ?attempt=N saja, jadi quiz
// harus bisa diturunkan dari attempt supaya halaman tidak 404 saat lanjut.
$attemptId = num('attempt');
if ($attemptId === 0 && post('attempt_id') !== '') {
    // Form ujian mengirim attempt_id lewat POST juga; dukung keduanya supaya
    // halaman tidak 404 saat form disubmit tanpa query string.
    $attemptId = (int)post('attempt_id');
}
$attempt = null;
if ($attemptId > 0) {
    $a = db()->prepare('SELECT * FROM quiz_attempts WHERE id = ?');
    $a->execute([$attemptId]);
    $attempt = $a->fetch();
    if ($attempt && (int)$attempt['user_id'] !== (int)$u['id'] && !in_array($u['role'], ['guru', 'admin'], true)) {
        http_response_code(403);
        include __DIR__ . '/includes/denied.php';
        exit;
    }
}

$quizId = num('quiz');
if ($quizId === 0 && $attempt) {
    $quizId = (int)$attempt['quiz_id'];
}
$stmt = db()->prepare('SELECT qz.*, co.judul AS kursus, co.id AS course_id, us.nama AS guru FROM quizzes qz
    LEFT JOIN courses co ON co.id = qz.course_id LEFT JOIN users us ON us.id = qz.guru_id WHERE qz.id = ?');
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();
if (!$quiz) {
    http_response_code(404);
    page_start('Quiz tidak ditemukan');
    echo '<div class="card">' . empty_state('Quiz tidak ditemukan.', 'search') . '</div>';
    page_end();
    exit;
}

$isGuru = in_array($u['role'], ['guru', 'admin'], true);
if ($u['role'] === 'siswa') {
    // Quiz hanya bisa dibuka setelah pendaftaran aktif (kursus berbayar harus lunas)
    $aksesQuiz = cek_akses_kursus((int)$u['id'], ['id' => (int)$quiz['course_id'], 'harga' => (float)db()->query('SELECT harga FROM courses WHERE id = ' . (int)$quiz['course_id'])->fetchColumn()]);
    if (!$aksesQuiz['boleh']) {
        flash($aksesQuiz['pesan'], 'warning');
        redirect($aksesQuiz['status'] === 'belum_daftar'
            ? 'kursus-detail.php?id=' . (int)$quiz['course_id']
            : 'siswa-pembayaran.php?kursus=' . (int)$quiz['course_id']);
    }
}

/* ------------------------------------------------------ mulai / lanjut sesi */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('aksi') === 'mulai') {
    csrf_check();
    // Soal diambil dari bank soal kursus yang ditugaskan ke quiz ini.
    if (!quiz_soal((int)$quiz['id'])) {
        flash('Quiz ini belum memiliki soal.', 'warning');
        redirect('kursus-detail.php?id=' . (int)$quiz['course_id']);
    }
    if (!quiz_boleh_mulai($quiz, (int)$u['id'])) {
        flash('Batas percobaan quiz ini (' . (int)$quiz['max_attempt'] . 'x) sudah tercapai.', 'warning');
        redirect('ujian-hasil.php?quiz=' . (int)$quiz['id']);
    }
    $attemptId = quiz_mulai_attempt($quiz, (int)$u['id']);
    audit('mulai_ujian', 'Mulai ' . $quiz['judul']);
    redirect('ujian.php?attempt=' . $attemptId);
}

/* --------------------------------------------------------------- submit */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('aksi') === 'submit') {
    csrf_check();
    $attemptId = (int)post('attempt_id');
    $a = db()->prepare('SELECT * FROM quiz_attempts WHERE id = ? AND user_id = ?');
    $a->execute([$attemptId, $u['id']]);
    $attempt = $a->fetch();
    if (!$attempt) {
        flash('Sesi ujian tidak ditemukan.', 'danger');
        redirect('kursus-detail.php?id=' . (int)$quiz['course_id']);
    }
    if ($attempt['status'] === 'in_progress') {
        $order = json_decode($attempt['soal_order'] ?: '[]', true) ?: [];
        $soalStmt = db()->prepare('SELECT * FROM questions WHERE id = ?');
        $upd = db()->prepare('UPDATE attempt_answers SET jawaban = ? WHERE attempt_id = ? AND question_id = ?');
        foreach ($order as $qid) {
            $soalStmt->execute([$qid]);
            $q = $soalStmt->fetch();
            if (!$q) {
                continue;
            }
            // PENTING: ujian ditampilkan satu soal per halaman, jadi form only
            // memuat soal yang sedang dibuka. Soal yang tidak dikirim form TIDAK
            // boleh ditimpa null (dulu itu menghapus jawaban yang sudah dibuka
            // siswa di halaman lain / hasil autosave).
            $jawaban = null;
            $adaInput = false;
            if ($q['tipe'] === 'matching') {
                if (isset($_POST['matching'][$qid]) && is_array($_POST['matching'][$qid])) {
                    $adaInput = true;
                    $val = [];
                    foreach ($_POST['matching'][$qid] as $k => $v) {
                        $v = trim((string)$v);
                        if ($v !== '') {
                            $val[(string)$k] = $v;
                        }
                    }
                    $jawaban = $val ? json_encode($val) : null;
                } elseif (is_string(post('matching_json')) && post('matching_json') !== '') {
                    $adaInput = true;
                    $decoded = json_decode((string)post('matching_json'), true);
                    $val = [];
                    foreach (is_array($decoded) ? $decoded : [] as $k => $v) {
                        $v = trim((string)$v);
                        if ($v !== '') {
                            $val[(string)$k] = $v;
                        }
                    }
                    $jawaban = $val ? json_encode($val) : null;
                }
            } elseif ($q['tipe'] === 'kategori' && isset($_POST['kat'][$qid]) && is_array($_POST['kat'][$qid])) {
                $adaInput = true;
                $val = [];
                foreach ($_POST['kat'][$qid] as $k => $v) {
                    $v = trim((string)$v);
                    if ($v !== '') {
                        $val[(string)$k] = $v;
                    }
                }
                // Peta item -> kategori disimpan sebagai objek JSON (cast object
                // supaya key numerik tidak berubah jadi array JSON).
                $jawaban = $val ? json_encode((object)$val) : null;
            } elseif (isset($_POST['q'][$qid])) {
                $adaInput = true;
                $raw = $_POST['q'][$qid];
                if (is_array($raw)) {
                    // MCMA: simpan sebagai JSON berisi indeks pilihan yang dicentang.
                    $raw = json_encode(array_values(array_map('strval', $raw)));
                }
                $raw = trim((string)$raw);
                if ($raw !== '' && $raw !== '[]') {
                    $jawaban = $raw;
                }
            }
            if (isset($_POST['q_present'][$qid])) {
                // Penanda bahwa soal ini memang ikut dikirim (MCMA yang dikosongkan).
                $adaInput = true;
            }
            if (!$adaInput) {
                continue;
            }
            $upd->execute([$jawaban, $attemptId, $qid]);
        }
        $nilai = grade_attempt($attemptId, post('auto_submit') === '1' ? 1 : 0);
        recalc_course_progress($u['id'], $quiz['course_id']);
        if ($quiz['guru_id'] && $nilai !== null) {
            notify($quiz['guru_id'], 'Hasil ' . $quiz['judul'], $u['nama'] . ' menyelesaikan quiz dengan nilai ' . number_format((float)$nilai, 2) . '.', 'guru-koreksi.php?quiz=' . (int)$quiz['id']);
        }
        audit('submit_ujian', 'Menyelesaikan ' . $quiz['judul'] . ' nilai ' . number_format((float)$nilai, 2));
        flash('Jawaban terkirim. Nilai kamu: ' . number_format((float)$nilai, 2));
    }
    redirect('ujian-hasil.php?attempt=' . $attemptId);
}

/* ---------------------------------------------------------------- sesi */
// $attempt sudah dimuat di bagian atas halaman (dari ?attempt= atau ?quiz=).
if ($attempt && (int)$attempt['user_id'] !== (int)$u['id']) {
    if ($isGuru) {
        redirect('ujian-hasil.php?attempt=' . (int)$attempt['id']);
    }
    http_response_code(403);
    redirect('kursus-detail.php?id=' . (int)$quiz['course_id']);
}

$riwayat = db()->prepare('SELECT * FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status != "in_progress" ORDER BY id DESC');
$riwayat->execute([$quiz['id'], $u['id']]);
$riwayat = $riwayat->fetchAll();
$best = 0;
foreach ($riwayat as $r) {
    $best = max($best, (float)$r['nilai']);
}
$soalCount = count(quiz_soal((int)$quiz['id']));

/* ------------- sesi yang sudah selesai -> langsung ke hasil ------------ */
if ($attempt && $attempt['status'] !== 'in_progress' && !$isGuru) {
    redirect('ujian-hasil.php?attempt=' . (int)$attempt['id']);
}

/* ---------------------------- tampilkan sesi berjalan ------------------- */
if ($attempt && $attempt['status'] === 'in_progress') {
    $remaining = attempt_remaining_seconds($attempt);
    if ($remaining !== null && $remaining <= 0) {
        grade_attempt((int)$attempt['id'], 1);
        flash('Waktu ujian sudah habis, jawaban kamu dikirim otomatis.', 'warning');
        redirect('ujian-hasil.php?attempt=' . (int)$attempt['id']);
    }
    $order = json_decode($attempt['soal_order'] ?: '[]', true) ?: [];
    $opsiOrder = json_decode($attempt['opsi_order'] ?: '[]', true) ?: [];
    $ansStmt = db()->prepare('SELECT question_id, jawaban FROM attempt_answers WHERE attempt_id = ?');
    $ansStmt->execute([$attempt['id']]);
    $answers = [];
    foreach ($ansStmt->fetchAll() as $r) {
        $answers[(int)$r['question_id']] = $r['jawaban'];
    }
    $qStmt = db()->prepare('SELECT * FROM questions WHERE id = ?');
    $soal = [];
    foreach ($order as $qid) {
        $qStmt->execute([$qid]);
        $q = $qStmt->fetch();
        if ($q) {
            $soal[] = $q;
        }
    }
    $terjawab = 0;
    $sudahDijawab = [];
    foreach ($soal as $q) {
        $terisi = jawaban_terisi($q['tipe'], $answers[(int)$q['id']] ?? null);
        $sudahDijawab[(int)$q['id']] = $terisi;
        if ($terisi) {
            $terjawab++;
        }
    }
    $totalSoal = count($soal);
    page_start($quiz['judul'], 'Kerjakan dengan teliti — jawaban tersimpan otomatis setiap kali kamu mengubahnya.');
    ?>
    <form method="post" id="quiz-form" data-attempt="<?= (int)$attempt['id'] ?>" data-remaining="<?= (int)$remaining ?>" data-guard="1"
          data-quiz="<?= (int)$quiz['id'] ?>" data-judul="<?= e($quiz['judul']) ?>" data-kursus="<?= e($quiz['kursus'] ?? '') ?>"
          data-durasi="<?= max(1, (int)$quiz['durasi_menit']) * 60 ?>" data-luring="<?= $attempt['batas_waktu'] ? '0' : '1' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="submit">
      <input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>">
      <input type="hidden" name="auto_submit" id="auto-submit" value="0">

      <div class="card quiz-head row-between">
        <div>
          <strong><?= e($quiz['judul']) ?></strong>
          <div class="muted small"><?= e($quiz['kursus'] ?? '') ?> &middot; nilai minimal <?= (int)$quiz['passing_grade'] ?></div>
        </div>
        <div class="row-gap">
          <span class="timer" id="quiz-timer"><?= icon('clock', 18) ?><span>--:--</span></span>
          <button class="btn btn-primary" type="submit" data-confirm="Kirim jawaban sekarang? Setelah dikirim kamu tidak bisa mengubahnya."><?= icon('check', 17) ?> Kirim Jawaban</button>
        </div>
      </div>

      <div class="split">
        <div>
          <?php /* Ujian ditampilkan SATU soal per halaman (dikelola assets/app.js).
                   Tanpa JavaScript semua soal tetap tampil (fallback <noscript>). */ ?>
          <noscript><style>
            [data-qp][hidden]{display:block !important}
            [data-qp-prev],[data-qp-skip],[data-qp-next]{display:none !important}
          </style></noscript>
          <div class="quiz-pages">
          <?php foreach ($soal as $i => $q):
              $qid = (int)$q['id'];
              $jawaban = $answers[$qid] ?? null;
              $opsi = $q['opsi'] ? json_decode($q['opsi'], true) : [];
              $ord = $opsiOrder[(string)$qid] ?? array_keys($opsi);
              $jawabanArr = in_array($q['tipe'], ['matching', 'kategori'], true) && $jawaban ? (json_decode($jawaban, true) ?: []) : [];
              $jawabanPilih = ($q['tipe'] === 'mcma' && $jawaban) ? array_map('strval', json_decode($jawaban, true) ?: []) : [];
              $kats = $q['tipe'] === 'kategori' ? soal_kategori_list($q) : [];
              $halamanTerakhir = $i === $totalSoal - 1;
              ?>
            <div class="q-block" id="soal-<?= $qid ?>" data-qp="<?= $i ?>" data-qid="<?= $qid ?>" data-tipe="<?= e($q['tipe']) ?>"<?= $i > 0 ? ' hidden' : '' ?>>
              <div class="q-num"><?= icon('quiz', 16) ?> Soal <?= $i + 1 ?> dari <?= $totalSoal ?> &middot; <span class="muted"><?= e(tipe_soal_label($q['tipe'])) ?> &middot; <?= (float)$q['skor'] ?> poin</span></div>
              <div class="q-text"><?= nl2br(e($q['pertanyaan'])) ?></div>
              <?= gambar_img(soal_gambar($q), 'q-img', 'Gambar soal') ?>
              <?php $gambarOpsi = soal_gambar_opsi($q); ?>

              <?php if ($q['tipe'] === 'mc'): ?>
                <?php foreach ($ord as $idx): ?>
                  <?php if (!isset($opsi[$idx])) { continue; } ?>
                  <label class="opt <?= (string)$jawaban === (string)$idx ? 'sel' : '' ?>">
                    <input type="radio" name="q[<?= $qid ?>]" value="<?= (int)$idx ?>" data-q="<?= $qid ?>" <?= (string)$jawaban === (string)$idx ? 'checked' : '' ?>>
                    <span class="opt-body"><span><?= e($opsi[$idx]) ?></span><?= gambar_img($gambarOpsi[(string)$idx] ?? '', 'opt-img', 'Gambar pilihan ' . chr(65 + (int)$idx)) ?></span>
                  </label>
                <?php endforeach; ?>

              <?php elseif ($q['tipe'] === 'tf'): ?>
                <?php foreach (['benar' => 'Benar (True)', 'salah' => 'Salah (False)'] as $val => $lab): ?>
                  <label class="opt <?= (string)$jawaban === $val ? 'sel' : '' ?>">
                    <input type="radio" name="q[<?= $qid ?>]" value="<?= $val ?>" data-q="<?= $qid ?>" <?= (string)$jawaban === $val ? 'checked' : '' ?>>
                    <span><?= e($lab) ?></span>
                  </label>
                <?php endforeach; ?>

              <?php elseif ($q['tipe'] === 'fill'): ?>
                <input type="text" name="q[<?= $qid ?>]" data-q="<?= $qid ?>" value="<?= e((string)$jawaban) ?>" placeholder="Tulis jawabanmu di sini...">

              <?php elseif ($q['tipe'] === 'mcma'): ?>
                <div class="form-hint mb2"><?= icon('check', 14) ?> Pilih SEMUA jawaban yang benar (bisa lebih dari satu).<?= ($q['penilaian'] ?? 'penuh') === 'parsial' ? ' Skor dihitung sebagian untuk setiap pilihan yang tepat.' : '' ?></div>
                <?php foreach ($ord as $idx): ?>
                  <?php if (!isset($opsi[$idx])) { continue; } ?>
                  <label class="opt <?= in_array((string)$idx, $jawabanPilih, true) ? 'sel' : '' ?>">
                    <input type="checkbox" name="q[<?= $qid ?>][]" value="<?= (int)$idx ?>" data-q="<?= $qid ?>" <?= in_array((string)$idx, $jawabanPilih, true) ? 'checked' : '' ?>>
                    <span class="opt-body"><span><?= e($opsi[$idx]) ?></span><?= gambar_img($gambarOpsi[(string)$idx] ?? '', 'opt-img', 'Gambar pilihan ' . chr(65 + (int)$idx)) ?></span>
                  </label>
                <?php endforeach; ?>

              <?php elseif ($q['tipe'] === 'kategori'): ?>
                <div class="form-hint mb2"><?= icon('filter', 14) ?> Kelompokkan setiap item ke kategori yang tepat.</div>
                <div class="row-gap mb2">
                  <?php foreach ($ord as $kidx): ?>
                    <?php if (!isset($kats[$kidx])) { continue; } ?>
                    <span class="chip solid"><?= e($kats[$kidx]) ?></span>
                  <?php endforeach; ?>
                </div>
                <div class="stack" data-kategori="<?= $qid ?>">
                  <?php foreach ($opsi as $idx => $item): ?>
                    <div class="opt" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                      <span><?= e($item) ?></span>
                      <select name="kat[<?= $qid ?>][<?= (int)$idx ?>]" data-idx="<?= (int)$idx ?>" data-kat="<?= $qid ?>">
                        <option value="">-- pilih kategori --</option>
                        <?php foreach ($ord as $kidx): ?>
                          <?php if (!isset($kats[$kidx])) { continue; } ?>
                          <option value="<?= (int)$kidx ?>" <?= (string)($jawabanArr[(string)$idx] ?? ($jawabanArr[$idx] ?? '')) === (string)$kidx ? 'selected' : '' ?>><?= e($kats[$kidx]) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  <?php endforeach; ?>
                </div>

              <?php elseif ($q['tipe'] === 'matching'): ?>
                <?php
                $kanan = [];
                foreach ($opsi as $p) {
                    $parts = explode('|', (string)$p);
                    $kanan[] = $parts[1] ?? '';
                }
                $kananUrut = [];
                foreach ($ord as $idx) {
                    if (isset($kanan[$idx])) {
                        $kananUrut[$idx] = $kanan[$idx];
                    }
                }
                ?>
                <div data-matching="<?= $qid ?>" class="stack">
                  <?php foreach ($opsi as $idx => $p):
                      $parts = explode('|', (string)$p);
                      $kiri = $parts[0] ?? ''; ?>
                    <div class="opt" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                      <span><?= e($kiri) ?></span>
                      <select name="matching[<?= $qid ?>][<?= (int)$idx ?>]" data-idx="<?= (int)$idx ?>">
                        <option value="">-- pilih pasangan --</option>
                        <?php foreach ($kananUrut as $kidx => $kval): ?>
                          <option value="<?= e($kval) ?>" <?= (($jawabanArr[(string)$idx] ?? '') === $kval) ? 'selected' : '' ?>><?= e($kval) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  <?php endforeach; ?>
                </div>

              <?php else: ?>
                <div class="essay-box">
                  <textarea name="q[<?= $qid ?>]" data-q="<?= $qid ?>" placeholder="Tulis jawaban essay kamu..."><?= e((string)$jawaban) ?></textarea>
                  <div class="form-hint">Jawaban essay dikoreksi manual oleh guru.</div>
                </div>
              <?php endif; ?>

              <div class="q-foot">
                <span class="muted small">Halaman <?= $i + 1 ?> dari <?= $totalSoal ?></span>
                <div class="row-gap">
                  <?php if ($totalSoal > 1): ?>
                    <button type="button" class="btn btn-ghost btn-sm" data-qp-prev <?= $i === 0 ? 'disabled' : '' ?>><?= icon('arrow-left', 16) ?> Kembali</button>
                    <button type="button" class="btn btn-skip btn-sm" data-qp-skip><?= icon('skip', 16) ?> Lewati</button>
                  <?php endif; ?>
                  <?php if ($halamanTerakhir): ?>
                    <button class="btn btn-primary btn-sm" type="submit" data-confirm="Kirim jawaban sekarang? Setelah dikirim kamu tidak bisa mengubahnya."><?= icon('check', 16) ?> Selesai &amp; Kirim Jawaban</button>
                  <?php else: ?>
                    <button type="button" class="btn btn-primary btn-sm" data-qp-next><?= icon('arrow-right', 16) ?> Lanjutkan</button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          </div>

          <div class="card row-between">
            <span class="autosave-note" id="autosave-note">Perubahan jawaban tersimpan otomatis.</span>
            <button class="btn btn-primary" type="submit" data-confirm="Kirim jawaban sekarang?"><?= icon('check', 17) ?> Kirim Jawaban</button>
          </div>
        </div>

        <aside>
          <div class="card" style="position:sticky;top:90px">
            <h3 class="mt0"><?= icon('list', 18) ?> Navigasi Soal</h3>
            <div class="qnav mb3">
              <?php foreach ($soal as $i => $q):
                  $isiJawab = !empty($sudahDijawab[(int)$q['id']]);
                  // Warna: hijau = sudah dijawab, merah = dilewati, orange =
                  // soal yang sedang dikerjakan (halaman aktif). Halaman aktif
                  // yang sudah dijawab tampil hijau dengan cincin orange.
                  $kelas = ['q-done' => $isiJawab, 'q-now' => $i === 0]; ?>
                <a href="#soal-<?= (int)$q['id'] ?>" data-qp-link="<?= $i ?>" class="<?= e(implode(' ', array_keys(array_filter($kelas)))) ?>"><?= $i + 1 ?></a>
              <?php endforeach; ?>
            </div>
            <div class="qnav-legend mb3">
              <span><i class="lg q-now"></i> sedang dikerjakan</span>
              <span><i class="lg q-done"></i> sudah dijawab</span>
              <span><i class="lg q-skip"></i> dilewati</span>
              <span><i class="lg"></i> belum dijawab</span>
            </div>
            <div class="progress-lab"><span>Terjawab</span><strong id="qp-done"><?= $terjawab ?>/<?= $totalSoal ?></strong></div>
            <div class="progress"><span id="qp-bar" style="width:<?= $totalSoal ? round($terjawab / $totalSoal * 100) : 0 ?>%"></span></div>
            <p class="muted small mt2 mb0" id="qp-count"><?= $terjawab ?> terjawab &middot; 0 dilewati &middot; <?= $totalSoal - $terjawab ?> belum dijawab</p>
            <div class="divider"></div>
            <p class="muted small mb0">Jawaban tersimpan otomatis. Saat tidak ada internet, jawabanmu disimpan di perangkat dan dikirim otomatis begitu online lagi.</p>
          </div>
        </aside>
      </div>
    </form>
    <?php
    page_end();
    exit;
}

/* ------------------------------ halaman inti/persiapan ----------------- */
$attemptBerjalan = quiz_attempt_berjalan((int)$quiz['id'], (int)$u['id']);

page_start($quiz['judul'], $quiz['kursus'] ?? '');
?>
<div class="split">
  <div class="card">
    <div class="row-gap mb3">
      <span class="chip solid"><?= $quiz['tipe'] === 'ujian' ? 'Ujian' : 'Quiz' ?></span>
      <span class="chip"><?= icon('clock', 14) ?> <?= (int)$quiz['durasi_menit'] ?> menit</span>
      <span class="chip"><?= icon('check', 14) ?> Nilai minimal <?= (int)$quiz['passing_grade'] ?></span>
      <span class="chip"><?= icon('list', 14) ?> <?= $soalCount ?> soal<?= (int)$quiz['random_soal'] && (int)$quiz['jumlah_soal_tampil'] > 0 ? ' (ditampilkan ' . (int)$quiz['jumlah_soal_tampil'] . ' acak)' : '' ?></span>
      <?php if ((int)$quiz['random_opsi']): ?><span class="chip">Urutan pilihan acak</span><?php endif; ?>
    </div>
    <p><?= nl2br(e($quiz['deskripsi'])) ?></p>
    <div class="divider"></div>
    <h3>Aturan Pengerjaan</h3>
    <ul class="muted small">
      <li>Timer berjalan otomatis dan jawaban dikirim otomatis saat waktu habis.</li>
      <li>Setiap perubahan jawaban tersimpan otomatis (auto-save), jadi aman jika koneksi terputus.</li>
      <li>Nilai dihitung otomatis; soal essay dikoreksi manual oleh guru.</li>
      <?php if ((int)$quiz['tampilkan_pembahasan']): ?><li>Pembahasan jawaban ditampilkan setelah kamu mengirim jawaban.</li><?php endif; ?>
      <?php if ((int)$quiz['max_attempt'] > 0): ?><li>Maksimal <?= (int)$quiz['max_attempt'] ?> kali percobaan.</li><?php endif; ?>
    </ul>
    <div class="row-gap mt3">
      <?php if ($attemptBerjalan): ?>
        <a class="btn btn-primary btn-lg" href="ujian.php?attempt=<?= (int)$attemptBerjalan['id'] ?>"><?= icon('play', 18) ?> Lanjutkan Sesi Berjalan</a>
      <?php elseif ($isGuru): ?>
        <div class="flash flash-info"><?= icon('bell', 17) ?><span>Akun guru/admin tidak mengerjakan quiz. Gunakan menu Koreksi & Nilai untuk melihat hasil siswa.</span></div>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="mulai">
          <button class="btn btn-primary btn-lg" type="submit" data-confirm="Mulai sekarang? Timer akan langsung berjalan."><?= icon('play', 18) ?> Mulai Kerjakan</button>
        </form>
      <?php endif; ?>
      <a class="btn btn-ghost" href="kursus-detail.php?id=<?= (int)$quiz['course_id'] ?>">Kembali ke Kursus</a>
    </div>
  </div>

  <div class="card">
    <h3 class="mt0"><?= icon('chart', 18) ?> Riwayat Pengerjaanmu</h3>
    <?php if (!$riwayat): ?>
      <p class="muted small">Belum ada riwayat. Percobaan terbaikmu akan dicatat di sini.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>#</th><th>Nilai</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($riwayat as $i => $r): ?>
            <tr>
              <td><?= count($riwayat) - $i ?></td>
              <td><strong><?= number_format((float)$r['nilai'], 2) ?></strong></td>
              <td><?= status_badge($r['status']) ?></td>
              <td><a class="btn btn-ghost btn-sm" href="ujian-hasil.php?attempt=<?= (int)$r['id'] ?>">Lihat</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="divider"></div>
      <div class="progress-lab"><span>Nilai terbaik</span><strong><?= number_format($best, 2) ?></strong></div>
      <div class="progress"><span style="width:<?= min(100, $best) ?>%"></span></div>
      <p class="muted small mt2 mb0"><?= $best >= (float)$quiz['passing_grade'] ? 'Selamat, kamu sudah lulus quiz ini.' : 'Nilai kamu masih di bawah batas kelulusan. Perbaiki materi lalu coba lagi.' ?></p>
    <?php endif; ?>
  </div>
</div>
<?php page_end(); ?>
