<?php
/**
 * Materi tipe "Infografis" — ringkasan bacaan (PDF / e-book / scan halaman)
 * yang dirender jadi infografis (kartu angka, langkah, poin, sorotan, tabel).
 *
 * Permintaan pemilik: "Ubah bacaan pdf/ebook menjadi infografis" → ditambahkan
 * sebagai SATU TIPE MATERI baru (`lessons.tipe = 'infografis'`, isinya di kolom
 * `lessons.infografis`). Jadi guru tidak perlu memindahkan apa pun: materi
 * bacaan yang sudah ada cukup diubah tipenya jadi Infografis, isi infografisnya
 * disusun otomatis dari bacaan itu (tombol "Susun dengan AI") lalu bisa disunting.
 *
 * Isi disimpan sebagai TEKS BERFORMAT BARIS (bukan JSON) supaya guru bisa
 * menyuntingnya dengan mudah dan model AI bisa diminta mengeluarkannya langsung
 * — JSON rawan rusak (koma/kutip) kalau disunting manusia. Satu-satunya
 * penerjemah formatnya ada di `infografis_parse()`.
 *
 *   # Judul infografis            (opsional; kalau kosong pakai judul materi)
 *   > Satu kalimat pengantar
 *   ## Judul bagian
 *   * 75% | Nilai lulus           → kartu angka (nilai | label, boleh | ikon)
 *   - Judul poin :: penjelasan    → kartu poin (tanpa "::" = butir biasa)
 *   - Butir biasa
 *   1. Langkah pertama            → langkah bernomor
 *   !! Hal penting yang diingat   → kotak sorotan
 *   | Kepala A | Kepala B         → tabel (baris pertama = kepala)
 *
 * Catatan penting soal sumber bacaan: 71 dari 72 materi pemilik berisi berkas
 * GAMBAR (scan/foto halaman) walau tipenya "PDF / E-book", jadi bacaan yang bisa
 * dibaca AI sering berupa gambar → `ai_panggil_konten()` (includes/ai.php)
 * mengirim gambarnya ke penyedia AI (butuh model yang bisa melihat gambar).
 * Untuk PDF asli, teks diambil dulu (pdftotext bila tersedia, kalau tidak
 * pengekstrak sederhana di berkas ini).
 */

require_once __DIR__ . '/ai.php';

/* ------------------------------------------------------- nama ikon & batas */

/** Nama ikon yang boleh dipakai pada baris statistik (katalog di helpers.php). */
function infografis_ikon_valid($nama)
{
    $nama = trim((string)$nama);
    if ($nama === '') {
        return '';
    }
    return array_key_exists($nama, icon_paths()) ? $nama : '';
}

/** Batas sumber bacaan: ukuran gambar (MB) dan panjang teks yang dikirim ke AI. */
if (!defined('INFOGRAFIS_GAMBAR_MB')) {
    define('INFOGRAFIS_GAMBAR_MB', 6);
}
if (!defined('INFOGRAFIS_TEKS_MAKS')) {
    define('INFOGRAFIS_TEKS_MAKS', 12000);
}

/* ---------------------------------------------------------------- format */

/** Contoh format — dipakai di petunjuk form guru dan di prompt AI. */
function infografis_format_contoh()
{
    return "# Simple Present Tense\n"
        . "> Kebiasaan dan fakta yang terjadi berulang\n"
        . "## Rumus Dasar\n"
        . "* +s/es | Kata kerja orang ketiga\n"
        . "- Subjek I/You/We/They :: pakai kata kerja bentuk dasar\n"
        . "- Subjek He/She/It :: kata kerja ditambah -s atau -es\n"
        . "## Contoh\n"
        . "1. She goes to school every day.\n"
        . "2. They play football on Sunday.\n"
        . "!! Ingat: jangan tambah -s kalau subjeknya I, You, We, They.\n"
        . "| Subjek | Kata kerja |\n| I | play |\n| She | plays |";
}

