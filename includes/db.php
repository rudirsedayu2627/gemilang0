<?php
// Database bootstrap: schema + seed data, version-gated so the whole
// migration+seed block runs in ONE transaction only when needed.

define('LMS_SCHEMA_VERSION', 13);
define('LMS_APP_DIR', dirname(__DIR__));

function lms_db_path()
{
    $p = getenv('LMS_DB_PATH');
    if ($p !== false && $p !== '') {
        return $p;
    }
    $dir = LMS_APP_DIR . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    // Nama berkas database dibuat acak (dari secret sekali pakai) supaya berkas
    // SQLite tidak bisa ditebak/diunduh langsung dari URL publik.
    //
    // PENTING: nama berkas yang sudah dipakai CONGKOK di data/.dbname. Tanpa ini,
    // hilangnya/berubahnya .dbkey akan mengubah nama berkas -> aplikasi membuat
    // database kosong baru dan data pengguna seolah hilang.
    $pointerFile = $dir . '/.dbname';
    $pointer = is_file($pointerFile) ? trim((string)@file_get_contents($pointerFile)) : '';
    if ($pointer !== '' && preg_match('/^[\w\-.]+\.sqlite$/', $pointer) && is_file($dir . '/' . $pointer)) {
        return $dir . '/' . $pointer;
    }

    $secretFile = $dir . '/.dbkey';
    if (!is_file($secretFile)) {
        @file_put_contents($secretFile, bin2hex(random_bytes(16)));
    }
    $secret = is_file($secretFile) ? trim((string)@file_get_contents($secretFile)) : '';
    if ($secret === '') {
        $secret = 'lms-default-key';
    }
    $hashed = $dir . '/lms-' . substr(hash('sha256', $secret), 0, 16) . '.sqlite';

    if (!is_file($hashed)) {
        // Adopsi database yang sudah ada di folder data (mis. dibuat dengan kunci
        // lama) daripada membuat berkas kosong baru. Bila ada beberapa kandidat,
        // pilih yang paling besar = paling banyak datanya.
        $kandidat = glob($dir . '/lms-*.sqlite') ?: [];
        $kandidat = array_values(array_filter($kandidat, function ($f) {
            return is_file($f);
        }));
        if ($kandidat) {
            usort($kandidat, function ($a, $b) {
                return filesize($b) <=> filesize($a);
            });
            @file_put_contents($pointerFile, basename($kandidat[0]));
            return $kandidat[0];
        }
    }

    if ($pointer !== basename($hashed) && !is_file($pointerFile)) {
        @file_put_contents($pointerFile, basename($hashed));
    }
    return $hashed;
}

/** Berkas penjaga agar isi folder runtime tidak bisa diakses/di-list dari web. */
function lms_guard_runtime_dirs()
{
    $targets = [
        LMS_APP_DIR . '/data' => true,
        LMS_APP_DIR . '/backups' => true,
    ];
    foreach ($targets as $dir => $_) {
        if (is_dir($dir)) {
            if (!is_file($dir . '/index.php')) {
                @file_put_contents($dir . '/index.php', "<?php http_response_code(403); exit('Akses ditolak.');\n");
            }
            if (!is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            }
        }
    }
    $up = LMS_APP_DIR . '/uploads'; // folder fallback bawaan (hindari pemanggilan setting() di sini)
    if (is_dir($up) && !is_file($up . '/.htaccess')) {
        @file_put_contents($up . '/.htaccess', "# Berkas media boleh diakses, tapi tidak boleh dieksekusi sebagai PHP\n<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n");
    }
}

/** Apakah proses ini berjalan di CLI TANPA LMS_DB_PATH? (mode periksa-saja) */
function lms_cli_baca_saja()
{
    $env = getenv('LMS_DB_PATH');
    return (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') && ($env === false || $env === '');
}

function db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    // Pengaman penting: dari CLI tanpa LMS_DB_PATH, database aplikasi dibuka
    // READ-ONLY dan tanpa migrasi/seed. Ini mencegah skrip perintah atau
    // pemeriksaan manual tanpa sengaja mengubah/menyemai ulang DATA PENGGGUNA
    // SUNGGUHAN (pernah terjadi: folder data dibersihkan sehingga berkas kunci
    // hilang dan aplikasi membuat database kosong). Skrip uji selalu menyetel
    // LMS_DB_PATH ke berkas sementara, sehingga tetap berjalan normal.
    $bacaSaja = lms_cli_baca_saja();
    $dsn = $bacaSaja ? ('sqlite:file:' . lms_db_path() . '?mode=ro') : ('sqlite:' . lms_db_path());
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    foreach ([
        'PRAGMA busy_timeout = 5000',
        'PRAGMA foreign_keys = ON',
    ] as $pragma) {
        try {
            $pdo->exec($pragma);
        } catch (Throwable $e) {
        }
    }
    if (!$bacaSaja) {
        try {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        } catch (Throwable $e) {
            // mode berkas tidak mendukung WAL (mis. read-only) -> lewati
        }
    }
    if ($bacaSaja) {
        return $pdo;
    }
    lms_bootstrap($pdo);
    lms_guard_runtime_dirs();
    return $pdo;
}

