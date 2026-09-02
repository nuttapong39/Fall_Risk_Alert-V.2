<?php
/**
 * sexual_action.php — Action handler สำหรับ sexual.php (Queue mode)
 *
 * Actions (POST):
 *   import_hosxp    — AJAX: Query HOSxP → upsert sexual_alert_queue  (ไม่ต้องมี token)
 *   send_queue_item — AJAX: ส่ง LINE Flex สำหรับ queue item by id    (ไม่ต้องมี token)
 *   send_now        — Bulk: ส่ง LINE หลายรายการ                      (ต้องมี token)
 *   requeue         — Bulk: reset status/attempt                      (ต้องมี token)
 *   clear_error     — Bulk: ล้าง last_error                           (ต้องมี token)
 *   send (legacy)   — AJAX: ส่งด้วย VN ตรงจาก HOSxP (backwards compat)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_sexual.php';
// auth_guard.php — ไม่ require เพราะถูกเรียกจาก AJAX และ CLI

date_default_timezone_set('Asia/Bangkok');

/* ── MOPH API Keys ───────────────────────────────────────────── */
if (!defined('MOPH_TIMEOUT'))      define('MOPH_TIMEOUT',      30);
if (!defined('SEXUAL_CLIENT_KEY')) define('SEXUAL_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('SEXUAL_SECRET_KEY')) define('SEXUAL_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

/* ── UI Token (ต้องตรงกับ sexual.php) ───────────────────────── */
if (!defined('SEXUAL_UI_TOKEN')) {
  define('SEXUAL_UI_TOKEN', hash('sha256', __DIR__ . '/sexual.php' . php_uname() . date('Y-m-d')));
}

/* ── Logging ─────────────────────────────────────────────────── */
$LOG_DIR  = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . '/moph_alert_sexual.log';

function sx_log_moph(array $row, $httpCode, $resp, $err = null): void {
  global $LOG_FILE;
  @file_put_contents($LOG_FILE,
    sprintf("[%s] id=%s vn=%s hn=%s http=%s err=%s resp=%s\n",
      date('Y-m-d H:i:s'),
      $row['id'] ?? '-', $row['vn'] ?? '-', $row['hn'] ?? '-',
      $httpCode, $err ?: '-', mb_substr($resp ?? '', 0, 2000)
    ), FILE_APPEND);
}

/* ── Method guard ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); exit('Method not allowed');
}

$action = trim($_POST['action'] ?? '');

/* ═══════════════════════════════════════════════════════════════
   AJAX — import_hosxp
   Query HOSxP → upsert sexual_alert_queue (ไม่ส่ง LINE ทันที)
   ═══════════════════════════════════════════════════════════════ */
if ($action === 'import_hosxp') {
  header('Content-Type: application/json; charset=utf-8');

  $impStart = trim($_POST['start'] ?? date('Y-m-d', strtotime('-30 days')));
  $impEnd   = trim($_POST['end']   ?? date('Y-m-d'));

  try {
    /* ── Query HOSxP ── */
    require_once __DIR__ . '/sources/sexual_source.php';
    $hosxpRows = sexual_source_rows($impStart, $impEnd, LAB_CODE_SEXUAL);

    /* ── Upsert ── */
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

    foreach ($hosxpRows as $hr) {
      $hr  = row_to_utf8($hr);
      $vn  = trim((string)($hr['vn'] ?? ''));
      $lon = trim((string)($hr['lab_order_number'] ?? ''));
      if ($vn === '' || $lon === '') { $skipped++; continue; }

      $chkStmt->execute([$vn, $lon]);
      $isNew = !$chkStmt->fetch();

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
      if ($isNew) $newRows++;
    }

    $skipNote = $skipped > 0 ? " (ข้าม {$skipped} แถว)" : '';
    echo json_encode([
      'ok'       => true,
      'imported' => $imported,
      'new'      => $newRows,
      'skipped'  => $skipped,
      'msg'      => "นำเข้าสำเร็จ {$imported} รายการ (ใหม่ {$newRows} รายการ){$skipNote}",
    ]);

  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
  }
  exit;
}

/* ═══════════════════════════════════════════════════════════════
   AJAX — send_queue_item
   ส่ง LINE Flex สำหรับ queue item by ID
   ═══════════════════════════════════════════════════════════════ */
if ($action === 'send_queue_item') {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'id ไม่ถูกต้อง']); exit; }

  [$ok, $ref, $errMsg] = sx_send_one($dbcon, $id);
  echo json_encode($ok
    ? ['ok' => true,  'msg' => 'ส่งสำเร็จ', 'ref' => $ref]
    : ['ok' => false, 'msg' => $errMsg ?? 'เกิดข้อผิดพลาด']
  );
  exit;
}

/* ═══════════════════════════════════════════════════════════════
   AJAX — send (legacy)
   ส่งด้วย VN ตรงจาก HOSxP (backwards compatibility)
   ═══════════════════════════════════════════════════════════════ */
if ($action === 'send') {
  header('Content-Type: application/json; charset=utf-8');
  $vn = trim($_POST['vn'] ?? '');
  if ($vn === '') { echo json_encode(['ok' => false, 'msg' => 'ไม่ได้ระบุ VN']); exit; }

  try {
    require_once __DIR__ . '/sources/sexual_source.php';
    $row = sexual_source_by_vn($vn, LAB_CODE_SEXUAL);
    if (!$row) { echo json_encode(['ok' => false, 'msg' => "ไม่พบ VN: {$vn}"]); exit; }
    $row = row_to_utf8($row);

    [$ok, $ref, $errMsg] = sx_build_and_send($row, $row);
    echo json_encode($ok
      ? ['ok' => true,  'msg' => 'ส่งสำเร็จ', 'ref' => $ref]
      : ['ok' => false, 'msg' => $errMsg ?? 'เกิดข้อผิดพลาด']
    );
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
  }
  exit;
}

