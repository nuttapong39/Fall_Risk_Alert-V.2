<?php
/**
 * lab_hemato.php — ตัวรัน ingest / send ของ Hematocrit Alert
 *   (แบบเดียวกับ pharm_lab.php — เรียกได้ทั้งจากเว็บและ CLI)
 *
 * เว็บ : lab_hemato.php?mode=ingest&start=YYYY-MM-DD&end=YYYY-MM-DD
 *        lab_hemato.php?mode=send
 *        lab_hemato.php?mode=both            ← default
 * CLI  : php lab_hemato.php mode=both start=2026-01-01 end=2026-08-31
 *        php lab_hemato.php dryrun                 (สไตล์เดียวกับ module อื่น)
 *        php lab_hemato.php start 2026-01-01 end 2026-08-31
 *
 * ไฟล์นี้เป็น worker ตัวจริง — run_lab_hemato.bat เรียกไฟล์นี้โดยตรง
 * (แบบเดียวกับ pharm_lab.php / covid.php / drug_send.php ที่ไม่มีไฟล์ _ingest แยก)
 * จึงกดรันจากหน้าเว็บก็ได้ ให้ Task Scheduler เรียกก็ได้ ใช้โค้ดชุดเดียวกัน
 */
require_once __DIR__ . '/config.php';          // ← ต้องมาก่อน define คีย์เสมอ
require_once __DIR__ . '/flex_builders.php';
require_once __DIR__ . '/covid_lib.php';       // row_to_utf8(), extract_moph_message_id()
require_once __DIR__ . '/sources/lab_hemato_source.php';
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');

