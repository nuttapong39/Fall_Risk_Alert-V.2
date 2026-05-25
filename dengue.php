<?php
/**
 * dengue.php
 * แจ้งเตือนผู้ป่วยไข้เลือดออก (Dengue Fever) — HR-CENTER 4.0
 * — Query ตรงจาก HOSxP (vn_stat + lab_order + patient)
 * — ส่ง LINE Flex ผ่าน MOPH Alert ด้วย dengue_action.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

// ── Date filter ───────────────────────────────────────────────────────────────
$start = (isset($_GET['start']) && $_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('-30 days'));
$end   = (isset($_GET['end'])   && $_GET['end'])   ? $_GET['end']   : date('Y-m-d');

// ── Query HOSxP ───────────────────────────────────────────────────────────────
$rows       = [];
$queryError = null;
try {
  $stmt = $dbcon->prepare(
    "SELECT
       ov.vn,
       ov.hn,
       CONCAT(pt.pname, pt.fname, ' ', pt.lname)            AS fullname,
       TIMESTAMPDIFF(YEAR, pt.birthday, ov.vstdate)          AS age,
       CASE WHEN pt.sex='1' THEN 'ชาย'
            WHEN pt.sex='2' THEN 'หญิง' ELSE '' END          AS sex,
       ov.cid,
       pt.hometel,
       ov.vstdate,
       d.name                                                 AS doctor,
       i.name                                                 AS disease,
       ov.pdx                                                 AS icd10,
       l.lab_order_result                                     AS result
     FROM   vn_stat ov
     LEFT  JOIN patient pt ON pt.hn  = ov.hn
     LEFT  JOIN icd101  i  ON i.code = ov.pdx
     LEFT  JOIN doctor  d  ON d.code = ov.dx_doctor
     INNER JOIN lab_head  h ON h.vn              = ov.vn
     INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number
     WHERE  ov.vstdate          BETWEEN ? AND ?
       AND  l.lab_items_code    = '2891'
       AND  (l.lab_order_result = 'Positive' OR l.lab_order_result = 'Weakly Positive IgM')
       AND  (   ov.pdx  >= 'A90' AND ov.pdx  <= 'A99'
             OR ov.dx0  >= 'A90' AND ov.dx0  <= 'A99'
             OR ov.dx1  >= 'A90' AND ov.dx1  <= 'A99'
             OR ov.dx2  >= 'A90' AND ov.dx2  <= 'A99'
             OR ov.dx3  >= 'A90' AND ov.dx3  <= 'A99')
     GROUP BY ov.vn
     ORDER BY ov.vstdate DESC
     LIMIT 500"
  );
  $stmt->execute([$start, $end]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $queryError = $e->getMessage();
}

// ── KPI ───────────────────────────────────────────────────────────────────────
$today  = date('Y-m-d');
$kpi    = ['total'=>0, 'today'=>0, 'unique_hn'=>0];
$hnSeen = [];
foreach ($rows as $r) {
  $kpi['total']++;
  if (substr((string)$r['vstdate'], 0, 10) === $today) $kpi['today']++;
  $hnSeen[$r['hn']] = true;
}
$kpi['unique_hn'] = count($hnSeen);

// ── UTF-8 helper ──────────────────────────────────────────────────────────────
function to_utf8_dengue($s) {
  if (!is_string($s)) return $s;
  if (mb_check_encoding($s, 'UTF-8')) return $s;
  foreach (['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc) {
    $t = @iconv($enc, 'UTF-8//IGNORE', $s); if ($t !== false && $t !== '') return $t;
    $t = @mb_convert_encoding($s, 'UTF-8', $enc); if ($t !== false && $t !== '') return $t;
  }
  return @iconv('UTF-8', 'UTF-8//IGNORE', $s);
}

// ── Page variables ────────────────────────────────────────────────────────────
$PAGE_TITLE = 'ไข้เลือดออก (Dengue Fever)';
$PAGE_KEY   = 'dengue';
$EXTRA_HEAD = '
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
<style>
  .filter-card  { padding:1rem 1.15rem; margin-bottom:1rem }
  .filter-card label { font-size:.82rem; color:#64748b; margin-bottom:.25rem }

  .table thead th { white-space:nowrap; font-size:.82rem; color:#475569;
                    background:#f8fafc; border-bottom:1px solid #e2e8f0 }
  .table td { font-size:.88rem; vertical-align:middle }

  /* Send button — amber dengue theme */
  .btn-send-disease {
    background: linear-gradient(135deg,#f59e0b,#d97706);
    border:none; color:#fff; border-radius:8px;
    font-size:.82rem; padding:.3rem .75rem;
    display:inline-flex; align-items:center; gap:.3rem;
    transition:opacity .2s, transform .1s; white-space:nowrap;
  }
  .btn-send-disease:hover  { opacity:.88; color:#fff }
  .btn-send-disease:active { transform:scale(.97) }
  .btn-send-disease:disabled {
    background:#d1fae5; color:#065f46;
    border:1px solid #a7f3d0; cursor:not-allowed; opacity:1
  }

  .setup-alert {
    background:linear-gradient(135deg,#d97706,#92400e);
    color:#fff; border-radius:14px; padding:20px 24px;
    display:flex; align-items:flex-start; gap:16px;
  }
  .setup-alert-icon { font-size:2rem; flex-shrink:0 }
  .setup-alert h5 { font-size:1rem; font-weight:700; margin:0 0 4px }
  .setup-alert p  { font-size:.83rem; margin:0; opacity:.9 }
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
    <span class="msi me-2" style="color:#d97706">pest_control</span>
    <?= htmlspecialchars($PAGE_TITLE) ?>
  </h1>
  <div class="d-flex gap-2 flex-wrap">
    <span class="badge rounded-pill"
          style="background:#fffbeb;color:#92400e;border:1px solid #fde68a;font-size:.78rem;padding:.35rem .75rem">
      <span class="msi me-1" style="font-size:.9em">science</span>
      Lab code: 2891
    </span>
    <a href="dengue.php" class="btn btn-outline-secondary btn-sm">
      <span class="msi me-1">refresh</span> รีเซ็ต
    </a>
  </div>
</div>

<?php if ($queryError): ?>
<div class="setup-alert mb-4">
  <div class="setup-alert-icon"><span class="msi">error_outline</span></div>
  <div>
    <h5>ไม่สามารถดึงข้อมูลจาก HOSxP</h5>
    <p>ตรวจสอบการเชื่อมต่อฐานข้อมูลที่ <a href="db_config_admin.php" class="text-white fw-bold">ตั้งค่าฐานข้อมูล</a></p>
    <p class="mt-1"><code style="background:rgba(0,0,0,.25);padding:2px 6px;border-radius:4px"><?= htmlspecialchars($queryError) ?></code></p>
  </div>
</div>
<?php else: ?>

<!-- KPI -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-4">
    <div class="kpi-card">
      <div class="kpi-icon bg-amber"><span class="msi">pest_control</span></div>
      <div>
        <p class="kpi-label">ทั้งหมด (<?= date('d/m',strtotime($start))?>–<?= date('d/m',strtotime($end))?>)</p>
        <p class="kpi-value" style="color:#d97706"><?= number_format($kpi['total']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-4">
    <div class="kpi-card">
      <div class="kpi-icon bg-blue"><span class="msi">today</span></div>
      <div>
        <p class="kpi-label">วันนี้</p>
        <p class="kpi-value text-primary"><?= number_format($kpi['today']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-4">
    <div class="kpi-card">
      <div class="kpi-icon bg-indigo"><span class="msi">people</span></div>
      <div>
        <p class="kpi-label">จำนวน HN ไม่ซ้ำ</p>
        <p class="kpi-value" style="color:#4f46e5"><?= number_format($kpi['unique_hn']) ?></p>
      </div>
    </div>
  </div>
</div>

<!-- Filter -->
<div class="card filter-card">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-sm-6 col-md-3">
      <label for="f-start">ตั้งแต่วันที่</label>
      <input type="date" id="f-start" class="form-control form-control-sm" name="start"
             value="<?= htmlspecialchars($start) ?>">
    </div>
    <div class="col-sm-6 col-md-3">
      <label for="f-end">ถึงวันที่</label>
      <input type="date" id="f-end" class="form-control form-control-sm" name="end"
             value="<?= htmlspecialchars($end) ?>">
    </div>
    <div class="col-sm-6 col-md-3 d-flex gap-2 align-items-end">
      <button class="btn btn-sm flex-grow-1"
              style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:8px">
        <span class="msi me-1">search</span> ค้นหา
      </button>
      <a class="btn btn-sm btn-outline-secondary" href="dengue.php" title="รีเซ็ตตัวกรอง">
        <span class="msi">undo</span>
      </a>
    </div>
    <div class="col-sm-6 col-md-3 d-flex align-items-end">
      <small class="text-muted">
        <span class="msi me-1" style="font-size:.9em">info</span>
        แสดง <?= number_format(count($rows)) ?> รายการ (max 500)
      </small>
    </div>
  </form>
</div>

<!-- Table -->
<div class="card p-3">
  <div class="table-responsive">
    <table id="tblDengue" class="table table-hover align-middle nowrap" style="width:100%">
      <thead>
        <tr>
          <th>#</th>
          <th>VN</th>
          <th>HN</th>
          <th>ชื่อ-สกุล</th>
          <th>อายุ / เพศ</th>
          <th>เลขบัตรประชาชน</th>
          <th>เบอร์โทร</th>
          <th>วันที่รับบริการ</th>
          <th>แพทย์ผู้ตรวจ</th>
          <th>ชื่อโรค / ICD-10</th>
          <th>ผลตรวจ</th>
          <th>ส่งแจ้งเตือน</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $i => $r):
        $r        = array_map('to_utf8_dengue', $r);
        $vn       = htmlspecialchars($r['vn']       ?? '');
        $hn       = htmlspecialchars($r['hn']       ?? '');
        $fullname = htmlspecialchars($r['fullname'] ?? '');
        $age      = htmlspecialchars($r['age']      ?? '');
        $sex      = htmlspecialchars($r['sex']      ?? '');
        $cid      = htmlspecialchars($r['cid']      ?? '');
        $tel      = htmlspecialchars($r['hometel']  ?? '');
        $vstdate  = htmlspecialchars($r['vstdate']  ?? '');
        $doctor   = htmlspecialchars($r['doctor']   ?? '');
        $disease  = htmlspecialchars($r['disease']  ?? '');
        $icd10    = htmlspecialchars($r['icd10']    ?? '');
        $result   = htmlspecialchars($r['result']   ?? '');
        $isToday  = (substr((string)($r['vstdate']??''), 0, 10) === $today);
      ?>
        <tr>
          <td class="text-muted" style="font-size:.8rem"><?= $i+1 ?></td>
          <td><code style="font-size:.8rem;color:#374151"><?= $vn ?></code></td>
          <td><strong><?= $hn ?></strong></td>
          <td><?= $fullname ?></td>
          <td class="text-center">
            <?php if ($age || $sex): ?>
              <span><?= $age ? $age.' ปี' : '' ?></span>
              <?php if ($sex): ?>
                <span class="badge rounded-pill ms-1"
                      style="background:<?= $sex==='ชาย'?'#dbeafe':'#fce7f3' ?>;
                             color:<?= $sex==='ชาย'?'#1e40af':'#9d174d' ?>;font-size:.75rem">
                  <?= $sex ?>
                </span>
              <?php endif; ?>
            <?php else: echo '-'; endif; ?>
          </td>
          <td style="font-size:.82rem;font-family:monospace"><?= $cid ?: '-' ?></td>
          <td style="font-size:.85rem"><?= $tel ?: '-' ?></td>
          <td>
            <span style="font-size:.85rem;<?= $isToday ? 'color:#dc2626;font-weight:600' : '' ?>">
              <?= $vstdate ?>
            </span>
            <?php if ($isToday): ?>
              <span class="badge rounded-pill ms-1"
                    style="background:#fef2f2;color:#dc2626;font-size:.72rem">วันนี้</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.85rem"><?= $doctor ?: '-' ?></td>
          <td>
            <div style="font-size:.85rem"><?= $disease ?: '-' ?></div>
            <?php if ($icd10): ?>
              <span class="badge rounded-pill mt-1"
                    style="background:#fffbeb;color:#92400e;border:1px solid #fde68a;font-size:.75rem">
                <?= $icd10 ?>
              </span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge rounded-pill"
                  style="background:#fffbeb;color:#92400e;font-size:.82rem;padding:.3rem .7rem;font-weight:600">
              <?= $result ?: '-' ?>
            </span>
          </td>
          <td>
            <button class="btn-send-disease" id="btn-<?= $vn ?>"
                    data-vn="<?= $vn ?>" data-hn="<?= $hn ?>" data-name="<?= $fullname ?>">
              <span class="msi">send</span> ส่งแจ้งเตือน
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

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
  $("#tblDengue").DataTable({
    responsive: true,
    order: [[7, "desc"]],
    pageLength: 25,
    lengthMenu: [[10,25,50,100,-1],["10","25","50","100","ทั้งหมด"]],
    dom: \'<"row align-items-center mb-2"<"col-sm-6"B><"col-sm-6 text-end"f>>rt<"row mt-2"<"col-sm-5"i><"col-sm-7"p>>\',
    buttons: [
      {extend:"excel", text:\'<span class="msi me-1">table_view</span> Excel\',
       className:"btn btn-sm btn-outline-success",
       title:"dengue_alert_"+new Date().toLocaleDateString("th-TH")},
      {extend:"print",  text:\'<span class="msi me-1">print</span> พิมพ์\',
       className:"btn btn-sm btn-outline-secondary"},
    ],
    language:{
      search:"ค้นหา:", lengthMenu:"แสดง _MENU_ รายการ",
      info:"แสดง _START_–_END_ จาก _TOTAL_ รายการ",
      infoEmpty:"ไม่มีข้อมูล", paginate:{previous:"ก่อน",next:"ถัดไป"},
      zeroRecords:"ไม่พบข้อมูลที่ค้นหา"
    }
  });

  document.addEventListener("click", function(e){
    const btn = e.target.closest(".btn-send-disease");
    if (!btn || btn.disabled) return;

    const vn   = btn.dataset.vn;
    const hn   = btn.dataset.hn;
    const name = btn.dataset.name;

    Swal.fire({
      title: "ส่งแจ้งเตือนไข้เลือดออก?",
      html: `<div class="text-start" style="font-size:.9rem">
               <div><strong>VN:</strong> ${vn}</div>
               <div><strong>HN:</strong> ${hn}</div>
               <div><strong>ชื่อ:</strong> ${name}</div>
             </div>`,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: \'<span class="msi me-1">send</span> ส่งเลย\',
      cancelButtonText: "ยกเลิก",
      confirmButtonColor: "#d97706",
      reverseButtons: true,
      focusCancel: true,
    }).then(r => {
      if (!r.isConfirmed) return;

      btn.disabled = true;
      btn.innerHTML = \'<span class="msi msi-spin">progress_activity</span> กำลังส่ง…\';
      btn.style.background = "#cbd5e1";

      fetch("dengue_action.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: new URLSearchParams({ action: "send", vn })
      })
      .then(res => res.json())
      .then(json => {
        if (json.ok) {
          btn.innerHTML = \'<span class="msi">check_circle</span> ส่งแล้ว\';
          Swal.fire({
            toast:true, position:"top-end", icon:"success", timer:3000,
            showConfirmButton:false, timerProgressBar:true,
            title:"ส่งสำเร็จ", text:"Ref: "+(json.ref??"-")
          });
        } else {
          btn.disabled = false;
          btn.innerHTML = \'<span class="msi">send</span> ส่งแจ้งเตือน\';
          btn.style.background = "";
          Swal.fire({icon:"error",title:"ส่งไม่สำเร็จ",
            text:json.msg??"เกิดข้อผิดพลาด",confirmButtonColor:"#d97706"});
        }
      })
      .catch(err => {
        btn.disabled = false;
        btn.innerHTML = \'<span class="msi">send</span> ส่งแจ้งเตือน\';
        btn.style.background = "";
        Swal.fire({icon:"error",title:"Network error",text:err.message,
          confirmButtonColor:"#d97706"});
      });
    });
  });
});
</script>
';
require_once __DIR__ . '/partials/footer.php';
?>
