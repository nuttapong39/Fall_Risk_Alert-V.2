<?php
/**
 * lepto_queue_action.php — Action handler สำหรับ Leptospira.php
 *
 * POST action=import_hosxp   start end              → Sync HOSxP → lepto_queue (JSON)
 * POST action=send_now        token ids[]            → Bulk send LINE Flex (redirect)
 * POST action=requeue         token ids[]            → Reset pending (redirect)
 * POST action=clear_error     token ids[]            → ล้าง error (redirect)
 * POST action=send_queue_item token id               → ส่งรายการเดี่ยว (JSON)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_disease.php';
date_default_timezone_set('Asia/Bangkok');

// ── Constants ─────────────────────────────────────────────────────────────────
define('LEPTO_Q_UI_TOKEN',  hash('sha256', __DIR__ . '/Leptospira.php' . php_uname() . date('Y-m-d')));

if (!defined('MOPH_API_URL'))         define('MOPH_API_URL',         'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT'))         define('MOPH_TIMEOUT',         30);
if (!defined('LEPTO_CLIENT_KEY'))    define('LEPTO_CLIENT_KEY',    defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('LEPTO_SECRET_KEY'))    define('LEPTO_SECRET_KEY',    defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

// ── Logging ───────────────────────────────────────────────────────────────────
$LOG_DIR  = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . '/moph_alert_lepto.log';

function dq_log(array $row, int $code, ?string $resp, ?string $err = null): void {
  global $LOG_FILE;
  $line = sprintf("[%s] id=%s vn=%s hn=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'),
    $row['id'] ?? '-', $row['vn'] ?? '-', $row['hn'] ?? '-',
    $code, $err ?: '-', mb_substr($resp ?? '', 0, 2000)
  );
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

// ── Send one queue row via MOPH Alert ─────────────────────────────────────────
function dq_send_row(array $qRow): array {
  $payloadRow = [
    'vn'       => $qRow['vn']               ?? '',
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

  $payload = buildDiseasePayload($payloadRow, 'lepto');
  $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if (!$body) return ['ok' => false, 'err' => 'json_encode failed'];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => MOPH_API_URL,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => MOPH_TIMEOUT,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
      'client-key: ' . LEPTO_CLIENT_KEY,
      'secret-key: ' . LEPTO_SECRET_KEY,
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json',
    ],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  dq_log($qRow, $code, $resp, $err);

  if ($err) return ['ok' => false, 'err' => "CURL: $err"];

  $json      = json_decode($resp, true);
  $mid       = extract_moph_message_id($json);
  $apiStatus = is_array($json) ? ($json['status']  ?? null) : null;
  $apiMsg    = is_array($json) ? ($json['message'] ?? null) : null;
  $ok        = $mid
            || (is_numeric($apiStatus) && (int)$apiStatus === 200)
            || ($apiMsg && preg_match('/succ(e|)ss/i', (string)$apiMsg));

  if (($code >= 200 && $code < 300) && $ok) {
    $ref = $mid ?: ($apiStatus ? "status:$apiStatus" : "HTTP$code");
    return ['ok' => true, 'ref' => $ref];
  }
  $detail = "HTTP=$code" . ($apiMsg ? " msg=$apiMsg" : '');
  return ['ok' => false, 'err' => "MOPH: $detail"];
}

// ── Route ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); exit('Method not allowed');
}

$action = $_POST['action'] ?? '';

/* ── import_hosxp (AJAX JSON) ──────────────────────────────────────────────── */
if ($action === 'import_hosxp') {
  header('Content-Type: application/json; charset=utf-8');

  $start    = trim($_POST['start'] ?? date('Y-m-d', strtotime('-7 days')));
  $end      = trim($_POST['end']   ?? date('Y-m-d'));

  try {
    require_once __DIR__ . '/sources/lepto_source.php';
    $hosxpRows = lepto_source_rows($start, $end);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'HOSxP query error: ' . $e->getMessage()]);
    exit;
  }

  $ins = $dbcon->prepare(
    "INSERT INTO lepto_queue
       (vn, lab_order_number, hn, fullname, age, sex, cid, hometel, address,
        vstdate, doctor, pdx, icd10_name, lab_order_result)
     VALUES (:vn,:lon,:hn,:fn,:age,:sex,:cid,:tel,:addr,:vd,:doc,:pdx,:iname,:lres)
     ON DUPLICATE KEY UPDATE
       fullname=VALUES(fullname), icd10_name=VALUES(icd10_name),
       lab_order_result=VALUES(lab_order_result)"
  );

  $chkStmt = $dbcon->prepare("SELECT id FROM lepto_queue WHERE vn=?");
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
        ':lon'   => null,
        ':hn'    => trim((string)($hr['hn'] ?? '')),
        ':fn'    => $hr['fullname']         ?? null,
        ':age'   => is_numeric($hr['age'])  ? (int)$hr['age'] : null,
        ':sex'   => $hr['sex']              ?? null,
        ':cid'   => $hr['cid']              ?? null,
        ':tel'   => $hr['hometel']          ?? null,
        ':addr'  => $hr['address']          ?? null,
        ':vd'    => $hr['vstdate']          ?: null,
        ':doc'   => $hr['doctor']           ?? null,
        ':pdx'   => $hr['icd10']            ?? null,
        ':iname' => $hr['disease']          ?? null,
        ':lres'  => $hr['result']           ?? null,
      ]);
      $imported++;
      if ($isNew) $newRows++;
    } catch (Throwable $e) {
      $skipped++;
    }
  }

  echo json_encode([
    'ok'       => true,
    'msg'      => "Sync สำเร็จ {$imported} รายการ (ใหม่ {$newRows} รายการ)",
    'imported' => $imported,
    'new'      => $newRows,
    'skipped'  => $skipped,
  ]);
  exit;
}

