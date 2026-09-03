<?php
/**
 * lab_hemato_queue_ui.php — คิวแจ้งเตือน Hematocrit Alert
 *  - อ่านจาก lab_hemato_queue (MedAlert_DB)
 *  - เงื่อนไขดึงข้อมูลแก้ผ่านปุ่ม "แก้ไขเงื่อนไขดึงข้อมูล" (field type labconds)
 *  - ปุ่ม: แก้เงื่อนไข · Sync จาก HOSxP · ดูตัวอย่าง Flex · Dashboard · รายงานรายวัน
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/partials/filter_modal.php';
require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

if (!function_exists('to_utf8_lh')) {
  function to_utf8_lh($s) {
    if (!is_string($s)) return $s;
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    foreach (['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc) {
      $t = @iconv($enc, 'UTF-8//IGNORE', $s); if ($t !== false && $t !== '') return $t;
      $t = @mb_convert_encoding($s, 'UTF-8', $enc); if ($t !== false && $t !== '') return $t;
    }
    return @iconv('UTF-8', 'UTF-8//IGNORE', $s);
  }
}

/* ---------- Filters ---------- */
$start  = isset($_GET['start']) && $_GET['start'] ? $_GET['start'] : date('Y-m-d', strtotime('-90 days'));
$end    = isset($_GET['end'])   && $_GET['end']   ? $_GET['end']   : date('Y-m-d');
$status = $_GET['status'] ?? 'all';
$code   = isset($_GET['code']) ? trim($_GET['code']) : '';

/* token seed เดียวกับ action (วันเปลี่ยน = token เปลี่ยน → ต้อง refresh หน้า) */
if (!defined('LAB_HEMATO_UI_ACTION_TOKEN')) {
  define('LAB_HEMATO_UI_ACTION_TOKEN', hash('sha256', __FILE__ . php_uname() . date('Y-m-d')));
}

/* ---------- Query ---------- */
$w = ["lab_date BETWEEN :s AND :e"];
$p = [':s' => $start, ':e' => $end];
if ($status === '0' || $status === '1') { $w[] = "status = :st"; $p[':st'] = (int)$status; }
if ($code !== '')                       { $w[] = "lab_items_code = :c"; $p[':c'] = $code; }
$where = implode(' AND ', $w);

$rows = [];
$stat = ['total' => 0, 'pending' => 0, 'sent' => 0, 'error' => 0];
try {
  $st = $dbcon->prepare("SELECT * FROM lab_hemato_queue WHERE $where ORDER BY lab_date DESC, id DESC");
  $st->execute($p);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $stat['total']++;
    if ((int)$r['status'] === 1) $stat['sent']++; else $stat['pending']++;
    if (!empty($r['last_error'])) $stat['error']++;
  }
} catch (Throwable $e) {
  $queryError = $e->getMessage();
}

