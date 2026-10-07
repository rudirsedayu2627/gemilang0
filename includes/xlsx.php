<?php
/**
 * Pembaca & penulis Excel (.xlsx) minimal tanpa dependensi eksternal.
 * Dipakai untuk import bank soal (Excel/CSV) dan export hasil ujian.
 */

function xlsx_col_index($ref)
{
    preg_match('/^([A-Z]+)/', strtoupper((string)$ref), $m);
    if (!$m) {
        return 0;
    }
    $letters = $m[1];
    $idx = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - 64);
    }
    return $idx - 1;
}

function xlsx_shared_strings(ZipArchive $zip)
{
    $out = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) === false) {
        return $out;
    }
    $doc = @simplexml_load_string($xml);
    if (!$doc) {
        return $out;
    }
    foreach ($doc->si as $si) {
        $text = '';
        if (isset($si->t)) {
            $text = (string)$si->t;
        } elseif (isset($si->r)) {
            foreach ($si->r as $r) {
                $text .= (string)$r->t;
            }
        }
        $out[] = $text;
    }
    return $out;
}

/** Baca sheet pertama file xlsx menjadi array baris (array of string). */
function xlsx_read($path)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi zip tidak tersedia untuk membaca file Excel.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('File Excel tidak bisa dibuka.');
    }
    // cari worksheet pertama
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb && $rels) {
        $doc = @simplexml_load_string($wb);
        $relDoc = @simplexml_load_string($rels);
        if ($doc && $relDoc && isset($doc->sheets->sheet[0])) {
            $rid = (string)$doc->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            foreach ($relDoc->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheetPath = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                }
            }
        }
    }
    $sheetXml = $zip->getFromName($sheetPath);
    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException('Sheet pertama tidak ditemukan pada file Excel.');
    }
    $shared = xlsx_shared_strings($zip);
    $zip->close();

    // Parser berbasis regex-ampas sengaja dipakai untuk semua file: sel kosong
    // ditulis Excel sebagai <c r="G2"/> (self-closing) dan XMLReader mudah
    // salah menggeser kursor pada bentuk itu. File bank soal selalu kecil.
    return xlsx_parse_rows_fallback($sheetXml, $shared);
}

/** Baca CSV menjadi array baris. */
function csv_read($path)
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('File CSV tidak bisa dibuka.');
    }
    $first = true;
    while (($data = fgetcsv($fh, 0, ',')) !== false) {
        if ($first) {
            $first = false;
            $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($data[0] ?? ''));
        }
        $rows[] = array_map(function ($v) {
            return trim((string)$v);
        }, $data);
    }
    fclose($fh);
    return $rows;
}

function xlsx_escape($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Pembaca cadangan bila ekstensi XMLReader tidak tersedia. */
function xlsx_parse_rows_fallback($sheetXml, array $shared)
{
    $rows = [];
    if (!preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheetXml, $rowMatches)) {
        return $rows;
    }
    foreach ($rowMatches[1] as $rowXml) {
        $line = [];
        // Alternatif self-closing (<c r="G2"/>) HARUS diuji lebih dulu, kalau tidak
        // pola sel biasa akan menelan isi sel berikutnya.
        if (preg_match_all('/<c([^>]*?)\/>|<c([^>]*?)>(.*?)<\/c>/s', $rowXml, $cellMatches, PREG_SET_ORDER)) {
            foreach ($cellMatches as $cm) {
                $isSelfClosing = !isset($cm[3]) || $cm[3] === '';
                $attrs = ($cm[1] ?? '') !== '' ? $cm[1] : ($cm[2] ?? '');
                $inner = $isSelfClosing && ($cm[1] ?? '') !== '' ? '' : ($cm[3] ?? '');
                preg_match('/r="([A-Z]+\d+)"/', $attrs, $rm);
                $idx = isset($rm[1]) ? xlsx_col_index($rm[1]) : count($line);
                preg_match('/t="([^"]+)"/', $attrs, $tm);
                $type = $tm[1] ?? '';
                $value = '';
                if ($type === 's') {
                    preg_match('/<v>(.*?)<\/v>/s', $inner, $vm);
                    $value = $shared[(int)($vm[1] ?? 0)] ?? '';
                } elseif ($type === 'inlineStr') {
                    preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm2);
                    $value = implode('', array_map(function ($x) {
                        return html_entity_decode($x, ENT_QUOTES, 'UTF-8');
                    }, $tm2[1]));
                } else {
                    preg_match('/<v>(.*?)<\/v>/s', $inner, $vm);
                    $value = html_entity_decode($vm[1] ?? '', ENT_QUOTES, 'UTF-8');
                }
                $line[$idx] = trim((string)$value);
            }
        }
        if ($line) {
            $max = max(array_keys($line));
            $out = [];
            for ($i = 0; $i <= $max; $i++) {
                $out[$i] = $line[$i] ?? '';
            }
            $rows[] = $out;
        }
    }
    return $rows;
}

