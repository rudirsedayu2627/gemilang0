<?php
/**
 * Fitur "Tanya AI" — obrolan dengan AI (tutor) di halaman belajar materi.
 *
 * Pemilik meminta: kartu "Tanya AI" tampil DI BAWAH isi materi dan DI ATAS blok
 * Quiz (`materi.php`), bisa dipakai semua peran yang sudah login, dan riwayat
 * percakapan disimpan per pengguna per materi (bisa dilanjutkan lain waktu).
 *
 * Aturan penting:
 * - Bawaannya NONAKTIF (`ai_aktif` = '0'). Pemilik menyalakannya di
 *   Pengaturan → AI dan menempelkan sendiri kunci API-nya di sana.
 * - Kunci API HANYA dibaca sisi server (file ini + `api-ai-chat.php`).
 *   Jangan pernah mengirim/menuliskannya ke HTML/JS yang diterima browser.
 * - Penyedia yang didukung: Google Gemini (`gemini`) dan penyedia apa pun yang
 *   memakai bentuk OpenAI (`openai`: OpenAI, Groq, OpenRouter, DeepSeek, server
 *   sendiri). `ai_base_url` boleh diisi untuk penyedia alternatif/pengujian.
 * - Panggilan ke luar lewat `ai_http_post_json()` (curl bila ada, kalau tidak
 *   memakai stream/file_get_contents).
 */

require_once __DIR__ . '/helpers.php';

/* ------------------------------------------------------------- pengaturan */

/** Fitur Tanya AI dinyalakan admin? (Pengaturan → AI) */
function ai_aktif()
{
    return setting('ai_aktif', '0') === '1';
}

function ai_nama()
{
    return setting('ai_nama', 'Tanya AI');
}

/**
 * Kalimat pengantar di kartu chat (bisa diubah admin di Pengaturan → AI).
 * Kosong = pakai teks bawaan. Dipakai di `ai_kartu_html()`.
 */
function ai_intro()
{
    $t = trim((string)setting('ai_intro', ''));
    $bawaan = lms_default_ai_settings();
    return $t !== '' ? $t : (string)$bawaan['ai_intro'];
}

/**
 * Petunjuk (placeholder) di dalam kotak tulis pertanyaan (bisa diubah admin).
 * Kosong = pakai teks bawaan.
 */
function ai_placeholder()
{
    $t = trim((string)setting('ai_placeholder', ''));
    $bawaan = lms_default_ai_settings();
    return $t !== '' ? $t : (string)$bawaan['ai_placeholder'];
}

function ai_provider()
{
    $p = setting('ai_provider', 'gemini');
    return in_array($p, ['gemini', 'openai'], true) ? $p : 'gemini';
}

function ai_model()
{
    $m = trim((string)setting('ai_model', ''));
    if ($m !== '') {
        return $m;
    }
    return ai_provider() === 'openai' ? 'gpt-4o-mini' : 'gemini-2.5-flash';
}

function ai_api_key()
{
    return trim((string)setting('ai_api_key', ''));
}

/** Kunci API sudah diisi? (tanpa kunci, kartu chat tidak ditampilkan ke siswa) */
function ai_siap()
{
    return ai_api_key() !== '';
}

/** Batas pertanyaan per pengguna per 24 jam (0 = tanpa batas). */
function ai_batas_harian()
{
    return (int)setting('ai_batas_harian', '40');
}

/* ------------------------------------------------------- suara (obrolan) */

/**
 * Fitur suara dinyalakan admin? Suara memakai kemampuan peramban/HP
 * (pengenal suara + pembaca teks), jadi tidak butuh kunci API tambahan dan
 * tidak ada biaya. Nilai pengaturan kosong = pakai bawaan (menyala).
 */
function ai_suara_aktif()
{
    return ai_suara_flag('ai_suara_aktif');
}

/** Jawaban AI otomatis dibacakan setelah masuk? */
function ai_suara_otomatis()
{
    return ai_suara_flag('ai_suara_otomatis');
}

/**
 * Mode percakapan (live): mikrofon mendengarkan terus-menerus, setiap kalimat
 * pengguna dikirim otomatis, jawabannya dibacakan, lalu mikrofon mendengarkan
 * lagi — seperti menelepon AI. Panelnya diatur admin (Pengaturan → AI).
 */
function ai_suara_live()
{
    return ai_suara_flag('ai_suara_live');
}

