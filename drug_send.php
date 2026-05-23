<?php
/**
 * drug_send.php — Automation CLI สำหรับแจ้งเตือนผู้ป่วยกลุ่มเสี่ยงยาอันตราย
 *
 * STEP 1 (ถ้าใช้ --with-sync): Sync ข้อมูลจาก HOSxP → drug_queue
 * STEP 2              : ส่ง Flex message ไป MOPH Alert + อัปเดตสถานะคิว
 *
 * ──────────────────────────────────────────────
 * CLI parameters (ทุกตัวเป็น optional):
 *   --with-sync          เปิดการ Sync จาก HOSxP ก่อนส่ง (default: ปิด)
 *   --icodes=CODE,...    รหัสยา (icode) คั่นด้วยคอมมา (ใช้กับ --with-sync)
 *                        default: ค่าจาก DEFAULT_DRUG_ICODES
 *   --start=YYYY-MM-DD   วันที่เริ่มต้น Sync (default: 30 วันย้อนหลัง)
 *   --end=YYYY-MM-DD     วันที่สิ้นสุด Sync (default: วันนี้)
 *   --cooldown=N         นาทีคูลดาวน์ก่อน retry (default: 1)
 *   --max-attempts=N     ส่งซ้ำได้สูงสุดกี่ครั้ง (default: 8)
 *   --batch=N            ดึงจากคิวครั้งละกี่รายการ (default: 50)
 *   --dry-run            ทดสอบโดยไม่ส่งจริง ไม่แก้ DB
 * ──────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_drug.php';
// require_once __DIR__ . '/auth_guard.php';

date_default_timezone_set('Asia/Bangkok');

/* ============================================================
 *  CONFIG
 * ============================================================ */
if (!defined('MOPH_API_URL'))  define('MOPH_API_URL',  'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT'))  define('MOPH_TIMEOUT',  30);

