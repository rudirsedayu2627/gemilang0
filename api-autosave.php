<?php
require_once __DIR__ . '/includes/helpers.php';
header('Content-Type: application/json; charset=UTF-8');

$u = current_user();
if (!$u) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Belum masuk']);
    exit;
}

$attemptId = (int)post('attempt_id');
$stmt = db()->prepare('SELECT * FROM quiz_attempts WHERE id = ? AND user_id = ?');
$stmt->execute([$attemptId, $u['id']]);
$attempt = $stmt->fetch();
if (!$attempt) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Sesi ujian tidak ditemukan']);
    exit;
}
// Kursus berbayar yang belum terverifikasi tidak boleh mengirim jawaban.
if ($u['role'] === 'siswa') {
    $kursusQuiz = db()->prepare('SELECT c.id, c.harga FROM quizzes qz JOIN courses c ON c.id = qz.course_id WHERE qz.id = ?');
    $kursusQuiz->execute([(int)$attempt['quiz_id']]);
    $kq = $kursusQuiz->fetch();
    if ($kq) {
        $aksesAuto = cek_akses_kursus((int)$u['id'], $kq);
        if (!$aksesAuto['boleh']) {
            echo json_encode(['ok' => false, 'error' => 'Akses kursus belum terbuka: ' . $aksesAuto['pesan'], 'terkunci' => true]);
            exit;
        }
    }
}

if ($attempt['status'] !== 'in_progress') {
    echo json_encode(['ok' => true, 'done' => true, 'remaining' => 0, 'forced' => true]);
    exit;
}

$remaining = attempt_remaining_seconds($attempt);
if ($remaining !== null && $remaining <= 0) {
    grade_attempt($attemptId, 1);
    echo json_encode(['ok' => true, 'remaining' => 0, 'forced' => true]);
    exit;
}

$order = json_decode($attempt['soal_order'] ?: '[]', true) ?: [];
$targetId = post('question_id');
$qids = [];
if ($targetId === '' || $targetId === 'all') {
    $qids = array_map('intval', $order);
} else {
    $qids = [(int)$targetId];
}

$soalStmt = db()->prepare('SELECT * FROM questions WHERE id = ?');
$upd = db()->prepare('UPDATE attempt_answers SET jawaban = ?, updated_at = CURRENT_TIMESTAMP WHERE attempt_id = ? AND question_id = ?');

foreach ($qids as $qid) {
    $soalStmt->execute([$qid]);
    $q = $soalStmt->fetch();
    if (!$q) {
        continue;
    }
    $jawaban = null;
    $adaInput = false; // apakah form benar-benar mengirim jawaban untuk soal ini
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
            // Semua pasangan dikosongkan = soal belum dijawab (bukan map kosong).
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
    } elseif ($q['tipe'] === 'kategori') {
        // Kirim dari [data-kategori] sebagai kat[question_id][index_item].
        if (isset($_POST['kat'][$qid]) && is_array($_POST['kat'][$qid])) {
            $adaInput = true;
            $val = [];
            foreach ($_POST['kat'][$qid] as $k => $v) {
                $v = trim((string)$v);
                if ($v !== '') {
                    $val[(string)$k] = $v;
                }
            }
            // Peta item -> kategori disimpan sebagai objek JSON.
            $jawaban = $val ? json_encode((object)$val) : null;
        } elseif (is_string(post('kategori_json')) && post('kategori_json') !== '') {
            $adaInput = true;
            $decoded = json_decode((string)post('kategori_json'), true);
            $jawaban = is_array($decoded) && $decoded ? json_encode($decoded) : null;
        }
    } elseif (isset($_POST['q'][$qid])) {
        $adaInput = true;
        $raw = $_POST['q'][$qid];
        if (is_array($raw)) {
            // MCMA: array pilihan yang dicentang -> JSON indeks.
            $jawaban = $raw ? json_encode(array_values(array_map('strval', $raw))) : null;
        } else {
            $raw = trim((string)$raw);
            $jawaban = $raw === '' ? null : $raw;
        }
    }
    if (isset($_POST['q_present'][$qid])) {
        // Form menandai soal ini memang ikut dikirim (mis. MCMA yang dikosongkan).
        $adaInput = true;
    }
    if ($targetId === 'all' && !$adaInput) {
        continue; // jangan hapus jawaban lama saat auto-save masal
    }
    $upd->execute([$jawaban, $attemptId, $qid]);
}

echo json_encode([
    'ok' => true,
    'remaining' => $remaining,
    'jam' => date('H:i:s'),
]);
