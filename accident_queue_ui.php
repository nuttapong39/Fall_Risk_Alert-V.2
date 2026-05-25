<?php
/**
 * accident_queue_ui.php
 * คิวแจ้งเตือน พ.ร.บ. — HR-CENTER 4.0
 * — Sticky action bar (ส่งซ้ำ / Requeue / ล้าง Error)
 * — SweetAlert2 confirm ก่อน bulk action
 * — DataTable พร้อม Export Excel + Print
 */
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
$kpi  = ['total' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0, 'today' => 0];
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

  /* Sticky action bar */
  #accStickyBar {
    position: fixed;
    bottom: 0; left: 260px; right: 0;
    z-index: 1050;
    background: #1e293b;
    border-top: 2px solid #d97706;
    padding: 12px 24px;
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    transform: translateY(100%);
    transition: transform .25s cubic-bezier(.4,0,.2,1);
    box-shadow: 0 -4px 20px rgba(0,0,0,.25);
  }
  #accStickyBar.show { transform: translateY(0); }

  @media (max-width: 991.98px) {
    #accStickyBar { left: 0; }
  }

  .acc-sel-count {
    color: #fbbf24; font-weight: 700; font-size: .88rem; flex-shrink: 0;
  }
  .acc-bar-btn {
    border: none; border-radius: 8px; font-size: .82rem; padding: .35rem .8rem;
    display: inline-flex; align-items: center; gap: .3rem; cursor: pointer;
    transition: opacity .2s, transform .1s; font-family: inherit;
  }
  .acc-bar-btn:hover  { opacity: .88; }
  .acc-bar-btn:active { transform: scale(.97); }
  .acc-bar-btn-send    { background: linear-gradient(135deg,#22c55e,#16a34a); color:#fff; }
  .acc-bar-btn-requeue { background: linear-gradient(135deg,#f59e0b,#d97706); color:#fff; }
  .acc-bar-btn-clear   { background: transparent; color:#f87171; border:1px solid #f87171; }
  .acc-bar-btn-cancel  { background: rgba(255,255,255,.1); color:#94a3b8; margin-left:auto; }

  /* Padding so last row isn't hidden behind sticky bar */
  .content-bottom-pad { padding-bottom: 80px; }
</style>
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
      <div class="kpi-icon bg-green"><span class="msi">check_circle</span></div>
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
    <div class="col-sm-6 col-md-2">
      <label>ตั้งแต่วันที่</label>
      <input type="date" class="form-control form-control-sm" name="start" value="<?= htmlspecialchars($start) ?>">
    </div>
    <div class="col-sm-6 col-md-2">
      <label>ถึงวันที่</label>
      <input type="date" class="form-control form-control-sm" name="end" value="<?= htmlspecialchars($end) ?>">
    </div>
    <div class="col-sm-6 col-md-2">
      <label>สถานะ</label>
      <select class="form-select form-select-sm" name="status">
        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
        <option value="0"   <?= $status === '0'   ? 'selected' : '' ?>>ค้างส่ง</option>
        <option value="1"   <?= $status === '1'   ? 'selected' : '' ?>>ส่งแล้ว</option>
      </select>
    </div>
    <div class="col-sm-6 col-md-3">
      <label>pttypes (คั่นด้วย ,)</label>
      <input type="text" class="form-control form-control-sm" name="pttypes"
             value="<?= htmlspecialchars($pttypes) ?>" placeholder="เช่น 33,35,36,39">
    </div>
    <div class="col-sm-6 col-md-3 d-flex gap-2 align-items-end">
      <button class="btn btn-sm btn-primary flex-grow-1">
        <span class="msi me-1">search</span> ค้นหา
      </button>
      <a class="btn btn-sm btn-outline-secondary" href="accident_queue_ui.php" title="รีเซ็ตตัวกรอง">
        <span class="msi">undo</span>
      </a>
    </div>
  </form>
</div>

<!-- Table -->
<div class="card content-bottom-pad">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><span class="msi me-2 text-warning">table</span>รายการคิวแจ้งเตือน พ.ร.บ.</span>
    <span class="badge bg-secondary"><?= count($rows) ?> รายการ</span>
  </div>
  <div class="p-3">
    <form id="bulkForm" method="post" action="accident_queue_action.php">
      <input type="hidden" name="token"  value="<?= htmlspecialchars(ACCIDENT_UI_ACTION_TOKEN) ?>">
      <input type="hidden" name="action" id="hiddenAction" value="">

      <div class="table-responsive">
        <table id="tblAcc" class="table table-hover align-middle nowrap mb-0" style="width:100%">
          <thead>
            <tr>
              <th>
                <div class="form-check mb-0">
                  <input class="form-check-input" type="checkbox" id="chkAll">
                </div>
              </th>
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
              <td>
                <div class="form-check mb-0">
                  <input class="form-check-input chk" type="checkbox"
                         name="ids[]" value="<?= $r['id'] ?>">
                </div>
              </td>
              <td><?= $r['id'] ?></td>
              <td>
                <?php if ((int)$r['status'] === 1): ?>
                  <span class="status-badge status-ok"><span class="msi">check</span>ส่งแล้ว</span>
                <?php else: ?>
                  <span class="status-badge status-pending"><span class="msi">schedule</span>ค้างส่ง</span>
                <?php endif; ?>
              </td>
              <td><strong><?= htmlspecialchars($r['hn']) ?></strong></td>
              <td><?= htmlspecialchars($r['an']) ?></td>
              <td><?= htmlspecialchars(to_utf8_acc($r['fullname'])) ?></td>
              <td><?= htmlspecialchars($r['regdate']) ?></td>
              <td><?= htmlspecialchars($r['regtime']) ?></td>
              <td><?= htmlspecialchars($r['pttype']) ?></td>
              <td style="font-size:.82rem"><?= htmlspecialchars(to_utf8_acc($r['pttname'])) ?></td>
              <td class="text-center"><?= $r['attempt'] ?></td>
              <td style="font-size:.82rem"><?= htmlspecialchars($r['last_attempt_at']) ?></td>
              <td style="font-size:.82rem"><?= htmlspecialchars($r['out_ref']) ?></td>
              <td style="font-size:.78rem; color:#dc2626; max-width:160px; white-space:normal">
                <?= htmlspecialchars(to_utf8_acc($r['last_error'])) ?>
              </td>
              <td style="font-size:.82rem"><?= htmlspecialchars($r['created_at']) ?></td>
              <td style="font-size:.82rem"><?= htmlspecialchars($r['sent_at']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div><!-- /table-responsive -->
    </form>
  </div>
</div>

<!-- ═══ Sticky Action Bar ═══ -->
<div id="accStickyBar">
  <span class="msi" style="color:#fbbf24">checklist</span>
  <span class="acc-sel-count" id="selCount">0 รายการที่เลือก</span>

  <button type="button" class="acc-bar-btn acc-bar-btn-send"
          data-action="send_now" data-label="ส่งซ้ำทันที">
    <span class="msi">send</span> ส่งซ้ำทันที
  </button>
  <button type="button" class="acc-bar-btn acc-bar-btn-requeue"
          data-action="requeue" data-label="Requeue">
    <span class="msi">refresh</span> Requeue
  </button>
  <button type="button" class="acc-bar-btn acc-bar-btn-clear"
          data-action="clear_error" data-label="ล้าง Error">
    <span class="msi">backspace</span> ล้าง Error
  </button>

  <button type="button" class="acc-bar-btn acc-bar-btn-cancel" id="btnCancelSel">
    <span class="msi">close</span> ยกเลิก
  </button>
</div>

<?php
$EXTRA_FOOTER = '
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script>
$(function(){
  // ── DataTable ─────────────────────────────────────────────────────────
  $("#tblAcc").DataTable({
    responsive: true,
    autoWidth: false,
    pageLength: 25,
    order: [[1, "desc"]],
    lengthMenu: [[10,25,50,100,-1],["10","25","50","100","ทั้งหมด"]],
    columnDefs: [
      { orderable: false, targets: 0 },   // checkbox column
    ],
    dom: \'<"row align-items-center mb-2"<"col-sm-6"B><"col-sm-6 text-end"f>>rt<"row mt-2"<"col-sm-5"i><"col-sm-7"p>>\',
    buttons: [
      {extend:"excel", text:\'<span class="msi me-1">table_view</span> Excel\',
       className:"btn btn-sm btn-outline-success",
       title:"accident_queue_"+new Date().toLocaleDateString("th-TH"),
       exportOptions:{ columns:":not(:first-child)" }},
      {extend:"print",  text:\'<span class="msi me-1">print</span> พิมพ์\',
       className:"btn btn-sm btn-outline-secondary",
       exportOptions:{ columns:":not(:first-child)" }},
    ],
    language:{
      search:"ค้นหา:", lengthMenu:"แสดง _MENU_ รายการ",
      info:"แสดง _START_–_END_ จาก _TOTAL_ รายการ",
      infoEmpty:"ไม่มีข้อมูล",
      paginate:{previous:"ก่อน",next:"ถัดไป"},
      zeroRecords:"ไม่พบข้อมูลที่ค้นหา"
    }
  });

  // ── Select All ────────────────────────────────────────────────────────
  document.getElementById("chkAll").addEventListener("change", function(){
    document.querySelectorAll(".chk").forEach(c => c.checked = this.checked);
    updateBar();
  });
  document.getElementById("tblAcc").addEventListener("change", function(e){
    if (e.target.classList.contains("chk")) updateBar();
  });

  // ── Update sticky bar ─────────────────────────────────────────────────
  function updateBar() {
    const count = document.querySelectorAll(".chk:checked").length;
    document.getElementById("selCount").textContent = count + " รายการที่เลือก";
    const bar = document.getElementById("accStickyBar");
    if (count > 0) bar.classList.add("show");
    else           bar.classList.remove("show");
    // Sync chkAll indeterminate
    const all  = document.querySelectorAll(".chk").length;
    const chkAll = document.getElementById("chkAll");
    chkAll.checked       = (count === all && all > 0);
    chkAll.indeterminate = (count > 0 && count < all);
  }

  // ── Cancel selection ─────────────────────────────────────────────────
  document.getElementById("btnCancelSel").addEventListener("click", function(){
    document.querySelectorAll(".chk, #chkAll").forEach(c => { c.checked = false; c.indeterminate = false; });
    updateBar();
  });

  // ── Bulk action buttons ───────────────────────────────────────────────
  document.querySelectorAll(".acc-bar-btn[data-action]").forEach(function(btn){
    btn.addEventListener("click", function(){
      const action = this.dataset.action;
      const label  = this.dataset.label;
      const count  = document.querySelectorAll(".chk:checked").length;
      if (count === 0) {
        Swal.fire({ icon:"warning", title:"กรุณาเลือกรายการ",
          text:"เลือกรายการในตารางก่อนดำเนินการ", confirmButtonColor:"#d97706" });
        return;
      }
      const icons   = { send_now:"send", requeue:"refresh", clear_error:"backspace" };
      const colors  = { send_now:"#16a34a", requeue:"#d97706", clear_error:"#dc2626" };
      const descs   = {
        send_now:    "ส่งซ้ำทันที (bypass cooldown) สำหรับ " + count + " รายการที่เลือก",
        requeue:     "รีเซ็ต attempt=0 status=0 สำหรับ " + count + " รายการที่เลือก",
        clear_error: "ล้างข้อความ Error สำหรับ " + count + " รายการที่เลือก",
      };
      Swal.fire({
        title: label,
        html:  \'<div class="text-start" style="font-size:.9rem">\' + descs[action] + \'</div>\',
        icon: "question",
        showCancelButton: true,
        confirmButtonText: \'<span class="msi me-1">\' + (icons[action]||"check") + \'</span> ยืนยัน\',
        cancelButtonText: "ยกเลิก",
        confirmButtonColor: colors[action] || "#1d4ed8",
        reverseButtons: true,
        focusCancel: true,
      }).then(function(r){
        if (r.isConfirmed) {
          document.getElementById("hiddenAction").value = action;
          document.getElementById("bulkForm").submit();
        }
      });
    });
  });
});
</script>
';
require_once __DIR__ . '/partials/footer.php';
?>
