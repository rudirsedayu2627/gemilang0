<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/xlsx.php';
$u = require_login(['guru', 'admin']);
$courses = db()->query("SELECT id, judul FROM courses WHERE " . ($u['role'] === 'admin' ? '1=1' : ('guru_id = ' . (int)$u['id'])) . ' ORDER BY judul')->fetchAll();
$allowed = array_map('intval', array_column($courses, 'id'));
$kursusId = num('kursus', (int)($courses[0]['id'] ?? 0));
$kelasId = num('kelas');
$tanggal = get('tanggal', date('Y-m-d'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = post('aksi');
    $cid = (int)post('course_id');
    $tgl = post('tanggal', date('Y-m-d'));
    if ($aksi === 'simpan' && in_array($cid, $allowed, true)) {
        $status = (array)post('status', []);
        $catatan = (array)post('catatan', []);
        $n = 0;
        foreach ($status as $sid => $st) {
            if ($st === '') {
                continue;
            }
            db()->prepare('INSERT INTO attendance (course_id, class_id, user_id, tanggal, status, catatan, dicatat_oleh) VALUES (?,?,?,?,?,?,?)
                ON CONFLICT(user_id, course_id, tanggal) DO UPDATE SET status = excluded.status, catatan = excluded.catatan, dicatat_oleh = excluded.dicatat_oleh')
                ->execute([$cid, $kelasId ?: null, (int)$sid, $tgl, $st, (string)($catatan[$sid] ?? ''), $u['id']]);
            $n++;
        }
        audit('simpan_absensi', 'Menyimpan absensi ' . $n . ' siswa pada ' . $tgl);
        flash($n . ' data absensi disimpan untuk ' . tgl_id($tgl) . '.');
    }
    if ($aksi === 'isi_semua' && in_array($cid, $allowed, true)) {
        foreach (db()->query('SELECT user_id FROM enrollments WHERE course_id = ' . $cid)->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            db()->prepare('INSERT INTO attendance (course_id, class_id, user_id, tanggal, status, dicatat_oleh) VALUES (?,?,?,?,"hadir",?)
                ON CONFLICT(user_id, course_id, tanggal) DO UPDATE SET status = "hadir"')
                ->execute([$cid, $kelasId ?: null, (int)$sid, $tgl, $u['id']]);
        }
        flash('Semua siswa ditandai hadir pada ' . tgl_id($tgl) . '.');
    }
    redirect('guru-absensi.php?kursus=' . $cid . '&kelas=' . $kelasId . '&tanggal=' . urlencode($tgl));
}

if (get('export') === 'xlsx') {
    $rows = [['Tanggal', 'Kursus', 'Siswa', 'Status', 'Catatan']];
    $data = db()->query("SELECT a.tanggal, co.judul AS kursus, us.nama, a.status, a.catatan FROM attendance a
        JOIN courses co ON co.id = a.course_id JOIN users us ON us.id = a.user_id
        WHERE co.guru_id " . ($u['role'] === 'admin' ? 'IS NOT NULL' : '= ' . (int)$u['id']) . ($kursusId ? ' AND a.course_id = ' . (int)$kursusId : '') . '
        ORDER BY a.tanggal DESC, us.nama')->fetchAll();
    foreach ($data as $d) {
        $rows[] = [$d['tanggal'], $d['kursus'], $d['nama'], $d['status'], $d['catatan']];
    }
    audit('export_absensi', 'Export data absensi');
    xlsx_download('absensi-' . date('Ymd') . '.xlsx', $rows);
}

$kelasList = $kursusId ? db()->query('SELECT * FROM classes WHERE course_id = ' . (int)$kursusId . ' ORDER BY nama')->fetchAll() : [];
$siswa = $kursusId ? db()->query("SELECT us.id, us.nama, us.email, us.foto FROM enrollments e JOIN users us ON us.id = e.user_id
    WHERE e.course_id = " . (int)$kursusId . ($kelasId ? ' AND us.id IN (SELECT user_id FROM class_students WHERE class_id = ' . (int)$kelasId . ')' : '') . ' ORDER BY us.nama')->fetchAll() : [];
$existing = [];
if ($kursusId && $siswa) {
    $st = db()->prepare('SELECT user_id, status, catatan FROM attendance WHERE course_id = ? AND tanggal = ?');
    $st->execute([$kursusId, $tanggal]);
    foreach ($st->fetchAll() as $r) {
        $existing[(int)$r['user_id']] = $r;
    }
}
$rekap = [];
if ($siswa) {
    $q = db()->prepare("SELECT user_id, status, COUNT(*) AS jml FROM attendance WHERE course_id = ? GROUP BY user_id, status");
    $q->execute([$kursusId]);
    foreach ($q->fetchAll() as $r) {
        $rekap[(int)$r['user_id']][$r['status']] = (int)$r['jml'];
    }
}
$riwayat = $kursusId ? db()->query("SELECT a.tanggal, COUNT(*) AS total,
        SUM(CASE WHEN a.status = 'hadir' THEN 1 ELSE 0 END) AS hadir,
        SUM(CASE WHEN a.status = 'izin' THEN 1 ELSE 0 END) AS izin,
        SUM(CASE WHEN a.status = 'sakit' THEN 1 ELSE 0 END) AS sakit,
        SUM(CASE WHEN a.status = 'alpa' THEN 1 ELSE 0 END) AS alpa
    FROM attendance a WHERE a.course_id = $kursusId GROUP BY a.tanggal ORDER BY a.tanggal DESC LIMIT 20")->fetchAll() : [];

page_start('Absensi', 'Catat kehadiran siswa per pertemuan dan pantau rekapnya.', [
    'actions' => '<a class="btn btn-ghost" href="guru-absensi.php?kursus=' . (int)$kursusId . '&export=xlsx">' . icon('download', 17) . ' Export Excel</a>',
]);
?>
<div class="card">
  <form class="toolbar">
    <div class="form-group" style="min-width:240px">
      <label>Kursus</label>
      <select name="kursus" data-autosubmit>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $kursusId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['judul']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="min-width:200px">
      <label>Kelas (opsional)</label>
      <select name="kelas" data-autosubmit>
        <option value="0">Semua siswa kursus</option>
        <?php foreach ($kelasList as $k): ?>
          <option value="<?= (int)$k['id'] ?>" <?= $kelasId === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['nama']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Tanggal pertemuan</label>
      <input type="date" name="tanggal" value="<?= e($tanggal) ?>" data-autosubmit>
    </div>
  </form>
</div>

<?php if (!$siswa): ?>
  <div class="card"><?= empty_state('Belum ada siswa yang bisa diabsen pada filter ini.', 'calendar') ?></div>
<?php else: ?>
<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="simpan">
    <input type="hidden" name="course_id" value="<?= (int)$kursusId ?>">
    <input type="hidden" name="kelas_id" value="<?= (int)$kelasId ?>">
    <input type="hidden" name="tanggal" value="<?= e($tanggal) ?>">
    <div class="card-head">
      <h2 class="mt0"><?= icon('calendar', 20) ?> Absensi <?= e(tgl_id($tanggal)) ?> &middot; <?= e(hari_id($tanggal)) ?></h2>
      <div class="row-gap">
        <button class="btn btn-ghost btn-sm" type="submit" name="aksi" value="isi_semua" data-confirm="Tandai semua siswa hadir?"><?= icon('check', 14) ?> Semua Hadir</button>
        <button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Simpan Absensi</button>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Siswa</th><th>Status Kehadiran</th><th>Catatan</th><th>Rekap</th></tr></thead>
        <tbody>
        <?php foreach ($siswa as $s):
            $cur = $existing[(int)$s['id']]['status'] ?? ''; ?>
          <tr>
            <td><div class="tbl-student"><span class="avatar sm" <?= $s['foto'] ? 'style="background-image:url(' . e($s['foto']) . ')"' : '' ?>><?= $s['foto'] ? '' : e(strtoupper(mb_substr($s['nama'], 0, 1))) ?></span><div><strong><?= e($s['nama']) ?></strong><div class="muted small"><?= e($s['email']) ?></div></div></div></td>
            <td>
              <div class="radio-row">
                <?php foreach (['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Alpa'] as $k => $v): ?>
                  <label class="check" style="margin:0">
                    <input type="radio" name="status[<?= (int)$s['id'] ?>]" value="<?= $k ?>" <?= ($cur === '' ? $k === 'hadir' : $cur === $k) ? 'checked' : '' ?>>
                    <span><?= $v ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </td>
            <td><input type="text" name="catatan[<?= (int)$s['id'] ?>]" value="<?= e((string)($existing[(int)$s['id']]['catatan'] ?? '')) ?>" placeholder="Catatan (opsional)"></td>
            <td class="small nowrap">
              <?php $r = $rekap[(int)$s['id']] ?? []; ?>
              H:<?= (int)($r['hadir'] ?? 0) ?> I:<?= (int)($r['izin'] ?? 0) ?> S:<?= (int)($r['sakit'] ?? 0) ?> A:<?= (int)($r['alpa'] ?? 0) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h2 class="mt0"><?= icon('list', 20) ?> Riwayat Absensi Kursus</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Tanggal</th><th>Hadir</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>Total</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($riwayat as $r): ?>
        <tr>
          <td><strong><?= e(tgl_id($r['tanggal'])) ?></strong><div class="muted small"><?= e(hari_id($r['tanggal'])) ?></div></td>
          <td><?= (int)$r['hadir'] ?></td>
          <td><?= (int)$r['izin'] ?></td>
          <td><?= (int)$r['sakit'] ?></td>
          <td><?= (int)$r['alpa'] ?></td>
          <td><?= (int)$r['total'] ?></td>
          <td><a class="btn btn-ghost btn-sm" href="guru-absensi.php?kursus=<?= (int)$kursusId ?>&kelas=<?= (int)$kelasId ?>&tanggal=<?= e($r['tanggal']) ?>">Buka</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$riwayat): ?><tr><td colspan="7" class="muted">Belum ada absensi tercatat.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_end(); ?>