/* รายการรหัส lab ที่มีอยู่จริงในคิว — ใช้ทำ dropdown กรอง */
$codes = [];
try {
  $codes = $dbcon->query("SELECT DISTINCT lab_items_code FROM lab_hemato_queue
                          WHERE lab_items_code <> '' ORDER BY lab_items_code")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { /* ตารางยังว่าง = ไม่ต้องมี dropdown */ }

/* ---------- Flash ---------- */
$flash = '';
if (isset($_GET['msg'])) {
  $aff = (int)($_GET['affected'] ?? 0);
  $ok  = (int)($_GET['ok'] ?? 0);
  $fa  = (int)($_GET['fail'] ?? 0);
  $flash = match ($_GET['msg']) {
    'sendnow'    => "success:ส่งสำเร็จ {$ok} รายการ" . ($fa > 0 ? " / ล้มเหลว {$fa} รายการ" : ''),
    'requeued'   => "success:Requeue แล้ว {$aff} รายการ (รอ worker รอบถัดไป หรือกดส่งซ้ำทันที)",
    'cleared'    => "success:ล้าง Error แล้ว {$aff} รายการ",
    'imported'   => "success:Sync จาก HOSxP สำเร็จ " . (int)($_GET['imported'] ?? 0) . " รายการ (ใหม่ " . (int)($_GET['new'] ?? 0) . " รายการ)",
    'no_ids'     => "warning:ยังไม่ได้เลือกรายการ",
    'bad_action' => "danger:คำสั่งไม่ถูกต้อง",
    'err'        => "danger:เกิดข้อผิดพลาด: " . htmlspecialchars((string)($_GET['detail'] ?? '')),
    default      => '',
  };
}

$cfgSummary = mf_labconds_summary(module_filter('lab_hemato')['groups'] ?? []);

$PAGE_TITLE = 'Hematocrit Alert';
$PAGE_KEY   = 'lab_hemato';
$EXTRA_HEAD = '
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<style>
  .lh-kpi{border-radius:14px;padding:14px 16px;color:#fff}
  .lh-kpi .n{font-size:1.6rem;font-weight:800;line-height:1.1}
  .lh-kpi .l{font-size:.78rem;opacity:.9}
  #lhBar{position:fixed;left:50%;transform:translateX(-50%) translateY(120%);bottom:18px;z-index:1050;
    display:flex;align-items:center;gap:10px;background:#0f172a;color:#fff;padding:10px 16px;
    border-radius:999px;box-shadow:0 10px 30px rgba(0,0,0,.3);transition:transform .22s}
  #lhBar.show{transform:translateX(-50%) translateY(0)}
  #lhBar .btn{border-radius:999px;font-size:.82rem}
  #lhSyncResult{display:none;border-radius:8px;padding:10px 14px;font-size:.85rem;margin-top:10px}
  #lhSyncResult.ok{background:#dcfce7;color:#166534} #lhSyncResult.err{background:#fee2e2;color:#991b1b}
  .msi-spin{animation:lhspin 1s linear infinite} @keyframes lhspin{to{transform:rotate(360deg)}}
</style>';

require_once __DIR__ . '/partials/header.php';
?>

<div class="page-header">
  <h1><span class="msi me-2" style="color:#9F1239">bloodtype</span><?= htmlspecialchars($PAGE_TITLE) ?></h1>
  <div class="d-flex gap-2 flex-wrap">
    <?= filter_edit_button('lab_hemato') ?>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#lhSyncModal">
      <span class="msi me-1">sync</span>Sync จาก HOSxP
    </button>
    <a href="lab_hemato_flex_preview.php" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">
      <span class="msi me-1">smartphone</span>ดูตัวอย่าง Flex
    </a>
    <a href="dashboard.php?module=lab_hemato" class="btn btn-outline-success btn-sm" target="_blank" rel="noopener">
      <span class="msi me-1">insights</span>Dashboard
    </a>
    <a href="report_daily.php?date=<?= date('Y-m-d') ?>" class="btn btn-outline-warning btn-sm" target="_blank" rel="noopener">
      <span class="msi me-1">description</span>รายงานรายวัน
    </a>
  </div>
</div>

<?= filter_flash_html() ?>
<?php if ($flash): [$cls, $txt] = explode(':', $flash, 2); ?>
  <div class="alert alert-<?= $cls ?> alert-dismissible fade show" style="border-radius:10px;font-size:.9rem">
    <?= $txt ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>
<?php if (!empty($queryError)): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:.9rem">
    อ่านตาราง <code>lab_hemato_queue</code> ไม่ได้: <?= htmlspecialchars($queryError) ?><br>
    ถ้ายังไม่มีตาราง ให้รัน <code>php db_migrate.php</code> หรือเปิดหน้า <code>db_config_admin.php</code>
  </div>
<?php endif; ?>

<div class="alert alert-light border d-flex align-items-start gap-2" style="border-radius:10px;font-size:.83rem">
  <span class="msi" style="font-size:1rem;color:#9F1239">tune</span>
  <span>เงื่อนไขที่ใช้ดึงข้อมูลตอนนี้: <b><?= htmlspecialchars($cfgSummary) ?></b>
    — แก้ได้ที่ปุ่ม "แก้ไขเงื่อนไขดึงข้อมูล"</span>
</div>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['ทั้งหมด', $stat['total'],   '135deg,#f43f5e,#9f1239'],
    ['รอส่ง',   $stat['pending'], '135deg,#f59e0b,#b45309'],
    ['ส่งแล้ว', $stat['sent'],    '135deg,#22c55e,#15803d'],
    ['มี Error', $stat['error'],  '135deg,#94a3b8,#475569'],
  ] as [$lab, $n, $grad]): ?>
    <div class="col-6 col-lg-3">
      <div class="lh-kpi" style="background:linear-gradient(<?= $grad ?>)">
        <div class="n"><?= number_format($n) ?></div><div class="l"><?= $lab ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<form class="card p-3 mb-3" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label" style="font-size:.8rem">ตั้งแต่วันที่</label>
      <input type="date" name="start" class="form-control form-control-sm" value="<?= htmlspecialchars($start) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label" style="font-size:.8rem">ถึงวันที่</label>
      <input type="date" name="end" class="form-control form-control-sm" value="<?= htmlspecialchars($end) ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label" style="font-size:.8rem">สถานะ</label>
      <select name="status" class="form-select form-select-sm">
        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
        <option value="0"   <?= $status === '0'   ? 'selected' : '' ?>>รอส่ง</option>
        <option value="1"   <?= $status === '1'   ? 'selected' : '' ?>>ส่งแล้ว</option>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label" style="font-size:.8rem">รหัส Lab</label>
      <select name="code" class="form-select form-select-sm">
        <option value="">ทั้งหมด</option>
        <?php foreach ($codes as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>" <?= $code === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-2 d-flex gap-2">
      <button class="btn btn-primary btn-sm flex-grow-1"><span class="msi me-1">search</span>ค้นหา</button>
      <a class="btn btn-outline-secondary btn-sm" href="lab_hemato_queue_ui.php" title="รีเซ็ต"><span class="msi">restart_alt</span></a>
    </div>
  </div>
</form>

<form id="lhForm" method="post" action="lab_hemato_queue_action.php">
  <input type="hidden" name="token"  value="<?= htmlspecialchars(LAB_HEMATO_UI_ACTION_TOKEN) ?>">
  <input type="hidden" name="action" id="lhAction" value="">
  <div class="card p-3">
    <div class="table-responsive">
      <table id="tblLH" class="table table-hover align-middle" style="width:100%">
        <thead>
          <tr>
            <th style="width:34px"><input type="checkbox" id="lhAll" class="form-check-input"></th>
            <th>สถานะ</th><th>HN</th><th>ชื่อ-สกุล</th><th>อายุ</th>
            <th>รหัส Lab</th><th>ผล</th><th>วันที่ตรวจ</th><th>แพทย์</th><th>Error</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input type="checkbox" class="form-check-input lhchk" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
            <td><?= (int)$r['status'] === 1
                  ? '<span class="badge bg-success">ส่งแล้ว</span>'
                  : '<span class="badge bg-warning text-dark">รอส่ง</span>' ?></td>
            <td><?= htmlspecialchars(to_utf8_lh($r['hn'])) ?></td>
            <td><?= htmlspecialchars(to_utf8_lh($r['fullname'])) ?></td>
            <td><?= $r['age'] !== null ? (int)$r['age'] : '-' ?></td>
            <td><code><?= htmlspecialchars(to_utf8_lh($r['lab_items_code'])) ?></code></td>
            <td><b style="color:#9F1239"><?= htmlspecialchars(to_utf8_lh($r['result'])) ?></b></td>
            <td><?= htmlspecialchars((string)$r['lab_date']) ?></td>
            <td><?= htmlspecialchars(to_utf8_lh($r['doctor'])) ?></td>
            <td><?= $r['last_error']
                  ? '<span class="text-danger" style="font-size:.78rem">' . htmlspecialchars(mb_substr(to_utf8_lh($r['last_error']), 0, 80)) . '</span>'
                  : '<span class="text-muted">-</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<div id="lhBar">
  <span class="msi" style="color:#fbbf24">checklist</span>
  <span id="lhCount">0 รายการที่เลือก</span>
  <button type="button" class="btn btn-success btn-sm" data-act="send_now"    data-label="ส่งซ้ำทันที"><span class="msi">send</span> ส่งซ้ำทันที</button>
  <button type="button" class="btn btn-warning btn-sm" data-act="requeue"     data-label="Requeue"><span class="msi">refresh</span> Requeue</button>
  <button type="button" class="btn btn-danger  btn-sm" data-act="clear_error" data-label="ล้าง Error"><span class="msi">backspace</span> ล้าง Error</button>
  <button type="button" class="btn btn-outline-light btn-sm" id="lhCancel"><span class="msi">close</span></button>
</div>

<!-- Sync modal -->
<div class="modal fade" id="lhSyncModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><span class="msi me-2">sync</span>Sync จาก HOSxP</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="alert alert-info py-2" style="font-size:.83rem;border-radius:10px">
          ใช้เงื่อนไขที่บันทึกไว้: <b><?= htmlspecialchars($cfgSummary) ?></b>
        </div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label" style="font-size:.8rem">ตั้งแต่</label>
            <input type="date" id="lhSyncStart" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('-7 days')) ?>"></div>
          <div class="col-6"><label class="form-label" style="font-size:.8rem">ถึง</label>
            <input type="date" id="lhSyncEnd" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <div id="lhSyncResult"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
        <button type="button" class="btn btn-primary btn-sm" id="lhSyncBtn" onclick="lhSync()">
          <span class="msi me-1" id="lhSyncIcon">sync</span><span id="lhSyncText">เริ่ม Sync</span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php render_filter_modal('lab_hemato'); ?>