/** Rapikan teks mentah yang TERSIMPAN (tanpa membuang penanda formatnya). */
function infografis_bersihkan($teks)
{
    $t = str_replace(["\r\n", "\r"], "\n", (string)$teks);
    $t = preg_replace('/[ \t]+$/m', '', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", (string)$t);
    return trim((string)$t);
}

/**
 * Rapikan keluaran AI sebelum ditampilkan/disimpan: buang pagar kode dan
 * penanda tebal/miring (model sering menambahkannya walau sudah diminta jangan).
 * Penanda format infografis (# > * - ! | angka) JANGAN dibuang.
 */
function infografis_bersihkan_ai($teks)
{
    $t = str_replace(["\r\n", "\r"], "\n", (string)$teks);
    $t = preg_replace('/^[ \t]*```[^\n]*$/m', '', $t);
    $t = str_replace(['**', '__', '`'], '', (string)$t);
    $t = preg_replace('/^[ \t]*#{4,}[ \t]*/m', '## ', (string)$t);
    $t = infografis_bersihkan($t);

    // Buang baris pembuka yang BUKAN bagian format (mis. "Berikut infografisnya:",
    // atau sisa judul berpenanda tebal yang tidak diawali penanda apa pun).
    $baris = explode("\n", $t);
    $buang = 0;
    while ($buang < 3 && isset($baris[$buang])) {
        $kandidat = trim($baris[$buang]);
        if ($kandidat === '') {
            $buang++;
            continue;
        }
        if (preg_match('/^([#>!|]|[*•\-]\s|\d{1,2}[.)]\s)/u', $kandidat)) {
            break;
        }
        $buang++;
    }
    if ($buang > 0 && count($baris) > $buang) {
        $t = infografis_bersihkan(implode("\n", array_slice($baris, $buang)));
    }
    return $t;
}

/** Ubah format infografis jadi teks biasa (untuk konteks AI & unduhan ringkasan). */
function infografis_teks_polos($teks)
{
    $t = infografis_bersihkan($teks);
    $t = preg_replace('/^[ \t]*\|/m', '', $t);
    $t = str_replace('|', ' - ', $t);
    $t = preg_replace('/^[ \t]*#{1,6}[ \t]*/m', '', $t);
    $t = preg_replace('/^[ \t]*>[ \t]*/m', '', $t);
    $t = preg_replace('/^[ \t]*!{1,2}[ \t]*/m', '', $t);
    // Penanda butir/statistik di awal baris juga dibuang (bukan bagian teksnya).
    $t = preg_replace('/^[ \t]*[*•\-][ \t]+/m', '', (string)$t);
    $t = preg_replace('/[ \t]*::[ \t]*/u', ' — ', (string)$t);
    return trim((string)$t);
}

/**
 * Terjemahkan teks berformat infografis menjadi struktur blok.
 * Hasil:
 *   ['judul' => '', 'subjudul' => '', 'bagian' => [['judul' => '', 'blok' => [ ... ]]]]
 * Tiap blok: t = stat|poin|daftar|langkah|sorot|tabel|teks.
 */
function infografis_parse($teks)
{
    $t = infografis_bersihkan($teks);
    $out = ['judul' => '', 'subjudul' => '', 'bagian' => []];
    $bagian = ['judul' => '', 'blok' => []];

    // Tutup bagian yang sedang berjalan (hanya bila sudah ada isinya).
    $tutupBagian = function () use (&$out, &$bagian) {
        $adaIsi = $bagian['judul'] !== '' || $bagian['blok'];
        if ($adaIsi) {
            $out['bagian'][] = $bagian;
        }
        $bagian = ['judul' => '', 'blok' => []];
    };

    // Tambahkan blok; blok sejenis yang berurutan DIGABUNG supaya angka/poin
    // berdekatan tersusun rapi dalam satu baris, bukan menumpuk ke bawah.
    $tambah = function ($tipe, $data) use (&$bagian) {
        $jml = count($bagian['blok']);
        if ($jml > 0 && $bagian['blok'][$jml - 1]['t'] === $tipe && in_array($tipe, ['stat', 'poin', 'daftar', 'langkah', 'tabel', 'teks'], true)) {
            if ($tipe === 'teks') {
                $bagian['blok'][$jml - 1]['isi'] .= ' ' . $data['isi'];
            } else {
                $bagian['blok'][$jml - 1]['items'][] = $data;
            }
            return;
        }
        if ($tipe === 'teks') {
            $bagian['blok'][] = ['t' => 'teks', 'isi' => $data['isi']];
            return;
        }
        if ($tipe === 'sorot') {
            $bagian['blok'][] = ['t' => 'sorot', 'judul' => $data['judul'], 'isi' => $data['isi']];
            return;
        }
        $bagian['blok'][] = ['t' => $tipe, 'items' => [$data]];
    };

    foreach (explode("\n", $t) as $baris) {
        $b = trim($baris);
        if ($b === '') {
            continue;
        }

        // Bagian baru / judul / pengantar
        if (preg_match('/^#{2,}\s*(.+)$/u', $b, $m)) {
            $tutupBagian();
            $bagian['judul'] = trim($m[1]);
            continue;
        }
        if (preg_match('/^#\s*(.+)$/u', $b, $m)) {
            if ($out['judul'] === '') {
                $out['judul'] = trim($m[1]);
            }
            continue;
        }
        if (preg_match('/^>\s*(.+)$/u', $b, $m)) {
            if ($out['subjudul'] === '') {
                $out['subjudul'] = trim($m[1]);
            }
            continue;
        }
        // Kotak sorotan
        if (preg_match('/^!{1,2}\s*(.+)$/u', $b, $m)) {
            $isi = trim($m[1]);
            $judulSorot = '';
            if (strpos($isi, '::') !== false) {
                [$judulSorot, $isi] = array_map('trim', explode('::', $isi, 2));
            }
            $tambah('sorot', ['judul' => $judulSorot, 'isi' => $isi]);
            continue;
        }
        // Statistik: * 75% | label | ikon
        if (preg_match('/^[*•]\s*(.+)$/u', $b, $m)) {
            $isi = trim($m[1]);
            $pisah = array_map('trim', explode('|', $isi));
            if (count($pisah) >= 2 && $pisah[0] !== '' && $pisah[1] !== '') {
                $tambah('stat', [
                    'nilai' => $pisah[0],
                    'label' => $pisah[1],
                    'ikon' => infografis_ikon_valid($pisah[2] ?? ''),
                ]);
            } else {
                $tambah('daftar', ['isi' => $isi]);
            }
            continue;
        }
        // Poin / butir
        if (preg_match('/^[-–—]\s*(.+)$/u', $b, $m)) {
            $isi = trim($m[1]);
            if (strpos($isi, '::') !== false) {
                [$judulPoin, $isiPoin] = array_map('trim', explode('::', $isi, 2));
                $tambah('poin', ['judul' => $judulPoin, 'isi' => $isiPoin]);
            } else {
                $tambah('daftar', ['isi' => $isi]);
            }
            continue;
        }
        // Langkah bernomor
        if (preg_match('/^\d{1,2}[.)]\s*(.+)$/u', $b, $m)) {
            $tambah('langkah', ['isi' => trim($m[1])]);
            continue;
        }
        // Tabel
        if (substr($b, 0, 1) === '|' || substr_count($b, '|') >= 2) {
            $sel = array_map('trim', explode('|', trim($b, " \t|")));
            // Baris pemisah markdown (|---|---|) dilewati.
            $hanyaGaris = true;
            foreach ($sel as $s) {
                if (!preg_match('/^:?-{2,}:?$/', $s)) {
                    $hanyaGaris = false;
                    break;
                }
            }
            if (!$hanyaGaris && $sel && $sel[0] !== '') {
                $tambah('tabel', ['sel' => $sel]);
            }
            continue;
        }
        // Paragraf bebas
        $tambah('teks', ['isi' => $b]);
    }
    $tutupBagian();

    return $out;
}

/** Apakah hasil parse punya isi yang layak ditampilkan? */
function infografis_ada_isi($parse)
{
    if (!is_array($parse)) {
        return false;
    }
    foreach ($parse['bagian'] ?? [] as $b) {
        if (!empty($b['blok'])) {
            return true;
        }
    }
    return false;
}

/**
 * Apakah isinya benar-benar BERBENTUK infografis (bukan hanya paragraf biasa)?
 * Dipakai api-infografis.php: balasan model yang cuma kalimat biasa ("Baik,
 * berikut ringkasannya...") harus ditolak dengan pesan jelas, bukan disimpan
 * sebagai infografis yang tampil sebagai satu paragraf panjang.
 */
function infografis_terstruktur($parse)
{
    foreach ($parse['bagian'] ?? [] as $b) {
        foreach ($b['blok'] as $blok) {
            if (($blok['t'] ?? '') !== 'teks') {
                return true;
            }
        }
    }
    return false;
}

/** Jumlah blok total (dipakai petunjuk singkat & uji). */
function infografis_jumlah_blok($parse)
{
    $n = 0;
    foreach ($parse['bagian'] ?? [] as $b) {
        $n += count($b['blok'] ?? []);
    }
    return $n;
}

/* ------------------------------------------------------------- perender */

/**
 * Render isi infografis jadi HTML. $l = baris tabel `lessons`
 * (butuh `infografis`, `judul`). Mengembalikan '' bila belum ada isinya.
 */
function infografis_html($l)
{
    $parse = infografis_parse($l['infografis'] ?? '');
    if (!infografis_ada_isi($parse)) {
        return '';
    }
    $judul = trim((string)$parse['judul']) !== '' ? $parse['judul'] : (string)($l['judul'] ?? '');

    $h = '<div class="info">';
    $h .= '<header class="info-head">'
        . '<span class="info-head-ic">' . icon('chart', 22) . '</span>'
        . '<div class="info-head-teks">'
        . '<h2 class="info-judul">' . e($judul) . '</h2>'
        . ($parse['subjudul'] !== '' ? '<p class="info-sub">' . e($parse['subjudul']) . '</p>' : '')
        . '</div></header>';

    foreach ($parse['bagian'] as $bagian) {
        $h .= '<section class="info-bagian">';
        if ($bagian['judul'] !== '') {
            $h .= '<h3 class="info-bagian-judul"><span class="info-titik"></span>' . e($bagian['judul']) . '</h3>';
        }
        foreach ($bagian['blok'] as $blok) {
            $h .= infografis_blok_html($blok);
        }
        $h .= '</section>';
    }

    return $h . '</div>';
}

/** Render satu blok infografis. */
function infografis_blok_html($blok)
{
    $t = $blok['t'] ?? '';
    if ($t === 'stat') {
        $h = '<div class="info-stat-grid">';
        foreach ($blok['items'] as $it) {
            $h .= '<div class="info-stat">'
                . ($it['ikon'] !== '' ? '<span class="info-stat-ic">' . icon($it['ikon'], 18) . '</span>' : '')
                . '<span class="info-stat-nilai">' . e($it['nilai']) . '</span>'
                . '<span class="info-stat-label">' . e($it['label']) . '</span></div>';
        }
        return $h . '</div>';
    }
    if ($t === 'langkah') {
        $h = '<ol class="info-langkah">';
        $n = 0;
        foreach ($blok['items'] as $it) {
            $n++;
            $h .= '<li><span class="info-langkah-no">' . $n . '</span><span class="info-langkah-teks">' . e($it['isi']) . '</span></li>';
        }
        return $h . '</ol>';
    }
    if ($t === 'poin') {
        $h = '<div class="info-poin-grid">';
        foreach ($blok['items'] as $it) {
            $h .= '<div class="info-poin">';
            if (($it['judul'] ?? '') !== '') {
                $h .= '<h4 class="info-poin-judul">' . e($it['judul']) . '</h4>';
            }
            if (($it['isi'] ?? '') !== '') {
                $h .= '<p class="info-poin-isi">' . e($it['isi']) . '</p>';
            }
            $h .= '</div>';
        }
        return $h . '</div>';
    }
    if ($t === 'daftar') {
        $h = '<ul class="info-daftar">';
        foreach ($blok['items'] as $it) {
            $h .= '<li>' . e($it['isi']) . '</li>';
        }
        return $h . '</ul>';
    }
    if ($t === 'sorot') {
        return '<div class="info-sorot">'
            . '<span class="info-sorot-ic">' . icon('lightbulb', 18) . '</span>'
            . '<div>'
            . (($blok['judul'] ?? '') !== '' ? '<strong>' . e($blok['judul']) . '</strong> ' : '')
            . e((string)($blok['isi'] ?? ''))
            . '</div></div>';
    }
    if ($t === 'tabel') {
        $h = '<div class="table-wrap info-tabel-wrap"><table class="info-tabel"><thead><tr>';
        $kepala = $blok['items'][0]['sel'] ?? [];
        foreach ($kepala as $k) {
            $h .= '<th>' . e($k) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach (array_slice($blok['items'], 1) as $row) {
            $h .= '<tr>';
            foreach ($kepala as $i => $k) {
                $h .= '<td>' . e($row['sel'][$i] ?? '') . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table></div>';
    }
    if ($t === 'teks') {
        return '<p class="info-teks">' . e((string)($blok['isi'] ?? '')) . '</p>';
    }
    return '';
}

/* ------------------------------------------- sumber bacaan untuk AI */

/** Rapikan teks bacaan hasil ekstraksi (tanpa tag, spasi wajar). */
function infografis_teks_rapi($teks, $maks = INFOGRAFIS_TEKS_MAKS)
{
    $t = (string)$teks;
    $t = preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $t);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/[ \t]+/', ' ', $t);
    $t = preg_replace('/ *\n */', "\n", (string)$t);
    $t = preg_replace('/\n{3,}/', "\n\n", (string)$t);
    $t = trim((string)$t);
    if ($maks > 0 && mb_strlen($t) > $maks) {
        $t = mb_substr($t, 0, $maks) . "\n[... dipotong ...]";
    }
    return $t;
}

/** Ekstraksi teks PDF: pdftotext bila ada di server, kalau tidak cara sederhana. */
function infografis_pdf_teks($path)
{
    if (function_exists('shell_exec')) {
        $exe = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
        if ($exe !== '' && is_file($exe)) {
            $keluar = @shell_exec(escapeshellarg($exe) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
            if (is_string($keluar) && trim($keluar) !== '') {
                return infografis_teks_rapi($keluar);
            }
        }
    }
    return infografis_pdf_teks_sederhana($path);
}

/**
 * Pengekstrak PDF sederhana (cadangan bila pdftotext tidak tersedia): buka aliran
 * isi (stream) yang dipadatkan, lalu ambil teks dari operator Tj/TJ. Bekerja untuk
 * PDF berteks biasa; PDF hasil scan tidak akan menghasilkan apa pun.
 */
function infografis_pdf_teks_sederhana($path)
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    $teks = '';
    $pos = 0;
    while (($s = strpos($raw, 'stream', $pos)) !== false) {
        $awal = $s + 6;
        if (substr($raw, $awal, 2) === "\r\n") {
            $awal += 2;
        } elseif (in_array(substr($raw, $awal, 1), ["\n", "\r"], true)) {
            $awal += 1;
        }
        $akhir = strpos($raw, 'endstream', $awal);
        if ($akhir === false) {
            break;
        }
        $isi = substr($raw, $awal, $akhir - $awal);
        $pos = $akhir + 9;
        $dict = substr($raw, max(0, $s - 800), 800);
        if (strpos($dict, 'FlateDecode') !== false) {
            $buka = @gzuncompress($isi);
            if ($buka === false) {
                $buka = @gzinflate($isi);
            }
            if ($buka === false) {
                continue;
            }
            $isi = $buka;
        }
        if (stripos($isi, 'Tj') === false && stripos($isi, 'TJ') === false) {
            continue;
        }
        $teks .= infografis_pdf_konten_teks($isi);
        if (mb_strlen($teks) > INFOGRAFIS_TEKS_MAKS) {
            break;
        }
    }
    return infografis_teks_rapi($teks);
}

/** Ambil teks dari isi satu aliran PDF (operator Tj dan TJ). */
function infografis_pdf_konten_teks($isi)
{
    $out = '';
    if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)\s*(?:Tj|\'|")/s', $isi, $m)) {
        foreach ($m[1] as $s) {
            $out .= infografis_pdf_unescape($s) . "\n";
        }
    }
    if (preg_match_all('/\[((?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ/s', $isi, $m2)) {
        foreach ($m2[1] as $arr) {
            if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/s', $arr, $ms)) {
                $baris = '';
                foreach ($ms[1] as $s) {
                    $baris .= infografis_pdf_unescape($s);
                }
                $out .= $baris . "\n";
            }
        }
    }
    return $out;
}

