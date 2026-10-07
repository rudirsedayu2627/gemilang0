<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');
require_once __DIR__ . '/includes/xlsx.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    if ($aksi === 'website') {
        foreach (['site_name', 'site_tagline', 'site_deskripsi', 'site_email', 'site_telepon', 'site_alamat', 'warna_utama', 'warna_kedua', 'cert_prefix', 'cert_penandatangan', 'cert_catatan'] as $k) {
            if (isset($_POST[$k])) {
                set_setting($k, post($k));
            }
        }
        set_setting('registrasi_terbuka', post('registrasi_terbuka') ? '1' : '0');
        set_setting('mode_pemeliharaan', post('mode_pemeliharaan') ? '1' : '0');
        set_setting('sertifikat_aktif', post('sertifikat_aktif') ? '1' : '0');
        if (!empty($_FILES['logo']['name'])) {
            try {
                set_setting('site_logo', handle_upload('logo'));
                flash('Logo baru berhasil diunggah.');
            } catch (Throwable $e) {
                flash('Upload logo gagal: ' . $e->getMessage(), 'danger');
            }
        }
        if (post('logo_url') !== '' && empty($_FILES['logo']['name'])) {
            set_setting('site_logo', post('logo_url'));
        }
        if (post('hapus_logo')) {
            set_setting('site_logo', '');
        }
        audit('pengaturan_website', 'Memperbarui pengaturan website');
        flash('Pengaturan website disimpan.');
        redirect('admin-pengaturan.php');
    }
    if ($aksi === 'smtp') {
        foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_from', 'smtp_from_nama'] as $k) {
            set_setting($k, post($k));
        }
        if (post('smtp_pass') !== '') {
            set_setting('smtp_pass', post('smtp_pass'));
        }
        set_setting('smtp_enabled', post('smtp_enabled') ? '1' : '0');
        audit('pengaturan_smtp', 'Memperbarui konfigurasi SMTP');
        flash('Konfigurasi SMTP disimpan.');
        redirect('admin-pengaturan.php#smtp');
    }
    if ($aksi === 'tes_smtp') {
        $ok = smtp_send(post('email_tujuan'), 'Tes Konfigurasi SMTP ' . setting('site_name'), '<p>Email tes dari ' . e(setting('site_name')) . '. Konfigurasi SMTP berfungsi.</p>');
        flash($ok ? 'Email tes berhasil dikirim.' : 'Email tes gagal/dilewati. Cek Log Email di bawah untuk detailnya.', $ok ? 'success' : 'warning');
        redirect('admin-pengaturan.php#smtp');
    }
    if ($aksi === 'ai') {
        $prov = post('ai_provider', 'gemini');
        set_setting('ai_aktif', post('ai_aktif') ? '1' : '0');
        set_setting('ai_nama', post('ai_nama', 'Tanya AI') !== '' ? post('ai_nama') : 'Tanya AI');
        // Teks kartu yang bisa diubah admin. Kosong = pakai teks bawaan.
        $bawaanAi = lms_default_ai_settings();
        $introAi = trim((string)post('ai_intro', ''));
        set_setting('ai_intro', $introAi !== '' ? $introAi : $bawaanAi['ai_intro']);
        $phAi = trim((string)post('ai_placeholder', ''));
        set_setting('ai_placeholder', $phAi !== '' ? $phAi : $bawaanAi['ai_placeholder']);
        set_setting('ai_provider', in_array($prov, ['gemini', 'openai'], true) ? $prov : 'gemini');
        set_setting('ai_model', trim(post('ai_model')));
        set_setting('ai_base_url', rtrim(trim(post('ai_base_url')), '/'));
        set_setting('ai_batas_harian', (string)max(0, (int)post('ai_batas_harian', '40')));
        $prompt = trim((string)post('ai_prompt'));
        set_setting('ai_prompt', $prompt !== '' ? $prompt : lms_default_ai_settings()['ai_prompt']);
        // Fitur suara (bicara + jawaban dibacakan). Memakai kemampuan peramban
        // pengguna, jadi tidak butuh kunci API/layanan tambahan.
        set_setting('ai_suara_aktif', post('ai_suara_aktif') ? '1' : '0');
        set_setting('ai_suara_otomatis', post('ai_suara_otomatis') ? '1' : '0');
        set_setting('ai_suara_live', post('ai_suara_live') ? '1' : '0');
        // Lama menunggu pengguna selesai bicara (mode percakapan).
        $jedaSuara = (int)post('ai_suara_jeda', '3000');
        if ($jedaSuara < 1000 || $jedaSuara > 10000) { $jedaSuara = 3000; }
        set_setting('ai_suara_jeda', (string)$jedaSuara);
        set_setting('ai_suara_bahasa', post('ai_suara_bahasa', 'id') === 'en' ? 'en' : 'id');
        // Kunci API: hanya ditimpa bila diisi. Nilai lama tidak pernah
        // ditampilkan kembali ke form (supaya tidak bocor ke HTML).
        if (post('ai_hapus_kunci')) {
            set_setting('ai_api_key', '');
        } elseif (trim((string)post('ai_api_key')) !== '') {
            set_setting('ai_api_key', trim((string)post('ai_api_key')));
        }
        audit('pengaturan_ai', 'Memperbarui pengaturan AI "' . post('ai_nama', 'Tanya AI') . '" (modul ' . (post('ai_aktif') ? 'aktif' : 'nonaktif') . ', penyedia ' . post('ai_provider', 'gemini') . ', suara ' . (post('ai_suara_aktif') ? 'aktif' : 'nonaktif') . ')');
        flash('Pengaturan AI disimpan.');
        redirect('admin-pengaturan.php#ai');
    }
    if ($aksi === 'ai_tes') {
        $hasil = ai_uji_koneksi();
        flash(($hasil['ok'] ? 'Koneksi AI berhasil. Jawaban uji: ' : 'Koneksi AI gagal: ') . $hasil['pesan'], $hasil['ok'] ? 'success' : 'danger');
        redirect('admin-pengaturan.php#ai');
    }
    if ($aksi === 'storage') {
        set_setting('storage_folder', trim(post('storage_folder', 'uploads'), '/'));
        set_setting('storage_driver', post('storage_driver', 'media-proxy'));
        audit('pengaturan_storage', 'Memperbarui pengaturan storage');
        flash('Pengaturan storage disimpan.');
        redirect('admin-pengaturan.php#storage');
    }
    if ($aksi === 'pembayaran') {
        set_setting('bayar_aktif', post('bayar_aktif') ? '1' : '0');
        set_setting('bayar_instruksi', post('bayar_instruksi'));
        set_setting('bayar_kontak', post('bayar_kontak'));
        // rekening: 4 slot, slot kosong dibuang
        $rekening = [];
        foreach ((array)post('rekening', []) as $slot => $r) {
            if (!is_array($r)) {
                continue;
            }
            $bank = trim((string)($r['bank'] ?? ''));
            $nomor = trim((string)($r['nomor'] ?? ''));
            $nama = trim((string)($r['nama'] ?? ''));
            if ($bank === '' && $nomor === '') {
                continue;
            }
            $rekening[] = ['bank' => $bank, 'nomor' => $nomor, 'nama' => $nama];
        }
        set_setting('bayar_rekening', json_encode($rekening));
        audit('pengaturan_pembayaran', 'Memperbarui pengaturan pembayaran (' . count($rekening) . ' rekening, modul ' . (post('bayar_aktif') ? 'aktif' : 'nonaktif') . ')');
        flash('Pengaturan pembayaran disimpan.');
        redirect('admin-pengaturan.php#pembayaran');
    }
    if ($aksi === 'buka_akses_semua' && post('konfirmasi_teks') === 'BUKA AKSES') {
        // Buka akses semua pendaftaran yang masih terkunci (mis. saat menyalakan
        // modul pembayaran untuk siswa yang sudah telanjur belajar).
        db()->exec("UPDATE enrollments SET status = 'aktif' WHERE status NOT IN ('aktif','selesai')");
        audit('buka_akses_massal', 'Membuka akses semua pendaftaran terkunci');
        flash('Semua pendaftaran terkunci kini dibuka.');
        redirect('admin-pengaturan.php#pembayaran');
    }
    if ($aksi === 'bersihkan_log') {
        db()->exec('DELETE FROM email_log WHERE created_at < date("now","-30 days")');
        db()->exec('DELETE FROM audit_logs WHERE created_at < date("now","-90 days")');
        db()->exec('DELETE FROM notifications WHERE dibaca = 1 AND created_at < date("now","-30 days")');
        audit('bersihkan_log', 'Membersihkan log lama');
        flash('Log lama (>30/90 hari) dibersihkan.');
        redirect('admin-pengaturan.php#lanjutan');
    }
}

