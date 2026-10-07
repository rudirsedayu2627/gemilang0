<?php
/**
 * Unggah gambar untuk soal (dipakai editor bank soal guru/admin lewat fetch()).
 * Berkas TIDAK pernah disimpan ke disk server ini: byte-nya diteruskan ke media
 * proxy platform oleh media_upload() (token hanya ada di sisi server).
 * Balasan: {"ok":true,"url":"https://..."} atau {"ok":false,"error":"..."}
 */
require_once __DIR__ . '/includes/helpers.php';
header('Content-Type: application/json; charset=UTF-8');

function media_upload_json($ok, $data, $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_SLASHES);
    exit;
}

$u = current_user();
if (!$u) {
    media_upload_json(false, ['error' => 'Sesi berakhir. Muat ulang halaman lalu masuk lagi.'], 401);
}
if (!in_array($u['role'], ['guru', 'admin'], true)) {
    media_upload_json(false, ['error' => 'Hanya guru/admin yang boleh mengunggah gambar soal.'], 403);
}
$t = $_POST['_csrf'] ?? '';
if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
    media_upload_json(false, ['error' => 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'], 419);
}

if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $pesan = $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
        ? 'Berkas terlalu besar (maksimal 15 MB).'
        : 'Tidak ada berkas gambar yang dikirim.';
    media_upload_json(false, ['error' => $pesan], 400);
}

$nama = (string)($_FILES['file']['name'] ?? 'gambar.png');
$ext = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
    media_upload_json(false, ['error' => 'Format gambar harus JPG, PNG, GIF, WEBP, atau SVG.'], 400);
}
if ((int)($_FILES['file']['size'] ?? 0) > 15 * 1024 * 1024) {
    media_upload_json(false, ['error' => 'Ukuran gambar melebihi 15 MB.'], 400);
}

try {
    $url = media_upload($_FILES['file']['tmp_name'], $nama, $_FILES['file']['type'] ?? 'application/octet-stream');
} catch (Throwable $e) {
    media_upload_json(false, ['error' => $e->getMessage()], 400);
}

$url = gambar_url_valid($url);
if ($url === '') {
    media_upload_json(false, ['error' => 'Media proxy tidak mengembalikan URL gambar yang valid.'], 502);
}
media_upload_json(true, ['url' => $url]);
