<?php
/**
 * patient_ingest.php — Automation for Psychiatric / Self-harm alerts via MOPH Alert
 * - STEP 1: Ingest (คัดกรอง ICD-10: T71, X60–X69, X70, X84 จาก vn_stat.pdx / dx0..dx3)
 * - STEP 2: ส่ง Flex message ไป MOPH Alert + อัปเดตสถานะคิว (มีคูลดาวน์/จำกัดครั้ง)
 *
 * ใช้โครงสร้างเดียวกับ fracture.php ทุกประการ (ยกเว้นตัวกรองโรคและชื่อ constants)
 * รันได้ทั้ง CLI (cron) และผ่านเบราว์เซอร์ (เพื่อ trigger ด้วยปุ่มในหน้าคิว)
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  การใช้งาน (Usage)
 * ═══════════════════════════════════════════════════════════════════════
 *  1) CRON รายวัน (default): ดึง + ส่งข้อมูล 7 วันล่าสุด
 *       php /path/patient_ingest.php
 *
 *  2) Backfill ประวัติ พ.ศ. 2568–2569:
 *       php patient_ingest.php --start=2025-06-01 --end=2026-12-31
 *       หรือเปิด URL: patient_ingest.php?start=2025-06-01&end=2026-12-31
 *       (ปลอดภัย — ON DUPLICATE KEY visit_vn กันซ้ำอัตโนมัติ)
 *
 *  3) ทดสอบไม่ยิงจริง:
 *       php patient_ingest.php --dry-run --start=2025-06-01 --end=2026-12-31
 *
 *  SQL เทียบกับ patient.php เวอร์ชันเดิม (ก่อน update):
 *   - เดิม: vstdate BETWEEN '2025-06-01' AND CURDATE(), LIMIT 2, ICD T71/X60%/X70%/X84%
 *   - ใหม่: vstdate BETWEEN :start AND :end (default 7 วัน), ไม่จำกัด LIMIT,
 *           ICD T71/X60%–X69%/X70%/X84% (กว้างขึ้น)
 *   → ถ้าต้องการพฤติกรรม "ตั้งแต่ 2568-06-01 ถึงวันนี้" แบบเดิม ให้ใช้ backfill URL ด้านบน
 * ═══════════════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_patient.php';   // ไลบรารี Flex กลาง (ต้องมาก่อนฟังก์ชันในไฟล์นี้)
// require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

/* ==============================
 *  CONFIG เฉพาะ Psych / Self-harm
 * ============================== */
if (!defined('MOPH_API_URL')) define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);

