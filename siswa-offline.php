<?php
/**
 * Halaman "Materi Offline" (mode luring).
 *
 * Aplikasi Android (glc-android/) menyimpan halaman quiz & materi beserta
 * asetnya di perangkat, sehingga keduanya bisa dibuka tanpa internet. Halaman ini
 * adalah pusatnya: daftar yang bisa diunduh, apa yang sudah tersimpan di
 * perangkat ini, dan jawaban ujian yang masih menunggu dikirim.
 *
 * Daftar quiz & materi dirender server (PHP) supaya halaman ini pun tetap utuh
 * saat dibuka dari salinan offline; interaksinya (unduh/hapus/kirim) ada di
 * assets/app.js bagian "halaman Materi Offline", lewat api-offline.php.
 * Tanpa jembatan aplikasi (dibuka di peramban) halaman tetap tampil, hanya
 * tombol unduhnya yang mengarahkan pengguna memakai aplikasi Android.
 */
require_once __DIR__ . '/includes/layout.php';
$u = require_login();
$staf = in_array($u['role'], ['guru', 'admin'], true);

/* ------------------------------------------------- kursus yang bisa dibuka */
$kursusSaya = [];
foreach (db()->query('SELECT * FROM courses ORDER BY judul')->fetchAll() as $c) {
    if (!$staf) {
        $ak = cek_akses_kursus((int)$u['id'], ['id' => (int)$c['id'], 'harga' => (float)$c['harga']]);
        if (empty($ak['boleh'])) {
            continue;
        }
    }
    $kursusSaya[] = $c;
}
$idKursus = array_map(function ($c) { return (int)$c['id']; }, $kursusSaya);

/* --------------------------------------------------------- quiz yang bisa */
$jmlSoal = quiz_jml_soal_map(0);
$quizLuring = [];
foreach ($kursusSaya as $c) {
    $st = db()->prepare('SELECT * FROM quizzes WHERE course_id = ? ORDER BY judul');
    $st->execute([(int)$c['id']]);
    foreach ($st->fetchAll() as $z) {
        $jml = (int)($jmlSoal[(int)$z['id']] ?? 0);
        if ($jml === 0) {
            continue;
        }
        $z['kursus'] = $c['judul'];
        $z['jml_soal'] = $jml;
        $quizLuring[] = $z;
    }
}

