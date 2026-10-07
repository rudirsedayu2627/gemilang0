<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login('admin');

$aksiF = get('aksi');
$userF = num('user');
$dari = get('dari');
$sampai = get('sampai');
$q = get('q');
$page = max(1, num('page', 1));

$where = ['1=1'];
$args = [];
if ($aksiF !== '') {
    $where[] = 'a.aksi = ?';
    $args[] = $aksiF;
}
if ($userF > 0) {
    $where[] = 'a.user_id = ?';
    $args[] = $userF;
}
if ($dari !== '') {
    $where[] = 'date(a.created_at) >= ?';
    $args[] = $dari;
}
if ($sampai !== '') {
    $where[] = 'date(a.created_at) <= ?';
    $args[] = $sampai;
}
if ($q !== '') {
    $where[] = '(a.detail LIKE ? OR a.ip LIKE ? OR us.nama LIKE ?)';
    $args[] = "%$q%";
    $args[] = "%$q%";
    $args[] = "%$q%";
}
$w = implode(' AND ', $where);

$cs = db()->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users us ON us.id = a.user_id WHERE $w");
$cs->execute($args);
$p = paginate((int)$cs->fetchColumn(), 30, $page);

$st = db()->prepare("SELECT a.*, us.nama, us.role FROM audit_logs a LEFT JOIN users us ON us.id = a.user_id
    WHERE $w ORDER BY a.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}");
$st->execute($args);
$rows = $st->fetchAll();

$jenisAksi = db()->query('SELECT aksi, COUNT(*) AS jml FROM audit_logs GROUP BY aksi ORDER BY jml DESC LIMIT 20')->fetchAll();
$perHari = db()->query("SELECT date(created_at) AS tgl, COUNT(*) AS jml FROM audit_logs WHERE created_at >= date('now','-13 days') GROUP BY tgl ORDER BY tgl")->fetchAll();
$maxHari = 1;
foreach ($perHari as $h) {
    $maxHari = max($maxHari, (int)$h['jml']);
}
$totalLog = (int)db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

page_start('Audit Log', $totalLog . ' aktivitas tercatat di sistem.', [
    'actions' => '<button class="btn btn-ghost" data-print>' . icon('file', 17) . ' Cetak</button>',
]);
?>
<div class="grid cols-4 mb3">
  <div class="stat"><div class="stat-ic g1"><?= icon('clock', 22) ?></div><div><div class="stat-num"><?= $totalLog ?></div><div class="stat-lab">Total aktivitas</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('shield', 22) ?></div><div><div class="stat-num"><?= count($jenisAksi) ?></div><div class="stat-lab">Jenis aksi berbeda</div></div></div>
  <div class="stat"><div class="stat-ic g5"><?= icon('check', 22) ?></div><div><div class="stat-num"><?= (int)db()->query("SELECT COUNT(*) FROM audit_logs WHERE date(created_at) = date('now')")->fetchColumn() ?></div><div class="stat-lab">Aktivitas hari ini</div></div></div>
  <div class="stat"><div class="stat-ic g4"><?= icon('users', 22) ?></div><div><div class="stat-num"><?= (int)db()->query('SELECT COUNT(DISTINCT user_id) FROM audit_logs')->fetchColumn() ?></div><div class="stat-lab">Pengguna aktif tercatat</div></div></div>
</div>

<div class="card">
  <form class="toolbar" method="get">
    <div class="form-group">
      <label>Jenis aksi</label>
      <select name="aksi" data-autosubmit>
        <option value="">Semua aksi</option>
        <?php foreach ($jenisAksi as $j): ?>
          <option value="<?= e($j['aksi']) ?>" <?= $aksiF === $j['aksi'] ? 'selected' : '' ?>><?= e($j['aksi']) ?> (<?= (int)$j['jml'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Dari tanggal</label><input type="date" name="dari" value="<?= e($dari) ?>"></div>
    <div class="form-group"><label>Sampai tanggal</label><input type="date" name="sampai" value="<?= e($sampai) ?>"></div>
    <div class="form-group" style="flex:1;min-width:170px"><label>Cari</label><input type="search" name="q" value="<?= e($q) ?>" placeholder="Detail, IP, atau nama"></div>
    <button class="btn btn-primary" type="submit"><?= icon('filter', 17) ?> Terapkan</button>
    <a class="btn btn-ghost" href="admin-audit.php">Reset</a>
  </form>
</div>

<div class="card">
  <div class="card-head"><h3 class="mt0"><?= icon('chart', 18) ?> Aktivitas 14 Hari Terakhir</h3></div>
  <div class="row-gap" style="align-items:flex-end;gap:8px">
    <?php foreach ($perHari as $h): ?>
      <div style="text-align:center;flex:1;min-width:36px">
        <div style="height:<?= max(6, round((int)$h['jml'] / $maxHari * 90)) ?>px;background:var(--grad);border-radius:8px" title="<?= (int)$h['jml'] ?> aktivitas"></div>
        <div class="muted" style="font-size:.68rem"><?= date('d/m', strtotime($h['tgl'])) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$perHari): ?><p class="muted small mb0">Belum ada aktivitas pada periode ini.</p><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Detail</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
        <tr>
          <td class="small nowrap"><?= e(tgl_id($a['created_at'], true)) ?></td>
          <td class="small"><?= e($a['nama'] ?? 'Sistem') ?><div class="muted small"><?= e(role_label($a['role'] ?? '-')) ?></div></td>
          <td><span class="chip"><?= e($a['aksi']) ?></span></td>
          <td class="small"><?= e($a['detail']) ?></td>
          <td class="muted small"><?= e($a['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="muted">Tidak ada log pada filter ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pager_html($p, 'admin-audit.php?aksi=' . urlencode($aksiF) . '&user=' . $userF . '&dari=' . urlencode($dari) . '&sampai=' . urlencode($sampai) . '&q=' . urlencode($q)) ?>
</div>
<?php page_end(); ?>