// ใช้ key จาก config.php เป็น default
if (!defined('PATIENT_CLIENT_KEY')) define('PATIENT_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('PATIENT_SECRET_KEY')) define('PATIENT_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

// default ช่วงวันหากไม่ส่งพารามิเตอร์
if (!defined('DEFAULT_LOOKBACK_DAYS')) define('DEFAULT_LOOKBACK_DAYS', 7);

// ---- Resend policy (ปรับได้ตามต้องการ) ----
if (!defined('PATIENT_RESEND_COOLDOWN_MIN')) define('PATIENT_RESEND_COOLDOWN_MIN', 1);
if (!defined('PATIENT_MAX_ATTEMPTS'))        define('PATIENT_MAX_ATTEMPTS', 8);
if (!defined('PATIENT_BATCH_LIMIT'))         define('PATIENT_BATCH_LIMIT', 50);

// log
$LOG_DIR  = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . DIRECTORY_SEPARATOR . 'moph_alert_patient.log';
$RUN_LOG  = $LOG_DIR . DIRECTORY_SEPARATOR . 'patient_task_run.log';
function p_runlog($t){ global $RUN_LOG; @file_put_contents($RUN_LOG, '['.date('Y-m-d H:i:s')."] $t\n", FILE_APPEND); }

/* ==============================
 *  Utilities
 * ============================== */
function p_logln($msg){ if (PHP_SAPI === 'cli') echo '['.date('Y-m-d H:i:s')."] $msg\n"; }

function log_moph_response_patient_ingest($row, $code, $resp, $err=null){
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

function p_readParam($key, $default=null){
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
function p_normalize_date_ymd($d, $fallback){
  if (!is_string($d) || $d==='') return $fallback;
  if (preg_match('/^\s*(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\s*$/', $d, $m)){
    $y=(int)$m[1]; $mo=(int)$m[2]; $da=(int)$m[3];
    if ($y > 2400) $y -= 543;
    if ($y < 1900 || $y > 2100 || $mo < 1 || $mo > 12 || $da < 1 || $da > 31) return $fallback;
    return sprintf('%04d-%02d-%02d', $y, $mo, $da);
  }
  return $fallback;
}

function send_via_moph_alert_patient(array $row): array{
  $row = row_to_utf8($row);
  $payload = buildPatientPayload($row);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false){
    $jsonErr = json_last_error_msg();
    log_moph_response_patient_ingest($row, 0, null, "JSON_ENCODE_FAIL: ".$jsonErr);
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
      'client-key: ' . PATIENT_CLIENT_KEY,
      'secret-key: ' . PATIENT_SECRET_KEY,
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json'
    ],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  log_moph_response_patient_ingest($row, $code, $resp, $err);
  if ($err) return [false, null, "CURL: $err"];

  $json = json_decode($resp, true);
  $mid  = extract_moph_message_id($json);
  $apiStatus = is_array($json) && array_key_exists('status',$json)  ? $json['status']  : null;
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
$start  = p_readParam('start', date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days')));
$end    = p_readParam('end',   date('Y-m-d'));
$hosp   = trim((string)p_readParam('hosp', ''));
$dryRun = p_readParam('dry-run', false);

// Normalize วันที่
$today        = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days'));
$start = p_normalize_date_ymd($start, $defaultStart);
$end   = p_normalize_date_ymd($end,   $today);
if (strtotime($start) === false || strtotime($end) === false || $start > $end){
  $start = $defaultStart; $end = $today;
}
p_logln("Effective range: $start -> $end");

/* ==============================
 *  STEP 1: Ingest เข้าคิว
 *  เงื่อนไข: ICD-10 กลุ่ม T71, X60–X69, X70, X84 (พบใน pdx หรือ dx0..dx3)
 * ============================== */
$where  = [];
$params = [];

$where[] = "ov.vstdate BETWEEN :start AND :end";
$params[':start'] = $start;
$params[':end']   = $end;

if ($hosp !== ''){
  $where[] = "ovst.hospsub = :hosp";
  $params[':hosp'] = $hosp;
}

/* สร้างเงื่อนไข ICD10 — ต้องเช็คทั้ง pdx, dx0..dx3 */
$codeCols = ['ov.pdx','ov.dx0','ov.dx1','ov.dx2','ov.dx3'];
$codeConds = [];
foreach ($codeCols as $col){
  $codeConds[] = "UPPER($col) = 'T71'";
  foreach (['X60','X61','X62','X63','X64','X65','X66','X67','X68','X69','X70','X84'] as $pfx){
    $codeConds[] = "UPPER($col) LIKE '{$pfx}%'";
  }
}
$where[] = '(' . implode(' OR ', $codeConds) . ')';

// === SELECT ===
$sql = $dbcon->prepare("
  SELECT
    ov.vn AS visit_vn,
    pt.hn,
    CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
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
  LEFT  JOIN patient pt ON pt.hn = ov.hn      -- เดิมเป็น LEFT OUTER JOIN ใน patient.php เก่า (กัน vn_stat ที่ไม่มี patient record)
  LEFT  JOIN sex    se  ON pt.sex = se.code
  LEFT  JOIN icd101 ic  ON ov.pdx = ic.code
  LEFT  JOIN ovst ovst  ON ovst.vn = ov.vn
  LEFT  JOIN hospcode h ON h.hospcode = ovst.hospsub
  LEFT  JOIN patient_queue q ON q.visit_vn = ov.vn
  WHERE ".implode(' AND ', $where)."
    AND q.visit_vn IS NULL
  ORDER BY ov.vstdate DESC, ov.vn DESC
");
$sql->execute($params);
$newRows = $sql->fetchAll();
p_logln("Ingest: found ".count($newRows)." new rows.");

if (!$dryRun && $newRows){
  $ins = $dbcon->prepare("
    INSERT INTO patient_queue
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
 *  STEP 2: ส่ง + อัปเดตสถานะ (คูลดาวน์/จำกัดครั้ง)
 * ============================== */
$cooldown = (int)PATIENT_RESEND_COOLDOWN_MIN;
$maxTry   = (int)PATIENT_MAX_ATTEMPTS;
$limit    = (int)PATIENT_BATCH_LIMIT;

$sqlQ = "
  SELECT *
  FROM patient_queue
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

p_logln("Send: to process ".count($queue)." rows (cooldown={$cooldown}m, maxTry={$maxTry}).");

$updOk = $dbcon->prepare("
  UPDATE patient_queue
  SET status=1, sent_at=NOW(), last_attempt_at=NOW(),
      attempt=attempt+1, last_error=NULL,
      out_ref=:ref, line_message_id=:ref
  WHERE id=:id
");
$updErr = $dbcon->prepare("
  UPDATE patient_queue
  SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:err
  WHERE id=:id
");

$okN = 0; $failN = 0;
foreach ($queue as $row){
  if ($dryRun){ p_logln("DRY-RUN: would send id={$row['id']} hn={$row['hn']}"); continue; }
  usleep(random_int(10,80) * 1000);
  [$ok, $ref, $err] = send_via_moph_alert_patient($row);
  if ($ok){
    $updOk->execute([':id'=>$row['id'], ':ref'=>$ref]);
    $okN++;
    p_logln("OK id={$row['id']} ref=".($ref ?? '-'));
  } else {
    $updErr->execute([':id'=>$row['id'], ':err'=>$err]);
    $failN++;
    p_logln("FAIL id={$row['id']} err=$err");
  }
}

p_runlog("run: ingest=".count($newRows)." sendOK=$okN sendFail=$failN dryRun=".($dryRun?'1':'0'));

if (PHP_SAPI !== 'cli'){
  echo "<pre>Done (Psych / Self-harm)
  start   = {$start}
  end     = {$end}
  hosp    = {$hosp}
  dryRun  = ".($dryRun?'1':'0')."
  ingested= ".count($newRows)."
  sendOK  = {$okN}
  sendFail= {$failN}
  </pre>";
  echo '<p><a href="patient.php">← กลับคิวแจ้งเตือน</a></p>';
}
