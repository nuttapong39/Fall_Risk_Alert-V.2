<?php
require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

/* ===================== Token (FIX: make same as UI) ===================== */
/*
  UI ของคุณสร้าง token จาก __FILE__ ของ accident_queue_ui.php
  ดังนั้น action ต้องสร้าง token ด้วย seed เดียวกัน ไม่ใช่ __FILE__ ของ action เอง
*/
if (!defined('ACCIDENT_UI_ACTION_TOKEN')) {
  $uiFile = __DIR__ . DIRECTORY_SEPARATOR . 'accident_queue_ui.php';
  define('ACCIDENT_UI_ACTION_TOKEN', hash('sha256', $uiFile . php_uname() . date('Y-m-d')));
}
if (!isset($_POST['token']) || $_POST['token'] !== ACCIDENT_UI_ACTION_TOKEN) {
  http_response_code(403); exit('Forbidden');
}

$action = $_POST['action'] ?? '';
$ids    = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
$ids    = array_values(array_filter($ids, fn($x)=>ctype_digit((string)$x)));
if (!$ids) { header('Location: accident_queue_ui.php?msg=no_ids'); exit; }

/* ===================== CONFIG (เหมือน accident.php) ===================== */
if (!defined('MOPH_API_URL')) define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);
if (!defined('MOPH_CONNECT_TIMEOUT')) define('MOPH_CONNECT_TIMEOUT', 10);

