<?php
/**
 * drugs_alert_worker.php — ตัวรัน ingest / send ของ Drug Alert (ยาเฝ้าระวังที่เภสัชเลือกเอง)
 *   (แบบเดียวกับ pharm_lab.php / HAD.php — เรียกได้ทั้งจากเว็บและ CLI)
 *
 * เว็บ : drugs_alert_worker.php?mode=ingest&start=YYYY-MM-DD&end=YYYY-MM-DD
 *        drugs_alert_worker.php?mode=send
 *        drugs_alert_worker.php?mode=both                        ← default (ปุ่ม Ingest+Send)
 *        drugs_alert_worker.php?mode=backfill&start=..&end=..     ← ปุ่ม Backfill ประวัติ
 *                                                                     (เก็บเป็น Sent ดูอย่างเดียว ไม่ยิง LINE)
 * CLI  : php drugs_alert_worker.php mode=both start=2026-01-01 end=2026-08-31
 *        php drugs_alert_worker.php dryrun
 *        php drugs_alert_worker.php start 2026-01-01 end 2026-08-31
 *
 * ไฟล์นี้เป็น worker ตัวจริง — run_drugs_alert.bat เรียกไฟล์นี้โดยตรง
 *
 * Date Range เริ่มต้นของ cron = เมื่อวาน+วันนี้ (ต่างจาก HAD ที่ 7 วัน) — เภสัชมักเพิ่มยา
 * เข้ารายการแล้วอยากเห็นผลจ่ายวันนี้ทันที ไม่ต้องแจ้งย้อนหลังไกล และกัน backfill/cron
 * ชนกันข้ามเที่ยงคืนด้วย (unique key กัน insert ซ้ำอยู่แล้ว)
 *
 * Send จะข้าม icode ที่ถูกเอาออกจากรายการเฝ้าระวังไปแล้ว (เทียบกับ module_filter('drugs_alert')
 * ปัจจุบัน) — ไม่ลบแถวเดิมใน queue แค่ไม่ส่งซ้ำ ถ้าอยากส่งแถวนั้นจริงๆ ใช้ปุ่ม "ส่งซ้ำทันที"
 * แบบเลือกเองในหน้า queue UI (ไม่ผ่านเงื่อนไข icode นี้)
 */
require_once __DIR__ . '/config.php';          // ← ต้องมาก่อน define คีย์เสมอ
require_once __DIR__ . '/flex_builders.php';
require_once __DIR__ . '/covid_lib.php';       // row_to_utf8(), extract_moph_message_id()
require_once __DIR__ . '/sources/drugs_alert_source.php';
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');

/* ── args (รับได้ทั้ง GET, CLI key=value, และสไตล์ dryrun/start/end เหมือน module อื่น) ── */
$args  = $_GET;
$isDry = false;
if ($isCli) {
  $cli = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--'));
  for ($i = 0; $i < count($cli); $i++) {
    $a = $cli[$i];
    if (strpos($a, '=') !== false)                                    { [$k,$v] = explode('=', $a, 2); $args[$k] = $v; }
    elseif ($a === 'dryrun')                                          { $isDry = true; }
    elseif (in_array($a, ['ingest','send','both','backfill'], true))  { $args['mode'] = $a; }
    elseif (($a === 'start' || $a === 'end') && isset($cli[$i+1]))    { $args[$a] = $cli[++$i]; }
  }
}
$isDry = $isDry || isset($args['dryrun']);
$mode  = $args['mode']  ?? 'both';
$start = $args['start'] ?? date('Y-m-d', strtotime('-1 day')); // เมื่อวาน
$end   = $args['end']   ?? date('Y-m-d');                       // วันนี้

if (!defined('DRUGS_ALERT_CLIENT_KEY')) define('DRUGS_ALERT_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('DRUGS_ALERT_SECRET_KEY')) define('DRUGS_ALERT_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);

$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$RUN_LOG  = $LOG_DIR . '/drugs_alert_task_run.log';
$SEND_LOG = $LOG_DIR . '/moph_alert_drugs_alert.log';

