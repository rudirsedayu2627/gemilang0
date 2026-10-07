<?php
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('aksi') === 'tambah') {
        $judul = post('judul');
        if ($judul !== '') {
            db()->prepare('INSERT INTO schedules (user_id, course_id, judul, tipe, tanggal, jam_mulai, jam_selesai, catatan, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$u['id'], post('course_id') ?: null, $judul, post('tipe', 'belajar'), post('tanggal'), post('jam_mulai', '19:00'), post('jam_selesai', '20:00'), post('catatan'), post('color', '#4F46E5')]);
            audit('tambah_jadwal', 'Menambah agenda belajar ' . $judul);
            flash('Agenda belajar ditambahkan.');
        }
        redirect('siswa-kalender.php?bulan=' . urlencode(substr(post('tanggal', date('Y-m-d')), 0, 7)));
    }
    if (post('aksi') === 'hapus') {
        db()->prepare('DELETE FROM schedules WHERE id = ? AND user_id = ?')->execute([(int)post('id'), $u['id']]);
        flash('Agenda dihapus.');
        redirect('siswa-kalender.php');
    }
}

$bulan = get('bulan', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}
$first = strtotime($bulan . '-01');
$daysInMonth = (int)date('t', $first);
$startDow = (int)date('N', $first); // 1=Senin
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$bulanLabel = $namaBulan[(int)date('n', $first) - 1];
$tahunLabel = date('Y', $first);

