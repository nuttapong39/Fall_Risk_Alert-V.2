<?php
require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

// ---------------- Filters ----------------
$start  = isset($_GET['start']) && $_GET['start'] ? $_GET['start'] : date('Y-m-d', strtotime('-7 days'));
$end    = isset($_GET['end'])   && $_GET['end']   ? $_GET['end']   : date('Y-m-d');
$status = isset($_GET['status']) ? $_GET['status'] : 'all'; // all | 0 | 1

$w = ["created_at BETWEEN :s AND :e"];
$p = [':s'=>$start.' 00:00:00', ':e'=>$end.' 23:59:59'];
if ($status==='0') { $w[] = "status=0"; }
if ($status==='1') { $w[] = "status=1"; }

/* ---- เกณฑ์เดียวกับ fracture.php (เวอร์ชันล่าสุด) ---- */
$w[] = "age >= 60"; // อายุ ≥ 60

// กรองรหัสโรค: W00–W19 และ S-codes ตามที่กำหนด  (ใช้ prepared parameters)
$dxParts = ["(UPPER(pdx_code) BETWEEN 'W00' AND 'W19')"];
$prefixes = ['S720','S721','S722','S525','S526','S422','S220','S221','S320','S327'];
foreach ($prefixes as $i => $prefix) {
    $key = ":px{$i}";
    $dxParts[] = "UPPER(pdx_code) LIKE {$key}";
    $p[$key] = $prefix . '%';
}
$w[] = '(' . implode(' OR ', $dxParts) . ')';

// ---------------- Query ----------------
$sql = "SELECT id, visit_vn, hn, fullname, cid, hometel, age, sex, address,
               pdx_code, pdx_name, vstdate, mainstation,
               status, attempt, last_attempt_at, out_ref, last_error,
               created_at, sent_at, line_message_id
        FROM fracture_queue
        WHERE ".implode(' AND ', $w)."
        ORDER BY vstdate DESC, id DESC
        LIMIT 2000";
$stmt = $dbcon->prepare($sql);
$stmt->execute($p);
$rows = $stmt->fetchAll();

// ---------------- KPI Summary ----------------
$kpi = ['total'=>0, 'pending'=>0, 'sent'=>0, 'failed'=>0, 'today'=>0];
$today = date('Y-m-d');
foreach ($rows as $r) {
    $kpi['total']++;
    if ((int)$r['status'] === 1)    $kpi['sent']++;
    else                             $kpi['pending']++;
    if (!empty($r['last_error']))   $kpi['failed']++;
    if (substr($r['created_at'],0,10) === $today) $kpi['today']++;
}

/* UTF-8 helper */
function to_utf8($s){
  if(!is_string($s)) return $s;
  if(mb_check_encoding($s,'UTF-8')) return $s;
  foreach(['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc){
    $t=@iconv($enc,'UTF-8//IGNORE',$s); if($t!==false && $t!=='') return $t;
    $t=@mb_convert_encoding($s,'UTF-8',$enc); if($t!==false && $t!=='') return $t;
  }
  return @iconv('UTF-8','UTF-8//IGNORE',$s);
}

if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', hash('sha256', __DIR__ . '/fracture_queue_ui.php' . php_uname() . date('Y-m-d')));
}

/* ---------- Flash message ---------- */
$flash = '';
if (isset($_GET['msg'])) {
  $ok  = (int)($_GET['ok']       ?? 0);
  $fa  = (int)($_GET['fail']     ?? 0);
  $aff = (int)($_GET['affected'] ?? 0);
  $imp = (int)($_GET['imported'] ?? 0);
  $nw  = (int)($_GET['new']      ?? 0);
  $flash = match($_GET['msg']) {
    'sendnow'   => "success:ส่งสำเร็จ {$ok} รายการ".($fa > 0 ? " / ล้มเหลว {$fa} รายการ" : ''),
    'requeued'  => "info:Requeue สำเร็จ {$aff} รายการ",
    'cleared'   => "info:ล้าง error สำเร็จ {$aff} รายการ",
    'imported'  => "success:Sync จาก HOSxP สำเร็จ {$imp} รายการ (ใหม่ {$nw} รายการ)",
    'no_ids'    => "warning:ยังไม่ได้เลือกรายการ",
    'bad_action'=> "danger:คำสั่งไม่ถูกต้อง",
    'err'       => "danger:เกิดข้อผิดพลาด: ".htmlspecialchars($_GET['detail']??''),
    default     => '',
  };
}

$PAGE_TITLE = 'คิวแจ้งเตือนพลัดตก / หกล้ม';
$PAGE_ICON  = 'fa-person-falling';
$PAGE_KEY   = 'fracture';
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
  .form-check-input:focus { box-shadow: 0 0 0 .2rem rgba(16,185,129,.25) }

  /* ── Sync modal ── */
  #frcSyncResult { display:none; border-radius:8px; padding:10px 14px; font-size:.85rem; font-weight:500; margin-top:10px }
  #frcSyncResult.ok  { background:#dcfce7; color:#166534 }
  #frcSyncResult.err { background:#fee2e2; color:#991b1b }