// ใช้ DRUG_CLIENT_KEY / fallback ไป MOPH_CLIENT_KEY
if (!defined('DRUG_CLIENT_KEY'))
  define('DRUG_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('DRUG_SECRET_KEY'))
  define('DRUG_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

// ─ Policy ────────────────────────────────────────────────────
if (!defined('DRUG_RESEND_COOLDOWN_MIN'))  define('DRUG_RESEND_COOLDOWN_MIN',  1);   // นาที
if (!defined('DRUG_MAX_ATTEMPTS'))         define('DRUG_MAX_ATTEMPTS',         8);   // ครั้ง
if (!defined('DRUG_BATCH_LIMIT'))          define('DRUG_BATCH_LIMIT',         50);   // รายการ/รอบ
if (!defined('DEFAULT_DRUG_LOOKBACK_DAYS'))define('DEFAULT_DRUG_LOOKBACK_DAYS',30);  // วัน
if (!defined('DEFAULT_DRUG_ICODES'))       define('DEFAULT_DRUG_ICODES',  '1483860');

/* ============================================================
 *  Logging
 * ============================================================ */
$LOG_DIR  = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . DIRECTORY_SEPARATOR . 'moph_alert_drug.log';
$RUN_LOG  = $LOG_DIR . DIRECTORY_SEPARATOR . 'drug_task_run.log';

function runlog(string $t): void {
  global $RUN_LOG;
  @file_put_contents($RUN_LOG, '['.date('Y-m-d H:i:s')."] $t\n", FILE_APPEND);
}

function logln(string $msg): void {
  if (PHP_SAPI === 'cli') echo '['.date('Y-m-d H:i:s')."] $msg\n";
}

function log_moph_response(array $row, $code, $resp, $err = null): void {
  global $LOG_FILE;
  $line = sprintf(
    "[%s] id=%s hn=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'),
    $row['id']  ?? '-',
    $row['hn']  ?? '-',
    $code,
    $err ?: '-',
    mb_substr($resp ?? '', 0, 2000)
  );
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
  if (PHP_SAPI === 'cli') echo $line;
}

/* ============================================================
 *  CLI Parameter reader
 * ============================================================ */
function readParam(string $key, $default = null) {
  if (PHP_SAPI === 'cli') {
    static $args;
    if ($args === null) {
      $args = getopt('', [
        'with-sync', 'dry-run',
        'icodes::', 'start::', 'end::',
        'cooldown::', 'max-attempts::', 'batch::',
      ]);
    }
    if ($key === 'with-sync') return array_key_exists('with-sync', $args);
    if ($key === 'dry-run')   return array_key_exists('dry-run',   $args);
    return $args[$key] ?? $default;
  }
  // เรียกผ่านเว็บ (ป้องกันพิเศษ — ปกติไม่ควรเกิด)
  if ($key === 'with-sync') return isset($_GET['with_sync']);
  if ($key === 'dry-run')   return isset($_GET['dry_run']);
  return $_GET[$key] ?? $default;
}

/* ============================================================
 *  Date normalizer (รองรับ พ.ศ. ด้วย)
 * ============================================================ */
function normalize_date_ymd(string $d, string $fallback): string {
  if ($d === '') return $fallback;
  if (preg_match('/^\s*(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\s*$/', $d, $m)) {
    $y = (int)$m[1]; $mo = (int)$m[2]; $da = (int)$m[3];
    if ($y > 2400) $y -= 543; // แปลง พ.ศ. → ค.ศ.
    if ($y < 1900 || $y > 2100 || $mo < 1 || $mo > 12 || $da < 1 || $da > 31) return $fallback;
    return sprintf('%04d-%02d-%02d', $y, $mo, $da);
  }
  return $fallback;
}

/* ============================================================
 *  Send via MOPH Alert
 * ============================================================ */
function send_via_moph_alert_drug(array $row): array {
  $row     = row_to_utf8($row);
  $payload = buildDrugPayload($row);
  $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

  if ($body === false) {
    $jsonErr = json_last_error_msg();
    log_moph_response($row, 0, null, "JSON_ENCODE_FAIL: $jsonErr");
    return [false, null, "JSON encode failed: $jsonErr"];
  }

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => MOPH_API_URL,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => MOPH_TIMEOUT,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
      'client-key: ' . DRUG_CLIENT_KEY,
      'secret-key: ' . DRUG_SECRET_KEY,
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json',
    ],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  log_moph_response($row, $code, $resp, $err);
  if ($err) return [false, null, "CURL: $err"];

  $json      = json_decode($resp, true);
  $mid       = extract_moph_message_id($json);
  $apiStatus = is_array($json) && isset($json['status'])  ? $json['status']  : null;
  $apiMsg    = is_array($json) && isset($json['message']) ? (string)$json['message'] : null;

  $ok = $mid
     || (is_numeric($apiStatus) && (int)$apiStatus === 200)
     || ($apiMsg && preg_match('/succ(e|)ss/i', $apiMsg));

  if (($code >= 200 && $code < 300) && $ok) {
    $ref = $mid ?: ($apiStatus ? "status:$apiStatus" : "HTTP$code");
    return [true, $ref, null];
  }

  $detail = "HTTP=$code";
  if ($apiStatus !== null) $detail .= " status=$apiStatus";
  if ($apiMsg)             $detail .= " msg=$apiMsg";
  return [false, null, "MOPH error: $detail"];
}

/* ============================================================
 *  READ PARAMETERS
 * ============================================================ */
$withSync  = (bool) readParam('with-sync', false);
$dryRun    = (bool) readParam('dry-run',   false);
$icodesRaw = trim((string) readParam('icodes', DEFAULT_DRUG_ICODES));
$cooldown  = max(0, (int) readParam('cooldown',     DRUG_RESEND_COOLDOWN_MIN));
$maxTry    = max(1, (int) readParam('max-attempts', DRUG_MAX_ATTEMPTS));
$batchSize = max(1, (int) readParam('batch',        DRUG_BATCH_LIMIT));

$today        = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-' . DEFAULT_DRUG_LOOKBACK_DAYS . ' days'));

$rawStart = (string) readParam('start', $defaultStart);
$rawEnd   = (string) readParam('end',   $today);
$start    = normalize_date_ymd($rawStart, $defaultStart);
$end      = normalize_date_ymd($rawEnd,   $today);
if ($start > $end) { $start = $defaultStart; $end = $today; }

runlog("=== START" . ($dryRun ? ' [DRY-RUN]' : '') . ($withSync ? ' [WITH-SYNC]' : '') . " ===");
logln("Drug Send Script — range: $start → $end  cooldown={$cooldown}m  maxTry={$maxTry}  batch={$batchSize}" . ($dryRun ? '  DRY-RUN' : ''));

/* ============================================================
 *  STEP 1: Sync จาก HOSxP → drug_queue  (--with-sync เท่านั้น)
 * ============================================================ */
if ($withSync) {
  $icodeArr = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $icodesRaw))));
  if (!$icodeArr) {
    logln("WARN: ไม่ได้ระบุ --icodes → ข้าม Sync");
    runlog("WARN: ไม่ได้ระบุ --icodes ข้าม Sync");
  } else {
    logln("Sync: icodes=[" . implode(',', $icodeArr) . "]  range: $start → $end");
    try {
      $place = implode(',', array_fill(0, count($icodeArr), '?'));
      /*
       * JOIN ovst ด้วย hn+vstdate (ไม่ใช่ vn เพราะ opitemrece.vn อาจเป็น NULL)
       * สร้าง synthetic visit_vn = hn-YYYYMMDD-icode เมื่อ opi.vn ว่าง
       */
      $sqlHosxp = "SELECT
                     COALESCE(
                       NULLIF(TRIM(opi.vn), ''),
                       CONCAT(opi.hn, '-', DATE_FORMAT(opi.vstdate,'%Y%m%d'), '-', opi.icode)
                     )                                                             AS visit_vn,
                     opi.hn,
                     CONCAT(pt.pname, pt.fname, ' ', pt.lname)                    AS fullname,
                     pt.cid,
                     pt.hometel,
                     TIMESTAMPDIFF(YEAR, pt.birthday, opi.vstdate)                AS age,
                     CASE WHEN pt.sex='1' THEN 'ชาย'
                          WHEN pt.sex='2' THEN 'หญิง' ELSE '' END                 AS sex,
                     pt.addrpart                                                   AS address,
                     opi.icode                                                     AS drug_code,
                     d.name                                                        AS drug_name,
                     opi.vstdate,
                     CONCAT(COALESCE(ost.name,''), ' : ', COALESCE(dep.department,'')) AS department,
                     ''                                                            AS mainstation
                   FROM   opitemrece opi
                   LEFT JOIN patient        pt  ON pt.hn        = opi.hn
                   LEFT JOIN drugitems      d   ON d.icode      = opi.icode
                   LEFT JOIN ovst           ov  ON ov.hn        = opi.hn
                                               AND ov.vstdate   = opi.vstdate
                   LEFT JOIN ovstost        ost ON ost.ovstost  = ov.ovstost
                   LEFT JOIN kskdepartment  dep ON dep.depcode  = ov.cur_dep
                   WHERE  opi.vstdate BETWEEN ? AND ?
                   AND    opi.icode   IN ($place)
                   AND    opi.hn      IS NOT NULL
                   AND    opi.hn      != ''
                   GROUP  BY opi.hn, opi.vstdate, opi.icode
                   ORDER  BY opi.vstdate DESC
                   LIMIT  2000";

      $params   = array_merge([$start, $end], $icodeArr);
      $stmtH    = $dbcon->prepare($sqlHosxp);
      $stmtH->execute($params);
      $hosxpRows = $stmtH->fetchAll();

      logln("Sync: HOSxP returned " . count($hosxpRows) . " rows.");

      if (!$dryRun && $hosxpRows) {
        $ins = $dbcon->prepare(
          "INSERT INTO drug_queue
             (visit_vn, hn, fullname, cid, hometel, age, sex, address,
              drug_code, drug_name, vstdate, department, mainstation)
           VALUES (:vn,:hn,:fn,:cid,:tel,:age,:sex,:addr,:dc,:dn,:vd,:dept,:ms)
           ON DUPLICATE KEY UPDATE
             fullname=VALUES(fullname), hometel=VALUES(hometel),
             drug_name=VALUES(drug_name), department=VALUES(department)"
        );
        $existStmt = $dbcon->prepare("SELECT id FROM drug_queue WHERE visit_vn=?");

        $imported = 0; $newRows = 0; $skipped = 0;
        foreach ($hosxpRows as $hr) {
          $hr  = row_to_utf8($hr);
          $vn  = trim((string)($hr['visit_vn'] ?? ''));
          $hn  = trim((string)($hr['hn']       ?? ''));
          if ($vn === '' || $hn === '') { $skipped++; continue; }

          $existStmt->execute([$vn]);
          $isNew = !$existStmt->fetch();

          $ins->execute([
            ':vn'  => $vn,
            ':hn'  => $hn,
            ':fn'  => $hr['fullname']    ?? '',
            ':cid' => $hr['cid']         ?? '',
            ':tel' => $hr['hometel']     ?? '',
            ':age' => is_numeric($hr['age']) ? (int)$hr['age'] : null,
            ':sex' => $hr['sex']         ?? '',
            ':addr'=> $hr['address']     ?? '',
            ':dc'  => $hr['drug_code']   ?? '',
            ':dn'  => $hr['drug_name']   ?? '',
            ':vd'  => $hr['vstdate']     ?: null,
            ':dept'=> $hr['department']  ?? '',
            ':ms'  => $hr['mainstation'] ?? '',
          ]);
          $imported++;
          if ($isNew) $newRows++;
        }

        $skipNote = $skipped > 0 ? "  (ข้าม {$skipped} แถวไม่มี VN/HN)" : '';
        logln("Sync: นำเข้าสำเร็จ {$imported} รายการ (ใหม่ {$newRows} รายการ){$skipNote}");
        runlog("Sync done: imported={$imported} new={$newRows} skipped={$skipped}");
      } elseif ($dryRun) {
        logln("DRY-RUN: จะนำเข้า " . count($hosxpRows) . " รายการ (ไม่แก้ DB)");
      }

    } catch (Throwable $e) {
      logln("ERROR Sync: " . $e->getMessage());
      runlog("ERROR Sync: " . $e->getMessage());
    }
  }
}

