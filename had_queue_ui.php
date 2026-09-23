<?php
/**
 * had_queue_ui.php — คิวแจ้งเตือน HAD Alert (High Alert Drug)
 *  - อ่านจาก had_queue (MedAlert_DB)
 *  - เงื่อนไขดึงข้อมูล = icode (คั่น ,) แก้ผ่านปุ่ม "แก้ไขเงื่อนไขดึงข้อมูล"
 *  - ปุ่ม: แก้เงื่อนไข · Sync จาก HOSxP · ดูตัวอย่าง Flex · Dashboard · รายงานรายวัน
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/partials/filter_modal.php';
require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

if (!function_exists('to_utf8_had')) {
  function to_utf8_had($s) {
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
$start = isset($_GET['start']) && $_GET['start'] ? $_GET['start'] : date('Y-m-d', strtotime('-90 days'));
$end   = isset($_GET['end'])   && $_GET['end']   ? $_GET['end']   : date('Y-m-d');
$status = $_GET['status'] ?? 'all';
$icode  = isset($_GET['icode']) ? trim($_GET['icode']) : '';

if (!defined('HAD_UI_ACTION_TOKEN')) {
  define('HAD_UI_ACTION_TOKEN', hash('sha256', __FILE__ . php_uname() . date('Y-m-d')));
}

/* ---------- Query ---------- */
$w = ["vstdate BETWEEN :s AND :e"];
$p = [':s' => $start, ':e' => $end];
if ($status === '0' || $status === '1') { $w[] = "status = :st"; $p[':st'] = (int)$status; }
if ($icode !== '')                      { $w[] = "icode = :c"; $p[':c'] = $icode; }
$where = implode(' AND ', $w);

