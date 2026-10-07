<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$periode = get('periode', 'all');
$range = '';
if ($periode === 'bulan') {
    $range = " AND a.selesai >= '" . date('Y-m-01') . "'";
}

$rows = db()->query("SELECT us.id, us.nama, us.foto,
        COUNT(a.id) AS jml_attempt,
        ROUND(AVG(a.nilai), 2) AS rata,
        MAX(a.nilai) AS tertinggi,
        COALESCE((SELECT ROUND(AVG(e.progress),1) FROM enrollments e WHERE e.user_id = us.id), 0) AS progress,
        (SELECT COUNT(*) FROM certificates c WHERE c.user_id = us.id) AS sertifikat
    FROM users us LEFT JOIN quiz_attempts a ON a.user_id = us.id AND a.status != 'in_progress' $range
    WHERE us.role = 'siswa' AND us.status = 'aktif'
    GROUP BY us.id ORDER BY rata DESC, tertinggi DESC, jml_attempt DESC LIMIT 50")->fetchAll();

$peringkatSaya = 0;
foreach ($rows as $i => $r) {
    if ((int)$r['id'] === (int)$u['id']) {
        $peringkatSaya = $i + 1;
    }
}

page_start('Ranking Siswa', 'Leaderboard berdasarkan rata-rata nilai quiz seluruh siswa.', [
    'actions' => '<a class="btn btn-ghost" href="siswa-leaderboard.php?periode=' . ($periode === 'bulan' ? 'all' : 'bulan') . '">'
        . icon('filter', 16) . ' ' . ($periode === 'bulan' ? 'Lihat sepanjang waktu' : 'Lihat bulan ini') . '</a>',
]);
?>
<div class="grid cols-3 mb3">
  <div class="stat"><div class="stat-ic g4"><?= icon('trophy', 22) ?></div><div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lab">Siswa berperingkat</div></div></div>
  <div class="stat"><div class="stat-ic g1"><?= icon('chart', 22) ?></div><div><div class="stat-num"><?= $rows ? number_format((float)$rows[0]['rata'], 2) : '0' ?></div><div class="stat-lab">Nilai tertinggi</div></div></div>
  <div class="stat"><div class="stat-ic g2"><?= icon('user', 22) ?></div><div><div class="stat-num"><?= $peringkatSaya ?: '-' ?></div><div class="stat-lab">Peringkat kamu</div></div></div>
</div>

<div class="card">
  <div class="card-head">
    <h2 class="mt0"><?= icon('trophy', 20) ?> Papan Peringkat <?= $periode === 'bulan' ? '(bulan ini)' : '(sepanjang waktu)' ?></h2>
    <span class="muted small">Diurutkan dari rata-rata nilai quiz terbanyak</span>
  </div>
  <?php if (!$rows): ?><?= empty_state('Belum ada data nilai siswa.', 'trophy') ?><?php endif; ?>
  <?php foreach ($rows as $i => $r):
      $cls = $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : '')); ?>
    <div class="leader-row <?= $cls ?> <?= (int)$r['id'] === (int)$u['id'] ? 'hl' : '' ?>">
      <div class="leader-rank"><?= $i + 1 ?></div>
      <span class="avatar" <?= $r['foto'] ? 'style="background-image:url(' . e($r['foto']) . ')"' : '' ?>><?= $r['foto'] ? '' : e(strtoupper(mb_substr($r['nama'], 0, 1))) ?></span>
      <div style="flex:1">
        <strong><?= e($r['nama']) ?><?= (int)$r['id'] === (int)$u['id'] ? ' (kamu)' : '' ?></strong>
        <div class="muted small"><?= (int)$r['jml_attempt'] ?> quiz dikerjakan &middot; progress belajar <?= number_format((float)$r['progress'], 0) ?>%<?= sertifikat_aktif() ? ' &middot; ' . (int)$r['sertifikat'] . ' sertifikat' : '' ?></div>
        <div class="progress thin mt1" style="max-width:320px"><span style="width:<?= min(100, (float)$r['rata']) ?>%"></span></div>
      </div>
      <div class="right">
        <div class="stat-num" style="font-size:1.3rem"><?= number_format((float)$r['rata'], 2) ?></div>
        <div class="muted small">rata-rata</div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php page_end(); ?>
