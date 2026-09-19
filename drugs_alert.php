<?php
/**
 * drugs_alert.php — คิวแจ้งเตือน Drug Alert (ยาเฝ้าระวังที่เภสัชเลือกเอง)
 *  - อ่านจาก drugs_alert_queue (MedAlert_DB)
 *  - เงื่อนไขดึงข้อมูล = icode ที่เภสัชเลือกผ่าน tag-input picker (ค้นจาก drugitems, real-time)
 *    ไม่ต้องพิมพ์/จำรหัสยาเอง — แก้ได้ทั้งจากการ์ดเด่นด้านบนและปุ่ม "แก้ไขเงื่อนไขดึงข้อมูล"
 *  - ปุ่ม: แก้เงื่อนไข · Sync จาก HOSxP · ดูตัวอย่าง Flex · Ingest+Send · Backfill ประวัติ · Dashboard
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/partials/filter_modal.php';
require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

if (!function_exists('to_utf8_da')) {
  function to_utf8_da($s) {
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
$icode  = isset($_GET['icode']) ? trim($_GET['icode']) : '';

if (!defined('DRUGS_ALERT_UI_ACTION_TOKEN')) {
  define('DRUGS_ALERT_UI_ACTION_TOKEN', hash('sha256', __DIR__ . '/drugs_alert.php' . php_uname() . date('Y-m-d')));
}

/* ---------- Query ---------- */
$w = ["vstdate BETWEEN :s AND :e"];
$p = [':s' => $start, ':e' => $end];
if ($status === '0' || $status === '1') { $w[] = "status = :st"; $p[':st'] = (int)$status; }
if ($icode !== '')                      { $w[] = "icode = :c"; $p[':c'] = $icode; }
$where = implode(' AND ', $w);

$rows = [];
$stat = ['total' => 0, 'pending' => 0, 'sent' => 0, 'error' => 0];
$queryError = null;
try {
  $st = $dbcon->prepare("SELECT * FROM drugs_alert_queue WHERE $where ORDER BY vstdate DESC, id DESC LIMIT 2000");
  $st->execute($p);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $stat['total']++;
    if ((int)$r['status'] === 1) $stat['sent']++; else $stat['pending']++;
    if (!empty($r['last_error'])) $stat['error']++;
  }
} catch (Throwable $e) { $queryError = $e->getMessage(); }