function lms_bootstrap(PDO $pdo)
{
    if (lms_cli_baca_saja()) {
        return;
    }
    try {
        $row = $pdo->query("SELECT value FROM settings WHERE key = 'schema_version'")->fetch();
        if ($row && (int)$row['value'] === LMS_SCHEMA_VERSION) {
            return;
        }
    } catch (Throwable $e) {
        // settings table does not exist yet -> first run
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach (lms_schema_sql() as $sql) {
            $pdo->exec($sql);
        }
        $migrasiOk = lms_migrate($pdo);
        lms_seed($pdo);
        if ($migrasiOk) {
            lms_bank_sync($pdo);
            $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES ('schema_version', ?)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value");
            $stmt->execute([(string)LMS_SCHEMA_VERSION]);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function lms_schema_sql()
{
    return [
        "CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )",
        "CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT UNIQUE,
            label TEXT,
            permissions TEXT
        )",
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT NOT NULL,
            username TEXT UNIQUE,
            email TEXT UNIQUE,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'siswa',
            foto TEXT,
            telepon TEXT,
            alamat TEXT,
            bio TEXT,
            status TEXT NOT NULL DEFAULT 'aktif',
            last_login TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT NOT NULL,
            slug TEXT UNIQUE,
            deskripsi TEXT,
            ikon TEXT,
            warna TEXT,
            urutan INTEGER DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS courses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            judul TEXT NOT NULL,
            slug TEXT UNIQUE,
            deskripsi TEXT,
            kategori_id INTEGER,
            guru_id INTEGER,
            level TEXT DEFAULT 'Pemula',
            harga REAL DEFAULT 0,
            durasi_jam INTEGER DEFAULT 0,
            bahasa TEXT DEFAULT 'Indonesia',
            thumbnail TEXT,
            status TEXT DEFAULT 'aktif',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS modules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER NOT NULL,
            judul TEXT NOT NULL,
            deskripsi TEXT,
            urutan INTEGER DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS lessons (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            module_id INTEGER,
            course_id INTEGER NOT NULL,
            judul TEXT NOT NULL,
            tipe TEXT DEFAULT 'teks',
            media_url TEXT,
            media_embed TEXT,
            isi_teks TEXT,
            infografis TEXT,
            durasi_menit INTEGER DEFAULT 0,
            urutan INTEGER DEFAULT 0,
            is_preview INTEGER DEFAULT 0,
            bisa_download INTEGER DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            course_id INTEGER NOT NULL,
            progress REAL DEFAULT 0,
            status TEXT DEFAULT 'aktif',
            enrolled_at TEXT DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT,
            UNIQUE(user_id, course_id)
        )",
        "CREATE TABLE IF NOT EXISTS lesson_progress (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            lesson_id INTEGER NOT NULL,
            course_id INTEGER NOT NULL,
            selesai INTEGER DEFAULT 0,
            menit_terakhir REAL DEFAULT 0,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, lesson_id)
        )",
        "CREATE TABLE IF NOT EXISTS quizzes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER,
            module_id INTEGER,
            lesson_id INTEGER,
            judul TEXT NOT NULL,
            deskripsi TEXT,
            tipe TEXT DEFAULT 'quiz',
            durasi_menit INTEGER DEFAULT 15,
            passing_grade INTEGER DEFAULT 70,
            random_soal INTEGER DEFAULT 0,
            random_opsi INTEGER DEFAULT 0,
            jumlah_soal_tampil INTEGER DEFAULT 0,
            tampilkan_pembahasan INTEGER DEFAULT 1,
            max_attempt INTEGER DEFAULT 0,
            status TEXT DEFAULT 'aktif',
            guru_id INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        // Bank soal: course_id = kursus pemilik soal (bank soal kursus),
        // quiz_id = SATU-SATUNYA quiz pemilik soal (NULL = belum ditugaskan ke
        // quiz mana pun). Sejak skema 10 satu soal tidak boleh dipakai dua quiz.
        "CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quiz_id INTEGER,
            course_id INTEGER,
            tipe TEXT DEFAULT 'mc',
            pertanyaan TEXT NOT NULL,
            opsi TEXT,
            jawaban TEXT,
            pembahasan TEXT,
            skor REAL DEFAULT 1,
            kesulitan TEXT DEFAULT 'sedang',
            penilaian TEXT DEFAULT 'penuh',
            opsi_kategori TEXT,
            gambar TEXT,
            opsi_gambar TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        // Tabel warisan (skema <= 9): dulu mencatat soal mana dipakai quiz mana
        // (banyak-ke-banyak). Sejak skema 10 kepemilikan soal ada di
        // questions.quiz_id, jadi tabel ini dikosongkan saat migrasi dan TIDAK
        // dipakai lagi sebagai sumber kebenaran. Tabelnya disisakan supaya skrip
        // pembersihan data lama (data/backup) tetap bisa berjalan.
        "CREATE TABLE IF NOT EXISTS quiz_questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quiz_id INTEGER NOT NULL,
            question_id INTEGER NOT NULL,
            urutan INTEGER DEFAULT 0,
            UNIQUE(quiz_id, question_id)
        )",
        "CREATE TABLE IF NOT EXISTS quiz_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quiz_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            mulai TEXT,
            batas_waktu TEXT,
            selesai TEXT,
            nilai REAL,
            benar INTEGER DEFAULT 0,
            salah INTEGER DEFAULT 0,
            kosong INTEGER DEFAULT 0,
            status TEXT DEFAULT 'in_progress',
            soal_order TEXT,
            opsi_order TEXT,
            waktu_habis INTEGER DEFAULT 0,
            dinilai_oleh INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS attempt_answers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attempt_id INTEGER NOT NULL,
            question_id INTEGER NOT NULL,
            jawaban TEXT,
            benar INTEGER,
            skor REAL DEFAULT 0,
            feedback TEXT,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(attempt_id, question_id)
        )",
        "CREATE TABLE IF NOT EXISTS certificates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nomor TEXT UNIQUE,
            user_id INTEGER NOT NULL,
            course_id INTEGER NOT NULL,
            nilai_akhir REAL DEFAULT 0,
            tanggal TEXT,
            status TEXT DEFAULT 'aktif',
            catatan TEXT
        )",
        "CREATE TABLE IF NOT EXISTS forum_topics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER,
            user_id INTEGER,
            judul TEXT NOT NULL,
            isi TEXT,
            pinned INTEGER DEFAULT 0,
            closed INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS forum_replies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            topic_id INTEGER NOT NULL,
            user_id INTEGER,
            isi TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS lesson_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lesson_id INTEGER NOT NULL,
            user_id INTEGER,
            isi TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        // Riwayat obrolan "Tanya AI" di halaman materi (satu utas per
        // pengguna per materi). peran = 'user' (pertanyaan pengguna) atau
        // 'ai' (jawaban model). Lihat includes/ai.php.
        "CREATE TABLE IF NOT EXISTS ai_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            lesson_id INTEGER NOT NULL,
            course_id INTEGER,
            peran TEXT NOT NULL DEFAULT 'user',
            isi TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            judul TEXT,
            pesan TEXT,
            url TEXT,
            dibaca INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER,
            guru_id INTEGER,
            judul TEXT NOT NULL,
            deskripsi TEXT,
            deadline TEXT,
            max_skor REAL DEFAULT 100,
            lampiran TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS assignment_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            assignment_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            isi TEXT,
            file_url TEXT,
            submitted_at TEXT DEFAULT CURRENT_TIMESTAMP,
            skor REAL,
            feedback TEXT,
            dinilai_at TEXT,
            dinilai_oleh INTEGER,
            UNIQUE(assignment_id, user_id)
        )",
        "CREATE TABLE IF NOT EXISTS attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER,
            class_id INTEGER,
            user_id INTEGER NOT NULL,
            tanggal TEXT NOT NULL,
            status TEXT DEFAULT 'hadir',
            catatan TEXT,
            dicatat_oleh INTEGER,
            UNIQUE(user_id, course_id, tanggal)
        )",
        "CREATE TABLE IF NOT EXISTS classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER,
            guru_id INTEGER,
            nama TEXT NOT NULL,
            kode TEXT UNIQUE,
            jadwal TEXT,
            kapasitas INTEGER DEFAULT 30,
            status TEXT DEFAULT 'aktif',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS class_students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            UNIQUE(class_id, user_id)
        )",
        "CREATE TABLE IF NOT EXISTS announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER,
            guru_id INTEGER,
            judul TEXT NOT NULL,
            isi TEXT,
            audience TEXT DEFAULT 'semua',
            pinned INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS faqs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pertanyaan TEXT NOT NULL,
            jawaban TEXT,
            kategori TEXT,
            urutan INTEGER DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS banners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            judul TEXT,
            subjudul TEXT,
            gambar TEXT,
            link TEXT,
            urutan INTEGER DEFAULT 0,
            aktif INTEGER DEFAULT 1
        )",
        "CREATE TABLE IF NOT EXISTS schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            course_id INTEGER,
            judul TEXT,
            tipe TEXT DEFAULT 'kelas',
            tanggal TEXT,
            jam_mulai TEXT,
            jam_selesai TEXT,
            catatan TEXT,
            color TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS bookmarks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            lesson_id INTEGER NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, lesson_id)
        )",
        "CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            course_id INTEGER,
            jumlah REAL DEFAULT 0,
            metode TEXT,
            bukti TEXT,
            status TEXT DEFAULT 'menunggu',
            verified_by INTEGER,
            catatan TEXT,
            metode_bayar TEXT,
            nomor_referensi TEXT,
            verified_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            aksi TEXT,
            detail TEXT,
            ip TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS email_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            penerima TEXT,
            subjek TEXT,
            isi TEXT,
            status TEXT,
            error TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS backups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT,
            ukuran INTEGER,
            dibuat_oleh INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE INDEX IF NOT EXISTS idx_lessons_course ON lessons(course_id)",
        "CREATE INDEX IF NOT EXISTS idx_modules_course ON modules(course_id)",
        "CREATE INDEX IF NOT EXISTS idx_questions_quiz ON questions(quiz_id)",
        "CREATE INDEX IF NOT EXISTS idx_questions_course ON questions(course_id)",
        "CREATE INDEX IF NOT EXISTS idx_quizq_quiz ON quiz_questions(quiz_id)",
        "CREATE INDEX IF NOT EXISTS idx_quizq_question ON quiz_questions(question_id)",
        "CREATE INDEX IF NOT EXISTS idx_attempts_user ON quiz_attempts(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_answers_attempt ON attempt_answers(attempt_id)",
        "CREATE INDEX IF NOT EXISTS idx_enroll_user ON enrollments(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_notif_user ON notifications(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at)",
    ];
}