$emailLog = db()->query('SELECT * FROM email_log ORDER BY id DESC LIMIT 10')->fetchAll();
$ukuran = [];
$dbFile = lms_db_path();
$ukuran['database'] = is_file($dbFile) ? filesize($dbFile) : 0;
$mediaDir = LMS_APP_DIR . '/' . trim(setting('storage_folder', 'uploads'), '/');
$fileMedia = 0;
$ukuranMedia = 0;
if (is_dir($mediaDir)) {
    foreach (scandir($mediaDir) as $f) {
        if ($f !== '.' && $f !== '..' && is_file($mediaDir . '/' . $f)) {
            $fileMedia++;
            $ukuranMedia += filesize($mediaDir . '/' . $f);
        }
    }
}
$ukuran['media'] = $ukuranMedia;
$ukuran['file_media'] = $fileMedia;

page_start('Pengaturan Website', 'Atur identitas situs, SMTP, penyimpanan media, dan pemeliharaan sistem.');
?>
<div class="card">
  <div class="tabs">
    <button class="tab active" data-tab="website">Website</button>
    <button class="tab" data-tab="smtp">SMTP</button>
    <button class="tab" data-tab="pembayaran">Pembayaran</button>
    <button class="tab" data-tab="ai">Tanya AI</button>
    <button class="tab" data-tab="storage">Storage</button>
    <button class="tab" data-tab="lanjutan">Pemeliharaan</button>
  </div>

  <div class="tab-panel active" id="panel-website">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="website">
      <div class="row-gap mb3">
        <?= site_logo(72) ?>
        <div style="flex:1;min-width:240px">
          <label>Logo situs</label>
          <input type="file" name="logo" accept="image/*">
          <input type="url" name="logo_url" class="mt1" placeholder="atau tempel URL logo">
          <label class="check mt1"><input type="checkbox" name="hapus_logo" value="1"> Kembalikan ke logo bawaan (seal)</label>
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group"><label>Nama situs</label><input type="text" name="site_name" value="<?= e(setting('site_name')) ?>" required></div>
        <div class="form-group"><label>Tagline</label><input type="text" name="site_tagline" value="<?= e(setting('site_tagline')) ?>"></div>
      </div>
      <div class="form-group"><label>Deskripsi situs</label><textarea name="site_deskripsi"><?= e(setting('site_deskripsi')) ?></textarea></div>
      <div class="form-grid three">
        <div class="form-group"><label>Email kontak</label><input type="email" name="site_email" value="<?= e(setting('site_email')) ?>"></div>
        <div class="form-group"><label>Telepon</label><input type="text" name="site_telepon" value="<?= e(setting('site_telepon')) ?>"></div>
        <div class="form-group"><label>Warna utama</label><input type="text" name="warna_utama" value="<?= e(setting('warna_utama', '#4F46E5')) ?>"></div>
      </div>
      <div class="form-grid">
        <div class="form-group"><label>Alamat</label><input type="text" name="site_alamat" value="<?= e(setting('site_alamat')) ?>"></div>
        <div class="form-group"><label>Warna aksen kedua</label><input type="text" name="warna_kedua" value="<?= e(setting('warna_kedua', '#8B5CF6')) ?>"></div>
      </div>
      <div class="divider"></div>
      <div class="card tight flat mb3">
        <h4 class="mt0"><?= icon('award', 18) ?> Fitur Sertifikat</h4>
        <label class="check"><input type="checkbox" name="sertifikat_aktif" value="1" <?= sertifikat_aktif() ? 'checked' : '' ?>> Aktifkan fitur sertifikat</label>
        <div class="form-hint">Saat nonaktif: menu, halaman, statistik, dan semua tautan sertifikat disembunyikan dari siswa, guru, admin, dan publik; penerbitan sertifikat (otomatis maupun manual) berhenti; verifikasi sertifikat publik ikut dimatikan. Data sertifikat yang sudah ada <strong>tidak dihapus</strong> dan muncul kembali begitu fitur dinyalakan lagi.</div>
      </div>
      <?php if (sertifikat_aktif()): ?>
      <h3><?= icon('award', 18) ?> Pengaturan Sertifikat</h3>
      <div class="form-grid">
        <div class="form-group"><label>Prefix nomor sertifikat</label><input type="text" name="cert_prefix" value="<?= e(setting('cert_prefix', 'GLC')) ?>"><div class="form-hint">Contoh: <?= e(setting('cert_prefix', 'GLC')) ?>-<?= date('Y') ?>-001-00004</div></div>
        <div class="form-group"><label>Nama penandatangan</label><input type="text" name="cert_penandatangan" value="<?= e(setting('cert_penandatangan')) ?>"></div>
      </div>
      <div class="form-group"><label>Kalimat sertifikat</label><textarea name="cert_catatan" style="min-height:80px"><?= e(setting('cert_catatan')) ?></textarea></div>
      <?php endif; ?>
      <div class="row-gap mb3">
        <label class="check"><input type="checkbox" name="registrasi_terbuka" value="1" <?= setting('registrasi_terbuka', '1') === '1' ? 'checked' : '' ?>> Pendaftaran siswa dibuka</label>
        <label class="check"><input type="checkbox" name="mode_pemeliharaan" value="1" <?= setting('mode_pemeliharaan') === '1' ? 'checked' : '' ?>> Mode pemeliharaan (hanya info)</label>
      </div>
      <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Pengaturan Website</button>
    </form>
  </div>

  <div class="tab-panel" id="panel-smtp">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="smtp">
      <div class="flash flash-info mb3"><?= icon('bell', 17) ?><span>Bila SMTP belum diaktifkan, semua email tetap dicatat pada Log Email dengan status "dilewati" sehingga tidak ada notifikasi yang terlewat tanpa jejak.</span></div>
      <div class="form-grid">
        <div class="form-group"><label>SMTP host</label><input type="text" name="smtp_host" value="<?= e(setting('smtp_host')) ?>" placeholder="smtp.gmail.com"></div>
        <div class="form-group"><label>Port</label><input type="number" name="smtp_port" value="<?= e(setting('smtp_port', '587')) ?>"></div>
        <div class="form-group"><label>Username</label><input type="text" name="smtp_user" value="<?= e(setting('smtp_user')) ?>"></div>
        <div class="form-group"><label>Password</label><input type="password" name="smtp_pass" placeholder="<?= setting('smtp_pass') !== '' ? '•••••••• (tersimpan)' : 'belum diatur' ?>"></div>
        <div class="form-group"><label>Email pengirim</label><input type="email" name="smtp_from" value="<?= e(setting('smtp_from')) ?>"></div>
        <div class="form-group"><label>Nama pengirim</label><input type="text" name="smtp_from_nama" value="<?= e(setting('smtp_from_nama')) ?>"></div>
      </div>
      <label class="check mb3"><input type="checkbox" name="smtp_enabled" value="1" <?= setting('smtp_enabled') === '1' ? 'checked' : '' ?>> Aktifkan pengiriman email via SMTP</label>
      <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Konfigurasi SMTP</button>
    </form>
    <div class="divider"></div>
    <form method="post" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="tes_smtp">
      <div class="form-group" style="flex:1;min-width:240px"><label>Kirim email tes ke</label><input type="email" name="email_tujuan" value="<?= e(setting('site_email')) ?>" required></div>
      <button class="btn btn-ghost" type="submit"><?= icon('mail', 17) ?> Kirim Email Tes</button>
    </form>
    <div class="divider"></div>
    <h3>Log Email Terakhir</h3>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Waktu</th><th>Penerima</th><th>Subjek</th><th>Status</th><th>Keterangan</th></tr></thead>
        <tbody>
        <?php foreach ($emailLog as $e): ?>
          <tr>
            <td class="small nowrap"><?= e(tgl_id($e['created_at'], true)) ?></td>
            <td class="small"><?= e($e['penerima']) ?></td>
            <td class="small"><?= e($e['subjek']) ?></td>
            <td><?= status_badge($e['status']) ?></td>
            <td class="muted small"><?= e((string)$e['error']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$emailLog): ?><tr><td colspan="5" class="muted">Belum ada email.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tab-panel" id="panel-pembayaran">
    <?php
    $rekSetting = rekening_list();
    $rekSetting = array_pad($rekSetting, 4, ['bank' => '', 'nomor' => '', 'nama' => '']);
    $jumlahTerkunci = (int)db()->query("SELECT COUNT(*) FROM enrollments WHERE status NOT IN ('aktif','selesai')")->fetchColumn();
    $jumlahKursusBerbayar = (int)db()->query('SELECT COUNT(*) FROM courses WHERE harga > 0')->fetchColumn();
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="pembayaran">
      <div class="row-between mb3">
        <div>
          <h3 class="mt0 mb1"><?= icon('wallet', 18) ?> Modul Pembayaran</h3>
          <p class="muted small mb0">Saat aktif, kursus dengan harga di atas 0 akan terkunci sampai pembayaran diverifikasi admin. Kursus harga 0 tetap gratis.</p>
        </div>
        <label class="check"><input type="checkbox" name="bayar_aktif" value="1" <?= setting('bayar_aktif', '1') === '1' ? 'checked' : '' ?>> Aktifkan modul pembayaran</label>
      </div>

      <h4>Rekening Tujuan Transfer</h4>
      <div class="form-grid" style="grid-template-columns:repeat(2,1fr)">
        <?php foreach ($rekSetting as $i => $r): ?>
          <div class="card tight flat">
            <div class="form-group mb1"><label>Bank / e-wallet <?= $i + 1 ?></label><input type="text" name="rekening[<?= $i ?>][bank]" value="<?= e($r['bank']) ?>" placeholder="mis. <?= $i === 0 ? 'Bank Mandiri' : 'DANA / BCA' ?>"></div>
            <div class="form-group mb1"><label>Nomor rekening</label><input type="text" name="rekening[<?= $i ?>][nomor]" value="<?= e($r['nomor']) ?>" placeholder="1234567890"></div>
            <div class="form-group mb0"><label>Atas nama</label><input type="text" name="rekening[<?= $i ?>][nama]" value="<?= e($r['nama']) ?>" placeholder="Nama pemilik rekening"></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="form-hint mb3">Kosongkan slot yang tidak dipakai — otomatis diabaikan.</div>

      <div class="form-group">
        <label>Instruksi pembayaran untuk siswa</label>
        <textarea name="bayar_instruksi" style="min-height:120px"><?= e(setting('bayar_instruksi')) ?></textarea>
        <div class="form-hint">Tampil di halaman Pembayaran siswa dan di modal unggah bukti.</div>
      </div>
      <div class="form-group">
        <label>Kontak bantuan (WA / email)</label>
        <input type="text" name="bayar_kontak" value="<?= e(setting('bayar_kontak')) ?>" placeholder="mis. 0812-xxxx-xxxx (Admin Gemilang)">
      </div>
      <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Pengaturan Pembayaran</button>
    </form>

    <div class="divider"></div>
    <h4>Ringkasan</h4>
    <div class="grid cols-3">
      <div class="card tight flat"><strong>Kursus berbayar</strong><div class="stat-num" style="font-size:1.2rem"><?= $jumlahKursusBerbayar ?></div><div class="muted small">harga di atas 0</div></div>
      <div class="card tight flat"><strong>Rekening aktif</strong><div class="stat-num" style="font-size:1.2rem"><?= count(rekening_list()) ?></div><div class="muted small">ditampilkan ke siswa</div></div>
      <div class="card tight flat"><strong>Pendaftaran terkunci</strong><div class="stat-num" style="font-size:1.2rem"><?= $jumlahTerkunci ?></div><div class="muted small">menunggu pembayaran/verifikasi</div></div>
    </div>
    <a class="btn btn-ghost mt2" href="admin-pembayaran.php"><?= icon('wallet', 17) ?> Buka Manajemen Pembayaran</a>

    <div class="divider"></div>
    <h4>Buka Akses Massal</h4>
    <p class="muted small">Berguna bila modul pembayaran baru dinyalakan setelah siswa telanjur belajar, atau ada kebijakan baru: semua pendaftaran yang masih terkunci langsung dibuka (riwayat pembayaran tidak berubah).</p>
    <form method="post" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="buka_akses_semua">
      <div class="form-group" style="min-width:220px">
        <label>Ketik <code>BUKA AKSES</code> untuk konfirmasi</label>
        <input type="text" name="konfirmasi_teks" placeholder="BUKA AKSES" required>
      </div>
      <button class="btn btn-danger" data-confirm="<?= $jumlahTerkunci ?> pendaftaran akan langsung dibuka. Lanjutkan?"><?= icon('lock', 17) ?> Buka Semua Akses Terkunci</button>
    </form>
  </div>

  <div class="tab-panel" id="panel-ai">
    <h3><?= icon('sparkle', 18) ?> Obrolan AI di halaman materi</h3>
    <p class="muted small">Siswa, guru, dan admin bisa bertanya ke AI langsung di halaman belajar materi (kartu "<strong><?= e(ai_nama()) ?></strong>" di bawah isi materi). AI diberi isi materi yang sedang dibuka sebagai konteks, dan riwayat percakapan disimpan per pengguna per materi sehingga bisa dilanjutkan kapan saja.</p>

    <?php if (!ai_siap()): ?>
      <div class="flash flash-warning"><?= icon('bell', 17) ?><span>Fitur ini butuh <strong>kunci API</strong> dari penyedia AI (layanan ini tidak menyediakan AI bawaan).
        Untuk Google Gemini, kunci gratis bisa dibuat di
        <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a> lalu ditempel di kolom "Kunci API" di bawah.
        Selama kunci belum diisi, kartu obrolan tidak ditampilkan kepada siswa.</span></div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="ai">
      <label class="check mb2"><input type="checkbox" name="ai_aktif" value="1" <?= ai_aktif() ? 'checked' : '' ?>> Aktifkan fitur obrolan AI di halaman materi</label>

      <div class="form-grid">
        <div class="form-group">
          <label>Nama kartu (tampil ke pengguna)</label>
          <input type="text" name="ai_nama" value="<?= e(ai_nama()) ?>" placeholder="Tanya AI">
          <div class="form-hint">Judul kartu obrolan di halaman materi.</div>
        </div>
        <div class="form-group">
          <label>Kalimat pengantar di kartu</label>
          <input type="text" name="ai_intro" value="<?= e(ai_intro()) ?>" placeholder="<?= e(lms_default_ai_settings()['ai_intro']) ?>">
          <div class="form-hint">Muncul di kartu, tepat di atas kotak pertanyaan. Kosongkan untuk memakai teks bawaan.</div>
        </div>
        <div class="form-group">
          <label>Tulisan di dalam kotak pertanyaan</label>
          <input type="text" name="ai_placeholder" value="<?= e(ai_placeholder()) ?>" placeholder="<?= e(lms_default_ai_settings()['ai_placeholder']) ?>">
          <div class="form-hint">Petunjuk abu-abu di dalam kotak tulis siswa. Kosongkan untuk memakai teks bawaan.</div>
        </div>
        <div class="form-group">
          <label>Penyedia AI</label>
          <select name="ai_provider">
            <option value="gemini" <?= ai_provider() === 'gemini' ? 'selected' : '' ?>>Google Gemini</option>
            <option value="openai" <?= ai_provider() === 'openai' ? 'selected' : '' ?>>OpenAI / kompatibel (Groq, OpenRouter, DeepSeek, server sendiri)</option>
          </select>
        </div>
        <div class="form-group">
          <label>Nama model</label>
          <input type="text" name="ai_model" value="<?= e(setting('ai_model', '')) ?>" placeholder="<?= ai_provider() === 'openai' ? 'gpt-4o-mini' : 'gemini-2.5-flash' ?>">
          <div class="form-hint">Kosongkan untuk memakai bawaan: <code>gemini-2.5-flash</code> (Gemini) atau <code>gpt-4o-mini</code> (OpenAI). Isi salah satu model milikmu sendiri bila berbeda, mis. <code>gemini-2.0-flash</code> atau <code>llama-3.3-70b-versatile</code> (Groq).</div>
        </div>
        <div class="form-group">
          <label>Batas pertanyaan per pengguna / 24 jam</label>
          <input type="number" name="ai_batas_harian" min="0" step="1" value="<?= ai_batas_harian() ?>">
          <div class="form-hint">Berguna untuk menjaga kuota gratis penyedia AI. Isi <code>0</code> untuk tanpa batas.</div>
        </div>
        <div class="form-group">
          <label>Alamat API khusus (opsional)</label>
          <input type="text" name="ai_base_url" value="<?= e(setting('ai_base_url', '')) ?>" placeholder="<?= ai_provider() === 'openai' ? 'https://api.openai.com/v1' : 'https://generativelanguage.googleapis.com' ?>">
          <div class="form-hint">Biarkan kosong untuk memakai bawaan penyedia. Diisi bila memakai penyedia lain, mis. <code>https://api.groq.com/openai/v1</code> atau <code>https://openrouter.ai/api/v1</code>.</div>
        </div>
      </div>

      <div class="form-group">
        <label>Kunci API <?= ai_siap() ? '<span class="badge badge-success">sudah tersimpan</span>' : '<span class="badge badge-warning">belum diisi</span>' ?></label>
        <input type="password" name="ai_api_key" value="" autocomplete="new-password" placeholder="<?= ai_siap() ? 'Kunci tersimpan — isi hanya bila ingin menggantinya' : 'Tempel kunci API di sini' ?>">
        <div class="form-hint">Kunci hanya disimpan di server dan tidak pernah dikirim ke browser pengunjung. Kunci lama tidak ditampilkan kembali di halaman ini.</div>
        <?php if (ai_siap()): ?>
          <label class="check mt1"><input type="checkbox" name="ai_hapus_kunci" value="1"> Hapus kunci API yang tersimpan</label>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label>Gaya jawaban AI (instruksi sistem)</label>
        <textarea name="ai_prompt" rows="6"><?= e(setting('ai_prompt', '') !== '' ? setting('ai_prompt') : lms_default_ai_settings()['ai_prompt']) ?></textarea>
        <div class="form-hint">Kode <code>{site}</code>, <code>{kursus}</code>, dan <code>{materi}</code> otomatis diganti. Isi materi yang sedang dibuka selalu ditambahkan di belakang instruksi ini.</div>
      </div>

      <div class="divider"></div>
      <h3><?= icon('mic', 18) ?> Suara (obrolan bicara)</h3>
      <p class="muted small">Pengguna bisa <strong>bicara</strong> ke AI lewat mikrofon (suaranya diubah menjadi teks lalu otomatis dikirim) dan <strong>jawaban AI dibacakan</strong>.
        Suara memakai kemampuan peramban/HP pengguna (Web Speech API) — tidak perlu kunci API tambahan dan tidak ada biaya.
        Tombol bicara otomatis disembunyikan di peramban yang belum mendukung suara; pengguna tetap bisa menulis pertanyaan seperti biasa.</p>
      <div class="form-grid">
        <div class="form-group">
          <label class="check"><input type="checkbox" name="ai_suara_aktif" value="1" <?= ai_suara_aktif() ? 'checked' : '' ?>> Aktifkan fitur suara di kartu obrolan</label>
          <div class="form-hint">Menampilkan tombol bicara (mikrofon), tombol pembacaan jawaban, dan pilihan bahasa suara.</div>
        </div>
        <div class="form-group">
          <label class="check"><input type="checkbox" name="ai_suara_otomatis" value="1" <?= ai_suara_otomatis() ? 'checked' : '' ?>> Bacakan jawaban AI otomatis</label>
          <div class="form-hint">Bawaan saat kartu dibuka. Pengguna masih bisa mematikan/menyalakannya sendiri lewat tombol "Suara AI" (pilihannya diingat peramban).</div>
        </div>
        <div class="form-group">
          <label class="check"><input type="checkbox" name="ai_suara_live" value="1" <?= ai_suara_live() ? 'checked' : '' ?>> Tampilkan tombol "Mode Percakapan" (mengobrol tanpa henti)</label>
          <div class="form-hint">Mikrofon mendengarkan secara bergiliran: tiap giliran diawali hitungan mundur ("mulai bicara dalam 3…") supaya tidak ada suara yang terkirim sebelum pengguna siap, lalu tiap kalimat otomatis dikirim, jawabannya dibacakan, dan giliran berikutnya dimulai. Suara berisik/gumaman dan suara sebelum giliran dimulai diabaikan; bila jawaban tidak bisa diputar, sesi dihentikan dengan pesan. Perlu izin mikrofon, alamat https, dan koneksi internet.</div>
        </div>
        <div class="form-group">
          <label>Berapa lama menunggu pengguna selesai bicara</label>
          <select name="ai_suara_jeda">
            <?php foreach ([1500 => 'Cepat (1,5 detik)', 3000 => 'Sedang (3 detik)', 5000 => 'Sabar (5 detik)', 8000 => 'Sangat sabar (8 detik)'] as $ms => $label): ?>
              <option value="<?= (int)$ms ?>" <?= ai_suara_jeda() === (int)$ms ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">Dipakai hanya di <strong>Mode Percakapan</strong>: berapa lama keheningan dianggap "pengguna sudah selesai bicara". Makin besar makin sabar — pengguna yang berpikir sejenak di tengah kalimat tidak akan dipotong. Bawaan <strong>Sedang (3 detik)</strong>.</div>
        </div>
        <div class="form-group">
          <label>Bahasa suara bawaan</label>
          <select name="ai_suara_bahasa">
            <option value="id" <?= ai_suara_bahasa() === 'id' ? 'selected' : '' ?>>Bahasa Indonesia</option>
            <option value="en" <?= ai_suara_bahasa() === 'en' ? 'selected' : '' ?>>Bahasa Inggris</option>
          </select>
          <div class="form-hint">Bawaan saat kartu dibuka — pengguna bisa berganti Indonesia &harr; English di kartu obrolan. Bahasa ini juga dipakai untuk pengenal suara.</div>
        </div>
      </div>

      <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Pengaturan AI</button>
    </form>

    <div class="divider"></div>
    <h3><?= icon('refresh', 18) ?> Tes Koneksi</h3>
    <p class="muted small">Mengirim satu pertanyaan singkat ke penyedia AI memakai pengaturan yang sedang aktif. Simpan dulu pengaturan di atas sebelum mengetes.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="ai_tes">
      <button class="btn btn-ghost"><?= icon('send', 17) ?> Tes Koneksi AI</button>
    </form>

    <div class="divider"></div>
    <dl class="kv">
      <dt>Status fitur</dt><dd><?= ai_aktif() ? '<span class="badge badge-success">aktif</span>' : '<span class="badge badge-muted">nonaktif</span>' ?></dd>
      <dt>Suara</dt><dd><?= ai_suara_aktif()
        ? '<span class="badge badge-success">aktif</span> · ' . e(ai_suara_bahasa_label()) . ' · jawaban ' . (ai_suara_otomatis() ? 'dibacakan otomatis' : 'dibacakan bila tombol diklik') . ' · mode percakapan ' . (ai_suara_live() ? 'tersedia (tunggu ' . e(ai_suara_jeda_label()) . ')' : 'dimatikan')
        : '<span class="badge badge-muted">nonaktif</span>' ?></dd>
      <dt>Penyedia &amp; model</dt><dd><?= e(ai_provider_label()) ?> · <?= e(ai_model()) ?></dd>
      <dt>Percakapan tersimpan</dt><dd><?= (int)db()->query('SELECT COUNT(*) FROM ai_messages')->fetchColumn() ?> pesan</dd>
      <dt>Pertanyaan 24 jam terakhir</dt><dd><?= (int)db()->query("SELECT COUNT(*) FROM ai_messages WHERE peran = 'user' AND created_at >= datetime('now','-1 day')")->fetchColumn() ?> pertanyaan</dd>
    </dl>
  </div>

  <div class="tab-panel" id="panel-storage">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="storage">
      <div class="form-grid">
        <div class="form-group">
          <label>Driver penyimpanan</label>
          <select name="storage_driver">
            <option value="media-proxy" <?= setting('storage_driver') === 'media-proxy' ? 'selected' : '' ?>>Media proxy platform (disarankan)</option>
            <option value="lokal" <?= setting('storage_driver') === 'lokal' ? 'selected' : '' ?>>Folder lokal server</option>
          </select>
          <div class="form-hint">Semua berkas materi, tugas, dan foto profil diunggah lewat backend aplikasi, bukan langsung dari browser pengunjung.</div>
        </div>
        <div class="form-group"><label>Folder fallback lokal</label><input type="text" name="storage_folder" value="<?= e(setting('storage_folder', 'uploads')) ?>"></div>
      </div>
      <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan</button>
    </form>
    <div class="divider"></div>
    <div class="grid cols-3">
      <div class="card tight flat"><strong>Ukuran database</strong><div class="stat-num" style="font-size:1.2rem"><?= number_format($ukuran['database'] / 1024, 1) ?> KB</div></div>
      <div class="card tight flat"><strong>Berkas media lokal</strong><div class="stat-num" style="font-size:1.2rem"><?= $ukuran['file_media'] ?> file</div></div>
      <div class="card tight flat"><strong>Total ukuran media lokal</strong><div class="stat-num" style="font-size:1.2rem"><?= number_format($ukuran['media'] / 1048576, 2) ?> MB</div></div>
    </div>
    <div class="dark-box mt3">Status token media: <?= media_token() !== '' ? '<strong>tersedia</strong> — upload akan dikirim ke penyimpanan platform.' : '<strong>belum tersedia</strong> — berkas akan disimpan di folder lokal hingga aplikasi dipublikasikan.' ?></div>
  </div>

  <div class="tab-panel" id="panel-lanjutan">
    <h3><?= icon('database', 18) ?> Pemeliharaan Data</h3>
    <p class="muted small">Membersihkan log lama agar database tetap ringan. Data pengguna, kursus, dan hasil belajar tidak terhapus.</p>
    <form method="post" class="mb3">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="bersihkan_log">
      <button class="btn btn-ghost" data-confirm="Hapus log notifikasi & audit yang lebih lama dari 30-90 hari?"><?= icon('refresh', 17) ?> Bersihkan Log Lama</button>
    </form>
    <div class="divider"></div>
    <h3><?= icon('shield', 18) ?> Informasi Sistem</h3>
    <dl class="kv">
      <dt>Versi skema database</dt><dd><?= (int)setting('schema_version', '0') ?></dd>
      <dt>Jumlah pengguna</dt><dd><?= (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() ?></dd>
      <dt>Jumlah kursus</dt><dd><?= (int)db()->query('SELECT COUNT(*) FROM courses')->fetchColumn() ?></dd>
      <dt>Jumlah soal</dt><dd><?= (int)db()->query('SELECT COUNT(*) FROM questions')->fetchColumn() ?></dd>
      <dt>Jumlah pengerjaan quiz</dt><dd><?= (int)db()->query('SELECT COUNT(*) FROM quiz_attempts')->fetchColumn() ?></dd>
      <dt>Jumlah email log</dt><dd><?= (int)db()->query('SELECT COUNT(*) FROM email_log')->fetchColumn() ?></dd>
    </dl>
    <div class="divider"></div>
    <a class="btn btn-primary" href="admin-backup.php"><?= icon('database', 17) ?> Buka Menu Backup Database</a>
  </div>
</div>
<?php page_end(); ?>
