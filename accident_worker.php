<?php
/**
 * accident_worker.php — Automation for Accident alerts (สิทธิ์ พ.ร.บ./ประกันสังคมต่างจังหวัด)
 * STEP 1: Ingest → accident_queue  (pttype 33/35/36/39 จาก ipt)
 * STEP 2: ส่ง Flex message ไป MOPH Alert + อัปเดตสถานะคิว (มีคูลดาวน์/จำกัดครั้ง)
 *
 * ใช้ Flow เดียวกับ fracture.php — รันได้จาก run_accident.bat / Task Scheduler / browser
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_accident.php';  // โหลดไลบรารี Flex กลาง (ต้องมาก่อนฟังก์ชันในไฟล์นี้)
// require_once __DIR__ . '/auth_guard.php';  // ปิดไว้ — ให้รันได้จาก bat/cron โดยไม่ต้อง login
date_default_timezone_set('Asia/Bangkok');

// Worker ต้องรันจนเสร็จ ไม่มี time limit
@set_time_limit(0);
@ini_set('max_execution_time', '0');

/* ==============================
 *  CONFIG เฉพาะ Accident
 * ============================== */
if (!defined('MOPH_API_URL'))            define('MOPH_API_URL',            'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT'))            define('MOPH_TIMEOUT',            20);   // วินาที
if (!defined('MOPH_CONNECT_TIMEOUT'))    define('MOPH_CONNECT_TIMEOUT',    8);    // TCP connect timeout