/**
 * Migrasi untuk database yang sudah berjalan. Skema memakai CREATE TABLE IF NOT
 * EXISTS, jadi kolom baru harus ditambahkan manual di sini (idempoten, dijalankan
 * sekali per kenaikan LMS_SCHEMA_VERSION).
 */
function lms_migrate(PDO $pdo)
{
    // ------------------------------------------------------------------
    // DAFTAR PERUBAHAN SKEMA. Tambahkan entri di sini setiap kali ada kolom
    // baru, lalu naikkan LMS_SCHEMA_VERSION. Hasilnya SELALU diverifikasi di
    // bawah: kalau ada yang gagal, versi skema TIDAK dinaikkan dan migrasi
    // dicoba lagi pada request berikutnya.
    // ------------------------------------------------------------------
    $kolom = [
        ['questions', 'penilaian', "TEXT DEFAULT 'penuh'"],
        ['questions', 'opsi_kategori', 'TEXT'],
        // Gambar soal: `gambar` = URL gambar pertanyaan, `opsi_gambar` = JSON peta
        // {"<index opsi>": "<url>"} (hanya untuk tipe mc/mcma).
        ['questions', 'gambar', 'TEXT'],
        ['questions', 'opsi_gambar', 'TEXT'],
        // Modul pembayaran manual (verifikasi transfer)
        ['payments', 'metode_bayar', 'TEXT'],
        ['payments', 'nomor_referensi', 'TEXT'],
        ['payments', 'verified_at', 'TEXT'],
        // Quiz ditampilkan DI DALAM halaman materi (di bawah isi materi):
        // quizzes.lesson_id = materi induknya (NULL = tidak ditautkan; quiz
        // seperti itu hanya tampil di daftar quiz kursus).
        ['quizzes', 'lesson_id', 'INTEGER'],
        // Infografis materi (tipe materi 'infografis'): isi ringkas berformat baris
        // yang dirender jadi infografis. Lihat includes/infografis.php.
        ['lessons', 'infografis', 'TEXT'],
    ];
    $index = [
        ['idx_quizq_quiz', 'quiz_questions', 'quiz_id'],
        ['idx_quizq_question', 'quiz_questions', 'question_id'],
        ['idx_payments_user', 'payments', 'user_id'],
        ['idx_payments_status', 'payments', 'status'],
        ['idx_enroll_status', 'enrollments', 'status'],
        ['idx_ai_user_lesson', 'ai_messages', 'user_id, lesson_id'],
    ];
    try {
        foreach ($kolom as $k) {
            lms_add_column($pdo, $k[0], $k[1], $k[2]);
        }
        foreach ($index as $i) {
            lms_add_index($pdo, $i[0], $i[1], $i[2]);
        }
    } catch (Throwable $e) {
        // Database tidak bisa ditulis saat ini; biarkan versi skema belum naik
        // supaya migrasi dicoba lagi nanti, dan situs tetap bisa dibuka.
        return false;
    }

    // Verifikasi: jangan pernah menaikkan versi skema bila kolom belum benar-benar
    // ada (pernah terjadi: ALTER gagal senyap sementara versi sudah naik, sehingga
    // aplikasi mengira skema sudah siap dan query-nya gagal di runtime).
    foreach ($kolom as $k) {
        if (!lms_has_column($pdo, $k[0], $k[1])) {
            return false;
        }
    }

    // Isi pengaturan pembayaran bila belum ada (rekening & instruksi awal).
    try {
        $ada = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE key = 'bayar_rekening'")->fetchColumn();
        if ($ada === 0) {
            $ins = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO NOTHING");
            $ins->execute(['bayar_aktif', '1']);
            $ins->execute(['bayar_rekening', json_encode([['bank' => 'Bank Mandiri', 'nomor' => '106-00-1234567-8', 'nama' => 'Gemilang Language Corner']])]);
            $ins->execute(['bayar_instruksi', "Transfer sesuai nominal tagihan ke salah satu rekening di atas, lalu unggah bukti transfer pada halaman Pembayaran.\nPembayaran diverifikasi admin maksimal 1x24 jam pada hari kerja."]);
            $ins->execute(['bayar_kontak', '']);
        }
    } catch (Throwable $e) {
        // pengaturan bersifat opsional
    }

    // Isi pengaturan "Tanya AI" bila belum ada. Bawaannya NONAKTIF dan tanpa
    // kunci API — pemilik menyalakannya sendiri di Pengaturan → AI, jadi tidak
    // ada satu pun halaman yang berubah setelah migrasi ini.
    try {
        $ada = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE key = 'ai_aktif'")->fetchColumn();
        if ($ada === 0) {
            $ins = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO NOTHING");
            foreach (lms_default_ai_settings() as $k => $v) {
                $ins->execute([$k, $v]);
            }
        }
    } catch (Throwable $e) {
        // pengaturan bersifat opsional
    }

    // Tandai pendaftaran lama yang masih aktif agar tidak terkunci setelah
    // modul pembayaran dinyalakan (siswa yang sudah belajar tetap bisa lanjut).
    try {
        $pdo->exec("UPDATE enrollments SET status = 'aktif' WHERE status NOT IN ('aktif','selesai','menunggu_bayar')");
    } catch (Throwable $e) {
    }

    // Contoh soal dua tipe baru untuk database yang sudah berisi data lama.
    // (Data pengguna asli tidak diubah selain penambahan kolom di atas.)
    try {
        $quizId = $pdo->query("SELECT id FROM quizzes WHERE judul = 'Quiz Tenses Dasar' LIMIT 1")->fetchColumn();
    } catch (Throwable $e) {
        return true;
    }
    if ($quizId) {
        $courseId = $pdo->query('SELECT course_id FROM quizzes WHERE id = ' . (int)$quizId)->fetchColumn();
        $contoh = [
            ['mcma', 'Pilih SEMUA kalimat yang memakai simple present tense (jawaban bisa lebih dari satu).',
                ['She teaches English every day.', 'They are playing football now.', 'I usually drink coffee in the morning.', 'He went to Jakarta yesterday.', 'We do not like durian.'],
                [0, 2, 4], 'Kalimat kebiasaan/fakta memakai simple present. "Are playing" = present continuous, "went" = past tense.', 3, 'parsial', null],
            ['kategori', 'Kelompokkan setiap kata ke kategori yang tepat.',
                ['Apple', 'Carrot', 'Mango', 'Spinach'],
                [0, 1, 0, 1], 'Apple & Mango = Fruit (buah), Carrot & Spinach = Vegetable (sayur).', 4, 'penuh', ['Fruit (Buah)', 'Vegetable (Sayur)']],
        ];
        $cek = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE quiz_id = ? AND pertanyaan = ?');
        $ins = $pdo->prepare('INSERT INTO questions (quiz_id, course_id, tipe, pertanyaan, opsi, jawaban, pembahasan, skor, penilaian, opsi_kategori) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($contoh as $c) {
            try {
                $cek->execute([$quizId, $c[1]]);
                if ((int)$cek->fetchColumn() !== 0) {
                    continue;
                }
            } catch (Throwable $e) {
                continue;
            }
            $kunci = $c[0] === 'kategori' ? json_encode((object)$c[3]) : json_encode($c[3]);
            try {
                $ins->execute([$quizId, $courseId, $c[0], $c[1], json_encode($c[2]), $kunci, $c[4], $c[5], $c[6], $c[7] === null ? null : json_encode($c[7])]);
            } catch (Throwable $e) {
                // biarkan; contoh soal bersifat opsional
            }
        }
    }
    return true;
}