function infografis_pdf_unescape($s)
{
    $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
        return mb_chr(octdec($m[1]), 'UTF-8');
    }, (string)$s);
    return str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'], ['(', ')', '\\', "\n", "\n", ' '], (string)$s);
}

/** Teks dari berkas epub/docx (keduanya zip berisi xhtml/xml). */
function infografis_zip_teks($path)
{
    if (!class_exists('ZipArchive')) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $teks = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nama = (string)$zip->getNameIndex($i);
        if (preg_match('/\.(x?html?|xml|txt)$/i', $nama) && !preg_match('/META-INF|container\.xml/i', $nama)) {
            $isi = (string)$zip->getFromIndex($i);
            $isi = preg_replace('~<(br|/p|/div|/h[1-6]|/li)\b[^>]*>~i', "\n", $isi);
            $teks .= $isi . "\n\n";
        }
        if (mb_strlen($teks) > INFOGRAFIS_TEKS_MAKS * 2) {
            break;
        }
    }
    $zip->close();
    return infografis_teks_rapi($teks);
}

/** Jenis berkas bacaan dari nama/ekstensi. */
function infografis_jenis_berkas($nama)
{
    $ext = strtolower(pathinfo(parse_url((string)$nama, PHP_URL_PATH) ?: (string)$nama, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        return ['jenis' => 'gambar', 'mime' => $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg'))];
    }
    if ($ext === 'pdf') {
        return ['jenis' => 'pdf', 'mime' => 'application/pdf'];
    }
    if (in_array($ext, ['epub', 'docx', 'zip'], true)) {
        return ['jenis' => 'zip', 'mime' => 'application/zip'];
    }
    if (in_array($ext, ['txt', 'md', 'csv', 'html', 'htm', 'json'], true)) {
        return ['jenis' => 'teks', 'mime' => 'text/plain'];
    }
    return ['jenis' => 'lain', 'mime' => ''];
}

/**
 * Baca satu berkas bacaan menjadi sumber untuk AI:
 *   ['jenis' => 'gambar'|'teks'|'kosong', 'teks' => '...', 'gambar' => ['data'=>..,'mime'=>..], 'catatan' => '...']
 * $sumberNama = nama berkas (untuk menentukan jenis), boleh berbeda dari $path.
 */
function infografis_sumber_berkas($path, $sumberNama = '')
{
    $info = infografis_jenis_berkas($sumberNama !== '' ? $sumberNama : $path);
    $kosong = ['jenis' => 'kosong', 'teks' => '', 'gambar' => null, 'catatan' => ''];

    if ($info['jenis'] === 'gambar') {
        $ukuran = (int)@filesize($path);
        if ($ukuran <= 0) {
            return $kosong;
        }
        if ($ukuran > INFOGRAFIS_GAMBAR_MB * 1024 * 1024) {
            return ['jenis' => 'kosong', 'teks' => '', 'gambar' => null, 'catatan' => 'Gambar bacaan lebih dari ' . INFOGRAFIS_GAMBAR_MB . ' MB sehingga tidak bisa dikirim ke AI. Kecilkan dulu gambarnya.'];
        }
        $data = @file_get_contents($path);
        if (!is_string($data) || $data === '') {
            return $kosong;
        }
        return ['jenis' => 'gambar', 'teks' => '', 'gambar' => ['data' => base64_encode($data), 'mime' => $info['mime']], 'catatan' => ''];
    }

    if ($info['jenis'] === 'pdf') {
        $teks = infografis_pdf_teks($path);
        if (trim($teks) === '') {
            return ['jenis' => 'kosong', 'teks' => '', 'gambar' => null, 'catatan' => 'PDF ini tidak memuat teks yang bisa dibaca (kemungkinan hasil scan/gambar). Kirim halamannya sebagai gambar (JPG/PNG) atau tempel teksnya.'];
        }
        return ['jenis' => 'teks', 'teks' => $teks, 'gambar' => null, 'catatan' => ''];
    }

    if ($info['jenis'] === 'zip') {
        $teks = infografis_zip_teks($path);
        if (trim($teks) === '') {
            return $kosong;
        }
        return ['jenis' => 'teks', 'teks' => $teks, 'gambar' => null, 'catatan' => ''];
    }

    $teks = @file_get_contents($path);
    if (!is_string($teks) || trim($teks) === '') {
        return $kosong;
    }
    return ['jenis' => 'teks', 'teks' => infografis_teks_rapi($teks), 'gambar' => null, 'catatan' => ''];
}

/** Unduh berkas bacaan dari URL (media sendiri/embed) ke berkas sementara. */
function infografis_unduh($url, $maksMB = 12)
{
    $url = trim((string)$url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return '';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'infosrc');
    if ($tmp === false) {
        return '';
    }
    $tulis = @fopen($tmp, 'wb');
    if (!$tulis) {
        @unlink($tmp);
        return '';
    }
    $ok = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $tulis,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'LMS-Infografis/1.0',
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = $ok !== false && $code === 200;
    } else {
        $isi = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60]]));
        if (is_string($isi)) {
            fwrite($tulis, $isi);
            $ok = $isi !== '';
        }
    }
    fclose($tulis);
    if (!$ok || (int)@filesize($tmp) <= 0) {
        @unlink($tmp);
        return '';
    }
    if ((int)@filesize($tmp) > $maksMB * 1024 * 1024) {
        @unlink($tmp);
        return '';
    }
    return $tmp;
}