$icodes = [];
try {
  $icodes = $dbcon->query("SELECT DISTINCT icode FROM drugs_alert_queue WHERE icode <> '' ORDER BY icode")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { /* ตารางยังว่าง/ยังไม่มี */ }

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

$icodesNow = module_filter('drugs_alert')['icodes'] ?? [];

$PAGE_TITLE = 'Drug Alert';
$PAGE_KEY   = 'drugs_alert';
$EXTRA_HEAD = '
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<style>
  .da-kpi{border-radius:14px;padding:14px 16px;color:#fff}
  .da-kpi .n{font-size:1.6rem;font-weight:800;line-height:1.1}
  .da-kpi .l{font-size:.78rem;opacity:.9}
  #daBar{position:fixed;left:50%;transform:translateX(-50%) translateY(120%);bottom:18px;z-index:1050;
    display:flex;align-items:center;gap:10px;background:#0f172a;color:#fff;padding:10px 16px;
    border-radius:999px;box-shadow:0 10px 30px rgba(0,0,0,.3);transition:transform .22s}
  #daBar.show{transform:translateX(-50%) translateY(0)}
  #daBar .btn{border-radius:999px;font-size:.82rem}
  #daSyncResult{display:none;border-radius:8px;padding:10px 14px;font-size:.85rem;margin-top:10px}
  #daSyncResult.ok{background:#dcfce7;color:#166534} #daSyncResult.err{background:#fee2e2;color:#991b1b}
  .msi-spin{animation:daspin 1s linear infinite} @keyframes daspin{to{transform:rotate(360deg)}}
  .da-hero{border:1px solid var(--card-border);border-radius:16px;background:var(--card-bg);
    box-shadow:var(--card-shadow);padding:18px 20px;margin-bottom:1.25rem}
  .da-hero-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
  .da-hero-head .msi{font-size:1.3rem;color:#65a30d}
  .da-hero-head h5{margin:0;font-weight:700}
</style>';

require_once __DIR__ . '/partials/header.php';
?>

<div class="page-header">
  <h1><span class="msi me-2" style="color:#65a30d">pill</span><?= htmlspecialchars($PAGE_TITLE) ?></h1>
  <div class="d-flex gap-2 flex-wrap">
    <?= filter_edit_button('drugs_alert') ?>
    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#daSyncModal">
      <span class="msi me-1">sync</span>Sync จาก HOSxP
    </button>
    <a href="drugs_alert_flex_preview.php" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">
      <span class="msi me-1">smartphone</span>ดูตัวอย่าง Flex
    </a>
    <a href="drugs_alert_worker.php?mode=both" class="btn btn-outline-success btn-sm" target="_blank" rel="noopener"
       title="รันดึงข้อมูลจาก HOSxP (เมื่อวาน+วันนี้) + ส่งคิวที่ค้าง">
      <span class="msi me-1">bolt</span>Ingest + Send
    </a>
    <a href="drugs_alert_worker.php?mode=backfill&amp;start=2025-06-01&amp;end=<?= date('Y-m-d') ?>"
       class="btn btn-outline-warning btn-sm" target="_blank" rel="noopener"
       title="ดึงข้อมูลย้อนหลังเก็บเป็น 'ส่งแล้ว' ไว้ดูประวัติเท่านั้น — ไม่ยิง LINE">
      <span class="msi me-1">history</span>Backfill ประวัติ
    </a>
    <a href="dashboard.php?module=drugs_alert" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
      <span class="msi me-1">insights</span>Dashboard
    </a>
  </div>
</div>

<?= filter_flash_html() ?>
<?php if ($flash): [$cls, $txt] = explode(':', $flash, 2); ?>
  <div class="alert alert-<?= $cls ?> alert-dismissible fade show" style="border-radius:10px;font-size:.9rem">
    <?= $txt ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>
<?php if ($queryError): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:.9rem">
    อ่านตาราง <code>drugs_alert_queue</code> ไม่ได้: <?= htmlspecialchars($queryError) ?><br>
    ถ้ายังไม่มีตาราง ให้รัน <code>php db_migrate.php</code> หรือเปิดหน้า <code>db_config_admin.php</code>
  </div>
<?php endif; ?>

<!-- ═══ HERO: รายการยาที่เฝ้าระวัง (ค้นหา + tag-input picker) ═══ -->
<div class="da-hero">
  <div class="da-hero-head">
    <span class="msi">pill</span>
    <h5>รายการยาที่เฝ้าระวัง</h5>
  </div>
  <div class="alert alert-light border py-2 mb-3" style="font-size:.83rem;border-radius:10px">
    <span class="msi me-1" style="font-size:1rem;color:#65a30d">info</span>
    ค้นหาชื่อยาหรือรหัสยาแล้วกดเพิ่มเป็น tag — ไม่ต้องพิมพ์ icode เอง ทุก tag ที่เพิ่มใหม่จะโชว์
    จำนวนที่จ่ายเมื่อวาน+วันนี้ให้ดูก่อนกดบันทึก
  </div>
  <form method="post" action="module_filter_action.php">
    <input type="hidden" name="module" value="drugs_alert">
    <input type="hidden" name="token" value="<?= htmlspecialchars(defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : '') ?>">
    <input type="hidden" name="back" value="drugs_alert.php">
    <input type="hidden" name="action" value="save">
    <?php dp_render_field('drugs_alert', 'icodes', $icodesNow, 'dp_hero_icodes'); ?>
    <button type="submit" class="btn btn-success btn-sm mt-3">
      <span class="msi me-1">save</span>บันทึกรายการยา
    </button>
  </form>
</div>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['ทั้งหมด', $stat['total'],   '135deg,#84cc16,#65a30d'],
    ['รอส่ง',   $stat['pending'], '135deg,#f59e0b,#b45309'],
    ['ส่งแล้ว', $stat['sent'],    '135deg,#22c55e,#15803d'],
    ['มี Error', $stat['error'],  '135deg,#94a3b8,#475569'],
  ] as [$lab, $n, $grad]): ?>
    <div class="col-6 col-lg-3">
      <div class="da-kpi" style="background:linear-gradient(<?= $grad ?>)">
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
      <label class="form-label" style="font-size:.8rem">รหัสยา (icode)</label>
      <select name="icode" class="form-select form-select-sm">
        <option value="">ทั้งหมด</option>
        <?php foreach ($icodes as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>" <?= $icode === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-2 d-flex gap-2">
      <button class="btn btn-success btn-sm flex-grow-1"><span class="msi me-1">search</span>ค้นหา</button>
      <a class="btn btn-outline-secondary btn-sm" href="drugs_alert.php" title="รีเซ็ต"><span class="msi">restart_alt</span></a>
    </div>
  </div>
</form>

<form id="daForm" method="post" action="drugs_alert_queue_action.php">
  <input type="hidden" name="token"  value="<?= htmlspecialchars(DRUGS_ALERT_UI_ACTION_TOKEN) ?>">
  <input type="hidden" name="action" id="daAction" value="">
  <div class="card p-3">
    <div class="table-responsive">
      <table id="tblDa" class="table table-hover align-middle" style="width:100%">
        <thead>
          <tr>
            <th style="width:34px"><input type="checkbox" id="daAll" class="form-check-input"></th>
            <th>สถานะ</th><th>HN</th><th>ชื่อ-สกุล</th><th>อายุ</th>
            <th>รหัสยา</th><th>ชื่อยา</th><th>ความแรง/หน่วย</th><th>วันที่รับยา</th><th>Error</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input type="checkbox" class="form-check-input dachk" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
            <td><?= (int)$r['status'] === 1
                  ? '<span class="badge bg-success">ส่งแล้ว</span>'
                  : '<span class="badge bg-warning text-dark">รอส่ง</span>' ?></td>
            <td><?= htmlspecialchars(to_utf8_da($r['hn'])) ?></td>
            <td><?= htmlspecialchars(to_utf8_da($r['fullname'])) ?></td>
            <td><?= $r['age'] !== null ? (int)$r['age'] : '-' ?></td>
            <td><code><?= htmlspecialchars(to_utf8_da($r['icode'])) ?></code></td>
            <td><b style="color:#65a30d"><?= htmlspecialchars(to_utf8_da($r['drug_name'])) ?></b></td>
            <td><?= htmlspecialchars(trim(to_utf8_da($r['strength'] ?? '') . ' ' . to_utf8_da($r['units'] ?? ''))) ?: '-' ?></td>
            <td><?= htmlspecialchars((string)$r['vstdate']) ?></td>
            <td><?= $r['last_error']
                  ? '<span class="text-danger" style="font-size:.78rem">' . htmlspecialchars(mb_substr(to_utf8_da($r['last_error']), 0, 80)) . '</span>'
                  : '<span class="text-muted">-</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<div id="daBar">
  <span class="msi" style="color:#fbbf24">checklist</span>
  <span id="daCount">0 รายการที่เลือก</span>
  <button type="button" class="btn btn-success btn-sm" data-act="send_now"    data-label="ส่งซ้ำทันที"><span class="msi">send</span> ส่งซ้ำทันที</button>
  <button type="button" class="btn btn-warning btn-sm" data-act="requeue"     data-label="Requeue"><span class="msi">refresh</span> Requeue</button>
  <button type="button" class="btn btn-danger  btn-sm" data-act="clear_error" data-label="ล้าง Error"><span class="msi">backspace</span> ล้าง Error</button>
  <button type="button" class="btn btn-outline-light btn-sm" id="daCancel"><span class="msi">close</span></button>
</div>

<!-- Sync modal -->
<div class="modal fade" id="daSyncModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><span class="msi me-2">sync</span>Sync จาก HOSxP</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="alert alert-info py-2" style="font-size:.83rem;border-radius:10px">
          ใช้รหัสยาที่บันทึกไว้ในรายการเฝ้าระวังตอนนี้ (ด้านบนของหน้า) — Sync นี้เป็น recheck
          เท่านั้น ไม่ยิง LINE
        </div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label" style="font-size:.8rem">ตั้งแต่</label>
            <input type="date" id="daSyncStart" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('-7 days')) ?>"></div>
          <div class="col-6"><label class="form-label" style="font-size:.8rem">ถึง</label>
            <input type="date" id="daSyncEnd" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <div id="daSyncResult"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
        <button type="button" class="btn btn-success btn-sm" id="daSyncBtn" onclick="daSync()">
          <span class="msi me-1" id="daSyncIcon">sync</span><span id="daSyncText">เริ่ม Sync</span>
        </button>
      </div>
    </div>
  </div>