// ใช้ key จาก config.php เป็น default
if (!defined('ACCIDENT_CLIENT_KEY')) define('ACCIDENT_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('ACCIDENT_SECRET_KEY')) define('ACCIDENT_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

// ACC_TITLE / ACC_SUBTITLE / ACC_SYSTEM_NAME / FALL_HEADER_URL — define ไว้แล้วใน flex_accident.php

// default ช่วงวันหากไม่ส่งพารามิเตอร์
if (!defined('DEFAULT_LOOKBACK_DAYS')) define('DEFAULT_LOOKBACK_DAYS', 7);

// Resend policy
if (!defined('ACCIDENT_RESEND_COOLDOWN_MIN')) define('ACCIDENT_RESEND_COOLDOWN_MIN', 1);   // เว้นอย่างน้อย 1 นาที
if (!defined('ACCIDENT_MAX_ATTEMPTS'))        define('ACCIDENT_MAX_ATTEMPTS', 8);           // ส่งซ้ำได้สูงสุดกี่ครั้ง
if (!defined('ACCIDENT_BATCH_LIMIT'))         define('ACCIDENT_BATCH_LIMIT', 50);           // ดึงครั้งละกี่เรคคอร์ด

/* ==============================
 *  LOG FILES
 * ============================== */
$LOG_DIR  = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . DIRECTORY_SEPARATOR . 'moph_alert_accident.log';
$RUN_LOG  = $LOG_DIR . DIRECTORY_SEPARATOR . 'accident_task_run.log';

function runlog($t)  { global $RUN_LOG;  @file_put_contents($RUN_LOG,  '['.date('Y-m-d H:i:s')."] $t\n", FILE_APPEND); }
function logln($msg) { if (PHP_SAPI === 'cli') echo '['.date('Y-m-d H:i:s')."] $msg\n"; }

function log_moph_response($row, $code, $resp, $err = null) {
  global $LOG_FILE;
  $line = sprintf(
    "[%s] id=%s an=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'),
    $row['id'] ?? '-',
    $row['an']  ?? '-',
    $code,
    $err ?: '-',
    mb_substr($resp ?? '', 0, 2000)
  );
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
  if (PHP_SAPI === 'cli') echo $line;
}

// to_utf8(), row_to_utf8(), acc_thai_date(), buildAccidentPayload(), extract_moph_message_id()
// — ถูก define ไว้ใน flex_accident.php แล้ว ไม่ต้องประกาศซ้ำ

/* ==============================
 *  Utilities
 * ============================== */
function readParam($key, $default = null) {
  if (PHP_SAPI === 'cli') {
    static $args; if ($args === null) $args = getopt('', ['start::', 'end::', 'dry-run']);
    if ($key === 'dry-run') return array_key_exists('dry-run', $args);
    return $args[$key] ?? $default;
  } else {
    if ($key === 'dry-run') return isset($_GET['dry-run']);
    return $_GET[$key] ?? $default;
  }
}

function normalize_date_ymd($d, $fallback) {
  if (!is_string($d) || $d === '') return $fallback;
  if (preg_match('/^\s*(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\s*$/', $d, $m)) {
    $y = (int)$m[1]; $mo = (int)$m[2]; $da = (int)$m[3];
    if ($y > 2400) $y -= 543;
    if ($y < 1900 || $y > 2100 || $mo < 1 || $mo > 12 || $da < 1 || $da > 31) return $fallback;
    return sprintf('%04d-%02d-%02d', $y, $mo, $da);
  }
  return $fallback;
}

/* ==============================
 *  Sender — เหมือน fracture.php โครงสร้าง
 * ============================== */
function send_via_moph_alert_accident(array $row): array {
  $row     = row_to_utf8($row);
  $payload = buildAccidentPayload($row);
  $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false) {
    $jsonErr = json_last_error_msg();
    log_moph_response($row, 0, null, "JSON_ENCODE_FAIL: " . $jsonErr);
    return [false, null, "JSON encode failed: " . $jsonErr];
  }

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => MOPH_API_URL,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => MOPH_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => MOPH_CONNECT_TIMEOUT,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
      'client-key: ' . ACCIDENT_CLIENT_KEY,
      'secret-key: ' . ACCIDENT_SECRET_KEY,
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json',
      'Expect:',
      'Connection: close',
    ],
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  log_moph_response($row, $code, $resp, $err ?: null);
  if ($err) return [false, null, "CURL: $err"];

  $json      = json_decode($resp, true);
  $mid       = extract_moph_message_id($json);
  $apiStatus = is_array($json) && array_key_exists('status',  $json) ? $json['status']  : null;
  $apiMsg    = is_array($json) && array_key_exists('message', $json) ? (string)$json['message'] : null;

  $looksSuccess = ($mid)
    || (is_numeric($apiStatus) && (int)$apiStatus === 200)
    || ($apiMsg && preg_match('/succ(e|)ss/i', $apiMsg));

  if (($code >= 200 && $code < 300) && $looksSuccess) {
    $ref = $mid ?: ($apiStatus ? "status:$apiStatus" : 'HTTP' . $code);
    return [true, $ref, null];
  }

  $detail = "HTTP=$code";
  if ($apiStatus !== null) $detail .= " status=$apiStatus";
  if ($apiMsg)             $detail .= " msg=$apiMsg";
  return [false, null, "MOPH error: $detail"];
}

/* ==============================
 *  รับพารามิเตอร์
 * ============================== */
$start  = readParam('start', date('Y-m-d', strtotime('-' . DEFAULT_LOOKBACK_DAYS . ' days')));
$end    = readParam('end',   date('Y-m-d'));
$dryRun = readParam('dry-run', false);

$today        = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-' . DEFAULT_LOOKBACK_DAYS . ' days'));
$start  = normalize_date_ymd($start, $defaultStart);
$end    = normalize_date_ymd($end,   $today);
if (strtotime($start) === false || strtotime($end) === false || $start > $end) {
  $start = $defaultStart; $end = $today;
}

logln("Effective range: $start -> $end" . ($dryRun ? ' [DRY-RUN]' : ''));
runlog("START range=$start~$end dryRun=" . ($dryRun ? '1' : '0'));

/* ==============================
 *  STEP 1: Ingest เข้าคิว
 *  เกณฑ์: ipt.regdate ในช่วงวัน + pttype IN (33, 35, 36, 39)
 *  ข้ามรายการที่มีอยู่ใน accident_queue แล้ว (LEFT JOIN NULL)
 * ============================== */