<?php
$EXTRA_FOOTER = '
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function () {
  $("#tblLH").DataTable({
    pageLength: 25, order: [[7, "desc"]],
    columnDefs: [{ orderable: false, targets: 0 }],
    language: { search: "ค้นหา:", lengthMenu: "แสดง _MENU_ แถว", info: "_START_-_END_ จาก _TOTAL_",
                paginate: { previous: "ก่อนหน้า", next: "ถัดไป" }, zeroRecords: "ไม่พบข้อมูล", emptyTable: "ยังไม่มีข้อมูลในคิว" }
  });
});

// DataTables ถอดแถวหน้าอื่นออกจาก DOM — checkbox หน้าอื่นจะไม่ถูกส่งไปกับฟอร์ม
// จึงนับ/เลือกเฉพาะแถวที่มองเห็นอยู่ และเตือนผู้ใช้ผ่านจำนวนที่แสดง
function lhUpdate() {
  var n = document.querySelectorAll(".lhchk:checked").length;
  document.getElementById("lhCount").textContent = n + " รายการที่เลือก";
  document.getElementById("lhBar").classList.toggle("show", n > 0);
}
document.addEventListener("change", function (e) {
  if (e.target.id === "lhAll") {
    document.querySelectorAll(".lhchk").forEach(function (c) { c.checked = e.target.checked; });
  }
  if (e.target.classList.contains("lhchk") || e.target.id === "lhAll") lhUpdate();
});
document.getElementById("lhCancel").addEventListener("click", function () {
  document.querySelectorAll(".lhchk, #lhAll").forEach(function (c) { c.checked = false; });
  lhUpdate();
});
document.querySelectorAll("#lhBar [data-act]").forEach(function (b) {
  b.addEventListener("click", function () {
    var n = document.querySelectorAll(".lhchk:checked").length;
    if (!n) return;
    Swal.fire({
      title: this.dataset.label, icon: "question", showCancelButton: true,
      html: "ดำเนินการกับ " + n + " รายการที่เลือก (เฉพาะแถวในหน้าปัจจุบัน)",
      confirmButtonText: "ยืนยัน", cancelButtonText: "ยกเลิก", reverseButtons: true
    }).then(function (r) {
      if (!r.isConfirmed) return;
      document.getElementById("lhAction").value = b.dataset.act;
      document.getElementById("lhForm").submit();
    });
  });
});

