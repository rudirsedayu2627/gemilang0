<?php
require_once __DIR__ . '/includes/helpers.php';
$u = require_login();
$l = db()->query('SELECT l.*, co.judul AS kursus FROM lessons l JOIN courses co ON co.id = l.course_id WHERE l.id = ' . num('id'))->fetch();
if (!$l || !(int)$l['bisa_download']) {
    http_response_code(404);
    exit('Materi tidak ditemukan atau tidak dapat diunduh.');
}
$kursusRow = db()->query('SELECT id, judul, harga FROM courses WHERE id = ' . (int)$l['course_id'])->fetch() ?: ['id' => (int)$l['course_id'], 'harga' => 0];
$akses = in_array($u['role'], ['guru', 'admin'], true)
    ? ['boleh' => true, 'pesan' => '']
    : cek_akses_kursus((int)$u['id'], $kursusRow);
if (!$akses['boleh'] && !(int)$l['is_preview']) {
    http_response_code(403);
    exit($akses['pesan'] !== '' ? $akses['pesan'] : 'Kamu belum punya akses ke materi kursus ini.');
}
audit('download', 'Mengunduh ringkasan materi ' . $l['judul']);
$nama = slugify($l['judul']) . '.html';
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nama . '"');
?><!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><title><?= e($l['judul']) ?></title>
<style>body{font-family:Georgia,serif;max-width:760px;margin:40px auto;padding:0 24px;line-height:1.7;color:#222}h1{font-size:1.6rem}small{color:#666}
.box{border:1px solid #ddd;border-radius:10px;padding:16px;margin:18px 0;background:#fafafa}hr{border:0;border-top:1px solid #ddd;margin:24px 0}</style>
</head><body>
<small><?= e(setting('site_name')) ?> &middot; <?= e($l['kursus']) ?></small>
<h1><?= e($l['judul']) ?></h1>
<div class="box"><strong>Tipe materi:</strong> <?= e(tipe_materi_label($l['tipe'])) ?><?php if ($l['durasi_menit']): ?> &middot; <strong>Estimasi:</strong> <?= (int)$l['durasi_menit'] ?> menit<?php endif; ?></div>
<?php if ($l['media_url']): ?><p><strong>Lampiran:</strong> <a href="<?= e($l['media_url']) ?>"><?= e($l['media_url']) ?></a></p><?php endif; ?>
<hr>
<div><?= nl2br(e($l['isi_teks'])) ?></div>
<hr>
<small>Diunduh oleh <?= e($u['nama']) ?> pada <?= e(tgl_id(date('Y-m-d H:i:s'), true)) ?></small>
</body></html>