$rows = [];
$stat = ['total' => 0, 'pending' => 0, 'sent' => 0, 'error' => 0];
try {
  $st = $dbcon->prepare("SELECT * FROM had_queue WHERE $where ORDER BY vstdate DESC, id DESC");
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
  $icodes = $dbcon->query("SELECT DISTINCT icode FROM had_queue WHERE icode <> '' ORDER BY icode")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { /* ตารางยังว่าง */ }

/* ---------- Flash ---------- */
$flash = '';
if (isset($_GET['msg'])) {
  $aff = (int)($_GET['affected'] ?? 0);
  $ok  = (int)($_GET['ok'] ?? 0);
  $fa  = (int)($_GET['fail'] ?? 0);
  $flash = match ($_GET['msg']) {
    'sendnow'    => "success:ส่งสำเร็จ {$ok} รายการ" . ($fa > 0 ? " / ล้มเหลว {$fa} รายการ" : ''),
    'cleared'    => "success:ล้าง Error แล้ว {$aff} รายการ",
    'imported'   => "success:Sync จาก HOSxP สำเร็จ " . (int)($_GET['imported'] ?? 0) . " รายการ (ใหม่ " . (int)($_GET['new'] ?? 0) . " รายการ)",
    'no_ids'     => "warning:ยังไม่ได้เลือกรายการ",
    'bad_action' => "danger:คำสั่งไม่ถูกต้อง",
    'win_saved'  => "success:บันทึกช่วงเวลาแจ้งเตือนแล้ว — มีผลกับรอบส่งอัตโนมัติถัดไป",
    'win_off'    => "success:ปิดช่วงเวลาแจ้งเตือนแล้ว — กลับไปส่งอัตโนมัติตลอด 24 ชม.",
    'win_bad'    => "danger:เวลาไม่ถูกต้อง (ต้องเป็น HH:MM แบบ 24 ชม.) — ยังไม่บันทึก ใช้ค่าเดิมต่อไป",
    'win_err'    => "danger:บันทึกช่วงเวลาไม่สำเร็จ — ตรวจสิทธิ์เขียนโฟลเดอร์ secrets/",
    'err'        => "danger:เกิดข้อผิดพลาด: " . htmlspecialchars((string)($_GET['detail'] ?? '')),
    default      => '',
  };
}

$icodesNow = module_filter('had')['icodes'] ?? [];
$cfgSummary = $icodesNow ? implode(', ', $icodesNow) : '(ยังไม่ได้ตั้งเงื่อนไข)';

/* ช่วงเวลาแจ้งเตือน (Alert Window) — จำกัดเฉพาะการส่งอัตโนมัติของ worker (ดู docs/adr/0004, เดิม 0003) */
$win     = alert_window('had');
$winOpen = alert_window_is_open('had');
$winSum  = alert_window_summary('had');

$PAGE_TITLE = 'HAD Alert';
$PAGE_KEY   = 'had';
$EXTRA_HEAD = '
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<style>
  .had-kpi{border-radius:14px;padding:14px 16px;color:#fff}
  .had-kpi .n{font-size:1.6rem;font-weight:800;line-height:1.1}
  .had-kpi .l{font-size:.78rem;opacity:.9}
  #hadBar{position:fixed;left:50%;transform:translateX(-50%) translateY(120%);bottom:18px;z-index:1050;
    display:flex;align-items:center;gap:10px;background:#0f172a;color:#fff;padding:10px 16px;
    border-radius:999px;box-shadow:0 10px 30px rgba(0,0,0,.3);transition:transform .22s}
  #hadBar.show{transform:translateX(-50%) translateY(0)}
  #hadBar .btn{border-radius:999px;font-size:.82rem}
  #hadSyncResult{display:none;border-radius:8px;padding:10px 14px;font-size:.85rem;margin-top:10px}
  #hadSyncResult.ok{background:#dcfce7;color:#166534} #hadSyncResult.err{background:#fee2e2;color:#991b1b}
  .msi-spin{animation:hadspin 1s linear infinite} @keyframes hadspin{to{transform:rotate(360deg)}}
</style>';

require_once __DIR__ . '/partials/header.php';
?>

<div class="page-header">
  <h1><span class="msi me-2" style="color:#0E7490">medication_liquid</span><?= htmlspecialchars($PAGE_TITLE) ?></h1>
  <div class="d-flex gap-2 flex-wrap">
    <?= filter_edit_button('had') ?>
    <button type="button" data-bs-toggle="modal" data-bs-target="#hadWindowModal"
            class="btn btn-sm <?= !$win['enabled'] ? 'btn-outline-secondary' : ($winOpen ? 'btn-outline-success' : 'btn-outline-danger') ?>"
            title="จำกัดเวลาส่งอัตโนมัติ (ไม่กระทบปุ่มส่งซ้ำทันที)">
      <span class="msi me-1">schedule</span>ช่วงเวลาแจ้งเตือน
    </button>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#hadSyncModal">
      <span class="msi me-1">sync</span>Sync จาก HOSxP
    </button>
    <a href="had_flex_preview.php" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">
      <span class="msi me-1">smartphone</span>ดูตัวอย่าง Flex
    </a>
    <a href="dashboard.php?module=had" class="btn btn-outline-success btn-sm" target="_blank" rel="noopener">
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
    อ่านตาราง <code>had_queue</code> ไม่ได้: <?= htmlspecialchars($queryError) ?><br>
    ถ้ายังไม่มีตาราง ให้รัน <code>php db_migrate.php</code> หรือเปิดหน้า <code>db_config_admin.php</code>
  </div>
<?php endif; ?>

<div class="alert alert-light border d-flex align-items-start gap-2" style="border-radius:10px;font-size:.83rem">
  <span class="msi" style="font-size:1rem;color:#0E7490">tune</span>
  <span>รหัสยา (icode) ที่ใช้ดึงข้อมูลตอนนี้: <b><?= htmlspecialchars($cfgSummary) ?></b>
    — แก้ได้ที่ปุ่ม "แก้ไขเงื่อนไขดึงข้อมูล"</span>
</div>

<div class="alert <?= ($win['enabled'] && !$winOpen) ? 'alert-warning' : 'alert-light' ?> border d-flex align-items-start gap-2"
     style="border-radius:10px;font-size:.83rem">
  <span class="msi" style="font-size:1rem;color:#0E7490">schedule</span>
  <span>ช่วงเวลาแจ้งเตือน (เฉพาะการส่งอัตโนมัติ): <b><?= htmlspecialchars($winSum) ?></b>
    <?php if ($win['enabled'] && !$winOpen): ?>
      — <b class="text-danger">ขณะนี้อยู่นอกช่วง</b> รายการที่ขึ้น "รอส่ง" จะถูกส่งเมื่อถึง
      <b><?= htmlspecialchars($win['start']) ?> น.</b> (การดึงข้อมูลจาก HOSxP ยังทำงานตามปกติ)
    <?php elseif ($win['enabled']): ?>
      — ขณะนี้<b class="text-success">อยู่ในช่วง</b> ส่งอัตโนมัติตามปกติ
    <?php endif; ?>
    — แก้ได้ที่ปุ่ม "ช่วงเวลาแจ้งเตือน" · เวลาเครื่องเซิร์ฟเวอร์ตอนนี้ <b><?= date('H:i') ?> น.</b></span>
</div>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['ทั้งหมด', $stat['total'],   '135deg,#22d3ee,#0e7490'],
    ['รอส่ง',   $stat['pending'], '135deg,#f59e0b,#b45309'],
    ['ส่งแล้ว', $stat['sent'],    '135deg,#22c55e,#15803d'],
    ['มี Error', $stat['error'],  '135deg,#94a3b8,#475569'],
  ] as [$lab, $n, $grad]): ?>
    <div class="col-6 col-lg-3">
      <div class="had-kpi" style="background:linear-gradient(<?= $grad ?>)">
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
      <button class="btn btn-primary btn-sm flex-grow-1"><span class="msi me-1">search</span>ค้นหา</button>
      <a class="btn btn-outline-secondary btn-sm" href="had_queue_ui.php" title="รีเซ็ต"><span class="msi">restart_alt</span></a>
    </div>
  </div>
