<?php
/**
 * sexual_ingest.php — CLI worker สำหรับ Task Scheduler
 * ─────────────────────────────────────────────────────────────────────────────
 * ต้องรันผ่าน PHP CLI เท่านั้น (ไม่ใช่ผ่านเว็บ)
 *
 * Usage:
 *   php sexual_ingest.php                          — ดึงย้อนหลัง 30 วัน (default)
 *   php sexual_ingest.php dryrun                   — แสดงผลเฉพาะ ไม่ upsert จริง
 *   php sexual_ingest.php send                     — ดึง + ส่ง pending queue ทันที
 *   php sexual_ingest.php start 2025-01-01         — backfill ตั้งแต่วันที่
 *   php sexual_ingest.php start 2025-01-01 end 2025-12-31
 *   php sexual_ingest.php send start 2025-06-01    — backfill + ส่งด้วย
 */

/* ── CLI guard ─────────────────────────────────────────────────── */
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("sexual_ingest.php must be run from CLI only.\n");
}

define('APP_DIR', __DIR__);
date_default_timezone_set('Asia/Bangkok');
set_time_limit(300);

/* ── Parse CLI args ────────────────────────────────────────────── */
$args   = array_slice($argv ?? [], 1);
$isDry  = in_array('dryrun', $args, true);
$isSend = in_array('send',   $args, true);
$start  = date('Y-m-d', strtotime('-90 days'));
$end    = date('Y-m-d');

for ($i = 0; $i < count($args); $i++) {
  if ($args[$i] === 'start' && isset($args[$i + 1])) $start = $args[$i + 1];
  if ($args[$i] === 'end'   && isset($args[$i + 1])) $end   = $args[$i + 1];
}

/* ── Bootstrap ─────────────────────────────────────────────────── */
require_once APP_DIR . '/config.php';
require_once APP_DIR . '/flex_sexual.php';

/* ── Logging ───────────────────────────────────────────────────── */
$LOG_DIR  = APP_DIR . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . '/sexual_task_run.log';