/** Bacaan pengaturan ya/tidak dengan teks kosong = bawaan. */
function ai_suara_flag($key)
{
    $v = trim((string)setting($key, ''));
    $bawaan = lms_default_ai_settings();
    if ($v === '') {
        $v = (string)($bawaan[$key] ?? '1');
    }
    return $v === '1';
}

/**
 * Berapa lama (milidetik) menunggu keheningan sebelum kalimat pengguna dianggap
 * selesai dan dikirim di mode percakapan. Makin besar = makin sabar menunggu
 * pengguna selesai bicara. Dibatasi 1000-10000 ms; kosong/tidak sah = bawaan.
 */
function ai_suara_jeda()
{
    $v = (int)trim((string)setting('ai_suara_jeda', ''));
    if ($v <= 0) {
        $v = (int)(lms_default_ai_settings()['ai_suara_jeda'] ?? 3000);
    }
    if ($v < 1000) { $v = 1000; }
    if ($v > 10000) { $v = 10000; }
    return $v;
}

/** Label pilihan jeda untuk UI admin (detik). */
function ai_suara_jeda_label($ms = null)
{
    $detik = ($ms !== null ? (int)$ms : ai_suara_jeda()) / 1000;
    return rtrim(rtrim(number_format($detik, 1, ',', '.'), '0'), ',') . ' detik';
}

/** Bahasa suara bawaan: 'id' atau 'en' (pengguna masih bisa menggantinya di kartu). */
function ai_suara_bahasa()
{
    $b = trim((string)setting('ai_suara_bahasa', ''));
    if ($b === '') {
        $b = (string)(lms_default_ai_settings()['ai_suara_bahasa'] ?? 'id');
    }
    return $b === 'en' ? 'en' : 'id';
}

/** Label bahasa untuk UI admin. */
function ai_suara_bahasa_label($kode = null)
{
    return ($kode ?? ai_suara_bahasa()) === 'en' ? 'Bahasa Inggris' : 'Bahasa Indonesia';
}

/** Tag bahasa untuk Web Speech API (dipakai di kartu, dokumen di JS). */
function ai_suara_tag($kode = null)
{
    return ($kode ?? ai_suara_bahasa()) === 'en' ? 'en-US' : 'id-ID';
}

/** Label penyedia untuk ditampilkan di UI (tanpa membocorkan kunci API). */
function ai_provider_label()
{
    if (ai_provider() === 'openai') {
        $host = parse_url(ai_base_url(), PHP_URL_HOST);
        return $host ? (string)$host : 'OpenAI';
    }
    return 'Google Gemini';
}

function ai_base_url()
{
    $b = trim((string)setting('ai_base_url', ''));
    if ($b !== '') {
        return rtrim($b, '/');
    }
    return ai_provider() === 'openai' ? 'https://api.openai.com/v1' : 'https://generativelanguage.googleapis.com';
}

/* ---------------------------------------------------------------- konteks */