</form>

<form id="hadForm" method="post" action="had_queue_action.php">
  <input type="hidden" name="token"  value="<?= htmlspecialchars(HAD_UI_ACTION_TOKEN) ?>">
  <input type="hidden" name="action" id="hadAction" value="">
  <div class="card p-3">
    <div class="table-responsive">
      <table id="tblHad" class="table table-hover align-middle" style="width:100%">
        <thead>
          <tr>
            <th style="width:34px"><input type="checkbox" id="hadAll" class="form-check-input"></th>
            <th>สถานะ</th><th>HN</th><th>ชื่อ-สกุล</th><th>อายุ</th>
            <th>รหัสยา</th><th>ชื่อยา</th><th>จำนวน</th><th>วันที่รับยา</th><th>Error</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input type="checkbox" class="form-check-input hadchk" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
            <td><?= (int)$r['status'] === 1
                  ? '<span class="badge bg-success">ส่งแล้ว</span>'
                  : '<span class="badge bg-warning text-dark">รอส่ง</span>' ?></td>
            <td><?= htmlspecialchars(to_utf8_had($r['hn'])) ?></td>
            <td><?= htmlspecialchars(to_utf8_had($r['fullname'])) ?></td>
            <td><?= $r['age'] !== null ? (int)$r['age'] : '-' ?></td>
            <td><code><?= htmlspecialchars(to_utf8_had($r['icode'])) ?></code></td>
            <td><b style="color:#0E7490"><?= htmlspecialchars(to_utf8_had($r['drug_name'])) ?></b></td>
            <td><?= $r['qty'] !== null ? number_format((float)$r['qty'], 2) : '-' ?></td>
            <td><?= htmlspecialchars((string)$r['vstdate']) ?></td>
            <td><?= $r['last_error']
                  ? '<span class="text-danger" style="font-size:.78rem">' . htmlspecialchars(mb_substr(to_utf8_had($r['last_error']), 0, 80)) . '</span>'
                  : '<span class="text-muted">-</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<div id="hadBar">
  <span class="msi" style="color:#fbbf24">checklist</span>
  <span id="hadCount">0 รายการที่เลือก</span>
  <button type="button" class="btn btn-success btn-sm" data-act="send_now"    data-label="ส่งซ้ำทันที"><span class="msi">send</span> ส่งซ้ำทันที</button>
  <button type="button" class="btn btn-danger  btn-sm" data-act="clear_error" data-label="ล้าง Error"><span class="msi">backspace</span> ล้าง Error</button>
  <button type="button" class="btn btn-outline-light btn-sm" id="hadCancel"><span class="msi">close</span></button>
</div>

