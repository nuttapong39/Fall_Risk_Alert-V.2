<?php
/**
 * dengue_ingest.php — CLI worker สำหรับ Task Scheduler
 * ─────────────────────────────────────────────────────────────────────────────
 * ต้องรันผ่าน PHP CLI เท่านั้น (ไม่ใช่ผ่านเว็บ)
 *
 * Usage:
 *   php dengue_ingest.php                        — ดึง + ส่ง 7 วันล่าสุด (default)
 *   php dengue_ingest.php dryrun                 — แสดงผลเฉพาะ ไม่ส่งจริง
 *   php dengue_ingest.php start 2025-06-01       — backfill ตั้งแต่วันที่ระบุ
 *   php dengue_ingest.php start 2025-06-01 end 2025-12-31
 */

/* ── CLI guard ─────────────────────────────────────────────────── */
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("dengue_ingest.php must be run from CLI only.\n");
}

define('APP_DIR', __DIR__);
date_default_timezone_set('Asia/Bangkok');
set_time_limit(300);


if (!defined('MOPH_API_URL'))       define('MOPH_API_URL',       'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT'))       define('MOPH_TIMEOUT',       30);

/* ── Parse CLI args ────────────────────────────────────────────── */
$args  = array_slice($argv ?? [], 1);
$isDry = in_array('dryrun', $args, true);
$start = date('Y-m-d', strtotime('-7 days'));
$end   = date('Y-m-d');

for ($i = 0; $i < count($args); $i++) {
  if ($args[$i] === 'start' && isset($args[$i + 1])) $start = $args[$i + 1];
  if ($args[$i] === 'end'   && isset($args[$i + 1])) $end   = $args[$i + 1];
}

/* ── Bootstrap ─────────────────────────────────────────────────── */
require_once APP_DIR . '/config.php';
require_once APP_DIR . '/flex_disease.php';

