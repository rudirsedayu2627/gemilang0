<?php
require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Jakarta');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ------------------------------------------------------------------ utils */

function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function setting($key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT key, value FROM settings') as $r) {
                $cache[$r['key']] = $r['value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null && $cache[$key] !== '' ? $cache[$key] : $default;
}

function set_setting($key, $value)
{
    $stmt = db()->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute([$key, (string)$value]);
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function post($key, $default = '')
{
    return isset($_POST[$key]) ? (is_array($_POST[$key]) ? $_POST[$key] : trim((string)$_POST[$key])) : $default;
}

function get($key, $default = '')
{
    return isset($_GET[$key]) ? (is_array($_GET[$key]) ? $_GET[$key] : trim((string)$_GET[$key])) : $default;
}

function num($key, $default = 0)
{
    return (int)get($key, (string)$default);
}

function slugify($text)
{
    $text = strtolower(trim((string)$text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function tgl_id($datetime, $with_time = false)
{
    if (!$datetime) {
        return '-';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return '-';
    }
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $s = date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    if ($with_time) {
        $s .= ', ' . date('H:i', $ts);
    }
    return $s;
}

function rupiah($n)
{
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

function hari_id($date)
{
    $ts = strtotime($date);
    $h = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
    return $h[date('l', $ts)] ?? date('l', $ts);
}

function flash($msg = null, $type = 'success')
{
    if ($msg !== null) {
        $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $out = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $out;
}

function old($key, $default = '')
{
    $v = $_SESSION['old'][$key] ?? $default;
    return $v;
}

function keep_old($data)
{
    $_SESSION['old'] = $data;
}

function clear_old()
{
    unset($_SESSION['old']);
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check()
{
    $t = $_POST['_csrf'] ?? $_GET['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

/* ------------------------------------------------------------------- auth */

function current_user()
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        $user = null;
        return $user;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function is_logged_in()
{
    return current_user() !== null;
}

function require_login($role = null)
{
    $u = current_user();
    if (!$u) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        flash('Silakan masuk terlebih dahulu.', 'warning');
        redirect('login.php');
    }
    if ($u['status'] !== 'aktif') {
        session_destroy();
        redirect('login.php?nonaktif=1');
    }
    if ($role !== null) {
        $allowed = is_array($role) ? $role : [$role];
        if (!in_array($u['role'], $allowed, true)) {
            http_response_code(403);
            include __DIR__ . '/denied.php';
            exit;
        }
    }
    return $u;
}

function login_user($u)
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    db()->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')->execute([$u['id']]);
    audit('login', 'Masuk ke sistem sebagai ' . $u['role']);
}

function logout_user()
{
    $u = current_user();
    if ($u) {
        audit('logout', 'Keluar dari sistem');
    }
    $_SESSION = [];
    session_destroy();
}

function role_label($role)
{
    return ['admin' => 'Administrator', 'guru' => 'Guru', 'siswa' => 'Siswa'][$role] ?? ucfirst((string)$role);
}

function can($perm)
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    if ($u['role'] === 'admin') {
        return true;
    }
    if ($perm === 'guru') {
        return $u['role'] === 'guru';
    }
    return true;
}

/* --------------------------------------------------------------- activity */

function audit($aksi, $detail = '')
{
    try {
        $u = current_user();
        db()->prepare('INSERT INTO audit_logs (user_id, aksi, detail, ip) VALUES (?, ?, ?, ?)')
            ->execute([$u['id'] ?? null, $aksi, $detail, $_SERVER['REMOTE_ADDR'] ?? 'cli']);
    } catch (Throwable $e) {
    }
}

function notify($user_id, $judul, $pesan, $url = '')
{
    if (!$user_id) {
        return;
    }
    db()->prepare('INSERT INTO notifications (user_id, judul, pesan, url) VALUES (?, ?, ?, ?)')
        ->execute([(int)$user_id, $judul, $pesan, $url]);
}

function notify_course($course_id, $judul, $pesan, $url = '', $except_user = null)
{
    $stmt = db()->prepare('SELECT user_id FROM enrollments WHERE course_id = ?');
    $stmt->execute([(int)$course_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if ($except_user && (int)$uid === (int)$except_user) {
            continue;
        }
        notify($uid, $judul, $pesan, $url);
    }
}

function unread_notifications()
{
    $u = current_user();
    if (!$u) {
        return 0;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dibaca = 0');
    $stmt->execute([$u['id']]);
    return (int)$stmt->fetchColumn();
}

/* ------------------------------------------------------------------ email */

function smtp_send($to, $subject, $body)
{
    $host = setting('smtp_host');
    if (setting('smtp_enabled') !== '1' || $host === '') {
        db()->prepare('INSERT INTO email_log (penerima, subjek, isi, status, error) VALUES (?, ?, ?, ?, ?)')
            ->execute([$to, $subject, $body, 'dilewati', 'SMTP belum diaktifkan — email dicatat saja.']);
        return false;
    }
    $port = (int)setting('smtp_port', '587');
    $user = setting('smtp_user');
    $pass = setting('smtp_pass');
    $from = setting('smtp_from');
    $fromName = setting('smtp_from_nama');
    $err = '';
    try {
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 10);
        if (!$fp) {
            throw new Exception('Tidak bisa terhubung ke SMTP: ' . $errstr);
        }
        $read = function () use ($fp) {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $say = function ($cmd) use ($fp, $read) {
            fwrite($fp, $cmd . "\r\n");
            return $read();
        };
        $read();
        $say('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $say('STARTTLS');
        @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $say('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($user !== '') {
            $say('AUTH LOGIN');
            $say(base64_encode($user));
            $say(base64_encode($pass));
        }
        $say('MAIL FROM:<' . $from . '>');
        $say('RCPT TO:<' . $to . '>');
        $say('DATA');
        $headers = 'From: ' . ($fromName ?: $from) . ' <' . $from . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n";
        $say($headers . "\r\n" . $body . "\r\n.");
        $res = $say('QUIT');
        fclose($fp);
        db()->prepare('INSERT INTO email_log (penerima, subjek, isi, status) VALUES (?, ?, ?, ?)')
            ->execute([$to, $subject, $body, 'terkirim']);
        return true;
    } catch (Throwable $e) {
        $err = $e->getMessage();
        db()->prepare('INSERT INTO email_log (penerima, subjek, isi, status, error) VALUES (?, ?, ?, ?, ?)')
            ->execute([$to, $subject, $body, 'gagal', $err]);
        return false;
    }
}

/* ------------------------------------------------------------------ media */

function media_token()
{
    // Path bisa dioverride lewat env (dipakai skrip uji agar tidak mengunggah
    // berkas percobaan ke penyimpanan media sungguhan).
    $override = getenv('LMS_MEDIA_TOKEN_FILE');
    $f = ($override !== false && $override !== '') ? $override : LMS_APP_DIR . '/.vibecoder-media-token';
    if (is_file($f)) {
        return trim((string)@file_get_contents($f));
    }
    return '';
}

/**
 * Upload file ke media proxy platform (server-side saja, token tidak pernah
 * dikirim ke browser). Kalau token belum ada (mode pengembangan sebelum
 * publish), file disimpan di folder uploads/ lokal.
 * Return: URL siap disimpan di database.
 */
function media_upload($tmp_path, $filename, $mime = 'application/octet-stream')
{
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$filename);
    $token = media_token();
    if ($token !== '') {
        $url = 'http://127.0.0.1:4310/api/app-media/upload?filename=' . rawurlencode($filename);
        $ch = curl_init($url);
        $fh = fopen($tmp_path, 'rb');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => fread($fh, (int)filesize($tmp_path) ?: 1),
            CURLOPT_HTTPHEADER => [
                'X-App-Media-Token: ' . $token,
                'Content-Type: application/octet-stream',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ]);
        fclose($fh);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code === 200 && $res) {
            $json = json_decode($res, true);
            if (!empty($json['url'])) {
                return $json['url'];
            }
        }
        $msg = 'Upload media gagal.';
        if ($res) {
            $json = json_decode($res, true);
            if (!empty($json['error'])) {
                $msg = (string)$json['error'];
            } elseif (!empty($json['message'])) {
                $msg = (string)$json['message'];
            }
        } elseif ($err) {
            $msg = 'Upload media gagal: ' . $err;
        }
        throw new RuntimeException($msg);
    }

    // Fallback pengembangan (belum dipublikasikan / token belum tersedia).
    $override = getenv('LMS_UPLOAD_DIR');
    $dir = ($override !== false && $override !== '')
        ? rtrim((string)$override, '/')
        : LMS_APP_DIR . '/' . trim(setting('storage_folder', 'uploads'), '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $dest = $dir . '/' . date('Ymd-His') . '-' . $filename;
    $segmen = ($override !== false && $override !== '') ? '' : rtrim(setting('storage_folder', 'uploads'), '/') . '/';
    if (!@move_uploaded_file($tmp_path, $dest) && !@copy($tmp_path, $dest)) {
        throw new RuntimeException('Gagal menyimpan file ke folder ' . setting('storage_folder', 'uploads') . '.');
    }
    return $segmen . basename($dest);
}

function media_kind_from_ext($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
        return 'image';
    }
    if (in_array($ext, ['mp4', 'webm', 'mov'], true)) {
        return 'video';
    }
    return 'document';
}

/**
 * Apakah URL media ini berkas gambar? Dipakai halaman materi supaya berkas
 * gambar (jpeg/png/webp/svg) selalu tampil sebagai <img> yang muat di layar —
 * bukan di dalam iframe/video yang memaksa pengguna menggeser ke samping.
 */
function media_url_gambar($url)
{
    $url = trim((string)$url);
    if ($url === '') {
        return false;
    }
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = strtok($url, '?#');
    }
    return media_kind_from_ext((string)$path) === 'image';
}

function handle_upload($field, $filename = null)
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $f = $_FILES[$field];
    $name = $filename ?: $f['name'];
    return media_upload($f['tmp_name'], $name, $f['type'] ?? 'application/octet-stream');
}

function embed_url($url)
{
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    if (preg_match('~vimeo\.com/(\d+)~', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }
    return $url;
}

/* --------------------------------------- pembersihan data turunan (hapus) */

/**
 * Bersihkan seluruh baris turunan sebelum induknya dihapus, supaya tidak ada
 * data yatim (baris yang menunjuk record yang sudah tidak ada). Data yatim
 * membuat angka statistik membengkak dan menyulitkan penelusuran.
 *
 * $jenis: 'user' | 'course' | 'question' | 'quiz' | 'class' | 'assignment'
 * Return: array jumlah baris yang dihapus/diubah per tabel.
 *
 * CATATAN KONTRAK: untuk 'user', baris users-nya TIDAK dihapus di sini (pemanggil
 * yang menghapus dengan filter role). Untuk 'course', 'question', 'quiz', 'class',
 * dan 'assignment', baris induknya ikut dihapus oleh fungsi ini.
 */
function lms_hapus_turunan($jenis, $id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return [];
    }
    $pdo = db();
    $laporan = [];
    $del = function ($sql, $args = []) use ($pdo, &$laporan) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $n = $stmt->rowCount();
        if ($n > 0) {
            // ambil nama tabel dari SQL untuk laporan
            if (preg_match('/^DELETE FROM\s+(\w+)/i', trim($sql), $m)) {
                $laporan[$m[1]] = ($laporan[$m[1]] ?? 0) + $n;
            }
        }
        return $n;
    };
    $set = function ($sql, $args = []) use ($pdo, &$laporan) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $n = $stmt->rowCount();
        if ($n > 0 && preg_match('/^UPDATE\s+(\w+)/i', trim($sql), $m)) {
            $laporan[$m[1] . ' (dilepas)'] = ($laporan[$m[1] . ' (dilepas)'] ?? 0) + $n;
        }
        return $n;
    };

    if ($jenis === 'user') {
        // hapus jejak belajar & administrasi
        $del('DELETE FROM attempt_answers WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE user_id = ?)', [$id]);
        $del('DELETE FROM quiz_attempts WHERE user_id = ?', [$id]);
        $del('DELETE FROM lesson_progress WHERE user_id = ?', [$id]);
        $del('DELETE FROM assignment_submissions WHERE user_id = ?', [$id]);
        $del('DELETE FROM attendance WHERE user_id = ?', [$id]);
        $del('DELETE FROM certificates WHERE user_id = ?', [$id]);
        $del('DELETE FROM bookmarks WHERE user_id = ?', [$id]);
        $del('DELETE FROM notifications WHERE user_id = ?', [$id]);
        $del('DELETE FROM schedules WHERE user_id = ?', [$id]);
        $del('DELETE FROM class_students WHERE user_id = ?', [$id]);
        $del('DELETE FROM payments WHERE user_id = ?', [$id]);
        $del('DELETE FROM ai_messages WHERE user_id = ?', [$id]);
        $del('DELETE FROM enrollments WHERE user_id = ?', [$id]);
        // konten yang pernah dibuat: penulisnya dilepas, isinya tetap ada
        $set('UPDATE forum_topics SET user_id = NULL WHERE user_id = ?', [$id]);
        $set('UPDATE forum_replies SET user_id = NULL WHERE user_id = ?', [$id]);
        $set('UPDATE lesson_comments SET user_id = NULL WHERE user_id = ?', [$id]);
        $set('UPDATE audit_logs SET user_id = NULL WHERE user_id = ?', [$id]);
        $set('UPDATE courses SET guru_id = NULL WHERE guru_id = ?', [$id]);
        $set('UPDATE classes SET guru_id = NULL WHERE guru_id = ?', [$id]);
        $set('UPDATE quizzes SET guru_id = NULL WHERE guru_id = ?', [$id]);
        $set('UPDATE assignments SET guru_id = NULL WHERE guru_id = ?', [$id]);
        $set('UPDATE announcements SET guru_id = NULL WHERE guru_id = ?', [$id]);
        $set('UPDATE banners SET link = link WHERE 0', []);
        return $laporan;
    }

    if ($jenis === 'course') {
        $del('DELETE FROM attempt_answers WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE quiz_id IN (SELECT id FROM quizzes WHERE course_id = ?))', [$id]);
        $del('DELETE FROM quiz_attempts WHERE quiz_id IN (SELECT id FROM quizzes WHERE course_id = ?)', [$id]);
        $del('DELETE FROM quiz_questions WHERE quiz_id IN (SELECT id FROM quizzes WHERE course_id = ?)', [$id]);
        $del('DELETE FROM quiz_questions WHERE question_id IN (SELECT id FROM questions WHERE course_id = ?)', [$id]);
        $del('DELETE FROM attempt_answers WHERE question_id IN (SELECT id FROM questions WHERE course_id = ?)', [$id]);
        $del('DELETE FROM lesson_comments WHERE lesson_id IN (SELECT id FROM lessons WHERE course_id = ?)', [$id]);
        $del('DELETE FROM bookmarks WHERE lesson_id IN (SELECT id FROM lessons WHERE course_id = ?)', [$id]);
        $del('DELETE FROM ai_messages WHERE course_id = ?', [$id]);
        $del('DELETE FROM lesson_progress WHERE course_id = ?', [$id]);
        $del('DELETE FROM assignment_submissions WHERE assignment_id IN (SELECT id FROM assignments WHERE course_id = ?)', [$id]);
        $del('DELETE FROM class_students WHERE class_id IN (SELECT id FROM classes WHERE course_id = ?)', [$id]);
        $del('DELETE FROM classes WHERE course_id = ?', [$id]);
        $del('DELETE FROM attendance WHERE course_id = ?', [$id]);
        $del('DELETE FROM certificates WHERE course_id = ?', [$id]);
        $del('DELETE FROM forum_replies WHERE topic_id IN (SELECT id FROM forum_topics WHERE course_id = ?)', [$id]);
        $del('DELETE FROM forum_topics WHERE course_id = ?', [$id]);
        $del('DELETE FROM schedules WHERE course_id = ?', [$id]);
        $del('DELETE FROM enrollments WHERE course_id = ?', [$id]);
        $del('DELETE FROM assignments WHERE course_id = ?', [$id]);
        $del('DELETE FROM lessons WHERE course_id = ?', [$id]);
        $del('DELETE FROM modules WHERE course_id = ?', [$id]);
        $del('DELETE FROM questions WHERE course_id = ?', [$id]);
        $del('DELETE FROM quizzes WHERE course_id = ?', [$id]);
        // baris kursusnya sendiri dihapus terakhir (setelah semua turunannya)
        $del('DELETE FROM courses WHERE id = ?', [$id]);
        return $laporan;
    }

    if ($jenis === 'question') {
        $del('DELETE FROM attempt_answers WHERE question_id = ?', [$id]);
        $del('DELETE FROM quiz_questions WHERE question_id = ?', [$id]);
        $del('DELETE FROM questions WHERE id = ?', [$id]);
        return $laporan;
    }

    if ($jenis === 'quiz') {
        $del('DELETE FROM attempt_answers WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE quiz_id = ?)', [$id]);
        $del('DELETE FROM quiz_attempts WHERE quiz_id = ?', [$id]);
        // Soal TIDAK dihapus: kepemilikannya dilepas saja supaya tetap tersimpan
        // di bank soal kursusnya (sejak skema 10 satu soal dimiliki satu quiz).
        $set('UPDATE questions SET quiz_id = NULL WHERE quiz_id = ?', [$id]);
        $del('DELETE FROM quizzes WHERE id = ?', [$id]);
        return $laporan;
    }

    if ($jenis === 'class') {
        $del('DELETE FROM class_students WHERE class_id = ?', [$id]);
        $del('DELETE FROM classes WHERE id = ?', [$id]);
        return $laporan;
    }

    if ($jenis === 'assignment') {
        $del('DELETE FROM assignment_submissions WHERE assignment_id = ?', [$id]);
        $del('DELETE FROM assignments WHERE id = ?', [$id]);
        return $laporan;
    }
    return $laporan;
}

/** Daftar baris yatim (menunjuk induk yang sudah tidak ada) beserta jumlahnya. */
function lms_orphan_counts()
{
    $daftar = [
        'enrollments' => 'Pendaftaran kursus',
        'lesson_progress' => 'Progress materi',
        'quiz_attempts' => 'Pengerjaan quiz',
        'attempt_answers' => 'Jawaban siswa',
        'assignment_submissions' => 'Pengumpulan tugas',
        'attendance' => 'Absensi',
        'certificates' => 'Sertifikat',
        'payments' => 'Pembayaran',
        'classes' => 'Kelas',
        'class_students' => 'Anggota kelas',
        'lessons' => 'Materi',
        'modules' => 'Modul',
        'quiz_questions' => 'Penugasan soal ke quiz (tabel lama)',
        'questions_quiz' => 'Kepemilikan soal (quiz pemiliknya hilang)',
        'quizzes_lesson' => 'Penautan quiz ke materi (materi sudah hilang)',
        'forum_topics' => 'Topik forum',
        'forum_replies' => 'Balasan forum',
        'lesson_comments' => 'Komentar materi',
        'bookmarks' => 'Bookmark',
        'notifications' => 'Notifikasi',
        'schedules' => 'Jadwal',
        'assignment_submissions_all' => 'Pengumpulan tugas (tugas hilang)',
        'ai_messages' => 'Riwayat obrolan AI',
    ];
    $sql = [
        'enrollments' => 'SELECT COUNT(*) FROM enrollments WHERE user_id NOT IN (SELECT id FROM users) OR course_id NOT IN (SELECT id FROM courses)',
        'lesson_progress' => 'SELECT COUNT(*) FROM lesson_progress WHERE user_id NOT IN (SELECT id FROM users) OR lesson_id NOT IN (SELECT id FROM lessons) OR course_id NOT IN (SELECT id FROM courses)',
        'quiz_attempts' => 'SELECT COUNT(*) FROM quiz_attempts WHERE user_id NOT IN (SELECT id FROM users) OR quiz_id NOT IN (SELECT id FROM quizzes)',
        'attempt_answers' => 'SELECT COUNT(*) FROM attempt_answers WHERE attempt_id NOT IN (SELECT id FROM quiz_attempts) OR question_id NOT IN (SELECT id FROM questions)',
        'assignment_submissions' => 'SELECT COUNT(*) FROM assignment_submissions WHERE user_id NOT IN (SELECT id FROM users)',
        'attendance' => 'SELECT COUNT(*) FROM attendance WHERE user_id NOT IN (SELECT id FROM users) OR (course_id IS NOT NULL AND course_id NOT IN (SELECT id FROM courses))',
        'certificates' => 'SELECT COUNT(*) FROM certificates WHERE user_id NOT IN (SELECT id FROM users) OR course_id NOT IN (SELECT id FROM courses)',
        'payments' => 'SELECT COUNT(*) FROM payments WHERE user_id NOT IN (SELECT id FROM users) OR (course_id IS NOT NULL AND course_id NOT IN (SELECT id FROM courses))',
        'classes' => 'SELECT COUNT(*) FROM classes WHERE course_id IS NOT NULL AND course_id NOT IN (SELECT id FROM courses)',
        'class_students' => 'SELECT COUNT(*) FROM class_students WHERE user_id NOT IN (SELECT id FROM users) OR class_id NOT IN (SELECT id FROM classes)',
        'lessons' => 'SELECT COUNT(*) FROM lessons WHERE course_id NOT IN (SELECT id FROM courses)',
        'modules' => 'SELECT COUNT(*) FROM modules WHERE course_id NOT IN (SELECT id FROM courses)',
        'quiz_questions' => 'SELECT COUNT(*) FROM quiz_questions WHERE quiz_id NOT IN (SELECT id FROM quizzes) OR question_id NOT IN (SELECT id FROM questions)',
        'questions_quiz' => 'SELECT COUNT(*) FROM questions WHERE quiz_id IS NOT NULL AND quiz_id NOT IN (SELECT id FROM quizzes)',
        'quizzes_lesson' => 'SELECT COUNT(*) FROM quizzes WHERE lesson_id IS NOT NULL AND lesson_id NOT IN (SELECT id FROM lessons)',
        'forum_topics' => 'SELECT COUNT(*) FROM forum_topics WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)',
        'forum_replies' => 'SELECT COUNT(*) FROM forum_replies WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)',
        'lesson_comments' => 'SELECT COUNT(*) FROM lesson_comments WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)',
        'bookmarks' => 'SELECT COUNT(*) FROM bookmarks WHERE user_id NOT IN (SELECT id FROM users) OR lesson_id NOT IN (SELECT id FROM lessons)',
        'notifications' => 'SELECT COUNT(*) FROM notifications WHERE user_id NOT IN (SELECT id FROM users)',
        'schedules' => 'SELECT COUNT(*) FROM schedules WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)',
        'assignment_submissions_all' => 'SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id NOT IN (SELECT id FROM assignments)',
        'ai_messages' => 'SELECT COUNT(*) FROM ai_messages WHERE user_id NOT IN (SELECT id FROM users) OR lesson_id NOT IN (SELECT id FROM lessons)',
    ];
    $hasil = [];
    foreach ($daftar as $kunci => $label) {
        try {
            $n = (int)db()->query($sql[$kunci])->fetchColumn();
        } catch (Throwable $e) {
            $n = 0;
        }
        if ($n > 0) {
            $hasil[$kunci] = ['label' => $label, 'jumlah' => $n];
        }
    }
    return $hasil;
}

/** Hapus seluruh baris yatim. Return ringkasan jumlah per kategori. */
function lms_orphan_cleanup()
{
    $pdo = db();
    $hasil = [];
    $jalankan = function ($label, $sql) use ($pdo, &$hasil) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $n = $stmt->rowCount();
            if ($n > 0) {
                $hasil[$label] = $n;
            }
        } catch (Throwable $e) {
            $hasil[$label] = 'gagal: ' . $e->getMessage();
        }
    };
    $jalankan('Jawaban siswa', 'DELETE FROM attempt_answers WHERE attempt_id NOT IN (SELECT id FROM quiz_attempts) OR question_id NOT IN (SELECT id FROM questions)');
    $jalankan('Pengerjaan quiz', 'DELETE FROM quiz_attempts WHERE user_id NOT IN (SELECT id FROM users) OR quiz_id NOT IN (SELECT id FROM quizzes)');
    $jalankan('Progress materi', 'DELETE FROM lesson_progress WHERE user_id NOT IN (SELECT id FROM users) OR lesson_id NOT IN (SELECT id FROM lessons) OR course_id NOT IN (SELECT id FROM courses)');
    $jalankan('Pengumpulan tugas', 'DELETE FROM assignment_submissions WHERE user_id NOT IN (SELECT id FROM users) OR assignment_id NOT IN (SELECT id FROM assignments)');
    $jalankan('Absensi', 'DELETE FROM attendance WHERE user_id NOT IN (SELECT id FROM users) OR (course_id IS NOT NULL AND course_id NOT IN (SELECT id FROM courses))');
    $jalankan('Sertifikat', 'DELETE FROM certificates WHERE user_id NOT IN (SELECT id FROM users) OR course_id NOT IN (SELECT id FROM courses)');
    $jalankan('Pembayaran', 'DELETE FROM payments WHERE user_id NOT IN (SELECT id FROM users) OR (course_id IS NOT NULL AND course_id NOT IN (SELECT id FROM courses))');
    $jalankan('Pendaftaran kursus', 'DELETE FROM enrollments WHERE user_id NOT IN (SELECT id FROM users) OR course_id NOT IN (SELECT id FROM courses)');
    $jalankan('Anggota kelas', 'DELETE FROM class_students WHERE user_id NOT IN (SELECT id FROM users) OR class_id NOT IN (SELECT id FROM classes)');
    $jalankan('Kelas', 'DELETE FROM classes WHERE course_id IS NOT NULL AND course_id NOT IN (SELECT id FROM courses)');
    $jalankan('Penugasan soal ke quiz (tabel lama)', 'DELETE FROM quiz_questions WHERE quiz_id NOT IN (SELECT id FROM quizzes) OR question_id NOT IN (SELECT id FROM questions)');
    $jalankan('Kepemilikan soal (quiz pemiliknya hilang)', 'UPDATE questions SET quiz_id = NULL WHERE quiz_id IS NOT NULL AND quiz_id NOT IN (SELECT id FROM quizzes)');
    // Quiz yang menempel ke materi yang sudah dihapus dilepas (tetap ada di daftar quiz kursus).
    $jalankan('Penautan quiz ke materi (materi sudah hilang)', 'UPDATE quizzes SET lesson_id = NULL WHERE lesson_id IS NOT NULL AND lesson_id NOT IN (SELECT id FROM lessons)');
    $jalankan('Balasan forum', 'DELETE FROM forum_replies WHERE topic_id NOT IN (SELECT id FROM forum_topics)');
    $jalankan('Topik forum', 'DELETE FROM forum_topics WHERE course_id IS NOT NULL AND course_id NOT IN (SELECT id FROM courses)');
    $jalankan('Komentar materi', 'DELETE FROM lesson_comments WHERE lesson_id NOT IN (SELECT id FROM lessons)');
    $jalankan('Bookmark', 'DELETE FROM bookmarks WHERE user_id NOT IN (SELECT id FROM users) OR lesson_id NOT IN (SELECT id FROM lessons)');
    $jalankan('Notifikasi', 'DELETE FROM notifications WHERE user_id NOT IN (SELECT id FROM users)');
    $jalankan('Jadwal', 'DELETE FROM schedules WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)');
    $jalankan('Riwayat obrolan AI', 'DELETE FROM ai_messages WHERE user_id NOT IN (SELECT id FROM users) OR lesson_id NOT IN (SELECT id FROM lessons)');
    $jalankan('Materi', 'DELETE FROM lessons WHERE course_id NOT IN (SELECT id FROM courses)');
    $jalankan('Modul', 'DELETE FROM modules WHERE course_id NOT IN (SELECT id FROM courses)');
    // penulis yang sudah tidak ada: dilepas, isinya dipertahankan
    foreach ([
        'Topik forum (penulis dilepas)' => 'UPDATE forum_topics SET user_id = NULL WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)',
        'Balasan forum (penulis dilepas)' => 'UPDATE forum_replies SET user_id = NULL WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)',
        'Komentar materi (penulis dilepas)' => 'UPDATE lesson_comments SET user_id = NULL WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)',
        'Jadwal (penulis dilepas)' => 'UPDATE schedules SET user_id = NULL WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)',
        'Kursus (pengajar dilepas)' => 'UPDATE courses SET guru_id = NULL WHERE guru_id IS NOT NULL AND guru_id NOT IN (SELECT id FROM users)',
        'Kelas (pengajar dilepas)' => 'UPDATE classes SET guru_id = NULL WHERE guru_id IS NOT NULL AND guru_id NOT IN (SELECT id FROM users)',
        'Quiz (pengajar dilepas)' => 'UPDATE quizzes SET guru_id = NULL WHERE guru_id IS NOT NULL AND guru_id NOT IN (SELECT id FROM users)',
        'Tugas (pengajar dilepas)' => 'UPDATE assignments SET guru_id = NULL WHERE guru_id IS NOT NULL AND guru_id NOT IN (SELECT id FROM users)',
        'Pengumuman (penulis dilepas)' => 'UPDATE announcements SET guru_id = NULL WHERE guru_id IS NOT NULL AND guru_id NOT IN (SELECT id FROM users)',
    ] as $label => $sql) {
        $jalankan($label, $sql);
    }
    return $hasil;
}

/* ------------------------------------------------------------- pembayaran */

/** Modul pembayaran dinyalakan admin? */
function pembayaran_aktif()
{
    return setting('bayar_aktif', '1') === '1';
}

/* ------------------------------------------------------------- sertifikat */

/**
 * Fitur sertifikat dinyalakan admin? (Pengaturan → Website)
 *
 * Saat NONAKTIF: menu & halaman sertifikat disembunyikan dari siswa, guru,
 * admin, dan publik; statistik/tautan sertifikat tidak ditampilkan; penerbitan
 * otomatis maupun manual berhenti; verifikasi publik dimatikan.
 * Data di tabel `certificates` TIDAK dihapus — menyalakannya lagi
 * mengembalikan semuanya seperti semula.
 */
function sertifikat_aktif()
{
    return setting('sertifikat_aktif', '0') === '1';
}

/**
 * FAQ yang tampil ke publik. Ketika fitur sertifikat dimatikan, entri FAQ
 * berkategori "Sertifikat" disembunyikan (isinya menjanjikan sertifikat yang
 * belum tentu terbit). Datanya tetap ada & tetap bisa dikelola admin di
 * Manajemen FAQ.
 */
function faq_publik($limit = 0)
{
    $sql = 'SELECT * FROM faqs';
    if (!sertifikat_aktif()) {
        $sql .= " WHERE COALESCE(kategori, '') <> 'Sertifikat'";
    }
    $sql .= ' ORDER BY urutan, id';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
    }
    return db()->query($sql)->fetchAll();
}