/**
 * Sinkronisasi bank soal (dipanggil sekali per kenaikan versi skema).
 *
 * Sejak skema 10 satu soal hanya boleh dimiliki SATU quiz (questions.quiz_id).
 * Di sini penugasan model lama (tabel quiz_questions) dipindahkan menjadi
 * kepemilikan tunggal: soal yang sudah dipakai sebuah quiz menempel ke quiz itu
 * (kalau pernah dipakai beberapa quiz, diambil quiz dengan id terkecil), dan
 * soal yang tidak dipakai quiz mana pun tetap di bank soal kursusnya dengan
 * quiz_id NULL. TIDAK ADA soal yang dihapus.
 */
function lms_bank_sync(PDO $pdo)
{
    // 1. Penugasan lama -> kepemilikan tunggal pada soal (quiz_id terkecil).
    $pdo->exec('UPDATE questions SET quiz_id = (
            SELECT MIN(qq.quiz_id) FROM quiz_questions qq WHERE qq.question_id = questions.id)
        WHERE quiz_id IS NULL
          AND EXISTS (SELECT 1 FROM quiz_questions qq WHERE qq.question_id = questions.id)');
    // 2. Kursus soal selalu mengikuti kursus quiz pemiliknya (bank soal per kursus).
    $pdo->exec('UPDATE questions SET course_id = (
            SELECT course_id FROM quizzes WHERE quizzes.id = questions.quiz_id)
        WHERE quiz_id IS NOT NULL
          AND EXISTS (SELECT 1 FROM quizzes WHERE quizzes.id = questions.quiz_id)');
    // 3. Soal yang menempel ke quiz yang sudah tidak ada -> kembali ke bank kursus.
    $pdo->exec('UPDATE questions SET quiz_id = NULL
        WHERE quiz_id IS NOT NULL AND quiz_id NOT IN (SELECT id FROM quizzes)');
    // 4. Tabel penugasan lama sudah tidak dipakai (banyak-ke-banyak -> tunggal).
    $pdo->exec('DELETE FROM quiz_questions');
}

/** Tambah index bila belum ada. */
function lms_add_index(PDO $pdo, $nama, $table, $kolom)
{
    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $nama . ' ON ' . $table . '(' . $kolom . ')');
    } catch (Throwable $e) {
    }
}