// agenda pribadi
$agenda = db()->query('SELECT * FROM schedules WHERE user_id = ' . (int)$u['id'] . " AND tanggal BETWEEN '$bulan-01' AND '$bulan-$daysInMonth'")->fetchAll();
// jadwal kelas dari kursus yang diikuti
$kelasAgenda = db()->query('SELECT c.nama AS judul, c.jadwal, co.judul AS kursus FROM classes c JOIN courses co ON co.id = c.course_id
    JOIN class_students cs ON cs.class_id = c.id AND cs.user_id = ' . (int)$u['id'])->fetchAll();
// deadline tugas
$tugas = db()->query('SELECT a.judul, a.deadline, co.judul AS kursus FROM assignments a JOIN enrollments e ON e.course_id = a.course_id AND e.user_id = ' . (int)$u['id'] . " LEFT JOIN courses co ON co.id = a.course_id WHERE a.deadline LIKE '$bulan%'")->fetchAll();

$ev = [];
foreach ($agenda as $a) {
    $ev[substr($a['tanggal'], 8, 2)][] = ['judul' => $a['judul'], 'tipe' => $a['tipe'], 'jam' => substr((string)$a['jam_mulai'], 0, 5) . '-' . substr((string)$a['jam_selesai'], 0, 5), 'id' => $a['id'], 'color' => $a['color']];
}
foreach ($tugas as $t) {
    $d = substr($t['deadline'], 8, 2);
    $ev[$d][] = ['judul' => 'Deadline: ' . $t['judul'], 'tipe' => 'tugas', 'jam' => substr($t['deadline'], 11, 5), 'id' => 0, 'color' => '#EC4899'];
}

$kursus = db()->query('SELECT co.id, co.judul FROM enrollments e JOIN courses co ON co.id = e.course_id WHERE e.user_id = ' . (int)$u['id'])->fetchAll();
$mendatang = db()->query('SELECT * FROM schedules WHERE user_id = ' . (int)$u['id'] . ' AND tanggal >= date("now") ORDER BY tanggal, jam_mulai LIMIT 10')->fetchAll();

page_start('Kalender Belajar', 'Atur jadwal belajar, pantau kelas, dan deadline tugas.');
?>
<div class="split">
  <div>
    <div class="card">
      <div class="card-head">
        <h2 class="mt0"><?= icon('calendar', 20) ?> <?= e($bulanLabel . ' ' . $tahunLabel) ?></h2>
        <div class="row-gap">
          <a class="btn btn-ghost btn-sm" href="siswa-kalender.php?bulan=<?= e($prev) ?>"><?= icon('arrow-left', 15) ?></a>
          <a class="btn btn-ghost btn-sm" href="siswa-kalender.php?bulan=<?= date('Y-m') ?>">Bulan ini</a>
          <a class="btn btn-ghost btn-sm" href="siswa-kalender.php?bulan=<?= e($next) ?>"><?= icon('arrow-right', 15) ?></a>
        </div>
      </div>
      <div class="cal-grid mb2">
        <?php foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $h): ?>
          <div class="cal-head"><?= $h ?></div>
        <?php endforeach; ?>
        <?php for ($i = 1; $i < $startDow; $i++): ?><div class="cal-day empty-day"></div><?php endfor; ?>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $key = str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            $isToday = date('Y-m-d') === "$bulan-$key"; ?>
          <div class="cal-day <?= $isToday ? 'today' : '' ?>">
            <div class="cal-date"><?= $d ?></div>
            <?php foreach ($ev[$key] ?? [] as $x): ?>
              <div class="cal-ev t-<?= e($x['tipe']) ?>" title="<?= e($x['judul'] . ' ' . $x['jam']) ?>"><?= e(mb_strimwidth($x['judul'], 0, 22, '..')) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endfor; ?>
      </div>
      <div class="row-gap">
        <span class="chip">Kelas / agenda</span>
        <span class="chip" style="background:rgba(236,72,153,.16)">Deadline tugas</span>
        <span class="chip" style="background:rgba(14,165,233,.16)">Belajar mandiri</span>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2 class="mt0"><?= icon('list', 20) ?> Agenda Bulan Ini</h2></div>
      <?php if (!$ev): ?><p class="muted small">Belum ada agenda pada bulan ini.</p><?php endif; ?>
      <div class="table-wrap">
        <?php if ($ev): ?>
        <table class="data">
          <thead><tr><th>Tanggal</th><th>Agenda</th><th>Jam</th><th>Tipe</th><th></th></tr></thead>
          <tbody>
          <?php ksort($ev); foreach ($ev as $d => $items): foreach ($items as $x): ?>
            <tr>
              <td class="nowrap"><?= (int)$d ?> <?= e(mb_substr($bulanLabel, 0, 3)) ?></td>
              <td><?= e($x['judul']) ?></td>
              <td><?= e($x['jam']) ?></td>
              <td><span class="chip"><?= e(ucfirst($x['tipe'])) ?></span></td>
              <td>
                <?php if ($x['id']): ?>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="hapus">
                    <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
                    <button class="btn btn-danger btn-sm" data-confirm="Hapus agenda ini?"><?= icon('trash', 14) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <aside>
    <div class="card">
      <h3 class="mt0"><?= icon('plus', 18) ?> Tambah Agenda Belajar</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="tambah">
        <div class="form-group">
          <label>Judul agenda</label>
          <input type="text" name="judul" placeholder="Mis. Latihan listening 30 menit" required>
        </div>
        <div class="form-group">
          <label>Tanggal</label>
          <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-grid">
          <div class="form-group"><label>Jam mulai</label><input type="time" name="jam_mulai" value="19:00"></div>
          <div class="form-group"><label>Jam selesai</label><input type="time" name="jam_selesai" value="20:00"></div>
        </div>
        <div class="form-group">
          <label>Tipe</label>
          <select name="tipe">
            <option value="belajar">Belajar mandiri</option>
            <option value="kelas">Kelas</option>
            <option value="ujian">Ujian / Quiz</option>
            <option value="lainnya">Lainnya</option>
          </select>
        </div>
        <div class="form-group">
          <label>Kursus terkait</label>
          <select name="course_id">
            <option value="">- tidak terkait -</option>
            <?php foreach ($kursus as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['judul']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Catatan</label>
          <textarea name="catatan" placeholder="Detail tambahan..."></textarea>
        </div>
        <button class="btn btn-primary btn-block" type="submit"><?= icon('check', 17) ?> Simpan Agenda</button>
      </form>
    </div>

    <div class="card">
      <h3 class="mt0"><?= icon('grid', 18) ?> Jadwal Kelas Saya</h3>
      <?php if (!$kelasAgenda): ?><p class="muted small">Kamu belum terdaftar di kelas terjadwal.</p><?php endif; ?>
      <?php foreach ($kelasAgenda as $k): ?>
        <div class="notice">
          <div class="notice-ic"><?= icon('calendar', 16) ?></div>
          <div><h4><?= e($k['judul']) ?></h4><p><?= e($k['kursus']) ?><br><?= e($k['jadwal']) ?></p></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h3 class="mt0"><?= icon('clock', 18) ?> Agenda Mendatang</h3>
      <?php if (!$mendatang): ?><p class="muted small">Belum ada agenda mendatang.</p><?php endif; ?>
      <?php foreach ($mendatang as $m): ?>
        <div class="row-gap mb2">
          <div class="leader-rank" style="background:<?= e($m['color'] ?: '#4F46E5') ?>;color:#fff"><?= date('d', strtotime($m['tanggal'])) ?></div>
          <div><strong class="small"><?= e($m['judul']) ?></strong><div class="muted small"><?= e(substr((string)$m['jam_mulai'], 0, 5)) ?>-<?= e(substr((string)$m['jam_selesai'], 0, 5)) ?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </aside>
</div>
<?php page_end(); ?>
