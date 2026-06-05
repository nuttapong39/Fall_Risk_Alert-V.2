<?php
/**
 * pharm_lab.php — Pharm Lab Alert: ingest + send orchestrator
 *   STEP 1: Ingest lab-critical rows จาก HosXP → pharm_lab_queue
 *   STEP 2: Send queue → MOPH Alert (Flex จาก flex_pharm.php)
 *
 * ใช้งาน:
 *   CLI รายวัน (default):
 *     php /path/pharm_lab.php
 *   Backfill ประวัติ:
 *     php pharm_lab.php --start=2025-06-01 --end=2026-12-31
 *   Dry-run:
 *     php pharm_lab.php --dry-run --start=2025-06-01 --end=2026-12-31
 *   เฉพาะ ingest (ไม่ส่ง):
 *     php pharm_lab.php --mode=ingest
 *   เฉพาะ send (ไม่ ingest):
 *     php pharm_lab.php --mode=send
 *   ผ่าน HTTP:
 *     pharm_lab.php?mode=both&start=2025-06-01&end=2026-12-31
 *
 * ICD / Lab codes ที่คัดมา:
 *   - INR (lab_items_code 539) ≥ 5
 *   - Depakin level (2368)    > 150
 *   - Lithium level (697 | 2388) > 1.2
 *   - Phenytoin level (2370)  > 20
 *
 * ไฟล์นี้ "ไม่" แบก buildPharmPayload() เองอีกต่อไป — ดึงจาก flex_pharm.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_pharm.php';   // buildPharmPayload / pharmRisk / row_to_utf8 / ...
date_default_timezone_set('Asia/Bangkok');

/* ===== Long-running guard (สำหรับ backfill ช่วงยาว) ===== */
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
if (PHP_SAPI !== 'cli') {
  // ปิด output buffering ให้ flush ออกได้ทันที กัน proxy/browser timeout
  @ini_set('zlib.output_compression', '0');
  @ini_set('output_buffering', '0');
  @ini_set('implicit_flush', '1');
  while (ob_get_level() > 0) { @ob_end_flush(); }
  @ob_implicit_flush(true);
  @ignore_user_abort(true);
}

/* ===== Defaults / Keys ===== */
if (!defined('MOPH_API_URL')) define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);
if (!defined('DEFAULT_LOOKBACK_DAYS')) define('DEFAULT_LOOKBACK_DAYS', 7);

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

/* ===== Policy ===== */
if (!defined('PHARM_RESEND_COOLDOWN_MIN')) define('PHARM_RESEND_COOLDOWN_MIN', 1);
if (!defined('PHARM_MAX_ATTEMPTS'))        define('PHARM_MAX_ATTEMPTS', 8);
if (!defined('PHARM_BATCH_LIMIT'))         define('PHARM_BATCH_LIMIT', 50);

/* ===== Logs ===== */
$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$RUN_LOG  = $LOG_DIR . '/pharm_lab_task_run.log';
$SEND_LOG = $LOG_DIR . '/moph_alert_pharm_lab.log';

if (!function_exists('runlog')) {
  function runlog($t){
    global $RUN_LOG;
    @file_put_contents($RUN_LOG,'['.date('Y-m-d H:i:s')."] $t\n",FILE_APPEND);
    if(PHP_SAPI==='cli') echo '['.date('Y-m-d H:i:s')."] $t\n";
  }
}
if (!function_exists('log_send')) {
  function log_send($row,$code,$resp,$err=null){
    global $SEND_LOG;
    $line = sprintf("[%s] id=%s hn=%s lab=%s http=%s err=%s resp=%s\n",
      date('Y-m-d H:i:s'),
      $row['id']??'-', $row['hn']??'-', $row['lab_name']??'-',
      $code, $err ?: '-', mb_substr($resp ?? '', 0, 2000));
    @file_put_contents($SEND_LOG, $line, FILE_APPEND);
    if(PHP_SAPI==='cli') echo $line;
  }
}