function da_out(string $line): void {
  global $RUN_LOG;
  $s = '[' . date('Y-m-d H:i:s') . '] ' . $line;
  echo $s . "\n";
  @file_put_contents($RUN_LOG, $s . "\n", FILE_APPEND);
}

$currentIcodes = module_filter('drugs_alert')['icodes'] ?? [];
da_out("=== DRUGS_ALERT START (mode={$mode} {$start}..{$end}" . ($isDry ? ' DRYRUN' : '') . ") ===");
da_out('รายการยาที่เฝ้าระวังตอนนี้: ' . ($currentIcodes ? implode(', ', $currentIcodes) : '(ยังไม่ได้เลือกยา)'));

/* ══ INGEST ══════════════════════════════════════════════════════════════
 * $asSent = true → backfill ประวัติ (เขียนเป็น status=1 ทันที ดูได้ในคิว/Dashboard แต่ Send
 * step จะไม่หยิบไปยิง LINE เพราะกรอง status=0) */
function drugs_alert_ingest(PDO $db, string $start, string $end, bool $asSent = false): array {
  $rows = drugs_alert_source_rows($start, $end);
  da_out('HOSxP: พบ ' . count($rows) . ' รายการ');

  $statusVal = $asSent ? 1 : 0;
  $ins = $db->prepare(
    "INSERT INTO drugs_alert_queue
       (hn, fullname, cid, hometel, age, sex, address, icode, drug_name, strength, units, vstdate, qty, sum_price, status)
     VALUES (:hn,:fn,:cid,:tel,:age,:sex,:addr,:code,:dn,:st,:un,:vd,:qty,:price,:status)
     ON DUPLICATE KEY UPDATE
       fullname=VALUES(fullname), drug_name=VALUES(drug_name), strength=VALUES(strength),
       units=VALUES(units), qty=VALUES(qty), sum_price=VALUES(sum_price)"
  );
  $exist = $db->prepare("SELECT id FROM drugs_alert_queue WHERE hn=? AND icode=? AND vstdate=?");

  $imported = 0; $new = 0; $skipped = 0;
  foreach ($rows as $r) {
    $r  = row_to_utf8($r);
    $hn = trim((string)($r['hn'] ?? ''));
    $vd = trim((string)($r['vstdate'] ?? ''));
    if ($hn === '' || $vd === '') { $skipped++; continue; }
    $exist->execute([$hn, (string)($r['icode'] ?? ''), $vd]);
    if (!$exist->fetch()) { $new++; da_out("NEW hn={$hn} icode={$r['icode']} date={$vd} = {$r['drug_name']}"); }
    $ins->execute([
      ':hn'=>$hn, ':fn'=>$r['fullname'] ?? '', ':cid'=>$r['cid'] ?? '', ':tel'=>$r['hometel'] ?? '',
      ':age'=>is_numeric($r['age'] ?? null) ? (int)$r['age'] : null, ':sex'=>$r['sex'] ?? '',
      ':addr'=>$r['address'] ?? '', ':code'=>$r['icode'] ?? '', ':dn'=>$r['drug_name'] ?? '',
      ':st'=>$r['strength'] ?? '', ':un'=>$r['units'] ?? '', ':vd'=>$vd,
      ':qty'=>is_numeric($r['qty'] ?? null) ? $r['qty'] : null,
      ':price'=>is_numeric($r['sum_price'] ?? null) ? $r['sum_price'] : null,
      ':status'=>$statusVal,
    ]);
    $imported++;
  }
  da_out("Upsert: imported={$imported} new={$new} skipped={$skipped}" . ($asSent ? ' (backfill -> status=1 Sent)' : ''));
  return [$imported, $new, $skipped];
}

/* ══ SEND ════════════════════════════════════════════════════════════════
 * ส่งเฉพาะ icode ที่ยังอยู่ในรายการเฝ้าระวังปัจจุบัน — ตัดยาที่เอาออกจาก list ไปแล้วออก
 * โดยไม่แก้ไข/ลบแถวเดิมในคิว (ยังดูประวัติได้ แค่ไม่ส่งซ้ำอัตโนมัติ) */