if (!defined('ACCIDENT_CLIENT_KEY')) define('ACCIDENT_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('ACCIDENT_SECRET_KEY')) define('ACCIDENT_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

if (!defined('ACCIDENT_TITLE'))         define('ACCIDENT_TITLE', 'ผู้ป่วย พ.ร.บ./ประกันสังคม (ต่างจังหวัด)');
if (!defined('ACCIDENT_HEADER_URL'))    define('ACCIDENT_HEADER_URL', 'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
if (!defined('ACCIDENT_ICON_URL'))      define('ACCIDENT_ICON_URL',   'https://www.ckhospital.net/home/PDF/Logo_ck.png');
if (!defined('ACCIDENT_HOSPITAL_NAME')) define('ACCIDENT_HOSPITAL_NAME', 'โรงพยาบาลเชียงกลาง');

/* ===================== LOG ===================== */
$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$SEND_LOG = $LOG_DIR . '/moph_alert_accident.log';

// ===== Logging (Accident) =====
function acc_logdir(){
  $dir = __DIR__ . '/logs';
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}
function acc_logfile_moph(){
  return acc_logdir() . '/moph_alert_accident.log';
}
function acc_logline($file, $line){
  $ts = date('Y-m-d H:i:s');
  @file_put_contents($file, "[$ts] $line\n", FILE_APPEND | LOCK_EX);
}

/**
 * log รูปแบบเดียวกับ fracture:
 * [YYYY-mm-dd HH:ii:ss] id=.. hn=.. http=.. err=- resp=...
 */
function acc_log_send_result($id, $hn, $httpCode, $err, $respJson){
  $errTxt  = ($err === null || $err === '' ? '-' : $err);
  $respTxt = is_string($respJson) ? $respJson : json_encode($respJson, JSON_UNESCAPED_UNICODE);
  acc_logline(acc_logfile_moph(), "id={$id} hn={$hn} http={$httpCode} err={$errTxt} resp={$respTxt}");
}


/* ===================== UTF-8 helpers ===================== */
function to_utf8($s){
  if ($s === null || $s === '' || !is_string($s)) return $s;
  if (mb_check_encoding($s, 'UTF-8')) return $s;
  foreach (['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc){
    $t = @iconv($enc, 'UTF-8//IGNORE', $s);
    if ($t !== false && $t !== '' && mb_check_encoding($t, 'UTF-8')) return $t;
    $t = @mb_convert_encoding($s, 'UTF-8', $enc);
    if ($t !== false && $t !== '' && mb_check_encoding($t, 'UTF-8')) return $t;
  }
  $t = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
  return $t !== false ? $t : $s;
}
function row_to_utf8(array $r): array {
  foreach($r as $k=>$v){ if (is_string($v)) $r[$k] = to_utf8($v); }
  return $r;
}

/* ===================== MOPH helpers ===================== */
function extract_moph_message_id($json){
  if (!is_array($json)) return null;
  $paths = [
    ['messageId'],
    ['data','messageId'],
    ['result','messageId'],
    ['messages',0,'messageId'],
    ['messages',0,'id'],
  ];
  foreach ($paths as $path){
    $t = $json;
    foreach ($path as $k){
      if (is_array($t) && array_key_exists($k,$t)) $t = $t[$k];
      else { $t = null; break; }
    }
    if (is_scalar($t) && $t!=='') return (string)$t;
  }
  return null;
}
function log_send($row, $code, $resp, $err=null){
  global $SEND_LOG;
  $line = sprintf(
    "[%s] id=%s an=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'),
    $row['id'] ?? '-',
    $row['an'] ?? '-',
    $code,
    $err ?: '-',
    mb_substr($resp ?? '', 0, 2000)
  );
  @file_put_contents($SEND_LOG, $line, FILE_APPEND);
}

/* ===================== Logs helpers ===================== */
function acc_task_echo($msg){
  echo "[".date('Y-m-d H:i:s')."] ".$msg.PHP_EOL;
}
  
/* =========================================================
 *  Modern Flex (same style as fracture.php latest)
 * ========================================================= */
if (!function_exists('ac_icon')) {
  function ac_icon(string $key): string {
    $map = [
      'hn'=>"🏥", 'fullname'=>"🧑‍⚕️", 'an'=>"🏨",
      'admit'=>"📅", 'pttype'=>"🏷️", 'pttname'=>"💳",
      'hospital'=>"🏣",
    ];
    return $map[$key] ?? "•";
  }
}
if (!function_exists('ac_row')) {
  function ac_row(string $icon, string $label, ?string $value, bool $highlight=false): array {
    $val = ($value === null || $value === '') ? '-' : (string)$value;

    $row = [
      "type"=>"box","layout"=>"horizontal","spacing"=>"md","margin"=>"sm",
      "contents"=>[
        [ "type"=>"text","text"=>$icon,"size"=>"sm","flex"=>0,"align"=>"start" ],
        [
          "type"=>"box","layout"=>"vertical","flex"=>1,"contents"=>[
            [ "type"=>"text","text"=>$label,"size"=>"sm","color"=>"#6B7280","weight"=>"bold" ],
            [ "type"=>"text","text"=>$val,"size"=>"md","color"=>"#111827","wrap"=>true ]
          ]
        ]
      ]
    ];

    if ($highlight) {
      $row = [
        "type"=>"box","layout"=>"vertical","margin"=>"sm","paddingAll"=>"10px",
        "cornerRadius"=>"12px","backgroundColor"=>"#F3F4F6","contents"=>[$row]
      ];
    }
    return $row;
  }
}
if (!function_exists('ac_divider')) {
  function ac_divider(string $margin="sm"): array {
    return [ "type"=>"separator","margin"=>$margin,"color"=>"#E5E7EB" ];
  }
}
if (!function_exists('ac_badge')) {
  function ac_badge(string $text, string $bg="#2563EB"): array {
    return [
      "type"=>"box","layout"=>"baseline","margin"=>"xs",
      "backgroundColor"=>$bg,"cornerRadius"=>"14px","paddingAll"=>"6px",
      "contents"=>[[
        "type"=>"text","text"=>$text,"color"=>"#FFFFFF","weight"=>"bold",
        "align"=>"center","size"=>"sm","wrap"=>true,"flex"=>1
      ]]
    ];
  }
}

function buildAccidentPayload(array $row): array {
  $row = row_to_utf8($row);

  $titleText = defined('ACCIDENT_TITLE') ? ACCIDENT_TITLE : 'ผู้ป่วย พ.ร.บ./ประกันสังคม (ต่างจังหวัด)';
  $headerUrl = defined('ACCIDENT_HEADER_URL') ? ACCIDENT_HEADER_URL : '';
  $logoUrl   = defined('ACCIDENT_ICON_URL') ? ACCIDENT_ICON_URL : '';
  $hospName  = defined('ACCIDENT_HOSPITAL_NAME') ? ACCIDENT_HOSPITAL_NAME : 'โรงพยาบาล';

  $admit = trim(($row['regdate'] ?? '').' '.($row['regtime'] ?? ''));
  if ($admit === '') $admit = '-';

  $header = [
    "type"=>"box","layout"=>"vertical","paddingAll"=>"0px",
    "contents"=>$headerUrl ? [[
      "type"=>"image","url"=>$headerUrl,"size"=>"full",
      "aspectRatio"=>"3120:885","aspectMode"=>"cover"
    ]] : []
  ];

  $titleRow = [
    "type"=>"box","layout"=>"horizontal","margin"=>"md","contents"=>[
      [
        "type"=>"box","layout"=>"vertical","flex"=>3,"contents"=>[
          [ "type"=>"text","text"=>$titleText,"weight"=>"bold","size"=>"xl","color"=>"#1F2937","wrap"=>true ],
          ac_badge("แจ้งเตือน: พ.ร.บ./ประกันสังคม (ต่างจังหวัด)", "#2563EB")
        ]
      ],
      $logoUrl
        ? [ "type"=>"image","url"=>$logoUrl,"flex"=>1,"size"=>"sm","align"=>"end","gravity"=>"center" ]
        : [ "type"=>"filler","flex"=>1 ]
    ]
  ];

  $rows = [];
  $pairs = [
    [ac_icon('fullname'), 'ชื่อ-สกุล',     $row['fullname'] ?? '-'],
    [ac_icon('hn'),       'HN',            $row['hn'] ?? '-'],
    [ac_icon('an'),       'AN',            $row['an'] ?? '-'],
    [ac_icon('admit'),    'วันที่ Admit',  $admit],
    [ac_icon('pttype'),   'รหัสสิทธิ',     $row['pttype'] ?? '-', true],
    [ac_icon('pttname'),  'สิทธิ',         $row['pttname'] ?? '-'],
    [ac_icon('hospital'), 'หน่วยบริการ',   $hospName],
  ];

  foreach ($pairs as $i => $item) {
    $icon=$item[0]; $label=$item[1]; $val=$item[2]; $hl=$item[3]??false;
    if ($i>0) $rows[] = ac_divider();
    $rows[] = ac_row($icon, $label, $val, $hl);
  }

  $card = [
    "type"=>"box","layout"=>"vertical","cornerRadius"=>"14px","paddingAll"=>"12px",
    "backgroundColor"=>"#FFFFFF","borderColor"=>"#E5E7EB","borderWidth"=>"1px",
    "contents"=>$rows
  ];

  $stamp = [
    "type"=>"box","layout"=>"horizontal","margin"=>"md",
    "contents"=>[[
      "type"=>"text","text"=>date('Y-m-d H:i'),"size"=>"xs",
      "color"=>"#9CA3AF","align"=>"end","flex"=>1
    ]]
  ];

  return [
    "messages"=>[[
      "type"=>"flex",
      "altText"=>"Accident/PRB-SSO Alert",
      "contents"=>[
        "type"=>"bubble","size"=>"mega",
        "header"=>$header,
        "body"=>[
          "type"=>"box","layout"=>"vertical","spacing"=>"sm",
          "contents"=>[ $titleRow, ac_divider("md"), $card, $stamp ]
        ],
        "styles"=>[ "body"=>[ "backgroundColor"=>"#F9FAFB" ] ]
      ]
    ]]
  ];
}

function send_one_now(PDO $db, int $id): array {
  $get = $db->prepare("SELECT * FROM accident_queue WHERE id=:id");
  $get->execute([':id'=>$id]);
  $row = $get->fetch(PDO::FETCH_ASSOC);
  if (!$row) return [false, null, "id not found"];
  $row = row_to_utf8($row);

  $payload = buildAccidentPayload($row);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false) {
    $jsonErr = json_last_error_msg();
    log_send($row, 0, null, "JSON_ENCODE_FAIL: ".$jsonErr);
    $upd = $db->prepare("UPDATE accident_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
    $upd->execute([':e'=>"JSON encode failed: ".$jsonErr, ':id'=>$id]);
    return [false, null, "JSON encode failed: ".$jsonErr];
  }

  $headers = [
    'client-key: ' . ACCIDENT_CLIENT_KEY,
    'secret-key: ' . ACCIDENT_SECRET_KEY,
    'Content-Type: application/json; charset=UTF-8',
    'Accept: application/json',
    'Expect:'
  ];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => MOPH_API_URL,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => MOPH_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => MOPH_CONNECT_TIMEOUT,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  log_send($row, $code, $resp, $err);

  if ($err) {
    $upd = $db->prepare("UPDATE accident_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
    $upd->execute([':e'=>"CURL: $err", ':id'=>$id]);
    return [false, null, "CURL: $err"];
  }

  $json = @json_decode($resp, true);
  $mid  = extract_moph_message_id($json);
  $st   = is_array($json) && array_key_exists('status',$json) ? $json['status'] : null;
  $msg  = is_array($json) && array_key_exists('message',$json) ? (string)$json['message'] : null;

  $looksSuccess = ($mid) || (is_numeric($st) && (int)$st===200) || ($msg && preg_match('/succ(e|)ss/i', $msg));
  if (($code>=200 && $code<300) && $looksSuccess) {
    $ref = $mid ?: ($st ? "status:$st" : 'HTTP'.$code);
    $upd = $db->prepare("
      UPDATE accident_queue
      SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1,
          last_error=NULL, out_ref=:r, line_message_id=:r
      WHERE id=:id
    ");
    $upd->execute([':r'=>$ref, ':id'=>$id]);
    return [true, $ref, null];
  }

  $detail = "HTTP=$code";
  if ($st !== null) $detail .= " status=$st";
  if ($msg)         $detail .= " msg=$msg";

  $upd = $db->prepare("UPDATE accident_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
  $upd->execute([':e'=>"MOPH error: $detail", ':id'=>$id]);
  return [false, null, "MOPH error: $detail"];
}

/* ===================== Execute action ===================== */
try {
  if ($action === 'requeue') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("
      UPDATE accident_queue
      SET status=0, attempt=0, last_attempt_at=NULL, last_error=NULL,
          out_ref=NULL, line_message_id=NULL, sent_at=NULL
      WHERE id IN ($place)
    ");
    $stmt->execute($ids);
    header('Location: accident_queue_ui.php?msg=requeued&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'clear_error') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("UPDATE accident_queue SET last_error=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: accident_queue_ui.php?msg=cleared&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'send_now') {
    $ok=0; $fail=0;
    foreach ($ids as $id) {
      [$o,$r,$e] = send_one_now($dbcon, (int)$id);
      if ($o) $ok++; else $fail++;
      usleep(random_int(10,80)*1000);
    }
    header('Location: accident_queue_ui.php?msg=sendnow&ok='.$ok.'&fail='.$fail); exit;

  } else {
    header('Location: accident_queue_ui.php?msg=bad_action'); exit;
  }
} catch (Throwable $e) {
  header('Location: accident_queue_ui.php?msg=err&detail='.urlencode($e->getMessage()));
}