/** Instruksi sistem: pengaturan pemilik + konteks materi yang sedang dibuka. */
function ai_prompt($lesson, $course = null, $mode = '')
{
    $bawaan = lms_default_ai_settings()['ai_prompt'];
    $p = trim((string)setting('ai_prompt', $bawaan));
    if ($p === '') {
        $p = $bawaan;
    }
    $p = str_replace(
        ['{site}', '{kursus}', '{materi}'],
        [setting('site_name', 'LMS'), (string)($lesson['kursus'] ?? ''), (string)($lesson['judul'] ?? '')],
        $p
    );

    // Aturan FORMAT, selalu ditambahkan (juga pada prompt kustom pemilik) karena
    // ini soal tampilan, bukan gaya menjawab: pemilik meminta balasan AI tampil
    // rapi TANPA tanda bintang. Penjaga terakhirnya ada di ai_rapikan_teks().
    $p .= "\n\n=== ATURAN FORMAT JAWABAN ===\n"
        . "Tulis jawaban sebagai kalimat biasa yang enak dibaca. Jangan memakai penanda format apa pun.\n"
        . "JANGAN memakai tanda bintang: jangan menebalkan dengan **teks**, jangan memiringkan dengan *teks*, "
        . "dan jangan membuat butir daftar dengan tanda bintang.\n"
        . "Jangan memakai tanda pagar (#) untuk judul, jangan memakai backtick, dan jangan membuat tabel markdown.\n"
        . "Bila perlu daftar, tulis angkanya di awal baris (1. 2. 3.) atau pakai tanda •.\n";

    // Mode percakapan suara: jawabannya DIBACAKAN, jadi harus pendek dan lisan.
    if ($mode === 'suara') {
        $p .= "\n\n=== MODE PERCAKAPAN SUARA ===\n"
            . "Jawabanmu akan DIBACAKAN lewat suara kepada pengguna, jadi jawab seperti berbicara: singkat, langsung, dan ramah.\n"
            . "Maksimal 3 kalimat atau sekitar 60 kata. Jangan memakai daftar bernomor, tabel, penanda markdown, atau emoji.\n"
            . "Bila perlu contoh, sebutkan satu contoh kalimat pendek saja. Bila pengguna minta penjelasan lebih panjang, "
            . "jelaskan satu bagian dulu lalu tawarkan melanjutkan.\n";
    }

    $isi = ai_teks_polos($lesson['isi_teks'] ?? '');
    // Materi infografis tidak punya isi teks, tetapi punya ringkasan berformat —
    // AI tetap boleh membacanya sebagai konteks materi (tanpa penanda formatnya).
    if ($isi === '' && function_exists('infografis_teks_polos')) {
        $isi = ai_teks_polos(infografis_teks_polos((string)($lesson['infografis'] ?? '')));
    }
    $konteks = "\n\n=== KONTEKS MATERI YANG SEDANG DIBUKA SISWA ===\n"
        . 'Kursus: ' . ($lesson['kursus'] ?? '-') . "\n"
        . 'Materi: ' . ($lesson['judul'] ?? '-') . "\n"
        . 'Jenis materi: ' . tipe_materi_label($lesson['tipe'] ?? 'teks') . "\n";
    if ($isi !== '') {
        $konteks .= "Isi materi (boleh dipotong):\n\"\"\"\n" . $isi . "\n\"\"\"\n";
    } else {
        $konteks .= "Materi ini tidak punya teks bacaan (berupa berkas/video/gambar). Jawab berdasarkan judul materi dan minta siswa menyebutkan bagian mana yang membingungkan.\n";
    }
    $konteks .= "=== AKHIR KONTEKS ===\nJawab pertanyaan siswa hanya berdasarkan konteks di atas bila memungkinkan.\n";
    return $p . $konteks;
}

/**
 * Rapikan jawaban AI: buang semua penanda format markdown supaya yang dibaca
 * pengguna hanya kalimat biasa (permintaan pemilik: "Rapikan balasan dari AI,
 * jangan pakai *").
 *
 * Penyebab aslinya: penyedia AI (mis. Gemini lewat gateway) senang menulis
 * `**tebal**`, `*miring*`, butir daftar dengan `*`, judul `#`, dan backtick.
 * Perender hanya menangani `**tebal**`, sehingga tanda bintang tunggal tampil
 * apa adanya di layar siswa.
 *
 * Hasilnya TIDAK memuat satu pun tanda bintang:
 *   `**teks**` / `*teks*` / `***teks***` -> `teks`
 *   `_teks_` (dua garis bawah)          -> `teks`
 *   butir `* ` / `- ` / `+ `            -> `• `
 *   judul `# Judul`                     -> `Judul`
 *   pagar kode, garis pemisah, backtick -> dihapus (isinya dipertahankan)
 *
 * Dipakai di dua tempat: saat jawaban diterima (supaya yang DISIMPAN sudah
 * bersih — dibaca layar maupun suara) dan saat merender riwayat lama yang
 * terlanjur tersimpan bermarkdown. Padanannya di peramban: aiRapi() di
 * assets/app.js (keduanya harus menghasilkan teks yang sama).
 */