function lhSync() {
  var btn = document.getElementById("lhSyncBtn"), ic = document.getElementById("lhSyncIcon"),
      tx = document.getElementById("lhSyncText"), out = document.getElementById("lhSyncResult");
  btn.disabled = true; ic.classList.add("msi-spin"); tx.textContent = "กำลัง Sync..."; out.style.display = "none";
  var fd = new FormData();
  fd.append("action", "import_hosxp");
  fd.append("start", document.getElementById("lhSyncStart").value);
  fd.append("end",   document.getElementById("lhSyncEnd").value);
  fetch("lab_hemato_queue_action.php", { method: "POST", body: fd })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      out.style.display = "block"; out.className = j.ok ? "ok" : "err"; out.textContent = j.msg;
      if (j.ok) setTimeout(function () {
        window.location.href = "lab_hemato_queue_ui.php?msg=imported&imported=" + (j.imported || 0) + "&new=" + (j.new || 0);
      }, 1200);
    })
    .catch(function (e) { out.style.display = "block"; out.className = "err"; out.textContent = "เชื่อมต่อไม่สำเร็จ: " + e; })
    .finally(function () { btn.disabled = false; ic.classList.remove("msi-spin"); tx.textContent = "เริ่ม Sync"; });
}
</script>';
require_once __DIR__ . '/partials/footer.php';
