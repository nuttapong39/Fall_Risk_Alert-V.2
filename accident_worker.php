<?php
/**
 * accident_worker.php — ส่งแจ้งเตือนอุบัติเหตุ (สิทธิ์ พ.ร.บ./ประกันสังคมต่างจังหวัด) ไป MOPH Alert
 * STEP 1: Ingest -> accident_queue
 * STEP 2: ส่ง Flex + อัปเดตสถานะ (มีคูลดาวน์/จำนวนครั้งสูงสุด)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

/* ===================== CONFIG / DEFAULTS ===================== */
if (!defined('MOPH_API_URL'))         define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT'))         define('MOPH_TIMEOUT', 30);

// ใช้คีย์เดียวกับ config.php (เหมือน fracture/covid)
if (!defined('ACCIDENT_CLIENT_KEY')) define('ACCIDENT_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('ACCIDENT_SECRET_KEY')) define('ACCIDENT_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

// ควบคุมนโยบายการส่งซ้ำ
if (!defined('ACCIDENT_RESEND_COOLDOWN_MIN')) define('ACCIDENT_RESEND_COOLDOWN_MIN', 1);
if (!defined('ACCIDENT_MAX_ATTEMPTS'))        define('ACCIDENT_MAX_ATTEMPTS', 8);
if (!defined('ACCIDENT_BATCH_LIMIT'))         define('ACCIDENT_BATCH_LIMIT', 50);

// default lookback
if (!defined('DEFAULT_LOOKBACK_DAYS')) define('DEFAULT_LOOKBACK_DAYS', 7);

// (ตัวเลือก) บังคับ IP ปลายทาง หากต้องการ pin IP ที่ดีไว้
if (!defined('MOPH_FORCE_IP')) define('MOPH_FORCE_IP', '');

// (ตัวเลือก) รายการ IP สำรองที่อยากให้ลองเพิ่มนอกเหนือจาก DNS (แก้ได้)
if (!defined('MOPH_SEED_IPS')) define('MOPH_SEED_IPS', '203.151.48.190,203.151.254.91,43.229.149.136');

/* ===================== LOG FILES ===================== */
$LOG_DIR  = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$RUN_LOG  = $LOG_DIR . '/accident_task_run.log';
$SEND_LOG = $LOG_DIR . '/moph_alert_accident.log';

function runlog($text){
  global $RUN_LOG;
  @file_put_contents($RUN_LOG, '['.date('Y-m-d H:i:s')."] $text\n", FILE_APPEND);
  if (PHP_SAPI==='cli') echo '['.date('Y-m-d H:i:s')."] $text\n";
}
function log_send($row,$code,$resp,$err=null,$note=''){
  global $SEND_LOG;
  $an  = $row['an']??'-';
  $id  = $row['id']??'-';
  $line = sprintf("[%s] id=%s an=%s http=%s err=%s %sresp=%s\n",
            date('Y-m-d H:i:s'), $id, $an, $code, $err?:'-',
            ($note!=='' ? $note.' ' : ''),
            mb_substr($resp??'',0,2000));
  @file_put_contents($SEND_LOG,$line,FILE_APPEND);
  if (PHP_SAPI==='cli') echo $line;
}

/* ===================== HELPERS (UTF-8/Params/Date) ===================== */
function to_utf8($s){
  if ($s===null || $s==='' || !is_string($s)) return $s;
  if (mb_check_encoding($s,'UTF-8')) return $s;
  foreach(['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc){
    $t=@iconv($enc,'UTF-8//IGNORE',$s); if($t!==false && $t!==''){ if(mb_check_encoding($t,'UTF-8')) return $t; }
    $t=@mb_convert_encoding($s,'UTF-8',$enc); if($t!==false && $t!==''){ if(mb_check_encoding($t,'UTF-8')) return $t; }
  }
  $t=@iconv('UTF-8','UTF-8//IGNORE',$s); return $t!==false ? $t : $s;
}
function row_to_utf8(array $r){ foreach($r as $k=>$v){ if(is_string($v)) $r[$k]=to_utf8($v); } return $r; }

function readParam($key,$default=null){
  if(PHP_SAPI==='cli'){
    static $args; if($args===null) $args=getopt('', ['start::','end::','dry-run']);
    if($key==='dry-run') return array_key_exists('dry-run',$args);
    return $args[$key]??$default;
  } else {
    if($key==='dry-run') return isset($_GET['dry-run']);
    return $_GET[$key]??$default;
  }
}
function normalize_date_ymd($d,$fallback){
  if(!is_string($d)||$d==='') return $fallback;
  if(preg_match('/^\s*(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\s*$/',$d,$m)){
    $y=(int)$m[1]; $mo=(int)$m[2]; $da=(int)$m[3];
    if($y>2400) $y-=543;
    if($y<1900||$y>2100||$mo<1||$mo>12||$da<1||$da>31) return $fallback;
    return sprintf('%04d-%02d-%02d',$y,$mo,$da);
  }
  return $fallback;
}

/* ===================== Flex Payload ===================== */
if (!defined('ACC_TITLE'))        define('ACC_TITLE', 'ผู้ป่วยอุบัติเหตุ (สิทธิ์ พ.ร.บ./ประกันสังคมต่างจังหวัด)');
if (!defined('FALL_HEADER_URL'))  define('FALL_HEADER_URL','https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
if (!defined('FALL_ICON_URL'))    define('FALL_ICON_URL','https://www.ckhospital.net/home/PDF/Logo_ck.png');

function makeTextBox($text){
  return [
    "type"=>"box","layout"=>"horizontal","margin"=>"8px",
    "contents"=>[["type"=>"text","text"=>$text,"size"=>"14.5px","align"=>"start","gravity"=>"center","wrap"=>true,"weight"=>"regular","flex"=>2]]
  ];
}
function buildAccidentPayload(array $r){
  $lines=[
    makeTextBox("คนไข้: ".($r['fullname']??'-')),
    makeTextBox("HN: ".($r['hn']??'-')."  AN: ".($r['an']??'-')),
    makeTextBox("วันที่ Admit: ".($r['regdate']??'-')." เวลา ".($r['regtime']??'-')),
    makeTextBox("สิทธิ์: ".($r['pttype']??'-').' '.($r['pttname']??'')),
  ];
  $body = array_merge([
    ["type"=>"box","layout"=>"vertical","margin"=>"8px","contents"=>[["type"=>"image","url"=>FALL_ICON_URL,"size"=>"full","aspectMode"=>"cover","align"=>"center"]],"cornerRadius"=>"100px","maxWidth"=>"72px","offsetStart"=>"93px"],
    ["type"=>"box","layout"=>"vertical","cornerRadius"=>"15px","margin"=>"xs","paddingTop"=>"lg","paddingBottom"=>"lg","paddingStart"=>"8px","paddingEnd"=>"8px","backgroundColor"=>"#DCE7FF","contents"=>[["type"=>"text","text"=>ACC_TITLE,"weight"=>"bold","size"=>"lg","align"=>"center","color"=>"#2D2D2D","adjustMode"=>"shrink-to-fit"]]],
    ["type"=>"box","layout"=>"vertical","margin"=>"sm","contents"=>[["type"=>"text","text"=>"-------------------------------------","weight"=>"bold","size"=>"14px","align"=>"center"]]],
  ], $lines, [
    ["type"=>"box","layout"=>"vertical","margin"=>"sm","contents"=>[["type"=>"text","text"=>"-------------------------------------","weight"=>"bold","size"=>"14px","align"=>"center"]]],
  ]);
  return [
    "messages"=>[[
      "type"=>"flex","altText"=>"Accident Alert",
      "contents"=>[
        "type"=>"bubble","size"=>"mega",
        "header"=>["type"=>"box","layout"=>"vertical","paddingAll"=>"0px","contents"=>[["type"=>"image","url"=>FALL_HEADER_URL,"size"=>"full","aspectRatio"=>"3120:885","aspectMode"=>"cover"]]],
        "body"=>["type"=>"box","layout"=>"vertical","contents"=>$body]
      ]
    ]]
  ];
}

/* ===================== JSON helpers ===================== */
function extract_moph_message_id($json){
  if(!is_array($json)) return null;
  $paths=[['messageId'],['data','messageId'],['result','messageId'],['messages',0,'messageId'],['messages',0,'id']];
  foreach($paths as $p){ $t=$json; foreach($p as $k){ if(is_array($t)&&array_key_exists($k,$t)) $t=$t[$k]; else { $t=null; break; } } if(is_scalar($t)&&$t!=='') return (string)$t; }
  return null;
}

/* ===================== SENDER (มี fallback + บันทึก primary_ip) ===================== */
function send_via_moph_alert_accident(array $row): array {
  $row = row_to_utf8($row);
  $payload = buildAccidentPayload($row);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false) {
    $jsonErr = json_last_error_msg();
    log_send($row, 0, null, "JSON_ENCODE_FAIL: ".$jsonErr);
    return [false, null, "JSON encode failed: ".$jsonErr];
  }

  $host   = parse_url(MOPH_API_URL, PHP_URL_HOST);
  $dnsIps = @gethostbynamel($host) ?: [];
  $seed   = array_values(array_filter(array_map('trim', explode(',', (string)MOPH_SEED_IPS))));
  $order  = [];

  // ถ้ามี force-ip → พยายามลองอันนี้ก่อน
  if (defined('MOPH_FORCE_IP') && MOPH_FORCE_IP) $order[] = MOPH_FORCE_IP;
  // ตามด้วย DNS ปัจจุบัน
  foreach ($dnsIps as $ip) $order[] = $ip;
  // ตามด้วย seed list
  foreach ($seed as $ip)   $order[] = $ip;

  // unique & เก็บลำดับแรกสุดไว้ก่อน
  $order = array_values(array_unique($order));

  // helper: ส่ง 1 ครั้ง (optionally fix IP)
  $doSend = function(?string $ipOverride) use ($body, $host, $row) {
    $ch = curl_init();
    $opts = [
      CURLOPT_URL            => MOPH_API_URL,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => MOPH_TIMEOUT,    // ให้พฤติกรรมเหมือน fracture/covid
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
    ];
    if ($ipOverride) {
      $opts[CURLOPT_RESOLVE] = [ $host.':443:'.$ipOverride ];
    }
    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $pIp  = curl_getinfo($ch, CURLINFO_PRIMARY_IP); // ip ที่ต่อจริง
    curl_close($ch);

    $note = 'primary_ip='.$pIp.($ipOverride? ' try_ip='.$ipOverride:'');
    log_send($row, $code, $resp, $err?:null, $note);

    return [$code,$resp,$err,$pIp];
  };

  /* 1) ยิงแบบปกติก่อน (ยกเว้นมี FORCE_IP จะลอง FORCE_IP ก่อน) */
  if (!MOPH_FORCE_IP) {
    [$code,$resp,$err, $pIp] = $doSend(null);
    if (!$err) {
      $json = json_decode($resp, true);
      $mid  = extract_moph_message_id($json);
      $apiS = is_array($json)&&array_key_exists('status',$json) ? $json['status'] : null;
      $apiM = is_array($json)&&array_key_exists('message',$json)? (string)$json['message'] : null;
      $looksSuccess = ($mid) || (is_numeric($apiS) && (int)$apiS===200) || ($apiM && preg_match('/succ(e|)ss/i',$apiM));
      if (($code>=200 && $code<300) && $looksSuccess) {
        $ref = $mid ?: ($apiS ? "status:$apiS" : 'HTTP'.$code);
        return [true, $ref, null];
      }
      $detail = "HTTP=$code"; if($apiS!==null)$detail.=" status=$apiS"; if($apiM)$detail.=" msg=$apiM";
      return [false, null, "MOPH error: $detail"];
    }
  }

  /* 2) ถ้า error/timeout → ลองทีละ IP จาก $order */
  foreach ($order as $ip) {
    [$code,$resp,$err, $pIp] = $doSend($ip);
    if ($err) continue;

    $json = json_decode($resp, true);
    $mid  = extract_moph_message_id($json);
    $apiS = is_array($json)&&array_key_exists('status',$json) ? $json['status'] : null;
    $apiM = is_array($json)&&array_key_exists('message',$json)? (string)$json['message'] : null;
    $looksSuccess = ($mid) || (is_numeric($apiS) && (int)$apiS===200) || ($apiM && preg_match('/succ(e|)ss/i',$apiM));
    if (($code>=200 && $code<300) && $looksSuccess) {
      $ref = $mid ?: ($apiS ? "status:$apiS" : 'HTTP'.$code);
      return [true, $ref, null];
    }
    $detail = "HTTP=$code"; if($apiS!==null)$detail.=" status=$apiS"; if($apiM)$detail.=" msg=$apiM";
    return [false, null, "MOPH error: $detail"];
  }

  return [false, null, "CURL: timeout/no response"];
}

/* ===================== PARAMS & DATE RANGE ===================== */
$start = readParam('start', date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days')));
$end   = readParam('end',   date('Y-m-d'));
$dry   = readParam('dry-run', false);

$today        = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days'));
$start = normalize_date_ymd($start,$defaultStart);
$end   = normalize_date_ymd($end,$today);
if(strtotime($start)===false || strtotime($end)===false || $start>$end){ $start=$defaultStart; $end=$today; }

runlog("Effective range: $start -> $end");

/* ===================== GLOBAL DB LOCK ===================== */
try{ $gotLock = (int)$dbcon->query("SELECT GET_LOCK('accident_send_lock', 1)")->fetchColumn(); }
catch(Throwable $e){ $gotLock=1; }
if($gotLock!==1){ runlog("skip: another instance running"); if(PHP_SAPI!=='cli') echo "<pre>skip: another instance</pre>"; return; }

/* ===================== STEP 1: INGEST ===================== */
// เกณฑ์: ipt.regdate ช่วงวัน + pttype IN (33,35,36,39)
$where  = ["ipt.regdate BETWEEN :s AND :e","ipt.pttype IN ('33','35','36','39')"];
$params = [":s"=>$start, ":e"=>$end];

$sql = $dbcon->prepare("
  SELECT
    ipt.an, pt.hn,
    CONCAT(COALESCE(pt.pname,''),COALESCE(pt.fname,''),' ',COALESCE(pt.lname,'')) AS fullname,
    ipt.regdate, ipt.regtime, ipt.pttype, ptt.name AS pttname
  FROM ipt
    LEFT JOIN patient pt ON pt.hn = ipt.hn
    LEFT JOIN pttype  ptt ON ptt.pttype = ipt.pttype
    LEFT JOIN accident_queue q ON q.an = ipt.an
  WHERE ".implode(' AND ',$where)."
    AND q.an IS NULL
  ORDER BY ipt.regdate DESC, ipt.regtime DESC, ipt.an DESC
  LIMIT 500
");
$sql->execute($params);
$newRows = $sql->fetchAll();
runlog("Ingest: found ".(is_array($newRows)?count($newRows):0)." new rows.");

if(!$dry && $newRows){
  $ins = $dbcon->prepare("
    INSERT INTO accident_queue
      (an, hn, fullname, regdate, regtime, pttype, pttname, status, attempt, created_at)
    VALUES
      (:an, :hn, :fullname, :regdate, :regtime, :pttype, :pttname, 0, 0, NOW())
    ON DUPLICATE KEY UPDATE an=an
  ");
  foreach($newRows as $r){
    $ins->execute([
      ':an'=>$r['an'], ':hn'=>$r['hn'], ':fullname'=>$r['fullname'],
      ':regdate'=>$r['regdate'], ':regtime'=>$r['regtime'],
      ':pttype'=>$r['pttype'], ':pttname'=>$r['pttname'],
    ]);
  }
}

/* ===================== STEP 2: SEND ===================== */
$cool = (int)ACCIDENT_RESEND_COOLDOWN_MIN;
$maxT = (int)ACCIDENT_MAX_ATTEMPTS;
$lim  = (int)ACCIDENT_BATCH_LIMIT;

$getQ = $dbcon->prepare("
  SELECT * FROM accident_queue
  WHERE status=0
    AND (last_attempt_at IS NULL OR TIMESTAMPDIFF(MINUTE,last_attempt_at,NOW()) >= :cd)
    AND attempt < :mx
  ORDER BY (last_attempt_at IS NULL) DESC, last_attempt_at ASC, created_at ASC
  LIMIT $lim
");
$getQ->execute([':cd'=>$cool, ':mx'=>$maxT]);
$queue = $getQ->fetchAll();
runlog("Send: to process ".count($queue)." rows (cooldown={$cool}m, maxTry={$maxT}).");

$updOk = $dbcon->prepare("UPDATE accident_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r WHERE id=:id");
$updNg = $dbcon->prepare("UPDATE accident_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");

foreach($queue as $row){
  if($dry){ runlog("DRY-RUN: would send id={$row['id']} an={$row['an']}"); continue; }
  usleep(random_int(10,80)*1000); // กันยิงพร้อมกัน

  [$ok,$ref,$err] = send_via_moph_alert_accident($row);

  if($ok){
    $updOk->execute([':r'=>$ref, ':id'=>$row['id']]);
    runlog("OK id={$row['id']} ref=".($ref??'-'));
  } else {
    $updNg->execute([':e'=>$err, ':id'=>$row['id']]);
    runlog("FAIL id={$row['id']} err=$err");
  }
}

/* ===================== UNLOCK & DONE ===================== */
try{ $dbcon->query("DO RELEASE_LOCK('accident_send_lock')"); } catch(Throwable $e){}
if(PHP_SAPI!=='cli'){ echo "<pre>Done</pre>"; }
