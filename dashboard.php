<?php
/**
 * dashboard.php — ศูนย์รวม Dashboard ทุก module (รายเดือน)
 *   โหมด Hub    : ไม่มี ?module            → KPI รวม + กราฟเทรนด์ 12 เดือน + การ์ด 10 module
 *   โหมด Detail : ?module=<key>            → KPI + Top panels + ตาราง + Export ของ module นั้น
 * แทนที่ fracture_dashboard.php เดิม (ยกระดับเป็นหลาย module) — read-only ต่อ DB
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/covid_lib.php';        // row_to_utf8
require_once __DIR__ . '/dashboard_modules.php';
date_default_timezone_set('Asia/Bangkok');

/* ── Params ─────────────────────────────────────────────────────────────── */
$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$moduleKey = (string)($_GET['module'] ?? '');
$mod       = dash_module($moduleKey);           // null = hub mode

$span = (string)($_GET['span'] ?? 'month');
if (!in_array($span, ['month','3m','6m','9m','q'], true)) $span = 'month';
$range  = dash_span_range($span, $month);       // [span, start, end, label]
$rStart = $range['start'];
$rEnd   = $range['end'];
$SPAN_OPTS = ['month'=>'รายเดือน','3m'=>'3 เดือน','6m'=>'6 เดือน','9m'=>'9 เดือน','q'=>'ไตรมาส (ปีงบ)'];