/* ── args (รับได้ทั้ง GET และ CLI แบบ key=value) ── */
$args  = $_GET;
$isDry = false;
if ($isCli) {
  // รับได้ 2 สไตล์: key=value (mode=both start=...) และแบบเดียวกับ module อื่น
  // (dryrun · start 2026-01-01 end 2026-12-31) เพื่อให้ run_lab_hemato.bat ส่ง %* ตรงเข้ามาได้
  $cli = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--'));
  for ($i = 0; $i < count($cli); $i++) {
    $a = $cli[$i];
    if (strpos($a, '=') !== false)                      { [$k,$v] = explode('=', $a, 2); $args[$k] = $v; }
    elseif ($a === 'dryrun')                            { $isDry = true; }
    elseif (in_array($a, ['ingest','send','both'], true)) { $args['mode'] = $a; }
    elseif (($a === 'start' || $a === 'end') && isset($cli[$i+1])) { $args[$a] = $cli[++$i]; }
  }
}
$isDry = $isDry || isset($args['dryrun']);
$mode  = $args['mode']  ?? 'both';
$start = $args['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end   = $args['end']   ?? date('Y-m-d');

if (!defined('LAB_HEMATO_CLIENT_KEY')) define('LAB_HEMATO_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('LAB_HEMATO_SECRET_KEY')) define('LAB_HEMATO_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);

$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$RUN_LOG  = $LOG_DIR . '/lab_hemato_task_run.log';
$SEND_LOG = $LOG_DIR . '/moph_alert_lab_hemato.log';

function lh_out(string $line): void {
  global $RUN_LOG;
  $s = '[' . date('Y-m-d H:i:s') . '] ' . $line;
  echo $s . "\n";
  @file_put_contents($RUN_LOG, $s . "\n", FILE_APPEND);
}

lh_out("=== lab_hemato START (mode={$mode} {$start}..{$end}" . ($isDry ? ' DRYRUN' : '') . ") ===");
lh_out('เงื่อนไข: ' . mf_labconds_summary(module_filter('lab_hemato')['groups'] ?? []));

/* ══ INGEST ══════════════════════════════════════════════════════════════ */
function lh_ingest(PDO $db, string $start, string $end): array {
  $rows = lab_hemato_source_rows($start, $end);
  lh_out('HOSxP: พบ ' . count($rows) . ' รายการ');

  $ins = $db->prepare(
    "INSERT INTO lab_hemato_queue
       (hn, vn, fullname, age, sex, hometel, lab_order_number, lab_items_code,
        result, lab_date, lab_time, doctor, patient_type)
     VALUES (:hn,:vn,:fn,:age,:sex,:tel,:ord,:code,:res,:ld,:lt,:doc,:pt)
     ON DUPLICATE KEY UPDATE
       fullname=VALUES(fullname), result=VALUES(result),
       lab_date=VALUES(lab_date), lab_time=VALUES(lab_time), doctor=VALUES(doctor)"
  );
  $exist = $db->prepare("SELECT id FROM lab_hemato_queue WHERE hn=? AND lab_order_number=? AND lab_items_code=?");

  $imported = 0; $new = 0; $skipped = 0;
  foreach ($rows as $r) {
    $r  = row_to_utf8($r);
    $hn = trim((string)($r['hn'] ?? ''));
    $on = trim((string)($r['lab_order_number'] ?? ''));
    if ($hn === '' || $on === '') { $skipped++; continue; }
    $exist->execute([$hn, $on, (string)($r['lab_items_code'] ?? '')]);
    if (!$exist->fetch()) { $new++; lh_out("NEW hn={$hn} ord={$on} code={$r['lab_items_code']} = {$r['result']}"); }
    $ins->execute([
      ':hn'=>$hn, ':vn'=>$r['vn'] ?? null, ':fn'=>$r['fullname'] ?? '',
      ':age'=>is_numeric($r['age'] ?? null) ? (int)$r['age'] : null,
      ':sex'=>$r['sex'] ?? '', ':tel'=>$r['hometel'] ?? '',
      ':ord'=>$on, ':code'=>$r['lab_items_code'] ?? '', ':res'=>$r['result'] ?? '',
      ':ld'=>$r['lab_date'] ?: null, ':lt'=>$r['lab_time'] ?: null,
      ':doc'=>$r['doctor'] ?? '', ':pt'=>$r['patient_type'] ?? 'OPD',
    ]);
    $imported++;
  }
  lh_out("Upsert: imported={$imported} new={$new} skipped={$skipped}");
  return [$imported, $new, $skipped];
}

/* ══ SEND ════════════════════════════════════════════════════════════════ */
function lh_send_pending(PDO $db, int $limit = 50, int $maxTry = 8, int $cooldownMin = 1): array {
  $q = $db->prepare(
    "SELECT * FROM lab_hemato_queue
     WHERE status = 0
       AND attempt < :maxtry
       AND (last_attempt_at IS NULL OR last_attempt_at < DATE_SUB(NOW(), INTERVAL :cd MINUTE))
     ORDER BY id ASC LIMIT {$limit}"
  );
  $q->execute([':maxtry' => $maxTry, ':cd' => $cooldownMin]);
  $queue = $q->fetchAll(PDO::FETCH_ASSOC);
  lh_out('Send: to process ' . count($queue) . " rows (cooldown={$cooldownMin}m, maxTry={$maxTry})");

  $ok = 0; $fail = 0;
  foreach ($queue as $row) {
    global $SEND_LOG;
    $row  = row_to_utf8($row);
    $body = json_encode(buildLabHematoPayload($row), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL            => MOPH_API_URL,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $body,
      CURLOPT_HTTPHEADER     => [
        'client-key: ' . LAB_HEMATO_CLIENT_KEY,
        'secret-key: ' . LAB_HEMATO_SECRET_KEY,
        'Content-Type: application/json; charset=UTF-8', 'Expect:',
      ],
      CURLOPT_TIMEOUT        => MOPH_TIMEOUT,
      CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    @file_put_contents($SEND_LOG, sprintf("[%s] id=%s hn=%s http=%s err=%s resp=%s\n",
      date('Y-m-d H:i:s'), $row['id'], $row['hn'], $code, $err ?: '-', mb_substr((string)$resp, 0, 2000)), FILE_APPEND);

    $json = @json_decode($resp, true);
    $mid  = extract_moph_message_id($json);
    $st   = is_array($json) && array_key_exists('status', $json) ? $json['status'] : null;
    $good = !$err && $code >= 200 && $code < 300 && ($mid || (is_numeric($st) && (int)$st === 200));

    if ($good) {
      $ref = $mid ?: 'HTTP' . $code;
      $db->prepare("UPDATE lab_hemato_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(),
                    attempt=attempt+1, last_error=NULL, out_ref=?, line_message_id=? WHERE id=?")
         ->execute([$ref, $ref, $row['id']]);
      lh_out("SENT id={$row['id']} hn={$row['hn']} ref={$ref}");
      $ok++;
    } else {
      $detail = $err ? "CURL: $err" : "MOPH error: HTTP=$code" . ($st !== null ? " status=$st" : '');
      $db->prepare("UPDATE lab_hemato_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=? WHERE id=?")
         ->execute([$detail, $row['id']]);
      lh_out("FAIL id={$row['id']} {$detail}");
      $fail++;
    }
    usleep(random_int(10, 80) * 1000);
  }
  lh_out("Send result: ok={$ok} fail={$fail}");
  return [$ok, $fail];
}

/* ══ RUN ═════════════════════════════════════════════════════════════════ */
try {
  if ($isDry) {
    // dryrun = แตะ HOSxP อย่างเดียว ไม่เขียน queue ไม่ยิง MOPH — ใช้ตรวจเงื่อนไขก่อนเปิดใช้จริง
    $rows = lab_hemato_source_rows($start, $end);
    lh_out('DRYRUN: HOSxP พบ ' . count($rows) . ' รายการ (ไม่เขียน queue ไม่ส่งแจ้งเตือน)');
    foreach (array_slice($rows, 0, 20) as $r) {
      $r = row_to_utf8($r);
      lh_out(sprintf('  hn=%s ord=%s code=%s result=%s date=%s',
        $r['hn'] ?? '-', $r['lab_order_number'] ?? '-', $r['lab_items_code'] ?? '-',
        $r['result'] ?? '-', $r['lab_date'] ?? '-'));
    }
    if (count($rows) > 20) lh_out('  ... (แสดง 20 แถวแรก)');
  } else {
    if ($mode === 'ingest' || $mode === 'both') lh_ingest($dbcon, $start, $end);
    if ($mode === 'send'   || $mode === 'both') lh_send_pending($dbcon);
  }
  lh_out('=== lab_hemato DONE ===');
} catch (Throwable $e) {
  lh_out('ERROR: ' . $e->getMessage());
  if (!$isCli) http_response_code(500);
  exit(1);
}