/** Daftar rekening tujuan transfer. */
function rekening_list()
{
    $raw = setting('bayar_rekening', '');
    $arr = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($arr)) {
        return [];
    }
    $out = [];
    foreach ($arr as $r) {
        if (!is_array($r)) {
            continue;
        }
        $bank = trim((string)($r['bank'] ?? ''));
        $nomor = trim((string)($r['nomor'] ?? ''));
        if ($bank === '' && $nomor === '') {
            continue;
        }
        $out[] = ['bank' => $bank, 'nomor' => $nomor, 'nama' => trim((string)($r['nama'] ?? ''))];
    }
    return $out;
}

/** Apakah kursus ini butuh pembayaran? (harga > 0 & modul pembayaran aktif) */
function kursus_berbayar($course)
{
    return pembayaran_aktif() && (float)($course['harga'] ?? 0) > 0;
}

/**
 * Status akses seorang siswa terhadap sebuah kursus.
 * Return: ['status' => 'belum_daftar'|'gratis'|'menunggu_bayar'|'ditolak'|'aktif'|'selesai',
 *          'boleh' => bool, 'pesan' => string, 'berbayar' => bool]
 */
function cek_akses_kursus($user_id, $course)
{
    $course_id = (int)($course['id'] ?? $course);
    $harga = is_array($course) ? (float)($course['harga'] ?? 0) : 0;
    if (!is_array($course)) {
        $stmt = db()->prepare('SELECT id, judul, harga FROM courses WHERE id = ?');
        $stmt->execute([$course_id]);
        $course = $stmt->fetch() ?: ['id' => $course_id, 'harga' => 0];
        $harga = (float)($course['harga'] ?? 0);
    }
    $berbayar = kursus_berbayar($course);

    $stmt = db()->prepare('SELECT status FROM enrollments WHERE user_id = ? AND course_id = ?');
    $stmt->execute([(int)$user_id, $course_id]);
    $status = $stmt->fetchColumn();

    if (!$status) {
        return [
            'status' => 'belum_daftar',
            'boleh' => false,
            'berbayar' => $berbayar,
            'pesan' => $berbayar ? 'Kursus ini berbayar. Daftar dan selesaikan pembayaran untuk membuka materinya.' : 'Ikuti kursus ini terlebih dahulu untuk membuka materinya.',
        ];
    }
    if (in_array($status, ['aktif', 'selesai'], true)) {
        return ['status' => $status, 'boleh' => true, 'berbayar' => $berbayar, 'pesan' => ''];
    }
    // menunggu_bayar / status lain
    $p = db()->prepare("SELECT status, catatan FROM payments WHERE user_id = ? AND course_id = ? ORDER BY id DESC LIMIT 1");
    $p->execute([(int)$user_id, $course_id]);
    $bayar = $p->fetch();
    $ditolak = $bayar && $bayar['status'] === 'ditolak';
    return [
        'status' => $ditolak ? 'ditolak' : 'menunggu_bayar',
        'boleh' => false,
        'berbayar' => $berbayar,
        'pesan' => $ditolak
            ? 'Bukti pembayaranmu ditolak admin' . (!empty($bayar['catatan']) ? ' (' . $bayar['catatan'] . ')' : '') . '. Silakan unggah ulang bukti pembayaran yang benar.'
            : ($bayar ? 'Pembayaranmu sedang menunggu verifikasi admin.' : 'Kursus ini terkunci sampai pembayaran selesai diverifikasi admin.'),
    ];
}