/** Apakah kolom tertentu sudah ada di sebuah tabel. */
function lms_has_column(PDO $pdo, $table, $column)
{
    try {
        $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    } catch (Throwable $e) {
        return false;
    }
    foreach ($cols as $c) {
        if (strcasecmp((string)$c['name'], $column) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Tambah kolom bila belum ada (SQLite tidak punya IF NOT EXISTS untuk kolom).
 * Mengembalikan true bila kolom ada setelah pemanggilan (baik baru dibuat maupun
 * memang sudah ada). Kesalahan TIDAK ditelan diam-diam: nilai baliknya dipakai
 * lms_migrate() untuk memutuskan boleh-tidaknya menaikkan versi skema.
 */
function lms_add_column(PDO $pdo, $table, $column, $definition)
{
    if (lms_has_column($pdo, $table, $column)) {
        return true;
    }
    try {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    } catch (Throwable $e) {
        error_log('lms_add_column gagal: ' . $table . '.' . $column . ' -> ' . $e->getMessage());
        return false;
    }
    return lms_has_column($pdo, $table, $column);
}

/**
 * Pengaturan bawaan fitur "Tanya AI" (obrolan dengan AI di halaman materi).
 *
 * Dipakai dua tempat: seed database baru (lms_seed) dan penambahan pengaturan
 * pada database yang sudah berjalan (lms_migrate). Bawaannya NONAKTIF.
 * Kunci API TIDAK pernah ikut ditulis di sini — pemilik mengisinya sendiri di
 * Pengaturan → AI, dan nilainya hanya dibaca oleh sisi server (tidak pernah
 * dikirim ke browser).
 */
function lms_default_ai_settings()
{
    return [
        'ai_aktif'        => '0',
        'ai_nama'         => 'Tanya AI',
        'ai_intro'        => 'Tanyakan bagian yang belum kamu pahami — jawabannya bisa keliru, jadi tetap cek ke pengajar bila ragu.',
        'ai_placeholder'  => 'Tulis pertanyaan tentang materi ini...',
        'ai_provider'     => 'gemini',
        'ai_api_key'      => '',
        'ai_model'        => 'gemini-2.5-flash',
        'ai_base_url'     => '',
        'ai_batas_harian' => '40',
        // Fitur suara (obrolan suara dengan AI) — peramban/HP yang menyediakan
        // pengenal suara & pembaca teks, jadi tidak perlu layanan berbayar.
        'ai_suara_aktif'    => '1',
        'ai_suara_otomatis' => '1',
        'ai_suara_bahasa'   => 'id',
        // Berapa lama (milidetik) menunggu keheningan sebelum kalimat pengguna
        // dianggap selesai dan dikirim di mode percakapan. 3 detik = cukup sabar
        // untuk pengguna yang berpikir sejenak di tengah kalimat.
        'ai_suara_jeda'     => '3000',
        // Mode percakapan (live): mendengar terus-menerus, kirim otomatis,
        // jawaban dibacakan, ulang lagi.
        'ai_suara_live'     => '1',
        'ai_prompt'       => "Kamu adalah tutor bahasa Inggris yang ramah di {site}. Bantu siswa memahami MATERI yang sedang mereka buka.\n"
            . "Jawab singkat dan jelas dalam bahasa Indonesia (istilah bahasa Inggris tetap boleh dipakai). Beri contoh kalimat sederhana bila membantu.\n"
            . "Bila pertanyaan di luar materi atau di luar bahasa Inggris, arahkan kembali dengan sopan.\n"
            . "JANGAN mengerjakan ujian/quiz untuk siswa: jelaskan konsepnya dan bimbing langkah demi langkah, jangan memberi kunci jawaban.",
    ];
}

function lms_seed(PDO $pdo)
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $settings = array_merge([
        'site_name'        => 'Marelan English Course',
        'site_tagline'     => 'Belajar Bahasa Inggris Lebih Percaya Diri',
        'site_deskripsi'   => 'LMS Marelan English Course — kursus bahasa Inggris online dengan modul terstruktur, quiz interaktif, dan sertifikat resmi.',
        'site_email'       => 'info@marelanenglishcourse.id',
        'site_telepon'     => '0812-6000-2026',
        'site_alamat'      => 'Jl. Marelan Raya No. 88, Medan Marelan, Sumatera Utara',
        'site_logo'        => '',           // empty = pakai logo SVG bawaan (seal)
        'warna_utama'      => '#4F46E5',
        'warna_kedua'      => '#8B5CF6',
        'smtp_host'        => '',
        'smtp_port'        => '587',
        'smtp_user'        => '',
        'smtp_pass'        => '',
        'smtp_from'        => 'no-reply@marelanenglishcourse.id',
        'smtp_from_nama'   => 'Marelan English Course',
        'smtp_enabled'     => '0',
        'storage_driver'   => 'media-proxy',
        'storage_folder'   => 'uploads',
        'cert_prefix'      => 'GLC',
        'cert_penandatangan' => 'Direktur Marelan English Course',
        'cert_catatan'     => 'Sertifikat ini menyatakan bahwa peserta telah menyelesaikan seluruh materi dan evaluasi kursus.',
        'sertifikat_aktif' => '0',
        'registrasi_terbuka' => '1',
        'mode_pemeliharaan' => '0',
        'bayar_aktif'      => '1',
        'bayar_rekening'   => json_encode([['bank' => 'Bank Mandiri', 'nomor' => '106-00-1234567-8', 'nama' => 'Gemilang Language Corner']]),
        'bayar_instruksi'  => "Transfer sesuai nominal tagihan ke salah satu rekening di atas, lalu unggah bukti transfer pada halaman Pembayaran.\nPembayaran diverifikasi admin maksimal 1x24 jam pada hari kerja.",
        'bayar_kontak'     => '',
    ], lms_default_ai_settings());
    $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO NOTHING");
    foreach ($settings as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    $roles = [
        ['admin', 'Administrator', json_encode(['*'])],
        ['guru', 'Guru / Pengajar', json_encode(['kursus', 'kelas', 'materi', 'quiz', 'soal', 'nilai', 'absensi', 'tugas', 'forum', 'pengumuman'])],
        ['siswa', 'Siswa', json_encode(['belajar', 'quiz', 'forum', 'tugas', 'sertifikat'])],
    ];
    $rs = $pdo->prepare('INSERT INTO roles (nama, label, permissions) VALUES (?, ?, ?)');
    foreach ($roles as $r) {
        $rs->execute($r);
    }

    $users = [
        ['Admin Marelan', 'admin', 'admin@marelan.test', 'admin123', 'admin'],
        ['Miss Sarah Hutapea', 'sarah', 'guru@marelan.test', 'guru123', 'guru'],
        ['Mr. Robert Sinaga', 'robert', 'robert@marelan.test', 'guru123', 'guru'],
        ['Budi Santoso', 'budi', 'siswa@marelan.test', 'siswa123', 'siswa'],
        ['Siti Rahmawati', 'siti', 'siti@marelan.test', 'siswa123', 'siswa'],
        ['Andi Pratama', 'andi', 'andi@marelan.test', 'siswa123', 'siswa'],
        ['Dewi Lestari', 'dewi', 'dewi@marelan.test', 'siswa123', 'siswa'],
        ['Kevin Wijaya', 'kevin', 'kevin@marelan.test', 'siswa123', 'siswa'],
    ];
    $us = $pdo->prepare('INSERT INTO users (nama, username, email, password, role, bio) VALUES (?, ?, ?, ?, ?, ?)');
    $ids = [];
    foreach ($users as $u) {
        $us->execute([$u[0], $u[1], $u[2], password_hash($u[3], PASSWORD_DEFAULT), $u[4], 'Anggota ' . $settings['site_name'] . '.']);
        $ids[$u[1]] = (int)$pdo->lastInsertId();
    }

    $cats = [
        ['Grammar', 'grammar', 'Tata bahasa Inggris dari dasar sampai mahir', 'book', '#4F46E5'],
        ['Speaking', 'speaking', 'Latihan percakapan dan pronunciation', 'mic', '#0EA5E9'],
        ['Listening', 'listening', 'Melatih pendengaran dengan audio native', 'audio', '#8B5CF6'],
        ['TOEFL Preparation', 'toefl-preparation', 'Persiapan tes TOEFL ITP & iBT', 'trophy', '#F59E0B'],
        ['Kids English', 'kids-english', 'Kelas bahasa Inggris untuk anak', 'smile', '#EC4899'],
        ['Business English', 'business-english', 'Bahasa Inggris untuk dunia kerja', 'briefcase', '#10B981'],
    ];
    $cs = $pdo->prepare('INSERT INTO categories (nama, slug, deskripsi, ikon, warna, urutan) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($cats as $i => $c) {
        $cs->execute([$c[0], $c[1], $c[2], $c[3], $c[4], $i + 1]);
    }

    // ---- Kursus 1: Grammar Dasar ----
    $courses = [
        [
            'judul' => 'Grammar Dasar: Bikin Kalimat Bahasa Inggris Benar',
            'slug'  => 'grammar-dasar',
            'deskripsi' => 'Kuasai struktur dasar bahasa Inggris: tenses, kata kerja, kalimat tanya, dan pola kalimat yang dipakai sehari-hari. Cocok untuk pemula yang ingin berbicara tanpa ragu.',
            'kategori_id' => 1, 'guru_id' => $ids['sarah'], 'level' => 'Pemula', 'harga' => 350000, 'durasi_jam' => 12,
            'modules' => [
                ['Pengenalan Grammar', [
                    ['Video: Apa itu Grammar?', 'video', 'https://www.youtube.com/embed/dQw4w9WgXcQ', 'Pengantar kenapa grammar penting untuk speaking dan writing.'],
                    ['Materi PDF: Peta Materi Grammar', 'pdf', 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf', 'Rangkuman 12 tenses dalam satu halaman.'],
                    ['Audio: Listening Warm Up', 'audio', 'https://file-examples.com/storage/fe0b7a3e6ae0a3f5e0e8b6a/2017/11/file_example_MP3_700KB.mp3', 'Latihan mendengar kalimat simple present.'],
                ]],
                ['Tenses Inti', [
                    ['Simple Present Tense', 'teks', '', 'Simple present dipakai untuk kebiasaan dan fakta. Rumus: Subject + V1 (+s/es) + Object. Contoh: She teaches English every day.'],
                    ['Simple Past Tense', 'teks', '', 'Simple past dipakai untuk kejadian yang sudah selesai. Rumus: Subject + V2 + Object. Contoh: I studied English yesterday.'],
                    ['Latihan: Tenses Quiz', 'teks', '', 'Kerjakan quiz tenses di bagian Quiz untuk mengukur pemahamanmu.'],
                ]],
                ['Membuat Kalimat Tanya', [
                    ['Yes/No Question', 'teks', '', 'Pola: Do/Does/Did + Subject + V1 + ? Contoh: Do you speak English?'],
                    ['WH Question', 'teks', '', 'What, Where, When, Why, Who, How + auxiliary + subject + verb. Contoh: Where do you study English?'],
                ]],
            ],
        ],
        [
            'judul' => 'Speaking Confidence: Lancar Ngomong Inggris',
            'slug'  => 'speaking-confidence',
            'deskripsi' => 'Latihan speaking bertahap dengan pola percakapan nyata: perkenalan, telepon, presentasi singkat, dan small talk. Dilengkapi audio model dan tugas rekaman suara.',
            'kategori_id' => 2, 'guru_id' => $ids['sarah'], 'level' => 'Menengah', 'harga' => 450000, 'durasi_jam' => 10,
            'modules' => [
                ['Dasar Berbicara', [
                    ['Video: Cara Mengatasi Grogi', 'video', 'https://www.youtube.com/embed/dQw4w9WgXcQ', 'Tips latihan speaking setiap hari dalam 15 menit.'],
                    ['Audio: Contoh Perkenalan Diri', 'audio', 'https://file-examples.com/storage/fe0b7a3e6ae0a3f5e0e8b6a/2017/11/file_example_MP3_700KB.mp3', 'Dengarkan lalu tiru intonasinya.'],
                ]],
                ['Percakapan Sehari-hari', [
                    ['Asking & Giving Opinion', 'teks', '', 'Contoh: What do you think about ...? In my opinion, ... I agree with you.'],
                    ['Telephone Conversation', 'teks', '', 'Contoh: Hello, this is Budi speaking. May I speak to Mr. Robert, please?'],
                ]],
            ],
        ],
        [
            'judul' => 'TOEFL Preparation Intensive',
            'slug'  => 'toefl-preparation',
            'deskripsi' => 'Strategi mengerjakan Structure & Written Expression, Listening Comprehension, dan Reading Comprehension dengan latihan soal bergaya TOEFL ITP dan pembahasan lengkap.',
            'kategori_id' => 4, 'guru_id' => $ids['robert'], 'level' => 'Lanjutan', 'harga' => 750000, 'durasi_jam' => 20,
            'modules' => [
                ['Structure & Written Expression', [
                    ['Video: Strategi Structure', 'video', 'https://www.youtube.com/embed/dQw4w9WgXcQ', 'Teknik mengenali subject-verb agreement dengan cepat.'],
                    ['E-book: Bank Soal Structure', 'pdf', 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf', '100 soal structure dengan kunci.'],
                ]],
                ['Listening Comprehension', [
                    ['Audio: Latihan Listening Part A', 'audio', 'https://file-examples.com/storage/fe0b7a3e6ae0a3f5e0e8b6a/2017/11/file_example_MP3_700KB.mp3', 'Fokus pada short dialogue dan kata kunci.'],
                    ['Tips Menjawab Listening', 'teks', '', 'Baca pilihan jawaban sebelum audio diputar, catat kata kunci, waspadai distractor.'],
                ]],
            ],
        ],
        [
            'judul' => 'Kids English Fun: Belajar Sambil Bermain',
            'slug'  => 'kids-english-fun',
            'deskripsi' => 'Kelas bahasa Inggris untuk anak usia 6-10 tahun: kosakata, lagu, warna, angka, dan cerita sederhana dengan aktivitas seru.',
            'kategori_id' => 5, 'guru_id' => $ids['robert'], 'level' => 'Pemula', 'harga' => 250000, 'durasi_jam' => 8,
            'modules' => [
                ['Hello Kids!', [
                    ['Video: Alphabet Song', 'video', 'https://www.youtube.com/embed/dQw4w9WgXcQ', 'Menyanyi sambil mengenal alfabet.'],
                    ['Angka & Warna', 'teks', '', 'One, two, three. Red, blue, green. Lagu warna untuk anak.'],
                ]],
            ],
        ],
    ];

    $cstmt = $pdo->prepare('INSERT INTO courses (judul, slug, deskripsi, kategori_id, guru_id, level, harga, durasi_jam, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "aktif")');
    $mstmt = $pdo->prepare('INSERT INTO modules (course_id, judul, deskripsi, urutan) VALUES (?, ?, ?, ?)');
    $lstmt = $pdo->prepare('INSERT INTO lessons (module_id, course_id, judul, tipe, media_url, media_embed, isi_teks, durasi_menit, urutan, is_preview, bisa_download) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');

    $courseIds = [];
    foreach ($courses as $c) {
        $cstmt->execute([$c['judul'], $c['slug'], $c['deskripsi'], $c['kategori_id'], $c['guru_id'], $c['level'], $c['harga'], $c['durasi_jam']]);
        $cid = (int)$pdo->lastInsertId();
        $courseIds[$c['slug']] = $cid;
        $ur = 0;
        $lu = 0;
        $first = true;
        foreach ($c['modules'] as $m) {
            $ur++;
            $mstmt->execute([$cid, $m[0], '', $ur]);
            $mid = (int)$pdo->lastInsertId();
            $seen = [];
            foreach ($m[1] as $l) {
                $lu++;
                $url = $l[2];
                $embed = '';
                if ($l[1] === 'video' && strpos($url, 'youtube.com/embed/') !== false) {
                    $embed = $url;
                    $url = '';
                }
                $lstmt->execute([$mid, $cid, $l[0], $l[1], $url, $embed, $l[3], ($l[1] === 'video' ? 8 : ($l[1] === 'audio' ? 5 : 10)), $lu, $first ? 1 : 0]);
                $first = false;
            }
        }
    }

    $qstmt = $pdo->prepare('INSERT INTO quizzes (course_id, judul, deskripsi, tipe, durasi_menit, passing_grade, random_soal, random_opsi, jumlah_soal_tampil, tampilkan_pembahasan, max_attempt, guru_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    // Tuple soal: [tipe, pertanyaan, opsi, kunci, pembahasan, skor, penilaian?, opsi_kategori?]
    $qq = $pdo->prepare('INSERT INTO questions (quiz_id, course_id, tipe, pertanyaan, opsi, jawaban, pembahasan, skor, penilaian, opsi_kategori) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $quizDefs = [
        ['grammar-dasar', 'Quiz Tenses Dasar', 'Evaluasi pemahaman tenses dasar.', 'quiz', 15, 70, 1, 1, 5, 1, 0, $ids['sarah'], [
            ['mc', 'She ___ English every morning.', ['study', 'studies', 'studying', 'studied'], 1, 'Subjek she memakai V1 + s (studies) pada simple present.', 1],
            ['mc', 'I ___ to the library yesterday.', ['go', 'goes', 'went', 'going'], 2, 'Yesterday menandakan past tense, jadi V2 = went.', 1],
            ['tf', 'Kalimat "Does he like coffee?" adalah bentuk present tense.', null, 'benar', 'Does + subject + V1 adalah pola present tense untuk subjek ketiga tunggal.', 1],
            ['fill', 'Lengkapi: They ___ (be) students.', null, 'are', 'Subjek they memakai to be "are".', 1],
            ['matching', 'Pasangkan kata dengan artinya.', ['Book|Buku', 'Teacher|Guru', 'Chair|Kursi'], 'semua', 'Kosakata dasar yang sering muncul.', 1],
            ['mc', 'Which sentence is correct?', ['He don\'t like tea.', 'He doesn\'t like tea.', 'He not like tea.', 'He didn\'t likes tea.'], 1, 'Subjek he memakai doesn\'t pada simple present negatif.', 1],
            ['mc', '___ you speak English?', ['Do', 'Does', 'Did', 'Are'], 0, 'Subjek you memakai Do untuk kalimat tanya simple present.', 1],
            ['mcma', 'Pilih SEMUA kalimat yang memakai simple present tense (jawaban bisa lebih dari satu).',
                ['She teaches English every day.', 'They are playing football now.', 'I usually drink coffee in the morning.', 'He went to Jakarta yesterday.', 'We do not like durian.'],
                [0, 2, 4], 'Kalimat kebiasaan/fakta memakai simple present. "Are playing" = present continuous, "went" = past tense.', 3, 'parsial', null],
            ['kategori', 'Kelompokkan setiap kata ke kategori yang tepat.',
                ['Apple', 'Carrot', 'Mango', 'Spinach'],
                [0, 1, 0, 1], 'Apple & Mango = Fruit (buah), Carrot & Spinach = Vegetable (sayur).', 4, 'penuh', ['Fruit (Buah)', 'Vegetable (Sayur)']],
        ]],
        ['speaking-confidence', 'Quiz Percakapan Sehari-hari', 'Uji ungkapan yang sering dipakai saat berbicara.', 'quiz', 15, 70, 0, 1, 0, 1, 3, $ids['sarah'], [
            ['mc', 'You want to give an opinion politely. You say ...', ['I don\'t know anything.', 'In my opinion, this is a good idea.', 'Shut up please.', 'Give me the answer.'], 1, 'Ungkapan memberi opini diawali "In my opinion, ...".', 1],
            ['mc', 'Someone says "Thank you very much." Best response:', ['Yes, I do.', 'You\'re welcome.', 'I am fine.', 'No, thank you.'], 1, '"You\'re welcome" adalah balasan sopan untuk terima kasih.', 1],
            ['essay', 'Tulis perkenalan diri singkat (3-4 kalimat) dalam bahasa Inggris.', null, '', 'Penilaian memperhatikan grammar, kelancaran, dan kelengkapan informasi.', 2],
            ['fill', 'Nice to ___ you.', null, 'meet', 'Ungkapan standar saat berkenalan.', 1],
            ['mcma', 'Pilih SEMUA balasan yang sopan ketika seseorang mengucapkan terima kasih.',
                ['You\'re welcome.', 'No problem at all.', 'Go away.', 'My pleasure.', 'I don\'t care.'],
                [0, 1, 3], 'You\'re welcome / No problem / My pleasure semuanya balasan sopan.', 3, 'penuh', null],
        ]],
        ['toefl-preparation', 'Simulasi TOEFL Structure', 'Simulasi singkat bagian Structure & Written Expression.', 'ujian', 30, 75, 1, 1, 4, 1, 0, $ids['robert'], [
            ['mc', 'The book ___ on the table belongs to my sister.', ['who', 'which', 'whose', 'whom'], 1, 'Kata "which" dipakai untuk benda sebagai penghubung klausa.', 1],
            ['mc', '___ she studied hard, she passed the exam.', ['Because', 'Although', 'However', 'Despite'], 0, 'Hubungan sebab-akibat memakai because.', 1],
            ['mc', 'The manager, as well as his assistants, ___ attending the meeting.', ['are', 'is', 'were', 'be'], 1, '"As well as" tidak mengubah subjek utama (singular) sehingga memakai is.', 1],
            ['tf', 'In written expression items, "much" can usually modify countable plural nouns.', null, 'salah', '"Much" untuk uncountable nouns, "many" untuk countable plural.', 1],
            ['fill', 'By the time we arrived, the film ___ (start).', null, 'had started', 'Past perfect dipakai untuk kejadian yang selesai sebelum kejadian lain di masa lalu.', 2],
        ]],
    ];
    foreach ($quizDefs as $q) {
        $qstmt->execute([$courseIds[$q[0]], $q[1], $q[2], $q[3], $q[4], $q[5], $q[6], $q[7], $q[8], $q[9], $q[10], $q[11]]);
        $qid = (int)$pdo->lastInsertId();
        foreach ($q[12] as $qqq) {
            $qq->execute([
                $qid,
                $courseIds[$q[0]],
                $qqq[0],
                $qqq[1],
                $qqq[2] === null ? null : json_encode($qqq[2]),
                // Kunci kategori disimpan sebagai peta objek {"item":kategori};
                // array PHP dengan key numerik akan menjadi JSON array, jadi di-cast.
                is_array($qqq[3]) ? json_encode($qqq[0] === 'kategori' ? (object)$qqq[3] : $qqq[3]) : (string)$qqq[3],
                $qqq[4],
                $qqq[5],
                $qqq[6] ?? 'penuh',
                isset($qqq[7]) && is_array($qqq[7]) ? json_encode($qqq[7]) : null,
            ]);
        }
    }

    // ---- Guru kedua juga mengajar TOEFL; set enrollment contoh ----
    $en = $pdo->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id, progress, status) VALUES (?, ?, ?, ?)');
    $en->execute([$ids['budi'], $courseIds['grammar-dasar'], 45, 'aktif']);
    $en->execute([$ids['budi'], $courseIds['speaking-confidence'], 100, 'selesai']);
    $en->execute([$ids['siti'], $courseIds['grammar-dasar'], 70, 'aktif']);
    $en->execute([$ids['andi'], $courseIds['toefl-preparation'], 25, 'aktif']);
    $en->execute([$ids['dewi'], $courseIds['grammar-dasar'], 100, 'selesai']);
    $en->execute([$ids['dewi'], $courseIds['kids-english-fun'], 60, 'aktif']);
    $en->execute([$ids['kevin'], $courseIds['speaking-confidence'], 30, 'aktif']);

    // ---- Kelas ----
    $cl = $pdo->prepare('INSERT INTO classes (course_id, guru_id, nama, kode, jadwal, kapasitas) VALUES (?, ?, ?, ?, ?, ?)');
    $cl->execute([$courseIds['grammar-dasar'], $ids['sarah'], 'Grammar Regular Senin-Rabu', 'GLC-GRM-01', 'Senin & Rabu, 19:00-20:30', 30]);
    $c1 = (int)$pdo->lastInsertId();
    $cl->execute([$courseIds['toefl-preparation'], $ids['robert'], 'TOEFL Intensive Sabtu', 'GLC-TOF-01', 'Sabtu, 09:00-12:00', 20]);
    $c2 = (int)$pdo->lastInsertId();
    $cs2 = $pdo->prepare('INSERT OR IGNORE INTO class_students (class_id, user_id) VALUES (?, ?)');
    foreach ([['c' => $c1, 'u' => ['budi', 'siti', 'dewi']], ['c' => $c2, 'u' => ['andi', 'kevin']]] as $grp) {
        foreach ($grp['u'] as $uu) {
            $cs2->execute([$grp['c'], $ids[$uu]]);
        }
    }

    // ---- Tugas ----
    $as = $pdo->prepare('INSERT INTO assignments (course_id, guru_id, judul, deskripsi, deadline, max_skor) VALUES (?, ?, ?, ?, ?, ?)');
    $as->execute([$courseIds['grammar-dasar'], $ids['sarah'], 'Tugas 1: Menulis 10 Kalimat Simple Present', 'Tulis 10 kalimat simple present tentang rutinitas harianmu, lalu kumpulkan dalam bentuk teks atau file PDF.', date('Y-m-d', strtotime('+7 days')) . ' 23:59', 100]);
    $a1 = (int)$pdo->lastInsertId();
    $as->execute([$courseIds['speaking-confidence'], $ids['sarah'], 'Tugas 2: Rekam Perkenalan Diri', 'Rekam voice note 1 menit berisi perkenalan diri dalam bahasa Inggris, lalu kumpulkan link/berkasnya.', date('Y-m-d', strtotime('+10 days')) . ' 23:59', 100]);
    $a2 = (int)$pdo->lastInsertId();
    $as->execute([$courseIds['toefl-preparation'], $ids['robert'], 'Tugas: Analisis Struktur Kalimat', 'Kerjakan 20 soal structure, tulis alasan pemilihan jawaban, kumpulkan dalam bentuk PDF.', date('Y-m-d', strtotime('+14 days')) . ' 23:59', 100]);

    $sub = $pdo->prepare('INSERT OR IGNORE INTO assignment_submissions (assignment_id, user_id, isi, skor, feedback, dinilai_at, dinilai_oleh) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $sub->execute([$a1, $ids['budi'], "1. I wake up at five o'clock.\n2. I take a shower every morning.\n3. I have breakfast with my family.", 88, 'Bagus! Perhatikan penggunaan huruf kapital pada kata I dan tanda titik.', date('Y-m-d H:i:s'), $ids['sarah']]);
    $sub->execute([$a1, $ids['dewi'], 'Latihan kalimat simple present saya lampirkan pada catatan.', null, null, null, null]);

    // ---- Absensi ----
    $at = $pdo->prepare('INSERT OR IGNORE INTO attendance (course_id, class_id, user_id, tanggal, status, dicatat_oleh) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([-14, -12, -7, -5] as $i => $d) {
        $tgl = date('Y-m-d', strtotime("$d days"));
        foreach (['budi', 'siti', 'dewi'] as $k => $uu) {
            $at->execute([$courseIds['grammar-dasar'], $c1, $ids[$uu], $tgl, ($k === 2 && $i === 2) ? 'izin' : 'hadir', $ids['sarah']]);
        }
    }

    // ---- Forum & komentar ----
    $ft = $pdo->prepare('INSERT INTO forum_topics (course_id, user_id, judul, isi, pinned) VALUES (?, ?, ?, ?, ?)');
    $ft->execute([$courseIds['grammar-dasar'], $ids['budi'], 'Beda Simple Past dan Past Continuous?', 'Saya masih bingung kapan pakai was/were + V-ing. Ada contoh sederhana?', 0]);
    $t1 = (int)$pdo->lastInsertId();
    $ft->execute([$courseIds['toefl-preparation'], $ids['robert'], 'Selamat Datang di Kelas TOEFL Intensive', 'Silakan perkenalkan diri dan tulis target skor TOEFL kalian di topik ini.', 1]);
    $t2 = (int)$pdo->lastInsertId();
    $fr = $pdo->prepare('INSERT INTO forum_replies (topic_id, user_id, isi) VALUES (?, ?, ?)');
    $fr->execute([$t1, $ids['sarah'], 'Past continuous dipakai untuk aksi yang sedang berlangsung saat aksi lain terjadi. Contoh: I was studying when he called.']);
    $fr->execute([$t1, $ids['siti'], 'Oh jadi yang "sedang terjadi" pakai past continuous, terima kasih Miss!']);
    $fr->execute([$t2, $ids['andi'], 'Target saya 550, Pak. Terima kasih.']);

    $lc = $pdo->prepare('INSERT INTO lesson_comments (lesson_id, user_id, isi) VALUES (?, ?, ?)');
    $firstLesson = (int)$pdo->query('SELECT id FROM lessons ORDER BY id LIMIT 1')->fetchColumn();
    $lc->execute([$firstLesson, $ids['siti'], 'Videonya jelas, terima kasih Miss Sarah!']);

    // ---- Pengumuman, FAQ, banner, jadwal ----
    $an = $pdo->prepare('INSERT INTO announcements (course_id, guru_id, judul, isi, audience, pinned) VALUES (?, ?, ?, ?, ?, ?)');
    $an->execute([$courseIds['grammar-dasar'], $ids['sarah'], 'Jadwal Quiz Tenses Minggu Ini', 'Quiz Tenses Dasar dibuka mulai Senin sampai Minggu. Kerjakan maksimal sebelum deadline, nilai minimal 70.', 'kursus', 1]);
    $an->execute([null, $ids['robert'], 'Libur Nasional', 'Kelas tanggal merah diliburkan dan diganti pada sesi berikutnya.', 'semua', 1]);
    $an->execute([$courseIds['toefl-preparation'], $ids['robert'], 'Simulasi TOEFL Gratis', 'Simulasi TOEFL Structure akan dibuka pada akhir bulan untuk seluruh siswa TOEFL.', 'kursus', 0]);

    $fq = $pdo->prepare('INSERT INTO faqs (pertanyaan, jawaban, kategori, urutan) VALUES (?, ?, ?, ?)');
    $fq->execute(['Bagaimana cara mendaftar kursus?', 'Buat akun siswa, pilih kursus di halaman Katalog Kursus, lalu klik "Ikuti Kursus". Materi langsung bisa diakses.', 'Pendaftaran', 1]);
    $fq->execute(['Apakah saya mendapat sertifikat?', 'Ya. Sertifikat otomatis diterbitkan setelah kamu menyelesaikan seluruh materi dan lulus quiz/ujian dengan nilai minimal yang ditentukan.', 'Sertifikat', 2]);
    $fq->execute(['Berapa lama akses materi berlaku?', 'Akses materi berlaku selamanya untuk kursus yang sudah kamu ikuti, termasuk pembaruan materi.', 'Akses', 3]);
    $fq->execute(['Bagaimana jika saya gagal quiz?', 'Kamu bisa mengulang quiz sesuai batas percobaan yang ditentukan guru. Nilai terbaik akan dipakai untuk progress.', 'Quiz', 4]);

    $bn = $pdo->prepare('INSERT INTO banners (judul, subjudul, gambar, link, urutan, aktif) VALUES (?, ?, ?, ?, ?, 1)');
    $bn->execute(['Belajar Bahasa Inggris Lebih Percaya Diri', 'Kelas online + tatap muka dengan pengajar berpengalaman di Marelan English Course.', '', 'kursus.php', 1]);
    $bn->execute(['Program TOEFL Intensive', 'Targetkan skor TOEFL impianmu dengan simulasi dan pembahasan lengkap.', '', 'kursus.php?kategori=4', 2]);

    $sc = $pdo->prepare('INSERT INTO schedules (user_id, course_id, judul, tipe, tanggal, jam_mulai, jam_selesai, catatan, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $sc->execute([$ids['budi'], $courseIds['grammar-dasar'], 'Kelas Grammar Tatap Muka', 'kelas', date('Y-m-d', strtotime('+2 days')), '19:00', '20:30', 'Bawa buku catatan latihan tenses.', '#4F46E5']);
    $sc->execute([$ids['budi'], $courseIds['grammar-dasar'], 'Deadline Tugas 1', 'tugas', date('Y-m-d', strtotime('+7 days')), '23:59', '23:59', 'Kumpulkan 10 kalimat simple present.', '#EC4899']);
    $sc->execute([$ids['budi'], $courseIds['grammar-dasar'], 'Belajar Mandiri: Simple Past', 'belajar', date('Y-m-d', strtotime('+3 days')), '20:00', '21:00', 'Selesaikan lesson Simple Past Tense.', '#0EA5E9']);
    $sc->execute([$ids['siti'], $courseIds['grammar-dasar'], 'Kelas Grammar Tatap Muka', 'kelas', date('Y-m-d', strtotime('+2 days')), '19:00', '20:30', '', '#4F46E5']);

    $nf = $pdo->prepare('INSERT INTO notifications (user_id, judul, pesan, url, dibaca) VALUES (?, ?, ?, ?, ?)');
    $nf->execute([$ids['budi'], 'Quiz Tenses Dasar tersedia', 'Quiz Tenses Dasar sudah dibuka. Nilai minimal 70 untuk lulus.', 'siswa-nilai.php', 0]);
    $nf->execute([$ids['budi'], 'Feedback tugas baru', 'Tugas 1 kamu sudah dinilai Miss Sarah: 88.', 'siswa-tugas.php', 0]);
    $nf->execute([$ids['siti'], 'Pengingat jadwal kelas', 'Kelas Grammar berlangsung 2 hari lagi pukul 19:00.', 'siswa-kalender.php', 0]);

    $au = $pdo->prepare('INSERT INTO audit_logs (user_id, aksi, detail, ip) VALUES (?, ?, ?, ?)');
    $au->execute([$ids['admin'], 'seed', 'Instalasi awal sistem LMS & data contoh', '127.0.0.1']);
}
