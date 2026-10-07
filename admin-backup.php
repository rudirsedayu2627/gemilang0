<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');

$backupDir = (getenv('LMS_BACKUP_DIR') !== false && getenv('LMS_BACKUP_DIR') !== '')
    ? (string)getenv('LMS_BACKUP_DIR')
    : LMS_APP_DIR . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0775, true);
}
// Berkas penjaga: folder backup berisi salinan penuh database, jangan sampai
// bisa diakses/di-list lewat URL publik.
if (!is_file($backupDir . '/index.php')) {
    @file_put_contents($backupDir . '/index.php', "<?php http_response_code(403); exit('Akses ditolak.');\n");
}
if (!is_file($backupDir . '/.htaccess')) {
    @file_put_contents($backupDir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}
$dbFile = lms_db_path();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    if ($aksi === 'buat') {
        $nama = 'backup-' . date('Ymd-His') . '.sqlite';
        $dest = $backupDir . '/' . $nama;
        $ok = false;
        try {
            // VACUUM INTO membuat snapshot konsisten tanpa menghentikan aplikasi.
            $stmt = db()->prepare('VACUUM INTO ?');
            $stmt->execute([$dest]);
            $ok = is_file($dest);
        } catch (Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            db()->exec('PRAGMA wal_checkpoint(FULL)');
            $ok = @copy($dbFile, $dest);
        }
        if ($ok) {
            db()->prepare('INSERT INTO backups (nama, ukuran, dibuat_oleh) VALUES (?, ?, ?)')->execute([$nama, (int)filesize($dest), $u['id']]);
            audit('backup_database', 'Membuat backup ' . $nama);
            flash('Backup database berhasil dibuat: ' . $nama . ' (' . number_format(filesize($dest) / 1024, 1) . ' KB)');
        } else {
            flash('Backup gagal dibuat. Periksa izin tulis folder backups/.', 'danger');
        }
        redirect('admin-backup.php');
    }
    if ($aksi === 'hapus') {
        $nama = basename((string)post('nama'));
        $path = $backupDir . '/' . $nama;
        if (is_file($path) && preg_match('/^backup-[\w\-]+\.sqlite$/', $nama)) {
            @unlink($path);
            db()->prepare('DELETE FROM backups WHERE nama = ?')->execute([$nama]);
            audit('hapus_backup', 'Menghapus backup ' . $nama);
            flash('Backup ' . $nama . ' dihapus.');
        }
        redirect('admin-backup.php');
    }
    if ($aksi === 'restore') {
        $nama = basename((string)post('nama'));
        $path = $backupDir . '/' . $nama;
        if (!is_file($path)) {
            flash('Berkas backup tidak ditemukan.', 'danger');
            redirect('admin-backup.php');
        }
        $isi = file_get_contents($path);
        if ($isi === false || strlen($isi) < 1024) {
            flash('Berkas backup tidak valid.', 'danger');
            redirect('admin-backup.php');
        }
        // Snapshot pengaman sebelum menimpa data yang sedang berjalan.
        $aman = $backupDir . '/sebelum-restore-' . date('Ymd-His') . '.sqlite';
        @copy($dbFile, $aman);
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        // Tulis ulang isi file database yang sama (tanpa menghapus) agar aman
        // walau ada proses lain yang memegang handle-nya.
        file_put_contents($dbFile, $isi);
        @unlink($dbFile . '-wal');
        @unlink($dbFile . '-shm');
        audit('restore_database', 'Restore database dari ' . $nama);
        flash('Database direstore dari ' . $nama . '. Muat ulang halaman untuk melihat data terbaru.');
        redirect('admin-backup.php');
    }
    if ($aksi === 'bersihkan_yatim') {
        $sebelum = lms_orphan_counts();
        $hasil = lms_orphan_cleanup();
        $sesudah = lms_orphan_counts();
        audit('bersihkan_data_yatim', 'Membersihkan data yatim: ' . json_encode($hasil, JSON_UNESCAPED_UNICODE));
        if (!$sebelum) {
            flash('Tidak ada data yatim — database sudah bersih.');
        } else {
            $ringkas = [];
            foreach ($hasil as $k => $v) {
                $ringkas[] = $k . ': ' . $v;
            }
            flash('Data yatim dibersihkan (' . implode(', ', $ringkas) . '). Sisa: ' . count($sesudah) . ' kategori.',
                $sesudah ? 'warning' : 'success');
        }
        redirect('admin-backup.php#yatim');
    }
    if ($aksi === 'bersihkan') {
        foreach (glob($backupDir . '/*.sqlite') ?: [] as $f) {
            if (is_file($f) && preg_match('/^sebelum-restore-/', basename($f))) {
                @unlink($f);
            }
        }
        flash('Snapshot pengaman sebelum restore dibersihkan.');
        redirect('admin-backup.php');
    }
}

$files = [];
foreach (glob($backupDir . '/*.sqlite') ?: [] as $f) {
    $files[] = ['nama' => basename($f), 'ukuran' => (int)filesize($f), 'waktu' => date('Y-m-d H:i:s', (int)filemtime($f))];
}
usort($files, function ($a, $b) {
    return strcmp($b['waktu'], $a['waktu']);
});
$riwayat = db()->query('SELECT b.*, us.nama AS nama_user FROM backups b LEFT JOIN users us ON us.id = b.dibuat_oleh ORDER BY b.id DESC LIMIT 20')->fetchAll();
$ukuranDb = is_file($dbFile) ? filesize($dbFile) : 0;
$totalBackup = array_sum(array_column($files, 'ukuran'));

