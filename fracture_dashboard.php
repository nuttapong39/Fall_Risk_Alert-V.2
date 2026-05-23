<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/covid_lib.php';
// require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

/* ---- Parameters ---- */
$start   = isset($_GET['start']) && $_GET['start'] ? $_GET['start'] : date('Y-m-d', strtotime('-30 days'));
$end     = isset($_GET['end'])   && $_GET['end']   ? $_GET['end']   : date('Y-m-d');
$station = trim($_GET['mainstation'] ?? '');
$pdx     = strtoupper(trim($_GET['pdx'] ?? ''));
$sex     = trim($_GET['sex'] ?? '');
$ageMin  = isset($_GET['age_min']) && is_numeric($_GET['age_min']) ? (int)$_GET['age_min'] : null;
$ageMax  = isset($_GET['age_max']) && is_numeric($_GET['age_max']) ? (int)$_GET['age_max'] : null;
$status  = $_GET['status'] ?? 'all';

/* ---- Where clause ---- */
$w = ["created_at BETWEEN :s AND :e"];
$p = [':s' => $start . ' 00:00:00', ':e' => $end . ' 23:59:59'];
if ($station !== '') { $w[] = "mainstation LIKE :st"; $p[':st'] = "%$station%"; }
if ($pdx !== '')     { $w[] = "(UPPER(pdx_code) LIKE :px OR UPPER(pdx_name) LIKE :px)"; $p[':px'] = "%$pdx%"; }
if ($sex !== '')     { $w[] = "sex = :sx"; $p[':sx'] = $sex; }
if ($ageMin !== null){ $w[] = "age >= :amin"; $p[':amin'] = $ageMin; }
if ($ageMax !== null){ $w[] = "age <= :amax"; $p[':amax'] = $ageMax; }
if ($status === '0') { $w[] = "status=0"; }
if ($status === '1') { $w[] = "status=1"; }
$where = implode(' AND ', $w);

