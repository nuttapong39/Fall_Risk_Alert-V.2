<?php
/**
 * sexual.php — คิวแจ้งเตือนผู้ถูกกระทำความรุนแรง / ข่มขืน (Queue mode)
 *  - อ่านข้อมูลจาก sexual_alert_queue (local queue)
 *  - Sync จาก HOSxP ผ่านปุ่ม Modal → sexual_action.php (import_hosxp)
 *  - ส่ง LINE Flex ทีละราย หรือ Bulk (send_now) ผ่าน sexual_action.php
 *  - Bulk: requeue, clear_error
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/flex_sexual.php';
date_default_timezone_set('Asia/Bangkok');

/* ── UI Action Token ─────────────────────────────────────────── */
if (!defined('SEXUAL_UI_TOKEN')) {
  define('SEXUAL_UI_TOKEN', hash('sha256', __DIR__ . '/sexual.php' . php_uname() . date('Y-m-d')));
}

/* ── Filters ─────────────────────────────────────────────────── */
$DEFAULT_START = date('Y-m-d', strtotime('-90 days'));
$start  = (isset($_GET['start']) && $_GET['start']) ? $_GET['start'] : $DEFAULT_START;
$end    = (isset($_GET['end'])   && $_GET['end'])   ? $_GET['end']   : date('Y-m-d');
$status = isset($_GET['status'])                    ? $_GET['status'] : 'all';

$w = ["lab_date BETWEEN :s AND :e"];
$p = [':s' => $start, ':e' => $end];
if ($status === '0') { $w[] = 'status = 0'; }
if ($status === '1') { $w[] = 'status = 1'; }

/* ── Query local queue ───────────────────────────────────────── */
$rows       = [];
$queryError = null;
try {
  $sql = "SELECT id, vn, hn, fullname, cid, age, sex, hometel,
                 lab_date, lab_time, lab_items_name_ref, lab_order_result,
                 lab_order_number, status, attempt, last_attempt_at,
                 last_error, out_ref, line_message_id, sent_at, created_at
          FROM   sexual_alert_queue
          WHERE  " . implode(' AND ', $w) . "
          ORDER  BY lab_date DESC, id DESC
          LIMIT  2000";
  $stmt = $dbcon->prepare($sql);
  $stmt->execute($p);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $queryError = $e->getMessage();
}

/* ── KPI ─────────────────────────────────────────────────────── */
$today = date('Y-m-d');
$kpi   = ['total' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0, 'today' => 0];
foreach ($rows as $r) {
  $kpi['total']++;
  if ((int)$r['status'] === 1) $kpi['sent']++;
  else                         $kpi['pending']++;
  if (!empty($r['last_error']))                              $kpi['failed']++;
  if (substr((string)($r['lab_date'] ?? ''), 0, 10) === $today) $kpi['today']++;
}

/* ── Flash message ───────────────────────────────────────────── */
$flash = '';
if (isset($_GET['msg'])) {
  $ok  = (int)($_GET['ok']       ?? 0);
  $fa  = (int)($_GET['fail']     ?? 0);
  $aff = (int)($_GET['affected'] ?? 0);
  $imp = (int)($_GET['imported'] ?? 0);
  $nw  = (int)($_GET['new']      ?? 0);
  $flash = match($_GET['msg']) {
    'sendnow'    => "success:ส่งสำเร็จ {$ok} รายการ" . ($fa > 0 ? " / ล้มเหลว {$fa} รายการ" : ''),
    'requeued'   => "info:Requeue สำเร็จ {$aff} รายการ",
    'cleared'    => "info:ล้าง error สำเร็จ {$aff} รายการ",
    'imported'   => "success:Sync จาก HOSxP สำเร็จ {$imp} รายการ (ใหม่ {$nw} รายการ)",
    'no_ids'     => "warning:ยังไม่ได้เลือกรายการ",
    'bad_action' => "danger:คำสั่งไม่ถูกต้อง",
    'err'        => "danger:เกิดข้อผิดพลาด: " . htmlspecialchars($_GET['detail'] ?? ''),
    default      => '',
  };
}