page_start('Backup Database', 'Buat snapshot data LMS, pulihkan bila perlu, dan kelola berkas backup.', [
    'actions' => '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="aksi" value="buat"><button class="btn btn-primary">' . icon('database', 17) . ' Buat Backup Sekarang</button></form>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('database', 22) ?></div><div><div class="stat-num"><?= number_format($ukuranDb / 1024, 1) ?> KB</div><div class="stat-lab">Ukuran database aktif</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('folder', 22) ?></div><div><div class="stat-num"><?= count($files) ?></div><div class="stat-lab">Berkas backup tersimpan</div></div></div>
  <div class="stat"><div class="stat-ic g3"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= number_format($totalBackup / 1048576, 2) ?> MB</div><div class="stat-lab">Total ukuran backup</div></div></div>
  <div class="stat"><div class="stat-ic g4"><?= icon('shield', 22) ?></div><div><div class="stat-num"><?= (int)setting('schema_version', '0') ?></div><div class="stat-lab">Versi skema database</div></div></div>
</div>

<div class="flash flash-info mb3"><?= icon('bell', 17) ?><span>Backup berisi seluruh data LMS (pengguna, kursus, materi, soal, nilai, sertifikat). Simpan berkas backup di penyimpanan lain agar aman bila server bermasalah.</span></div>

<div class="card">
  <div class="card-head"><h2 class="mt0"><?= icon('database', 20) ?> Berkas Backup</h2>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="bersihkan">
      <button class="btn btn-ghost btn-sm"><?= icon('refresh', 15) ?> Bersihkan Snapshot Pengaman</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Nama berkas</th><th>Ukuran</th><th>Dibuat</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($files as $f): ?>
        <tr>
          <td><code><?= e($f['nama']) ?></code></td>
          <td><?= number_format($f['ukuran'] / 1024, 1) ?> KB</td>
          <td class="small"><?= e(tgl_id($f['waktu'], true)) ?></td>
          <td class="actions">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="restore">
              <input type="hidden" name="nama" value="<?= e($f['nama']) ?>">
              <button class="btn btn-ghost btn-sm" data-confirm="Restore database dari backup ini? Data saat ini akan ditimpa (snapshot pengaman dibuat otomatis)."><?= icon('refresh', 14) ?> Restore</button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="nama" value="<?= e($f['nama']) ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus berkas backup ini?"><?= icon('trash', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$files): ?><tr><td colspan="4" class="muted">Belum ada berkas backup. Klik "Buat Backup Sekarang".</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2 class="mt0"><?= icon('clock', 20) ?> Riwayat Pembuatan Backup</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Waktu</th><th>Nama berkas</th><th>Ukuran</th><th>Dibuat oleh</th></tr></thead>
      <tbody>
      <?php foreach ($riwayat as $r): ?>
        <tr>
          <td class="small nowrap"><?= e(tgl_id($r['created_at'], true)) ?></td>
          <td class="small"><code><?= e($r['nama']) ?></code></td>
          <td><?= number_format((int)$r['ukuran'] / 1024, 1) ?> KB</td>
          <td class="small"><?= e((string)($r['nama_user'] ?? '-')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$riwayat): ?><tr><td colspan="4" class="muted">Belum ada riwayat backup.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h3 class="mt0" id="yatim"><?= icon('shield', 18) ?> Pemeliharaan Data</h3>
  <?php $yatim = lms_orphan_counts(); ?>
  <?php if (!$yatim): ?>
    <div class="flash flash-success"><?= icon('check', 17) ?><span>Tidak ada data yatim — seluruh baris database menunjuk data yang masih ada.</span></div>
  <?php else: ?>
    <div class="flash flash-warning"><?= icon('bell', 17) ?><span>Ada <strong><?= array_sum(array_column($yatim, 'jumlah')) ?></strong> baris data yatim: baris yang menunjuk record sudah dihapus (sisa dari penghapusan data sebelumnya). Baris ini tidak tampil di halaman mana pun, tetapi bisa membuat angka statistik jadi lebih besar dari seharusnya.</span></div>
    <div class="table-wrap mb2">
      <table class="data">
        <thead><tr><th>Kategori</th><th>Jumlah baris</th></tr></thead>
        <tbody>
        <?php foreach ($yatim as $y): ?>
          <tr><td><?= e($y['label']) ?></td><td><strong><?= (int)$y['jumlah'] ?></strong></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" class="mb3">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="bersihkan_yatim">
      <button class="btn btn-danger" data-confirm="Hapus semua baris data yatim? Hanya baris yang menunjuk data sudah-dihapus yang terpengaruh; data siswa, kursus, nilai, dan sertifikat yang masih ada tidak disentuh."><?= icon('trash', 17) ?> Bersihkan Data Yatim</button>
    </form>
  <?php endif; ?>
  <p class="muted small mb3">Pembersihan ini hanya menghapus baris yang induknya sudah tidak ada (mis. pendaftaran milik siswa yang sudah dihapus). Data yang masih lengkap tidak tersentuh. Penulis konten yang sudah dihapus akan dilepas namanya, tetapi isi kontennya tetap ada.</p>

  <h3 class="mt0"><?= icon('shield', 18) ?> Catatan Penting</h3>
  <ul class="muted small">
    <li>Backup dibuat dengan perintah <code>VACUUM INTO</code> sehingga tetap konsisten walau siswa sedang mengerjakan quiz.</li>
    <li>Restore akan membuat snapshot pengaman <code>sebelum-restore-*.sqlite</code> lebih dulu.</li>
    <li>Setelah restore, minta pengguna memuat ulang halaman karena sesi login tetap berjalan.</li>
    <li>Untuk mengunduh seluruh kode sumber atau mengekspor database ke luar platform, gunakan fitur tersebut pada halaman Pro platform.</li>
  </ul>
</div>
<?php page_end(); ?>