/* ---- Daily summary ---- */
$q = $dbcon->prepare("SELECT DATE(created_at) d,
    COUNT(*) total, SUM(status=1) sent_ok,
    SUM(status=0) pending,
    SUM(CASE WHEN last_error IS NOT NULL THEN 1 ELSE 0 END) failed
    FROM fracture_queue WHERE $where
    GROUP BY DATE(created_at) ORDER BY d");
$q->execute($p);
$rows = $q->fetchAll();

/* ---- Top station / PDx ---- */
$q2 = $dbcon->prepare("SELECT COALESCE(mainstation,'-') mainstation, COUNT(*) c
    FROM fracture_queue WHERE $where GROUP BY mainstation ORDER BY c DESC LIMIT 10");
$q2->execute($p);
$topStation = array_map('row_to_utf8', $q2->fetchAll());

$q3 = $dbcon->prepare("SELECT pdx_code, pdx_name, COUNT(*) c
    FROM fracture_queue WHERE $where GROUP BY pdx_code, pdx_name ORDER BY c DESC LIMIT 10");
$q3->execute($p);
$topPdx = array_map('row_to_utf8', $q3->fetchAll());

/* ---- Chart data ---- */
$labels = $total = $sent = $pend = $fail = [];
foreach ($rows as $r) {
    $labels[] = $r['d'];
    $total[]  = (int)$r['total'];
    $sent[]   = (int)$r['sent_ok'];
    $pend[]   = (int)$r['pending'];
    $fail[]   = (int)$r['failed'];
}

/* ---- KPI totals ---- */
$kpi = ['total' => array_sum($total), 'sent' => array_sum($sent),
        'pending' => array_sum($pend), 'failed' => array_sum($fail)];

/* ---- Page setup ---- */
$PAGE_TITLE = 'Fracture Dashboard';
$PAGE_KEY   = 'fracture_dash';
$EXTRA_HEAD = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
<style>
  .chart-card { padding: 1.25rem; }
  canvas { max-height: 320px; }
</style>
';

require_once __DIR__ . '/partials/header.php';
?>

<!-- Page header -->
<div class="page-header">
  <h1><span class="msi text-primary me-2">show_chart</span><?= htmlspecialchars($PAGE_TITLE) ?></h1>
  <a href="fracture_queue_ui.php" class="btn btn-outline-success btn-sm">
    <span class="msi me-1">list</span>ดูคิวทั้งหมด
  </a>
</div>

<!-- KPI -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-slate"><span class="msi">checklist</span></div>
      <div><p class="kpi-label">ทั้งหมด</p><p class="kpi-value"><?= number_format($kpi['total']) ?></p></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-green"><span class="msi">check_circle</span></div>
      <div><p class="kpi-label">ส่งสำเร็จ</p><p class="kpi-value" style="color:#059669"><?= number_format($kpi['sent']) ?></p></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-amber"><span class="msi">hourglass_empty</span></div>
      <div><p class="kpi-label">ค้างส่ง</p><p class="kpi-value" style="color:#d97706"><?= number_format($kpi['pending']) ?></p></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-red"><span class="msi">warning</span></div>
      <div><p class="kpi-label">ล้มเหลว</p><p class="kpi-value" style="color:#dc2626"><?= number_format($kpi['failed']) ?></p></div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card mb-4">
  <div class="card-header"><span class="msi me-2">filter_list</span>กรองข้อมูล</div>
  <div class="p-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-sm-6 col-md-3">
        <label class="form-label" style="font-size:.82rem; color:#64748b">ตั้งแต่วันที่</label>
        <input type="date" class="form-control" name="start" value="<?= htmlspecialchars($start) ?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label" style="font-size:.82rem; color:#64748b">ถึงวันที่</label>
        <input type="date" class="form-control" name="end" value="<?= htmlspecialchars($end) ?>">
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label" style="font-size:.82rem; color:#64748b">สถานบริการ</label>
        <input type="text" class="form-control" name="mainstation"
               placeholder="พิมพ์บางส่วน" value="<?= htmlspecialchars($station) ?>">
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label" style="font-size:.82rem; color:#64748b">PDX</label>
        <input type="text" class="form-control" name="pdx"
               placeholder="เช่น S72" value="<?= htmlspecialchars($pdx) ?>">
      </div>
      <div class="col-sm-6 col-md-2 d-flex gap-2 align-items-end">
        <button class="btn btn-primary flex-grow-1">
          <span class="msi me-1">search</span>กรอง
        </button>
        <a class="btn btn-outline-secondary" href="fracture_dashboard.php" title="รีเซ็ต">
          <span class="msi">undo</span>
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Chart -->
<div class="card mb-4">
  <div class="card-header"><span class="msi me-2 text-primary">area_chart</span>กราฟสรุปต่อวัน</div>
  <div class="chart-card">
    <canvas id="lineChart"></canvas>
  </div>
</div>

<!-- Top tables -->
<div class="row g-4 mb-2">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header"><span class="msi me-2">local_hospital</span>Top สถานบริการหลัก</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>สถานบริการหลัก</th><th class="text-end">จำนวน</th></tr></thead>
          <tbody>
            <?php foreach ($topStation as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['mainstation'] ?: '-') ?></td>
                <td class="text-end fw-semibold"><?= $r['c'] ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$topStation): ?>
              <tr><td colspan="2" class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card">
      <div class="card-header"><span class="msi me-2">stethoscope</span>Top PDX</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>รหัส</th><th>ชื่อโรค</th><th class="text-end">จำนวน</th></tr></thead>
          <tbody>
            <?php foreach ($topPdx as $r): ?>
              <tr>
                <td><code><?= $r['pdx_code'] ?: '-' ?></code></td>
                <td><?= htmlspecialchars($r['pdx_name'] ?: '-') ?></td>
                <td class="text-end fw-semibold"><?= $r['c'] ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$topPdx): ?>
              <tr><td colspan="3" class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
const ctx = document.getElementById('lineChart');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      { label: 'ทั้งหมด',  data: <?= json_encode($total) ?>, borderColor: '#64748b', backgroundColor: 'rgba(100,116,139,.08)', tension: .3, fill: true },
      { label: 'ส่งสำเร็จ', data: <?= json_encode($sent)  ?>, borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.08)',  tension: .3, fill: true },
      { label: 'ค้างส่ง',  data: <?= json_encode($pend)  ?>, borderColor: '#d97706', backgroundColor: 'rgba(217,119,6,.08)',  tension: .3, fill: true },
      { label: 'ล้มเหลว',  data: <?= json_encode($fail)  ?>, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.06)',  tension: .3, fill: true },
    ]
  },
  options: {
    responsive: true,
    interaction: { mode: 'index', intersect: false },
    plugins: { legend: { position: 'top' } },
    scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { color: '#f1f5f9' } } }
  }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