/* ── Helpers (dash_thai_month/span/quarter อยู่ใน dashboard_modules.php) ──── */
/** นับรายเดือนย้อนหลังของ module → array ตามลำดับ $months */
function dash_trend(PDO $db, array $mod, array $months): array {
  $expr = dash_month_expr($mod);
  $st = $db->prepare("SELECT $expr ym, COUNT(*) c FROM {$mod['table']}
                      WHERE $expr BETWEEN :a AND :b GROUP BY ym");
  $st->execute([':a' => $months[0], ':b' => end($months)]);
  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[$r['ym']] = (int)$r['c'];
  return array_map(fn($m) => $map[$m] ?? 0, $months);
}
/** Top panel: group ตาม cols ในเดือน :ym */
function dash_top(PDO $db, array $mod, array $top, string $a, string $b): array {
  $expr = dash_month_expr($mod);
  $sel  = implode(', ', array_map(fn($c) => "COALESCE($c,'-') `$c`", $top['cols']));
  $grp  = implode(', ', $top['cols']);
  $st = $db->prepare("SELECT $sel, COUNT(*) c FROM {$mod['table']}
                      WHERE $expr BETWEEN :a AND :b GROUP BY $grp ORDER BY c DESC LIMIT 10");
  $st->execute([':a' => $a, ':b' => $b]);
  return array_map('row_to_utf8', $st->fetchAll(PDO::FETCH_ASSOC));
}

/* ── ช่วงเวลา ────────────────────────────────────────────────────────────── */
$monthList = [];                                  // dropdown: 15 เดือนล่าสุด
for ($i = 0; $i < 15; $i++) $monthList[] = date('Y-m', strtotime("first day of -$i month"));
$trendMonths = [];                                // กราฟเทรนด์: 12 เดือน (เก่า→ใหม่)
for ($i = 11; $i >= 0; $i--) $trendMonths[] = date('Y-m', strtotime("first day of -$i month"));
$trendLabels = array_map('dash_thai_month', $trendMonths);

/* ═══ DETAIL MODE ════════════════════════════════════════════════════════ */
if ($mod) {
  $kpi   = dash_status_counts($dbcon, $mod, $rStart, $rEnd);
  $trend = dash_trend($dbcon, $mod, $trendMonths);
  $tops  = [];
  foreach ($mod['tops'] as $t) $tops[] = ['def' => $t, 'rows' => dash_top($dbcon, $mod, $t, $rStart, $rEnd)];

  $expr = dash_month_expr($mod);
  $st = $dbcon->prepare("SELECT * FROM {$mod['table']} WHERE $expr BETWEEN :a AND :b
                         ORDER BY COALESCE({$mod['date']}, created_at) DESC LIMIT 500");
  $st->execute([':a' => $rStart, ':b' => $rEnd]);
  $rows = array_map('row_to_utf8', $st->fetchAll(PDO::FETCH_ASSOC));

  $PAGE_TITLE = 'Dashboard · ' . $mod['label'];
} else {
  /* ═══ HUB MODE ═════════════════════════════════════════════════════════ */
  $cards = [];  $sumKpi = ['total'=>0,'sent'=>0,'pending'=>0,'failed'=>0];  $datasets = [];
  foreach ($MODS = dash_modules() as $key => $m) {
    $c = dash_status_counts($dbcon, $m, $rStart, $rEnd);
    $cards[$key] = $c;
    foreach ($sumKpi as $k => $_) $sumKpi[$k] += $c[$k];
    $datasets[] = ['label' => $m['label'], 'color' => $m['color'],
                   'data' => dash_trend($dbcon, $m, $trendMonths)];
  }
  $PAGE_TITLE = 'Dashboard ศูนย์รวมทุกระบบ';
}

$PAGE_KEY = 'dashboard';
$EXTRA_HEAD = <<<'HTML'
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
<style>
  .dash-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
  .dash-month-select {
    border:1px solid var(--card-border); background:var(--card-bg); color:var(--text);
    border-radius:10px; padding:8px 14px; font-family:inherit; font-size:.9rem; font-weight:500; cursor:pointer;
  }
  canvas { max-height: 340px; }

  /* Module cards grid (hub) */
  .dash-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
  .dash-card {
    display:flex; align-items:center; gap:14px; padding:16px;
    background:var(--card-bg); border:1px solid var(--card-border); border-radius:14px;
    box-shadow:var(--card-shadow); text-decoration:none; color:inherit;
    transition:box-shadow .2s, transform .1s, border-color .2s;
  }
  .dash-card:hover { box-shadow:var(--card-shadow-hover); transform:translateY(-2px); border-color:var(--blue-100); }
  .dash-thumb {
    width:54px; height:54px; border-radius:14px; flex-shrink:0;
    display:grid; place-items:center; color:#fff; font-size:1.5rem;
    background:rgba(5,150,105,.12);   /* พื้นเขียวอ่อนโทนเดียวกับไอคอน #059669 — กลมกลืน สบายตา (ปรับตามธีม) */
  }
  /* ไอคอนในการ์ด Hub ทั้ง 10 ใบ · R:5 G:150 B:105 — !important เพื่อชนะ html[data-iconcolor] .msi */
  .dash-card .dash-thumb .msi { color:#059669 !important; }
  .dash-card-body { flex:1; min-width:0; }
  .dash-card-title { font-weight:700; font-size:.9rem; color:var(--text); line-height:1.2;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .dash-card-count { font-size:1.6rem; font-weight:700; line-height:1.15; margin-top:2px; }
  .dash-card-count small { font-size:.72rem; font-weight:500; color:var(--muted); }
  .dash-stat { display:flex; gap:12px; margin-top:4px; font-size:.72rem; color:var(--muted); }
  .dash-stat b { color:var(--text); font-weight:600; }
  .dash-dot { width:8px; height:8px; border-radius:50%; display:inline-block; vertical-align:middle; margin-right:3px; }
  .dot-ok{background:#22c55e} .dot-pend{background:#f59e0b} .dot-fail{background:#ef4444}
  .dash-card-arrow { color:#cbd5e1; flex-shrink:0; }

  /* Detail table */
  .dash-table-wrap { overflow-x:auto; }
  .dash-table { white-space:nowrap; }
  .dash-table td, .dash-table th { font-size:.82rem; }
</style>
HTML;

require_once __DIR__ . '/partials/header.php';

/* month dropdown (ใช้ทั้ง 2 โหมด) */
$moduleQS = $mod ? 'module=' . urlencode($moduleKey) . '&' : '';
?>

<?php if ($mod): /* ═══════════════ DETAIL VIEW ═══════════════ */ ?>

<div class="page-header">
  <div class="d-flex align-items-center gap-3">
    <div class="dash-thumb" style="width:44px;height:44px;font-size:1.2rem;background:linear-gradient(<?= $mod['grad'] ?>)">
      <span class="msi"><?= $mod['icon'] ?></span>
    </div>
    <div>
      <h1 style="font-size:1.2rem"><?= htmlspecialchars($mod['label']) ?></h1>
      <div style="font-size:.8rem;color:var(--muted)">ข้อมูล · <?= htmlspecialchars($range['label']) ?></div>
    </div>
  </div>
  <div class="dash-toolbar">
    <select class="dash-month-select"
            onchange="location.href='dashboard.php?<?= $moduleQS ?>span=<?= $span ?>&month='+this.value">
      <?php foreach ($monthList as $ym): ?>
        <option value="<?= $ym ?>" <?= $ym===$month?'selected':'' ?>><?= dash_thai_month($ym) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="dash-month-select"
            onchange="location.href='dashboard.php?<?= $moduleQS ?>month=<?= $month ?>&span='+this.value">
      <?php foreach ($SPAN_OPTS as $sv => $sl): ?>
        <option value="<?= $sv ?>" <?= $sv===$span?'selected':'' ?>><?= $sl ?></option>
      <?php endforeach; ?>
    </select>
    <a href="dashboard_export.php?module=<?= urlencode($moduleKey) ?>&month=<?= $month ?>&span=<?= $span ?>"
       class="btn btn-success btn-sm"><span class="msi me-1">download</span>Export Excel</a>
    <a href="<?= htmlspecialchars($mod['ui']) ?>" class="btn btn-outline-secondary btn-sm">
      <span class="msi me-1">list</span>ดูคิวทั้งหมด</a>
    <a href="dashboard.php?month=<?= $month ?>&span=<?= $span ?>" class="btn btn-outline-secondary btn-sm">
      <span class="msi me-1">grid_view</span>ภาพรวม</a>
  </div>
</div>

<!-- KPI -->
<div class="row g-3 mb-4">
  <?php
  $kpiDefs = [
    ['ทั้งหมด','checklist','bg-slate','var(--text)',$kpi['total']],
    ['ส่งสำเร็จ','check_circle','bg-green','#059669',$kpi['sent']],
    ['ค้างส่ง','hourglass_empty','bg-amber','#d97706',$kpi['pending']],
    ['ล้มเหลว','warning','bg-red','#dc2626',$kpi['failed']],
  ];
  foreach ($kpiDefs as [$lbl,$ic,$bg,$col,$val]): ?>
  <div class="col-6 col-md-3"><div class="kpi-card">
    <div class="kpi-icon <?= $bg ?>"><span class="msi"><?= $ic ?></span></div>
    <div><p class="kpi-label"><?= $lbl ?></p><p class="kpi-value" style="color:<?= $col ?>"><?= number_format($val) ?></p></div>
  </div></div>
  <?php endforeach; ?>
</div>

<!-- Trend chart -->
<div class="card mb-4">
  <div class="card-header"><span class="msi me-2" style="color:<?= $mod['color'] ?>">show_chart</span>แนวโน้มรายเดือน (12 เดือน)</div>
  <div class="p-3"><canvas id="detailChart"></canvas></div>
</div>

<!-- Top panels -->
<div class="row g-4 mb-4">
  <?php foreach ($tops as $tp): $t=$tp['def']; $cols=$t['cols']; ?>
  <div class="col-md-6"><div class="card h-100">
    <div class="card-header"><span class="msi me-2"><?= $t['icon'] ?></span><?= htmlspecialchars($t['label']) ?></div>
    <div class="table-responsive"><table class="table table-hover mb-0">
      <thead><tr>
        <?php foreach ($cols as $c): ?><th><?= htmlspecialchars($mod['columns'][$c] ?? $c) ?></th><?php endforeach; ?>
        <th class="text-end">จำนวน</th>
      </tr></thead>
      <tbody>
        <?php foreach ($tp['rows'] as $r): ?>
        <tr>
          <?php foreach ($cols as $c): ?><td><?= htmlspecialchars((string)($r[$c] ?? '-')) ?: '-' ?></td><?php endforeach; ?>
          <td class="text-end fw-semibold"><?= (int)$r['c'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$tp['rows']): ?><tr><td colspan="<?= count($cols)+1 ?>" class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div></div>
  <?php endforeach; ?>
</div>

<!-- Data table -->
<div class="card">
  <div class="card-header d-flex align-items-center">
    <span class="msi me-2">table_rows</span>รายการ (<?= count($rows) ?> แถว · สูงสุด 500)
  </div>
  <div class="dash-table-wrap"><table class="table table-hover table-sm dash-table mb-0">
    <thead><tr><?php foreach ($mod['columns'] as $lbl): ?><th><?= htmlspecialchars($lbl) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <?php foreach ($mod['columns'] as $col => $lbl): ?>
          <?php if ($col === 'status'): [$sl,$sc]=dash_row_status($r); ?>
            <td><span class="status-badge <?= $sc ?>"><?= $sl ?></span></td>
          <?php else: ?>
            <td><?= htmlspecialchars((string)($r[$col] ?? '')) ?: '<span class="text-muted">-</span>' ?></td>
          <?php endif; ?>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?= count($mod['columns']) ?>" class="text-center text-muted py-4">ไม่มีข้อมูลในเดือนนี้</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<script>
new Chart(document.getElementById('detailChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      label: <?= json_encode($mod['label'], JSON_UNESCAPED_UNICODE) ?>,
      data: <?= json_encode($trend) ?>,
      borderColor: '<?= $mod['color'] ?>',
      backgroundColor: '<?= $mod['color'] ?>22',
      tension: .3, fill: true, pointRadius: 3
    }]
  },
  options: {
    responsive: true, plugins: { legend: { display:false } },
    scales: { y: { beginAtZero:true, grid:{ color:'rgba(148,163,184,.15)' } }, x:{ grid:{ display:false } } }
  }
});
</script>

<?php else: /* ═══════════════ HUB VIEW ═══════════════ */ ?>

<div class="page-header">
  <div>
    <h1><span class="msi me-2" style="color:var(--blue)">dashboard</span>Dashboard ศูนย์รวมทุกระบบ</h1>
    <div style="font-size:.82rem;color:var(--muted)">ภาพรวมการแจ้งเตือนทุก module · <?= htmlspecialchars($range['label']) ?></div>
  </div>
  <div class="dash-toolbar">
    <select class="dash-month-select" onchange="location.href='dashboard.php?span=<?= $span ?>&month='+this.value">
      <?php foreach ($monthList as $ym): ?>
        <option value="<?= $ym ?>" <?= $ym===$month?'selected':'' ?>><?= dash_thai_month($ym) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="dash-month-select" onchange="location.href='dashboard.php?month=<?= $month ?>&span='+this.value">
      <?php foreach ($SPAN_OPTS as $sv => $sl): ?>
        <option value="<?= $sv ?>" <?= $sv===$span?'selected':'' ?>><?= $sl ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<!-- KPI รวม -->
<div class="row g-3 mb-4">
  <?php
  $sumDefs = [
    ['เคสทั้งหมด','checklist','bg-slate','var(--text)',$sumKpi['total']],
    ['ส่งสำเร็จ','check_circle','bg-green','#059669',$sumKpi['sent']],
    ['ค้างส่ง','hourglass_empty','bg-amber','#d97706',$sumKpi['pending']],
    ['ล้มเหลว','warning','bg-red','#dc2626',$sumKpi['failed']],
  ];
  foreach ($sumDefs as [$lbl,$ic,$bg,$col,$val]): ?>
  <div class="col-6 col-md-3"><div class="kpi-card">
    <div class="kpi-icon <?= $bg ?>"><span class="msi"><?= $ic ?></span></div>
    <div><p class="kpi-label"><?= $lbl ?></p><p class="kpi-value" style="color:<?= $col ?>"><?= number_format($val) ?></p></div>
  </div></div>
  <?php endforeach; ?>
</div>

<!-- Trend chart รวม -->
<div class="card mb-4">
  <div class="card-header"><span class="msi me-2" style="color:var(--blue)">stacked_line_chart</span>แนวโน้มรายเดือนแยกตาม module (12 เดือน)</div>
  <div class="p-3"><canvas id="hubChart"></canvas></div>
</div>

<!-- Module cards -->
<div class="mb-2" style="font-size:.72rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--section-lbl)">เลือก module เพื่อดูรายละเอียด</div>
<div class="dash-grid mb-3">
  <?php foreach (dash_modules() as $key => $m): $c = $cards[$key]; ?>
  <a class="dash-card" href="dashboard.php?module=<?= urlencode($key) ?>&month=<?= $month ?>&span=<?= $span ?>">
    <div class="dash-thumb"><span class="msi"><?= $m['icon'] ?></span></div>
    <div class="dash-card-body">
      <div class="dash-card-title"><?= htmlspecialchars($m['label']) ?></div>
      <div class="dash-card-count"><?= number_format($c['total']) ?> <small>เคส</small></div>
      <div class="dash-stat">
        <span><span class="dash-dot dot-ok"></span><b><?= $c['sent'] ?></b> ส่ง</span>
        <span><span class="dash-dot dot-pend"></span><b><?= $c['pending'] ?></b> ค้าง</span>
        <span><span class="dash-dot dot-fail"></span><b><?= $c['failed'] ?></b> ล้ม</span>
      </div>
    </div>
    <span class="msi dash-card-arrow">chevron_right</span>
  </a>
  <?php endforeach; ?>
</div>

<script>
new Chart(document.getElementById('hubChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: <?= json_encode(array_map(fn($d) => [
      'label' => $d['label'],
      'data'  => $d['data'],
      'borderColor' => $d['color'],
      'backgroundColor' => $d['color'] . '18',
      'tension' => .3, 'fill' => false, 'pointRadius' => 2, 'borderWidth' => 2,
    ], $datasets), JSON_UNESCAPED_UNICODE) ?>
  },
  options: {
    responsive: true,
    interaction: { mode:'index', intersect:false },
    plugins: { legend: { position:'bottom', labels:{ boxWidth:12, font:{ size:11 } } } },
    scales: { y: { beginAtZero:true, grid:{ color:'rgba(148,163,184,.15)' } }, x:{ grid:{ display:false } } }
  }
});
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