function drugs_alert_send_pending(PDO $db, array $activeIcodes, int $limit = 50, int $maxTry = 8, int $cooldownMin = 1): array {
  if (!$activeIcodes) { da_out('Send: ข้าม — ยังไม่ได้เลือกยาเฝ้าระวัง (module_filter icodes ว่าง)'); return [0, 0]; }

  $place = implode(',', array_fill(0, count($activeIcodes), '?'));
  $sql = "SELECT * FROM drugs_alert_queue
          WHERE status = 0
            AND icode IN ($place)
            AND attempt < ?
            AND (last_attempt_at IS NULL OR last_attempt_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
          ORDER BY id ASC LIMIT {$limit}";
  $q = $db->prepare($sql);
  $q->execute(array_merge($activeIcodes, [$maxTry, $cooldownMin]));
  $queue = $q->fetchAll(PDO::FETCH_ASSOC);
  da_out('Send: to process ' . count($queue) . " rows (cooldown={$cooldownMin}m, maxTry={$maxTry}, icodes=" . count($activeIcodes) . ')');

  $ok = 0; $fail = 0;
  foreach ($queue as $row) {
    global $SEND_LOG;
    $row  = row_to_utf8($row);
    $body = json_encode(buildDrugsAlertPayload($row), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL            => MOPH_API_URL,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $body,
      CURLOPT_HTTPHEADER     => [
        'client-key: ' . DRUGS_ALERT_CLIENT_KEY,
        'secret-key: ' . DRUGS_ALERT_SECRET_KEY,
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
      $db->prepare("UPDATE drugs_alert_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(),
                    attempt=attempt+1, last_error=NULL, out_ref=?, line_message_id=? WHERE id=?")
         ->execute([$ref, $ref, $row['id']]);
      da_out("SENT id={$row['id']} hn={$row['hn']} icode={$row['icode']} ref={$ref}");
      require_once __DIR__ . '/telegram_lib.php';
      telegram_mirror('drugs_alert', '💊 แจ้งเตือนยาเฝ้าระวัง', $row);
      $ok++;
    } else {
      $detail = $err ? "CURL: $err" : "MOPH error: HTTP=$code" . ($st !== null ? " status=$st" : '');
      $db->prepare("UPDATE drugs_alert_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=? WHERE id=?")
         ->execute([$detail, $row['id']]);
      da_out("FAIL id={$row['id']} {$detail}");
      $fail++;
    }
    usleep(random_int(10, 80) * 1000);
  }
  da_out("Send result: ok={$ok} fail={$fail}");
  return [$ok, $fail];
}

/* ══ RUN ═════════════════════════════════════════════════════════════════ */
try {
  if ($isDry) {
    $rows = drugs_alert_source_rows($start, $end);
    da_out('DRYRUN: HOSxP พบ ' . count($rows) . ' รายการ (ไม่เขียน queue ไม่ส่งแจ้งเตือน)');
    foreach (array_slice($rows, 0, 20) as $r) {
      $r = row_to_utf8($r);
      da_out(sprintf('  hn=%s icode=%s drug=%s date=%s',
        $r['hn'] ?? '-', $r['icode'] ?? '-', $r['drug_name'] ?? '-', $r['vstdate'] ?? '-'));
    }
    if (count($rows) > 20) da_out('  ... (แสดง 20 แถวแรก)');
  } elseif ($mode === 'backfill') {
    drugs_alert_ingest($dbcon, $start, $end, true);
  } else {
    if ($mode === 'ingest' || $mode === 'both') drugs_alert_ingest($dbcon, $start, $end, false);
    if ($mode === 'send'   || $mode === 'both') drugs_alert_send_pending($dbcon, $currentIcodes);
  }
  da_out('=== DRUGS_ALERT DONE ===');
} catch (Throwable $e) {
  da_out('ERROR: ' . $e->getMessage());
  if (!$isCli) http_response_code(500);
  exit(1);
}