</style>
';

require_once __DIR__ . '/partials/header.php';
?>

<?php if ($flash):
  [$ft, $fm] = explode(':', $flash, 2) + ['info',''];
  $ftMap  = ['success'=>'alert-success','info'=>'alert-info','warning'=>'alert-warning','danger'=>'alert-danger'];
  $ftIcon = ['success'=>'check_circle','info'=>'info','warning'=>'warning','danger'=>'error'];
?>
<div class="alert <?= $ftMap[$ft]??'alert-info' ?> d-flex align-items-center gap-2 mb-3"
     style="border-radius:10px; font-size:.9rem">
  <span class="msi"><?= $ftIcon[$ft]??'info' ?></span> <?= $fm ?>
</div>
<?php endif; ?>

<div class="page-header">
  <h1><span class="msi text-success me-2">falling</span><?= htmlspecialchars($PAGE_TITLE) ?></h1>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#frcSyncModal">
      <span class="msi me-1">sync</span> Sync จาก HOSxP
    </button>
    <a href="fracture_flex_preview.php" class="btn btn-outline-primary" target="_blank" rel="noopener">
      <span class="msi me-1">smartphone</span> ดูตัวอย่าง Flex
    </a>
    <a href="fracture_dashboard.php" class="btn btn-outline-success">
      <span class="msi me-1">show_chart</span> Dashboard
    </a>
    <a href="fracture_report_daily.php" class="btn btn-outline-secondary">
      <span class="msi me-1">description</span> รายงานรายวัน
    </a>
  </div>
</div>

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
    <div class="col-sm-6 col-md-3">
      <label for="f-start">ตั้งแต่วันที่</label>
      <input type="date" id="f-start" class="form-control" name="start" value="<?=htmlspecialchars($start)?>">
    </div>
    <div class="col-sm-6 col-md-3">
      <label for="f-end">ถึงวันที่</label>
      <input type="date" id="f-end" class="form-control" name="end" value="<?=htmlspecialchars($end)?>">
    </div>
    <div class="col-sm-6 col-md-3">
      <label for="f-status">สถานะ</label>
      <select id="f-status" class="form-select" name="status">
        <option value="all" <?=$status==='all'?'selected':''?>>ทั้งหมด</option>
        <option value="0"   <?=$status==='0'?'selected':''?>>ค้างส่ง</option>
        <option value="1"   <?=$status==='1'?'selected':''?>>ส่งแล้ว</option>
      </select>
    </div>
    <div class="col-sm-6 col-md-3 d-flex gap-2">
      <button class="btn btn-success flex-grow-1">
        <span class="msi me-1">search</span> ค้นหา
      </button>
      <a class="btn btn-outline-secondary" href="fracture_queue_ui.php" title="รีเซ็ต">
        <span class="msi">undo</span>
      </a>
    </div>
  </form>
</div>

<!-- Table + Actions -->
<form method="post" action="fracture_queue_action.php" id="actionForm">
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
            <th>เพศ</th>
            <th>ที่อยู่</th>
            <th>ICD-10</th>
            <th>ชื่อโรค</th>
            <th>วันรับบริการ</th>
            <th>สถานบริการหลัก</th>
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
        ?>
          <tr>
            <td><input type="checkbox" class="form-check-input chk" name="ids[]" value="<?=$r['id']?>"></td>
            <td>
              <a href="fracture_flex_preview.php?id=<?=$r['id']?>" target="_blank" rel="noopener"
                 class="text-decoration-none" title="ดูตัวอย่าง Flex ของแถวนี้">
                <?=$r['id']?> <span class="msi text-muted ms-1" style="font-size:.78rem">visibility</span>
              </a>
            </td>
            <td><?= $badge ?></td>
            <td><?=htmlspecialchars($r['hn'])?></td>
            <td><?=htmlspecialchars(to_utf8($r['fullname']))?></td>
            <td><?=$r['age']?></td>
            <td><?=htmlspecialchars(to_utf8($r['sex']))?></td>
            <td class="small-text"><?=htmlspecialchars(to_utf8($r['address']))?></td>
            <td><code><?=$r['pdx_code']?></code></td>
            <td class="small-text"><?=htmlspecialchars(to_utf8($r['pdx_name']))?></td>
            <td><?=$r['vstdate']?></td>
            <td><?=htmlspecialchars(to_utf8($r['mainstation']))?></td>
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

