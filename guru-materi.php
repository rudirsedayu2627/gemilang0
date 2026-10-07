<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login(['guru', 'admin']);
require_once __DIR__ . '/includes/xlsx.php';
require_once __DIR__ . '/includes/infografis.php';
$scope = $u['role'] === 'admin' ? '1=1' : ('guru_id = ' . (int)$u['id']);

$courses = db()->query("SELECT id, judul FROM courses WHERE $scope ORDER BY judul")->fetchAll();
$kursusId = num('kursus', (int)($courses[0]['id'] ?? 0));
$kursus = null;
foreach ($courses as $c) {
    if ((int)$c['id'] === $kursusId) {
        $kursus = $c;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $cid = (int)post('course_id');
    $ok = false;
    foreach ($courses as $c) {
        if ((int)$c['id'] === $cid) {
            $ok = true;
        }
    }
    if ($ok) {
        if ($aksi === 'simpan_modul') {
            $id = (int)post('id');
            if ($id > 0) {
                db()->prepare('UPDATE modules SET judul=?, deskripsi=?, urutan=? WHERE id=? AND course_id=?')
                    ->execute([post('judul'), post('deskripsi'), (int)post('urutan'), $id, $cid]);
            } else {
                db()->prepare('INSERT INTO modules (course_id, judul, deskripsi, urutan) VALUES (?,?,?,?)')
                    ->execute([$cid, post('judul'), post('deskripsi'), (int)post('urutan')]);
            }
            audit('simpan_modul', 'Menyimpan modul ' . post('judul'));
            flash('Modul disimpan.');
        }
        if ($aksi === 'hapus_modul') {
            db()->prepare('UPDATE lessons SET module_id = NULL WHERE module_id = ?')->execute([(int)post('id')]);
            db()->prepare('DELETE FROM modules WHERE id=? AND course_id=?')->execute([(int)post('id'), $cid]);
            flash('Modul dihapus (materi dipindah ke Materi Umum).');
        }
        if ($aksi === 'simpan_materi') {
            $id = (int)post('id');
            $tipe = in_array(post('tipe'), tipe_materi_valid(), true) ? post('tipe') : 'teks';
            $mediaUrl = post('media_url');
            $embed = post('media_embed');
            // Isi infografis (hanya dipakai bila tipe materi = Infografis).
            $infoTeks = infografis_bersihkan(post('infografis'));
            $uploadError = '';
            if (!empty($_FILES['file']['name'])) {
                try {
                    $mediaUrl = handle_upload('file');
                    $embed = '';
                } catch (Throwable $e) {
                    $uploadError = $e->getMessage();
                }
            }
            if ($tipe === 'video' && $mediaUrl !== '' && preg_match('~(youtube|youtu\.be|vimeo)~', $mediaUrl)) {
                $embed = embed_url($mediaUrl);
                $mediaUrl = '';
            }
            if ($uploadError) {
                flash('Upload gagal: ' . $uploadError, 'danger');
            } elseif ($embed !== '' || $mediaUrl !== '' || post('isi_teks') !== '' || $infoTeks !== '') {
                $args = [post('module_id') ?: null, $cid, post('judul'), $tipe, $mediaUrl, $embed, post('isi_teks'), $infoTeks, (int)post('durasi_menit'), (int)post('urutan'), post('is_preview') ? 1 : 0, post('bisa_download') ? 1 : 0];
                if ($id > 0) {
                    $args[] = $id;
                    $args[] = $cid;
                    db()->prepare('UPDATE lessons SET module_id=?, course_id=?, judul=?, tipe=?, media_url=?, media_embed=?, isi_teks=?, infografis=?, durasi_menit=?, urutan=?, is_preview=?, bisa_download=? WHERE id=? AND course_id=?')->execute($args);
                } else {
                    db()->prepare('INSERT INTO lessons (module_id, course_id, judul, tipe, media_url, media_embed, isi_teks, infografis, durasi_menit, urutan, is_preview, bisa_download) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($args);
                }
                notify_course($cid, 'Materi baru: ' . post('judul'), 'Materi baru sudah ditambahkan pada kursus kamu.', 'materi.php?kursus=' . $cid);
                audit('simpan_materi', 'Menyimpan materi ' . post('judul'));
                flash('Materi disimpan.');
            } else {
                flash('Materi perlu isi teks atau berkas/link.', 'warning');
            }
        }
        if ($aksi === 'hapus_materi') {
            // Quiz yang ditampilkan di materi ini dilepas dulu (tidak ikut terhapus,
            // tetap ada di daftar quiz kursus).
            db()->prepare('UPDATE quizzes SET lesson_id = NULL WHERE lesson_id = ?')->execute([(int)post('id')]);
            db()->prepare('DELETE FROM lessons WHERE id=? AND course_id=?')->execute([(int)post('id'), $cid]);
            db()->prepare('DELETE FROM lesson_progress WHERE lesson_id=?')->execute([(int)post('id')]);
            // Riwayat obrolan AI materi ini ikut dibuang (materi sudah tidak ada).
            db()->prepare('DELETE FROM ai_messages WHERE lesson_id=?')->execute([(int)post('id')]);
            flash('Materi dihapus.');
            audit('hapus_materi', 'Menghapus materi id ' . (int)post('id'));
        }
        if ($aksi === 'import_materi') {
            try {
                $rows = [];
                if (!empty($_FILES['file']['name'])) {
                    $tmp = $_FILES['file']['tmp_name'];
                    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                    $rows = $ext === 'csv' ? csv_read($tmp) : xlsx_read($tmp);
                }
                $n = 0;
                $urutan = (int)db()->query('SELECT COALESCE(MAX(urutan),0) FROM lessons WHERE course_id = ' . $cid)->fetchColumn();
                foreach ($rows as $i => $r) {
                    if ($i === 0 || !isset($r[0]) || trim((string)$r[0]) === '') {
                        continue;
                    }
                    $urutan++;
                    $tipeImport = in_array($r[1] ?? '', tipe_materi_valid(), true) ? $r[1] : 'teks';
                    db()->prepare('INSERT INTO lessons (course_id, judul, tipe, media_url, isi_teks, durasi_menit, urutan, is_preview, bisa_download) VALUES (?,?,?,?,?,?,?,0,1)')
                        ->execute([$cid, trim((string)$r[0]), $tipeImport, trim((string)($r[2] ?? '')), trim((string)($r[3] ?? '')), (int)($r[4] ?? 10), $urutan]);
                    $n++;
                }
                flash($n . ' materi berhasil diimport.');
                audit('import_materi', 'Import ' . $n . ' materi ke kursus ' . $cid);
            } catch (Throwable $e) {
                flash('Import gagal: ' . $e->getMessage(), 'danger');
            }
        }
    }
    redirect('guru-materi.php?kursus=' . $cid);
}

if (get('template') === 'materi') {
    xlsx_download('template-materi.xlsx', [
        ['JUDUL MATERI', 'TIPE (video/pdf/audio/teks/tautan)', 'URL MEDIA', 'ISI TEKS', 'DURASI MENIT'],
        ['Video: Perkenalan Tenses', 'video', 'https://www.youtube.com/watch?v=xxxx', 'Penjelasan singkat video.', '8'],
        ['E-book: Rangkuman Grammar', 'pdf', 'https://contoh.com/rangkuman.pdf', '', '10'],
        ['Materi Teks: Simple Present', 'teks', '', 'Rumus: Subject + V1 (+s/es).', '5'],
    ]);
}

$modules = $kursus ? db()->query('SELECT * FROM modules WHERE course_id = ' . (int)$kursusId . ' ORDER BY urutan, id')->fetchAll() : [];
$lessons = $kursus ? db()->query('SELECT * FROM lessons WHERE course_id = ' . (int)$kursusId . ' ORDER BY urutan, id')->fetchAll() : [];
$byModule = [];
$orphan = [];
foreach ($lessons as $l) {
    if ($l['module_id']) {
        $byModule[(int)$l['module_id']][] = $l;
    } else {
        $orphan[] = $l;
    }
}
$totalMenit = array_sum(array_map(function ($l) { return (int)$l['durasi_menit']; }, $lessons));

// Diskusi materi: jumlah komentar per materi + materi yang komentar terakhirnya
// masih dari siswa (butuh balasan guru). Dipakai tombol Diskusi & kartu di bawah.
$jmlKomentarPerLesson = [];
$perluJawab = [];
$diskusi = [];
if ($kursus) {
    $idLessons = 'SELECT id FROM lessons WHERE course_id = ' . (int)$kursusId;
    foreach (db()->query("SELECT lesson_id, COUNT(*) AS jml FROM lesson_comments WHERE lesson_id IN ($idLessons) GROUP BY lesson_id") as $r) {
        $jmlKomentarPerLesson[(int)$r['lesson_id']] = (int)$r['jml'];
    }
    // id komentar terbesar = komentar terbaru pada materi itu
    foreach (db()->query("SELECT k.lesson_id FROM lesson_comments k JOIN users us ON us.id = k.user_id
        WHERE k.lesson_id IN ($idLessons) AND us.role = 'siswa'
        AND k.id = (SELECT MAX(k2.id) FROM lesson_comments k2 WHERE k2.lesson_id = k.lesson_id)") as $r) {
        $perluJawab[(int)$r['lesson_id']] = true;
    }
    $diskusi = db()->query("SELECT k.id, k.isi, k.created_at, us.nama, us.role, us.foto, l.id AS lesson_id, l.judul AS materi
        FROM lesson_comments k JOIN lessons l ON l.id = k.lesson_id LEFT JOIN users us ON us.id = k.user_id
        WHERE l.course_id = " . (int)$kursusId . "
        ORDER BY k.created_at DESC, k.id DESC LIMIT 10")->fetchAll();
}

page_start('Modul & Materi', 'Susun modul, lesson, dan unggah video/PDF/audio/gambar atau tempel link.', [
    'actions' => '<a class="btn btn-ghost" href="guru-materi.php?template=materi">' . icon('download', 17) . ' Template Import</a>'
        . '<button class="btn btn-ghost" data-modal-open="modal-import">' . icon('upload', 17) . ' Import Excel/CSV</button>'
        . ($kursus ? '<button class="btn btn-ghost" data-modal-open="modal-modul">' . icon('folder', 17) . ' Modul Baru</button>'
            . '<button class="btn btn-primary" data-modal-open="modal-materi">' . icon('plus', 17) . ' Materi Baru</button>' : ''),
]);
?>
<div class="card">
  <form class="inline-form">
    <div class="form-group" style="min-width:280px">
      <label>Pilih kursus</label>
      <select name="kursus" data-autosubmit>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($kursus): ?>
      <div class="stat" style="flex:1">
        <div class="stat-ic g1"><?= icon('layers', 20) ?></div>
        <div><div class="stat-num"><?= count($lessons) ?></div><div class="stat-lab">materi &middot; <?= count($modules) ?> modul &middot; ±<?= $totalMenit ?> menit</div></div>
      </div>
    <?php endif; ?>
  </form>
</div>

<?php if (!$courses): ?>
  <div class="card"><?= empty_state('Belum ada kursus. Buat kursus dulu di menu Kelola Kursus.', 'book') ?>
  <div class="center"><a class="btn btn-primary" href="guru-kursus.php">Kelola Kursus</a></div></div>
<?php endif; ?>

<?php if ($kursus): ?>
  <div class="card">
    <div class="card-head">
      <div>
        <h3 class="mt0 mb0"><?= icon('chat', 18) ?> Diskusi Materi (<?= array_sum($jmlKomentarPerLesson) ?>)</h3>
        <div class="muted small">Pertanyaan &amp; komentar siswa pada materi kursus ini. Klik untuk membuka diskusinya dan membalas.</div>
      </div>
      <?php if ($perluJawab): ?><span class="badge badge-warning"><?= count($perluJawab) ?> materi belum dijawab</span><?php endif; ?>
    </div>
    <?php
    $sudahTampil = [];
    foreach ($diskusi as $d):
        $lid = (int)$d['lesson_id'];
        $terbaruMateri = !isset($sudahTampil[$lid]);
        $sudahTampil[$lid] = true;
        $butuhJawaban = $terbaruMateri && !empty($perluJawab[$lid]);
        ?>
      <a class="post" href="materi.php?id=<?= $lid ?>#komentar" style="text-decoration:none;color:inherit">
        <span class="avatar sm" <?= $d['foto'] ? 'style="background-image:url(' . e($d['foto']) . ')"' : '' ?>><?= $d['foto'] ? '' : e(strtoupper(mb_substr((string)($d['nama'] ?? 'U'), 0, 1))) ?></span>
        <div class="post-body">
          <div class="post-meta">
            <strong><?= e((string)($d['nama'] ?? 'Pengguna')) ?></strong>
            <?= in_array($d['role'] ?? '', ['guru', 'admin'], true) ? '<span class="badge badge-info">Pengajar</span>' : '' ?>
            <span><?= e(tgl_id($d['created_at'], true)) ?></span>
            <span><?= icon('file', 13) ?> <?= e((string)$d['materi']) ?></span>
          </div>
          <p style="margin:0" class="muted small"><?= e(mb_strimwidth((string)$d['isi'], 0, 160, '...')) ?></p>
        </div>
        <?php if ($butuhJawaban): ?><span class="badge badge-warning" style="align-self:center">Belum dijawab</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <?php if (!$diskusi): ?><p class="muted small mb0">Belum ada pertanyaan siswa pada materi kursus ini.</p><?php endif; ?>
  </div>
<?php endif; ?>

<?php foreach ($modules as $m): ?>
  <div class="card">
    <div class="card-head">
      <div>
        <h3 class="mt0 mb0"><?= icon('folder', 18) ?> <?= e($m['judul']) ?></h3>
        <div class="muted small"><?= count($byModule[(int)$m['id']] ?? []) ?> materi<?= $m['deskripsi'] ? ' &middot; ' . e($m['deskripsi']) : '' ?></div>
      </div>
      <div class="row-gap">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="hapus_modul">
          <input type="hidden" name="course_id" value="<?= (int)$kursusId ?>">
          <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
          <button class="btn btn-danger btn-sm" data-confirm="Hapus modul ini? Materi di dalamnya tetap ada di Materi Umum."><?= icon('trash', 14) ?></button>
        </form>
      </div>
    </div>
    <?php foreach ($byModule[(int)$m['id']] ?? [] as $l): ?>
      <div class="lesson-item mb2">
        <span class="lesson-ic <?= e($l['tipe']) ?>"><?= icon(tipe_materi_ikon($l['tipe']), 17) ?></span>
        <span class="lesson-meta">
          <strong><?= e($l['judul']) ?></strong>
          <small><?= e(tipe_materi_label($l['tipe'])) ?><?= $l['durasi_menit'] ? ' &middot; ' . (int)$l['durasi_menit'] . ' menit' : '' ?><?= (int)$l['is_preview'] ? ' &middot; preview gratis' : '' ?><?= $l['media_url'] ? ' &middot; berkas terunggah' : ($l['media_embed'] ? ' &middot; embed link' : '') ?></small>
        </span>
        <span style="margin-left:auto" class="row-gap">
          <?php $butuh = !empty($perluJawab[(int)$l['id']]); ?>
          <?php if ($butuh): ?><span class="badge badge-warning">Belum dijawab</span><?php endif; ?>
          <a class="btn btn-sm <?= $butuh ? 'btn-primary' : 'btn-ghost' ?>" href="materi.php?id=<?= (int)$l['id'] ?>#komentar" title="Buka diskusi materi ini"><?= icon('chat', 14) ?> Diskusi<?= isset($jmlKomentarPerLesson[(int)$l['id']]) ? ' (' . (int)$jmlKomentarPerLesson[(int)$l['id']] . ')' : '' ?></a>
          <?php if ($l['media_url'] || $l['media_embed']): ?><a class="btn btn-ghost btn-sm" href="<?= e($l['media_url'] ?: $l['media_embed']) ?>" target="_blank" rel="noopener"><?= icon('link', 14) ?></a><?php endif; ?>
          <a class="btn btn-ghost btn-sm" href="guru-materi.php?kursus=<?= (int)$kursusId ?>&edit_materi=<?= (int)$l['id'] ?>&tipe=infografis&susun=1" title="Buat/ubah infografis materi ini (bacaan dibaca otomatis oleh AI)"><?= icon('chart', 14) ?> Infografis</a>
          <a class="btn btn-ghost btn-sm" href="guru-materi.php?kursus=<?= (int)$kursusId ?>&edit_materi=<?= (int)$l['id'] ?>"><?= icon('edit', 14) ?></a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="hapus_materi">
            <input type="hidden" name="course_id" value="<?= (int)$kursusId ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-danger btn-sm" data-confirm="Hapus materi ini?"><?= icon('trash', 14) ?></button>
          </form>
        </span>
      </div>
    <?php endforeach; ?>
    <?php if (!($byModule[(int)$m['id']] ?? [])): ?><p class="muted small mb0">Belum ada materi pada modul ini.</p><?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($orphan): ?>
  <div class="card">
    <div class="card-head"><h3 class="mt0 mb0"><?= icon('file', 18) ?> Materi Umum (tanpa modul)</h3></div>
    <?php foreach ($orphan as $l): ?>
      <div class="lesson-item mb2">
        <span class="lesson-ic <?= e($l['tipe']) ?>"><?= icon(tipe_materi_ikon($l['tipe']), 17) ?></span>
        <span class="lesson-meta"><strong><?= e($l['judul']) ?></strong><small><?= e(tipe_materi_label($l['tipe'])) ?></small></span>
        <span style="margin-left:auto" class="row-gap">
          <?php $butuh = !empty($perluJawab[(int)$l['id']]); ?>
          <?php if ($butuh): ?><span class="badge badge-warning">Belum dijawab</span><?php endif; ?>
          <a class="btn btn-sm <?= $butuh ? 'btn-primary' : 'btn-ghost' ?>" href="materi.php?id=<?= (int)$l['id'] ?>#komentar" title="Buka diskusi materi ini"><?= icon('chat', 14) ?> Diskusi<?= isset($jmlKomentarPerLesson[(int)$l['id']]) ? ' (' . (int)$jmlKomentarPerLesson[(int)$l['id']] . ')' : '' ?></a>
          <a class="btn btn-ghost btn-sm" href="guru-materi.php?kursus=<?= (int)$kursusId ?>&edit_materi=<?= (int)$l['id'] ?>&tipe=infografis&susun=1" title="Buat/ubah infografis materi ini (bacaan dibaca otomatis oleh AI)"><?= icon('chart', 14) ?> Infografis</a>
          <a class="btn btn-ghost btn-sm" href="guru-materi.php?kursus=<?= (int)$kursusId ?>&edit_materi=<?= (int)$l['id'] ?>"><?= icon('edit', 14) ?></a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="hapus_materi">
            <input type="hidden" name="course_id" value="<?= (int)$kursusId ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-danger btn-sm" data-confirm="Hapus materi ini?"><?= icon('trash', 14) ?></button>
          </form>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
$editMateri = null;
if (num('edit_materi') > 0 && $kursus) {
    $s = db()->prepare('SELECT * FROM lessons WHERE id = ? AND course_id = ?');
    $s->execute([num('edit_materi'), $kursusId]);
    $editMateri = $s->fetch();
}
// Tipe materi yang dipilih di form: bisa dipaksa lewat URL (tombol "Jadikan
// infografis" pada daftar materi mengarah ke sini dengan ?tipe=infografis&susun=1).
$tipeEdit = (string)($editMateri['tipe'] ?? '');
if (get('tipe') !== '' && in_array(get('tipe'), tipe_materi_valid(), true)) {
    $tipeEdit = get('tipe');
}
$infoTampil = $tipeEdit === 'infografis';
$infoAuto = $infoTampil && get('susun') === '1';
?>
<div class="modal-back <?= $editMateri ? 'open' : '' ?>" id="modal-materi">
  <div class="modal">
    <div class="modal-head">
      <h3 class="mt0"><?= $editMateri ? 'Ubah Materi' : 'Materi Baru' ?></h3>
      <button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan_materi">
      <input type="hidden" name="course_id" value="<?= (int)$kursusId ?>">
      <input type="hidden" name="id" value="<?= $editMateri ? (int)$editMateri['id'] : 0 ?>">
      <div class="form-group">
        <label>Judul materi *</label>
        <input type="text" name="judul" value="<?= e($editMateri['judul'] ?? '') ?>" required>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label>Modul</label>
          <select name="module_id">
            <option value="">- Materi Umum -</option>
            <?php foreach ($modules as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= (int)($editMateri['module_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['judul']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Tipe materi</label>
          <select name="tipe" data-tipe-materi>
            <?php foreach (tipe_materi_options() as $k => $v): ?>
              <option value="<?= $k ?>" <?= $tipeEdit === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Durasi (menit)</label>
          <input type="number" name="durasi_menit" value="<?= (int)($editMateri['durasi_menit'] ?? 10) ?>" min="0">
        </div>
        <div class="form-group">
          <label>Urutan</label>
          <input type="number" name="urutan" value="<?= (int)($editMateri['urutan'] ?? (count($lessons) + 1)) ?>">
        </div>
      </div>

      <div class="card tight flat">
        <h4>Opsi A — Unggah berkas (video/pdf/audio/gambar)</h4>
        <input type="file" name="file" accept="video/mp4,video/webm,audio/*,.pdf,image/*">
        <div class="form-hint">Batas ukuran: gambar/dokumen 15–50MB, video 300MB. Bisa juga tempel link di bawah sebagai gantinya.</div>
      </div>
      <div class="card tight flat">
        <h4>Opsi B — Tempel link / embed</h4>
        <div class="form-group">
          <label>URL media (YouTube, Vimeo, Google Drive, dsb.)</label>
          <input type="url" name="media_url" value="<?= e($editMateri['media_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=...">
        </div>
        <div class="form-group">
          <label>atau kode embed iframe</label>
          <input type="text" name="media_embed" value="<?= e($editMateri['media_embed'] ?? '') ?>" placeholder="https://www.youtube.com/embed/...">
        </div>
      </div>

      <div class="form-group">
        <label>Isi teks / ringkasan materi</label>
        <textarea name="isi_teks" style="min-height:140px"><?= e($editMateri['isi_teks'] ?? '') ?></textarea>
      </div>

      <?php
      // Panel khusus tipe materi "Infografis": isi ringkas berformat baris +
      // tombol menyusunnya otomatis dari bacaan (berkas/isi teks materi).
      $infoTeks = (string)($editMateri['infografis'] ?? '');
      ?>
      <div class="card tight flat" data-panel-tipe="infografis" <?= $infoTampil ? '' : 'hidden' ?><?= $infoAuto ? ' data-info-auto="1"' : '' ?>>
        <h4 class="mt0"><?= icon('chart', 17) ?> Isi Infografis</h4>
        <p class="form-hint mb2">Ringkasan visual materi ini: kartu angka, poin penting, langkah, dan sorotan. Untuk materi bacaan (PDF/e-book/scan halaman) yang sudah diunggah, cukup tekan <strong>Susun dengan AI</strong> — bacaan materinya dibaca otomatis, hasilnya bisa kamu sunting dulu sebelum disimpan.</p>
        <div class="row-gap mb2">
          <button type="button" class="btn btn-primary btn-sm" data-info-susun <?= ai_aktif() && ai_siap() ? '' : 'disabled' ?>><?= icon('sparkle', 16) ?> Susun dengan AI</button>
          <select data-info-sumber style="max-width:250px">
            <option value="materi">Bacaan materi ini</option>
            <option value="teks">Teks yang saya tempel</option>
            <option value="berkas">Berkas yang saya pilih</option>
          </select>
        </div>
        <?php if (!ai_aktif() || !ai_siap()): ?>
          <div class="form-hint mb2">Penyusunan otomatis belum bisa dipakai (fitur Tanya AI nonaktif atau kunci API belum diisi di Pengaturan → AI). Isi infografisnya manual dengan format di bawah.</div>
        <?php endif; ?>
        <div class="form-group" data-info-kotak="teks" hidden>
          <label>Teks bacaan untuk disusun</label>
          <textarea data-info-teks placeholder="Tempel teks bacaan di sini, lalu tekan Susun dengan AI..." style="min-height:90px"></textarea>
        </div>
        <div class="form-group" data-info-kotak="berkas" hidden>
          <label>Berkas bacaan (PDF, gambar hasil scan, teks, epub/docx)</label>
          <input type="file" data-info-berkas accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.txt,.md,.html,.csv,.epub,.docx">
          <div class="form-hint">Berkas ini hanya dipakai untuk menyusun infografis (tidak ikut tersimpan ke materi). Batas ukuran berkas di server ini <?= e(ini_get('post_max_size') ?: '8M') ?> — untuk halaman buku, kirim satu per satu.</div>
        </div>
        <div class="form-group">
          <label>Isi infografis (format baris)</label>
          <textarea name="infografis" data-info-isi style="min-height:180px" placeholder="Tekan Susun dengan AI, atau tulis sendiri dengan format di bawah..."><?= e($infoTeks) ?></textarea>
        </div>
        <details class="form-hint">
          <summary>Panduan format isi infografis</summary>
          <pre class="info-format"><?= e(infografis_format_contoh()) ?></pre>
          <p class="mb0">Baris diawali <code>#</code> = judul, <code>&gt;</code> = kalimat pengantar, <code>##</code> = judul bagian, <code>* 75% | label</code> = kartu angka, <code>- Judul :: penjelasan</code> = kartu poin, <code>1.</code> = langkah bernomor, <code>!!</code> = kotak sorotan, <code>| a | b |</code> = baris tabel.</p>
        </details>
        <div class="form-hint" data-info-status></div>
      </div>
      <div class="row-gap mb3">
        <label class="check"><input type="checkbox" name="is_preview" value="1" <?= (int)($editMateri['is_preview'] ?? 0) ? 'checked' : '' ?>> Bisa dicoba gratis (preview)</label>
        <label class="check"><input type="checkbox" name="bisa_download" value="1" <?= (int)($editMateri['bisa_download'] ?? 1) ? 'checked' : '' ?>> Izinkan diunduh siswa</label>
      </div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Materi</button>
        <a class="btn btn-ghost" href="guru-materi.php?kursus=<?= (int)$kursusId ?>">Batal</a>
      </div>
    </form>
  </div>
</div>

<div class="modal-back" id="modal-modul">
  <div class="modal" style="max-width:520px">
    <div class="modal-head"><h3 class="mt0">Modul Baru</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan_modul">
      <input type="hidden" name="course_id" value="<?= (int)$kursusId ?>">
      <div class="form-group"><label>Judul modul *</label><input type="text" name="judul" required></div>
      <div class="form-group"><label>Deskripsi</label><input type="text" name="deskripsi"></div>
      <div class="form-group"><label>Urutan</label><input type="number" name="urutan" value="<?= count($modules) + 1 ?>"></div>
      <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Modul</button>
    </form>
  </div>
</div>

<div class="modal-back" id="modal-import">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0">Import Materi dari Excel/CSV</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="import_materi">
      <input type="hidden" name="course_id" value="<?= (int)$kursusId ?>">
      <div class="form-group">
        <label>Berkas .xlsx atau .csv *</label>
        <input type="file" name="file" accept=".xlsx,.csv" required>
        <div class="form-hint">Format kolom: JUDUL MATERI, TIPE, URL MEDIA, ISI TEKS, DURASI MENIT. Baris pertama dianggap header.</div>
      </div>
      <a class="btn btn-ghost btn-sm mb2" href="guru-materi.php?template=materi"><?= icon('download', 15) ?> Unduh template</a>
      <div><button class="btn btn-primary" type="submit"><?= icon('upload', 17) ?> Import</button></div>
    </form>
  </div>
</div>
<?php page_end(); ?>
