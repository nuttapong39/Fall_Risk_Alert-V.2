<?php
/**
 * pharm_lab_queue_ui.php — คิวแจ้งเตือน Lab วิกฤต / ต้องเฝ้าระวังห้องยา
 *  - อ่านข้อมูลจาก pharm_lab_queue
 *  - กรอง INR (≥5 / ≥3.5), Depakin (>150), Lithium (>1.2), Phenytoin (>20) ถูกคัดมาจาก ingest แล้ว
 *  - ใช้ partials/header.php + footer.php (AdminLTE + Bootstrap5)
 *  - ใช้ DataTables + SweetAlert + Bulk action bar
 */
require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

/* ---------------- Filters ---------------- */
/**
 * ใช้ช่วงเวลาค่อนข้างกว้าง เพื่อให้ backfill / ประวัติเก่ายังมองเห็นได้เสมอ
 * (เทียบกับ patient.php ที่ default = 2025-06-01)
 */
$DEFAULT_PHARM_START = '2025-06-01';
$start  = isset($_GET['start']) && $_GET['start'] ? $_GET['start'] : $DEFAULT_PHARM_START;
$end    = isset($_GET['end'])   && $_GET['end']   ? $_GET['end']   : date('Y-m-d');
$status = isset($_GET['status']) ? $_GET['status'] : 'all'; // all | 0 | 1
$lab    = isset($_GET['lab'])    ? trim($_GET['lab'])   : 'all'; // all | INR | Depakin | Lithium | Phenytoin

$w = ["created_at BETWEEN :s AND :e"];
$p = [':s'=>$start.' 00:00:00', ':e'=>$end.' 23:59:59'];
if ($status==='0') { $w[] = "status=0"; }
if ($status==='1') { $w[] = "status=1"; }
if ($lab !== 'all' && $lab !== '') {
  $w[] = "lab_name LIKE :lab";
  $p[':lab'] = '%'.$lab.'%';
}

/* ---------------- Query ---------------- */
$sql = "SELECT id, hn, fullname, age, lab_date, lab_time, doctor,
               lab_name, result, patient_type, lab_order_number,
               reported_by_id, reported_by_name, reported_date, reported_time,
               status, attempt, last_attempt_at, out_ref, last_error,
               created_at, sent_at, line_message_id
        FROM pharm_lab_queue
        WHERE ".implode(' AND ', $w)."
        ORDER BY id DESC
        LIMIT 2000";
try {
  $stmt = $dbcon->prepare($sql);
  $stmt->execute($p);
  $rows = $stmt->fetchAll();
} catch (Throwable $e) {
  $rows = [];
  $queryError = $e->getMessage();
}

/* ---------------- KPI Summary ---------------- */
$kpi = ['total'=>0, 'pending'=>0, 'sent'=>0, 'failed'=>0, 'today'=>0, 'reported'=>0];
$today = date('Y-m-d');
foreach ($rows as $r) {
    $kpi['total']++;
    if ((int)$r['status'] === 1)    $kpi['sent']++;
    else                             $kpi['pending']++;
    if (!empty($r['last_error']))   $kpi['failed']++;
    if (substr($r['created_at'],0,10) === $today) $kpi['today']++;
    if (!empty($r['reported_by_name']) && !empty($r['reported_date'])) $kpi['reported']++;
}

/* UTF-8 helper (shared) */
if (!function_exists('to_utf8')) {
  function to_utf8($s){
    if(!is_string($s)) return $s;
    if(mb_check_encoding($s,'UTF-8')) return $s;
    foreach(['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc){
      $t=@iconv($enc,'UTF-8//IGNORE',$s); if($t!==false && $t!=='') return $t;
      $t=@mb_convert_encoding($s,'UTF-8',$enc); if($t!==false && $t!=='') return $t;
    }
    return @iconv('UTF-8','UTF-8//IGNORE',$s);
  }
}

if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', hash('sha256', __DIR__ . '/pharm_lab_queue_ui.php' . php_uname() . date('Y-m-d')));
}