/**
 * Sumber bacaan dari sebuah materi: berkas media (gambar/PDF) atau isi teksnya.
 */
function infografis_sumber_lesson($l)
{
    $tmp = '';
    $nama = '';
    foreach (['media_url', 'media_embed'] as $kolom) {
        $url = trim((string)($l[$kolom] ?? ''));
        if ($url === '') {
            continue;
        }
        $nama = (string)parse_url($url, PHP_URL_PATH);
        $tujuan = $url;
        // Berkas di folder app sendiri dibaca langsung, URL luar diunduh.
        if (!preg_match('~^https?://~i', $url) && is_file(__DIR__ . '/../' . ltrim($url, '/'))) {
            $tujuan = __DIR__ . '/../' . ltrim($url, '/');
            $tmp = '';
            $sumber = infografis_sumber_berkas($tujuan, $nama);
        } else {
            $tmp = infografis_unduh($url);
            if ($tmp === '') {
                continue;
            }
            $sumber = infografis_sumber_berkas($tmp, $nama !== '' ? $nama : $url);
            @unlink($tmp);
            $tmp = '';
        }
        if ($sumber['jenis'] !== 'kosong') {
            $sumber['nama'] = $nama;
            return $sumber;
        }
        if ($sumber['catatan'] !== '') {
            $sumber['nama'] = $nama;
            return $sumber;
        }
    }

    $teks = infografis_teks_rapi((string)($l['isi_teks'] ?? ''));
    if (trim($teks) !== '') {
        return ['jenis' => 'teks', 'teks' => $teks, 'gambar' => null, 'catatan' => '', 'nama' => 'isi teks materi'];
    }
    return ['jenis' => 'kosong', 'teks' => '', 'gambar' => null, 'catatan' => 'Materi ini belum punya bacaan (berkas maupun teks).', 'nama' => ''];
}