/* ── CSRF guard (bulk actions require token) ─────────────────── */
if (!isset($_POST['token']) || $_POST['token'] !== SEXUAL_UI_TOKEN) {
  http_response_code(403); exit('Forbidden');
}

$ids = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
$ids = array_values(array_filter($ids, fn($x) => ctype_digit((string)$x)));
if (!$ids) { header('Location: sexual.php?msg=no_ids'); exit; }

try {
  /* ═══ Bulk: requeue ══════════════════════════════════════════ */
  if ($action === 'requeue') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $dbcon->prepare(
      "UPDATE sexual_alert_queue
       SET status=0, attempt=0, last_attempt_at=NULL,
           last_error=NULL, out_ref=NULL, line_message_id=NULL
       WHERE id IN ($place)"
    );
    $stmt->execute($ids);
    header('Location: sexual.php?msg=requeued&affected=' . $stmt->rowCount()); exit;
  }

  /* ═══ Bulk: clear_error ══════════════════════════════════════ */
  if ($action === 'clear_error') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $dbcon->prepare("UPDATE sexual_alert_queue SET last_error=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: sexual.php?msg=cleared&affected=' . $stmt->rowCount()); exit;
  }

  /* ═══ Bulk: send_now ══════════════════════════════════════════ */
  if ($action === 'send_now') {
    $ok = 0; $fail = 0;
    foreach ($ids as $id) {
      [$o] = sx_send_one($dbcon, (int)$id);
      if ($o) $ok++; else $fail++;
    }
    header('Location: sexual.php?msg=sendnow&ok=' . $ok . '&fail=' . $fail); exit;
  }

  header('Location: sexual.php?msg=bad_action'); exit;

} catch (Throwable $e) {
  header('Location: sexual.php?msg=err&detail=' . urlencode($e->getMessage()));
}

/* ═══════════════════════════════════════════════════════════════
   Helper: ส่ง LINE Flex สำหรับ queue item by ID
   คืนค่า [bool $ok, ?string $ref, ?string $errMsg]
   ═══════════════════════════════════════════════════════════════ */
function sx_send_one(PDO $db, int $id): array {
  $get = $db->prepare("SELECT * FROM sexual_alert_queue WHERE id=:id");
  $get->execute([':id' => $id]);
  $row = $get->fetch(PDO::FETCH_ASSOC);
  if (!$row) return [false, null, 'id not found'];
  $row = row_to_utf8($row);

  /* Map queue columns → buildSexualPayload fields */
  $payloadRow = [
    'vn'                 => $row['vn'],
    'hn'                 => $row['hn'],
    'fullname'           => $row['fullname'],
    'cid'                => $row['cid']                ?? '-',
    'hometel'            => $row['hometel']             ?? '-',
    'address'            => $row['address']             ?? '-',
    'age'                => $row['age']                 ?? '',
    'sex'                => $row['sex']                 ?? '',
    'order_date'         => $row['lab_date']            ?? '',
    'lab_items_name_ref' => $row['lab_items_name_ref']  ?? '-',
    'lab_order_result'   => $row['lab_order_result']    ?? '-',
  ];

  [$ok, $ref, $errMsg] = sx_build_and_send($payloadRow, $row);

  if ($ok) {
    $db->prepare(
      "UPDATE sexual_alert_queue
       SET status=1, sent_at=NOW(), last_attempt_at=NOW(),
           attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r
       WHERE id=:id"
    )->execute([':r' => $ref, ':id' => $id]);
  } else {
    $db->prepare(
      "UPDATE sexual_alert_queue
       SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e
       WHERE id=:id"
    )->execute([':e' => $errMsg, ':id' => $id]);
  }
  return [$ok, $ref, $errMsg];
}

/* ═══════════════════════════════════════════════════════════════
   Helper: ประกอบ Flex payload แล้วส่ง MOPH Alert
   คืนค่า [bool $ok, ?string $ref, ?string $errMsg]
   ═══════════════════════════════════════════════════════════════ */
function sx_build_and_send(array $payloadRow, array $logRow = []): array {
  $logRow  = $logRow ?: $payloadRow;
  $payload = buildSexualPayload($payloadRow);
  $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

  if ($body === false) {
    $e = 'JSON encode failed: ' . json_last_error_msg();
    sx_log_moph($logRow, 0, null, $e);
    return [false, null, $e];
  }

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

  sx_log_moph($logRow, $code, $resp, $err);

  if ($err) return [false, null, "CURL: $err"];

  $json      = json_decode($resp, true);
  $mid       = extract_moph_message_id($json);
  $apiStatus = is_array($json) ? ($json['status']  ?? null) : null;
  $apiMsg    = is_array($json) ? ($json['message'] ?? null) : null;
  $looksOk   = $mid
            || (is_numeric($apiStatus) && (int)$apiStatus === 200)
            || ($apiMsg && preg_match('/succ(e|)ss/i', (string)$apiMsg));

  if (($code >= 200 && $code < 300) && $looksOk) {
    $ref = $mid ?: ($apiStatus ? "status:{$apiStatus}" : "HTTP{$code}");
    return [true, $ref, null];
  }

  $detail  = "HTTP={$code}";
  if ($apiStatus !== null) $detail .= " status={$apiStatus}";
  if ($apiMsg)             $detail .= " msg={$apiMsg}";
  return [false, null, "MOPH error: {$detail}"];
}