/* ============================================================
 *  STEP 2: ส่ง Flex message จากคิว drug_queue
 * ============================================================ */
try {
  $sqlQ = "
    SELECT *
    FROM drug_queue
    WHERE status = 0
      AND (last_attempt_at IS NULL OR TIMESTAMPDIFF(MINUTE, last_attempt_at, NOW()) >= :cd)
      AND attempt < :maxtry
    ORDER BY
      (last_attempt_at IS NULL) DESC,
      last_attempt_at ASC,
      created_at ASC
    LIMIT $batchSize
  ";
  $getQ = $dbcon->prepare($sqlQ);
  $getQ->execute([':cd' => $cooldown, ':maxtry' => $maxTry]);
  $queue = $getQ->fetchAll();

  logln("Send: พบ " . count($queue) . " รายการในคิว (cooldown={$cooldown}m, maxTry={$maxTry})");

  $updOk = $dbcon->prepare("
    UPDATE drug_queue
    SET status=1, sent_at=NOW(), last_attempt_at=NOW(),
        attempt=attempt+1, last_error=NULL,
        out_ref=:ref, line_message_id=:ref
    WHERE id=:id
  ");
  $updErr = $dbcon->prepare("
    UPDATE drug_queue
    SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:err
    WHERE id=:id
  ");

  $cntOk = 0; $cntFail = 0;
  foreach ($queue as $row) {
    if ($dryRun) {
      logln("DRY-RUN: would send id={$row['id']} hn={$row['hn']} drug={$row['drug_code']}");
      continue;
    }
    usleep(random_int(10, 80) * 1000); // throttle 10–80 ms

    [$ok, $ref, $err] = send_via_moph_alert_drug($row);
    if ($ok) {
      $updOk->execute([':id' => $row['id'], ':ref' => $ref]);
      logln("OK  id={$row['id']} hn={$row['hn']} ref=" . ($ref ?? '-'));
      $cntOk++;
    } else {
      $updErr->execute([':id' => $row['id'], ':err' => $err]);
      logln("FAIL id={$row['id']} hn={$row['hn']} err=$err");
      $cntFail++;
    }
  }

  logln("Send: สำเร็จ {$cntOk} รายการ  ล้มเหลว {$cntFail} รายการ");
  runlog("Send done: ok={$cntOk} fail={$cntFail}");

} catch (Throwable $e) {
  logln("ERROR Send: " . $e->getMessage());
  runlog("ERROR Send: " . $e->getMessage());
}

runlog("=== END ===");

if (PHP_SAPI !== 'cli') {
  echo "<pre>Done: start={$start} end={$end} dryRun=" . ($dryRun ? '1' : '0') . "</pre>";
}
