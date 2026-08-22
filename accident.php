<?php
/**
 * accident.php — แจ้งเตือนผู้ป่วยอุบัติเหตุ พ.ร.บ./ประกันสังคม (ต่างจังหวัด)
 * STEP 1: Ingest -> ใส่ accident_queue (กันซ้ำด้วย ON DUPLICATE KEY)
 * STEP 2: Send   -> ส่ง Flex ผ่าน MOPH Alert + อัปเดตสถานะ
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

/* ===================== CONFIG ===================== */
if (!defined('MOPH_API_URL')) define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);
if (!defined('MOPH_CONNECT_TIMEOUT')) define('MOPH_CONNECT_TIMEOUT', 10);

if (!defined('ACCIDENT_CLIENT_KEY')) define('ACCIDENT_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('ACCIDENT_SECRET_KEY')) define('ACCIDENT_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

if (!defined('ACCIDENT_TITLE'))            define('ACCIDENT_TITLE', 'ผู้ป่วย พ.ร.บ./ประกันสังคม (ต่างจังหวัด)');
if (!defined('ACCIDENT_HEADER_URL'))       define('ACCIDENT_HEADER_URL', 'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
if (!defined('ACCIDENT_ICON_URL'))         define('ACCIDENT_ICON_URL',   'https://www.ckhospital.net/home/PDF/Logo_ck.png');
if (!defined('ACCIDENT_HOSPITAL_NAME'))    define('ACCIDENT_HOSPITAL_NAME', 'โรงพยาบาลเชียงกลาง');

if (!defined('ACCIDENT_LOOKBACK_DAYS'))       define('ACCIDENT_LOOKBACK_DAYS', 7);
if (!defined('ACCIDENT_RESEND_COOLDOWN_MIN')) define('ACCIDENT_RESEND_COOLDOWN_MIN', 1);
if (!defined('ACCIDENT_MAX_ATTEMPTS'))        define('ACCIDENT_MAX_ATTEMPTS', 8);
if (!defined('ACCIDENT_BATCH_LIMIT'))         define('ACCIDENT_BATCH_LIMIT', 50);

// สิทธิ พ.ร.บ./ประกันสังคม ต่างจังหวัด — default จาก store (แก้ผ่าน modal ในหน้า queue_ui)
if (!defined('ACCIDENT_PTTYPES')) define('ACCIDENT_PTTYPES',
  function_exists('module_filter') ? implode(',', module_filter('accident')['pttypes']) : '33,35,36,39');

// LOG
$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$RUN_LOG  = $LOG_DIR.'/accident_task_run.log';
$SEND_LOG = $LOG_DIR.'/moph_alert_accident.log';

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

/* ===================== Utils ===================== */
function runlog($t){ global $RUN_LOG; @file_put_contents($RUN_LOG,'['.date('Y-m-d H:i:s')."] $t\n",FILE_APPEND); if(PHP_SAPI==='cli') echo '['.date('Y-m-d H:i:s')."] $t\n"; }
function log_send($row,$code,$resp,$err=null){ global $SEND_LOG; $line=sprintf("[%s] id=%s an=%s http=%s err=%s resp=%s\n",date('Y-m-d H:i:s'),$row['id']??'-',$row['an']??'-',$code,$err?:'-',mb_substr($resp??'',0,2000)); @file_put_contents($SEND_LOG,$line,FILE_APPEND); if(PHP_SAPI==='cli') echo $line; }

function to_utf8($s){
  if ($s===null || $s==='' || !is_string($s)) return $s;
  if (mb_check_encoding($s,'UTF-8')) return $s;
  foreach(['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc){
    $t=@iconv($enc,'UTF-8//IGNORE',$s); if($t!==false && $t!==''){ if(mb_check_encoding($t,'UTF-8')) return $t; }
    $t=@mb_convert_encoding($s,'UTF-8',$enc); if($t!==false && $t!==''){ if(mb_check_encoding($t,'UTF-8')) return $t; }
  }
  $t=@iconv('UTF-8','UTF-8//IGNORE',$s);
  return $t!==false ? $t : $s;
}
function row_to_utf8(array $r){ foreach($r as $k=>$v){ if(is_string($v)) $r[$k]=to_utf8($v); } return $r; }

function readParam($k,$d=null){
  if(PHP_SAPI==='cli'){ static $args; if($args===null) $args=getopt('',['start::','end::','pttypes::','n::','dry-run']); if($k==='dry-run') return array_key_exists('dry-run',$args); return $args[$k]??$d; }
  else { if($k==='dry-run') return isset($_GET['dry-run']); return $_GET[$k]??$d; }
}
function normalize_date_ymd($d,$fb){
  if(!is_string($d)||$d==='') return $fb;
  if(preg_match('/^\s*(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\s*$/',$d,$m)){
    $y=(int)$m[1]; $mo=(int)$m[2]; $da=(int)$m[3]; if($y>2400) $y-=543;
    if($y<1900||$y>2100||$mo<1||$mo>12||$da<1||$da>31) return $fb;
    return sprintf('%04d-%02d-%02d',$y,$mo,$da);
  } return $fb;
}
function parse_pttypes_list($s){
  if(!is_string($s)||$s==='') $s=(string)ACCIDENT_PTTYPES;
  $a=array_values(array_filter(array_map('trim',explode(',',$s)),fn($x)=>$x!==''));
  $a=array_map(fn($x)=>preg_replace('/[^A-Z0-9]/i','',$x),$a);
  return array_values(array_unique($a));
}
function extract_moph_message_id($json){
  if(!is_array($json)) return null;
  foreach([['messageId'],['data','messageId'],['result','messageId'],['messages',0,'messageId'],['messages',0,'id']] as $p){
    $t=$json; foreach($p as $k){ if(is_array($t)&&array_key_exists($k,$t)) $t=$t[$k]; else { $t=null; break; } }
    if(is_scalar($t)&&$t!=='') return (string)$t;
  } return null;
}

/* ===== Flex helpers ===== */
/* ===== Flex helpers: icons + compact rows + divider + alert badge (Modern like fracture) ===== */
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

/* ===== NEW: Modern Flex payload (same style as fracture.php latest) ===== */
function buildAccidentPayload(array $row): array {
  $row = row_to_utf8($row);

  $titleText = defined('ACCIDENT_TITLE') ? ACCIDENT_TITLE : 'ผู้ป่วย พ.ร.บ./ประกันสังคม (ต่างจังหวัด)';
  $headerUrl = defined('ACCIDENT_HEADER_URL') ? ACCIDENT_HEADER_URL : '';
  $logoUrl   = defined('ACCIDENT_ICON_URL') ? ACCIDENT_ICON_URL : '';
  $hospName  = defined('ACCIDENT_HOSPITAL_NAME') ? ACCIDENT_HOSPITAL_NAME : 'โรงพยาบาล';

  $admit = trim(($row['regdate'] ?? '').' '.($row['regtime'] ?? ''));
  if ($admit === '') $admit = '-';

  /* Header banner */
  $header = [
    "type"=>"box","layout"=>"vertical","paddingAll"=>"0px",
    "contents"=>$headerUrl ? [[
      "type"=>"image","url"=>$headerUrl,"size"=>"full",
      "aspectRatio"=>"3120:885","aspectMode"=>"cover"
    ]] : []
  ];

  /* Title row (left) + right logo + badge */
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

  /* Card rows */
  $rows = [];
  $pairs = [
    [ac_icon('fullname'), 'ชื่อ-สกุล',     $row['fullname'] ?? '-'],
    [ac_icon('hn'),       'HN',            $row['hn'] ?? '-'],
    [ac_icon('an'),       'AN',            $row['an'] ?? '-'],
    [ac_icon('admit'),    'วันที่ Admit',  $admit],
    // ไฮไลต์สิทธิให้เด่น
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


/* ===== ส่งผ่าน MOPH Alert ===== */
function send_via_moph_alert_accident(array $row): array {
  $payload = buildAccidentPayload($row);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false){ $e=json_last_error_msg(); return [false,null,"JSON encode failed: $e"]; }

  $headers = [
    'client-key: ' . ACCIDENT_CLIENT_KEY,
    'secret-key: ' . ACCIDENT_SECRET_KEY,
    'Content-Type: application/json; charset=UTF-8',
    'Accept: application/json',
    'Expect:' // ปิด 100-continue
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

  log_send($row,$code,$resp,$err);

  // retry รอบเดียวเฉพาะ timeout
  if ($err && stripos($err,'timed out')!==false){
    usleep(200000);
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL            => MOPH_API_URL,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CUSTOMREQUEST  => 'POST',
      CURLOPT_POSTFIELDS     => $body,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_TIMEOUT        => 60,
      CURLOPT_CONNECTTIMEOUT => 12,
      CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
    $resp2 = curl_exec($ch);
    $err2  = curl_error($ch);
    $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    log_send($row,$code2,$resp2,$err2);
    if(!$err2){ $resp=$resp2; $err=null; $code=$code2; }
  }

  if ($err) return [false,null,"CURL: $err"];

  $json = @json_decode($resp,true);
  $mid  = extract_moph_message_id($json);
  $st   = is_array($json)&&array_key_exists('status',$json)?$json['status']:null;
  $msg  = is_array($json)&&array_key_exists('message',$json)?(string)$json['message']:null;
  $ok   = ($mid) || (is_numeric($st)&&(int)$st===200) || ($msg && preg_match('/succ(e|)ss/i',$msg));
  if (($code>=200 && $code<300) && $ok){
    $ref = $mid ?: ($st ? "status:$st" : 'HTTP'.$code);
    return [true,$ref,null];
  }
  $detail="HTTP=$code"; if($st!==null) $detail.=" status=$st"; if($msg) $detail.=" msg=$msg";
  return [false,null,"MOPH error: $detail"];
}

/* ===================== Params ===================== */
$start   = readParam('start', date('Y-m-d', strtotime('-'.ACCIDENT_LOOKBACK_DAYS.' days')));
$end     = readParam('end',   date('Y-m-d'));
$pttypes = readParam('pttypes', (string)ACCIDENT_PTTYPES);
$limitN  = (int)readParam('n', ACCIDENT_BATCH_LIMIT);
$dryRun  = readParam('dry-run', false);

$today = date('Y-m-d');
$defS  = date('Y-m-d', strtotime('-'.ACCIDENT_LOOKBACK_DAYS.' days'));
$start = normalize_date_ymd($start,$defS);
$end   = normalize_date_ymd($end,$today);
if(strtotime($start)===false || strtotime($end)===false || $start>$end){ $start=$defS; $end=$today; }
$ptList = parse_pttypes_list($pttypes);
if(!$ptList){ $ptList = parse_pttypes_list((string)ACCIDENT_PTTYPES); }
if($limitN<=0) $limitN=(int)ACCIDENT_BATCH_LIMIT;

runlog("Effective range: $start -> $end (pttypes=".implode(',',$ptList).")");

/* ===================== Guard: กันรันซ้อน ===================== */
try { $gotLock = (int)$dbcon->query("SELECT GET_LOCK('accident_send_lock', 1)")->fetchColumn(); }
catch(Throwable $e){ $gotLock = 1; }
if($gotLock!==1){
  runlog("skip: another instance is running");
  if(PHP_SAPI!=='cli') echo "<pre>skip: another instance is running</pre>";
  return;
}

/* ===================== STEP 1: Ingest ===================== */
require_once __DIR__ . '/sources/accident_source.php';
$newRows = accident_source_ipt_rows($start, $end, $ptList) ?: [];
runlog("Ingest: found ".count($newRows)." new rows.");

if($newRows){
  $ins = $dbcon->prepare("
    INSERT INTO accident_queue
      (hn, an, regdate, regtime, pttype, pttname, fullname, status, attempt, created_at)
    VALUES
      (:hn,:an,:regdate,:regtime,:pttype,:pttname,:fullname,0,0,NOW())
    ON DUPLICATE KEY UPDATE an=an
  ");
  foreach($newRows as $r){
    $ins->execute([
      ':hn'       => $r['hn'],
      ':an'       => $r['an'],
      ':regdate'  => $r['regdate'],
      ':regtime'  => $r['regtime'],
      ':pttype'   => $r['pttype'],
      ':pttname'  => $r['pttname'],
      ':fullname' => $r['fullname'],
    ]);
  }
}

/* ===================== STEP 2: Send ===================== */
$cool   = (int)ACCIDENT_RESEND_COOLDOWN_MIN;
$maxTry = (int)ACCIDENT_MAX_ATTEMPTS;

$getQ = $dbcon->prepare("
  SELECT * FROM accident_queue
  WHERE status=0
    AND (last_attempt_at IS NULL OR TIMESTAMPDIFF(MINUTE,last_attempt_at,NOW()) >= :cd)
    AND attempt < :mx
  ORDER BY (last_attempt_at IS NULL) DESC, last_attempt_at ASC, created_at ASC
  LIMIT :lim
");
$getQ->bindValue(':cd',$cool,PDO::PARAM_INT);
$getQ->bindValue(':mx',$maxTry,PDO::PARAM_INT);
$getQ->bindValue(':lim',$limitN,PDO::PARAM_INT);
$getQ->execute();
$queue = $getQ->fetchAll() ?: [];
runlog("Send: to process ".count($queue)." rows (cooldown={$cool}m, maxTry={$maxTry}).");

$okU=$dbcon->prepare("UPDATE accident_queue SET status=1,sent_at=NOW(),last_attempt_at=NOW(),attempt=attempt+1,last_error=NULL,out_ref=:r,line_message_id=:r WHERE id=:id");
$ngU=$dbcon->prepare("UPDATE accident_queue SET last_attempt_at=NOW(),attempt=attempt+1,last_error=:e WHERE id=:id");

$ok=0; $fail=0;
foreach($queue as $row){
  if($dryRun){ runlog("DRY-RUN: would send id={$row['id']} an={$row['an']}"); continue; }
  usleep(random_int(10,80)*1000);
  [$sent,$ref,$err] = send_via_moph_alert_accident($row);
  if($sent){ $okU->execute([':r'=>$ref,':id'=>$row['id']]); $ok++; runlog("OK id={$row['id']} ref=".($ref??'-'));
  }else{ $ngU->execute([':e'=>$err,':id'=>$row['id']]); $fail++; runlog("FAIL id={$row['id']} err=$err"); }
}
runlog("send: ok=$ok fail=$fail");

// ปลดล็อค
try { $dbcon->query("DO RELEASE_LOCK('accident_send_lock')"); } catch(Throwable $e){}

if(PHP_SAPI!=='cli'){
  echo "<pre>Done: start={$start} end={$end} pttypes=".htmlspecialchars(implode(',',$ptList))." sent_ok={$ok} sent_fail={$fail}</pre>";
}