<!-- Sync modal -->
<div class="modal fade" id="hadSyncModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><span class="msi me-2">sync</span>Sync จาก HOSxP</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="alert alert-info py-2" style="font-size:.83rem;border-radius:10px">
          ใช้รหัสยา (icode) ที่บันทึกไว้: <b><?= htmlspecialchars($cfgSummary) ?></b>
        </div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label" style="font-size:.8rem">ตั้งแต่</label>
            <input type="date" id="hadSyncStart" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('-7 days')) ?>"></div>
          <div class="col-6"><label class="form-label" style="font-size:.8rem">ถึง</label>
            <input type="date" id="hadSyncEnd" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <div id="hadSyncResult"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
        <button type="button" class="btn btn-primary btn-sm" id="hadSyncBtn" onclick="hadSync()">
          <span class="msi me-1" id="hadSyncIcon">sync</span><span id="hadSyncText">เริ่ม Sync</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Alert Window modal — ตั้งช่วงเวลาที่อนุญาตให้ worker ส่งอัตโนมัติ (ดู docs/adr/0004, เดิม 0003) -->
<div class="modal fade" id="hadWindowModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="had_queue_action.php" autocomplete="off">
        <input type="hidden" name="token"  value="<?= htmlspecialchars(HAD_UI_ACTION_TOKEN) ?>">
        <input type="hidden" name="action" value="save_window">
        <div class="modal-header">
          <h5 class="modal-title"><span class="msi me-2" style="color:#0E7490">schedule</span>ช่วงเวลาแจ้งเตือน</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info py-2 d-flex align-items-start gap-2" style="font-size:.83rem;border-radius:10px">
            <span class="msi" style="font-size:1rem">info</span>
            <span>จำกัดเฉพาะ<b>การส่งอัตโนมัติ</b>เท่านั้น · การดึงข้อมูลจาก HOSxP ยังทำงานทั้งวัน
              แต่ <b>auto-send เฉพาะรายการที่เกิดขึ้นในช่วงเวลานี้เท่านั้น</b> —
              รายการที่เกิดนอกช่วงจะค้าง "รอส่ง" ตลอดไป ไม่ไหลออกเองแม้หน้าต่างจะเปิดรอบถัดไป ·
              ต้องกดปุ่ม "<b>ส่งซ้ำทันที</b>" เองเท่านั้น (<b>ใช้ได้ตลอดเวลา</b> ไม่ถูกจำกัด)</span>
          </div>

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" value="1"
                   id="hadWinEnabled" name="win_enabled" <?= $win['enabled'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="hadWinEnabled">เปิดใช้ช่วงเวลาแจ้งเตือน
              <span class="text-muted" style="font-size:.82rem">(ปิด = ส่งตลอด 24 ชม. เหมือนเดิม)</span>
            </label>
          </div>

          <div class="row g-2">
            <div class="col-6">
              <label class="form-label" style="font-size:.8rem">เริ่มส่ง (รวมนาทีนี้)</label>
              <input type="time" id="hadWinStart" name="win_start" class="form-control form-control-sm"
                     value="<?= htmlspecialchars($win['start']) ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label" style="font-size:.8rem">หยุดส่ง (ไม่รวมนาทีนี้)</label>
              <input type="time" id="hadWinEnd" name="win_end" class="form-control form-control-sm"
                     value="<?= htmlspecialchars($win['end']) ?>" required>
            </div>
          </div>
          <div class="form-text" style="font-size:.75rem">
            ตั้งเวลาเริ่มช้ากว่าเวลาหยุดได้ = ข้ามเที่ยงคืน (เช่น 16:30 → 08:00) ·
            ตั้งเริ่ม = หยุด คือเปิดตลอด 24 ชม. ·
            เวลาเริ่มส่งจริงอาจช้ากว่าที่ตั้งได้ถึง <?= AW_CRON_MIN ?> นาที เพราะระบบวิ่งรอบละ <?= AW_CRON_MIN ?> นาที
          </div>

          <div id="hadWinCalc" class="alert py-2 mt-3" style="font-size:.83rem;border-radius:10px"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn btn-primary btn-sm"><span class="msi me-1">save</span>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php render_filter_modal('had'); ?>

<?php
$EXTRA_FOOTER = '
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
/* ค่าจากฝั่ง PHP — ต้องเป็นตัวเลขชุดเดียวกับที่ worker ใช้จริง (ดู alert_window_loader.php) */
var HAD_WIN_LIMIT = ' . AW_SEND_LIMIT . ', HAD_WIN_CRON = ' . AW_CRON_MIN . ';
var HAD_WIN_ON    = ' . ($win['enabled'] ? 'true' : 'false') . ';
var HAD_WIN_SMIN  = ' . aw_minutes($win['start']) . ', HAD_WIN_EMIN = ' . aw_minutes($win['end']) . ';
var HAD_WIN_TXT   = ' . json_encode($winSum, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
$(function () {
  $("#tblHad").DataTable({
    pageLength: 25, order: [[7, "desc"]],
    columnDefs: [{ orderable: false, targets: 0 }],
    language: { search: "ค้นหา:", lengthMenu: "แสดง _MENU_ แถว", info: "_START_-_END_ จาก _TOTAL_",
                paginate: { previous: "ก่อนหน้า", next: "ถัดไป" }, zeroRecords: "ไม่พบข้อมูล", emptyTable: "ยังไม่มีข้อมูลในคิว" }
  });
});

function hadUpdate() {
  var n = document.querySelectorAll(".hadchk:checked").length;
  document.getElementById("hadCount").textContent = n + " รายการที่เลือก";
  document.getElementById("hadBar").classList.toggle("show", n > 0);
}
document.addEventListener("change", function (e) {
  if (e.target.id === "hadAll") {
    document.querySelectorAll(".hadchk").forEach(function (c) { c.checked = e.target.checked; });
  }
  if (e.target.classList.contains("hadchk") || e.target.id === "hadAll") hadUpdate();
});
document.getElementById("hadCancel").addEventListener("click", function () {
  document.querySelectorAll(".hadchk, #hadAll").forEach(function (c) { c.checked = false; });
  hadUpdate();
});
/* คำนวณใหม่จากนาฬิกา "เบราว์เซอร์" ตอนคลิก ไม่ใช้ค่าที่ PHP คำนวณไว้ตอน render
   เพราะห้องยามักเปิดหน้านี้ค้างไว้ข้ามเวลา 16:30 พอดี (เป็นกะที่ฟีเจอร์นี้ออกแบบมาเพื่อ) */
function hadWinOpenNow() {
  if (!HAD_WIN_ON) return true;
  if (HAD_WIN_SMIN === HAD_WIN_EMIN) return true;
  var d = new Date(), n = d.getHours() * 60 + d.getMinutes();
  return (HAD_WIN_SMIN < HAD_WIN_EMIN)
    ? (n >= HAD_WIN_SMIN && n < HAD_WIN_EMIN)
    : (n >= HAD_WIN_SMIN || n < HAD_WIN_EMIN);
}

document.querySelectorAll("#hadBar [data-act]").forEach(function (b) {
  b.addEventListener("click", function () {
    var n = document.querySelectorAll(".hadchk:checked").length;
    if (!n) return;
    var body = "ดำเนินการกับ " + n + " รายการที่เลือก (เฉพาะแถวในหน้าปัจจุบัน)";
    /* เตือนเฉพาะ send_now — clear_error ไม่ได้ยิง LINE ออกไปจริง */
    if (b.dataset.act === "send_now" && !hadWinOpenNow()) {
      body += "<div class=\'mt-2 text-danger\' style=\'font-size:.85rem\'>"
            + "⚠ ขณะนี้อยู่<b>นอกช่วงเวลาแจ้งเตือน</b> (" + HAD_WIN_TXT + ")<br>"
            + "การกดปุ่มนี้จะ<b>ยิง LINE ออกไปทันที</b> ไม่รอถึงเวลาที่ตั้งไว้</div>";
    }
    Swal.fire({
      title: this.dataset.label, icon: "question", showCancelButton: true,
      html: body,
      confirmButtonText: "ยืนยัน", cancelButtonText: "ยกเลิก", reverseButtons: true
    }).then(function (r) {
      if (!r.isConfirmed) return;
      document.getElementById("hadAction").value = b.dataset.act;
      document.getElementById("hadForm").submit();
    });
  });
});

/* ตัวคำนวณความจุสดในโมดัลช่วงเวลา — ความจุ = (ความยาวช่วง ÷ รอบ Cron) × LIMIT ต่อรอบ
   โดยเลขทั้งสองตัวมาจาก AW_CRON_MIN / AW_SEND_LIMIT ที่ worker ใช้จริง (ฝังมาข้างบน)
   กับดักที่ต้องกัน: ตั้งช่วงสั้นเกินไปแล้วคิวล้นจะไม่มี error ให้เห็นเลย
   เพราะแถวส่วนเกินไม่เคยถูก SELECT ออกมา ช่อง attempt จึงไม่ขยับ */
function hadWinCalc() {
  var box = document.getElementById("hadWinCalc");
  if (!box) return;
  var on = document.getElementById("hadWinEnabled").checked;
  var s  = document.getElementById("hadWinStart").value;
  var e  = document.getElementById("hadWinEnd").value;
  if (!on) {
    box.className = "alert alert-secondary py-2 mt-3";
    box.innerHTML = "ปิดอยู่ — ระบบส่งอัตโนมัติ<b>ตลอด 24 ชม.</b> (พฤติกรรมเดิม)";
    return;
  }
  if (!s || !e) {
    box.className = "alert alert-secondary py-2 mt-3";
    box.innerHTML = "กรอกเวลาให้ครบทั้งสองช่อง";
    return;
  }
  var sm = +s.slice(0, 2) * 60 + +s.slice(3, 5);
  var em = +e.slice(0, 2) * 60 + +e.slice(3, 5);
  var mins = (sm === em) ? 1440 : (em > sm ? em - sm : 1440 - sm + em);
  var runs = Math.floor(mins / HAD_WIN_CRON);
  var cap  = runs * HAD_WIN_LIMIT;
  var note = (sm === em) ? " <b>(= เปิดตลอด 24 ชม.)</b>" : (sm > em ? " (ข้ามเที่ยงคืน)" : "");

  var html = "ช่วงนี้ยาว <b>" + (mins / 60).toFixed(1) + " ชม.</b>" + note
           + " ≈ <b>" + runs.toLocaleString("en-US") + " รอบ</b> (รอบละ " + HAD_WIN_CRON + " นาที)"
           + " ≈ ส่งได้ <b>~" + cap.toLocaleString("en-US") + " รายการ/วัน</b>";

  if (mins < HAD_WIN_CRON) {
    box.className = "alert alert-danger py-2 mt-3";
    html += "<div class=\'mt-1\'>⚠ ช่วงสั้นกว่ารอบการทำงาน (" + HAD_WIN_CRON + " นาที) — "
          + "<b>อาจไม่มีรอบไหนตกอยู่ในช่วงนี้เลย = ไม่ส่งอะไรทั้งวัน</b></div>";
  } else if (mins < 30) {
    box.className = "alert alert-warning py-2 mt-3";
    html += "<div class=\'mt-1\'>⚠ ช่วงสั้นกว่า 30 นาที — ถ้ามีคิวเกิน " + cap.toLocaleString("en-US")
          + " รายการ/วัน คิวจะสะสมข้ามวันไปเรื่อยๆ และ<b>จะไม่เห็น error</b> "
          + "เพราะรายการส่วนเกินไม่เคยถูกหยิบมาส่ง</div>";
  } else {
    box.className = "alert alert-success py-2 mt-3";
  }
  box.innerHTML = html;
}
["hadWinStart", "hadWinEnd", "hadWinEnabled"].forEach(function (id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.addEventListener("input", hadWinCalc);
  el.addEventListener("change", hadWinCalc);
});
document.getElementById("hadWindowModal").addEventListener("shown.bs.modal", hadWinCalc);
hadWinCalc();

function hadSync() {
  var btn = document.getElementById("hadSyncBtn"), ic = document.getElementById("hadSyncIcon"),
      tx = document.getElementById("hadSyncText"), out = document.getElementById("hadSyncResult");
  btn.disabled = true; ic.classList.add("msi-spin"); tx.textContent = "กำลัง Sync..."; out.style.display = "none";
  var fd = new FormData();
  fd.append("action", "import_hosxp");
  fd.append("start", document.getElementById("hadSyncStart").value);
  fd.append("end",   document.getElementById("hadSyncEnd").value);
  fetch("had_queue_action.php", { method: "POST", body: fd })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      out.style.display = "block"; out.className = j.ok ? "ok" : "err"; out.textContent = j.msg;
      if (j.ok) setTimeout(function () {
        window.location.href = "had_queue_ui.php?msg=imported&imported=" + (j.imported || 0) + "&new=" + (j.new || 0);
      }, 1200);
    })
    .catch(function (e) { out.style.display = "block"; out.className = "err"; out.textContent = "เชื่อมต่อไม่สำเร็จ: " + e; })
    .finally(function () { btn.disabled = false; ic.classList.remove("msi-spin"); tx.textContent = "เริ่ม Sync"; });
}
</script>';
require_once __DIR__ . '/partials/footer.php';