/* ===== Send function (ใช้ buildPharmPayload() จาก flex_pharm.php) ===== */
if (!function_exists('send_via_moph_alert_pharm')) {
  function send_via_moph_alert_pharm(array $row): array {
    $payload = buildPharmPayload($row);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($body === false){
      $e = json_last_error_msg();
      log_send($row, 0, null, "JSON_ENCODE_FAIL: $e");
      return [false, null, "JSON encode failed: $e"];
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
        'Accept: application/json',
      ],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    log_send($row, $code, $resp, $err);
    if ($err) return [false, null, "CURL: $err"];

    $json = @json_decode($resp, true);
    $mid  = extract_moph_message_id($json);
    $st   = is_array($json) && array_key_exists('status',$json)  ? $json['status']  : null;
    $msg  = is_array($json) && array_key_exists('message',$json) ? (string)$json['message'] : null;
    $ok   = ($mid) || (is_numeric($st) && (int)$st===200) || ($msg && preg_match('/succ(e|)ss/i',$msg));

    if (($code>=200 && $code<300) && $ok){
      $ref = $mid ?: ($st ? "status:$st" : 'HTTP'.$code);
      return [true, $ref, null];
    }
    $detail = "HTTP=$code";
    if ($st !== null) $detail .= " status=$st";
    if ($msg)         $detail .= " msg=$msg";
    return [false, null, "MOPH error: $detail"];
  }
}