/** Label & warna badge untuk status akses kursus. */
function akses_badge($status)
{
    $map = [
        'gratis' => ['badge-success', 'Aktif'],
        'aktif' => ['badge-success', 'Aktif'],
        'selesai' => ['badge-info', 'Selesai'],
        'menunggu_bayar' => ['badge-warning', 'Menunggu pembayaran'],
        'ditolak' => ['badge-danger', 'Pembayaran ditolak'],
        'belum_daftar' => ['badge-muted', 'Belum terdaftar'],
    ];
    [$cls, $label] = $map[$status] ?? ['badge-muted', ucfirst((string)$status)];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/**
 * Jaga akses halaman yang butuh kursus terbuka. Bila terkunci, siswa diarahkan
 * ke halaman pembayaran dengan pesan yang jelas (bukan error mentah).
 * Return array akses.
 */
function wajib_akses_kursus($course, $redirect_back = null)
{
    $u = require_login();
    if (in_array($u['role'], ['guru', 'admin'], true)) {
        return ['status' => 'staf', 'boleh' => true, 'berbayar' => false, 'pesan' => ''];
    }
    $akses = cek_akses_kursus((int)$u['id'], $course);
    if ($akses['boleh']) {
        return $akses;
    }
    if ($akses['status'] === 'belum_daftar') {
        flash($akses['pesan'], 'warning');
        redirect('kursus-detail.php?id=' . (int)(is_array($course) ? $course['id'] : $course));
    }
    flash($akses['pesan'], 'warning');
    redirect('siswa-pembayaran.php?kursus=' . (int)(is_array($course) ? $course['id'] : $course));
}

/** Total nominal yang sudah terverifikasi untuk sebuah siswa (opsional laporan). */
function total_pembayaran_terverifikasi($user_id = null)
{
    $sql = "SELECT COALESCE(SUM(jumlah), 0) FROM payments WHERE status = 'terverifikasi'";
    if ($user_id) {
        $sql .= ' AND user_id = ' . (int)$user_id;
    }
    return (float)db()->query($sql)->fetchColumn();
}

/* ------------------------------------------------------------ quiz engine */

function attempt_remaining_seconds($attempt)
{
    if (!$attempt['batas_waktu']) {
        return null;
    }
    return strtotime($attempt['batas_waktu']) - time();
}

function attempt_max_score($attempt_id)
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(q.skor), 0) FROM attempt_answers a JOIN questions q ON q.id = a.question_id WHERE a.attempt_id = ?');
    $stmt->execute([$attempt_id]);
    return (float)$stmt->fetchColumn();
}