/* Map lab_name → risk color class (UI preview เท่านั้น — payload จริงใช้ pharmRisk() ใน flex_pharm) */
function pharm_ui_chip($labName, $result){
  $ln = mb_strtolower((string)$labName, 'UTF-8');
  $x  = (is_numeric($result)) ? (float)$result : null;
  if (strpos($ln,'inr')!==false){
    if ($x!==null && $x>=5.0)  return ['วิกฤต','status-fail'];
    if ($x!==null && $x>=3.5)  return ['สูง','status-pending'];
  }
  if (strpos($ln,'depakin')!==false && $x!==null && $x>150)   return ['สูง','status-fail'];
  if (strpos($ln,'lithium')!==false && $x!==null && $x>1.2)   return ['สูง','status-fail'];
  if ((strpos($ln,'phenytoin')!==false||strpos($ln,'dilantin')!==false) && $x!==null && $x>20) return ['สูง','status-fail'];
  return ['เฝ้าระวัง','status-pending'];
}

$PAGE_TITLE = 'คิวแจ้งเตือน Lab ห้องยา';
$PAGE_ICON  = 'fa-file-prescription';
$PAGE_KEY   = 'pharm';
$EXTRA_HEAD = '
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
<style>
  .filter-card { padding:1rem 1.15rem; margin-bottom:1rem }
  .filter-card label { font-size:.82rem; color:#64748b; margin-bottom:.25rem }
  .table thead th { white-space:nowrap; font-size:.82rem; color:#475569; background:#f8fafc; border-bottom:1px solid #e2e8f0 }
  .table td { font-size:.88rem; vertical-align: middle }
  .table td.small-text { font-size:.82rem }
  .dt-buttons .btn { border-radius:.5rem }
  .action-bar { position: sticky; bottom: 1rem; z-index:50;
                background:#fff; border:1px solid #e2e8f0; border-radius:14px;
                padding:.75rem 1rem; box-shadow: 0 10px 25px rgba(15,23,42,.12);
                display:flex; align-items:center; gap:.5rem; flex-wrap:wrap }
  .action-bar .selected-count { font-weight:600; color:#0f172a; margin-right:auto }
  .form-check-input:focus { box-shadow: 0 0 0 .2rem rgba(37,99,235,.25) }
  .page-header h1 .text-primary { color:#1E3A8A !important }
  .lab-badge { display:inline-block; padding:.1rem .5rem; border-radius:.5rem;
               font-size:.72rem; font-weight:600; background:#EEF2FF; color:#3730A3;
               border:1px solid #C7D2FE }
  .result-high { color:#991B1B; font-weight:700 }
  .result-mid  { color:#92400E; font-weight:600 }
</style>
';

require_once __DIR__ . '/partials/header.php';
?>

<div class="page-header">
  <h1><span class="msi text-primary me-2">prescriptions</span><?= htmlspecialchars($PAGE_TITLE) ?></h1>
  <div class="d-flex gap-2">
    <a href="pharm_flex_preview.php" class="btn btn-outline-primary" target="_blank" rel="noopener">
      <span class="msi me-1">smartphone</span> ดูตัวอย่าง Flex
    </a>
    <a href="pharm_lab.php?mode=both" class="btn btn-outline-success" target="_blank" rel="noopener"
       title="รันดึงข้อมูลจาก HosXP 7 วันล่าสุด + ส่งคิวที่ค้าง">
      <span class="msi me-1">bolt</span> Ingest + Send
    </a>
    <a href="pharm_lab.php?mode=ingest&amp;start=2025-06-01&amp;end=<?= date('Y-m-d') ?>"
       class="btn btn-outline-warning" target="_blank" rel="noopener"
       title="ดึงข้อมูลย้อนหลังตั้งแต่ 2568-06-01 ถึงวันนี้ (ไม่ส่งซ้ำที่มีอยู่แล้ว — ON DUPLICATE KEY)">
      <span class="msi me-1">history</span> Backfill ประวัติ
    </a>
  </div>
</div>

<?php if (!empty($queryError)): ?>
  <div class="alert alert-danger">
    <strong>ดึงข้อมูลไม่สำเร็จ:</strong> <?= htmlspecialchars($queryError) ?>
    <br><small>ตรวจสอบว่าตาราง <code>pharm_lab_queue</code> ถูกสร้างแล้วหรือไม่
    — ดู schema ได้ในไฟล์ <code>pharm_lab_queue.sql</code></small>
  </div>
<?php endif; ?>

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
      <div class="kpi-icon bg-blue"><span class="msi">how_to_reg</span></div>
      <div>
        <p class="kpi-label">เภสัชรายงานแล้ว</p>
        <p class="kpi-value text-primary"><?= number_format($kpi['reported']) ?></p>
      </div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card filter-card">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-sm-6 col-md-3">
      <label for="f-start">ตั้งแต่วันที่</label>
      <input type="date" id="f-start" class="form-control" name="start" value="<?=htmlspecialchars($start)?>">
    </div>
    <div class="col-sm-6 col-md-3">
      <label for="f-end">ถึงวันที่</label>
      <input type="date" id="f-end" class="form-control" name="end" value="<?=htmlspecialchars($end)?>">
    </div>
    <div class="col-sm-6 col-md-2">
      <label for="f-status">สถานะ</label>
      <select id="f-status" class="form-select" name="status">
        <option value="all" <?=$status==='all'?'selected':''?>>ทั้งหมด</option>
        <option value="0"   <?=$status==='0'?'selected':''?>>ค้างส่ง</option>
        <option value="1"   <?=$status==='1'?'selected':''?>>ส่งแล้ว</option>
      </select>
    </div>
    <div class="col-sm-6 col-md-2">
      <label for="f-lab">ประเภท Lab</label>
      <select id="f-lab" class="form-select" name="lab">
        <option value="all" <?=$lab==='all'?'selected':''?>>ทั้งหมด</option>
        <option value="INR" <?=$lab==='INR'?'selected':''?>>INR</option>
        <option value="Depakin" <?=$lab==='Depakin'?'selected':''?>>Depakin level</option>
        <option value="Lithium" <?=$lab==='Lithium'?'selected':''?>>Lithium level</option>
        <option value="Phenytoin" <?=$lab==='Phenytoin'?'selected':''?>>Phenytoin level</option>
      </select>
    </div>
    <div class="col-sm-12 col-md-2 d-flex gap-2">
      <button class="btn btn-primary flex-grow-1">
        <span class="msi me-1">search</span> ค้นหา
      </button>
      <a class="btn btn-outline-secondary" href="pharm_lab_queue_ui.php" title="รีเซ็ต">
        <span class="msi">undo</span>
      </a>
    </div>
  </form>
</div>

<!-- Table + Actions -->
<form method="post" action="pharm_lab_queue_action.php" id="actionForm">
  <input type="hidden" name="token" value="<?=htmlspecialchars(UI_ACTION_TOKEN)?>">

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
            <th>Lab</th>
            <th>ผลตรวจ</th>
            <th>ระดับ</th>
            <th>วันที่ออกผล</th>
            <th>เวลา</th>
            <th>แพทย์</th>
            <th>ประเภท</th>
            <th>ผู้รายงาน</th>
            <th>Attempt</th>
            <th>Last Attempt</th>
            <th>Out Ref</th>
            <th>Error</th>
            <th>Created</th>
            <th>Sent</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r):
            $isDone = (int)$r['status'] === 1;
            $hasErr = !empty($r['last_error']);
            if ($hasErr && !$isDone) {
                $badge = '<span class="status-badge status-fail"><span class="msi">close</span> ล้มเหลว</span>';
            } elseif ($isDone) {
                $badge = '<span class="status-badge status-ok"><span class="msi">check</span> ส่งแล้ว</span>';
            } else {
                $badge = '<span class="status-badge status-pending"><span class="msi">schedule</span> ค้างส่ง</span>';
            }
            [$chipTxt, $chipCls] = pharm_ui_chip($r['lab_name'] ?? '', $r['result'] ?? null);
            $resultCls = ($chipCls === 'status-fail') ? 'result-high'
                       : (($chipCls === 'status-pending') ? 'result-mid' : '');
            $reporter = trim((string)($r['reported_by_name'] ?? ''));
        ?>
          <tr>
            <td><input type="checkbox" class="form-check-input chk" name="ids[]" value="<?=$r['id']?>"></td>
            <td>
              <a href="pharm_flex_preview.php?id=<?=$r['id']?>" target="_blank" rel="noopener"
                 class="text-decoration-none" title="ดูตัวอย่าง Flex ของแถวนี้">
                <?=$r['id']?> <span class="msi text-muted ms-1" style="font-size:.78rem">visibility</span>
              </a>
            </td>
            <td><?= $badge ?></td>
            <td><?=htmlspecialchars($r['hn'])?></td>
            <td><?=htmlspecialchars(to_utf8($r['fullname']))?></td>
            <td class="text-center"><?=$r['age']?></td>
            <td><span class="lab-badge"><?=htmlspecialchars($r['lab_name'])?></span></td>
            <td class="text-center <?=$resultCls?>"><?=htmlspecialchars($r['result'])?></td>
            <td><span class="status-badge <?=$chipCls?>"><?=$chipTxt?></span></td>
            <td><?=htmlspecialchars($r['lab_date'])?></td>
            <td class="small-text"><?= htmlspecialchars(substr((string)$r['lab_time'],0,5)) ?></td>
            <td><?=htmlspecialchars(to_utf8($r['doctor']))?></td>
            <td class="text-center"><?=htmlspecialchars($r['patient_type'])?></td>
            <td class="small-text">
              <?php if ($reporter !== ''): ?>
                <span class="msi text-primary me-1">personal_injury</span><?=htmlspecialchars(to_utf8($reporter))?>
              <?php else: ?>
                <span class="text-muted">ยังไม่รายงาน</span>
              <?php endif; ?>
            </td>
            <td class="text-center"><?=$r['attempt']?></td>
            <td class="small-text"><?=$r['last_attempt_at']?></td>
            <td class="small-text"><?=htmlspecialchars($r['out_ref'])?></td>
            <td class="small-text text-danger"><?=htmlspecialchars($r['last_error'])?></td>
            <td class="small-text"><?=$r['created_at']?></td>
            <td class="small-text"><?=$r['sent_at']?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Sticky action bar -->
  <div class="action-bar">
    <span class="selected-count" id="selectedCount">เลือก 0 รายการ</span>
    <button type="button" class="btn btn-primary btn-sm" data-action="send_now" data-label="ส่งซ้ำทันที" data-confirm-icon="question">
      <span class="msi me-1">send</span> ส่งซ้ำทันที
    </button>
    <button type="button" class="btn btn-warning btn-sm" data-action="requeue" data-label="Requeue" data-confirm-icon="warning">
      <span class="msi me-1">refresh</span> Requeue
    </button>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="clear_error" data-label="ล้าง error" data-confirm-icon="warning">
      <span class="msi me-1">backspace</span> ล้าง error
    </button>
  </div>
</form>

<?php
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
  const table = $("#tbl").DataTable({
    responsive: true,
    autoWidth: false,
    pageLength: 25,
    order: [[1,"desc"]],
    dom: "<\"row mb-2\"<\"col-sm-4\"l><\"col-sm-4 text-center\"B><\"col-sm-4\"f>>tip",
    buttons: [
      { extend: "colvis", text: "<span class=\"msi\">view_column<\/span> คอลัมน์", className: "btn btn-outline-secondary btn-sm", columns: ":not(:first-child)" }
    ],
    language: {
      search: "ค้นหา:", lengthMenu: "แสดง _MENU_ รายการ",
      info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
      infoEmpty: "ไม่มีรายการ",
      zeroRecords: "ไม่พบรายการที่ตรงกับคำค้น",
      paginate: { first:"หน้าแรก", last:"หน้าสุดท้าย", next:"ถัดไป", previous:"ก่อนหน้า" }
    },
    columnDefs: [
      { targets: [0,1,2,5,7,8,12,14], className: "text-nowrap text-center" }
    ]
  });

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

  $("[data-action]").on("click", function(){
    const action = $(this).data("action");
    const label  = $(this).data("label");
    const icon   = $(this).data("confirm-icon") || "question";
    const n = $("#tbl tbody .chk:checked").length;
    if (n === 0){
      Swal.fire({icon:"info", title:"ยังไม่ได้เลือกรายการ", text:"กรุณาติ๊กเลือกรายการในตารางก่อน"});
      return;
    }
    Swal.fire({
      icon: icon,
      title: "ยืนยัน" + label + "?",
      text: "จะดำเนินการกับรายการที่เลือก " + n + " รายการ",
      showCancelButton: true,
      confirmButtonText: label,
      cancelButtonText: "ยกเลิก",
      reverseButtons: true,
      focusCancel: true,
      confirmButtonColor: "#2563EB"
    }).then(r=>{
      if (r.isConfirmed){
        const $form = $("#actionForm");
        $form.append("<input type=\"hidden\" name=\"action\" value=\""+action+"\">");
        $form[0].submit();
      }
    });
  });
});
</script>
';
require_once __DIR__ . '/partials/footer.php';
?>