require_once __DIR__ . '/sources/accident_source.php';
$newRows = accident_source_ipt_rows($start, $end);
logln("Ingest: found " . count($newRows) . " new rows.");
runlog("Ingest: " . count($newRows) . " new rows");

if (!$dryRun && $newRows) {
  $ins = $dbcon->prepare("
    INSERT INTO accident_queue
      (an, hn, fullname, regdate, regtime, pttype, pttname, status, attempt, created_at)
    VALUES
      (:an, :hn, :fullname, :regdate, :regtime, :pttype, :pttname, 0, 0, NOW())
    ON DUPLICATE KEY UPDATE an = an
  ");
  foreach ($newRows as $r) {
    $ins->execute([
      ':an'       => $r['an'],
      ':hn'       => $r['hn'],
      ':fullname' => $r['fullname'],
      ':regdate'  => $r['regdate'],
      ':regtime'  => $r['regtime'],
      ':pttype'   => $r['pttype'],
      ':pttname'  => $r['pttname'],
    ]);
  }
}

/* ==============================
 *  STEP 2: ส่ง + อัปเดตสถานะ (มีคูลดาวน์/จำกัดครั้ง)
 * ============================== */
$cooldown = (int)ACCIDENT_RESEND_COOLDOWN_MIN;
$maxTry   = (int)ACCIDENT_MAX_ATTEMPTS;
$limit    = (int)ACCIDENT_BATCH_LIMIT;

$getQ = $dbcon->prepare("
  SELECT *
  FROM accident_queue
  WHERE status = 0
    AND (last_attempt_at IS NULL OR TIMESTAMPDIFF(MINUTE, last_attempt_at, NOW()) >= :cd)
    AND attempt < :maxtry
  ORDER BY
    (last_attempt_at IS NULL) DESC,
    last_attempt_at ASC,
    created_at ASC
  LIMIT $limit
");
$getQ->execute([':cd' => $cooldown, ':maxtry' => $maxTry]);
$queue = $getQ->fetchAll();

logln("Send: to process " . count($queue) . " rows (cooldown={$cooldown}m, maxTry={$maxTry}).");
runlog("Send: " . count($queue) . " rows to process");

$updOk = $dbcon->prepare("
  UPDATE accident_queue
  SET status          = 1,
      sent_at         = NOW(),
      last_attempt_at = NOW(),
      attempt         = attempt + 1,
      last_error      = NULL,
      out_ref         = :ref,
      line_message_id = :ref
  WHERE id = :id
");
$updErr = $dbcon->prepare("
  UPDATE accident_queue
  SET last_attempt_at = NOW(),
      attempt         = attempt + 1,
      last_error      = :err
  WHERE id = :id
");

foreach ($queue as $row) {
  if ($dryRun) {
    logln("DRY-RUN: would send id={$row['id']} an={$row['an']}");
    continue;
  }
  usleep(random_int(10, 80) * 1000);   // กระจาย request เล็กน้อย

  [$ok, $ref, $err] = send_via_moph_alert_accident($row);

  if ($ok) {
    $updOk->execute([':id' => $row['id'], ':ref' => $ref]);
    require_once __DIR__ . '/telegram_lib.php';
    telegram_mirror('accident', '🚑 แจ้งเตือนผู้ป่วยอุบัติเหตุ (พ.ร.บ.)', $row);
    logln("OK   id={$row['id']} ref=" . ($ref ?? '-'));
    runlog("OK id={$row['id']} an={$row['an']} ref=" . ($ref ?? '-'));
  } else {
    $updErr->execute([':id' => $row['id'], ':err' => $err]);
    logln("FAIL id={$row['id']} err=$err");
    runlog("FAIL id={$row['id']} an={$row['an']} err=$err");
  }
}

runlog("DONE range=$start~$end");
if (PHP_SAPI !== 'cli') {
  echo "<pre>Done: start={$start} end={$end} dryRun=" . ($dryRun ? '1' : '0') . "</pre>";
}