/* ------------------------------------------------------- materi yang bisa */
$materiLuring = [];
if ($idKursus) {
    $materiLuring = db()->query('SELECT l.id, l.judul, l.tipe, l.is_preview, l.urutan, co.judul AS kursus
        FROM lessons l JOIN courses co ON co.id = l.course_id
        WHERE l.course_id IN (' . implode(',', $idKursus) . ')
        ORDER BY co.judul, l.urutan, l.id')->fetchAll();
}

$adaKursus = count($kursusSaya) > 0;
page_start('Materi Offline', 'Kerjakan ujian & baca materi tanpa internet — dari aplikasi Android.');
?>
<div id="luring-app" data-csrf="<?= e(csrf_token()) ?>">

  <div class="card">
    <div class="card-head">
      <h3 class="mt0"><?= icon('cloud-off', 19) ?> Belajar tanpa internet</h3>
      <span class="chip"><?= icon('download', 14) ?> Hanya di aplikasi Android</span>
    </div>
    <p class="muted small">Unduh dulu saat ada internet, lalu kerjakan kapan saja — di jalan, di rumah, atau saat
      sinyalnya hilang. Jawaban ujian otomatis dikirim begitu perangkat kembali online.</p>
    <div class="luring-langkah">
      <div class="luring-step"><span class="luring-step-n">1</span><span>Tekan <strong>Unduh offline</strong> pada quiz atau materi di bawah.</span></div>
      <div class="luring-step"><span class="luring-step-n">2</span><span>Buka lewat aplikasi walau tidak ada internet — timer ujian tetap berjalan.</span></div>
      <div class="luring-step"><span class="luring-step-n">3</span><span>Begitu online, jawabanmu terkirim otomatis dan dinilai seperti biasa.</span></div>
    </div>
    <div data-luring-status class="mt3"></div>
    <div data-luring-pesan></div>
  </div>

  <div class="split-even">
    <div class="card">
      <div class="card-head">
        <h3 class="mt0"><?= icon('download', 18) ?> Tersimpan di perangkat ini</h3>
        <button type="button" class="btn btn-ghost btn-sm" data-luring-periksa><?= icon('refresh', 15) ?> Periksa ulang</button>
      </div>
      <div data-luring-tersimpan>
        <p class="muted small mb0">Memuat…</p>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h3 class="mt0"><?= icon('cloud', 18) ?> Jawaban menunggu dikirim (<span data-luring-hitung>0</span>)</h3>
        <button type="button" class="btn btn-primary btn-sm" data-luring-kirim hidden><?= icon('send', 15) ?> Kirim sekarang</button>
      </div>
      <div data-luring-antre>
        <p class="muted small mb0">Memuat…</p>
      </div>
      <p class="muted small mt3 mb0">Jawaban yang dikerjakan tanpa internet aman tersimpan di perangkat. Pengiriman
        otomatis terjadi begitu ada koneksi — kamu juga bisa menekan "Kirim sekarang".</p>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h3 class="mt0"><?= icon('quiz', 18) ?> Quiz &amp; Ujian bisa dikerjakan offline</h3>
      <?php if ($quizLuring): ?>
        <input type="search" data-filter-input="#tbl-luring-quiz" placeholder="Cari quiz..." class="luring-cari">
      <?php endif; ?>
    </div>
    <?php if (!$adaKursus): ?>
      <?= empty_state('Belum ada kursus yang terbuka untukmu.', 'book') ?>
    <?php elseif (!$quizLuring): ?>
      <?= empty_state('Belum ada quiz berisi soal di kursusmu.', 'quiz') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data" id="tbl-luring-quiz">
          <thead><tr><th>Quiz</th><th>Kursus</th><th>Soal</th><th>Waktu</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php foreach ($quizLuring as $z): ?>
            <tr>
              <td><strong><?= e($z['judul']) ?></strong>
                <div class="muted small"><?= (int)$z['random_soal'] && (int)$z['jumlah_soal_tampil'] > 0
                    ? 'Diambil ' . (int)$z['jumlah_soal_tampil'] . ' soal acak dari ' . (int)$z['jml_soal']
                    : (int)$z['jml_soal'] . ' soal' ?> &middot; nilai minimal <?= (int)$z['passing_grade'] ?></div>
              </td>
              <td class="small"><?= e($z['kursus']) ?></td>
              <td class="small"><?= (int)$z['jml_soal'] ?></td>
              <td class="small"><?= (int)$z['durasi_menit'] ?> menit</td>
              <td>
                <div class="row-gap">
                  <button type="button" class="btn btn-primary btn-sm" data-luring-quiz="<?= (int)$z['id'] ?>"
                          data-judul="<?= e($z['judul']) ?>"><?= icon('download', 15) ?> Unduh offline</button>
                  <span class="luring-tanda" data-luring-tanda="quiz:<?= (int)$z['id'] ?>"></span>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="muted small mt3 mb0">Tanda "Tersimpan" muncul setelah quiz benar-benar tersimpan di perangkat
        (lewat aplikasi). Unduhan dihitung sebagai satu sesi ujian — sama seperti menekan "Mulai Kerjakan".</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head">
      <h3 class="mt0"><?= icon('book', 18) ?> Materi bisa dibaca offline</h3>
      <?php if ($materiLuring): ?>
        <input type="search" data-filter-input="#tbl-luring-materi" placeholder="Cari materi..." class="luring-cari">
      <?php endif; ?>
    </div>
    <?php if (!$materiLuring): ?>
      <?= empty_state('Belum ada materi di kursusmu.', 'book') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data" id="tbl-luring-materi">
          <thead><tr><th>Materi</th><th>Kursus</th><th>Jenis</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php foreach ($materiLuring as $m):
              $url = 'materi.php?id=' . (int)$m['id']; ?>
            <tr>
              <td><strong><?= e($m['judul']) ?></strong></td>
              <td class="small"><?= e($m['kursus']) ?></td>
              <td class="small"><?= e(tipe_materi_label($m['tipe'])) ?></td>
              <td>
                <div class="row-gap">
                  <a class="btn btn-ghost btn-sm" href="<?= e($url) ?>"><?= icon('play', 14) ?> Buka</a>
                  <button type="button" class="btn btn-primary btn-sm" data-luring-unduh="<?= e($url) ?>"
                          data-judul="<?= e($m['judul']) ?>"><?= icon('download', 15) ?> Unduh offline</button>
                  <span class="luring-tanda" data-luring-tanda="<?= e($url) ?>"></span>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="muted small mt3 mb0">Teks dan gambar materi ikut tersimpan. Berkas video/PDF berukuran besar tetap
        perlu internet, jadi unduh materi berupa teks atau gambar bila ingin dibaca sepenuhnya offline.</p>
    <?php endif; ?>
  </div>
</div>
<?php page_end(); ?>