// key ต้อง define หลัง config.php (moph_keys_loader โหลด MOPH_CLIENT_KEY/SECRET default ก่อน)
// ไม่งั้น fallback ได้ค่าว่าง → MOPH ตอบ 401 Unauthorized
if (!defined('DENGUE_CLIENT_KEY'))  define('DENGUE_CLIENT_KEY',  defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('DENGUE_SECRET_KEY'))  define('DENGUE_SECRET_KEY',  defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

/* ── Logging ───────────────────────────────────────────────────── */
$LOG_DIR  = APP_DIR . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . '/dengue_task_run.log';
$MOPH_LOG = $LOG_DIR . '/moph_alert_dengue.log';

function dng_log(string $msg): void {
  global $LOG_FILE;
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
  echo $line;
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

function dng_moph_log(array $row, int $code, ?string $resp, ?string $err = null): void {
  global $MOPH_LOG;
  $line = sprintf("[%s] vn=%s hn=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'),
    $row['vn'] ?? '-', $row['hn'] ?? '-',
    $code, $err ?: '-', mb_substr($resp ?? '', 0, 2000)
  );
  @file_put_contents($MOPH_LOG, $line, FILE_APPEND);
}

/* ═══ START ══════════════════════════════════════════════════════ */
dng_log('=== dengue_ingest START' . ($isDry ? ' [DRYRUN]' : '') . ' ===');
dng_log("ช่วง: {$start} ถึง {$end}  lab_items_code=" . (module_filter('dengue')['lab_code'] ?? '2891'));

/* ═══ STEP 1: Query HOSxP ════════════════════════════════════════ */
try {
  require_once __DIR__ . '/sources/dengue_source.php';
  $hosxpRows = dengue_source_rows($start, $end);
  dng_log('HOSxP: พบ ' . count($hosxpRows) . ' รายการ');
} catch (Throwable $e) {
  dng_log('ERROR HOSxP query: ' . $e->getMessage());
  exit(1);
}

/* ═══ STEP 2: Upsert → dengue_queue ═════════════════════════════ */
if ($isDry) {
  dng_log('DRYRUN — ไม่ upsert จริง แสดงเฉพาะผลลัพธ์:');
  foreach ($hosxpRows as $r) {
    $r = row_to_utf8($r);
    dng_log("  ROW vn={$r['vn']} hn={$r['hn']} vstdate={$r['vstdate']} result={$r['lab_order_result']}");
  }
  dng_log('=== dengue_ingest DONE [DRYRUN] ===');
  exit(0);
}

$ins = $dbcon->prepare(
  "INSERT INTO dengue_queue
     (vn, lab_order_number, hn, fullname, age, sex, cid, hometel, address,
      vstdate, doctor, pdx, icd10_name, lab_order_result)
   VALUES (:vn,:lon,:hn,:fn,:age,:sex,:cid,:tel,:addr,:vd,:doc,:pdx,:iname,:lres)
   ON DUPLICATE KEY UPDATE
     fullname=VALUES(fullname), icd10_name=VALUES(icd10_name),
     lab_order_result=VALUES(lab_order_result)"
);

$chkStmt = $dbcon->prepare("SELECT id FROM dengue_queue WHERE vn=?");
$imported = 0; $newRows = 0; $skipped = 0;

foreach ($hosxpRows as $hr) {
  $hr  = row_to_utf8($hr);
  $vn  = trim((string)($hr['vn'] ?? ''));
  if ($vn === '') { $skipped++; continue; }

  $chkStmt->execute([$vn]);
  $isNew = !$chkStmt->fetch();

  try {
    $ins->execute([
      ':vn'    => $vn,
      ':lon'   => $hr['lab_order_number'] ?? null,
      ':hn'    => trim((string)($hr['hn'] ?? '')),
      ':fn'    => $hr['fullname']         ?? null,
      ':age'   => is_numeric($hr['age'])  ? (int)$hr['age'] : null,
      ':sex'   => $hr['sex']              ?? null,
      ':cid'   => $hr['cid']              ?? null,
      ':tel'   => $hr['hometel']          ?? null,
      ':addr'  => $hr['address']          ?? null,
      ':vd'    => $hr['vstdate']          ?: null,
      ':doc'   => $hr['doctor']           ?? null,
      ':pdx'   => $hr['pdx']              ?? null,
      ':iname' => $hr['icd10_name']       ?? null,
      ':lres'  => $hr['lab_order_result'] ?? null,
    ]);
    $imported++;
    if ($isNew) {
      $newRows++;
      dng_log("  NEW vn={$vn} hn={$hr['hn']} vstdate={$hr['vstdate']}");
    }
  } catch (Throwable $e) {
    dng_log("  WARN upsert vn={$vn}: " . $e->getMessage());
    $skipped++;
  }
}
dng_log("Upsert: imported={$imported} new={$newRows} skipped={$skipped}");

/* ═══ STEP 3: Send pending queue ════════════════════════════════ */
dng_log('กำลังส่ง pending queue (attempt < 3)...');

try {
  $pending = $dbcon->query(
    "SELECT * FROM dengue_queue
     WHERE  status = 0 AND attempt < 3
     ORDER  BY vstdate ASC
     LIMIT  50"
  )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  dng_log('ERROR pending query: ' . $e->getMessage());
  exit(1);
}

dng_log('Pending: ' . count($pending) . ' รายการ');
$sentOk = 0; $sentFail = 0;

foreach ($pending as $qRow) {
  $qRow = row_to_utf8($qRow);
  $id   = (int)$qRow['id'];
  $vn   = $qRow['vn'] ?? '';

  $payloadRow = [
    'vn'       => $vn,
    'hn'       => $qRow['hn']               ?? '',
    'fullname' => $qRow['fullname']         ?? '-',
    'age'      => $qRow['age']              ?? '',
    'sex'      => $qRow['sex']              ?? '',
    'cid'      => $qRow['cid']              ?? '-',
    'address'  => $qRow['address']          ?? '-',
    'hometel'  => $qRow['hometel']          ?? '-',
    'vstdate'  => $qRow['vstdate']          ?? '',
    'doctor'   => $qRow['doctor']           ?? '-',
    'disease'  => $qRow['icd10_name']       ?? '-',
    'icd10'    => $qRow['pdx']              ?? '-',
    'result'   => $qRow['lab_order_result'] ?? '-',
  ];

  $payload = buildDiseasePayload($payloadRow, 'dengue');
  $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if (!$body) {
    dng_log("  SKIP vn={$vn} json_encode failed");
    $sentFail++;
    continue;
  }

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => MOPH_API_URL,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => MOPH_TIMEOUT,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
      'client-key: ' . DENGUE_CLIENT_KEY,
      'secret-key: ' . DENGUE_SECRET_KEY,
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json',
    ],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  dng_moph_log($qRow, $code, $resp, $err);

  $json      = json_decode($resp, true);
  $mid       = extract_moph_message_id($json);
  $apiStatus = is_array($json) ? ($json['status']  ?? null) : null;
  $apiMsg    = is_array($json) ? ($json['message'] ?? null) : null;
  $ok        = $mid
            || (is_numeric($apiStatus) && (int)$apiStatus === 200)
            || ($apiMsg && preg_match('/succ(e|)ss/i', (string)$apiMsg));

  if (!$err && ($code >= 200 && $code < 300) && $ok) {
    $ref = $mid ?: ($apiStatus ? "status:{$apiStatus}" : "HTTP{$code}");
    $dbcon->prepare(
      "UPDATE dengue_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(),
       attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r WHERE id=:id"
    )->execute([':r' => $ref, ':id' => $id]);
    require_once __DIR__ . '/telegram_lib.php';
    telegram_mirror('dengue', '🦟 แจ้งเตือนผู้ป่วยไข้เลือดออก', $qRow);
    dng_log("  SENT vn={$vn} hn={$qRow['hn']} ref={$ref}");
    $sentOk++;
  } else {
    $detail = $err ? "CURL: {$err}" : "HTTP={$code}" . ($apiMsg ? " msg={$apiMsg}" : '');
    $dbcon->prepare(
      "UPDATE dengue_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id"
    )->execute([':e' => $detail, ':id' => $id]);
    dng_log("  FAIL vn={$vn} hn={$qRow['hn']} err={$detail}");
    $sentFail++;
  }
}
dng_log("Send result: ok={$sentOk} fail={$sentFail}");

/* ═══ DONE ════════════════════════════════════════════════════════ */
dng_log('=== dengue_ingest DONE ===');
exit(0);