/* ── UTF-8 helper (guarded) ──────────────────────────────────── */
if (!function_exists('to_utf8')) {
  function to_utf8($s) {
    if (!is_string($s)) return $s;
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    foreach (['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc) {
      $t = @iconv($enc, 'UTF-8//IGNORE', $s); if ($t !== false && $t !== '') return $t;
      $t = @mb_convert_encoding($s, 'UTF-8', $enc); if ($t !== false && $t !== '') return $t;
    }
    return @iconv('UTF-8', 'UTF-8//IGNORE', $s);
  }
}

/* ── Page setup ──────────────────────────────────────────────── */
$PAGE_TITLE = 'คิวแจ้งเตือน ผู้ถูกกระทำความรุนแรง';
$PAGE_KEY   = 'sexual';
$EXTRA_HEAD = '
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
<style>
  /* ── Layout ── */
  .filter-card { padding:1rem 1.15rem; margin-bottom:1rem }
  .filter-card label { font-size:.82rem; color:#64748b; margin-bottom:.25rem }
  .table thead th { white-space:nowrap; font-size:.82rem; color:#475569;
                    background:#f8fafc; border-bottom:1px solid #e2e8f0 }
  .table td { font-size:.88rem; vertical-align:middle }
  .table td.small-text { font-size:.8rem; color:#6b7280 }
  .dt-buttons .btn { border-radius:.5rem }

  /* ── Sticky action bar ── */
  .action-bar {
    position:sticky; bottom:1rem; z-index:50;
    background:#fff; border:1px solid #e2e8f0; border-radius:14px;
    padding:.75rem 1rem; box-shadow:0 10px 25px rgba(15,23,42,.12);
    display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;
  }
  .action-bar .selected-count { font-weight:600; color:#0f172a; margin-right:auto }
  .form-check-input:focus { box-shadow:0 0 0 .2rem rgba(219,39,119,.2) }

  /* ── Send button (per row) ── */
  .btn-send-row {
    background:linear-gradient(135deg,#db2777,#be185d);
    border:none; color:#fff; border-radius:8px;
    font-size:.8rem; padding:.28rem .7rem;
    display:inline-flex; align-items:center; gap:.3rem;
    transition:opacity .15s,transform .1s; white-space:nowrap;
    cursor:pointer;
  }
  .btn-send-row:hover   { opacity:.88; color:#fff }
  .btn-send-row:active  { transform:scale(.97) }
  .btn-send-row:disabled {
    background:#d1fae5; color:#065f46;
    border:1px solid #a7f3d0; cursor:not-allowed; opacity:1;
  }

  /* ── Sync modal result ── */
  #sxSyncResult { display:none; border-radius:8px; padding:10px 14px;
                  font-size:.85rem; font-weight:500; margin-top:10px }
  #sxSyncResult.ok  { background:#dcfce7; color:#166534 }
  #sxSyncResult.err { background:#fee2e2; color:#991b1b }

  /* ── Query error ── */
  .setup-alert {
    background:linear-gradient(135deg,#be185d,#9f1239);
    color:#fff; border-radius:14px; padding:20px 24px;
    display:flex; align-items:flex-start; gap:16px;
  }
  .setup-alert-icon { font-size:2rem; flex-shrink:0 }
  .setup-alert h5   { font-size:1rem; font-weight:700; margin:0 0 4px }
  .setup-alert p    { font-size:.83rem; margin:0; opacity:.9 }
  .setup-alert code { background:rgba(0,0,0,.25); padding:2px 6px; border-radius:4px }
</style>
';

require_once __DIR__ . '/partials/header.php';
?>

<?php if ($flash):
  [$ft, $fm] = explode(':', $flash, 2) + ['info', ''];
  $ftMap  = ['success'=>'alert-success','info'=>'alert-info','warning'=>'alert-warning','danger'=>'alert-danger'];
  $ftIcon = ['success'=>'check_circle','info'=>'info','warning'=>'warning','danger'=>'error'];
?>
<div class="alert <?= $ftMap[$ft] ?? 'alert-info' ?> d-flex align-items-center gap-2 mb-3"
     style="border-radius:10px; font-size:.9rem">
  <span class="msi"><?= $ftIcon[$ft] ?? 'info' ?></span> <?= $fm ?>
</div>
<?php endif; ?>

<!-- ── Page header ───────────────────────────────────────────── -->
<div class="page-header">
  <h1>
    <span class="msi me-2" style="color:#db2777">shield_person</span>
    <?= htmlspecialchars($PAGE_TITLE) ?>
  </h1>
  <div class="d-flex gap-2 flex-wrap">
    <button type="button" class="btn btn-sm"
            style="background:linear-gradient(135deg,#db2777,#9d174d);color:#fff;border:none;border-radius:8px"
            data-bs-toggle="modal" data-bs-target="#sxSyncModal">
      <span class="msi me-1">sync</span> Sync จาก HOSxP
    </button>
    <span class="badge rounded-pill align-self-center"
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
<!-- ── ตาราง sexual_alert_queue ยังไม่ถูกสร้าง ── -->
<div class="setup-alert mb-4">
  <div class="setup-alert-icon"><span class="msi">error_outline</span></div>
  <div>
    <h5>ไม่สามารถดึงข้อมูลจากคิว</h5>
    <p>ตรวจสอบว่าสร้างตาราง <code>sexual_alert_queue</code> แล้วหรือไม่</p>
    <p class="mt-1">รัน SQL ได้จากไฟล์ <code>sexual_alert_queue.sql</code>
       หรือใน <a href="db_config_admin.php" class="text-white fw-bold">ตั้งค่าฐานข้อมูล</a></p>
    <p class="mt-2"><code><?= htmlspecialchars($queryError) ?></code></p>
  </div>
</div>
<?php else: ?>

<!-- ── KPI Summary ────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:linear-gradient(135deg,#db2777,#9d174d)">
        <span class="msi">shield_person</span>
      </div>
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
      <div class="kpi-icon bg-green"><span class="msi">check_circle</span></div>
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

<!-- ── Filter ─────────────────────────────────────────────────── -->
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
    <div class="col-sm-6 col-md-2">
      <label for="f-status">สถานะ</label>
      <select id="f-status" class="form-select form-select-sm" name="status">
        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
        <option value="0"   <?= $status === '0'   ? 'selected' : '' ?>>ค้างส่ง</option>
        <option value="1"   <?= $status === '1'   ? 'selected' : '' ?>>ส่งแล้ว</option>
      </select>
    </div>
    <div class="col-sm-6 col-md-4 d-flex gap-2 align-items-end">
      <button class="btn btn-sm flex-grow-1"
              style="background:linear-gradient(135deg,#db2777,#9d174d);color:#fff;border:none;border-radius:8px">
        <span class="msi me-1">search</span> ค้นหา
      </button>
      <a class="btn btn-sm btn-outline-secondary" href="sexual.php" title="รีเซ็ตตัวกรอง">
        <span class="msi">undo</span>
      </a>
      <small class="text-muted align-self-center ms-1">
        <?= number_format(count($rows)) ?> รายการ
      </small>
    </div>
  </form>
</div>

<!-- ── Table + Bulk Actions ───────────────────────────────────── -->
<form method="post" action="sexual_action.php" id="sxActionForm">
  <input type="hidden" name="token" value="<?= htmlspecialchars(SEXUAL_UI_TOKEN) ?>">

  <div class="card p-3 mb-3">
    <div class="table-responsive">
      <table id="tblSexual" class="table table-hover align-middle nowrap" style="width:100%">
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
            <th>เบอร์โทร</th>
            <th>ผลตรวจ</th>
            <th>รายการ LAB</th>
            <th>วันที่ LAB</th>
            <th>Attempt</th>
            <th>Last Attempt</th>
            <th>Error</th>
            <th>Created</th>
            <th>Sent At</th>
            <th>แจ้งเตือน</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $r      = array_map(fn($v) => is_string($v) ? to_utf8($v) : $v, $r);
          $isDone = (int)$r['status'] === 1;
          $hasErr = !empty($r['last_error']);
          if ($hasErr && !$isDone) {
            $badge = '<span class="status-badge status-fail"><span class="msi">close</span> ล้มเหลว</span>';
          } elseif ($isDone) {
            $badge = '<span class="status-badge status-ok"><span class="msi">check</span> ส่งแล้ว</span>';
          } else {
            $badge = '<span class="status-badge status-pending"><span class="msi">schedule</span> ค้างส่ง</span>';
          }
          $isToday = (substr((string)($r['lab_date'] ?? ''), 0, 10) === $today);
          $sex     = $r['sex'] ?? '';
        ?>
          <tr>
            <td><input type="checkbox" class="form-check-input chk" name="ids[]" value="<?= $r['id'] ?>"></td>
            <td class="text-center"><small class="text-muted"><?= $r['id'] ?></small></td>
            <td><?= $badge ?></td>
            <td><strong><?= htmlspecialchars($r['hn'] ?? '') ?></strong></td>
            <td><?= htmlspecialchars($r['fullname'] ?? '') ?></td>
            <td class="text-center"><?= $r['age'] ? $r['age'] . ' ปี' : '-' ?></td>
            <td class="text-center">
              <?php if ($sex): ?>
                <span class="badge rounded-pill"
                      style="background:<?= $sex === 'ชาย' ? '#dbeafe' : '#fce7f3' ?>;
                             color:<?= $sex === 'ชาย' ? '#1e40af' : '#9d174d' ?>;
                             font-size:.76rem">
                  <?= htmlspecialchars($sex) ?>
                </span>
              <?php else: echo '-'; endif; ?>
            </td>
            <td style="font-size:.85rem"><?= htmlspecialchars($r['hometel'] ?? '') ?: '-' ?></td>
            <td class="text-center">
              <span class="badge rounded-pill"
                    style="background:#fef2f2;color:#991b1b;font-size:.8rem;padding:.22rem .6rem;font-weight:600">
                <?= htmlspecialchars($r['lab_order_result'] ?? '') ?: '-' ?>
              </span>
            </td>
            <td style="font-size:.82rem;max-width:160px;white-space:normal">
              <?= htmlspecialchars($r['lab_items_name_ref'] ?? '') ?>
            </td>
            <td>
              <span style="font-size:.85rem;<?= $isToday ? 'color:#dc2626;font-weight:600' : '' ?>">
                <?= htmlspecialchars($r['lab_date'] ?? '') ?>
              </span>
              <?php if ($isToday): ?>
                <span class="badge rounded-pill ms-1"
                      style="background:#fef2f2;color:#dc2626;font-size:.7rem">วันนี้</span>
              <?php endif; ?>
            </td>
            <td class="text-center"><?= (int)$r['attempt'] ?></td>
            <td class="small-text"><?= htmlspecialchars(substr((string)($r['last_attempt_at'] ?? ''), 0, 16)) ?></td>
            <td class="small-text text-danger" style="max-width:140px;white-space:normal">
              <?= htmlspecialchars($r['last_error'] ?? '') ?>
            </td>
            <td class="small-text"><?= htmlspecialchars(substr((string)($r['created_at'] ?? ''), 0, 16)) ?></td>
            <td class="small-text"><?= htmlspecialchars(substr((string)($r['sent_at'] ?? ''), 0, 16)) ?></td>
            <td>
              <?php if ($isDone): ?>
                <button type="button" class="btn-send-row" disabled>
                  <span class="msi">check_circle</span> ส่งแล้ว
                </button>
              <?php else: ?>
                <button type="button" class="btn-send-row"
                        data-id="<?= (int)$r['id'] ?>"
                        data-hn="<?= htmlspecialchars($r['hn'] ?? '') ?>"
                        data-name="<?= htmlspecialchars($r['fullname'] ?? '') ?>">
                  <span class="msi">send</span> ส่งแจ้งเตือน
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Sticky action bar ─────────────────────────────────── -->
  <div class="action-bar">
    <span class="selected-count" id="selectedCount">เลือก 0 รายการ</span>
    <button type="button" class="btn btn-sm"
            style="background:linear-gradient(135deg,#db2777,#be185d);color:#fff;border:none;border-radius:8px"
            data-action="send_now" data-label="ส่งแจ้งเตือนทันที" data-confirm-icon="question">
      <span class="msi me-1">send</span> ส่งทันที
    </button>
    <button type="button" class="btn btn-warning btn-sm"
            data-action="requeue" data-label="Requeue" data-confirm-icon="warning">
      <span class="msi me-1">refresh</span> Requeue
    </button>
    <button type="button" class="btn btn-outline-secondary btn-sm"
            data-action="clear_error" data-label="ล้าง error" data-confirm-icon="warning">
      <span class="msi me-1">backspace</span> ล้าง error
    </button>
  </div>
</form>

<?php endif; ?>

<!-- ═══ Sync from HOSxP Modal ══════════════════════════════════ -->
<div class="modal fade" id="sxSyncModal" tabindex="-1" aria-labelledby="sxSyncModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:14px; overflow:hidden">
      <div class="modal-header border-0"
           style="background:linear-gradient(135deg,#db2777,#9d174d); color:#fff">
        <h5 class="modal-title" id="sxSyncModalLabel">
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
              <input type="date" id="sxSyncStart" class="form-control"
                     value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
            </div>
            <div class="col-auto d-flex align-items-center text-muted">ถึง</div>
            <div class="col">
              <input type="date" id="sxSyncEnd" class="form-control"
                     value="<?= date('Y-m-d') ?>">
            </div>
          </div>
        </div>
        <div class="p-2 rounded mb-2"
             style="background:#fdf2f8; border:1px solid #fbcfe8; font-size:.82rem; color:#9d174d">
          <span class="msi me-1">info</span>
          Query จาก <strong>lab_head + lab_order + patient</strong> ใน HOSxP
          เฉพาะ <code>lab_items_code = <?= htmlspecialchars(LAB_CODE_SEXUAL) ?></code>
          แล้ว Upsert เข้า <code>sexual_alert_queue</code>
          — ไม่ส่ง LINE ทันที ต้องกด "ส่งแจ้งเตือน" ในตารางหลัง Sync เสร็จ
        </div>
        <div id="sxSyncResult"></div>
      </div>
      <div class="modal-footer border-0" style="padding-top:0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-sm px-4" id="sxSyncBtn"
                style="background:linear-gradient(135deg,#db2777,#9d174d);color:#fff;border:none;border-radius:8px"
                onclick="doSxSync()">
          <span class="msi me-1" id="sxSyncIcon">sync</span>
          <span id="sxSyncBtnText">Sync ข้อมูล</span>
        </button>
      </div>
    </div>
  </div>
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
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.colVis.min.js"></script>
<script>
$(function(){
  /* ── DataTable ── */
  const table = $("#tblSexual").DataTable({
    responsive: true,
    autoWidth:  false,
    pageLength: 25,
    order: [[10, "desc"]],
    dom: "<\"row mb-2\"<\"col-sm-4\"l><\"col-sm-4 text-center\"B><\"col-sm-4\"f>>tip",
    buttons: [
      { extend:"excel", text:"<span class=\"msi me-1\">table_view<\/span> Excel",
        className:"btn btn-sm btn-outline-success",
        title:"sexual_alert_" + new Date().toLocaleDateString("th-TH") },
      { extend:"print", text:"<span class=\"msi me-1\">print<\/span> พิมพ์",
        className:"btn btn-sm btn-outline-secondary" },
      { extend:"colvis", text:"<span class=\"msi\">view_column<\/span> คอลัมน์",
        className:"btn btn-sm btn-outline-secondary", columns:":not(:first-child)" }
    ],
    language: {
      search:"ค้นหา:", lengthMenu:"แสดง _MENU_ รายการ",
      info:"แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
      infoEmpty:"ไม่มีรายการ", zeroRecords:"ไม่พบรายการที่ตรงกับคำค้น",
      paginate:{first:"หน้าแรก",last:"หน้าสุดท้าย",next:"ถัดไป",previous:"ก่อนหน้า"}
    },
    columnDefs:[{ targets:[0,1,2,5,6,8,11], className:"text-nowrap text-center" }]
  });

  /* ── Checkbox all ── */
  const $chkAll = $("#chkAll");
  $chkAll.on("change", function(){
    $("#tblSexual tbody .chk").prop("checked", this.checked);
    updateCount();
  });
  $(document).on("change", ".chk", updateCount);
  table.on("draw", function(){ $chkAll.prop("checked", false); updateCount(); });
  function updateCount(){
    const n = $("#tblSexual tbody .chk:checked").length;
    $("#selectedCount").text("เลือก " + n + " รายการ");
  }

  /* ── Bulk actions ── */
  $("[data-action]").on("click", function(){
    const action = $(this).data("action");
    const label  = $(this).data("label");
    const icon   = $(this).data("confirm-icon") || "question";
    const n      = $("#tblSexual tbody .chk:checked").length;
    if (n === 0){
      Swal.fire({ icon:"info", title:"ยังไม่ได้เลือกรายการ",
                  text:"กรุณาติ๊กเลือกรายการในตารางก่อน" });
      return;
    }
    Swal.fire({
      icon, title:"ยืนยัน " + label + "?",
      text:"จะดำเนินการกับรายการที่เลือก " + n + " รายการ",
      showCancelButton:true, confirmButtonText:label,
      cancelButtonText:"ยกเลิก", reverseButtons:true, focusCancel:true,
      confirmButtonColor:"#db2777"
    }).then(r => {
      if (!r.isConfirmed) return;
      const $form = $("#sxActionForm");
      $form.append("<input type=\"hidden\" name=\"action\" value=\"" + action + "\">");
      $form[0].submit();
    });
  });

  /* ── Send single row ── */
  document.addEventListener("click", function(e){
    const btn = e.target.closest(".btn-send-row[data-id]");
    if (!btn || btn.disabled) return;
    const id   = btn.dataset.id;
    const hn   = btn.dataset.hn;
    const name = btn.dataset.name;

    Swal.fire({
      title:"ส่งแจ้งเตือน?",
      html:`<div class="text-start" style="font-size:.9rem">
              <div><strong>ID:</strong> ${id}</div>
              <div><strong>HN:</strong> ${hn}</div>
              <div><strong>ชื่อ:</strong> ${name}</div>
            </div>`,
      icon:"question", showCancelButton:true,
      confirmButtonText:"<span class=\"msi me-1\">send<\/span> ส่งเลย",
      cancelButtonText:"ยกเลิก",
      confirmButtonColor:"#db2777", reverseButtons:true, focusCancel:true,
    }).then(r => {
      if (!r.isConfirmed) return;
      btn.disabled = true;
      btn.innerHTML = "<span class=\"msi msi-spin\">progress_activity<\/span> กำลังส่ง…";
      btn.style.background = "#cbd5e1";

      fetch("sexual_action.php", {
        method:"POST",
        headers:{"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({ action:"send_queue_item", id })
      })
      .then(res => res.json())
      .then(json => {
        if (json.ok) {
          btn.innerHTML = "<span class=\"msi\">check_circle<\/span> ส่งแล้ว";
          btn.style.background = "#d1fae5"; btn.style.color = "#065f46";
          Swal.fire({ toast:true, position:"top-end", icon:"success", timer:3000,
            showConfirmButton:false, timerProgressBar:true,
            title:"ส่งสำเร็จ", text:"Ref: " + (json.ref ?? "-") });
        } else {
          btn.disabled = false;
          btn.innerHTML = "<span class=\"msi\">send<\/span> ส่งแจ้งเตือน";
          btn.style.background = "";
          Swal.fire({ icon:"error", title:"ส่งไม่สำเร็จ",
            text:json.msg ?? "เกิดข้อผิดพลาด", confirmButtonColor:"#db2777" });
        }
      })
      .catch(err => {
        btn.disabled = false;
        btn.innerHTML = "<span class=\"msi\">send<\/span> ส่งแจ้งเตือน";
        btn.style.background = "";
        Swal.fire({ icon:"error", title:"Network error",
          text:err.message, confirmButtonColor:"#db2777" });
      });
    });
  });
});

/* ── Sync from HOSxP ── */
function doSxSync() {
  const btn     = document.getElementById("sxSyncBtn");
  const icon    = document.getElementById("sxSyncIcon");
  const btnText = document.getElementById("sxSyncBtnText");
  const result  = document.getElementById("sxSyncResult");
  const start   = document.getElementById("sxSyncStart").value;
  const end     = document.getElementById("sxSyncEnd").value;

  btn.disabled       = true;
  icon.classList.add("msi-spin");
  btnText.textContent = "กำลัง Sync...";
  result.style.display = "none";

  const fd = new FormData();
  fd.append("action", "import_hosxp");
  fd.append("start",  start);
  fd.append("end",    end);

  fetch("sexual_action.php", { method:"POST", body:fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      icon.classList.remove("msi-spin");
      btnText.textContent  = "Sync ข้อมูล";
      result.style.display = "block";
      result.className     = data.ok ? "ok" : "err";
      result.innerHTML     = (data.ok
        ? "<span class=\"msi me-1\">check_circle<\/span>"
        : "<span class=\"msi me-1\">error<\/span>") + data.msg;
      if (data.ok) {
        setTimeout(() => {
          window.location.href = "sexual.php?msg=imported&imported="
            + (data.imported || 0) + "&new=" + (data.new || 0);
        }, 1600);
      }
    })
    .catch(err => {
      btn.disabled = false;
      icon.classList.remove("msi-spin");
      btnText.textContent  = "Sync ข้อมูล";
      result.style.display = "block";
      result.className     = "err";
      result.innerHTML     = "<span class=\"msi me-1\">error<\/span>เกิดข้อผิดพลาด: " + err;
    });
}
</script>
';
require_once __DIR__ . '/partials/footer.php';
?>
