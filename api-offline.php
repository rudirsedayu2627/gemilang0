<?php
/**
 * Endpoint "Mode Offline" (aplikasi Android).
 *
 * Aplikasi Android menyimpan halaman quiz beserta asetnya di perangkat supaya
 * bisa dikerjakan tanpa internet. Endpoint ini yang menyiapkannya di sisi server:
 *
 *   aksi=siap   (POST)  -> siapkan sesi ujian untuk dikerjakan offline.
 *                          Mengembalikan id attempt + URL halaman yang harus
 *                          disimpan aplikasi. Sesi itu sengaja dibuat dengan
 *                          `batas_waktu = NULL` (lihat quiz_mulai_attempt):
 *                          timer-nya baru berjalan saat quiz benar-benar dibuka
 *                          di perangkat (dihitung skrip halaman), jadi unduhan
 *                          yang lama tidak keburu "hangus" sebelum dikerjakan.
 *   aksi=batal  (POST)  -> buang sesi offline yang belum diisi sama sekali
 *                          (mis. pemilik menekan "Hapus dari perangkat"). Sesi
 *                          yang sudah ada jawabannya TIDAK dihapus.
 *   aksi=status (GET)   -> status beberapa attempt sekaligus, dipakai halaman
 *                          "Materi Offline" untuk merapikan salinan lokal yang
 *                          quiznya sudah selesai.
 *   aksi=ping   (GET)   -> penanda koneksi ringan untuk skrip halaman.
 *
 * Semua jawaban offline TIDAK dikirim ke sini: skrip halaman menyimpannya di
 * perangkat lalu mengirimnya begitu online lewat api-autosave.php + ujian.php
 * (jalur yang sama dengan pengerjaan biasa), jadi penilaian tetap satu pintu.
 */
require_once __DIR__ . '/includes/helpers.php';
header('Content-Type: application/json; charset=UTF-8');

function luring_keluar(array $data, $kode = 200)
{
    http_response_code($kode);
    echo json_encode($data);
    exit;
}

$u = current_user();
if (!$u) {
    luring_keluar(['ok' => false, 'error' => 'Belum masuk'], 401);
}

$aksi = post('aksi') !== '' ? post('aksi') : get('aksi');
$staf = in_array($u['role'], ['guru', 'admin'], true);

/* --------------------------------------------------------------- ping --- */
if ($aksi === 'ping') {
    luring_keluar(['ok' => true, 'jam' => date('H:i:s')]);
}

/* --------------------------------------------------------------- siap --- */
if ($aksi === 'siap') {
    csrf_check();
    $quizId = (int)post('quiz');
    $st = db()->prepare('SELECT * FROM quizzes WHERE id = ?');
    $st->execute([$quizId]);
    $quiz = $st->fetch();
    if (!$quiz) {
        luring_keluar(['ok' => false, 'error' => 'Quiz tidak ditemukan'], 404);
    }
    if (!$staf) {
        // Sama seperti ujian.php: kursus berbayar harus sudah terbuka.
        $ks = db()->prepare('SELECT id, harga FROM courses WHERE id = ?');
        $ks->execute([(int)$quiz['course_id']]);
        $kursus = $ks->fetch();
        if ($kursus) {
            $akses = cek_akses_kursus((int)$u['id'], $kursus);
            if (!$akses['boleh']) {
                luring_keluar(['ok' => false, 'error' => $akses['pesan'], 'terkunci' => true]);
            }
        }
    }
    $jumlahSoal = count(quiz_soal($quizId));
    if ($jumlahSoal === 0) {
        luring_keluar(['ok' => false, 'error' => 'Quiz ini belum memiliki soal']);
    }

    // Sesi yang masih berjalan dipakai ulang supaya menekan "Unduh" berkali-kali
    // tidak menghabiskan jatah percobaan (max_attempt).
    $berjalan = quiz_attempt_berjalan($quizId, (int)$u['id']);
    if ($berjalan) {
        $attemptId = (int)$berjalan['id'];
    } else {
        if (!quiz_boleh_mulai($quiz, (int)$u['id'])) {
            luring_keluar(['ok' => false, 'error' => 'Batas percobaan quiz ini (' . (int)$quiz['max_attempt'] . 'x) sudah tercapai.']);
        }
        // false = tanpa batas waktu: timer dimulai perangkat saat quiz dibuka.
        $attemptId = quiz_mulai_attempt($quiz, (int)$u['id'], false);
        if (!$attemptId) {
            luring_keluar(['ok' => false, 'error' => 'Quiz ini belum memiliki soal']);
        }
        audit('siap_luring', 'Menyiapkan "' . $quiz['judul'] . '" untuk dikerjakan offline');
    }

    luring_keluar([
        'ok' => true,
        'attempt' => $attemptId,
        'url' => 'ujian.php?attempt=' . $attemptId,
        'quiz' => $quizId,
        'judul' => $quiz['judul'],
        'kursus' => (string)db()->query('SELECT judul FROM courses WHERE id = ' . (int)$quiz['course_id'])->fetchColumn(),
        'jumlah_soal' => $jumlahSoal,
        'menit' => (int)$quiz['durasi_menit'],
    ]);
}

