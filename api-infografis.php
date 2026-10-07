<?php
/**
 * Endpoint "Susun infografis" (dipakai tombol Susun dengan AI di form materi guru,
 * lewat fetch() dari assets/app.js).
 *
 * Pemakaian (POST, form-urlencoded atau multipart):
 *   _csrf=<token>&lesson_id=<id materi>&judul=<judul materi>
 *   &sumber=<teks bacaan yang ditempel>            (opsional)
 *   &sumber_file=<berkas bacaan: pdf/gambar/txt>   (opsional, multipart)
 *
 * Sumber bacaan yang dipakai, berurutan: berkas yang diunggah → teks yang
 * ditempel → berkas/isi materi itu sendiri. Balasan:
 *   {"ok":true,"teks":"<isi infografis berformat>","jenis":"gambar|teks",...}
 *   {"ok":false,"error":"..."}
 *
 * Kunci API penyedia AI hanya dipakai di sisi server (includes/ai.php).
 */
require_once __DIR__ . '/includes/infografis.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function info_json($ok, $data = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    info_json(false, ['error' => 'Metode tidak didukung. Kirim lewat tombol "Susun dengan AI" di form materi.'], 405);
}
// Berkas yang melebihi batas server (post_max_size) membuat $_POST dan $_FILES
// KOSONG tanpa pesan apa pun. Tanpa pemeriksaan ini guru hanya melihat kegagalan
// yang membingungkan ("tidak ada bacaan") padahal berkasnya cuma terlalu besar.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    info_json(false, ['error' => 'Berkas yang dikirim terlalu besar untuk server (batas ' . (ini_get('post_max_size') ?: '8M') . '). Kirim halamannya satu per satu sebagai gambar JPG/PNG, atau kecilkan berkasnya.'], 413);
}

if (!ai_aktif()) {
    info_json(false, ['error' => 'Fitur ' . ai_nama() . ' sedang dinonaktifkan oleh admin (Pengaturan → AI).'], 403);
}
if (!ai_siap()) {
    info_json(false, ['error' => 'Kunci API belum diisi admin di Pengaturan → AI, jadi infografis belum bisa disusun otomatis. Isi infografisnya manual saja.'], 403);
}

$u = current_user();
if (!$u) {
    info_json(false, ['error' => 'Sesi berakhir. Masuk dulu untuk menyusun infografis.'], 401);
}
if (!in_array($u['role'] ?? '', ['guru', 'admin'], true)) {
    info_json(false, ['error' => 'Hanya guru/admin yang bisa menyusun infografis materi.'], 403);
}

$token = $_POST['_csrf'] ?? '';
if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
    info_json(false, ['error' => 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'], 419);
}

// Materi (opsional): dipakai sebagai sumber bacaan dan untuk memastikan guru
// hanya menyusun materi pada kursus miliknya.
$lesson = null;
$lessonId = (int)($_POST['lesson_id'] ?? 0);
if ($lessonId > 0) {
    $stmt = db()->prepare('SELECT l.*, co.judul AS kursus, co.guru_id FROM lessons l LEFT JOIN courses co ON co.id = l.course_id WHERE l.id = ?');
    $stmt->execute([$lessonId]);
    $lesson = $stmt->fetch() ?: null;
    if (!$lesson) {
        info_json(false, ['error' => 'Materi tidak ditemukan.'], 404);
    }
    if ($u['role'] === 'guru' && (int)$lesson['guru_id'] !== (int)$u['id']) {
        info_json(false, ['error' => 'Materi ini bukan milik kursus yang kamu ampu.'], 403);
    }
}

$judul = trim((string)($_POST['judul'] ?? ($lesson['judul'] ?? '')));

/* ---------------------------------------------------------- sumber bacaan */

$sumber = null;
// Berkas yang gagal diunggah (mis. melebihi batas server) diberi pesan sendiri —
// jangan sampai jatuh diam-diam ke sumber lain sehingga guru bingung.
$errUnggah = $_FILES['sumber_file']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($errUnggah !== UPLOAD_ERR_NO_FILE && $errUnggah !== UPLOAD_ERR_OK) {
    $pesanUnggah = [
        UPLOAD_ERR_INI_SIZE => 'Berkas bacaan terlalu besar untuk server. Pilih berkas yang lebih kecil, kirim per halaman, atau tempel teksnya.',
        UPLOAD_ERR_FORM_SIZE => 'Berkas bacaan terlalu besar.',
        UPLOAD_ERR_PARTIAL => 'Berkas bacaan terputus saat diunggah. Coba lagi.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server tidak punya folder berkas sementara.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis berkas bacaan.',
    ][$errUnggah] ?? 'Berkas bacaan gagal diunggah. Coba lagi.';
    info_json(false, ['error' => $pesanUnggah], 400);
}
if (!empty($_FILES['sumber_file']['name']) && $errUnggah === UPLOAD_ERR_OK) {
    $nama = (string)$_FILES['sumber_file']['name'];
    if (!is_uploaded_file($_FILES['sumber_file']['tmp_name'])) {
        info_json(false, ['error' => 'Berkas bacaan gagal dibaca. Coba unggah ulang.'], 400);
    }
    $sumber = infografis_sumber_berkas($_FILES['sumber_file']['tmp_name'], $nama);
}

if (!$sumber) {
    $teksTempel = trim((string)($_POST['sumber'] ?? ''));
    if ($teksTempel !== '') {
        $sumber = ['jenis' => 'teks', 'teks' => infografis_teks_rapi($teksTempel), 'gambar' => null, 'catatan' => '', 'nama' => 'teks yang ditempel'];
    }
}
if (!$sumber && $lesson) {
    $sumber = infografis_sumber_lesson($lesson);
}
if (!$sumber) {
    info_json(false, ['error' => 'Tidak ada bacaan yang bisa dibaca. Tempel teksnya, pilih berkas bacaan, atau simpan dulu berkas/isi teks pada materi ini.'], 400);
}
if (($sumber['jenis'] ?? 'kosong') === 'kosong') {
    info_json(false, ['error' => $sumber['catatan'] !== '' ? $sumber['catatan'] : 'Bacaan tidak bisa dibaca.'], 400);
}
if (($sumber['jenis'] ?? '') === 'teks' && mb_strlen(trim((string)$sumber['teks'])) < 20) {
    info_json(false, ['error' => 'Bacaan terlalu pendek untuk dijadikan infografis. Tambahkan teksnya dulu (minimal beberapa kalimat).'], 400);
}

/* --------------------------------------------------------------- panggil AI */

@set_time_limit(180);
try {
    $teks = infografis_ai_susun($sumber, $judul, (string)($lesson['kursus'] ?? ''));
} catch (Throwable $e) {
    info_json(false, ['error' => $e->getMessage()], 400);
}

$parse = infografis_parse($teks);
if (!infografis_terstruktur($parse)) {
    info_json(false, ['error' => 'Penyedia AI membalas, tetapi hasilnya belum berbentuk infografis. Coba tekan "Susun dengan AI" sekali lagi, atau tulis infografisnya manual.'], 400);
}

audit('infografis_ai', 'Menyusun infografis materi ' . ($judul !== '' ? $judul : '(tanpa judul)') . ' dari ' . ($sumber['jenis'] ?? 'teks') . ' (' . mb_strlen((string)($sumber['teks'] ?? '')) . ' huruf teks)');

info_json(true, [
    'teks' => $teks,
    'jenis' => $sumber['jenis'],
    'panjang' => mb_strlen((string)($sumber['teks'] ?? '')),
    'blok' => infografis_jumlah_blok($parse),
    'catatan' => (string)($sumber['catatan'] ?? ''),
]);