function attempt_score($attempt_id)
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(skor), 0) FROM attempt_answers WHERE attempt_id = ?');
    $stmt->execute([$attempt_id]);
    return (float)$stmt->fetchColumn();
}

function normalize_answer($s)
{
    $s = mb_strtolower(trim((string)$s));
    $s = preg_replace('/\s+/', ' ', $s);
    $s = trim($s, " .!,;:");
    return $s;
}

function grade_answer($question, $jawaban)
{
    $tipe = $question['tipe'];
    $opsi = $question['opsi'] ? json_decode($question['opsi'], true) : [];
    $kunci = (string)$question['jawaban'];
    if ($tipe === 'mc') {
        if ($jawaban === null || $jawaban === '') {
            return 0.0;
        }
        return (string)$jawaban === $kunci ? (float)$question['skor'] : 0.0;
    }
    if ($tipe === 'tf') {
        if ($jawaban === null || $jawaban === '') {
            return 0.0;
        }
        return normalize_answer($jawaban) === normalize_answer($kunci) ? (float)$question['skor'] : 0.0;
    }
    if ($tipe === 'fill') {
        if ($jawaban === null || $jawaban === '') {
            return 0.0;
        }
        $alternatives = array_map('normalize_answer', explode('|', $kunci));
        return in_array(normalize_answer($jawaban), $alternatives, true) ? (float)$question['skor'] : 0.0;
    }
    if ($tipe === 'mcma') {
        // Pilihan ganda kompleks: jawaban benar lebih dari satu.
        $kunciArr = json_decode($kunci, true);
        $pilihArr = $jawaban ? json_decode((string)$jawaban, true) : [];
        if (!is_array($kunciArr) || !$kunciArr || !is_array($pilihArr) || !$pilihArr) {
            return 0.0;
        }
        $kunciArr = array_values(array_unique(array_map('strval', $kunciArr)));
        $pilihArr = array_values(array_unique(array_map('strval', $pilihArr)));
        if (($question['penilaian'] ?? 'penuh') === 'parsial') {
            $benar = count(array_intersect($pilihArr, $kunciArr));
            $salah = count(array_diff($pilihArr, $kunciArr));
            $rasio = ($benar - $salah) / count($kunciArr);
            return max(0.0, round((float)$question['skor'] * min(1.0, $rasio), 2));
        }
        $a = $pilihArr;
        $b = $kunciArr;
        sort($a);
        sort($b);
        return $a === $b ? (float)$question['skor'] : 0.0;
    }
    if ($tipe === 'kategori') {
        // Pilihan ganda kompleks kategori: setiap item dipetakan ke satu kategori.
        $items = soal_item_list($question);
        $kunciArr = json_decode($kunci, true);
        $pilihArr = $jawaban ? json_decode((string)$jawaban, true) : [];
        if (!$items || !is_array($kunciArr) || !is_array($pilihArr)) {
            return 0.0;
        }
        $benar = 0;
        foreach ($items as $i => $_) {
            $pilih = $pilihArr[(string)$i] ?? ($pilihArr[$i] ?? null);
            $kunciItem = $kunciArr[(string)$i] ?? ($kunciArr[$i] ?? null);
            if ($pilih !== null && $pilih !== '' && $kunciItem !== null && (string)$pilih === (string)$kunciItem) {
                $benar++;
            }
        }
        return round((float)$question['skor'] * ($benar / count($items)), 2);
    }
    if ($tipe === 'matching') {
        $pairs = is_array($opsi) ? $opsi : [];
        $jawab = $jawaban ? json_decode($jawaban, true) : [];
        if (!is_array($jawab) || !$pairs) {
            return 0.0;
        }
        $benar = 0;
        foreach ($pairs as $i => $pair) {
            $parts = explode('|', (string)$pair);
            $right = $parts[1] ?? '';
            if (isset($jawab[$i]) && normalize_answer($jawab[$i]) === normalize_answer($right)) {
                $benar++;
            }
        }
        return round((float)$question['skor'] * ($benar / count($pairs)), 2);
    }
    return null; // essay: dikoreksi manual guru
}