function ai_rapikan_teks($teks)
{
    $t = str_replace(["\r\n", "\r"], "\n", (string)$teks);

    // Pagar kode ``` (dengan atau tanpa nama bahasa): pagarnya saja yang dibuang.
    $t = preg_replace('/^[ \t]*```[^\n]*$/m', '', $t);

    // Garis pemisah markdown (---, ***, ___) dibuang seluruhnya.
    $t = preg_replace('/^[ \t]*([-*_])[ \t]*(\1[ \t]*){2,}$/m', '', (string)$t);

    // Butir daftar memakai tanda bintang/minus/plus -> butir sungguhan (•).
    // WAJIB dijalankan SEBELUM penekanan: pada "* *contoh*" tanda bintang pertama
    // adalah penanda butir, dan kalau penekanan jalan lebih dulu, penanda itu
    // ikut termakan sehingga butirnya hilang.
    $t = preg_replace('/^[ \t]*[*\-+][ \t]+/m', '• ', (string)$t);

    // Penekanan tebal/miring. Diulang beberapa kali karena bentuk bersarang
    // seperti "***C*a**" perlu dua putaran sampai tandanya habis.
    for ($i = 0; $i < 4; $i++) {
        $sebelum = $t;
        $t = preg_replace('/\*{1,3}([^*\n]+?)\*{1,3}/u', '$1', (string)$t);
        $t = preg_replace('/_{2,3}([^_\n]+?)_{2,3}/u', '$1', (string)$t);
        if ($t === $sebelum) {
            break;
        }
    }

    // Judul markdown: tanda pagar di awal baris dibuang, teksnya tetap.
    $t = preg_replace('/^[ \t]*#{1,6}[ \t]*/m', '', (string)$t);

    // Sisa tanda bintang & backtick apa pun dibuang (pemilik tidak ingin ada *).
    $t = str_replace(['*', '`'], '', (string)$t);

    // Rapikan spasi: baris berspasi ganda (penanda baris baru markdown), spasi
    // sebelum baris baru, dan baris kosong beruntun.
    $t = preg_replace('/[ \t]+$/m', '', (string)$t);
    $t = preg_replace('/[ \t]{2,}/', ' ', (string)$t);
    $t = preg_replace('/\n{3,}/', "\n\n", (string)$t);
    $t = preg_replace('/^[ \t]+/m', '', (string)$t);

    return trim((string)$t);
}

