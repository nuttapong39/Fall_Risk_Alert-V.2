<?php
require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

/* ---------- Filters ---------- */
$start   = isset($_GET['start']) && $_GET['start'] ? $_GET['start'] : date('Y-m-d', strtotime('-7 days'));
$end     = isset($_GET['end'])   && $_GET['end']   ? $_GET['end']   : date('Y-m-d');
$status  = isset($_GET['status']) ? $_GET['status'] : 'all';
$pttypes = isset($_GET['pttypes']) ? trim($_GET['pttypes']) : '';

if (!defined('ACCIDENT_UI_ACTION_TOKEN')) {
    define('ACCIDENT_UI_ACTION_TOKEN', hash('sha256', __FILE__ . php_uname() . date('Y-m-d')));
}

function to_utf8_acc($s) {
    if (!is_string($s)) return $s;
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    foreach (['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc) {
        $t = @iconv($enc, 'UTF-8//IGNORE', $s);
        if ($t !== false && $t !== '') return $t;
        $t = @mb_convert_encoding($s, 'UTF-8', $enc);
        if ($t !== false && $t !== '') return $t;
    }
    return @iconv('UTF-8', 'UTF-8//IGNORE', $s);
}

/* ---------- Query conditions ---------- */
$w = ["created_at BETWEEN :s AND :e"];
$p = [':s' => $start . ' 00:00:00', ':e' => $end . ' 23:59:59'];

if ($status === '0') { $w[] = "status=0"; }
if ($status === '1') { $w[] = "status=1"; }

$ptList = [];
if ($pttypes !== '') {
    $ptList = array_values(array_filter(array_map('trim', explode(',', $pttypes)), fn($x) => $x !== ''));
    $ptList = array_map(fn($x) => preg_replace('/[^A-Z0-9]/i', '', $x), $ptList);
    $ptList = array_values(array_unique($ptList));
}
if ($ptList) {
    $ph = [];
    foreach ($ptList as $i => $code) {
        $k = ":pt{$i}"; $ph[] = $k; $p[$k] = $code;
    }
    $w[] = "pttype IN (" . implode(',', $ph) . ")";
}

$sql = "SELECT id, hn, an, regdate, regtime, pttype, pttname, fullname,
               status, attempt, last_attempt_at, out_ref, last_error,
               created_at, sent_at, line_message_id
        FROM accident_queue
        WHERE " . implode(' AND ', $w) . "
        ORDER BY id DESC LIMIT 2000";
$stmt = $dbcon->prepare($sql);
$stmt->execute($p);
$rows = $stmt->fetchAll() ?: [];

/* ---------- KPI ---------- */
$kpi = ['total' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0, 'today' => 0];
$today = date('Y-m-d');
foreach ($rows as $r) {
    $kpi['total']++;
    if ((int)$r['status'] === 1) $kpi['sent']++;
    else                          $kpi['pending']++;
    if (!empty($r['last_error'])) $kpi['failed']++;
    if (substr($r['created_at'], 0, 10) === $today) $kpi['today']++;
}

/* ---------- Page setup ---------- */
$PAGE_TITLE = 'คิวแจ้งเตือน พ.ร.บ.';
$PAGE_KEY   = 'accident';
$EXTRA_HEAD = '
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
<style>
  .filter-card { padding: 1rem 1.15rem; margin-bottom: 1rem; }
  .filter-card label { font-size: .82rem; color: #64748b; margin-bottom: .25rem; }
</style>
';
$EXTRA_FOOTER = '
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script>
$(function(){
  $("#tbl").DataTable({
    responsive: true, autoWidth: false, pageLength: 25, order: [[1,"desc"]],
    language: {
      search: "ค้นหา:", lengthMenu: "แสดง _MENU_ รายการ",
      info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
      paginate: { first:"หน้าแรก", last:"หน้าสุดท้าย", next:"ถัดไป", previous:"ก่อนหน้า" }
    }
  });
});
</script>
';

require_once __DIR__ . '/partials/header.php';
?>

<!-- Page header -->
<div class="page-header">
  <h1><span class="msi text-warning me-2">car_crash</span><?= htmlspecialchars($PAGE_TITLE) ?></h1>
  <div class="d-flex gap-2">
    <a href="accident_queue_ui.php" class="btn btn-outline-secondary btn-sm">
      <span class="msi me-1">undo</span>รีเซ็ต
    </a>
  </div>
</div>

<!-- KPI -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-slate"><span class="msi">list</span></div>
      <div><p class="kpi-label">ทั้งหมด</p><p class="kpi-value"><?= number_format($kpi['total']) ?></p></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-amber"><span class="msi">schedule</span></div>
      <div><p class="kpi-label">ค้างส่ง</p><p class="kpi-value" style="color:#d97706"><?= number_format($kpi['pending']) ?></p></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-green"><span class="msi">check</span></div>
      <div><p class="kpi-label">ส่งสำเร็จ</p><p class="kpi-value" style="color:#059669"><?= number_format($kpi['sent']) ?></p></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-blue"><span class="msi">today</span></div>
      <div><p class="kpi-label">วันนี้</p><p class="kpi-value" style="color:#1d4ed8"><?= number_format($kpi['today']) ?></p></div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card filter-card">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-sm-6 col-md-3">
      <label>ตั้งแต่วันที่</label>
      <input type="date" class="form-control" name="start" value="<?= htmlspecialchars($start) ?>">
    </div>
    <div class="col-sm-6 col-md-3">
      <label>ถึงวันที่</label>
      <input type="date" class="form-control" name="end" value="<?= htmlspecialchars($end) ?>">
    </div>
    <div class="col-sm-6 col-md-2">
      <label>สถานะ</label>
      <select class="form-select" name="status">
        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
        <option value="0"   <?= $status === '0'   ? 'selected' : '' ?>>ค้างส่ง</option>
        <option value="1"   <?= $status === '1'   ? 'selected' : '' ?>>ส่งแล้ว</option>
      </select>
    </div>
    <div class="col-sm-6 col-md-3">
      <label>pttypes (คั่นด้วย ,)</label>
      <input type="text" class="form-control" name="pttypes"
             value="<?= htmlspecialchars($pttypes) ?>" placeholder="เช่น 33,35,36,39">
    </div>
    <div class="col-sm-6 col-md-1 d-flex gap-2">
      <button class="btn btn-primary flex-grow-1">
        <span class="msi">search</span>
      </button>
    </div>
  </form>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><span class="msi me-2 text-warning">table</span>รายการคิวแจ้งเตือน พ.ร.บ.</span>
    <span class="badge bg-secondary"><?= count($rows) ?> รายการ</span>
  </div>
  <div class="table-responsive">
    <form method="post" action="accident_queue_action.php"
          onsubmit="return confirm('ยืนยันดำเนินการกับรายการที่เลือก?');">
      <input type="hidden" name="token" value="<?= htmlspecialchars(ACCIDENT_UI_ACTION_TOKEN) ?>">

      <div class="p-3 border-bottom d-flex gap-2 flex-wrap">
        <button class="btn btn-sm btn-success" name="action" value="send_now">
          <span class="msi me-1">send</span>ส่งซ้ำทันที
        </button>
        <button class="btn btn-sm btn-warning" name="action" value="requeue">
          <span class="msi me-1">refresh</span>Requeue
        </button>
        <button class="btn btn-sm btn-outline-danger" name="action" value="clear_error">
          <span class="msi me-1">backspace</span>ล้าง Error
        </button>
      </div>

      <table id="tbl" class="table table-hover mb-0" style="width:100%">
        <thead>
          <tr>
            <th><input type="checkbox" onclick="document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked)"></th>
            <th>ID</th><th>สถานะ</th><th>HN</th><th>AN</th>
            <th>ชื่อ-สกุล</th><th>วันที่ Reg</th><th>เวลา</th>
            <th>pttype</th><th>สิทธิ</th><th>Attempt</th>
            <th>Last Attempt</th><th>Out Ref</th><th>Error</th>
            <th>Created</th><th>Sent</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><input type="checkbox" class="chk" name="ids[]" value="<?= $r['id'] ?>"></td>
            <td><?= $r['id'] ?></td>
            <td>
              <?php if ((int)$r['status'] === 1): ?>
                <span class="status-badge status-ok"><span class="msi">check</span>ส่งแล้ว</span>
              <?php else: ?>
                <span class="status-badge status-pending"><span class="msi">schedule</span>ค้างส่ง</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['hn']) ?></td>
            <td><?= htmlspecialchars($r['an']) ?></td>
            <td><?= htmlspecialchars(to_utf8_acc($r['fullname'])) ?></td>
            <td><?= htmlspecialchars($r['regdate']) ?></td>
            <td><?= htmlspecialchars($r['regtime']) ?></td>
            <td><?= htmlspecialchars($r['pttype']) ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars(to_utf8_acc($r['pttname'])) ?></td>
            <td><?= $r['attempt'] ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($r['last_attempt_at']) ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($r['out_ref']) ?></td>
            <td style="font-size:.78rem; color:#dc2626"><?= htmlspecialchars(to_utf8_acc($r['last_error'])) ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($r['created_at']) ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($r['sent_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