/* ------------------------------------------------------------ penyusun AI */

/** Instruksi ke AI: bentuk keluaran HARUS mengikuti format infografis. */
function infografis_prompt()
{
    return "Kamu menyusun INFOGRAFIS ringkas untuk materi kursus bahasa Inggris.\n"
        . "Bacaan bisa berupa teks, halaman buku hasil scan, atau berkas PDF. Baca dulu seluruh bacaan, lalu ringkas.\n\n"
        . "=== FORMAT KELUARAN (WAJIB, hanya baris-baris ini) ===\n"
        . "# Judul infografis            (maksimal 8 kata)\n"
        . "> Satu kalimat pengantar      (maksimal 20 kata)\n"
        . "## Judul bagian                (maksimal 5 kata, boleh 2-4 bagian)\n"
        . "* 75% | label singkat          (fakta berupa angka; label maksimal 4 kata)\n"
        . "- Judul poin :: penjelasan singkat   (penjelasan maksimal 15 kata)\n"
        . "1. langkah berurutan          (hanya bila bacaan memuat urutan/langkah)\n"
        . "!! Kalimat penting yang harus diingat\n"
        . "| Kepala 1 | Kepala 2          (hanya bila bacaan memuat perbandingan/tabel)\n"
        . "=========================================\n\n"
        . "Aturan:\n"
        . "1. Balas HANYA dengan baris-baris format di atas. Jangan menulis pembuka, penutup, penjelasan tambahan, atau catatan apa pun.\n"
        . "2. Jangan memakai markdown: tanpa tanda bintang ganda, tanpa tanda pagar panjang, tanpa backtick.\n"
        . "3. Maksimal 4 bagian dan total maksimal 14 baris isi. Ringkas, jangan menyalin seluruh bacaan.\n"
        . "4. Statistik (baris berawalan *) hanya bila bacaan benar-benar memuat angka; kalau tidak ada angkanya, jangan dipaksakan.\n"
        . "5. Langkah bernomor hanya bila bacaan berisi urutan/langkah; tabel hanya bila bacaan berisi perbandingan.\n"
        . "6. Setiap baris maksimal 14 kata. Jangan mengarang fakta yang tidak ada di bacaan.\n"
        . "7. Materi bisa berbahasa Indonesia atau Inggris: pakai bahasa yang dipakai di bacaan; istilah bahasa Inggris boleh dipertahankan.\n"
        . "8. Bila bacaan berisi latihan/soal, ringkas aturan dan contoh pentingnya, bukan seluruh soalnya.\n\n"
        . "Contoh bentuk keluaran yang benar:\n"
        . infografis_format_contoh();
}

