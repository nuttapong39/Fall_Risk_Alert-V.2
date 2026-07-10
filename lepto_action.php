<?php
/**
 * lepto_action.php
 * AJAX action handler สำหรับ Leptospira.php
 *
 * POST action=send  vn=<VN>
 *   → query HOSxP ด้วย VN + lab_items_code '290'
 *   → ส่ง LINE Flex ผ่าน MOPH Alert API
 *   → return JSON { ok, msg, ref }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_disease.php';
// require_once __DIR__ . '/auth_guard.php';

date_default_timezone_set('Asia/Bangkok');

// ── Constants ─────────────────────────────────────────────────────────────────
define('LEPTO_LAB_CODE', '290');
define('LEPTO_TYPE',     'lepto');

if (!defined('MOPH_API_URL'))   define('MOPH_API_URL',   'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT'))   define('MOPH_TIMEOUT',   30);
if (!defined('LEPTO_CLIENT_KEY'))
  define('LEPTO_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('LEPTO_SECRET_KEY'))
  define('LEPTO_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

// ── Logging ───────────────────────────────────────────────────────────────────
$LOG_DIR  = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . '/moph_alert_lepto.log';

function lepto_log(array $row, $code, $resp, $err = null): void {
  global $LOG_FILE;
  $line = sprintf("[%s] vn=%s hn=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'),
    $row['vn'] ?? '-',
    $row['hn'] ?? '-',
    $code,
    $err ?: '-',
    mb_substr($resp ?? '', 0, 2000)
  );
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

// ── Validate request ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); exit('Method not allowed');
}

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';
$vn     = trim($_POST['vn'] ?? '');

if ($action !== 'send' || $vn === '') {
  echo json_encode(['ok'=>false, 'msg'=>'Invalid request']);
  exit;
}

// ── Query HOSxP ───────────────────────────────────────────────────────────────
try {
  require_once __DIR__ . '/sources/lepto_source.php';
  $row = lepto_source_by_vn($vn, LEPTO_LAB_CODE);

  if (!$row) {
    echo json_encode(['ok'=>false,
      'msg'=>"ไม่พบข้อมูล VN: {$vn} (lab_items_code=".LEPTO_LAB_CODE.")"]);
    exit;
  }

  $row = row_to_utf8($row);

  // ── Build Flex payload ────────────────────────────────────────────────────
  $payload = buildDiseasePayload($row, LEPTO_TYPE);
  $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

  if ($body === false) {
    $jsonErr = json_last_error_msg();
    lepto_log($row, 0, null, "JSON_ENCODE_FAIL: $jsonErr");
    echo json_encode(['ok'=>false, 'msg'=>"JSON encode failed: $jsonErr"]);
    exit;
  }

  // ── Send via MOPH Alert ───────────────────────────────────────────────────
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
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  lepto_log($row, $code, $resp, $err);

  if ($err) {
    echo json_encode(['ok'=>false, 'msg'=>"CURL error: $err"]);
    exit;
  }

  $json      = json_decode($resp, true);
  $mid       = extract_moph_message_id($json);
  $apiStatus = is_array($json) && isset($json['status'])  ? $json['status']  : null;
  $apiMsg    = is_array($json) && isset($json['message']) ? (string)$json['message'] : null;
  $ok        = $mid
            || (is_numeric($apiStatus) && (int)$apiStatus === 200)
            || ($apiMsg && preg_match('/succ(e|)ss/i', $apiMsg));

  if (($code >= 200 && $code < 300) && $ok) {
    $ref = $mid ?: ($apiStatus ? "status:$apiStatus" : "HTTP$code");
    require_once __DIR__ . '/telegram_lib.php';
    telegram_mirror('lepto', '💧 แจ้งเตือนผู้ป่วยเลปโตสไปโรสิส', $row);
    echo json_encode(['ok'=>true, 'msg'=>'ส่งสำเร็จ', 'ref'=>$ref]);
  } else {
    $detail = "HTTP=$code";
    if ($apiStatus !== null) $detail .= " status=$apiStatus";
    if ($apiMsg)             $detail .= " msg=$apiMsg";
    echo json_encode(['ok'=>false, 'msg'=>"MOPH error: $detail"]);
  }

} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'msg'=>'เกิดข้อผิดพลาด: '.$e->getMessage()]);
}