<!-- ═══ Sync from HOSxP Modal ═══ -->
<div class="modal fade" id="frcSyncModal" tabindex="-1" aria-labelledby="frcSyncModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:14px; overflow:hidden">
      <div class="modal-header" style="background:linear-gradient(135deg,#059669,#065f46); color:#fff; border:none">
        <h5 class="modal-title" id="frcSyncModalLabel">
          <span class="msi me-2">sync</span>Sync ข้อมูลจาก HOSxP
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">
            <span class="msi me-1" style="font-size:1rem">calendar_today</span>ช่วงวันที่ดึงข้อมูล
          </label>
          <div class="row g-2">
            <div class="col">
              <input type="date" id="frcSyncStart" class="form-control"
                     value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
            </div>
            <div class="col-auto d-flex align-items-center text-muted">ถึง</div>
            <div class="col">
              <input type="date" id="frcSyncEnd" class="form-control"
                     value="<?= date('Y-m-d') ?>">
            </div>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">
            <span class="msi me-1" style="font-size:1rem">elderly</span>อายุขั้นต่ำ (ปี)
          </label>
          <input type="number" id="frcSyncMinAge" class="form-control" min="0" max="120" value="60">
          <div class="form-text">ค่าเริ่มต้น 60 ปี — กรอง ICD W00–W19 และ S-codes กระดูกหัก</div>
        </div>
        <div class="p-2 rounded" style="background:#d1fae5; border:1px solid #6ee7b7; font-size:.8rem; color:#065f46">
          <span class="msi me-1">info</span>
          ระบบจะ Query จาก <strong>vn_stat</strong> ใน HOSxP แล้ว Upsert เข้า <code>fracture_queue</code>
          รายการที่มีอยู่แล้วจะอัปเดตชื่อและสถานบริการ ไม่รีเซ็ตสถานะการส่ง
        </div>
        <div id="frcSyncResult"></div>
      </div>
      <div class="modal-footer" style="border:none; padding-top:0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-success px-4" id="frcSyncBtn" onclick="doFrcSync()">
          <span class="msi me-1" id="frcSyncIcon">sync</span>
          <span id="frcSyncBtnText">Sync ข้อมูล</span>
        </button>
      </div>
    </div>
  </div>
</div>

  <!-- Sticky action bar -->
  <div class="action-bar">
    <span class="selected-count" id="selectedCount">เลือก 0 รายการ</span>
    <button type="button" class="btn btn-success btn-sm" data-action="send_now" data-label="ส่งซ้ำทันที" data-confirm-icon="question">
      <span class="msi me-1">send</span> ส่งซ้ำทันที
    </button>
    <button type="button" class="btn btn-warning btn-sm" data-action="requeue" data-label="Requeue" data-confirm-icon="warning">
      <span class="msi me-1">refresh</span> Requeue
    </button>
    <button type="button" class="btn btn-outline-danger btn-sm" data-action="clear_error" data-label="ล้าง error" data-confirm-icon="warning">
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
    order: [[10,"desc"]],
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
      { targets: [0,1,2,12], className: "text-nowrap text-center" }
    ]
  });

  // Select-all (scope to current page only, so users see what they selected)
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

  // SweetAlert confirm for all bulk actions
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
      confirmButtonColor: "#059669"
    }).then(r=>{
      if (r.isConfirmed){
        const $form = $("#actionForm");
        $form.append("<input type=\"hidden\" name=\"action\" value=\""+action+"\">");
        $form[0].submit();
      }
    });
  });
});

// ── Sync from HOSxP (AJAX) ────────────────────────────────────────────────
function doFrcSync() {
  var btn     = document.getElementById("frcSyncBtn");
  var icon    = document.getElementById("frcSyncIcon");
  var btnText = document.getElementById("frcSyncBtnText");
  var result  = document.getElementById("frcSyncResult");
  var start   = document.getElementById("frcSyncStart").value;
  var end     = document.getElementById("frcSyncEnd").value;
  var minAge  = document.getElementById("frcSyncMinAge").value;

  btn.disabled = true;
  icon.textContent = "sync";
  icon.classList.add("msi-spin");
  btnText.textContent = "กำลัง Sync...";
  result.style.display = "none";

  var fd = new FormData();
  fd.append("action",  "import_hosxp");
  fd.append("start",   start);
  fd.append("end",     end);
  fd.append("min_age", minAge);

  fetch("fracture_queue_action.php", { method:"POST", body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
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
        setTimeout(function(){
          window.location.href = "fracture_queue_ui.php?msg=imported&imported="
            + (data.imported||0) + "&new=" + (data.new||0);
        }, 1500);
      }
    })
    .catch(function(err){
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
// ไม่ echo ที่นี่ — ส่งเป็นตัวแปรให้ partials/footer.php echo หลัง AdminLTE JS
// (เพื่อให้ DataTables + inline script ใช้ jQuery ตัวเดียวกับ AdminLTE)
require_once __DIR__ . '/partials/footer.php';
?>