/** Teks materi tanpa tag, spasi dirapikan, dipotong agar hemat token. */
function ai_teks_polos($teks, $maks = 4000)
{
    $t = str_replace(["\r\n", "\r"], "\n", (string)$teks);
    $t = preg_replace('/[ \t]+/', ' ', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    $t = trim($t);
    if (mb_strlen($t) > $maks) {
        $t = mb_substr($t, 0, $maks) . "\n[... dipotong ...]";
    }
    return $t;
}

/* ---------------------------------------------------------------- riwayat */

function ai_riwayat($user_id, $lesson_id, $limit = 40)
{
    $stmt = db()->prepare('SELECT * FROM ai_messages WHERE user_id = ? AND lesson_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
    $stmt->execute([(int)$user_id, (int)$lesson_id]);
    $rows = $stmt->fetchAll();
    return array_reverse($rows);
}

function ai_jumlah_hari_ini($user_id)
{
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM ai_messages WHERE user_id = ? AND peran = 'user' AND created_at >= datetime('now','-1 day')");
        $stmt->execute([(int)$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function ai_bersihkan($user_id, $lesson_id)
{
    $stmt = db()->prepare('DELETE FROM ai_messages WHERE user_id = ? AND lesson_id = ?');
    $stmt->execute([(int)$user_id, (int)$lesson_id]);
    return $stmt->rowCount();
}

/* -------------------------------------------------------------- panggilan */

/**
 * POST JSON ke penyedia AI. Mengembalikan [status, body, error].
 * Pakai curl bila tersedia (dipakai juga oleh media_upload), kalau tidak
 * memakai stream context — jadi tetap jalan di lingkungan tanpa ekstensi curl.
 */
function ai_http_post_json($url, array $headers, array $payload, $timeout = 60)
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)$timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);
        return [$code, is_string($res) ? $res : '', $err];
    }
    if (!ini_get('allow_url_fopen')) {
        return [0, '', 'Ekstensi curl tidak tersedia di server ini.'];
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => (int)$timeout,
            'ignore_errors' => true,
        ],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    return [$code, is_string($res) ? $res : '', $res === false ? 'Koneksi ke penyedia AI gagal.' : ''];
}

/**
 * Kirim percakapan ke penyedia AI, kembalikan teks jawabannya.
 * Melempar RuntimeException dengan pesan yang aman ditampilkan ke pengguna.
 */
function ai_panggil_model($system, array $riwayat, $timeout = 60)
{
    $pesan = [];
    foreach ($riwayat as $m) {
        $pesan[] = ['peran' => $m['peran'], 'isi' => (string)$m['isi']];
    }
    return ai_panggil_konten($system, $pesan, $timeout);
}

/**
 * Sama seperti ai_panggil_model(), tetapi isi pesannya boleh berupa teks DAN
 * gambar — dipakai penyusun infografis, karena bacaan pemilik banyak berupa
 * scan/foto halaman (berkas gambar), bukan teks.
 *
 * $pesan = daftar pesan:
 *   ['peran' => 'user'|'ai', 'isi' => '<teks>', 'gambar' => ['data' => base64, 'mime' => 'image/jpeg']]
 * 'gambar' opsional; kalau ada, gambarnya ikut dikirim ke penyedia.
 */
function ai_panggil_konten($system, array $pesan, $timeout = 60, $maks_token = 1024)
{
    if (!ai_siap()) {
        throw new RuntimeException('Kunci API belum diisi admin di Pengaturan → AI.');
    }
    $model = ai_model();
    $headers = ['Content-Type: application/json'];
    $maks_token = max(256, (int)$maks_token);

    if (ai_provider() === 'openai') {
        $url = ai_base_url() . '/chat/completions';
        $headers[] = 'Authorization: Bearer ' . ai_api_key();
        $msgs = [['role' => 'system', 'content' => $system]];
        foreach ($pesan as $m) {
            $role = $m['peran'] === 'ai' ? 'assistant' : 'user';
            $gambar = $m['gambar'] ?? null;
            if (is_array($gambar) && !empty($gambar['data'])) {
                $msgs[] = ['role' => $role, 'content' => [
                    ['type' => 'text', 'text' => (string)$m['isi']],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . ($gambar['mime'] ?? 'image/jpeg') . ';base64,' . $gambar['data']]],
                ]];
            } else {
                $msgs[] = ['role' => $role, 'content' => (string)$m['isi']];
            }
        }
        $payload = [
            'model' => $model,
            'messages' => $msgs,
            'temperature' => 0.6,
            'max_tokens' => $maks_token,
        ];
    } else {
        $url = ai_base_url() . '/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $headers[] = 'x-goog-api-key: ' . ai_api_key();
        $contents = [];
        foreach ($pesan as $m) {
            $parts = [['text' => (string)$m['isi']]];
            $gambar = $m['gambar'] ?? null;
            if (is_array($gambar) && !empty($gambar['data'])) {
                $parts[] = ['inline_data' => ['mime_type' => ($gambar['mime'] ?? 'image/jpeg'), 'data' => $gambar['data']]];
            }
            $contents[] = [
                'role' => $m['peran'] === 'ai' ? 'model' : 'user',
                'parts' => $parts,
            ];
        }
        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.6, 'maxOutputTokens' => $maks_token],
        ];
    }

    [$code, $raw, $err] = ai_http_post_json($url, $headers, $payload, $timeout);
    $json = $raw !== '' ? json_decode($raw, true) : null;

    if ($code !== 200) {
        throw new RuntimeException(ai_pesan_error($code, $json, $err));
    }
    if (!is_array($json)) {
        throw new RuntimeException('Balasan penyedia AI tidak bisa dibaca.');
    }

    if (ai_provider() === 'openai') {
        $teks = $json['choices'][0]['message']['content'] ?? '';
        if ($teks === '' && !empty($json['error']['message'])) {
            throw new RuntimeException('Penyedia AI menolak permintaan: ' . (string)$json['error']['message']);
        }
    } else {
        $teks = '';
        foreach (($json['candidates'][0]['content']['parts'] ?? []) as $part) {
            $teks .= (string)($part['text'] ?? '');
        }
        if (trim($teks) === '') {
            $blok = $json['promptFeedback']['blockReason'] ?? ($json['candidates'][0]['finishReason'] ?? '');
            if ($blok !== '') {
                throw new RuntimeException('Pertanyaan ditolak oleh penyaring keamanan penyedia AI (' . (string)$blok . '). Coba tanyakan dengan cara lain.');
            }
        }
    }

    $teks = trim((string)$teks);
    if ($teks === '') {
        throw new RuntimeException('Penyedia AI tidak mengirim jawaban. Coba lagi sebentar lagi.');
    }
    return $teks;
}

