<?php
/**
 * drugitems01.php
 * คิวแจ้งเตือนผู้ป่วยกลุ่มเสี่ยงยาอันตราย (High-Alert Medications)
 * Features: เหมือน fracture_queue_ui.php ทุก feature
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

// ─── Filters ─────────────────────────────────────────────────────────────────
$start  = (isset($_GET['start']) && $_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('-30 days'));
$end    = (isset($_GET['end'])   && $_GET['end'])   ? $_GET['end']   : date('Y-m-d');
$status = $_GET['status'] ?? 'all';
$drug   = trim($_GET['drug'] ?? '');

// ─── Query drug_queue ─────────────────────────────────────────────────────────
$w = ["created_at BETWEEN :s AND :e"];
$p = [':s' => $start.' 00:00:00', ':e' => $end.' 23:59:59'];
if ($status === '0') { $w[] = "status=0"; }
if ($status === '1') { $w[] = "status=1"; }
if ($drug !== '')    { $w[] = "drug_code=:drug"; $p[':drug'] = $drug; }

$rows      = [];
$tableError = null;
try {
  $sql  = "SELECT id, visit_vn, hn, fullname, cid, hometel, age, sex, address,
                  drug_code, drug_name, vstdate, department, mainstation,
                  status, attempt, last_attempt_at, out_ref, last_error,
                  created_at, sent_at, line_message_id
           FROM   drug_queue
           WHERE  ".implode(' AND ', $w)."
           ORDER  BY vstdate DESC, id DESC
           LIMIT  2000";
  $stmt = $dbcon->prepare($sql);
  $stmt->execute($p);
  $rows = $stmt->fetchAll();
} catch (Throwable $e) {
  $tableError = $e->getMessage();
}

// ─── KPI ─────────────────────────────────────────────────────────────────────
$kpi   = ['total'=>0,'pending'=>0,'sent'=>0,'failed'=>0,'today'=>0];
$today = date('Y-m-d');
foreach ($rows as $r) {
  $kpi['total']++;
  if ((int)$r['status'] === 1) $kpi['sent']++; else $kpi['pending']++;
  if (!empty($r['last_error']))                $kpi['failed']++;
  if (substr($r['created_at'],0,10)===$today)  $kpi['today']++;
}

// ─── Drug list for filter dropdown ───────────────────────────────────────────
$drugList = [];
try {
  $drugList = $dbcon->query(
    "SELECT DISTINCT drug_code, drug_name FROM drug_queue ORDER BY drug_name LIMIT 200"
  )->fetchAll();
} catch (Throwable) {}

// ─── Flash messages ───────────────────────────────────────────────────────────
$flash = '';
if (isset($_GET['msg'])) {
  $ok  = (int)($_GET['ok']       ?? 0);
  $fa  = (int)($_GET['fail']     ?? 0);
  $aff = (int)($_GET['affected'] ?? 0);
  $imp = (int)($_GET['imported'] ?? 0);
  $nw  = (int)($_GET['new']      ?? 0);
  $flash = match($_GET['msg']) {
    'sendnow'  => "success:ส่งสำเร็จ {$ok} รายการ".($fa > 0 ? " / ล้มเหลว {$fa} รายการ" : ''),
    'requeued' => "info:Requeue สำเร็จ {$aff} รายการ",
    'cleared'  => "info:ล้าง error สำเร็จ {$aff} รายการ",
    'imported' => "success:Sync จาก HOSxP สำเร็จ {$imp} รายการ (ใหม่ {$nw} รายการ)",
    'no_ids'   => "warning:ยังไม่ได้เลือกรายการ",
    'bad_action'=>"danger:คำสั่งไม่ถูกต้อง",
    'err'      => "danger:เกิดข้อผิดพลาด: ".htmlspecialchars($_GET['detail']??''),
    default    => '',
  };
}

// ─── UTF-8 helper ────────────────────────────────────────────────────────────
function to_utf8_dq($s){
  if(!is_string($s)) return $s;
  if(mb_check_encoding($s,'UTF-8')) return $s;
  foreach(['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc){
    $t=@iconv($enc,'UTF-8//IGNORE',$s); if($t!==false&&$t!=='') return $t;
    $t=@mb_convert_encoding($s,'UTF-8',$enc); if($t!==false&&$t!=='') return $t;
  }
  return @iconv('UTF-8','UTF-8//IGNORE',$s);
}

if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', hash('sha256', __DIR__ . '/drugitems01.php' . php_uname() . date('Y-m-d')));
}

// ─── Page variables ───────────────────────────────────────────────────────────
$PAGE_TITLE = 'คิวแจ้งเตือนกลุ่มเสี่ยงยาอันตราย';
$PAGE_KEY   = 'drug';
$EXTRA_HEAD = '
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
<style>
  .filter-card { padding:1rem 1.15rem; margin-bottom:1rem }
  .filter-card label { font-size:.82rem; color:#64748b; margin-bottom:.25rem }
  .table thead th { white-space:nowrap; font-size:.82rem; color:#475569; background:#f8fafc; border-bottom:1px solid #e2e8f0 }
  .table td { font-size:.88rem; vertical-align:middle }
  .table td.small-text { font-size:.82rem }
  .dt-buttons .btn { border-radius:.5rem }
  .action-bar {
    position:sticky; bottom:1rem; z-index:50;
    background:#fff; border:1px solid #e2e8f0; border-radius:14px;
    padding:.75rem 1rem; box-shadow:0 10px 25px rgba(15,23,42,.12);
    display:flex; align-items:center; gap:.5rem; flex-wrap:wrap
  }
  .action-bar .selected-count { font-weight:600; color:#0f172a; margin-right:auto }
  .form-check-input:focus { box-shadow:0 0 0 .2rem rgba(234,88,12,.25) }

  /* ── Import modal ── */
  .import-chip {
    display:inline-flex; align-items:center; gap:4px;
    background:#fef3c7; border:1px solid #fde68a;
    color:#92400e; border-radius:8px;
    font-size:.75rem; font-weight:600; padding:3px 9px;
  }
  #syncResult { display:none; border-radius:8px; padding:10px 14px; font-size:.85rem; font-weight:500; margin-top:10px }
  #syncResult.ok  { background:#dcfce7; color:#166534 }
  #syncResult.err { background:#fee2e2; color:#991b1b }

  /* ── No-table error ── */
  .setup-alert {
    background:linear-gradient(135deg,#7c3aed,#4f46e5);
    color:#fff; border-radius:14px; padding:20px 24px;
    display:flex; align-items:flex-start; gap:16px;
  }
  .setup-alert-icon { font-size:2rem; flex-shrink:0; }
  .setup-alert h5 { font-size:1rem; font-weight:700; margin:0 0 4px; }
  .setup-alert p  { font-size:.83rem; margin:0; opacity:.9; }
</style>
';

require_once __DIR__ . '/partials/header.php';
?>

<!-- ═══════════════════════════════════════════════
     PAGE CONTENT
═══════════════════════════════════════════════ -->

<!-- Page header -->
<div class="page-header">
  <h1>
    <span class="msi me-2" style="color:#ea580c">medication</span>
    <?= htmlspecialchars($PAGE_TITLE) ?>
  </h1>
  <div class="d-flex gap-2 flex-wrap">
    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#syncModal">
      <span class="msi me-1">sync</span> Sync จาก HOSxP
    </button>
  </div>
</div>

<!-- Flash messages -->
<?php if ($flash):
  [$ft, $fm] = explode(':', $flash, 2) + ['info',''];
  $ftMap = ['success'=>'alert-success','info'=>'alert-info','warning'=>'alert-warning','danger'=>'alert-danger'];
  $ftIcon = ['success'=>'check_circle','info'=>'info','warning'=>'warning','danger'=>'error'];
?>
<div class="alert <?= $ftMap[$ft]??'alert-info' ?> d-flex align-items-center gap-2 mb-3"
     style="border-radius:10px; font-size:.9rem">
  <span class="msi"><?= $ftIcon[$ft]??'info' ?></span> <?= $fm ?>
</div>
<?php endif; ?>

<?php if ($tableError): ?>
<!-- ตาราง drug_queue ยังไม่ได้ import SQL -->
<div class="setup-alert mb-4">
  <div class="setup-alert-icon"><span class="msi">table_chart</span></div>
  <div>
    <h5>ยังไม่พบตาราง drug_queue</h5>
    <p>
      กรุณา Import ไฟล์ <code>drug_queue.sql</code> เข้าฐานข้อมูล<br>
      <small style="opacity:.8">Error: <?= htmlspecialchars($tableError) ?></small>
    </p>
    <a href="drug_queue.sql" download class="btn btn-light btn-sm mt-2">
      <span class="msi me-1">download</span> ดาวน์โหลด drug_queue.sql
    </a>
  </div>
</div>
<?php else: ?>

<!-- KPI Summary -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-slate"><span class="msi">list</span></div>
      <div>
        <p class="kpi-label">ทั้งหมด</p>
        <p class="kpi-value"><?= number_format($kpi['total']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-amber"><span class="msi">schedule</span></div>
      <div>
        <p class="kpi-label">ค้างส่ง</p>
        <p class="kpi-value text-warning"><?= number_format($kpi['pending']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-green"><span class="msi">check</span></div>
      <div>
        <p class="kpi-label">ส่งสำเร็จ</p>
        <p class="kpi-value text-success"><?= number_format($kpi['sent']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-blue"><span class="msi">today</span></div>
      <div>
        <p class="kpi-label">วันนี้</p>
        <p class="kpi-value text-primary"><?= number_format($kpi['today']) ?></p>
      </div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card filter-card">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-sm-6 col-md-2">
      <label for="f-start">ตั้งแต่วันที่</label>
      <input type="date" id="f-start" class="form-control" name="start"
             value="<?= htmlspecialchars($start) ?>">
    </div>
    <div class="col-sm-6 col-md-2">
      <label for="f-end">ถึงวันที่</label>
      <input type="date" id="f-end" class="form-control" name="end"
             value="<?= htmlspecialchars($end) ?>">
    </div>
    <div class="col-sm-6 col-md-3">
      <label for="f-status">สถานะ</label>
      <select id="f-status" class="form-select" name="status">
        <option value="all" <?= $status==='all'?'selected':'' ?>>ทั้งหมด</option>
        <option value="0"   <?= $status==='0'?'selected':'' ?>>ค้างส่ง</option>
        <option value="1"   <?= $status==='1'?'selected':'' ?>>ส่งแล้ว</option>
      </select>
    </div>
    <div class="col-sm-6 col-md-3">
      <label for="f-drug">รหัสยา (icode)</label>
      <select id="f-drug" class="form-select" name="drug">
        <option value="">ยาทั้งหมด</option>
        <?php foreach ($drugList as $dl): ?>
        <option value="<?= htmlspecialchars($dl['drug_code']) ?>"
                <?= $drug===$dl['drug_code']?'selected':'' ?>>
          <?= htmlspecialchars($dl['drug_code'].' — '.$dl['drug_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-12 col-md-2 d-flex gap-2">
      <button class="btn btn-success flex-grow-1">
        <span class="msi me-1">search</span> ค้นหา
      </button>
      <a class="btn btn-outline-secondary" href="drugitems01.php" title="รีเซ็ต">
        <span class="msi">undo</span>
      </a>
    </div>
  </form>
</div>

<!-- Table + Bulk Actions -->
<form method="post" action="drug_queue_action.php" id="actionForm">
  <input type="hidden" name="token" value="<?= htmlspecialchars(UI_ACTION_TOKEN) ?>">

  <div class="card p-3 mb-3">
    <div class="table-responsive">
      <table id="tbl" class="table table-hover align-middle nowrap" style="width:100%">
        <thead>
          <tr>
            <th style="width:30px">
              <input type="checkbox" class="form-check-input" id="chkAll" aria-label="เลือกทั้งหมด">
            </th>
            <th>ID</th>
            <th>สถานะ</th>
            <th>HN</th>
            <th>ชื่อ-สกุล</th>
            <th>อายุ</th>
            <th>เพศ</th>
            <th>รหัสยา</th>
            <th>ชื่อยา</th>
            <th>วันรับบริการ</th>
            <th>แผนก / สถานะ</th>
            <th>สถานบริการ</th>
            <th>Attempt</th>
            <th>Last Attempt</th>
            <th>Out Ref</th>
            <th>Error</th>
            <th>Created</th>
            <th>Sent</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $isDone = (int)$r['status'] === 1;
          $hasErr = !empty($r['last_error']);
          if ($hasErr && !$isDone) {
            $badge = '<span class="status-badge status-fail"><span class="msi">close</span> ล้มเหลว</span>';
          } elseif ($isDone) {
            $badge = '<span class="status-badge status-ok"><span class="msi">check</span> ส่งแล้ว</span>';
          } else {
            $badge = '<span class="status-badge status-pending"><span class="msi">schedule</span> ค้างส่ง</span>';
          }
        ?>
          <tr>
            <td><input type="checkbox" class="form-check-input chk" name="ids[]" value="<?= $r['id'] ?>"></td>
            <td><?= $r['id'] ?></td>
            <td><?= $badge ?></td>
            <td><?= htmlspecialchars($r['hn']) ?></td>
            <td><?= htmlspecialchars(to_utf8_dq($r['fullname'])) ?></td>
            <td><?= $r['age'] ?></td>
            <td><?= htmlspecialchars(to_utf8_dq($r['sex'])) ?></td>
            <td><code><?= htmlspecialchars($r['drug_code']) ?></code></td>
            <td class="small-text"><?= htmlspecialchars(to_utf8_dq($r['drug_name'])) ?></td>
            <td><?= $r['vstdate'] ?></td>
            <td class="small-text"><?= htmlspecialchars(to_utf8_dq($r['department'])) ?></td>
            <td class="small-text"><?= htmlspecialchars(to_utf8_dq($r['mainstation'])) ?></td>
            <td class="text-center"><?= $r['attempt'] ?></td>
            <td class="small-text"><?= $r['last_attempt_at'] ?></td>
            <td class="small-text"><?= htmlspecialchars($r['out_ref']) ?></td>
            <td class="small-text text-danger"><?= htmlspecialchars($r['last_error']) ?></td>
            <td class="small-text"><?= $r['created_at'] ?></td>
            <td class="small-text"><?= $r['sent_at'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Sticky action bar -->
  <div class="action-bar">
    <span class="selected-count" id="selectedCount">เลือก 0 รายการ</span>
    <button type="button" class="btn btn-success btn-sm"
            data-action="send_now" data-label="ส่งซ้ำทันที" data-confirm-icon="question">
      <span class="msi me-1">send</span> ส่งซ้ำทันที
    </button>
    <button type="button" class="btn btn-warning btn-sm"
            data-action="requeue" data-label="Requeue" data-confirm-icon="warning">
      <span class="msi me-1">refresh</span> Requeue
    </button>
    <button type="button" class="btn btn-outline-danger btn-sm"
            data-action="clear_error" data-label="ล้าง error" data-confirm-icon="warning">
      <span class="msi me-1">backspace</span> ล้าง error
    </button>
  </div>
</form>

<?php endif; // end tableError check ?>

<!-- ═══════════════════════════════════════════════
     SYNC FROM HOSxP MODAL
═══════════════════════════════════════════════ -->
<div class="modal fade" id="syncModal" tabindex="-1" aria-labelledby="syncModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:14px; overflow:hidden">
      <div class="modal-header" style="background:linear-gradient(135deg,#ea580c,#d97706); color:#fff; border:none">
        <h5 class="modal-title" id="syncModalLabel">
          <span class="msi me-2">sync</span>Sync ข้อมูลจาก HOSxP
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold" for="syncStart">
            <span class="msi me-1" style="font-size:1rem">calendar_today</span>ช่วงวันที่ดึงข้อมูล
          </label>
          <div class="row g-2">
            <div class="col">
              <input type="date" id="syncStart" class="form-control"
                     value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
            </div>
            <div class="col-auto d-flex align-items-center text-muted">ถึง</div>
            <div class="col">
              <input type="date" id="syncEnd" class="form-control"
                     value="<?= date('Y-m-d') ?>">
            </div>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="syncIcodes">
            <span class="msi me-1" style="font-size:1rem">medication</span>รหัสยา (icode)
          </label>
          <input type="text" id="syncIcodes" class="form-control font-monospace"
                 value="1483860"
                 placeholder="ระบุ icode คั่นด้วย , เช่น 1483860,2234567">
          <div class="form-text">
            คั่นหลาย icode ด้วยเครื่องหมาย <code>,</code> หรือช่องว่าง
          </div>
        </div>
        <div class="p-2 rounded" style="background:#fef3c7; border:1px solid #fde68a; font-size:.8rem; color:#92400e">
          <span class="msi me-1" style="font-size:1rem">info</span>
          ระบบจะ Query จาก <strong>opitemrece</strong> ใน HOSxP แล้ว Upsert เข้า <code>drug_queue</code>
          รายการที่มีอยู่แล้วจะอัปเดตเฉพาะชื่อยาและแผนก ไม่รีเซ็ตสถานะการส่ง
        </div>
        <div id="syncResult"></div>
      </div>
      <div class="modal-footer" style="border:none; padding-top:0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-warning px-4" id="syncBtn" onclick="doSync()">
          <span class="msi me-1" id="syncIcon">sync</span>
          <span id="syncBtnText">Sync ข้อมูล</span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php
// ─── Extra JS ──────────────────────────────────────────────────────────────
$EXTRA_FOOTER = '
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.colVis.min.js"></script>
<script>
$(function(){

  // ── DataTable ──────────────────────────────────────────────────────────────
  const table = $("#tbl").DataTable({
    responsive: true,
    autoWidth:  false,
    pageLength: 25,
    order: [[9,"desc"]],
    dom: "<\"row mb-2\"<\"col-sm-4\"l><\"col-sm-4 text-center\"B><\"col-sm-4\"f>>tip",
    buttons: [
      {
        extend: "colvis",
        text: "<span class=\"msi\">view_column<\/span> คอลัมน์",
        className: "btn btn-outline-secondary btn-sm",
        columns: ":not(:first-child)"
      }
    ],
    language: {
      search: "ค้นหา:", lengthMenu: "แสดง _MENU_ รายการ",
      info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
      infoEmpty: "ไม่มีรายการ",
      zeroRecords: "ไม่พบรายการที่ตรงกับคำค้น",
      paginate: { first:"หน้าแรก", last:"หน้าสุดท้าย", next:"ถัดไป", previous:"ก่อนหน้า" }
    },
    columnDefs: [
      { targets:[0,1,2,12], className:"text-nowrap text-center" }
    ]
  });

  // ── Select-all checkbox ────────────────────────────────────────────────────
  const $chkAll = $("#chkAll");
  $chkAll.on("change", function(){
    $("#tbl tbody .chk").prop("checked", this.checked);
    updateCount();
  });
  $(document).on("change", ".chk", updateCount);
  table.on("draw", function(){
    $chkAll.prop("checked", false);
    updateCount();
  });
  function updateCount(){
    const n = $("#tbl tbody .chk:checked").length;
    $("#selectedCount").text("เลือก " + n + " รายการ");
  }

  // ── Bulk action buttons with SweetAlert2 confirm ───────────────────────────
  $("[data-action]").on("click", function(){
    const action = $(this).data("action");
    const label  = $(this).data("label");
    const icon   = $(this).data("confirm-icon") || "question";
    const n = $("#tbl tbody .chk:checked").length;
    if (n === 0){
      Swal.fire({ icon:"info", title:"ยังไม่ได้เลือกรายการ",
                  text:"กรุณาติ๊กเลือกรายการในตารางก่อน" });
      return;
    }
    Swal.fire({
      icon: icon,
      title: "ยืนยัน " + label + "?",
      text: "จะดำเนินการกับรายการที่เลือก " + n + " รายการ",
      showCancelButton: true,
      confirmButtonText: label,
      cancelButtonText: "ยกเลิก",
      reverseButtons: true,
      focusCancel: true,
      confirmButtonColor: "#059669"
    }).then(r => {
      if (r.isConfirmed){
        const $form = $("#actionForm");
        $form.append("<input type=\"hidden\" name=\"action\" value=\""+action+"\">");
        $form[0].submit();
      }
    });
  });
});

// ── Sync from HOSxP (AJAX) ─────────────────────────────────────────────────
function doSync() {
  const btn      = document.getElementById("syncBtn");
  const icon     = document.getElementById("syncIcon");
  const btnText  = document.getElementById("syncBtnText");
  const result   = document.getElementById("syncResult");
  const start    = document.getElementById("syncStart").value;
  const end      = document.getElementById("syncEnd").value;
  const icodes   = document.getElementById("syncIcodes").value.trim();

  if (!icodes) {
    result.style.display = "block";
    result.className = "err";
    result.textContent = "กรุณาระบุรหัสยา (icode) อย่างน้อย 1 รายการ";
    return;
  }

  btn.disabled   = true;
  icon.textContent = "sync";
  icon.classList.add("msi-spin");
  btnText.textContent = "กำลัง Sync...";
  result.style.display = "none";

  const fd = new FormData();
  fd.append("action", "import_hosxp");
  fd.append("start",  start);
  fd.append("end",    end);
  fd.append("icodes", icodes);

  fetch("drug_queue_action.php", { method:"POST", body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      icon.classList.remove("msi-spin");
      icon.textContent = "sync";
      btnText.textContent = "Sync ข้อมูล";
      result.style.display = "block";
      result.className = data.ok ? "ok" : "err";
      result.innerHTML = (data.ok
        ? "<span class=\"msi me-1\">check_circle<\/span>"
        : "<span class=\"msi me-1\">error<\/span>") + data.msg;
      if (data.ok) {
        // รีโหลดหน้าหลังจาก 1.5 วินาที
        setTimeout(() => {
          window.location.href = "drugitems01.php?msg=imported&imported="
            + (data.imported||0) + "&new=" + (data.new||0);
        }, 1500);
      }
    })
    .catch(err => {
      btn.disabled = false;
      icon.classList.remove("msi-spin");
      icon.textContent = "sync";
      btnText.textContent = "Sync ข้อมูล";
      result.style.display = "block";
      result.className = "err";
      result.innerHTML = "<span class=\"msi me-1\">error<\/span>เกิดข้อผิดพลาด: " + err;
    });
}
</script>
';

require_once __DIR__ . '/partials/footer.php';
?>