/* -------------------------------------------------------------- batal --- */
if ($aksi === 'batal') {
    csrf_check();
    $attemptId = (int)post('attempt');
    $st = db()->prepare('SELECT * FROM quiz_attempts WHERE id = ? AND user_id = ?');
    $st->execute([$attemptId, (int)$u['id']]);
    $attempt = $st->fetch();
    if (!$attempt) {
        luring_keluar(['ok' => false, 'error' => 'Sesi tidak ditemukan'], 404);
    }
    if ($attempt['status'] !== 'in_progress') {
        luring_keluar(['ok' => false, 'error' => 'Sesi sudah selesai', 'selesai' => true]);
    }
    $adaJawaban = (int)db()->query('SELECT COUNT(*) FROM attempt_answers WHERE attempt_id = ' . $attemptId . ' AND jawaban IS NOT NULL AND jawaban != ""')->fetchColumn();
    if ($adaJawaban > 0) {
        // Jangan sampai jawaban yang belum terkirim ikut terhapus.
        luring_keluar(['ok' => false, 'error' => 'Sudah ada jawaban tersimpan', 'ada_jawaban' => true]);
    }
    db()->prepare('DELETE FROM attempt_answers WHERE attempt_id = ?')->execute([$attemptId]);
    db()->prepare('DELETE FROM quiz_attempts WHERE id = ?')->execute([$attemptId]);
    audit('batal_luring', 'Membatalkan sesi offline #' . $attemptId);
    luring_keluar(['ok' => true]);
}

/* ------------------------------------------------------------- status --- */
if ($aksi === 'status') {
    $mentah = (string)get('attempts');
    $ids = [];
    foreach (explode(',', $mentah) as $x) {
        $x = (int)trim($x);
        if ($x > 0) {
            $ids[] = $x;
        }
        if (count($ids) >= 40) {
            break;
        }
    }
    if (!$ids) {
        luring_keluar(['ok' => true, 'attempt' => (object)[]]);
    }
    $in = implode(',', array_map('intval', $ids));
    $sql = 'SELECT * FROM quiz_attempts WHERE id IN (' . $in . ')';
    if (!$staf) {
        $sql .= ' AND user_id = ' . (int)$u['id'];
    }
    $hasil = [];
    foreach (db()->query($sql)->fetchAll() as $a) {
        $hasil[(string)$a['id']] = [
            'status' => $a['status'],
            'selesai' => $a['status'] !== 'in_progress',
            'nilai' => $a['nilai'] === null ? null : (float)$a['nilai'],
            'hasil_url' => 'ujian-hasil.php?attempt=' . (int)$a['id'],
        ];
    }
    luring_keluar(['ok' => true, 'attempt' => $hasil]);
}

luring_keluar(['ok' => false, 'error' => 'Aksi tidak dikenal'], 400);