/** Terjemahkan kode/HTTP body error penyedia menjadi pesan untuk pengguna. */
function ai_pesan_error($code, $json, $err)
{
    $detail = '';
    if (is_array($json)) {
        $detail = (string)($json['error']['message'] ?? $json['message'] ?? '');
    }
    $map = [
        400 => 'Permintaan ditolak penyedia AI (400). Periksa nama model di Pengaturan → AI.',
        401 => 'Kunci API ditolak penyedia AI (401). Periksa kembali kunci API di Pengaturan → AI.',
        403 => 'Akses ditolak penyedia AI (403). Kunci API mungkin belum aktif atau model tidak diizinkan.',
        404 => 'Model tidak ditemukan (404). Periksa nama model di Pengaturan → AI.',
        429 => 'Kuota/limit penyedia AI sedang penuh (429). Coba lagi beberapa saat lagi.',
    ];
    if (isset($map[$code])) {
        return $map[$code] . ($detail !== '' ? ' Detail: ' . mb_strimwidth($detail, 0, 200, '...') : '');
    }
    if ($code >= 500) {
        return 'Penyedia AI sedang bermasalah (' . (int)$code . '). Coba lagi nanti.';
    }
    if ($err !== '') {
        return 'Tidak bisa menghubungi penyedia AI: ' . mb_strimwidth($err, 0, 200, '...');
    }
    return 'Gagal menghubungi penyedia AI (kode ' . (int)$code . ').';
}

/**
 * Kirim satu pertanyaan pengguna + simpan riwayatnya.
 * Mengembalikan ['ok' => bool, 'balasan' => string, 'error' => string].
 * Pesan pengguna HANYA disimpan setelah jawaban berhasil didapat, supaya
 * pertanyaan gagal tidak menggantung tanpa jawaban di riwayat.
 * $mode = 'suara' dipakai mode percakapan live (jawaban diminta pendek & lisan).
 */
