<?php
require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/covid_lib.php';     // มี to_utf8(), row_to_utf8(), extract_moph_message_id()
require_once __DIR__ . '/flex_fracture.php'; // ไลบรารี Flex กลาง — ต้องมาก่อนฟังก์ชันท้องถิ่น

date_default_timezone_set('Asia/Bangkok');

// ให้ action สร้าง UI_ACTION_TOKEN แบบเดียวกับหน้า UI
if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', hash('sha256', __DIR__ . '/fracture_queue_ui.php' . php_uname() . date('Y-m-d')));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
if (!isset($_POST['token']) || !defined('UI_ACTION_TOKEN') || $_POST['token'] !== UI_ACTION_TOKEN) {
  http_response_code(403); exit('Forbidden');
}

$action = $_POST['action'] ?? '';
$ids    = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
$ids    = array_values(array_filter($ids, fn($x)=>ctype_digit((string)$x)));
if (!$ids) { header('Location: fracture_queue_ui.php?msg=no_ids'); exit; }

// ====== CONFIG (สำหรับส่ง MOPH Alert ฝั่ง Fracture) ======
if (!defined('MOPH_API_URL')) define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);

// ใช้ key เดียวกับ MOPH ถ้าไม่ได้กำหนดแยก
if (!defined('FRACTURE_CLIENT_KEY')) {
  define('FRACTURE_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
}
if (!defined('FRACTURE_SECRET_KEY')) {
  define('FRACTURE_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');
}

// FALL_TITLE / FALL_HEADER_URL / FALL_ICON_URL — define ไว้แล้วใน flex_fracture.php

$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . '/moph_alert_fracture.log';

// ====== Helpers ======
// buildFracturePayload() / extract_moph_message_id() / to_utf8() / row_to_utf8()
// ถูก define ไว้ใน flex_fracture.php + covid_lib.php แล้ว — ไม่ประกาศซ้ำ

function log_moph_response($row, $code, $resp, $err=null) {
  global $LOG_FILE;
  $line = sprintf("[%s] id=%s hn=%s http=%s err=%s resp=%s\n",
  date('Y-m-d H:i:s'), $row['id']??'-', $row['hn']??'-',
  $code, $err ?: '-', mb_substr($resp ?? '', 0, 2000)
);

  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}
function send_one_now(PDO $db, int $id): array {
  $get = $db->prepare("SELECT * FROM fracture_queue WHERE id=:id");
  $get->execute([':id'=>$id]);
  $row = $get->fetch();
  if (!$row) return [false, null, "id not found"];
  $row = row_to_utf8($row);

  $payload = buildFracturePayload($row);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false) {
    $jsonErr = json_last_error_msg();
    log_moph_response($row, 0, null, "JSON_ENCODE_FAIL: ".$jsonErr);
    $upd = $db->prepare("UPDATE fracture_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
    $upd->execute([':e'=>"JSON encode failed: ".$jsonErr, ':id'=>$id]);
    return [false, null, "JSON encode failed: ".$jsonErr];
  }

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => MOPH_API_URL,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => MOPH_TIMEOUT,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
      'client-key: ' . FRACTURE_CLIENT_KEY,
      'secret-key: ' . FRACTURE_SECRET_KEY,
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json'
    ],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  log_moph_response($row, $code, $resp, $err);

  if ($err) {
    $upd = $db->prepare("UPDATE fracture_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
    $upd->execute([':e'=>"CURL: $err", ':id'=>$id]);
    return [false, null, "CURL: $err"];
  }

  $json = json_decode($resp, true);
  $mid  = extract_moph_message_id($json);
  $apiStatus = is_array($json) && array_key_exists('status',$json) ? $json['status'] : null;
  $apiMsg    = is_array($json) && array_key_exists('message',$json) ? (string)$json['message'] : null;
  $looksSuccess = ($mid) || (is_numeric($apiStatus) && (int)$apiStatus===200) || ($apiMsg && preg_match('/succ(e|)ss/i',$apiMsg));

  if (($code>=200 && $code<300) && $looksSuccess) {
    $ref = $mid ?: ($apiStatus ? "status:$apiStatus" : 'HTTP'.$code);
    $upd = $db->prepare("UPDATE fracture_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r WHERE id=:id");
    $upd->execute([':r'=>$ref, ':id'=>$id]);
    return [true, $ref, null];
  }

  $detail = "HTTP=$code"; if ($apiStatus!==null) $detail.=" status=$apiStatus"; if ($apiMsg) $detail.=" msg=$apiMsg";
  $upd = $db->prepare("UPDATE fracture_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
  $upd->execute([':e'=>"MOPH error: $detail", ':id'=>$id]);
  return [false, null, "MOPH error: $detail"];
}

// ====== Execute action ======
try {
  if ($action === 'requeue') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("UPDATE fracture_queue SET status=0, attempt=0,last_attempt_at=NULL, last_error=NULL, out_ref=NULL, line_message_id=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: fracture_queue_ui.php?msg=requeued&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'clear_error') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("UPDATE fracture_queue SET last_error=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: fracture_queue_ui.php?msg=cleared&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'send_now') {
    $ok=0; $fail=0;
    foreach ($ids as $id) {
      [$o,$r,$e] = send_one_now($dbcon, (int)$id);
      if ($o) $ok++; else $fail++;
    }
    header('Location: fracture_queue_ui.php?msg=sendnow&ok='.$ok.'&fail='.$fail); exit;

  } else {
    header('Location: fracture_queue_ui.php?msg=bad_action'); exit;
  }
} catch (Throwable $e) {
  header('Location: fracture_queue_ui.php?msg=err&detail='.urlencode($e->getMessage()));
}
