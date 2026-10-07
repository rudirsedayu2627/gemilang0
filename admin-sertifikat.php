<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login('admin');

if (!sertifikat_aktif()) {
    // Fitur sertifikat dimatikan: halaman kelola disembunyikan. Data lama di
    // tabel `certificates` tetap tersimpan (tidak ada yang dihapus).
    flash('Fitur sertifikat sedang dinonaktifkan. Aktifkan lagi di Pengaturan → Website bila diperlukan.', 'warning');
    redirect('admin-dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $id = (int)post('id');
    if ($aksi === 'terbitkan') {
        $uid = (int)post('user_id');
        $cid = (int)post('course_id');
        if ($uid && $cid) {
            $nilai = (float)post('nilai_akhir');
            $nomor = setting('cert_prefix', 'GLC') . '-' . date('Y') . '-' . str_pad((string)$cid, 3, '0', STR_PAD_LEFT) . '-' . str_pad((string)$uid, 5, '0', STR_PAD_LEFT) . (post('nomor_suffix') ? '-' . post('nomor_suffix') : '');
            $cek = db()->prepare('SELECT id FROM certificates WHERE user_id = ? AND course_id = ?');
            $cek->execute([$uid, $cid]);
            if ($cek->fetch()) {
                flash('Siswa ini sudah memiliki sertifikat untuk kursus tersebut.', 'warning');
            } else {
                db()->prepare('INSERT INTO certificates (nomor, user_id, course_id, nilai_akhir, tanggal, catatan) VALUES (?,?,?,?,?,?)')
                    ->execute([$nomor, $uid, $cid, $nilai, post('tanggal', date('Y-m-d')), post('catatan')]);
                notify($uid, 'Sertifikat diterbitkan admin', 'Sertifikat kursusmu sudah diterbitkan. Nomor: ' . $nomor, 'siswa-sertifikat.php');
                audit('terbitkan_sertifikat', 'Menerbitkan sertifikat ' . $nomor);
                flash('Sertifikat diterbitkan dengan nomor ' . $nomor . '.');
            }
        }
    }
    if ($aksi === 'status') {
        db()->prepare('UPDATE certificates SET status = ? WHERE id = ?')->execute([post('status'), $id]);
        audit('ubah_status_sertifikat', 'Mengubah status sertifikat ' . $id . ' menjadi ' . post('status'));
        flash('Status sertifikat diperbarui.');
    }
    if ($aksi === 'hapus') {
        db()->prepare('DELETE FROM certificates WHERE id = ?')->execute([$id]);
        audit('hapus_sertifikat', 'Menghapus sertifikat ' . $id);
        flash('Sertifikat dihapus.');
    }
    if ($aksi === 'generate_massal') {
        $cid = (int)post('course_id');
        $n = 0;
        if ((int)post('paksa') === 1) {
            foreach (db()->query('SELECT user_id FROM enrollments WHERE course_id = ' . $cid)->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $cert = generate_certificate((int)$uid, $cid);
                if ($cert) {
                    $n++;
                }
            }
        } else {
            foreach (db()->query('SELECT e.user_id FROM enrollments e WHERE e.course_id = ' . $cid . ' AND e.progress >= 100')->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                if (maybe_issue_certificate((int)$uid, $cid)) {
                    $n++;
                }
            }
        }
        flash($n . ' sertifikat diproses untuk kursus terpilih.');
        audit('generate_sertifikat', 'Generate massal ' . $n . ' sertifikat');
    }
    redirect('admin-sertifikat.php');
}

if (get('export') === 'xlsx') {
    $rows = [['Nomor', 'Nama', 'Email', 'Kursus', 'Nilai Akhir', 'Tanggal', 'Status']];
    foreach (db()->query('SELECT c.*, us.nama, us.email, co.judul AS kursus FROM certificates c
        JOIN users us ON us.id = c.user_id JOIN courses co ON co.id = c.course_id ORDER BY c.id DESC')->fetchAll() as $r) {
        $rows[] = [$r['nomor'], $r['nama'], $r['email'], $r['kursus'], $r['nilai_akhir'], $r['tanggal'], $r['status']];
    }
    audit('export_sertifikat', 'Export data sertifikat');
    xlsx_download('data-sertifikat-' . date('Ymd') . '.xlsx', $rows);
}

$rows = db()->query("SELECT c.*, us.nama, us.email, us.foto, co.judul AS kursus, co.level
    FROM certificates c JOIN users us ON us.id = c.user_id JOIN courses co ON co.id = c.course_id
    WHERE " . (num('kursus') ? ('c.course_id = ' . num('kursus')) : '1=1') . " ORDER BY c.id DESC")->fetchAll();
$courses = db()->query('SELECT id, judul FROM courses ORDER BY judul')->fetchAll();
$siswaList = db()->query("SELECT id, nama, email FROM users WHERE role = 'siswa' ORDER BY nama")->fetchAll();
$kandidat = db()->query("SELECT e.course_id, co.judul AS kursus, COUNT(*) AS jml FROM enrollments e JOIN courses co ON co.id = e.course_id
    WHERE e.progress >= 100 AND NOT EXISTS (SELECT 1 FROM certificates c WHERE c.user_id = e.user_id AND c.course_id = e.course_id)
    GROUP BY e.course_id ORDER BY jml DESC")->fetchAll();

page_start('Manajemen Sertifikat', count($rows) . ' sertifikat diterbitkan.', [
    'actions' => '<a class="btn btn-ghost" href="admin-sertifikat.php?export=xlsx">' . icon('download', 17) . ' Export Excel</a>'
        . '<button class="btn btn-ghost" data-modal-open="modal-massal">' . icon('refresh', 17) . ' Generate Massal</button>'
        . '<button class="btn btn-primary" data-modal-open="modal-sertifikat">' . icon('plus', 17) . ' Terbitkan Manual</button>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('award', 22) ?></div><div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lab">Sertifikat diterbitkan</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= count(array_filter($rows, function ($r) { return $r['status'] === 'aktif'; })) ?></div><div class="stat-lab">Status aktif</div></div></div>
  <div class="stat"><div class="stat-ic g4"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= $rows ? number_format(array_sum(array_column($rows, 'nilai_akhir')) / count($rows), 2) : '0' ?></div><div class="stat-lab">Rata-rata nilai akhir</div></div></div>
  <div class="stat"><div class="stat-ic g6"><?= icon('bell', 22) ?></div><div><div class="stat-num"><?= array_sum(array_column($kandidat, 'jml')) ?></div><div class="stat-lab">Siswa siap disertifikasi</div></div></div>
</div>

<?php if ($kandidat): ?>
<div class="card">
  <div class="card-head"><h2 class="mt0"><?= icon('bell', 20) ?> Siswa Selesai 100% & Siap Diberi Sertifikat</h2>
    <button class="btn btn-primary btn-sm" data-modal-open="modal-massal"><?= icon('refresh', 15) ?> Proses Sekarang</button></div>
  <div class="grid cols-3">
    <?php foreach ($kandidat as $k): ?>
      <div class="card tight flat">
        <strong><?= e($k['kursus']) ?></strong>
        <div class="muted small"><?= (int)$k['jml'] ?> siswa selesai & belum bersertifikat</div>
        <form method="post" class="mt2">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="generate_massal">
          <input type="hidden" name="course_id" value="<?= (int)$k['course_id'] ?>">
          <button class="btn btn-ghost btn-sm btn-block"><?= icon('award', 14) ?> Terbitkan Otomatis</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <form class="toolbar">
    <div class="form-group" style="min-width:240px">
      <label>Filter kursus</label>
      <select name="kursus" data-autosubmit>
        <option value="0">Semua kursus</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= num('kursus') === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1;min-width:180px">
      <label>Cari</label>
      <input type="search" data-filter-input="#tbl-cert" placeholder="Nomor sertifikat atau nama siswa...">
    </div>
  </form>
  <div class="table-wrap">
    <table class="data" id="tbl-cert">
      <thead><tr><th>Nomor</th><th>Siswa</th><th>Kursus</th><th>Nilai Akhir</th><th>Tanggal</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr>
          <td><code><?= e($c['nomor']) ?></code></td>
          <td><div class="tbl-student"><span class="avatar sm" <?= $c['foto'] ? 'style="background-image:url(' . e($c['foto']) . ')"' : '' ?>><?= $c['foto'] ? '' : e(strtoupper(mb_substr($c['nama'], 0, 1))) ?></span><div><strong><?= e($c['nama']) ?></strong><div class="muted small"><?= e($c['email']) ?></div></div></div></td>
          <td class="small"><?= e($c['kursus']) ?></td>
          <td><?= number_format((float)$c['nilai_akhir'], 2) ?></td>
          <td class="small"><?= e(tgl_id($c['tanggal'])) ?></td>
          <td><?= status_badge($c['status']) ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="sertifikat-verifikasi.php?nomor=<?= urlencode($c['nomor']) ?>" title="Verifikasi"><?= icon('shield', 14) ?></a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="status">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="status" value="<?= $c['status'] === 'aktif' ? 'dicabut' : 'aktif' ?>">
              <button class="btn btn-ghost btn-sm" title="Aktif/Cabut"><?= icon($c['status'] === 'aktif' ? 'lock' : 'check', 14) ?></button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Hapus sertifikat ini?"><?= icon('trash', 14) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted">Belum ada sertifikat.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="modal-sertifikat">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 class="mt0">Terbitkan Sertifikat Manual</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="terbitkan">
      <div class="form-group">
        <label>Siswa *</label>
        <select name="user_id" required>
          <?php foreach ($siswaList as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['nama']) ?> — <?= e($s['email']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Kursus *</label>
        <select name="course_id" required>
          <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['judul']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid">
        <div class="form-group"><label>Nilai akhir</label><input type="number" step="0.01" name="nilai_akhir" value="85"></div>
        <div class="form-group"><label>Tanggal terbit</label><input type="date" name="tanggal" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="form-group"><label>Catatan (opsional)</label><input type="text" name="catatan"></div>
      <div class="form-hint mb3">Nomor sertifikat dibuat otomatis dengan format <code><?= e(setting('cert_prefix', 'GLC')) ?>-<?= date('Y') ?>-XXX-XXXXX</code>.</div>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('award', 17) ?> Terbitkan</button>
        <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-back" id="modal-massal">
  <div class="modal" style="max-width:520px">
    <div class="modal-head"><h3 class="mt0">Generate Sertifikat Massal</h3><button class="icon-btn" data-modal-close><?= icon('x', 18) ?></button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="generate_massal">
      <div class="form-group">
        <label>Kursus *</label>
        <select name="course_id" required>
          <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['judul']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <label class="check mb3"><input type="checkbox" name="paksa" value="1"> Paksa terbitkan untuk semua siswa terdaftar (abaikan syarat kelulusan)</label>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><?= icon('refresh', 17) ?> Proses</button>
        <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
      </div>
    </form>
  </div>
</div>
<?php page_end(); ?>