function grade_attempt($attempt_id, $waktu_habis = 0)
{
    $pdo = db();
    // Catatan: kolom dengan nama sama harus di-alias, kalau tidak nilai kolom
    // dari tabel questions akan menimpa jawaban siswa (bug: essay dianggap kosong).
    $stmt = $pdo->prepare('SELECT a.id AS answer_id, a.jawaban AS jawaban_siswa, q.tipe, q.opsi, q.jawaban AS kunci, q.skor AS skor_maks,
            q.penilaian, q.opsi_kategori
        FROM attempt_answers a JOIN questions q ON q.id = a.question_id WHERE a.attempt_id = ?');
    $stmt->execute([$attempt_id]);
    $rows = $stmt->fetchAll();
    $benar = 0;
    $salah = 0;
    $kosong = 0;
    $adaEssay = false;
    $upd = $pdo->prepare('UPDATE attempt_answers SET benar = ?, skor = ? WHERE id = ?');
    foreach ($rows as $r) {
        $jawaban = $r['jawaban_siswa'];
        $soal = [
            'tipe' => $r['tipe'],
            'opsi' => $r['opsi'],
            'jawaban' => $r['kunci'],
            'skor' => $r['skor_maks'],
            'penilaian' => $r['penilaian'],
            'opsi_kategori' => $r['opsi_kategori'],
        ];
        if ($r['tipe'] === 'essay') {
            $adaEssay = true;
            if ($jawaban === null || trim((string)$jawaban) === '') {
                $kosong++;
                $upd->execute([0, 0, $r['answer_id']]);
            }
            continue;
        }
        if ($jawaban === null || $jawaban === '') {
            $kosong++;
            $upd->execute([0, 0, $r['answer_id']]);
            continue;
        }
        $skor = grade_answer($soal, $jawaban);
        $isBenar = $skor >= (float)$r['skor_maks'] ? 1 : 0;
        if ($isBenar) {
            $benar++;
        } else {
            $salah++;
        }
        $upd->execute([$isBenar, $skor, $r['answer_id']]);
    }
    $max = attempt_max_score($attempt_id);
    $got = attempt_score($attempt_id);
    $nilai = $max > 0 ? round($got / $max * 100, 2) : 0;
    $status = $adaEssay ? 'menunggu_koreksi' : 'dinilai';
    $pdo->prepare('UPDATE quiz_attempts SET selesai = CURRENT_TIMESTAMP, nilai = ?, benar = ?, salah = ?, kosong = ?, status = ?, waktu_habis = ? WHERE id = ?')
        ->execute([$nilai, $benar, $salah, $kosong, $status, $waktu_habis ? 1 : 0, $attempt_id]);
    return $nilai;
}

function recalc_course_progress($user_id, $course_id)
{
    $pdo = db();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM lessons WHERE course_id = ' . (int)$course_id)->fetchColumn();
    $done = (int)$pdo->query('SELECT COUNT(*) FROM lesson_progress WHERE selesai = 1 AND user_id = ' . (int)$user_id . ' AND course_id = ' . (int)$course_id)->fetchColumn();
    $progress = $total > 0 ? round($done / $total * 100, 2) : 0;
    $pdo->prepare('UPDATE enrollments SET progress = ? WHERE user_id = ? AND course_id = ?')->execute([$progress, $user_id, $course_id]);
    if ($progress >= 100) {
        maybe_issue_certificate($user_id, $course_id);
    }
    return $progress;
}

function course_average_score($user_id, $course_id)
{
    $stmt = db()->prepare("SELECT AVG(nilai) FROM quiz_attempts WHERE user_id = ? AND status IN ('dinilai','menunggu_koreksi') AND quiz_id IN (SELECT id FROM quizzes WHERE course_id = ?)");
    $stmt->execute([$user_id, $course_id]);
    return round((float)$stmt->fetchColumn(), 2);
}

function course_full_completed($user_id, $course_id)
{
    $stmt = db()->prepare('SELECT progress FROM enrollments WHERE user_id = ? AND course_id = ?');
    $stmt->execute([$user_id, $course_id]);
    $row = $stmt->fetch();
    return $row && (float)$row['progress'] >= 100;
}

function generate_certificate($user_id, $course_id, $nilai = null)
{
    if (!sertifikat_aktif()) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM certificates WHERE user_id = ? AND course_id = ?');
    $stmt->execute([$user_id, $course_id]);
    $existing = $stmt->fetch();
    if ($existing) {
        return $existing;
    }
    if ($nilai === null) {
        $nilai = course_average_score($user_id, $course_id);
    }
    $nomor = setting('cert_prefix', 'GLC') . '-' . date('Y') . '-' . str_pad((string)$course_id, 3, '0', STR_PAD_LEFT) . '-' . str_pad((string)$user_id, 5, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO certificates (nomor, user_id, course_id, nilai_akhir, tanggal) VALUES (?, ?, ?, ?, ?)')
        ->execute([$nomor, $user_id, $course_id, $nilai, date('Y-m-d')]);
    $stmt = $pdo->prepare('SELECT * FROM certificates WHERE user_id = ? AND course_id = ?');
    $stmt->execute([$user_id, $course_id]);
    $cert = $stmt->fetch();
    notify($user_id, 'Sertifikat diterbitkan', 'Selamat! Sertifikat kursus kamu sudah tersedia.', 'siswa-sertifikat.php');
    audit('sertifikat', 'Menerbitkan sertifikat ' . $nomor);
    return $cert;
}

function maybe_issue_certificate($user_id, $course_id)
{
    if (!sertifikat_aktif()) {
        return null;
    }
    if (!course_full_completed($user_id, $course_id)) {
        return null;
    }
    $pdo = db();
    // Semua quiz/ujian wajib sudah dikerjakan & lulus passing grade
    $stmt = $pdo->prepare('SELECT * FROM quizzes WHERE course_id = ? AND status = "aktif"');
    $stmt->execute([$course_id]);
    foreach ($stmt->fetchAll() as $q) {
        $a = $pdo->prepare('SELECT MAX(nilai) FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status IN ("dinilai","menunggu_koreksi")');
        $a->execute([$q['id'], $user_id]);
        $best = $a->fetchColumn();
        if ($best === null || (float)$best < (float)$q['passing_grade']) {
            return null;
        }
    }
    return generate_certificate($user_id, $course_id);
}

/* ----------------------------------------------------------------- misc */

function paginate($total, $per_page, $page)
{
    $pages = max(1, (int)ceil($total / $per_page));
    $page = max(1, min($page, $pages));
    return ['total' => (int)$total, 'per_page' => $per_page, 'page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $per_page];
}

function pager_html($p, $base_url)
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $sep = strpos($base_url, '?') === false ? '?' : '&';
    $html = '<div class="pager">';
    if ($p['page'] > 1) {
        $html .= '<a class="btn btn-ghost btn-sm" href="' . e($base_url . $sep . 'page=' . ($p['page'] - 1)) . '">&laquo; Sebelumnya</a>';
    }
    $html .= '<span class="pager-info">Halaman ' . $p['page'] . ' dari ' . $p['pages'] . ' &middot; ' . $p['total'] . ' data</span>';
    if ($p['page'] < $p['pages']) {
        $html .= '<a class="btn btn-ghost btn-sm" href="' . e($base_url . $sep . 'page=' . ($p['page'] + 1)) . '">Berikutnya &raquo;</a>';
    }
    return $html . '</div>';
}

function csv_download($filename, $headers, $rows)
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

function tipe_soal_label($t)
{
    return [
        'mc' => 'Pilihan Ganda',
        'tf' => 'Benar / Salah',
        'essay' => 'Essay',
        'matching' => 'Matching',
        'fill' => 'Fill in the Blank',
        'mcma' => 'PG Kompleks — MCMA',
        'kategori' => 'PG Kompleks — Kategori',
    ][$t] ?? $t;
}

/** Daftar tipe materi yang didukung — SATU tempat (dropdown, import, validasi). */
function tipe_materi_label_map()
{
    return [
        'video' => 'Video',
        'pdf' => 'PDF / E-book',
        'audio' => 'Audio',
        'teks' => 'Modul Teks',
        'tautan' => 'Tautan',
        // Materi berisi ringkasan visual (lihat includes/infografis.php).
        'infografis' => 'Infografis',
    ];
}

function tipe_materi_valid()
{
    return array_keys(tipe_materi_label_map());
}

function tipe_materi_label($t)
{
    $map = tipe_materi_label_map();
    return $map[$t] ?? $t;
}

/** Ikon daftar materi per tipe (kelas .lesson-ic juga memakai nama tipe ini). */
function tipe_materi_ikon($t)
{
    return ['video' => 'video', 'pdf' => 'pdf', 'audio' => 'audio', 'infografis' => 'chart'][$t] ?? 'file';
}

function tipe_materi_options()
{
    $out = [];
    foreach (tipe_materi_label_map() as $k => $v) {
        $out[$k] = $v;
    }
    return $out;
}

/** Semua tipe soal yang didukung (dipakai validasi & dropdown). */
function tipe_soal_valid()
{
    return ['mc', 'mcma', 'tf', 'essay', 'matching', 'fill', 'kategori'];
}

function tipe_soal_options()
{
    $out = [];
    foreach (tipe_soal_valid() as $t) {
        $out[$t] = tipe_soal_label($t);
    }
    return $out;
}

/**
 * Apakah jawaban tersimpan dianggap "sudah dijawab" (untuk penanda warna
 * navigasi soal di ujian.php)? Untuk tipe majemuk (matching/kategori) atau
 * MCMA, isi yang semuanya kosong tetap dianggap belum dijawab.
 * Logikanya sengaja dibuat sama dengan isAnswered() di assets/app.js.
 */
function jawaban_terisi($tipe, $jawaban)
{
    if ($jawaban === null) {
        return false;
    }
    $j = trim((string)$jawaban);
    if ($j === '' || $j === '{}' || $j === '[]') {
        return false;
    }
    if (in_array($tipe, ['matching', 'kategori'], true)) {
        foreach ((json_decode($j, true) ?: []) as $v) {
            if (trim((string)$v) !== '') {
                return true;
            }
        }
        return false;
    }
    if ($tipe === 'mcma') {
        return count(json_decode($j, true) ?: []) > 0;
    }
    return true;
}

/** Ambil daftar kategori sebuah soal bertipe 'kategori'. */
function soal_kategori_list($question)
{
    $k = $question['opsi_kategori'] ?? null;
    $arr = $k ? json_decode((string)$k, true) : [];
    return is_array($arr) ? array_values($arr) : [];
}

/** Ambil daftar item sebuah soal bertipe 'kategori'. */
function soal_item_list($question)
{
    $arr = $question['opsi'] ? json_decode((string)$question['opsi'], true) : [];
    return is_array($arr) ? array_values($arr) : [];
}

/* ------------------------------------------------- gambar soal (v7) */
/* `questions.gambar` = URL gambar pertanyaan, `questions.opsi_gambar` = JSON peta
   {"<index opsi>": "<url>"} untuk gambar tiap pilihan jawaban (tipe mc/mcma). */

/**
 * Bersihkan URL gambar sebelum disimpan: hanya http(s) yang boleh, atau path
 * relatif di dalam aplikasi (hasil fallback upload lokal saat belum publikasi).
 * Selain itu dikembalikan '' supaya tidak ada nilai aneh (mis. javascript:)
 * yang tersimpan ke database.
 */
function gambar_url_valid($url)
{
    $url = trim((string)$url);
    if ($url === '' || strlen($url) > 900) {
        return '';
    }
    if (preg_match('~^https?://[^\s<>"\'\\\\]+$~i', $url)) {
        return $url;
    }
    // Path relatif (mis. uploads/20250101-abcd.png) — tanpa skema, tanpa "..".
    if (strpos($url, '..') === false && preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', $url)) {
        return $url;
    }
    return '';
}

/** URL gambar pertanyaan ('' bila tidak ada). */
function soal_gambar($question)
{
    return gambar_url_valid($question['gambar'] ?? '');
}

/** Peta [index opsi => URL gambar] untuk soal bertipe mc/mcma. */
function soal_gambar_opsi($question)
{
    $raw = $question['opsi_gambar'] ?? null;
    if (!$raw) {
        return [];
    }
    $arr = json_decode((string)$raw, true);
    if (!is_array($arr)) {
        return [];
    }
    $out = [];
    foreach ($arr as $idx => $url) {
        $url = gambar_url_valid($url);
        if ($url !== '') {
            $out[(string)(int)$idx] = $url;
        }
    }
    return $out;
}

/** Gambar satu pilihan jawaban ('' bila tidak ada). */
function soal_gambar_opsi_satu($question, $index)
{
    $map = soal_gambar_opsi($question);
    return $map[(string)(int)$index] ?? '';
}

/**
 * <img> untuk gambar aplikasi. Bawaannya siap diklik untuk diperbesar
 * (lightbox global di layout); kirim $lightbox = false bila gambar itu tidak
 * boleh diperbesar (mis. gambar materi).
 */
function gambar_img($url, $class = 'q-img', $alt = 'Gambar soal', $lightbox = true)
{
    $url = gambar_url_valid($url);
    if ($url === '') {
        return '';
    }
    return '<img class="' . e($class) . '" src="' . e($url) . '" alt="' . e($alt) . '"'
        . ($lightbox ? ' data-lightbox' : '') . ' loading="lazy">';
}

/**
 * Slot pemilih gambar untuk form soal (dipakai juga oleh JavaScript lewat
 * window.LMS_IMG_SLOT, jadi markupnya harus sama persis). Berisi: pratinjau,
 * tombol Upload file, tombol tempel URL, dan tombol Hapus. Nilai akhir dikirim
 * lewat input hidden bernama $name.
 */
function gambar_slot($name, $value = '', $label = 'Gambar', $small = false)
{
    $value = gambar_url_valid($value);
    $id = 'gsl-' . preg_replace('/[^A-Za-z0-9]+/', '-', $name) . '-' . substr(md5($name . '|' . $value), 0, 6);
    $cls = 'img-slot' . ($small ? ' img-slot-sm' : '') . ($value !== '' ? ' has-img' : '');
    $prev = $value !== ''
        ? '<img src="' . e($value) . '" alt="Pratinjau ' . e($label) . '" data-lightbox="">'
        : '<span class="img-slot-empty">' . icon('image', 22) . '<em>Belum ada gambar</em></span>';
    return '<div class="' . $cls . '" data-img-slot>'
        . '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '" data-img-value>'
        . '<div class="img-slot-prev" data-img-preview>' . $prev . '</div>'
        . '<div class="row-gap img-slot-actions">'
        . '<label class="btn btn-ghost btn-sm" for="' . e($id) . '">' . icon('upload', 15) . ' Upload' . '</label>'
        . '<input type="file" id="' . e($id) . '" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" data-img-file class="img-file">'
        . '<button type="button" class="btn btn-ghost btn-sm" data-img-url-toggle>' . icon('link', 15) . ' URL</button>'
        . '<button type="button" class="btn btn-danger btn-sm" data-img-clear' . ($value === '' ? ' hidden' : '') . '>' . icon('x', 15) . ' Hapus</button>'
        . '</div>'
        . '<input type="text" class="img-url-input" placeholder="https://contoh.com/gambar.png" data-img-url value="' . (preg_match('~^https?://~i', $value) ? e($value) : '') . '" hidden>'
        . '<div class="form-hint img-slot-status" data-img-status hidden></div>'
        . '</div>';
}

/* ---------------------------------------------------------------- bank soal */
/* Bank soal dimiliki KURSUS (questions.course_id) dan tiap soal hanya boleh
   dimiliki SATU quiz (questions.quiz_id; NULL = belum ditugaskan ke quiz mana
   pun). Satu soal tidak bisa dipakai dua quiz sekaligus: memakainya untuk quiz
   lain berarti memindahkan kepemilikannya. Tabel warisan quiz_questions tidak
   dipakai lagi sejak skema 10. */

/** Peta [question_id => [['id'=>quiz_id,'judul'=>...], ...]] untuk satu kursus. */
function bank_soal_pakai_map($courseId)
{
    $st = db()->prepare('SELECT q.id AS question_id, qz.id, qz.judul FROM questions q
        JOIN quizzes qz ON qz.id = q.quiz_id WHERE q.course_id = ? ORDER BY qz.judul, qz.id');
    $st->execute([(int)$courseId]);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[(int)$r['question_id']][] = ['id' => (int)$r['id'], 'judul' => $r['judul']];
    }
    return $map;
}

/** Jumlah soal tiap quiz ([quiz_id => jumlah]). $courseId=0 berarti semua kursus. */
function quiz_jml_soal_map($courseId = 0)
{
    $sql = 'SELECT q.quiz_id AS quiz_id, COUNT(*) AS jml FROM questions q
        WHERE q.quiz_id IS NOT NULL' . ($courseId ? (' AND q.course_id = ' . (int)$courseId) : '') . '
        GROUP BY q.quiz_id';
    $out = [];
    foreach (db()->query($sql)->fetchAll() as $r) {
        $out[(int)$r['quiz_id']] = (int)$r['jml'];
    }
    return $out;
}

/* -------------------------------------- quiz yang tampil DI DALAM materi */

/**
 * Normalisasi judul untuk mencocokkan judul quiz dengan judul materi:
 * huruf kecil, tanpa nomor urut & kata umum di depan ("1. ", "Quiz ", "Ujian 2 - "),
 * tanpa tanda baca.
 * Contoh: "1. Alphabet & Phonics" -> "alphabet phonics".
 */
function judul_normal($s)
{
    $s = mb_strtolower(trim((string)$s));
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/^\s*\d+\s*[\.\)\-:]?\s*/u', '', $s);
    $s = preg_replace('/^(?:(?:quiz|ujian|latihan|soal|materi|bab)\s*\d*\s*[\.\)\-:]?\s*)+/u', '', $s);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', (string)$s);
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}

/** Apakah judul quiz dianggap sama dengan judul materi (lihat judul_normal()). */
function quiz_judul_cocok($judulQuiz, $judulMateri)
{
    $a = judul_normal($judulQuiz);
    return $a !== '' && $a === judul_normal($judulMateri);
}

/**
 * Validasi pilihan "tampilkan di dalam materi": materi harus ada DAN satu kursus
 * dengan quiz. Mengembalikan id materi atau null (dianggap tidak ditautkan).
 */
function quiz_lesson_id_valid($courseId, $lessonId)
{
    if ((int)$lessonId <= 0 || (int)$courseId <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT id FROM lessons WHERE id = ? AND course_id = ?');
    $st->execute([(int)$lessonId, (int)$courseId]);
    return $st->fetchColumn() ? (int)$lessonId : null;
}

/**
 * Kotak pilihan "tampilkan quiz di dalam materi" untuk form quiz guru/admin.
 * $lessonsPerKursus = [course_id => daftar materi], $judulKursus = [course_id => judul kursus].
 */
function form_pilih_materi_quiz($edit, array $lessonsPerKursus, array $judulKursus)
{
    $pilih = (int)($edit['lesson_id'] ?? 0);
    $html = '<div class="form-group"><label>Tampilkan di dalam materi (opsional)</label>'
        . '<select name="lesson_id"><option value="">- tidak ditautkan ke materi -</option>';
    foreach ($lessonsPerKursus as $cid => $daftar) {
        $html .= '<optgroup label="' . e($judulKursus[$cid] ?? ('Kursus ' . (int)$cid)) . '">';
        foreach ($daftar as $ls) {
            $html .= '<option value="' . (int)$ls['id'] . '"' . ($pilih === (int)$ls['id'] ? ' selected' : '') . '>' . e($ls['judul']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html . '</select><div class="form-hint">Quiz akan muncul di bawah isi materi itu pada halaman belajar siswa. '
        . 'Quiz yang judulnya sama dengan judul materi juga tampil otomatis walau tidak ditautkan di sini.</div></div>';
}

/**
 * Quiz yang ditampilkan DI DALAM sebuah materi (di bawah isi materi).
 * Ada dua sumber: (1) ditautkan eksplisit lewat quizzes.lesson_id, dan (2) judul
 * quiz sama dengan judul materi — supaya quiz yang sudah ada langsung muncul di
 * materinya tanpa perlu diatur ulang (lihat catatan di AGENTS.md).
 */
function quiz_untuk_materi(array $quizzes, $lessonId, $judulMateri)
{
    $out = [];
    foreach ($quizzes as $qz) {
        if ((int)($qz['lesson_id'] ?? 0) === (int)$lessonId || quiz_judul_cocok($qz['judul'] ?? '', $judulMateri)) {
            $out[] = $qz;
        }
    }
    return $out;
}

/**
 * Materi induk sebuah quiz untuk daftar di sisi guru/admin (hanya materi dari
 * kursus yang sama, jadi $lessons harus daftar materi kursus quiz itu):
 * ['judul' => '...', 'otomatis' => bool] atau null bila tidak tampil di materi mana pun.
 */
function quiz_materi_induk(array $quiz, array $lessons)
{
    $id = (int)($quiz['lesson_id'] ?? 0);
    if ($id > 0) {
        foreach ($lessons as $ls) {
            if ((int)$ls['id'] === $id) {
                return ['judul' => (string)$ls['judul'], 'otomatis' => false];
            }
        }
    }
    foreach ($lessons as $ls) {
        if (quiz_judul_cocok($quiz['judul'] ?? '', $ls['judul'] ?? '')) {
            return ['judul' => (string)$ls['judul'], 'otomatis' => true];
        }
    }
    return null;
}

/**
 * Satu baris kartu quiz. Dipakai DUA tempat: blok "Quiz materi ini" di halaman
 * belajar (materi.php) dan kartu "Quiz & Ujian" di sidebar halaman yang sama —
 * jadi aturan tombolnya (staf: Hasil, siswa: Kerjakan/Lihat hasil, belum
 * berakses: ajakan mendaftar/bayar) tidak mungkin berbeda antar tampilan.
 * $akses = hasil cek_akses_kursus(), $hasil = hasil terbaik siswa (atau null).
 */
function quiz_kartu_html(array $qz, $hasil, $jmlSoal, $staf, array $akses, $courseId)
{
    $qid = (int)$qz['id'];
    $lulus = $hasil && (float)$hasil['best'] >= (float)$qz['passing_grade'];
    $html = '<div class="lesson-item">'
        . '<span class="lesson-ic">' . icon('quiz', 17) . '</span>'
        . '<span class="lesson-meta">'
        . '<strong>' . e($qz['judul']) . ($qz['tipe'] === 'ujian' ? ' <span class="badge badge-warning">Ujian</span>' : '') . '</strong>'
        . '<small>' . (int)$jmlSoal . ' soal &middot; ' . (int)$qz['durasi_menit'] . ' menit &middot; nilai min ' . (int)$qz['passing_grade']
        . ((int)$qz['max_attempt'] ? ' &middot; maks ' . (int)$qz['max_attempt'] . 'x' : '') . '</small>';
    if ($hasil) {
        $html .= '<small>' . ($lulus ? '<span class="badge badge-success">Lulus</span>' : '<span class="badge badge-danger">Belum lulus</span>')
            . ' terbaik ' . number_format((float)$hasil['best'], 2) . ' &middot; ' . (int)$hasil['jml'] . 'x dikerjakan</small>';
    }
    $html .= '</span><span style="margin-left:auto" class="row-gap">';
    if ($staf) {
        $html .= '<a class="btn btn-ghost btn-sm" href="guru-koreksi.php?quiz=' . $qid . '">' . icon('check', 14) . ' Hasil</a>';
    } elseif (!empty($akses['boleh'])) {
        $html .= '<a class="btn btn-primary btn-sm" href="ujian.php?quiz=' . $qid . '">' . ($hasil ? 'Kerjakan Lagi' : 'Kerjakan') . '</a>';
        if ($hasil) {
            $html .= '<a class="btn btn-ghost btn-sm" href="ujian-hasil.php?attempt=' . (int)$hasil['attempt'] . '" title="Lihat hasil terbaik">' . icon('chart', 14) . '</a>';
        }
    } else {
        $html .= '<span class="muted small">';
        if (!is_logged_in()) {
            $html .= 'Masuk &amp; ikuti kursus dulu';
        } elseif (($akses['status'] ?? '') === 'belum_daftar') {
            $html .= '<a href="kursus-detail.php?id=' . (int)$courseId . '">Ikuti kursus dulu</a>';
        } else {
            $html .= '<a href="siswa-pembayaran.php?kursus=' . (int)$courseId . '">Selesaikan pembayaran</a>';
        }
        $html .= '</span>';
    }
    return $html . '</span></div>';
}

/** Semua soal milik sebuah quiz, urut sesuai urutan pembuatannya. */
function quiz_soal($quizId)
{
    $st = db()->prepare('SELECT * FROM questions WHERE quiz_id = ? ORDER BY id');
    $st->execute([(int)$quizId]);
    return $st->fetchAll();
}

/** Apakah user masih boleh memulai percobaan baru untuk quiz ini? */
function quiz_boleh_mulai(array $quiz, $userId)
{
    if ((int)($quiz['max_attempt'] ?? 0) > 0) {
        $st = db()->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status != "in_progress"');
        $st->execute([(int)$quiz['id'], (int)$userId]);
        if ((int)$st->fetchColumn() >= (int)$quiz['max_attempt']) {
            return false;
        }
    }
    return true;
}

/** Percobaan yang masih berjalan (in_progress) milik user untuk quiz ini. */
function quiz_attempt_berjalan($quizId, $userId)
{
    $st = db()->prepare('SELECT * FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status = "in_progress" ORDER BY id DESC LIMIT 1');
    $st->execute([(int)$quizId, (int)$userId]);
    $a = $st->fetch();
    return $a ?: null;
}

/**
 * Buat sesi ujian baru: pilih soal (acak bila quiz menghendaki), tentukan urutan
 * acak pilihan, lalu simpan attempt + baris attempt_answers.
 *
 * Satu-satunya tempat pembuatan sesi ujian — dipakai ujian.php (tombol "Mulai
 * Kerjakan") maupun api-offline.php (unduh quiz untuk dikerjakan offline).
 * $pakaiBatasWaktu = false dipakai penyiapan offline: batas waktu sengaja NULL
 * (belum berjalan), timer dimulai perangkat saat quiz benar-benar dibuka.
 *
 * @return int id attempt, atau 0 bila quiz belum punya soal.
 */
function quiz_mulai_attempt(array $quiz, $userId, $pakaiBatasWaktu = true)
{
    $all = quiz_soal((int)$quiz['id']);
    if (!$all) {
        return 0;
    }
    if ((int)$quiz['random_soal'] && (int)$quiz['jumlah_soal_tampil'] > 0) {
        shuffle($all);
        $all = array_slice($all, 0, (int)$quiz['jumlah_soal_tampil']);
    }
    $order = array_map('intval', array_column($all, 'id'));

    $opsiOrder = [];
    foreach ($all as $q) {
        if ($q['tipe'] === 'kategori') {
            // Untuk tipe kategori, urutan acak berlaku untuk daftar KATEGORI,
            // sedangkan item tetap ditampilkan berurutan.
            $kats = soal_kategori_list($q);
            $idx = range(0, max(0, count($kats) - 1));
        } else {
            $opsi = $q['opsi'] ? json_decode($q['opsi'], true) : [];
            $idx = range(0, max(0, count($opsi) - 1));
        }
        if ((int)$quiz['random_opsi'] && $q['tipe'] !== 'essay') {
            shuffle($idx);
        }
        $opsiOrder[(string)$q['id']] = $idx;
    }

    $batas = $pakaiBatasWaktu
        ? date('Y-m-d H:i:s', time() + max(1, (int)$quiz['durasi_menit']) * 60)
        : null;
    db()->prepare('INSERT INTO quiz_attempts (quiz_id, user_id, mulai, batas_waktu, status, soal_order, opsi_order) VALUES (?, ?, CURRENT_TIMESTAMP, ?, "in_progress", ?, ?)')
        ->execute([$quiz['id'], $userId, $batas, json_encode($order), json_encode($opsiOrder)]);
    $attemptId = (int)db()->lastInsertId();
    $ins = db()->prepare('INSERT INTO attempt_answers (attempt_id, question_id) VALUES (?, ?)');
    foreach ($order as $qid) {
        $ins->execute([$attemptId, $qid]);
    }
    return $attemptId;
}

/** Id kursus pemilik bank soal yang dipakai sebuah quiz. */
function quiz_course_id($quizId)
{
    $st = db()->prepare('SELECT course_id FROM quizzes WHERE id = ?');
    $st->execute([(int)$quizId]);
    return (int)$st->fetchColumn();
}

/**
 * Jadikan soal-soal ini milik $quizId (soal dari kursus lain ditolak supaya bank
 * soal tetap terpisah per kursus). Soal yang tadinya milik quiz lain pada kursus
 * yang sama akan BERPINDAH ke quiz ini — sejak skema 10 satu soal hanya boleh
 * dimiliki satu quiz.
 * Return: jumlah soal yang kepemilikannya benar-benar berubah.
 */
function quiz_assign_soal($quizId, array $ids)
{
    $quizId = (int)$quizId;
    $courseId = quiz_course_id($quizId);
    if ($quizId <= 0 || $courseId <= 0) {
        return 0;
    }
    $upd = db()->prepare('UPDATE questions SET quiz_id = ? WHERE id = ? AND course_id = ? AND (quiz_id IS NULL OR quiz_id <> ?)');
    $n = 0;
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        $upd->execute([$quizId, $id, $courseId, $quizId]);
        $n += $upd->rowCount();
    }
    return $n;
}

/**
 * Lepas soal dari sebuah quiz -> kembali ke bank soal kursusnya (quiz_id NULL).
 * Soal TIDAK dihapus.
 */
function quiz_unassign_soal($quizId, array $ids)
{
    $quizId = (int)$quizId;
    if ($quizId <= 0) {
        return 0;
    }
    $upd = db()->prepare('UPDATE questions SET quiz_id = NULL WHERE id = ? AND quiz_id = ?');
    $n = 0;
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        $upd->execute([$id, $quizId]);
        $n += $upd->rowCount();
    }
    return $n;
}

/**
 * Ambil $n soal acak dari bank soal kursus quiz yang BELUM dimiliki quiz mana pun
 * (soal milik quiz lain tidak dirampas). Return: jumlah soal yang dijadikan milik
 * quiz tersebut.
 */
function quiz_ambil_soal_acak($quizId, $n)
{
    $quizId = (int)$quizId;
    $n = (int)$n;
    if ($quizId <= 0 || $n <= 0) {
        return 0;
    }
    $st = db()->prepare('SELECT q.id FROM questions q JOIN quizzes qz ON qz.id = ?
        WHERE q.course_id = qz.course_id AND q.quiz_id IS NULL
        ORDER BY RANDOM() LIMIT ' . $n);
    $st->execute([$quizId]);
    $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
    return $ids ? quiz_assign_soal($quizId, $ids) : 0;
}

/**
 * Ubah satu baris Excel/CSV bank soal menjadi data soal siap simpan.
 * Format kolom: TIPE, PERTANYAAN, A, B, C, D, E, JAWABAN, PEMBAHASAN, SKOR, PENILAIAN
 *  - mc      : A-E = pilihan, JAWABAN = huruf/nomor
 *  - mcma    : A-E = pilihan, JAWABAN = huruf dipisah koma (mis. A,C,E)
 *  - kategori: A-E = nama kategori, JAWABAN = "Item=HurufKategori" dipisah ";" (mis. Apple=A;Carrot=B)
 *  - matching: A-E = pasangan "Kiri|Kanan", JAWABAN diabaikan
 *  - tf      : JAWABAN = benar/salah ; fill: JAWABAN = kunci (varian dipisah |)
 *  - essay   : dikoreksi manual, JAWABAN boleh kosong
 *  - GAMBAR  : URL gambar pertanyaan ; GAMBAR_A..GAMBAR_E = URL gambar tiap pilihan
 *              (kolom ke-12 dst, opsional — berkas format lama tetap bisa diimport)
 * Return: ['ok'=>bool, 'alasan'=>string, 'data'=>array]
 */
function soal_from_row(array $r)
{
    $pertanyaan = trim((string)($r[1] ?? ''));
    if ($pertanyaan === '') {
        return ['ok' => false, 'alasan' => 'pertanyaan kosong'];
    }
    $tipe = strtolower(trim((string)($r[0] ?? 'mc')));
    if (!in_array($tipe, tipe_soal_valid(), true)) {
        $tipe = 'mc';
    }
    $kolom = [2 => 'A', 3 => 'B', 4 => 'C', 5 => 'D', 6 => 'E'];
    $nilaiKolom = [];
    foreach ($kolom as $idx => $L) {
        $nilaiKolom[] = trim((string)($r[$idx] ?? ''));
    }
    $jawabanRaw = trim((string)($r[7] ?? ''));
    $pembahasan = trim((string)($r[8] ?? ''));
    $skor = (float)($r[9] ?? 1);
    if ($skor <= 0) {
        $skor = 1;
    }
    $penilaian = strtolower(trim((string)($r[10] ?? 'penuh'))) === 'parsial' ? 'parsial' : 'penuh';
    // Kolom gambar (opsional, hanya untuk mc/mcma): GAMBAR + GAMBAR_A..GAMBAR_E.
    // Nilai yang diisi tapi tidak dikenali (mis. path komputer "C:\..." atau
    // nama berkas lokal) DICATAT di $gambarDitolak supaya import bisa
    // melaporkannya — dulu nilainya hilang diam-diam sehingga pengguna
    // mengira gambarnya ikut ter-import padahal tidak.
    $gambarKolom = [];
    $gambarDitolak = [];
    for ($gi = 0; $gi < 5; $gi++) {
        $mentah = trim((string)($r[12 + $gi] ?? ''));
        $gambarKolom[$gi] = gambar_url_valid($mentah);
        if ($mentah !== '' && $gambarKolom[$gi] === '') {
            $gambarDitolak[] = 'GAMBAR_' . chr(65 + $gi) . ' = "' . mb_substr($mentah, 0, 40) . '"';
        }
    }
    $gambarMentah = trim((string)($r[11] ?? ''));
    $gambar = gambar_url_valid($gambarMentah);
    if ($gambarMentah !== '' && $gambar === '') {
        $gambarDitolak[] = 'GAMBAR = "' . mb_substr($gambarMentah, 0, 40) . '"';
    }
    $opsi = null;
    $opsiKategori = null;
    $opsiGambar = null;
    $jawaban = $jawabanRaw;

    $isiOpsi = array_values(array_filter($nilaiKolom, function ($x) {
        return $x !== '';
    }));

    if ($tipe === 'mc' || $tipe === 'mcma') {
        if (!$isiOpsi) {
            return ['ok' => false, 'alasan' => 'pilihan A-E kosong'];
        }
        $opsi = json_encode($isiOpsi);
        // Gambar pilihan mengikuti kolom A-E yang benar-benar berisi pilihan
        // (kolom kosong tidak menjadi opsi, jadi indexnya ikut digeser).
        $imgOpsi = [];
        $posOpsi = 0;
        foreach ($nilaiKolom as $ci => $teks) {
            if ($teks === '') {
                continue;
            }
            if ($gambarKolom[$ci] !== '') {
                $imgOpsi[$posOpsi] = $gambarKolom[$ci];
            }
            $posOpsi++;
        }
        $opsiGambar = $imgOpsi ? json_encode((object)$imgOpsi) : null;
        $pilih = [];
        foreach (preg_split('/[,;]+/', $jawabanRaw) as $bagian) {
            $bagian = trim($bagian);
            if ($bagian === '') {
                continue;
            }
            if (preg_match('/^[A-Ea-e]$/', $bagian)) {
                $pilih[] = (string)(ord(strtoupper($bagian)) - 65);
            } elseif (is_numeric($bagian)) {
                $pilih[] = (string)((int)$bagian);
            } else {
                $pos = array_search($bagian, $isiOpsi, true);
                if ($pos !== false) {
                    $pilih[] = (string)$pos;
                }
            }
        }
        $pilih = array_values(array_unique(array_filter($pilih, function ($x) use ($isiOpsi) {
            return (int)$x >= 0 && (int)$x < count($isiOpsi);
        })));
        if ($tipe === 'mc') {
            if (!$pilih) {
                return ['ok' => false, 'alasan' => 'kunci jawaban tidak dikenal'];
            }
            $jawaban = $pilih[0];
        } else {
            if (!$pilih) {
                return ['ok' => false, 'alasan' => 'kunci MCMA kosong'];
            }
            sort($pilih);
            $jawaban = json_encode($pilih);
        }
    } elseif ($tipe === 'kategori') {
        $kats = $isiOpsi; // A-E = nama kategori
        if (count($kats) < 2) {
            return ['ok' => false, 'alasan' => 'butuh minimal 2 kategori pada kolom A-E'];
        }
        $items = [];
        $kunci = [];
        foreach (preg_split('/[;]+/', $jawabanRaw) as $pasangan) {
            $pasangan = trim($pasangan);
            if ($pasangan === '') {
                continue;
            }
            $pisah = strrpos($pasangan, '=');
            if ($pisah === false) {
                $pisah = strrpos($pasangan, ':');
            }
            if ($pisah === false) {
                return ['ok' => false, 'alasan' => 'format JAWABAN kategori harus "Item=HurufKategori" (mis. Apple=A;Carrot=B)'];
            }
            $item = trim(substr($pasangan, 0, $pisah));
            $kat = trim(substr($pasangan, $pisah + 1));
            if ($item === '') {
                continue;
            }
            $idxKat = null;
            if (preg_match('/^[A-Ea-e]$/', $kat)) {
                $idxKat = ord(strtoupper($kat)) - 65;
            } elseif (is_numeric($kat)) {
                $idxKat = (int)$kat - 1;
            } else {
                $pos = array_search($kat, $kats, true);
                if ($pos !== false) {
                    $idxKat = (int)$pos;
                }
            }
            if ($idxKat === null || !isset($kats[$idxKat])) {
                return ['ok' => false, 'alasan' => 'kategori "' . $kat . '" tidak ada di kolom A-E'];
            }
            $kunci[(string)count($items)] = $idxKat;
            $items[] = $item;
        }
        if (count($items) < 2) {
            return ['ok' => false, 'alasan' => 'butuh minimal 2 item pada JAWABAN kategori'];
        }
        $opsi = json_encode($items);
        $opsiKategori = json_encode($kats);
        $jawaban = json_encode((object)$kunci); // peta {"item":kategori}
    } elseif ($tipe === 'matching') {
        $pairs = [];
        foreach ($isiOpsi as $p) {
            if (strpos($p, '|') === false) {
                return ['ok' => false, 'alasan' => 'pasangan matching harus berformat "Kiri|Kanan"'];
            }
            $pairs[] = $p;
        }
        if (count($pairs) < 2) {
            return ['ok' => false, 'alasan' => 'butuh minimal 2 pasangan matching'];
        }
        $opsi = json_encode($pairs);
        $jawaban = 'semua';
    } elseif ($tipe === 'tf') {
        $jawaban = normalize_answer($jawabanRaw) === 'salah' || normalize_answer($jawabanRaw) === 'false' ? 'salah' : 'benar';
    } elseif ($tipe === 'fill') {
        if ($jawabanRaw === '') {
            return ['ok' => false, 'alasan' => 'kunci fill in the blank kosong'];
        }
        $jawaban = $jawabanRaw;
    } else { // essay
        $jawaban = '';
    }

    return ['ok' => true, 'alasan' => '', 'data' => [
        'tipe' => $tipe,
        'pertanyaan' => $pertanyaan,
        'opsi' => $opsi,
        'jawaban' => (string)$jawaban,
        'pembahasan' => $pembahasan,
        'skor' => $skor,
        'penilaian' => $penilaian,
        'opsi_kategori' => $opsiKategori,
        'gambar' => $gambar,
        'opsi_gambar' => $opsiGambar,
        // Nilai kolom gambar yang diisi tapi tidak dikenali (untuk dilaporkan
        // ke pengguna oleh pemanggil import). Kosong = semua gambar terbaca.
        'peringatan' => $gambarDitolak,
    ]];
}

/** Susun satu baris untuk export Excel dari data soal. */
function soal_ke_baris(array $q)
{
    $tipe = $q['tipe'];
    $opsi = $q['opsi'] ? (json_decode((string)$q['opsi'], true) ?: []) : [];
    $kats = soal_kategori_list($q);
    $kolom = ['', '', '', '', ''];
    $imgKolom = ['', '', '', '', ''];
    $jawaban = (string)$q['jawaban'];
    $gambarOpsi = soal_gambar_opsi($q);
    if ($tipe === 'mc' || $tipe === 'mcma') {
        $kolom = array_pad(array_slice($opsi, 0, 5), 5, '');
        // Gambar pilihan sejajar dengan kolom A-E (index opsi asli).
        for ($i = 0; $i < 5; $i++) {
            $imgKolom[$i] = $gambarOpsi[(string)$i] ?? '';
        }
        if ($tipe === 'mc') {
            $jawaban = isset($opsi[(int)$q['jawaban']]) ? chr(65 + (int)$q['jawaban']) : (string)$q['jawaban'];
        } else {
            $arr = json_decode((string)$q['jawaban'], true) ?: [];
            $huruf = array_map(function ($x) {
                return chr(65 + (int)$x);
            }, $arr);
            $jawaban = implode(',', $huruf);
        }
    } elseif ($tipe === 'kategori') {
        $kolom = array_pad(array_slice($kats, 0, 5), 5, '');
        $kunci = json_decode((string)$q['jawaban'], true) ?: [];
        $pasangan = [];
        foreach ($opsi as $i => $item) {
            $k = $kunci[(string)$i] ?? ($kunci[$i] ?? null);
            if ($k === null) {
                continue;
            }
            $pasangan[] = $item . '=' . chr(65 + (int)$k);
        }
        $jawaban = implode(';', $pasangan);
    } elseif ($tipe === 'matching') {
        $kolom = array_pad(array_slice($opsi, 0, 5), 5, '');
        $jawaban = '';
    } elseif ($tipe === 'essay') {
        $jawaban = '';
    }
    return array_merge(
        [$tipe, $q['pertanyaan'], $kolom[0], $kolom[1], $kolom[2], $kolom[3], $kolom[4], $jawaban, (string)$q['pembahasan'], (string)$q['skor'], (string)($q['penilaian'] ?? 'penuh')],
        [soal_gambar($q)],
        $imgKolom
    );
}

/**
 * Susun pesan + tipe flash hasil import bank soal. Dipakai bersama oleh
 * guru-soal.php dan admin-soal.php supaya kalimatnya identik.
 * $gambarDitolak = daftar "baris N: GAMBAR = \"...\"" (lihat soal_from_row()).
 * Return: ['pesan' => string, 'tipe' => 'success'|'warning'].
 */
function soal_import_pesan($n, $tujuan, array $lewati, array $gambarDitolak)
{
    $pesan = $n . ' soal berhasil diimport ke ' . $tujuan . '.';
    if ($lewati) {
        $pesan .= ' Dilewati: ' . implode(', ', array_slice($lewati, 0, 6)) . (count($lewati) > 6 ? '...' : '');
    }
    if ($gambarDitolak) {
        $pesan .= ' ⚠ KOLOM GAMBAR TIDAK TERBACA pada '
            . implode('; ', array_slice($gambarDitolak, 0, 3)) . (count($gambarDitolak) > 3 ? '...' : '')
            . ' — isi kolom GAMBAR / GAMBAR_A…E dengan URL yang diawali http:// atau https://.'
            . ' Berkas gambar di komputer kamu TIDAK bisa dibaca langsung dari Excel:'
            . ' unggah gambarnya lewat tombol Upload di form soal, lalu pakai URL hasil unggahan itu.';
    }
    return ['pesan' => $pesan, 'tipe' => ($lewati || $gambarDitolak) ? 'warning' : 'success'];
}

function status_badge($status)
{
    $map = [
        'aktif' => 'badge-success', 'selesai' => 'badge-info', 'menunggu_koreksi' => 'badge-warning',
        'dinilai' => 'badge-success', 'in_progress' => 'badge-info', 'alpa' => 'badge-danger',
        'hadir' => 'badge-success', 'izin' => 'badge-warning', 'sakit' => 'badge-info',
        'nonaktif' => 'badge-danger', 'menunggu' => 'badge-warning', 'draft' => 'badge-muted',
        'terkirim' => 'badge-success', 'gagal' => 'badge-danger', 'dilewati' => 'badge-muted',
    ];
    $cls = $map[$status] ?? 'badge-muted';
    return '<span class="badge ' . $cls . '">' . e(str_replace('_', ' ', (string)$status)) . '</span>';
}

/* ------------------------------------------------------------------ icons */

/**
 * Katalog ikon SVG — SATU tempat. Dipakai `icon()` dan validasi nama ikon
 * (mis. ikon pilihan pada infografis materi, lihat includes/infografis.php).
 */
function icon_paths()
{
    static $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'book' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H19v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H19v3H6.5A2.5 2.5 0 0 1 4 20.5z"/>',
        'video' => '<rect x="2.5" y="5" width="14" height="14" rx="2.5"/><path d="M16.5 10.5 22 7.5v9l-5.5-3z"/>',
        'pdf' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
        'audio' => '<path d="M11 5 6.5 9H3v6h3.5L11 19z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 6a9 9 0 0 1 0 12"/>',
        'quiz' => '<path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7v.5"/><circle cx="12" cy="17.5" r="1"/><circle cx="12" cy="12" r="9.2"/>',
        'trophy' => '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 5H5.5A2.5 2.5 0 0 0 8 9.5"/><path d="M16 5h2.5A2.5 2.5 0 0 1 16 9.5"/><path d="M12 13v4M8.5 20h7"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.8 20a6.2 6.2 0 0 1 12.4 0"/><path d="M16 5.5a3 3 0 0 1 0 5.4"/><path d="M17.5 20h3.7a5.6 5.6 0 0 0-3-4.9"/>',
        'user' => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2 2 2 0 1 1-4 0 1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 4.6 15a2 2 0 1 1 0-4 1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 11.5 4a2 2 0 1 1 4 0 1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.7 1.7 0 0 0 19.4 11a2 2 0 1 1 0 4z"/>',
        'bell' => '<path d="M18 8a6 6 0 1 0-12 0c0 6-2 7-2 7h16s-2-1-2-7"/><path d="M10.3 20a2 2 0 0 0 3.4 0"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M8 3v4M16 3v4M3 10h18"/>',
        'clipboard' => '<path d="M9 4h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M9 4a2 2 0 0 0-2 2h10a2 2 0 0 0-2-2z" opacity="0"/><rect x="9" y="3" width="6" height="3.5" rx="1"/><path d="M9.5 11h5M9.5 15h4"/>',
        'chat' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 21l1.9-5.2A8 8 0 1 1 21 12z"/>',
        'star' => '<path d="m12 3.6 2.6 5.3 5.8.8-4.2 4.1 1 5.8-5.2-2.8-5.2 2.8 1-5.8L3.6 9.7l5.8-.8z"/>',
        'download' => '<path d="M12 3v12"/><path d="m7.5 11 4.5 4.5L16.5 11"/><path d="M4 20h16"/>',
        'upload' => '<path d="M12 17V5"/><path d="m7.5 9.5 4.5-4.5 4.5 4.5"/><path d="M4 20h16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>',
        'zoom-in' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/><path d="M11 8.2v5.6M8.2 11h5.6"/>',
        'zoom-out' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/><path d="M8.2 11h5.6"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/>',
        'moon' => '<path d="M21 13A9 9 0 1 1 11 3a7 7 0 0 0 10 10z"/>',
        'edit' => '<path d="M4 20h4L20 8l-4-4L4 16z"/><path d="m14 6 4 4"/>',
        'trash' => '<path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13"/><path d="M10 11v6M14 11v6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'logout' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5"/><path d="M5 12h10"/>',
        'check' => '<path d="m5 13 4.5 4.5L19 7"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'play' => '<circle cx="12" cy="12" r="9"/><path d="m10 8.5 6 3.5-6 3.5z"/>',
        'lock' => '<rect x="4.5" y="10" width="15" height="11" rx="2.5"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10"/>',
        'award' => '<circle cx="12" cy="9" r="5.5"/><path d="m8.5 13.8L7 21l5-2.5 5 2.5-1.5-7.2"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        'folder' => '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.2l2 2.5h7.8A2.5 2.5 0 0 1 21 10v7.5A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5z"/>',
        'filter' => '<path d="M3 5h18l-7 8v6l-4-2v-4z"/>',
        'arrow-left' => '<path d="M19 12H5"/><path d="m11 6-6 6 6 6"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'skip' => '<path d="m5 6.5 5 5.5-5 5.5"/><path d="m12 6.5 5 5.5-5 5.5"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'home' => '<path d="m4 10.5 8-6.5 8 6.5V19a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 19z"/><path d="M10 20.5v-6h4v6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.3l3.5 2"/>',
        'shield' => '<path d="M12 3 5 6v6c0 4.5 3 7.6 7 9 4-1.4 7-4.5 7-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'database' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.8"/><path d="m4 18 5-5 3.5 3.5L16 13l4 4"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'briefcase' => '<rect x="3" y="7.5" width="18" height="12.5" rx="2.5"/><path d="M9 7.5V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5"/><path d="M3 12.5h18"/>',
        'smile' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0"/><circle cx="9" cy="10" r=".8"/><circle cx="15" cy="10" r=".8"/>',
        'mic' => '<rect x="9" y="3" width="6" height="10" rx="3"/><path d="M6 11a6 6 0 0 0 12 0"/><path d="M12 17v4M9 21h6"/>',
        'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'bookmark' => '<path d="M6 3h12v18l-6-4.5L6 21z"/>',
        'link' => '<path d="M9.5 14.5 14.5 9.5"/><path d="M11 6.5 13 4.5a4.2 4.2 0 0 1 6 6l-2 2"/><path d="M13 17.5l-2 2a4.2 4.2 0 0 1-6-6l2-2"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-13.7-5.3L4 8"/><path d="M4 4v4h4"/><path d="M4 13a8 8 0 0 0 13.7 5.3L20 16"/><path d="M20 20v-4h-4"/>',
        'shuffle' => '<path d="M16 3h5v5"/><path d="M4 20 21 3"/><path d="M21 16v5h-5"/><path d="m15 15 6 6"/><path d="m4 4 5 5"/>',
        'grid' => '<rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/>',
        'wallet' => '<path d="M3 8.5A2.5 2.5 0 0 1 5.5 6h13A2.5 2.5 0 0 1 21 8.5v8A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5z"/><path d="M3 10h18"/><circle cx="16.5" cy="14.5" r="1.2"/>',
        'receipt' => '<path d="M5 3h14v18l-2.3-1.5-2.4 1.5-2.3-1.5L9.6 21 7.3 19.5 5 21z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'bank' => '<path d="M3 10 12 4l9 6"/><path d="M5 10v9M9.5 10v9M14.5 10v9M19 10v9"/><path d="M3 20h18"/>',
        // dipakai kartu "Tanya AI" (materi.php)
        'sparkle' => '<path d="M11 3.5 12.7 8l4.5 1.7-4.5 1.7L11 15.9 9.3 11.4 4.8 9.7 9.3 8z"/><path d="M17.8 14.4l.8 2.1 2.1.8-2.1.8-.8 2.1-.8-2.1-2.1-.8 2.1-.8z"/>',
        'send' => '<path d="M4.5 11.6 20 4.2l-7.4 15.6-1.9-6.1z"/><path d="m10.7 13.7 9.3-9.5"/>',
        // dipakai fitur suara kartu "Tanya AI"
        'volume' => '<path d="M4 9.5h3L11 6v12l-4-3.5H4z"/><path d="M15 9.2a4 4 0 0 1 0 5.6"/><path d="M17.9 6.6a8 8 0 0 1 0 10.8"/>',
        'volume-off' => '<path d="M4 9.5h3L11 6v12l-4-3.5H4z"/><path d="m15.8 9.6 4.4 4.8"/><path d="m20.2 9.6-4.4 4.8"/>',
        'phone' => '<path d="M6.6 3.6h2.6l1.4 3.6-1.9 1.4a11.5 11.5 0 0 0 5.7 5.7l1.4-1.9 3.6 1.4v2.6a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 4.6 5.8a2 2 0 0 1 2-2.2z"/>',
        // dipakai pita status jaringan (offline) & halaman "Materi Offline"
        'cloud-off' => '<path d="M17.5 17.5H7a4.5 4.5 0 0 1-.6-9A5.5 5.5 0 0 1 16.8 10a4 4 0 0 1 .7 7.5z"/><path d="M3.5 3.5l17 17"/>',
        'cloud' => '<path d="M17.5 17.5H7a4.5 4.5 0 0 1-.6-9A5.5 5.5 0 0 1 16.8 10a4 4 0 0 1 .7 7.5z"/>',
        // dipakai infografis materi (kotak sorotan/tips)
        'lightbulb' => '<path d="M9.5 17.5h5"/><path d="M10.5 21h3"/><path d="M12 3a5.5 5.5 0 0 0-3.2 9.9c.5.4.7.9.7 1.5v.6h5v-.6c0-.6.2-1.1.7-1.5A5.5 5.5 0 0 0 12 3z"/>',
        'target' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
    ];
    return $paths;
}

function icon($name, $size = 20, $class = '')
{
    $paths = icon_paths();
    $p = $paths[$name] ?? $paths['grid'];
    return '<svg class="ic ' . $class . '" width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

function logo_svg($size = 36)
{
    return '<svg width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 64 64" fill="none" aria-hidden="true">
      <defs><linearGradient id="lmg" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="#4F46E5"/><stop offset="1" stop-color="#8B5CF6"/></linearGradient></defs>
      <circle cx="32" cy="32" r="30" fill="url(#lmg)"/>
      <circle cx="32" cy="32" r="25.5" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="1.2"/>
      <path d="M18 34.5c4-2.2 9-2.2 14 0 5-2.2 10-2.2 14 0V24c-4-2.2-9-2.2-14 0-5-2.2-10-2.2-14 0z" fill="#fff"/>
      <path d="M32 24v10.5" stroke="#4F46E5" stroke-width="1.2"/>
      <path d="M14 40.5c6 3.5 12 3.5 18 0 6 3.5 12 3.5 18 0" stroke="#fff" stroke-opacity=".85" stroke-width="1.6" stroke-linecap="round"/>
      <path d="M44 16.5c-3 3.5-4.5 7-4.5 10.5 0 1.7 1.3 3 3 3s3-1.3 3-3c0-3.5-.5-7-1.5-10.5z" fill="#fff" fill-opacity=".9"/>
    </svg>';
}

function site_logo($size = 36)
{
    $custom = setting('site_logo');
    if ($custom !== '') {
        return '<img src="' . e($custom) . '" alt="Logo" width="' . (int)$size . '" height="' . (int)$size . '" class="brand-img">';
    }
    return logo_svg($size);
}
