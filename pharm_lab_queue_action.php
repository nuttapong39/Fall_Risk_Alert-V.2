<?php
/**
 * pharm_lab_queue_action.php — Bulk action handler สำหรับคิว pharm_lab_queue
 *  - Actions: send_now, requeue, clear_error
 *  - ใช้ buildPharmPayload() จาก flex_pharm.php
 *  - ไม่จำเป็นต้อง include pharm_lab.php อีกต่อไป (ตัด PHARM_LIB_ONLY workaround ออก)
 */
require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/flex_pharm.php';  // buildPharmPayload, extract_moph_message_id, row_to_utf8, ...

date_default_timezone_set('Asia/Bangkok');

/* ให้ action สร้าง UI_ACTION_TOKEN แบบเดียวกับหน้า UI */
if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', hash('sha256', __DIR__ . '/pharm_lab_queue_ui.php' . php_uname() . date('Y-m-d')));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
if (!isset($_POST['token']) || $_POST['token'] !== UI_ACTION_TOKEN) { http_response_code(403); exit('Forbidden'); }

$action = $_POST['action'] ?? '';
$ids    = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
$ids    = array_values(array_filter($ids, fn($x)=>ctype_digit((string)$x)));
if (!$ids) { header('Location: pharm_lab_queue_ui.php?msg=no_ids'); exit; }

/* ====== CONFIG (ฝั่ง Pharm) ====== */
if (!defined('MOPH_API_URL')) define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);

if (!defined('PHARM_CLIENT_KEY')) {
  if      (defined('MOPH_CLIENT_KEY'))     define('PHARM_CLIENT_KEY', MOPH_CLIENT_KEY);
  elseif  (defined('FRACTURE_CLIENT_KEY')) define('PHARM_CLIENT_KEY', FRACTURE_CLIENT_KEY);
  else                                      define('PHARM_CLIENT_KEY', '');
}
if (!defined('PHARM_SECRET_KEY')) {
  if      (defined('MOPH_SECRET_KEY'))     define('PHARM_SECRET_KEY', MOPH_SECRET_KEY);
  elseif  (defined('FRACTURE_SECRET_KEY')) define('PHARM_SECRET_KEY', FRACTURE_SECRET_KEY);
  else                                      define('PHARM_SECRET_KEY', '');
}

$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . '/moph_alert_pharm_lab.log';

function log_moph_response_pharm($row, $code, $resp, $err=null){
  global $LOG_FILE;
  $line = sprintf("[%s] id=%s hn=%s lab=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'),
    $row['id']??'-', $row['hn']??'-', $row['lab_name']??'-',
    $code, $err ?: '-', mb_substr($resp ?? '', 0, 2000));
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

function send_one_now_pharm(PDO $db, int $id): array {
  $get = $db->prepare("SELECT * FROM pharm_lab_queue WHERE id=:id");
  $get->execute([':id'=>$id]);
  $row = $get->fetch();
  if (!$row) return [false, null, "id not found"];
  $row = row_to_utf8($row);

  $payload = buildPharmPayload($row);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false){
    $jsonErr = json_last_error_msg();
    log_moph_response_pharm($row, 0, null, "JSON_ENCODE_FAIL: ".$jsonErr);
    $upd = $db->prepare("UPDATE pharm_lab_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
    $upd->execute([':e'=>"JSON encode failed: ".$jsonErr, ':id'=>$id]);
    return [false, null, "JSON encode failed: ".$jsonErr];
  }

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => MOPH_API_URL,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => MOPH_TIMEOUT,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
      'client-key: ' . PHARM_CLIENT_KEY,
      'secret-key: ' . PHARM_SECRET_KEY,
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json'
    ],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  log_moph_response_pharm($row, $code, $resp, $err);

  if ($err){
    $upd = $db->prepare("UPDATE pharm_lab_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
    $upd->execute([':e'=>"CURL: $err", ':id'=>$id]);
    return [false, null, "CURL: $err"];
  }

  $json = json_decode($resp, true);
  $mid  = extract_moph_message_id($json);
  $apiStatus = is_array($json) && array_key_exists('status',$json) ? $json['status'] : null;
  $apiMsg    = is_array($json) && array_key_exists('message',$json) ? (string)$json['message'] : null;
  $looksSuccess = ($mid) || (is_numeric($apiStatus) && (int)$apiStatus===200)
                  || ($apiMsg && preg_match('/succ(e|)ss/i',$apiMsg));

  if (($code>=200 && $code<300) && $looksSuccess){
    $ref = $mid ?: ($apiStatus ? "status:$apiStatus" : 'HTTP'.$code);
    $upd = $db->prepare("UPDATE pharm_lab_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r WHERE id=:id");
    $upd->execute([':r'=>$ref, ':id'=>$id]);
    return [true, $ref, null];
  }

  $detail = "HTTP=$code"; if ($apiStatus!==null) $detail.=" status=$apiStatus"; if ($apiMsg) $detail.=" msg=$apiMsg";
  $upd = $db->prepare("UPDATE pharm_lab_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
  $upd->execute([':e'=>"MOPH error: $detail", ':id'=>$id]);
  return [false, null, "MOPH error: $detail"];
}

/* ====== Execute action ====== */
try {
  if ($action === 'requeue') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("UPDATE pharm_lab_queue SET status=0, attempt=0, last_attempt_at=NULL, last_error=NULL, out_ref=NULL, line_message_id=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: pharm_lab_queue_ui.php?msg=requeued&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'clear_error') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("UPDATE pharm_lab_queue SET last_error=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: pharm_lab_queue_ui.php?msg=cleared&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'send_now') {
    $ok=0; $fail=0;
    foreach ($ids as $id) {
      [$o,$r,$e] = send_one_now_pharm($dbcon, (int)$id);
      if ($o) $ok++; else $fail++;
    }
    header('Location: pharm_lab_queue_ui.php?msg=sendnow&ok='.$ok.'&fail='.$fail); exit;

  } else {
    header('Location: pharm_lab_queue_ui.php?msg=bad_action'); exit;
  }
} catch (Throwable $e) {
  header('Location: pharm_lab_queue_ui.php?msg=err&detail='.urlencode($e->getMessage()));
}
