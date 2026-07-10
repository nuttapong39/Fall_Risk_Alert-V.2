<?php
/**
 * covid.php — Ingest + ส่งแจ้งเตือนผู้ป่วย COVID ผ่าน MOPH Alert (หรือ email/file ตาม DRIVER)
 * ใช้ร่วมกับ queue_ui.php และ queue_action.php
 */
require_once __DIR__ . '/config.php';
if (PHP_SAPI !== 'cli') require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/covid_lib.php';
date_default_timezone_set('Asia/Bangkok');

/* ========================= Global lock (กันรันซ้อน) ========================= */
try {
  $got = (int)$dbcon->query("SELECT GET_LOCK('covid_send_lock',1)")->fetchColumn();
} catch (Throwable $e) {
  $got = 1; // ถ้าดึง lock ไม่ได้ ให้ไปต่อ (ไม่ให้งานค้าง)
}
if ($got !== 1) {
  if (PHP_SAPI !== 'cli') echo "<pre>skip: another instance</pre>";
  return;
}
register_shutdown_function(function() use ($dbcon){
  try { $dbcon->query("DO RELEASE_LOCK('covid_send_lock')"); } catch(Throwable $e){}
});

/* ========================= Params ========================= */
function readParam($key, $default=null) {
  if (PHP_SAPI === 'cli') {
    static $args; if ($args===null) $args = getopt('', ['start::','end::','hosp::','dry-run']);
    if ($key==='dry-run') return array_key_exists('dry-run', $args);
    return $args[$key] ?? $default;
  } else {
    if ($key==='dry-run') return isset($_GET['dry-run']);
    return $_GET[$key] ?? $default;
  }
}
$lookbackDays = defined('DEFAULT_LOOKBACK_DAYS') ? (int)DEFAULT_LOOKBACK_DAYS : 7;
$start  = readParam('start', date('Y-m-d', strtotime('-'.$lookbackDays.' days')));
$end    = readParam('end',   date('Y-m-d'));
$hosp   = trim(readParam('hosp', defined('DEFAULT_HOSP_CODE') ? DEFAULT_HOSP_CODE : ''));
$dryRun = readParam('dry-run', false);

function logln($msg){ if (PHP_SAPI === 'cli') echo '['.date('Y-m-d H:i:s')."] $msg\n"; }

/* ========================= STEP 1: Ingest เข้าคิว ========================= */
/* ใช้ DATE(ov.vstdate) เพื่อกันปัญหา DATETIME ปลายวันไม่ถูกนับ */
require_once __DIR__ . '/sources/covid_source.php';
$newRows = covid_source_rows($start, $end);
logln("Ingest: found ".count($newRows)." new rows.");

if (!$dryRun && $newRows) {
  $ins = $dbcon->prepare("
    INSERT INTO covid_queue
      (lab_order_number, hn, fullname, age, cid, informaddr, hometel, vstdate, doctor, pdx, lab_order_result, status, attempt, created_at)
    VALUES
      (:lab_order_number, :hn, :fullname, :age, :cid, :informaddr, :hometel, :vstdate, :doctor, :pdx, :lab_order_result, 0, 0, NOW())
    ON DUPLICATE KEY UPDATE lab_order_number = lab_order_number
  ");
  foreach ($newRows as $r) {
    $ins->execute([
      ':lab_order_number' => $r['lab_order_number'],
      ':hn'               => $r['hn'],
      ':fullname'         => $r['fullname'],
      ':age'              => (int)$r['age'],
      ':cid'              => $r['cid'],
      ':informaddr'       => $r['informaddr'],
      ':hometel'          => $r['hometel'],
      ':vstdate'          => $r['vstdate'],
      ':doctor'           => $r['doctor'],
      ':pdx'              => $r['pdx'],
      ':lab_order_result' => $r['lab_order_result'],
    ]);
  }
}

/* ========================= STEP 2: ส่ง + อัปเดตสถานะ ========================= */
$getQ = $dbcon->prepare("
  SELECT * FROM covid_queue
  WHERE status = 0
  ORDER BY created_at ASC
  LIMIT 50
");
$getQ->execute();
$queue = $getQ->fetchAll();
logln("Send: to process ".count($queue)." rows.");

$updOk = $dbcon->prepare("
  UPDATE covid_queue
  SET status=1,
      sent_at=NOW(),
      last_attempt_at=NOW(),
      attempt=attempt+1,
      last_error=NULL,
      out_ref=:ref,
      line_message_id=:ref
  WHERE id=:id
");
$updErr = $dbcon->prepare("
  UPDATE covid_queue
  SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:err
  WHERE id=:id
");

foreach ($queue as $row) {
  if ($dryRun) { logln("DRY-RUN: would send id={$row['id']} hn={$row['hn']}"); continue; }

  if (defined('DELIVERY_DRIVER') && DELIVERY_DRIVER === 'email') {
    [$ok, $ref, $err] = send_via_email($row);
  } elseif (defined('DELIVERY_DRIVER') && DELIVERY_DRIVER === 'file') {
    [$ok, $ref, $err] = send_via_file($row);
  } else { // default: moph_alert
    [$ok, $ref, $err] = covid_send_via_moph_alert($row);
  }

  if ($ok) {
    $updOk->execute([':id' => $row['id'], ':ref' => $ref]);
    logln("OK id={$row['id']} ref=".($ref ?? '-'));
  } else {
    $updErr->execute([':id' => $row['id'], ':err' => $err]);
    logln("FAIL id={$row['id']} err=$err");
  }
}

if (PHP_SAPI !== 'cli') {
  echo "<pre>Done: start={$start} end={$end} hosp={$hosp} dryRun=".($dryRun?'1':'0')."</pre>";
}