function sx_ingest_log(string $msg): void {
  global $LOG_FILE;
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
  echo $line;
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

/* ═══ START ══════════════════════════════════════════════════════ */
sx_ingest_log('=== sexual_ingest START' . ($isDry ? ' [DRYRUN]' : '') . ($isSend ? ' [+SEND]' : '') . ' ===');
sx_ingest_log("ช่วง: {$start} ถึง {$end}  lab_items_code=" . LAB_CODE_SEXUAL);

/* ═══ STEP 1: Query HOSxP ════════════════════════════════════════ */
try {
  require_once __DIR__ . '/sources/sexual_source.php';
  $rows = sexual_source_rows($start, $end, LAB_CODE_SEXUAL);
  sx_ingest_log('HOSxP: พบ ' . count($rows) . ' รายการ');
} catch (Throwable $e) {
  sx_ingest_log('ERROR HOSxP query: ' . $e->getMessage());
  exit(1);
}

/* ═══ STEP 2: Upsert → sexual_alert_queue ════════════════════════ */
if ($isDry) {
  sx_ingest_log('DRYRUN — ไม่ upsert จริง แสดงเฉพาะผลลัพธ์:');
  foreach ($rows as $r) {
    $r = row_to_utf8($r);
    sx_ingest_log("  ROW vn={$r['vn']} hn={$r['hn']} lab_date={$r['lab_date']} result={$r['lab_order_result']}");
  }
} else {
  $ins = $dbcon->prepare(
    "INSERT INTO sexual_alert_queue
       (vn, hn, fullname, cid, age, sex, hometel, address,
        lab_date, lab_time, lab_items_name_ref, lab_order_result, lab_order_number)
     VALUES (:vn,:hn,:fn,:cid,:age,:sex,:tel,:addr,:ld,:lt,:lname,:lres,:lon)
     ON DUPLICATE KEY UPDATE
       fullname=VALUES(fullname), lab_order_result=VALUES(lab_order_result)"
  );
  $chkStmt = $dbcon->prepare(
    "SELECT id FROM sexual_alert_queue WHERE vn=? AND lab_order_number=?"
  );

  $imported = 0; $newRows = 0; $skipped = 0;

  foreach ($rows as $hr) {
    $hr  = row_to_utf8($hr);
    $vn  = trim((string)($hr['vn'] ?? ''));
    $lon = trim((string)($hr['lab_order_number'] ?? ''));
    if ($vn === '' || $lon === '') { $skipped++; continue; }

    $chkStmt->execute([$vn, $lon]);
    $isNew = !$chkStmt->fetch();

    try {
      $ins->execute([
        ':vn'   => $vn,
        ':hn'   => trim((string)($hr['hn'] ?? '')),
        ':fn'   => $hr['fullname']              ?? '',
        ':cid'  => $hr['cid']                  ?? null,
        ':age'  => is_numeric($hr['age'])       ? (int)$hr['age'] : null,
        ':sex'  => $hr['sex']                  ?? null,
        ':tel'  => $hr['hometel']              ?? null,
        ':addr' => $hr['address']              ?? null,
        ':ld'   => $hr['lab_date']             ?: null,
        ':lt'   => $hr['lab_time']             ? substr((string)$hr['lab_time'], 0, 8) : null,
        ':lname'=> $hr['lab_items_name_ref']   ?? null,
        ':lres' => $hr['lab_order_result']     ?? null,
        ':lon'  => $lon,
      ]);
      $imported++;
      if ($isNew) {
        $newRows++;
        sx_ingest_log("  NEW vn={$vn} hn={$hr['hn']} lab_date={$hr['lab_date']}");
      }
    } catch (Throwable $e) {
      sx_ingest_log("  WARN upsert vn={$vn}: " . $e->getMessage());
      $skipped++;
    }
  }
  sx_ingest_log("Upsert: imported={$imported} new={$newRows} skipped={$skipped}");
}

/* ═══ STEP 3: Send pending queue (ถ้า mode=send) ════════════════ */
if ($isSend && !$isDry) {
  if (!defined('MOPH_TIMEOUT'))      define('MOPH_TIMEOUT',      30);
  if (!defined('SEXUAL_CLIENT_KEY')) define('SEXUAL_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
  if (!defined('SEXUAL_SECRET_KEY')) define('SEXUAL_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

  sx_ingest_log('กำลังส่ง pending queue (attempt < 3)...');

  $pending = $dbcon->query(
    "SELECT * FROM sexual_alert_queue
     WHERE  status = 0 AND attempt < 3
     ORDER  BY lab_date ASC
     LIMIT  50"
  )->fetchAll(PDO::FETCH_ASSOC);

  sx_ingest_log('Pending: ' . count($pending) . ' รายการ');

  $sentOk = 0; $sentFail = 0;

  foreach ($pending as $qRow) {
    $qRow       = row_to_utf8($qRow);
    $payloadRow = [
      'vn'                 => $qRow['vn'],
      'hn'                 => $qRow['hn'],
      'fullname'           => $qRow['fullname'],
      'cid'                => $qRow['cid']               ?? '-',
      'hometel'            => $qRow['hometel']            ?? '-',
      'address'            => $qRow['address']            ?? '-',
      'age'                => $qRow['age']                ?? '',
      'sex'                => $qRow['sex']                ?? '',
      'order_date'         => $qRow['lab_date']           ?? '',
      'lab_items_name_ref' => $qRow['lab_items_name_ref'] ?? '-',
      'lab_order_result'   => $qRow['lab_order_result']   ?? '-',
    ];

    $payload = buildSexualPayload($payloadRow);
    $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!$body) { $sentFail++; sx_ingest_log("  SKIP id={$qRow['id']} json_encode failed"); continue; }

    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL            => MOPH_API_URL,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => MOPH_TIMEOUT,
      CURLOPT_CUSTOMREQUEST  => 'POST',
      CURLOPT_POSTFIELDS     => $body,
      CURLOPT_HTTPHEADER     => [
        'client-key: ' . SEXUAL_CLIENT_KEY,
        'secret-key: ' . SEXUAL_SECRET_KEY,
        'Content-Type: application/json; charset=UTF-8',
        'Accept: application/json',
      ],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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
        "UPDATE sexual_alert_queue
         SET status=1, sent_at=NOW(), last_attempt_at=NOW(),
             attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r
         WHERE id=:id"
      )->execute([':r' => $ref, ':id' => $qRow['id']]);
      require_once __DIR__ . '/telegram_lib.php';
      telegram_mirror('sexual', '🧬 แจ้งเตือนผล Lab โรคติดต่อทางเพศสัมพันธ์', $qRow);
      sx_ingest_log("  SENT id={$qRow['id']} vn={$qRow['vn']} ref={$ref}");
      $sentOk++;
    } else {
      $detail = $err ? "CURL: {$err}" : "HTTP={$code}" . ($apiMsg ? " msg={$apiMsg}" : '');
      $dbcon->prepare(
        "UPDATE sexual_alert_queue
         SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e
         WHERE id=:id"
      )->execute([':e' => $detail, ':id' => $qRow['id']]);
      sx_ingest_log("  FAIL id={$qRow['id']} vn={$qRow['vn']} err={$detail}");
      $sentFail++;
    }
  }
  sx_ingest_log("Send result: ok={$sentOk} fail={$sentFail}");
}

/* ═══ DONE ════════════════════════════════════════════════════════ */
sx_ingest_log('=== sexual_ingest DONE ===');
exit(0);
