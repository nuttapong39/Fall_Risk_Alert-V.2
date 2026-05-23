<?php
/**
 * fracture.php — Automation for Fall/Fracture alerts via MOPH Alert
 * - STEP 1: Ingest (อายุ≥60, ช่วงวันที่, เงื่อนไข W00–W109/W18–W199 หรือ S-codes ที่กำหนด)
 * - STEP 2: ส่ง Flex message ไป MOPH Alert + อัปเดตสถานะคิว (มีคูลดาวน์/จำกัดครั้ง)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_fracture.php';   // โหลดไลบรารี Flex กลาง (ต้องมาก่อนที่ฟังก์ชันในไฟล์นี้จะถูกประกาศ)
// require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

/* ==============================
 *  CONFIG เฉพาะ Fracture
 * ============================== */
if (!defined('MOPH_API_URL')) define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);

// ใช้ key จาก config.php เป็น default; ถ้าต้องการแยก key สำหรับ Fracture ให้ define ไว้ใน config.php ก่อน
if (!defined('FRACTURE_CLIENT_KEY')) define('FRACTURE_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('FRACTURE_SECRET_KEY')) define('FRACTURE_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

// FALL_TITLE / FALL_HEADER_URL / FALL_ICON_URL — ถูก define ไว้แล้วใน flex_fracture.php

// default ช่วงวันหากไม่ส่งพารามิเตอร์
if (!defined('DEFAULT_LOOKBACK_DAYS')) define('DEFAULT_LOOKBACK_DAYS', 7);

// ---- Resend policy (ปรับได้ตามต้องการ) ----
if (!defined('FRACTURE_RESEND_COOLDOWN_MIN')) define('FRACTURE_RESEND_COOLDOWN_MIN', 1);   // เว้นอย่างน้อย 1 นาทีค่อยลองส่งใหม่
if (!defined('FRACTURE_MAX_ATTEMPTS'))       define('FRACTURE_MAX_ATTEMPTS', 8);           // ส่งซ้ำได้สูงสุดกี่ครั้ง
if (!defined('FRACTURE_BATCH_LIMIT'))        define('FRACTURE_BATCH_LIMIT', 50);           // ดึงครั้งละกี่เรคคอร์ด

// log
$LOG_DIR  = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . DIRECTORY_SEPARATOR . 'moph_alert_fracture.log';
$RUN_LOG  = $LOG_DIR . DIRECTORY_SEPARATOR . 'fracture_task_run.log';
function runlog($t){ global $RUN_LOG; @file_put_contents($RUN_LOG, '['.date('Y-m-d H:i:s')."] $t\n", FILE_APPEND); }

/* ==============================
 *  Utilities
 * ============================== */
function logln($msg){ if (PHP_SAPI === 'cli') echo '['.date('Y-m-d H:i:s')."] $msg\n"; }

function log_moph_response($row, $code, $resp, $err=null){
  global $LOG_FILE;
  $line = sprintf(
    "[%s] id=%s hn=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'),
    $row['id']??'-',
    $row['hn']??'-',
    $code,
    $err ?: '-',
    mb_substr($resp ?? '', 0, 2000)
  );
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
  if (PHP_SAPI === 'cli') echo $line;
}

// to_utf8(), row_to_utf8() → ย้ายไปไว้ใน flex_fracture.php แล้ว (มี guard function_exists)

function readParam($key, $default=null){
  if (PHP_SAPI === 'cli'){
    static $args; if ($args===null) $args = getopt('', ['start::','end::','hosp::','dry-run']);
    if ($key==='dry-run') return array_key_exists('dry-run', $args);
    return $args[$key] ?? $default;
  } else {
    if ($key==='dry-run') return isset($_GET['dry-run']);
    return $_GET[$key] ?? $default;
  }
}

// แปลง พ.ศ./ฟอร์แมตอื่น → YYYY-MM-DD
function normalize_date_ymd($d, $fallback){
  if (!is_string($d) || $d==='') return $fallback;
  if (preg_match('/^\s*(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\s*$/', $d, $m)){
    $y=(int)$m[1]; $mo=(int)$m[2]; $da=(int)$m[3];
    if ($y > 2400) $y -= 543; // พ.ศ.
    if ($y < 1900 || $y > 2100 || $mo < 1 || $mo > 12 || $da < 1 || $da > 31) return $fallback;
    return sprintf('%04d-%02d-%02d', $y, $mo, $da);
  }
  return $fallback;
}

// หมายเหตุ: buildFracturePayload(), extract_moph_message_id(), to_utf8(), row_to_utf8()
// ถูก define ไว้ใน flex_fracture.php แล้ว — ไม่ต้องประกาศซ้ำในไฟล์นี้

function send_via_moph_alert_fracture(array $row): array{
  $row = row_to_utf8($row);
  $payload = buildFracturePayload($row);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false){
    $jsonErr = json_last_error_msg();
    log_moph_response($row, 0, null, "JSON_ENCODE_FAIL: ".$jsonErr);
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
  if ($err) return [false, null, "CURL: $err"];

  $json = json_decode($resp, true);
  $mid  = extract_moph_message_id($json);
  $apiStatus = is_array($json) && array_key_exists('status',$json) ? $json['status'] : null;
  $apiMsg    = is_array($json) && array_key_exists('message',$json) ? (string)$json['message'] : null;

  $looksSuccess = ($mid) || (is_numeric($apiStatus) && (int)$apiStatus===200) || ($apiMsg && preg_match('/succ(e|)ss/i',$apiMsg));
  if (($code>=200 && $code<300) && $looksSuccess){
    $ref = $mid ?: ($apiStatus ? "status:$apiStatus" : 'HTTP'.$code);
    return [true, $ref, null];
  }
  $detail = "HTTP=$code";
  if ($apiStatus!==null) $detail.=" status=$apiStatus";
  if ($apiMsg)           $detail.=" msg=$apiMsg";
  return [false, null, "MOPH error: $detail"];
}

/* ==============================
 *  รับพารามิเตอร์
 * ============================== */
$start  = readParam('start', date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days')));
$end    = readParam('end',   date('Y-m-d'));
$hosp   = trim(readParam('hosp', ''));          // ใช้ ovst.hospsub ตาม SQL ที่กำหนด
$dryRun = readParam('dry-run', false);

// Normalize วันที่
$today        = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days'));
if (!isset($defaultStart)) { $defaultStart = date('Y-m-d', strtotime('-7 days')); } // safety
$start = normalize_date_ymd($start, $defaultStart);
$end   = normalize_date_ymd($end,   $today);
if (strtotime($start) === false || strtotime($end) === false || $start > $end){
  $start = $defaultStart; $end = $today;
}
logln("Effective range: $start -> $end");

/* ==============================
 *  STEP 1: Ingest เข้าคิว (STRICT ตาม SQL ผู้ใช้)
 * ============================== */
$where  = [];
$params = [];

$where[] = "ov.age_y >= 60";
$where[] = "ov.vstdate BETWEEN :start AND :end";
$params[':start'] = $start;
$params[':end']   = $end;

// ถ้าต้องกรองรพ. ให้ใช้ ovst.hospsub
if ($hosp !== ''){
  $where[] = "ovst.hospsub = :hosp";
  $params[':hosp'] = $hosp;
}

/* ===== คัดกรองตามที่ร้องขอ: ใช้เกณฑ์เดียวกับ fracture_queue_ui.php =====
   - อายุ ≥ 60 ถูกกำหนดไว้แล้วด้านบน (ov.age_y >= 60)
   - ICD10: เฉพาะ W00–W19 และ S-codes กลุ่มกระดูกหัก
*/
$dxConds = [];

// W-ranges (W00–W19) เฉพาะ pdx
$dxConds[] = "(UPPER(ov.pdx) BETWEEN 'W00' AND 'W19')";

// S-code list เฉพาะ pdx
foreach (['S720','S721','S722','S525','S526','S422','S220','S221','S320','S327'] as $prefix) {
  $dxConds[] = "UPPER(ov.pdx) LIKE '{$prefix}%'";
}

// รวมเป็นเงื่อนไขเดียว
$where[] = '(' . implode(' OR ', $dxConds) . ')';


// === SELECT ตามเดิม ===
$sql = $dbcon->prepare("
  SELECT 
    ov.vn AS visit_vn,
    pt.hn,
    CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname, ''), ' ', COALESCE(pt.lname,'')) AS fullname,
    pt.cid,
    pt.hometel,
    ov.age_y AS age,  
    se.name  AS sex,
    pt.informaddr AS address,
    ov.pdx   AS pdx_code,
    ic.name  AS pdx_name,
    ov.vstdate,
    COALESCE(h.name, '') AS mainstation
  FROM vn_stat ov
  INNER JOIN er_regist e ON e.vn = ov.vn
  INNER JOIN patient pt ON pt.hn = ov.hn
  LEFT  JOIN sex    se ON pt.sex = se.code
  LEFT  JOIN icd101 ic ON ov.pdx = ic.code
  LEFT  JOIN ovst ovst ON ovst.vn = ov.vn
  LEFT  JOIN hospcode h ON h.hospcode = ovst.hospsub
  LEFT  JOIN fracture_queue q ON q.visit_vn = ov.vn
  WHERE ".implode(' AND ', $where)."
    AND q.visit_vn IS NULL
  ORDER BY ov.vstdate DESC, ov.vn DESC
");
$sql->execute($params);
$newRows = $sql->fetchAll();
logln("Ingest: found ".count($newRows)." new rows.");

if (!$dryRun && $newRows){
  $ins = $dbcon->prepare("
    INSERT INTO fracture_queue
      (visit_vn, hn, fullname, cid, hometel, age, sex, address, pdx_code, pdx_name, vstdate, mainstation, status, attempt, created_at)
    VALUES
      (:visit_vn, :hn, :fullname, :cid, :hometel, :age, :sex, :address, :pdx_code, :pdx_name, :vstdate, :mainstation, 0, 0, NOW())
    ON DUPLICATE KEY UPDATE visit_vn = visit_vn
  ");
  foreach ($newRows as $r){
    $ins->execute([
      ':visit_vn'    => $r['visit_vn'],
      ':hn'          => $r['hn'],
      ':fullname'    => $r['fullname'],
      ':cid'         => $r['cid'],
      ':hometel'     => $r['hometel'],
      ':age'         => (int)$r['age'],
      ':sex'         => $r['sex'],
      ':address'     => $r['address'],
      ':pdx_code'    => $r['pdx_code'],
      ':pdx_name'    => $r['pdx_name'],
      ':vstdate'     => $r['vstdate'],
      ':mainstation' => $r['mainstation'],
    ]);
  }
}

/* ==============================
 *  STEP 2: ส่ง + อัปเดตสถานะ (มีคูลดาวน์/จำกัดครั้ง)
 * ============================== */
$cooldown = (int)FRACTURE_RESEND_COOLDOWN_MIN;
$maxTry   = (int)FRACTURE_MAX_ATTEMPTS;
$limit    = (int)FRACTURE_BATCH_LIMIT;

$sqlQ = "
  SELECT *
  FROM fracture_queue
  WHERE status = 0
    AND (last_attempt_at IS NULL OR TIMESTAMPDIFF(MINUTE, last_attempt_at, NOW()) >= :cd)
    AND attempt < :maxtry
  ORDER BY
    (last_attempt_at IS NULL) DESC,
    last_attempt_at ASC,
    created_at ASC
  LIMIT $limit
";
$getQ = $dbcon->prepare($sqlQ);
$getQ->execute([':cd'=>$cooldown, ':maxtry'=>$maxTry]);
$queue = $getQ->fetchAll();

logln("Send: to process ".count($queue)." rows (cooldown={$cooldown}m, maxTry={$maxTry}).");

$updOk = $dbcon->prepare("
  UPDATE fracture_queue
  SET status=1,
      sent_at=NOW(),
      last_attempt_at=NOW(),
      attempt=attempt+1,
      last_error=NULL,
      out_ref=:ref,
      line_message_id=:ref
  WHERE id=:id
");
$updErr = $dbcon->prepare("
  UPDATE fracture_queue
  SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:err
  WHERE id=:id
");

foreach ($queue as $row){
  if ($dryRun){ logln("DRY-RUN: would send id={$row['id']} hn={$row['hn']}"); continue; }
  usleep(random_int(10,80) * 1000);
  [$ok, $ref, $err] = send_via_moph_alert_fracture($row);
  if ($ok){
    $updOk->execute([':id'=>$row['id'], ':ref'=>$ref]);
    logln("OK id={$row['id']} ref=".($ref ?? '-'));
  } else {
    $updErr->execute([':id'=>$row['id'], ':err'=>$err]);
    logln("FAIL id={$row['id']} err=$err");
  }
}

if (PHP_SAPI !== 'cli'){
  echo "<pre>Done: start={$start} end={$end} hosp={$hosp} dryRun=".($dryRun?'1':'0')."</pre>";
}