function ai_kirim($user_id, $lesson, $pesan, $mode = '')
{
    $pesan = trim((string)$pesan);
    if ($pesan === '') {
        return ['ok' => false, 'error' => 'Tulis pertanyaanmu dulu.'];
    }
    if (mb_strlen($pesan) > 1200) {
        return ['ok' => false, 'error' => 'Pertanyaan terlalu panjang (maksimal 1200 karakter).'];
    }
    $batas = ai_batas_harian();
    if ($batas > 0 && ai_jumlah_hari_ini($user_id) >= $batas) {
        return ['ok' => false, 'error' => 'Batas ' . $batas . ' pertanyaan per 24 jam sudah tercapai. Coba lagi besok ya.'];
    }

    $riwayat = ai_riwayat($user_id, (int)$lesson['id'], 10);
    $riwayat[] = ['peran' => 'user', 'isi' => $pesan];

    try {
        $balasan = ai_panggil_model(ai_prompt($lesson, null, $mode === 'suara' ? 'suara' : ''), $riwayat);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    // Bersihkan penanda format (tanda bintang dll) SEBELUM disimpan, supaya baik
    // tampilan di layar maupun pembacaan suara memakai teks yang sudah rapi.
    $balasan = ai_rapikan_teks($balasan);

    try {
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $ins = $pdo->prepare('INSERT INTO ai_messages (user_id, lesson_id, course_id, peran, isi) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([(int)$user_id, (int)$lesson['id'], (int)$lesson['course_id'], 'user', $pesan]);
            $ins->execute([(int)$user_id, (int)$lesson['id'], (int)$lesson['course_id'], 'ai', $balasan]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Jawaban sudah didapat, tetapi gagal disimpan. Coba lagi.'];
    }

    return ['ok' => true, 'balasan' => $balasan];
}

/** Uji koneksi dari Pengaturan → AI (tanpa menyimpan riwayat). */
function ai_uji_koneksi()
{
    try {
        $balasan = ai_panggil_model(
            'Balas dengan satu kata saja: "OK".',
            [['peran' => 'user', 'isi' => 'Tes koneksi. Jawab singkat.']],
            30
        );
        return ['ok' => true, 'pesan' => mb_strimwidth(trim($balasan), 0, 120, '...')];
    } catch (Throwable $e) {
        return ['ok' => false, 'pesan' => $e->getMessage()];
    }
}

/* ----------------------------------------------------------------- render */

/** Teks jawaban AI -> HTML aman (sama dengan aiFmt() di assets/app.js).
 *
 * Penanda format sudah dibuang di ai_rapikan_teks(); sisa penanda yang mungkin
 * lolos (mis. dari jawaban yang tersimpan sebelum perbaikan ini) tetap dirender
 * sebagai tebal/miring alih-alih tampil sebagai tanda bintang di layar.
 *
 * $ai = false untuk pesan SISWA: teks yang mereka tulis ditampilkan APA ADANYA
 * (hanya di-escape, tanpa diubah menjadi tebal/miring) — tanda bintang yang
 * mereka tulis tetap terlihat seperti yang mereka ketik.
 */
function ai_teks_html($teks, $ai = true)
{
    if (!$ai) {
        return nl2br(e($teks), false);
    }
    $t = e(ai_rapikan_teks($teks));
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', (string)$t);
    $t = preg_replace('/\*([^*\n]+?)\*/s', '<em>$1</em>', (string)$t);
    $t = preg_replace('/`([^`\n]+)`/s', '<code>$1</code>', (string)$t);
    $t = preg_replace('/^#{1,4}\s*(.+)$/m', '<strong>$1</strong>', (string)$t);
    $t = preg_replace('/^[ \t]*[-*]\s+/m', '• ', (string)$t);
    return nl2br($t, false);
}

/** Satu gelembung pesan (markup harus sama dengan aiMsgHtml() di app.js). */
function ai_pesan_html($peran, $teks, $user = null, $waktu = null)
{
    $ai = $peran === 'ai';
    $ava = $ai
        ? '<span class="ai-ava ai">' . icon('sparkle', 16) . '</span>'
        : '<span class="ai-ava">' . e(strtoupper(mb_substr((string)($user['nama'] ?? 'U'), 0, 1))) . '</span>';
    $jam = $waktu ? date('H:i', strtotime((string)$waktu)) : date('H:i');
    // Tombol bacakan hanya pada jawaban AI (bunyi suara dijalankan app.js).
    $baca = $ai && ai_suara_aktif()
        ? '<button type="button" class="ai-speak" data-ai-speak title="Bacakan jawaban ini" aria-label="Bacakan jawaban ini">' . icon('volume', 15) . '</button>'
        : '';
    return '<div class="ai-msg ' . ($ai ? 'ai' : 'user') . '">' . $ava
        . '<div class="ai-bubble"><div class="ai-text">' . ai_teks_html($teks, $ai) . '</div>'
        . '<div class="ai-jam">' . $baca . '<span>' . e($jam) . '</span></div></div></div>';
}

/**
 * Baris pengendali suara di kartu: tombol bicara (mikrofon), tombol
 * menghidupkan/mematikan pembacaan jawaban, pilihan bahasa ID <-> EN, dan
 * (bila diizinkan admin) tombol "Mode Percakapan" untuk mengobrol tanpa henti
 * seperti menelepon AI.
 * Tombol-tombol ini dimatikan sendiri oleh assets/app.js bila peramban
 * pengguna tidak menyediakan pengenal suara / pembaca teks.
 */
function ai_suara_kontrol_html()
{
    $bhs = ai_suara_bahasa();
    $otomatis = ai_suara_otomatis();
    $live = ai_suara_live();
    ob_start(); ?>
    <div class="ai-suara" data-ai-suara="1">
      <button type="button" class="btn btn-ghost btn-sm ai-mic" data-ai-mic hidden aria-pressed="false">
        <?= icon('mic', 16) ?> <span data-ai-mic-label>Bicara</span>
      </button>
      <button type="button" class="btn btn-ghost btn-sm ai-dengar" data-ai-dengar aria-pressed="<?= $otomatis ? 'true' : 'false' ?>" title="Bacakan jawaban AI secara otomatis">
        <span data-ai-dengar-ikon><?= icon($otomatis ? 'volume' : 'volume-off', 16) ?></span>
        <span data-ai-dengar-label>Suara AI: <?= $otomatis ? 'Nyala' : 'Mati' ?></span>
      </button>
      <?php if ($live): ?>
        <button type="button" class="btn btn-ghost btn-sm ai-live" data-ai-live hidden aria-pressed="false" title="Mengobrol tanpa henti seperti menelepon AI">
          <?= icon('phone', 16) ?> <span data-ai-live-label>Mode Percakapan</span>
        </button>
      <?php endif; ?>
      <div class="ai-lang" role="group" aria-label="Bahasa suara">
        <button type="button" class="chip<?= $bhs === 'id' ? ' solid' : '' ?>" data-ai-lang="id" aria-pressed="<?= $bhs === 'id' ? 'true' : 'false' ?>">Indonesia</button>
        <button type="button" class="chip<?= $bhs === 'en' ? ' solid' : '' ?>" data-ai-lang="en" aria-pressed="<?= $bhs === 'en' ? 'true' : 'false' ?>">English</button>
      </div>
    </div>
    <?php if ($live): ?>
      <div class="ai-live-bar" data-ai-live-bar hidden>
        <span class="ai-live-dot" data-ai-live-dot></span>
        <span class="ai-live-status" data-ai-live-status>Menyiapkan mode percakapan...</span>
        <button type="button" class="btn btn-danger btn-sm" data-ai-live-stop><?= icon('x', 15) ?> Akhiri</button>
      </div>
      <p class="muted small ai-live-kata" data-ai-live-kata hidden></p>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}

/** Kartu chat untuk pengguna yang sudah login. */
function ai_kartu_html($u, $lesson, $riwayat = null)
{
    $riwayat = $riwayat ?? ai_riwayat((int)$u['id'], (int)$lesson['id']);
    $nama = ai_nama();
    $siap = ai_siap();
    $staf = in_array($u['role'] ?? '', ['guru', 'admin'], true);
    $suara = ai_suara_aktif();
    ob_start(); ?>
    <div class="divider"></div>
    <div class="ai-card" id="ai-materi" data-ai data-ai-url="api-ai-chat.php" data-ai-lesson="<?= (int)$lesson['id'] ?>" data-ai-inisial="<?= e(strtoupper(mb_substr((string)($u['nama'] ?? 'U'), 0, 1))) ?>" data-ai-bahasa="<?= e(ai_suara_bahasa()) ?>" data-ai-suara-otomatis="<?= ai_suara_otomatis() ? '1' : '0' ?>" data-ai-jeda="<?= (int)ai_suara_jeda() ?>">
      <div class="card-head">
        <h3 class="mt0 mb0"><?= icon('sparkle', 18) ?> <?= e($nama) ?></h3>
      </div>
      <?php if (!$siap): ?>
        <div class="flash flash-warning"><?= icon('bell', 17) ?><span>Kunci API belum diisi, jadi siswa belum bisa memakai fitur ini. Isi dulu di
          <a href="admin-pengaturan.php#ai">Pengaturan → AI</a>.</span></div>
      <?php else: ?>
        <p class="muted small"><?= e(ai_intro()) ?></p>
        <div class="ai-thread" data-ai-thread>
          <?php if (!$riwayat): ?>
            <p class="muted small ai-kosong" data-ai-kosong>Belum ada percakapan di materi ini. Mulai dengan pertanyaanmu di bawah.</p>
          <?php else: ?>
            <?php foreach ($riwayat as $m): ?>
              <?= ai_pesan_html($m['peran'], $m['isi'], $u, $m['created_at']) ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php if ($suara): ?>
          <?= ai_suara_kontrol_html() ?>
        <?php endif; ?>
        <form class="ai-form" data-ai-form>
          <?= csrf_field() ?>
          <textarea name="pesan" rows="2" maxlength="1200" placeholder="<?= e(ai_placeholder()) ?>" required></textarea>
          <div class="ai-form-aksi">
            <span class="ai-status small" data-ai-status></span>
            <button type="button" class="btn btn-ghost btn-sm" data-ai-bersihkan<?= $riwayat ? '' : ' hidden' ?>><?= icon('trash', 15) ?> Hapus Riwayat</button>
            <button class="btn btn-primary btn-sm" type="submit" data-ai-send><?= icon('send', 16) ?> Kirim</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

/** Kartu pengganti untuk tamu (materi preview boleh dibaca tanpa login). */
function ai_kartu_tamu()
{
    ob_start(); ?>
    <div class="divider"></div>
    <div class="ai-card" id="ai-materi">
      <div class="card-head">
        <h3 class="mt0 mb0"><?= icon('sparkle', 18) ?> <?= e(ai_nama()) ?></h3>
        <span class="muted small">Perlu masuk</span>
      </div>
      <p class="muted small">Masuk ke akunmu untuk bertanya langsung ke AI tentang materi ini (riwayatnya tersimpan, bisa dilanjutkan kapan saja).</p>
      <a class="btn btn-primary btn-sm" href="login.php"><?= icon('lock', 16) ?> Masuk untuk bertanya</a>
    </div>
    <?php
    return (string)ob_get_clean();
}