</div>

<?php render_filter_modal('drugs_alert'); ?>

<?php
$EXTRA_FOOTER = '
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function () {
  $("#tblDa").DataTable({
    pageLength: 25, order: [[7, "desc"]],
    columnDefs: [{ orderable: false, targets: 0 }],
    language: { search: "ค้นหา:", lengthMenu: "แสดง _MENU_ แถว", info: "_START_-_END_ จาก _TOTAL_",
                paginate: { previous: "ก่อนหน้า", next: "ถัดไป" }, zeroRecords: "ไม่พบข้อมูล", emptyTable: "ยังไม่มีข้อมูลในคิว" }
  });
});

function daUpdate() {
  var n = document.querySelectorAll(".dachk:checked").length;
  document.getElementById("daCount").textContent = n + " รายการที่เลือก";
  document.getElementById("daBar").classList.toggle("show", n > 0);
}
document.addEventListener("change", function (e) {
  if (e.target.id === "daAll") {
    document.querySelectorAll(".dachk").forEach(function (c) { c.checked = e.target.checked; });
  }
  if (e.target.classList.contains("dachk") || e.target.id === "daAll") daUpdate();
});
document.getElementById("daCancel").addEventListener("click", function () {
  document.querySelectorAll(".dachk, #daAll").forEach(function (c) { c.checked = false; });
  daUpdate();
});
document.querySelectorAll("#daBar [data-act]").forEach(function (b) {
  b.addEventListener("click", function () {
    var n = document.querySelectorAll(".dachk:checked").length;
    if (!n) return;
    Swal.fire({
      title: this.dataset.label, icon: "question", showCancelButton: true,
      html: "ดำเนินการกับ " + n + " รายการที่เลือก (เฉพาะแถวในหน้าปัจจุบัน)",
      confirmButtonText: "ยืนยัน", cancelButtonText: "ยกเลิก", reverseButtons: true
    }).then(function (r) {
      if (!r.isConfirmed) return;
      document.getElementById("daAction").value = b.dataset.act;
      document.getElementById("daForm").submit();
    });
  });
});

