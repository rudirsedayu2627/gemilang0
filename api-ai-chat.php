<?php
/**
 * Endpoint obrolan "Tanya AI" di halaman materi (dipakai fetch() dari
 * assets/app.js). Kunci API penyedia HANYA dipakai di sisi server — browser
 * tidak pernah menerimanya, dan panggilan ke penyedia AI selalu dari sini.
 *
 * Pemakaian (POST, form-urlencoded):
 *   _csrf=<token>&lesson_id=<id>&aksi=kirim&pesan=<pertanyaan>
 *   _csrf=<token>&lesson_id=<id>&aksi=bersihkan
 * Balasan: {"ok":true,"balasan":"...","html":"<div class=\"ai-msg ai\">..."}
 *       atau {"ok":false,"error":"..."}
 */
require_once __DIR__ . '/includes/ai.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function ai_json($ok, $data = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!ai_aktif()) {
    ai_json(false, ['error' => 'Fitur ' . ai_nama() . ' sedang dinonaktifkan oleh admin.'], 403);
}

$u = current_user();
if (!$u) {
    ai_json(false, ['error' => 'Sesi berakhir. Masuk dulu untuk bertanya ke AI.'], 401);
}
if ($u['status'] !== 'aktif') {
    ai_json(false, ['error' => 'Akunmu tidak aktif.'], 403);
}

$token = $_POST['_csrf'] ?? '';
if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
    ai_json(false, ['error' => 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'], 419);
}

$lessonId = (int)($_POST['lesson_id'] ?? 0);
$stmt = db()->prepare('SELECT l.id, l.judul, l.tipe, l.isi_teks, l.course_id, l.is_preview, co.judul AS kursus
    FROM lessons l LEFT JOIN courses co ON co.id = l.course_id WHERE l.id = ?');
$stmt->execute([$lessonId]);
$lesson = $stmt->fetch();
if (!$lesson) {
    ai_json(false, ['error' => 'Materi tidak ditemukan.'], 404);
}

// Hak baca materi yang sama dengan materi.php: staf selalu boleh, siswa harus
// berakses, materi preview boleh dibaca siapa pun yang sudah masuk.
$staf = in_array($u['role'], ['guru', 'admin'], true);
if (!$staf && (int)$lesson['is_preview'] !== 1) {
    $kursusRow = db()->query('SELECT id, judul, harga FROM courses WHERE id = ' . (int)$lesson['course_id'])->fetch()
        ?: ['id' => (int)$lesson['course_id'], 'judul' => '', 'harga' => 0];
    $akses = cek_akses_kursus((int)$u['id'], $kursusRow);
    if (!$akses['boleh']) {
        ai_json(false, ['error' => $akses['pesan'], 'kode' => 'tanpa_akses'], 403);
    }
}

$aksi = (string)($_POST['aksi'] ?? 'kirim');

if ($aksi === 'bersihkan') {
    $n = ai_bersihkan((int)$u['id'], (int)$lesson['id']);
    audit('ai_hapus_riwayat', 'Menghapus ' . $n . ' pesan ' . ai_nama() . ' pada materi ' . $lesson['judul']);
    ai_json(true, ['dihapus' => $n]);
}

if ($aksi !== 'kirim') {
    ai_json(false, ['error' => 'Aksi tidak dikenal.'], 400);
}

// Jawaban AI bisa makan waktu; beri ruang lebih dari batas waktu PHP bawaan.
@set_time_limit(120);

// Mode percakapan (live) meminta jawaban pendek supaya enak dibacakan.
$mode = ($_POST['mode'] ?? '') === 'suara' ? 'suara' : '';

$hasil = ai_kirim((int)$u['id'], $lesson, (string)($_POST['pesan'] ?? ''), $mode);
if (!$hasil['ok']) {
    ai_json(false, ['error' => $hasil['error']], 400);
}

ai_json(true, [
    'balasan' => $hasil['balasan'],
    'html' => ai_pesan_html('ai', $hasil['balasan'], $u),
]);