/* ===== Main (ไม่ทำงานเมื่อถูก include เป็น library) ===== */
if (!defined('PHARM_LIB_ONLY')) {

  $mode  = strtolower((string)readParam('mode','both')); // ingest|send|both
  $start = readParam('start', date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days')));
  $end   = readParam('end', date('Y-m-d'));
  $dry   = (bool)readParam('dry-run', false);

  $today = date('Y-m-d');
  $defS  = date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days'));
  $start = normalize_date_ymd($start, $defS);
  $end   = normalize_date_ymd($end,   $today);
  if (strtotime($start)===false || strtotime($end)===false || $start>$end){ $start=$defS; $end=$today; }

  runlog("Effective range: $start -> $end  mode=$mode  dry=".($dry?'1':'0'));

  /* ========== STEP 1: Ingest (chunked + split OPD/IPD) ==========
     หมายเหตุ: query เดิม JOIN lab_head/lab_order สองชุด (OPD+IPD) ในครั้งเดียว ทำให้ cartesian
     ระเบิดและช้ามาก. แยกเป็น 2 query (OPD ผ่าน ov.vn, IPD ผ่าน s.an) แล้วรวมผลใน PHP
     โดยกัน duplicate ผ่าน UNIQUE KEY (hn, lab_order_number, lab_name) ใน pharm_lab_queue
  */
  if ($mode==='ingest' || $mode==='both'){

    /* helper: แบ่งช่วงวันเป็นกลุ่มเดือน เพื่อให้แต่ละ query เล็กลง */
    if (!function_exists('pharm_month_chunks')) {
      function pharm_month_chunks(string $start, string $end): array {
        $chunks = [];
        $cur = strtotime($start);
        $endTs = strtotime($end);
        while ($cur <= $endTs) {
          $chunkEnd = strtotime(date('Y-m-t', $cur)); // วันสุดท้ายของเดือน
          if ($chunkEnd > $endTs) $chunkEnd = $endTs;
          $chunks[] = [date('Y-m-d', $cur), date('Y-m-d', $chunkEnd)];
          $cur = strtotime(date('Y-m-d', $chunkEnd) . ' +1 day');
        }
        return $chunks;
      }
    }

    /* helper: ใช้ผลแถวเดียวผ่าน rules เพื่อตัดสิน lab_name + ตรวจค่าเกินเกณฑ์ */
    if (!function_exists('pharm_classify_row')) {
      function pharm_classify_row(string $lab_items_code, ?string $result_text): ?string {
        $v = is_numeric($result_text) ? (float)$result_text : null;
        if ($v === null) return null;
        if ($lab_items_code === '539'  && $v >= 5)   return 'INR';
        if ($lab_items_code === '2368' && $v >  150) return 'Depakin level';
        if (in_array($lab_items_code, ['697','2388'], true) && $v > 1.2) return 'Lithium level';
        if ($lab_items_code === '2370' && $v >  20)  return 'Phenytoin level';
        return null;
      }
    }

    /* prepared statement เดียวสำหรับ insert (UNIQUE KEY จะกันซ้ำให้เอง) */
    $ins = $dbcon->prepare("
      INSERT INTO pharm_lab_queue
        (hn, fullname, age, lab_date, lab_time, doctor,
         lab_name, result, patient_type, lab_order_number,
         status, attempt, created_at)
      VALUES
        (:hn, :fullname, :age, :lab_date, :lab_time, :doctor,
         :lab_name, :result, :patient_type, :lab_order_number,
         0, 0, NOW())
      ON DUPLICATE KEY UPDATE lab_order_number = VALUES(lab_order_number)
    ");

    /* SQL ต้นแบบ — ใช้ ? placeholder + IN-list ของ lab_items_code (sargable + ใช้ index ได้)
       แยก OPD (ผ่าน ov.vn) และ IPD (ผ่าน s.an) เพื่อหลีกเลี่ยง JOIN ซ้อน
    */
    $codes = ['539','2368','697','2388','2370'];
    $placeholders = implode(',', array_fill(0, count($codes), '?'));

    $sqlOPD = "
      SELECT
        pt.hn,
        CONCAT(COALESCE(pt.pname,''),' ',COALESCE(pt.fname,''),' ',COALESCE(pt.lname,'')) AS fullname,
        TIMESTAMPDIFF(YEAR, pt.birthday, CURDATE()) AS age,
        h.report_date AS lab_date,
        h.report_time AS lab_time,
        d.name        AS doctor,
        l.lab_items_code   AS lab_items_code,
        l.lab_order_result AS result,
        l.lab_order_number AS lab_order_number,
        'OPD'         AS patient_type
      FROM ovst s
        INNER JOIN vn_stat  ov ON ov.vn = s.vn
        LEFT  JOIN patient  pt ON pt.hn = s.hn
        LEFT  JOIN doctor   d  ON d.code = ov.dx_doctor
        INNER JOIN lab_head  h ON h.vn  = ov.vn
        INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number
      WHERE h.order_date BETWEEN ? AND ?
        AND l.lab_items_code IN ($placeholders)
        AND l.lab_order_result IS NOT NULL
        AND l.lab_order_result <> ''
    ";
    $sqlIPD = "
      SELECT
        pt.hn,
        CONCAT(COALESCE(pt.pname,''),' ',COALESCE(pt.fname,''),' ',COALESCE(pt.lname,'')) AS fullname,
        TIMESTAMPDIFF(YEAR, pt.birthday, CURDATE()) AS age,
        h1.report_date AS lab_date,
        h1.report_time AS lab_time,
        d.name         AS doctor,
        l1.lab_items_code   AS lab_items_code,
        l1.lab_order_result AS result,
        l1.lab_order_number AS lab_order_number,
        'IPD'          AS patient_type
      FROM ovst s
        INNER JOIN vn_stat  ov ON ov.vn = s.vn
        LEFT  JOIN patient  pt ON pt.hn = s.hn
        LEFT  JOIN doctor   d  ON d.code = ov.dx_doctor
        INNER JOIN lab_head  h1 ON h1.vn = s.an
        INNER JOIN lab_order l1 ON l1.lab_order_number = h1.lab_order_number
      WHERE h1.order_date BETWEEN ? AND ?
        AND s.an IS NOT NULL AND s.an <> ''
        AND l1.lab_items_code IN ($placeholders)
        AND l1.lab_order_result IS NOT NULL
        AND l1.lab_order_result <> ''
    ";

    $stOPD = $dbcon->prepare($sqlOPD);
    $stIPD = $dbcon->prepare($sqlIPD);

    $totalRows = 0; $totalKept = 0;
    $chunks = pharm_month_chunks($start, $end);
    runlog("Ingest plan: ".count($chunks)." monthly chunks for $start..$end");

    foreach ($chunks as $i => [$cs, $ce]) {
      $tStart = microtime(true);

      foreach ([['OPD',$stOPD], ['IPD',$stIPD]] as [$tag, $st]) {
        $params = array_merge([$cs, $ce], $codes);
        $st->execute($params);

        // stream rows — ไม่โหลดทั้งหมดเข้า memory
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
          $totalRows++;
          $labName = pharm_classify_row((string)$r['lab_items_code'], $r['result']);
          if (!$labName) continue;       // ไม่ถึงเกณฑ์วิกฤต ข้าม
          if (!$dry) {
            $ins->execute([
              ':hn'               => $r['hn'],
              ':fullname'         => $r['fullname'],
              ':age'              => (int)$r['age'],
              ':lab_date'         => $r['lab_date'],
              ':lab_time'         => $r['lab_time'],
              ':doctor'           => $r['doctor'],
              ':lab_name'         => $labName,
              ':result'           => $r['result'],
              ':patient_type'     => $r['patient_type'],
              ':lab_order_number' => $r['lab_order_number'],
            ]);
          }
          $totalKept++;
        }
        $st->closeCursor();
      }

      $dt = number_format(microtime(true)-$tStart, 2);
      runlog(sprintf("  chunk %d/%d %s..%s  scanned=%d kept=%d  (%.2fs)",
        $i+1, count($chunks), $cs, $ce, $totalRows, $totalKept, (float)$dt));

      // flush ออกหน้าจอ browser ระหว่างทำงาน
      if (PHP_SAPI !== 'cli') {
        echo str_pad('', 256)."<pre style='margin:0'>chunk ".($i+1)."/".count($chunks)
            ." {$cs}..{$ce}  scanned={$totalRows}  kept={$totalKept}  ({$dt}s)</pre>";
        @flush();
      }
    }

    runlog("Ingest done: scanned=$totalRows kept=$totalKept (range $start..$end)");

    if ($mode==='ingest' && PHP_SAPI!=='cli'){
      echo "<pre><b>Done (ingest-only):</b> $start -> $end  scanned=$totalRows  kept=$totalKept</pre>";
      return;
    }
  }

  /* ========== STEP 2: Send ========== */
  if ($mode==='send' || $mode==='both'){
    $cool = (int)PHARM_RESEND_COOLDOWN_MIN;
    $maxT = (int)PHARM_MAX_ATTEMPTS;
    $lim  = (int)PHARM_BATCH_LIMIT;

    $getQ = $dbcon->prepare("
      SELECT * FROM pharm_lab_queue
      WHERE status=0
        AND (last_attempt_at IS NULL OR TIMESTAMPDIFF(MINUTE,last_attempt_at,NOW()) >= :cd)
        AND attempt < :mx
      ORDER BY (last_attempt_at IS NULL) DESC, last_attempt_at ASC, created_at ASC
      LIMIT $lim
    ");
    $getQ->execute([':cd'=>$cool, ':mx'=>$maxT]);
    $q = $getQ->fetchAll();
    runlog("Send: to process ".count($q)." rows (cooldown={$cool}m, maxTry={$maxT}).");

    $okU = $dbcon->prepare("UPDATE pharm_lab_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r WHERE id=:id");
    $ngU = $dbcon->prepare("UPDATE pharm_lab_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");

    foreach ($q as $row){
      if ($dry){ runlog("DRY-RUN: would send id={$row['id']} hn={$row['hn']} lab={$row['lab_name']}"); continue; }
      usleep(random_int(10,80)*1000);
      [$ok,$ref,$err] = send_via_moph_alert_pharm($row);
      if ($ok){
        $okU->execute([':r'=>$ref, ':id'=>$row['id']]);
        runlog("OK id={$row['id']} ref=".($ref??'-'));
      } else {
        $ngU->execute([':e'=>$err, ':id'=>$row['id']]);
        runlog("FAIL id={$row['id']} err=$err");
      }
    }
    if (PHP_SAPI!=='cli'){ echo "<pre>Done: start={$start} end={$end}</pre>"; }
  }
}
