<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/infografis.php';
$u = current_user();

$kursusId = num('kursus');
if ($kursusId > 0 && num('id') === 0) {
    // Guru/admin juga boleh memakai ?kursus=N (tombol "Lanjut Belajar" di
    // kursus-detail mengarah ke sini). Dulu hanya siswa, sehingga staf yang
    // mengklik tombol itu mendapat halaman "Akses ditolak".
    require_login(['siswa', 'guru', 'admin']);
    $next = db()->query("SELECT l.id FROM lessons l
        LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = " . (int)$u['id'] . "
        WHERE l.course_id = " . $kursusId . " AND COALESCE(lp.selesai,0) = 0 ORDER BY l.urutan, l.id LIMIT 1")->fetchColumn();
    if (!$next) {
        $next = db()->query('SELECT id FROM lessons WHERE course_id = ' . $kursusId . ' ORDER BY urutan, id LIMIT 1')->fetchColumn();
    }
    if ($next) {
        redirect('materi.php?id=' . (int)$next);
    }
    redirect('kursus-detail.php?id=' . $kursusId);
}

$id = num('id');
$stmt = db()->prepare('SELECT l.*, m.judul AS modul, co.judul AS kursus, co.id AS course_id, co.guru_id FROM lessons l
    LEFT JOIN modules m ON m.id = l.module_id
    LEFT JOIN courses co ON co.id = l.course_id WHERE l.id = ?');
$stmt->execute([$id]);
$l = $stmt->fetch();
if (!$l) {
    http_response_code(404);
    page_start('Materi tidak ditemukan');
    echo '<div class="card">' . empty_state('Materi tidak ditemukan.', 'search') . '</div>';
    page_end();
    exit;
}

$isPreview = (int)$l['is_preview'] === 1;
$kursusRow = db()->query('SELECT id, judul, harga FROM courses WHERE id = ' . (int)$l['course_id'])->fetch() ?: ['id' => (int)$l['course_id'], 'judul' => '', 'harga' => 0];
$akses = cek_akses_kursus((int)($u['id'] ?? 0), $kursusRow);
$staf = in_array($u['role'] ?? '', ['guru', 'admin'], true);
if (!$staf && !$akses['boleh'] && !$isPreview) {
    flash($akses['pesan'], 'warning');
    if ($akses['status'] === 'belum_daftar') {
        redirect('kursus-detail.php?id=' . (int)$l['course_id']);
    }
    redirect('siswa-pembayaran.php?kursus=' . (int)$l['course_id']);
}
$enrolled = $staf || $akses['boleh'] || $isPreview;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    if ($aksi === 'selesai') {
        db()->prepare('INSERT INTO lesson_progress (user_id, lesson_id, course_id, selesai) VALUES (?, ?, ?, 1)
            ON CONFLICT(user_id, lesson_id) DO UPDATE SET selesai = 1, updated_at = CURRENT_TIMESTAMP')
            ->execute([$u['id'], $l['id'], $l['course_id']]);
        recalc_course_progress($u['id'], $l['course_id']);
        flash('Materi ditandai selesai. Lanjut ke materi berikutnya!');
        $next = db()->query("SELECT l2.id FROM lessons l2 LEFT JOIN lesson_progress lp ON lp.lesson_id = l2.id AND lp.user_id = " . (int)$u['id'] . "
            WHERE l2.course_id = " . (int)$l['course_id'] . " AND l2.urutan > " . (int)$l['urutan'] . " AND COALESCE(lp.selesai,0) = 0 ORDER BY l2.urutan LIMIT 1")->fetchColumn();
        redirect($next ? 'materi.php?id=' . (int)$next : 'kursus-detail.php?id=' . (int)$l['course_id']);
    }
    if ($aksi === 'komentar') {
        // Materi preview boleh dibaca tamu, tapi menulis komentar wajib login —
        // tanpa ini tamu bisa menyisipkan komentar tanpa identitas (user_id NULL).
        require_login();
        $isi = post('isi');
        if ($isi !== '') {
            db()->prepare('INSERT INTO lesson_comments (lesson_id, user_id, isi) VALUES (?, ?, ?)')->execute([$l['id'], $u['id'], $isi]);
            $ringkas = mb_strimwidth($isi, 0, 90, '...');
            $tujuanDiskusi = 'materi.php?id=' . (int)$l['id'] . '#komentar';
            if ($staf) {
                // Balasan guru/admin: beri tahu siswa yang sudah berkomentar di
                // materi ini supaya pertanyaannya tidak menggantung tanpa kabar.
                $penerima = db()->query('SELECT DISTINCT k.user_id FROM lesson_comments k
                    JOIN users us ON us.id = k.user_id
                    WHERE k.lesson_id = ' . (int)$l['id'] . ' AND us.role = "siswa" AND k.user_id <> ' . (int)$u['id'])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($penerima as $pid) {
                    notify((int)$pid, 'Guru membalas diskusi materi ' . $l['judul'], $u['nama'] . ': ' . $ringkas, $tujuanDiskusi);
                }
            } elseif ($l['guru_id'] && (int)$l['guru_id'] !== (int)$u['id']) {
                notify($l['guru_id'], 'Komentar baru di materi ' . $l['judul'], $u['nama'] . ': ' . $ringkas, $tujuanDiskusi);
            }
            audit('komentar', 'Komentar pada materi ' . $l['judul']);
            flash('Komentar terkirim.');
        }
        redirect('materi.php?id=' . (int)$l['id'] . '#komentar');
    }
    if ($aksi === 'bookmark') {
        require_login();
        $cek = db()->prepare('SELECT id FROM bookmarks WHERE user_id = ? AND lesson_id = ?');
        $cek->execute([$u['id'], $l['id']]);
        if ($cek->fetch()) {
            db()->prepare('DELETE FROM bookmarks WHERE user_id = ? AND lesson_id = ?')->execute([$u['id'], $l['id']]);
            flash('Bookmark dihapus.');
        } else {
            db()->prepare('INSERT INTO bookmarks (user_id, lesson_id) VALUES (?, ?)')->execute([$u['id'], $l['id']]);
            flash('Materi ditambahkan ke bookmark.');
        }
        redirect('materi.php?id=' . (int)$l['id']);
    }
}

$done = false;
$bookmarked = false;
if ($u) {
    $s = db()->prepare('SELECT selesai FROM lesson_progress WHERE user_id = ? AND lesson_id = ?');
    $s->execute([$u['id'], $l['id']]);
    $done = (bool)$s->fetchColumn();
    $b = db()->prepare('SELECT id FROM bookmarks WHERE user_id = ? AND lesson_id = ?');
    $b->execute([$u['id'], $l['id']]);
    $bookmarked = (bool)$b->fetch();
}

$lessons = db()->query('SELECT l2.id, l2.judul, l2.tipe, l2.urutan, l2.module_id, m.judul AS modul FROM lessons l2 LEFT JOIN modules m ON m.id = l2.module_id WHERE l2.course_id = ' . (int)$l['course_id'] . ' ORDER BY l2.urutan, l2.id')->fetchAll();
$doneIds = $u ? array_map('intval', db()->query('SELECT lesson_id FROM lesson_progress WHERE selesai = 1 AND user_id = ' . (int)$u['id'])->fetchAll(PDO::FETCH_COLUMN)) : [];
$komentar = db()->query('SELECT k.*, us.nama, us.role, us.foto FROM lesson_comments k LEFT JOIN users us ON us.id = k.user_id WHERE k.lesson_id = ' . (int)$l['id'] . ' ORDER BY k.created_at ASC')->fetchAll();
$jmlKomentar = count($komentar);

// Quiz & ujian kursus ini ditampilkan menyatu di halaman belajar: quiz yang
// menjadi milik materi INI tampil di bawah isi materi (di dalam kartu materi).
// Daftar quiz kursus di sidebar halaman ini DIHAPUS (permintaan pemilik, 2 Okt
// 2026) — jadi halaman materi tidak lagi punya daftar quiz. Quiz yang belum
// ditautkan ke materi mana pun tetap dikerjakan dari halaman materi tempat ia
// tampil; data quiz tidak diubah sama sekali, hanya cara menampilkannya.
$quizzesMateri = db()->query('SELECT id, judul, tipe, durasi_menit, passing_grade, max_attempt, lesson_id FROM quizzes
    WHERE course_id = ' . (int)$l['course_id'] . ' AND status = "aktif" ORDER BY id')->fetchAll();
$jmlSoalQuiz = quiz_jml_soal_map((int)$l['course_id']);
$quizMateri = quiz_untuk_materi($quizzesMateri, $l['id'], $l['judul']);
$hasilQuiz = [];
if ($u) {
    foreach (db()->query('SELECT id, quiz_id, nilai, status FROM quiz_attempts
        WHERE user_id = ' . (int)$u['id'] . ' AND status != "in_progress" ORDER BY id') as $r) {
        $qid = (int)$r['quiz_id'];
        if (!isset($hasilQuiz[$qid])) {
            $hasilQuiz[$qid] = ['best' => (float)$r['nilai'], 'jml' => 0, 'attempt' => (int)$r['id']];
        }
        $hasilQuiz[$qid]['jml']++;
        if ((float)$r['nilai'] > $hasilQuiz[$qid]['best']) {
            $hasilQuiz[$qid]['best'] = (float)$r['nilai'];
            $hasilQuiz[$qid]['attempt'] = (int)$r['id'];
        }
    }
}

// Materi bisa berupa berkas gambar (scan/foto), baik diunggah sebagai berkas
// maupun ditempel sebagai URL — apa pun tipe materinya. Gambar selalu ditampilkan
// sebagai <img> yang muat di layar, bukan di dalam iframe/video yang memaksa
// pengguna menggeser materi ke kiri-kanan.
$gambarMateri = '';
foreach ([$l['media_url'], $l['media_embed']] as $kandidat) {
    $kandidat = gambar_url_valid($kandidat);
    if ($kandidat !== '' && media_url_gambar($kandidat)) {
        $gambarMateri = $kandidat;
        break;
    }
}

// Blok media materi sebagai HTML (video/audio/PDF/gambar). Dipakai untuk materi
// biasa, dan pada materi infografis ditampilkan sebagai "bacaan asli" (blok lipat
// di bawah infografis) supaya berkas aslinya tetap bisa dibuka siswa.
$mediaPolos = '';
if ($gambarMateri !== '') {
    $mediaPolos = '<div class="media-frame media-gambar">' . gambar_img($gambarMateri, 'media-img', $l['judul'], false) . '</div>';
} elseif ($l['tipe'] === 'video') {
    $srcMedia = $l['media_embed'] ?: $l['media_url'];
    if ($srcMedia) {
        $mediaPolos = '<div class="media-frame">' . (preg_match('~\.(mp4|webm|mov)$~i', $srcMedia)
            ? '<video controls preload="metadata" src="' . e($srcMedia) . '"></video>'
            : '<iframe src="' . e(embed_url($srcMedia)) . '" title="' . e($l['judul']) . '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>') . '</div>';
    } else {
        $mediaPolos = '<div class="flash flash-warning mb3">' . icon('bell', 17) . '<span>Video belum diunggah oleh pengajar.</span></div>';
    }
} elseif ($l['tipe'] === 'audio') {
    $mediaPolos = '<div class="media-frame">'
        . ($l['media_url']
            ? '<audio controls src="' . e($l['media_url']) . '"></audio>'
            : '<div style="padding:30px;text-align:center;color:#fff">Audio belum tersedia.</div>')
        . '</div>';
} elseif ($l['tipe'] === 'pdf' && $l['media_url']) {
    $mediaPolos = '<div class="media-frame" style="background:var(--surface-2)">'
        . '<iframe src="' . e($l['media_url']) . '#view=FitH" title="PDF" style="height:70vh;aspect-ratio:auto"></iframe></div>';
}

// Materi tipe infografis: isi ringkasannya dirender jadi infografis visual.
$infoHtml = $l['tipe'] === 'infografis' ? infografis_html($l) : '';
// Sebutan jenis berkas untuk blok lipat "bacaan asli" pada materi infografis.
$labelAsli = $gambarMateri !== '' ? 'gambar/scan' : ($l['tipe'] === 'pdf' ? 'PDF' : ($l['tipe'] === 'video' ? 'video' : ($l['tipe'] === 'audio' ? 'audio' : 'berkas')));

page_start($l['judul'], ($l['kursus'] ?? '') . ($l['modul'] ? ' · ' . $l['modul'] : ''));
if (!$staf && !$akses['boleh'] && $isPreview) {
    echo '<div class="flash flash-info mb3">' . icon('lock', 17) . '<span>Ini materi contoh (preview). '
        . (kursus_berbayar($kursusRow)
            ? 'Selesaikan pembayaran kursus ini untuk membuka seluruh materi: <a href="siswa-pembayaran.php?kursus=' . (int)$l['course_id'] . '">buka halaman pembayaran</a>.'
            : 'Ikuti kursus ini untuk membuka seluruh materi.') . '</span></div>';
}
?>
<div class="split">
  <div>
    <div class="card">
      <div class="row-gap mb3">
        <a class="btn btn-ghost btn-sm" href="kursus-detail.php?id=<?= (int)$l['course_id'] ?>"><?= icon('arrow-left', 15) ?> Detail Kursus</a>
        <span class="chip"><?= e(tipe_materi_label($l['tipe'])) ?></span>
        <?php if ($l['durasi_menit']): ?><span class="chip"><?= icon('clock', 14) ?> <?= (int)$l['durasi_menit'] ?> menit</span><?php endif; ?>
        <?php if ($done): ?><span class="badge badge-success">Selesai</span><?php endif; ?>
      </div>

      <?php if ($l['tipe'] === 'infografis'): ?>
        <?php /* Materi infografis: ringkasan visual di atas, bacaan aslinya (berkas
                 PDF/gambar yang diunggah) bisa dibuka lewat blok lipat di bawah. */ ?>
        <?php if ($infoHtml !== ''): ?>
          <?= $infoHtml ?>
        <?php else: ?>
          <div class="notice">
            <div class="notice-ic"><?= icon('chart', 18) ?></div>
            <div>
              <h4>Infografis belum diisi</h4>
              <p><?= $staf
                ? 'Buka <a href="guru-materi.php?kursus=' . (int)$l['course_id'] . '&edit_materi=' . (int)$l['id'] . '&tipe=infografis&susun=1">Kelola Materi</a> lalu tekan &ldquo;Susun dengan AI&rdquo; untuk membuat ringkasannya dari bacaan materi ini.'
                : 'Ringkasan visual materi ini belum disiapkan pengajar. Kamu masih bisa membaca berkas materinya di bawah.' ?></p>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($mediaPolos !== ''): ?>
          <details class="info-asli">
            <summary><?= icon('book', 16) ?> Lihat bacaan asli (<?= e($labelAsli) ?>)</summary>
            <?= $mediaPolos ?>
          </details>
        <?php endif; ?>
      <?php elseif ($gambarMateri !== ''): ?>
        <?php /* Zoom gambar materi: hanya gambarnya yang diperbesar/diperkecil
                 (teks materi tidak ikut), lewat tombol − / + atau cubit dua jari.
                 Mesinnya di assets/app.js bagian "zoom gambar materi". */ ?>
        <div class="zoom-alat" data-zoom-alat>
          <div class="zoom-bar">
            <div class="zoom-grup">
              <button type="button" class="zoom-btn" data-zoom-out aria-label="Perkecil gambar" title="Perkecil"><?= icon('zoom-out', 16) ?></button>
              <button type="button" class="zoom-btn zoom-nilai" data-zoom-reset aria-label="Kembalikan ke ukuran awal" title="Kembalikan ke ukuran awal"><span data-zoom-nilai>100%</span></button>
              <button type="button" class="zoom-btn" data-zoom-in aria-label="Perbesar gambar" title="Perbesar"><?= icon('zoom-in', 16) ?></button>
            </div>
            <span class="zoom-hint"><?= icon('search', 14) ?> Perbesar gambar dengan cubit dua jari atau tombol − / +, lalu geser untuk menjelajah.</span>
          </div>
          <div class="media-frame media-gambar" data-zoom>
            <?= gambar_img($gambarMateri, 'media-img', $l['judul'], false) ?>
          </div>
        </div>
      <?php else: ?>
        <?= $mediaPolos ?>
      <?php endif; ?>

      <?php if ($l['isi_teks']): ?>
        <div class="reading"><?= nl2br(e($l['isi_teks'])) ?></div>
      <?php endif; ?>

      <?php /* Tanya AI: obrolan dengan AI yang sudah membaca materi ini.
               Ditaruh tepat di bawah isi materi dan di atas blok Quiz.
               - staf: kartu selalu tampil saat fitur dinyalakan (termasuk
                 peringatan bila kunci API belum diisi, supaya tidak "diam saja");
               - siswa: hanya saat kunci API sudah siap (jangan sampai siswa
                 melihat fitur yang belum bisa dipakai);
               - tamu (materi preview): ajakan masuk. */ ?>
      <?php if (ai_aktif()): ?>
        <?php if ($staf): ?>
          <?= ai_kartu_html($u, $l) ?>
        <?php elseif (ai_siap()): ?>
          <?= $u ? ai_kartu_html($u, $l) : ai_kartu_tamu() ?>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($quizMateri): ?>
        <div class="divider"></div>
        <div class="quiz-materi" id="quiz-materi">
          <div class="card-head">
            <h3 class="mt0 mb0"><?= icon('quiz', 18) ?> Quiz &amp; Ujian Materi Ini</h3>
            <span class="muted small"><?= count($quizMateri) ?> quiz</span>
          </div>
          <p class="muted small">Uji pemahamanmu setelah membaca materi ini.</p>
          <div class="lesson-stack">
            <?php foreach ($quizMateri as $qz): ?>
              <?= quiz_kartu_html($qz, $hasilQuiz[(int)$qz['id']] ?? null, (int)($jmlSoalQuiz[(int)$qz['id']] ?? 0), $staf, $akses, (int)$l['course_id']) ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="divider"></div>
      <div class="row-gap" id="materi-aksi">
        <?php if (!$done): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="selesai">
            <button class="btn btn-primary" type="submit"><?= icon('check', 18) ?> Tandai Selesai & Lanjut</button>
          </form>
        <?php else: ?>
          <span class="badge badge-success"><?= icon('check', 14) ?> Materi ini sudah selesai</span>
        <?php endif; ?>
        <?php if ($l['bisa_download']): ?>
          <?php if ($l['media_url']): ?>
            <a class="btn btn-ghost" href="<?= e($l['media_url']) ?>" download target="_blank" rel="noopener"><?= icon('download', 17) ?> Download Materi</a>
          <?php elseif ($l['tipe'] === 'teks'): ?>
            <a class="btn btn-ghost" href="api-download.php?id=<?= (int)$l['id'] ?>"><?= icon('download', 17) ?> Download Ringkasan (HTML)</a>
          <?php endif; ?>
        <?php endif; ?>
        <?php /* Simpan halaman materi ini (berserta gambarnya) ke perangkat lewat
                 aplikasi Android supaya bisa dibaca tanpa internet. Hanya untuk
                 siswa yang kursusnya terbuka — staf & materi preview tidak.
                 Mesinnya LMSLuring.unduhMateri di assets/app.js. */ ?>
        <?php if ($u && $u['role'] === 'siswa' && !empty($akses['boleh'])):
            $urlLuring = 'materi.php?id=' . (int)$l['id']; ?>
          <button type="button" class="btn btn-ghost" data-luring-unduh="<?= e($urlLuring) ?>"
                  data-judul="<?= e($l['judul']) ?>" title="Baca tanpa internet lewat aplikasi Android">
            <?= icon('cloud-off', 17) ?> Unduh Offline
          </button>
          <span class="luring-tanda" data-luring-tanda="<?= e($urlLuring) ?>"></span>
        <?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="bookmark">
          <button class="btn btn-ghost" type="submit"><?= icon('bookmark', 17) ?> <?= $bookmarked ? 'Hapus Bookmark' : 'Bookmark' ?></button>
        </form>
      </div>
    </div>

    <div class="card" id="komentar">
      <div class="card-head"><h2><?= icon('chat', 20) ?> Diskusi Materi (<?= $jmlKomentar ?>)</h2></div>
      <?php if (!$komentar): ?><p class="muted small"><?= $staf ? 'Belum ada pertanyaan siswa pada materi ini.' : 'Belum ada komentar. Tanyakan hal yang belum kamu pahami di sini.' ?></p><?php endif; ?>
      <?php foreach ($komentar as $k): ?>
        <div class="post">
          <span class="avatar sm" <?= $k['foto'] ? 'style="background-image:url(' . e($k['foto']) . ')"' : '' ?>><?= $k['foto'] ? '' : e(strtoupper(mb_substr($k['nama'] ?? 'U', 0, 1))) ?></span>
          <div class="post-body">
            <div class="post-meta"><strong><?= e($k['nama'] ?? 'Pengguna') ?></strong><?= in_array($k['role'] ?? '', ['guru', 'admin'], true) ? '<span class="badge badge-info">Pengajar</span>' : '' ?><span><?= e(tgl_id($k['created_at'], true)) ?></span></div>
            <p style="margin:0"><?= nl2br(e($k['isi'])) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
      <form method="post" class="reply-box">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="komentar">
        <span class="avatar sm"><?= $u ? e(strtoupper(mb_substr($u['nama'], 0, 1))) : '?' ?></span>
        <div style="flex:1">
          <textarea name="isi" placeholder="<?= $staf ? 'Tulis balasan atau penjelasan tambahan untuk siswa...' : 'Tulis pertanyaan atau komentar tentang materi ini...' ?>" required></textarea>
          <button class="btn btn-primary btn-sm mt1" type="submit"><?= icon('chat', 16) ?> <?= $staf ? 'Kirim Balasan' : 'Kirim Komentar' ?></button>
        </div>
      </form>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card-head"><h3 class="mt0"><?= icon('list', 18) ?> Daftar Materi</h3></div>
      <div class="scroll-y">
        <div class="lesson-stack">
          <?php
          $lastMod = null;
          foreach ($lessons as $l2):
              if ($l2['modul'] !== $lastMod): $lastMod = $l2['modul']; ?>
                <div class="small muted" style="font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:8px"><?= e($l2['modul'] ?: 'Materi Umum') ?></div>
              <?php endif; ?>
            <a class="lesson-item <?= (int)$l2['id'] === (int)$l['id'] ? 'active' : (in_array((int)$l2['id'], $doneIds, true) ? 'done' : '') ?>" href="materi.php?id=<?= (int)$l2['id'] ?>">
              <span class="lesson-ic <?= e($l2['tipe']) ?>"><?= icon(tipe_materi_ikon($l2['tipe']), 17) ?></span>
              <span class="lesson-meta"><strong><?= e(mb_strimwidth($l2['judul'], 0, 40, '...')) ?></strong><small><?= e(tipe_materi_label($l2['tipe'])) ?></small></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="divider"></div>
      <a class="btn btn-ghost btn-block" href="kursus-detail.php?id=<?= (int)$l['course_id'] ?>"><?= icon('grid', 17) ?> Semua Materi & Quiz</a>
    </div>

    <?php /* Daftar quiz & ujian kursus di sidebar DIHAPUS atas permintaan pemilik
             (2 Okt 2026). Quiz tetap dikerjakan dari blok "Quiz & Ujian Materi Ini"
             di dalam kartu materi. Jangan dikembalikan tanpa diminta. */ ?>

    <div class="card">
      <h3 class="mt0"><?= icon('bookmark', 18) ?> Aksi Cepat</h3>
      <div class="stack">
        <a class="btn btn-ghost btn-sm" href="siswa-bookmark.php"><?= icon('bookmark', 16) ?> Materi Bookmark Saya</a>
        <a class="btn btn-ghost btn-sm" href="siswa-forum.php?kursus=<?= (int)$l['course_id'] ?>"><?= icon('chat', 16) ?> Forum Kursus</a>
        <a class="btn btn-ghost btn-sm" href="siswa-kalender.php"><?= icon('calendar', 16) ?> Jadwal Belajar</a>
      </div>
    </div>
  </aside>
</div>
<?php page_end(); ?>
