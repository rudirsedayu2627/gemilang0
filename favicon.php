<?php
/**
 * Favicon dinamis — ikon tab mengikuti LOGO website (Pengaturan -> Website).
 *
 * - `site_logo` kosong  -> logo bawaan: berkas assets/favicon.svg
 *   (markup-nya sama dengan logo_svg() di includes/helpers.php).
 * - `site_logo` terisi  -> byte gambar logo dikecilkan ke 64x64 PNG (GD) supaya
 *   favicon tetap ringan (logo unggahan bisa 1024px / ratusan KB), hasilnya
 *   di-cache di folder sementara sistem dan dipakai ulang.
 * - Gagal mengambil gambar / GD tidak ada -> alihkan (302) ke URL logo apa adanya,
 *   jadi favicon tetap tampil walau pemotongan gagal.
 *
 * Dipanggil dari includes/layout.php sebagai `favicon.php?v=<hash logo>` sehingga
 * URL-nya berubah begitu logo diganti (favicon lama tidak nyangkut di cache).
 */
require_once __DIR__ . '/includes/helpers.php';

$logo = trim((string) setting('site_logo'));

/** Kirim logo bawaan (SVG) lalu berhenti. */
function favicon_kirim_svg(): void
{
    $svg = @file_get_contents(__DIR__ . '/assets/favicon.svg');
    if ($svg === false || $svg === '') {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    echo $svg;
    exit;
}

/** Ambil byte gambar logo: URL http(s) atau path relatif di dalam folder app. */
function favicon_ambil(string $src): ?string
{
    if (preg_match('#^https?://#i', $src)) {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($src);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'LMS-Favicon/1.0',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($raw) && $raw !== '') ? $raw : null;
    }
    $rel = ltrim(str_replace('\\', '/', $src), '/');
    if ($rel === '' || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
        return null;
    }
    $path = __DIR__ . '/' . $rel;
    return is_file($path) ? (string) @file_get_contents($path) : null;
}

/** Potong gambar apa pun menjadi PNG 64x64 (proporsional, latar transparan). */
function favicon_kecilkan(string $raw): ?string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagesavealpha')) {
        return null;
    }
    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return null;
    }
    $size = 64;
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagealphablending($dst, true);

    $skala = min($size / $w, $size / $h);
    $dw = max(1, (int) round($w * $skala));
    $dh = max(1, (int) round($h * $skala));
    imagecopyresampled($dst, $src, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
    imagedestroy($src);

    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    ob_start();
    $ok = imagepng($dst);
    $png = (string) ob_get_clean();
    imagedestroy($dst);
    return ($ok && $png !== '') ? $png : null;
}

/** Kirim PNG ke browser lalu berhenti. */
function favicon_kirim_png(string $png): void
{
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    header('Cache-Control: public, max-age=86400');
    echo $png;
    exit;
}

if ($logo === '') {
    favicon_kirim_svg();
}

$dir = sys_get_temp_dir() . '/lms-favicon-' . substr(md5(__DIR__), 0, 8);
$cache = $dir . '/' . md5($logo . '|64') . '.png';
if (is_file($cache) && (int) @filesize($cache) > 0) {
    favicon_kirim_png((string) file_get_contents($cache));
}

$raw = favicon_ambil($logo);
if ($raw === null) {
    header('Location: ' . $logo, true, 302);
    exit;
}
$png = favicon_kecilkan($raw);
if ($png === null) {
    header('Location: ' . $logo, true, 302);
    exit;
}
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
@file_put_contents($cache, $png, LOCK_EX);
favicon_kirim_png($png);