/* ── send_queue_item (AJAX JSON — single row) ──────────────────────────────── */
if ($action === 'send_queue_item') {
  header('Content-Type: application/json; charset=utf-8');

  $id = (int)($_POST['id'] ?? 0);
  if (!$id) { echo json_encode(['ok' => false, 'msg' => 'Invalid id']); exit; }

  $qRow = $dbcon->query("SELECT * FROM lepto_queue WHERE id=$id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  if (!$qRow) { echo json_encode(['ok' => false, 'msg' => "ไม่พบ id=$id"]); exit; }

  $qRow   = row_to_utf8($qRow);
  $result = dq_send_row($qRow);

  if ($result['ok']) {
    $dbcon->prepare(
      "UPDATE lepto_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(),
       attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r WHERE id=:id"
    )->execute([':r' => $result['ref'], ':id' => $id]);
    echo json_encode(['ok' => true, 'ref' => $result['ref']]);
  } else {
    $dbcon->prepare(
      "UPDATE lepto_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id"
    )->execute([':e' => $result['err'], ':id' => $id]);
    echo json_encode(['ok' => false, 'msg' => $result['err']]);
  }
  exit;
}

/* ── Token-protected bulk actions (form submit → redirect) ─────────────────── */
$token = $_POST['token'] ?? '';
if ($token !== LEPTO_Q_UI_TOKEN) {
  header('Location: Leptospira.php?msg=bad_action');
  exit;
}

$rawIds = $_POST['ids'] ?? [];
$ids    = array_filter(array_map('intval', (array)$rawIds));

if (empty($ids) && $action !== 'import_hosxp') {
  header('Location: Leptospira.php?msg=no_ids');
  exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

/* ── send_now ──────────────────────────────────────────────────────────────── */
if ($action === 'send_now') {
  $rows   = $dbcon->prepare("SELECT * FROM lepto_queue WHERE id IN ($placeholders)");
  $rows->execute($ids);
  $rows   = $rows->fetchAll(PDO::FETCH_ASSOC);
  $sentOk = $sentFail = 0;

  foreach ($rows as $qRow) {
    $qRow   = row_to_utf8($qRow);
    $result = dq_send_row($qRow);
    $id     = (int)$qRow['id'];

    if ($result['ok']) {
      $dbcon->prepare(
        "UPDATE lepto_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(),
         attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r WHERE id=:id"
      )->execute([':r' => $result['ref'], ':id' => $id]);
      $sentOk++;
    } else {
      $dbcon->prepare(
        "UPDATE lepto_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id"
      )->execute([':e' => $result['err'], ':id' => $id]);
      $sentFail++;
    }
  }
  header("Location: Leptospira.php?msg=sendnow&ok={$sentOk}&fail={$sentFail}");
  exit;
}

/* ── requeue ───────────────────────────────────────────────────────────────── */
if ($action === 'requeue') {
  $stmt = $dbcon->prepare(
    "UPDATE lepto_queue SET status=0, attempt=0, last_error=NULL, last_attempt_at=NULL WHERE id IN ($placeholders)"
  );
  $stmt->execute($ids);
  $aff = $stmt->rowCount();
  header("Location: Leptospira.php?msg=requeued&affected={$aff}");
  exit;
}

/* ── clear_error ───────────────────────────────────────────────────────────── */
if ($action === 'clear_error') {
  $stmt = $dbcon->prepare(
    "UPDATE lepto_queue SET last_error=NULL WHERE id IN ($placeholders)"
  );
  $stmt->execute($ids);
  $aff = $stmt->rowCount();
  header("Location: Leptospira.php?msg=cleared&affected={$aff}");
  exit;
}

header('Location: Leptospira.php?msg=bad_action');
exit;