/** Tulis file xlsx sederhana (inline string) ke output stream / path. */
function xlsx_write($rows, $path = null)
{
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $r => $cols) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach ($cols as $c => $val) {
            $ref = '';
            $n = $c;
            do {
                $ref = chr(65 + ($n % 26)) . $ref;
                $n = intdiv($n, 26) - 1;
            } while ($n >= 0);
            $ref .= ($r + 1);
            if (is_numeric($val) && !preg_match('/^0\d/', (string)$val)) {
                $sheet .= '<c r="' . $ref . '"><v>' . xlsx_escape($val) . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_escape($val) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    if ($path !== null) {
        copy($tmp, $path);
        @unlink($tmp);
        return $path;
    }
    return $tmp;
}

function xlsx_download($filename, $rows)
{
    $tmp = xlsx_write($rows);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/** Template import bank soal (kolom: TIPE, PERTANYAAN, A, B, C, D, E, JAWABAN, PEMBAHASAN, SKOR). */
function xlsx_template_soal()
{
    return [
        ['TIPE', 'PERTANYAAN', 'A', 'B', 'C', 'D', 'E', 'JAWABAN', 'PEMBAHASAN', 'SKOR', 'PENILAIAN', 'GAMBAR', 'GAMBAR_A', 'GAMBAR_B', 'GAMBAR_C', 'GAMBAR_D', 'GAMBAR_E'],
        ['mc', 'She ___ English every morning.', 'study', 'studies', 'studying', 'studied', '', 'B', 'Subjek she memakai V1+s.', '1', ''],
        ['mcma', 'Pilih SEMUA kalimat yang memakai simple present tense.',
            'She teaches English every day.', 'They are playing football now.', 'I usually drink coffee.', 'He went to Jakarta yesterday.', 'We do not like durian.',
            'A,C,E', 'Kebiasaan/fakta memakai simple present.', '3', 'parsial'],
        ['kategori', 'Kelompokkan setiap kata ke kategori yang tepat.',
            'Fruit (Buah)', 'Vegetable (Sayur)', '', '', '',
            'Apple=A;Carrot=B;Mango=A;Spinach=B', 'Kolom A-E = nama kategori; JAWABAN = Item=HurufKategori.', '4', ''],
        ['tf', 'Kalimat "Does he like coffee?" adalah present tense.', '', '', '', '', '', 'benar', 'Does + V1 untuk orang ketiga tunggal.', '1', ''],
        ['fill', 'They ___ (be) students.', '', '', '', '', '', 'are', 'Subjek they memakai are.', '1', ''],
        ['matching', 'Pasangkan kata dengan artinya.', 'Book|Buku', 'Teacher|Guru', 'Chair|Kursi', '', '', '', 'Gunakan format Kiri|Kanan per kolom.', '3', ''],
        ['essay', 'Tulis perkenalan diri 3 kalimat dalam bahasa Inggris.', '', '', '', '', '', '', 'Perhatikan grammar dan kelengkapan.', '5', ''],
    ];
}