/**
 * Minta AI menyusun infografis dari sumber bacaan.
 * $sumber = hasil infografis_sumber_lesson()/infografis_sumber_berkas().
 * Melempar RuntimeException dengan pesan yang aman ditampilkan.
 */
function infografis_ai_susun($sumber, $judul, $kursus = '')
{
    $teks = trim((string)($sumber['teks'] ?? ''));
    $kalimat = "Materi: " . ($judul !== '' ? $judul : '(tanpa judul)') . "\n";
    if (trim((string)$kursus) !== '') {
        $kalimat .= "Kursus: " . $kursus . "\n";
    }
    if ($teks !== '') {
        $kalimat .= "Bacaan (teks):\n\"\"\"\n" . $teks . "\n\"\"\"\n";
    }
    if (!empty($sumber['gambar']['data'])) {
        $kalimat .= "Bacaan utama ada pada GAMBAR yang dilampirkan (halaman buku/hasil scan). Baca seluruh tulisan pada gambar itu.\n";
    }
    $kalimat .= "Susun infografisnya sekarang, hanya dengan format yang diminta.";

    $pesan = [['peran' => 'user', 'isi' => $kalimat, 'gambar' => $sumber['gambar'] ?? null]];
    $teksAi = ai_panggil_konten(infografis_prompt(), $pesan, 150, 1500);

    return infografis_bersihkan_ai($teksAi);
}
