<?php
/**
 * sexual.php
 * คิวแจ้งเตือน ผู้ถูกทำร้ายร่างกาย / ข่มขืน (HR-CENTER 4.0)
 * — Query ตรงจาก HOSxP (lab_order + lab_head + patient)
 * — ส่ง LINE Flex ผ่าน MOPH Alert ด้วย sexual_action.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/flex_sexual.php';   // LAB_CODE_SEXUAL constant
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
       h.vn,
       pt.hn,
       CONCAT(pt.pname, pt.fname, ' ', pt.lname)                   AS fullname,
       pt.cid,
       pt.hometel,
       pt.addrpart                                                   AS address,
       TIMESTAMPDIFF(YEAR, pt.birthday, h.order_date)               AS age,
       CASE WHEN pt.sex='1' THEN 'ชาย'
            WHEN pt.sex='2' THEN 'หญิง' ELSE '' END                 AS sex,
       h.order_date,
       l.lab_items_name_ref,
       l.lab_order_result,
       l.lab_items_code
     FROM   lab_order l
     INNER JOIN lab_head h  ON l.lab_order_number = h.lab_order_number
     LEFT  JOIN patient  pt ON pt.hn = h.hn
     WHERE  l.lab_items_code = ?
     AND    h.order_date     BETWEEN ? AND ?
     ORDER  BY h.order_date DESC
     LIMIT  500"
  );
  $stmt->execute([LAB_CODE_SEXUAL, $start, $end]);
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
  if (substr((string)$r['order_date'], 0, 10) === $today) $kpi['today']++;
  $hnSeen[$r['hn']] = true;
}
$kpi['unique_hn'] = count($hnSeen);

// ── UTF-8 helper ──────────────────────────────────────────────────────────────
function to_utf8_sx($s) {
  if (!is_string($s)) return $s;
  if (mb_check_encoding($s, 'UTF-8')) return $s;
  foreach (['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc) {
    $t = @iconv($enc, 'UTF-8//IGNORE', $s); if ($t !== false && $t !== '') return $t;
    $t = @mb_convert_encoding($s, 'UTF-8', $enc); if ($t !== false && $t !== '') return $t;
  }
  return @iconv('UTF-8', 'UTF-8//IGNORE', $s);
}

// ── Page variables ────────────────────────────────────────────────────────────
$PAGE_TITLE = 'ผู้ถูกข่มขืน / ทำร้ายร่างกาย';
$PAGE_KEY   = 'sexual';
$EXTRA_HEAD = '
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
<style>
  .filter-card  { padding:1rem 1.15rem; margin-bottom:1rem }
  .filter-card label { font-size:.82rem; color:#64748b; margin-bottom:.25rem }

  /* Table */
  .table thead th { white-space:nowrap; font-size:.82rem; color:#475569;
                    background:#f8fafc; border-bottom:1px solid #e2e8f0 }
  .table td { font-size:.88rem; vertical-align:middle }

  /* Sent badge (JS-applied) */
  .badge-sent   { background:#dcfce7; color:#166534; border-radius:999px;
                  padding:.2rem .7rem; font-size:.76rem; font-weight:600 }
  .badge-unsent { background:#fef9c3; color:#713f12; border-radius:999px;
                  padding:.2rem .7rem; font-size:.76rem; font-weight:600 }

  /* Send button */
  .btn-send-alert {
    background:linear-gradient(135deg,#db2777,#be185d);
    border:none; color:#fff; border-radius:8px;
    font-size:.82rem; padding:.3rem .75rem;
    display:inline-flex; align-items:center; gap:.3rem;
    transition:opacity .2s, transform .1s;
  }
  .btn-send-alert:hover { opacity:.88; color:#fff }
  .btn-send-alert:active { transform:scale(.97) }
  .btn-send-alert:disabled {
    background:#d1fae5; color:#065f46;
    border:1px solid #a7f3d0; cursor:not-allowed; opacity:1
  }

  /* Query error */
  .setup-alert {
    background:linear-gradient(135deg,#be185d,#9f1239);
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
    <span class="msi me-2" style="color:#db2777">shield_person</span>
    <?= htmlspecialchars($PAGE_TITLE) ?>
  </h1>
  <div class="d-flex gap-2 flex-wrap">
    <span class="badge rounded-pill"
          style="background:#fdf2f8;color:#9d174d;border:1px solid #fbcfe8;font-size:.78rem;padding:.35rem .75rem">
      <span class="msi me-1" style="font-size:.9em">science</span>
      Lab code: <?= htmlspecialchars(LAB_CODE_SEXUAL) ?>
    </span>
    <a href="sexual.php" class="btn btn-outline-secondary btn-sm">
      <span class="msi me-1">refresh</span> รีเซ็ต
    </a>
  </div>
</div>

<?php if ($queryError): ?>
<!-- Query error -->
<div class="setup-alert mb-4">
  <div class="setup-alert-icon"><span class="msi">error_outline</span></div>
  <div>
    <h5>ไม่สามารถดึงข้อมูลจาก HOSxP</h5>
    <p>ตรวจสอบการเชื่อมต่อฐานข้อมูลที่ <a href="db_config_admin.php" class="text-white fw-bold">ตั้งค่าฐานข้อมูล</a></p>
    <p class="mt-1"><code style="background:rgba(0,0,0,.25);padding:2px 6px;border-radius:4px"><?= htmlspecialchars($queryError) ?></code></p>
  </div>
</div>
<?php else: ?>

<!-- KPI Summary -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-4">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:linear-gradient(135deg,#db2777,#9d174d)">
        <span class="msi">shield_person</span>
      </div>
      <div>
        <p class="kpi-label">ทั้งหมด (<?= date('d/m',strtotime($start))?>–<?= date('d/m',strtotime($end))?>)</p>
        <p class="kpi-value"><?= number_format($kpi['total']) ?></p>
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
              style="background:linear-gradient(135deg,#db2777,#9d174d);color:#fff;border:none;border-radius:8px">
        <span class="msi me-1">search</span> ค้นหา
      </button>
      <a class="btn btn-sm btn-outline-secondary" href="sexual.php" title="รีเซ็ตตัวกรอง">
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
    <table id="tblSexual" class="table table-hover align-middle nowrap" style="width:100%">
      <thead>
        <tr>
          <th>#</th>
          <th>VN</th>
          <th>HN</th>
          <th>ชื่อ-สกุล</th>
          <th>เลขบัตรประชาชน</th>
          <th>อายุ</th>
          <th>เพศ</th>
          <th>เบอร์โทร</th>
          <th>วันที่สั่ง LAB</th>
          <th>รายการ LAB</th>
          <th>ผลตรวจ</th>
          <th>การแจ้งเตือน</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $i => $r):
        $r = array_map('to_utf8_sx', $r);
        $vn       = htmlspecialchars($r['vn']               ?? '');
        $hn       = htmlspecialchars($r['hn']               ?? '');
        $fullname = htmlspecialchars($r['fullname']          ?? '');
        $cid      = htmlspecialchars($r['cid']              ?? '');
        $age      = htmlspecialchars($r['age']              ?? '');
        $sex      = htmlspecialchars($r['sex']              ?? '');
        $tel      = htmlspecialchars($r['hometel']          ?? '');
        $odate    = htmlspecialchars($r['order_date']       ?? '');
        $labname  = htmlspecialchars($r['lab_items_name_ref'] ?? '');
        $labres   = htmlspecialchars($r['lab_order_result'] ?? '');
        $isToday  = (substr((string)($r['order_date']??''), 0, 10) === $today);
      ?>
        <tr>
          <td class="text-muted" style="font-size:.8rem"><?= $i+1 ?></td>
          <td><code style="font-size:.8rem;color:#374151"><?= $vn ?></code></td>
          <td><strong><?= $hn ?></strong></td>
          <td><?= $fullname ?></td>
          <td style="font-size:.82rem;font-family:monospace"><?= $cid ?></td>
          <td class="text-center"><?= $age ? $age.' ปี' : '-' ?></td>
          <td class="text-center">
            <?php if ($sex): ?>
              <span class="badge rounded-pill"
                    style="background:<?= $sex==='ชาย'?'#dbeafe':'#fce7f3' ?>;
                           color:<?= $sex==='ชาย'?'#1e40af':'#9d174d' ?>;
                           font-size:.78rem">
                <?= $sex ?>
              </span>
            <?php else: echo '-'; endif; ?>
          </td>
          <td style="font-size:.85rem"><?= $tel ?: '-' ?></td>
          <td>
            <span style="font-size:.85rem;<?= $isToday ? 'color:#dc2626;font-weight:600' : '' ?>">
              <?= $odate ?>
            </span>
            <?php if ($isToday): ?>
              <span class="badge rounded-pill ms-1"
                    style="background:#fef2f2;color:#dc2626;font-size:.72rem">วันนี้</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.85rem;max-width:160px;white-space:normal"><?= $labname ?></td>
          <td>
            <span class="badge rounded-pill"
                  style="background:#fef2f2;color:#991b1b;font-size:.82rem;padding:.3rem .7rem;font-weight:600">
              <?= $labres ?: '-' ?>
            </span>
          </td>
          <td>
            <button class="btn-send-alert" id="btn-<?= $vn ?>"
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
  // ── DataTable ─────────────────────────────────────────────
  $("#tblSexual").DataTable({
    responsive: true,
    order: [[8, "desc"]],          // เรียงตามวันที่สั่ง LAB ล่าสุด
    pageLength: 25,
    lengthMenu: [[10,25,50,100,-1],["10","25","50","100","ทั้งหมด"]],
    dom: \'<"row align-items-center mb-2"<"col-sm-6"B><"col-sm-6 text-end"f>>rt<"row mt-2"<"col-sm-5"i><"col-sm-7"p>>\',
    buttons: [
      {extend:"excel", text:\'<span class="msi me-1">table_view</span> Excel\',
       className:"btn btn-sm btn-outline-success", title:"sexual_alert_".new Date().toLocaleDateString("th-TH")},
      {extend:"print",  text:\'<span class="msi me-1">print</span> พิมพ์\',
       className:"btn btn-sm btn-outline-secondary"},
    ],
    language: {
      search:"ค้นหา:", lengthMenu:"แสดง _MENU_ รายการ",
      info:"แสดง _START_–_END_ จาก _TOTAL_ รายการ",
      infoEmpty:"ไม่มีข้อมูล", paginate:{previous:"ก่อน",next:"ถัดไป"},
      zeroRecords:"ไม่พบข้อมูลที่ค้นหา"
    }
  });

  // ── Send alert (per row) ──────────────────────────────────
  document.addEventListener("click", function(e){
    const btn = e.target.closest(".btn-send-alert");
    if (!btn || btn.disabled) return;

    const vn   = btn.dataset.vn;
    const hn   = btn.dataset.hn;
    const name = btn.dataset.name;

    Swal.fire({
      title: "ส่งแจ้งเตือน?",
      html: `<div class="text-start" style="font-size:.9rem">
               <div><strong>VN:</strong> ${vn}</div>
               <div><strong>HN:</strong> ${hn}</div>
               <div><strong>ชื่อ:</strong> ${name}</div>
             </div>`,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: \'<span class="msi me-1">send</span> ส่งเลย\',
      cancelButtonText: "ยกเลิก",
      confirmButtonColor: "#db2777",
      reverseButtons: true,
      focusCancel: true,
    }).then(r => {
      if (!r.isConfirmed) return;

      // Show loading
      btn.disabled = true;
      btn.innerHTML = \'<span class="msi msi-spin">progress_activity</span> กำลังส่ง…\';
      btn.style.background = "#cbd5e1";

      fetch("sexual_action.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: new URLSearchParams({ action: "send", vn })
      })
      .then(res => res.json())
      .then(json => {
        if (json.ok) {
          btn.innerHTML = \'<span class="msi">check_circle</span> ส่งแล้ว\';
          Swal.fire({
            toast: true, position: "top-end", icon: "success", timer: 3000,
            showConfirmButton: false, timerProgressBar: true,
            title: "ส่งสำเร็จ",
            text: "Ref: " + (json.ref ?? "-")
          });
        } else {
          btn.disabled = false;
          btn.innerHTML = \'<span class="msi">send</span> ส่งแจ้งเตือน\';
          btn.style.background = "";
          Swal.fire({
            icon: "error", title: "ส่งไม่สำเร็จ",
            text: json.msg ?? "เกิดข้อผิดพลาด",
            confirmButtonColor: "#db2777"
          });
        }
      })
      .catch(err => {
        btn.disabled = false;
        btn.innerHTML = \'<span class="msi">send</span> ส่งแจ้งเตือน\';
        btn.style.background = "";
        Swal.fire({
          icon: "error", title: "Network error", text: err.message,
          confirmButtonColor: "#db2777"
        });
      });
    });
  });
});
</script>
';
require_once __DIR__ . '/partials/footer.php';
?>