function daSync() {
  var btn = document.getElementById("daSyncBtn"), ic = document.getElementById("daSyncIcon"),
      tx = document.getElementById("daSyncText"), out = document.getElementById("daSyncResult");
  btn.disabled = true; ic.classList.add("msi-spin"); tx.textContent = "กำลัง Sync..."; out.style.display = "none";
  var fd = new FormData();
  fd.append("action", "import_hosxp");
  fd.append("start", document.getElementById("daSyncStart").value);
  fd.append("end",   document.getElementById("daSyncEnd").value);
  fetch("drugs_alert_queue_action.php", { method: "POST", body: fd })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      out.style.display = "block"; out.className = j.ok ? "ok" : "err"; out.textContent = j.msg;
      if (j.ok) setTimeout(function () {
        window.location.href = "drugs_alert.php?msg=imported&imported=" + (j.imported || 0) + "&new=" + (j.new || 0);
      }, 1200);
    })
    .catch(function (e) { out.style.display = "block"; out.className = "err"; out.textContent = "เชื่อมต่อไม่สำเร็จ: " + e; })
    .finally(function () { btn.disabled = false; ic.classList.remove("msi-spin"); tx.textContent = "เริ่ม Sync"; });
}
</script>';
require_once __DIR__ . '/partials/footer.php';
