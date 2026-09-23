<?php
/**
 * HAD.php — ตัวรัน ingest / send ของ HAD Alert (High Alert Drug)
 *   (แบบเดียวกับ pharm_lab.php / lab_hemato.php — เรียกได้ทั้งจากเว็บและ CLI)
 *
 * เว็บ : HAD.php?mode=ingest&start=YYYY-MM-DD&end=YYYY-MM-DD
 *        HAD.php?mode=send
 *        HAD.php?mode=both            ← default
 * CLI  : php HAD.php mode=both start=2026-01-01 end=2026-08-31
 *        php HAD.php dryrun
 *        php HAD.php start 2026-01-01 end 2026-08-31
 *
 * ไฟล์นี้เป็น worker ตัวจริง — run_had.bat เรียกไฟล์นี้โดยตรง
 */
require_once __DIR__ . '/config.php';          // ← ต้องมาก่อน define คีย์เสมอ
require_once __DIR__ . '/flex_builders.php';
require_once __DIR__ . '/covid_lib.php';       // row_to_utf8(), extract_moph_message_id()
require_once __DIR__ . '/sources/had_source.php';
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
    if (strpos($a, '=') !== false)                        { [$k,$v] = explode('=', $a, 2); $args[$k] = $v; }
    elseif ($a === 'dryrun')                              { $isDry = true; }
    elseif (in_array($a, ['ingest','send','both'], true))  { $args['mode'] = $a; }
    elseif (($a === 'start' || $a === 'end') && isset($cli[$i+1])) { $args[$a] = $cli[++$i]; }
  }
}
$isDry = $isDry || isset($args['dryrun']);
$mode  = $args['mode']  ?? 'both';
$start = $args['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end   = $args['end']   ?? date('Y-m-d');

if (!defined('HAD_CLIENT_KEY')) define('HAD_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('HAD_SECRET_KEY')) define('HAD_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);

$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$RUN_LOG  = $LOG_DIR . '/had_task_run.log';
$SEND_LOG = $LOG_DIR . '/moph_alert_had.log';

function had_out(string $line): void {
  global $RUN_LOG;
  $s = '[' . date('Y-m-d H:i:s') . '] ' . $line;
  echo $s . "\n";
  @file_put_contents($RUN_LOG, $s . "\n", FILE_APPEND);
}

had_out("=== HAD START (mode={$mode} {$start}..{$end}" . ($isDry ? ' DRYRUN' : '') . ") ===");
had_out('รหัสยา (icode): ' . implode(', ', module_filter('had')['icodes'] ?? []));
// log บรรทัดนี้เฉพาะตอนเปิดใช้ — รพ. ส่วนใหญ่ปิดไว้ (default) จะได้ไม่มีบรรทัดเพิ่มใน log เปล่าๆ
// (worker วิ่งทุก 5 นาที = 288 รอบ/วัน ทุกบรรทัดที่เพิ่มคือ 288 บรรทัด/วัน และ log ไม่มี rotation)
if (alert_window('had')['enabled']) had_out('ช่วงเวลาแจ้งเตือน: ' . alert_window_summary('had'));

/* ══ INGEST ══════════════════════════════════════════════════════════════ */
function had_ingest(PDO $db, string $start, string $end): array {
  $rows = had_source_rows($start, $end);
  had_out('HOSxP: พบ ' . count($rows) . ' รายการ');

  $ins = $db->prepare(
    "INSERT INTO had_queue
       (hn, fullname, cid, hometel, age, sex, address, icode, drug_name, vstdate, qty, sum_price)
     VALUES (:hn,:fn,:cid,:tel,:age,:sex,:addr,:code,:dn,:vd,:qty,:price)
     ON DUPLICATE KEY UPDATE
       fullname=VALUES(fullname), drug_name=VALUES(drug_name), qty=VALUES(qty), sum_price=VALUES(sum_price)"
  );
  $exist = $db->prepare("SELECT id FROM had_queue WHERE hn=? AND icode=? AND vstdate=?");

  $imported = 0; $new = 0; $skipped = 0;
  foreach ($rows as $r) {
    $r  = row_to_utf8($r);
    $hn = trim((string)($r['hn'] ?? ''));
    $vd = trim((string)($r['vstdate'] ?? ''));
    if ($hn === '' || $vd === '') { $skipped++; continue; }
    $exist->execute([$hn, (string)($r['icode'] ?? ''), $vd]);
    if (!$exist->fetch()) { $new++; had_out("NEW hn={$hn} icode={$r['icode']} date={$vd} = {$r['drug_name']}"); }
    $ins->execute([
      ':hn'=>$hn, ':fn'=>$r['fullname'] ?? '', ':cid'=>$r['cid'] ?? '', ':tel'=>$r['hometel'] ?? '',
      ':age'=>is_numeric($r['age'] ?? null) ? (int)$r['age'] : null, ':sex'=>$r['sex'] ?? '',
      ':addr'=>$r['address'] ?? '', ':code'=>$r['icode'] ?? '', ':dn'=>$r['drug_name'] ?? '', ':vd'=>$vd,
      ':qty'=>is_numeric($r['qty'] ?? null) ? $r['qty'] : null,
      ':price'=>is_numeric($r['sum_price'] ?? null) ? $r['sum_price'] : null,
    ]);
    $imported++;
  }
  had_out("Upsert: imported={$imported} new={$new} skipped={$skipped}");
  return [$imported, $new, $skipped];
}

/* ══ SEND ════════════════════════════════════════════════════════════════ */
// $limit ผูกกับ AW_SEND_LIMIT (alert_window_loader.php) ที่เดียว — ห้ามเขียนเลขซ้ำตรงนี้
// เพราะโมดัล "ช่วงเวลาแจ้งเตือน" เอาเลขนี้ไปคำนวณโชว์ว่าส่งได้กี่รายการ/วัน ถ้าแยกกันจะโกหกผู้ใช้
function had_send_pending(PDO $db, int $limit = AW_SEND_LIMIT, int $maxTry = 8, int $cooldownMin = 1): array {
  // กรอง Alert Window ต่อแถว (created_at) ตรงนี้เอง — ไม่ใช่ทีหลังใน PHP หลัง fetch —
  // กัน starvation: ถ้ากรองทีหลัง แถวเก่านอกช่วง (id น้อย) จะบัง ORDER BY id ASC LIMIT
  // ไว้ทุกรอบ จนแถวใหม่ในช่วงเวลาไม่มีวันถูกดึงมาส่งเลย (ดู docs/adr/0004)
  [$winSql, $winParams] = alert_window_sql_condition('had', 'created_at', 'aw');
  $q = $db->prepare(
    "SELECT * FROM had_queue
     WHERE status = 0
       AND attempt < :maxtry
       AND (last_attempt_at IS NULL OR last_attempt_at < DATE_SUB(NOW(), INTERVAL :cd MINUTE))
       AND ({$winSql})
     ORDER BY id ASC LIMIT {$limit}"
  );
  $q->execute(array_merge([':maxtry' => $maxTry, ':cd' => $cooldownMin], $winParams));
  $queue = $q->fetchAll(PDO::FETCH_ASSOC);
  had_out('Send: to process ' . count($queue) . " rows (cooldown={$cooldownMin}m, maxTry={$maxTry})");

  $ok = 0; $fail = 0;
  foreach ($queue as $row) {
    global $SEND_LOG;
    $row  = row_to_utf8($row);
    $body = json_encode(buildHadPayload($row), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL            => MOPH_API_URL,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $body,
      CURLOPT_HTTPHEADER     => [
        'client-key: ' . HAD_CLIENT_KEY,
        'secret-key: ' . HAD_SECRET_KEY,
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
      $db->prepare("UPDATE had_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(),
                    attempt=attempt+1, last_error=NULL, out_ref=?, line_message_id=? WHERE id=?")
         ->execute([$ref, $ref, $row['id']]);
      had_out("SENT id={$row['id']} hn={$row['hn']} ref={$ref}");
      $ok++;
    } else {
      $detail = $err ? "CURL: $err" : "MOPH error: HTTP=$code" . ($st !== null ? " status=$st" : '');
      $db->prepare("UPDATE had_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=? WHERE id=?")
         ->execute([$detail, $row['id']]);
      had_out("FAIL id={$row['id']} {$detail}");
      $fail++;
    }
    usleep(random_int(10, 80) * 1000);
  }
  had_out("Send result: ok={$ok} fail={$fail}");
  return [$ok, $fail];
}

/* ══ RUN ═════════════════════════════════════════════════════════════════ */
try {
  if ($isDry) {
    $rows = had_source_rows($start, $end);
    had_out('DRYRUN: HOSxP พบ ' . count($rows) . ' รายการ (ไม่เขียน queue ไม่ส่งแจ้งเตือน)');
    foreach (array_slice($rows, 0, 20) as $r) {
      $r = row_to_utf8($r);
      had_out(sprintf('  hn=%s icode=%s drug=%s date=%s',
        $r['hn'] ?? '-', $r['icode'] ?? '-', $r['drug_name'] ?? '-', $r['vstdate'] ?? '-'));
    }
    if (count($rows) > 20) had_out('  ... (แสดง 20 แถวแรก)');
  } else {
    if ($mode === 'ingest' || $mode === 'both') had_ingest($dbcon, $start, $end);
    if ($mode === 'send'   || $mode === 'both') {
      /* ── Alert Window — กั้นเฉพาะขั้น Send เท่านั้น (ดู docs/adr/0004, เดิม 0003) ──
         Ingest ด้านบนยังวิ่งทั้งวันโดยตั้งใจ: หน้า Queue UI/Dashboard จะได้เห็นคนไข้
         ของวันนี้เป็น "รอส่ง" ตามจริง — เฉพาะแถวที่ created_at ตกอยู่ในช่วงเวลาเท่านั้น
         ที่ auto-send ได้ (กรองต่อแถวใน had_send_pending() เอง) แถวนอกช่วงจะค้าง
         Pending ตลอดไปแม้หน้าต่างจะเปิดในรอบถัดไปก็ตาม ต้องสั่งส่งเองทาง "ส่งซ้ำทันที"
         บังคับที่นี่ ไม่ใช่ที่ Cron เพราะการแก้ scheduled task ต้องใช้สิทธิ์
         Administrator/UAC (ดู task/install_tasks.bat) ซึ่งหน้าเว็บสั่งเองไม่ได้
         หมายเหตุ: ปุ่ม "ส่งซ้ำทันที" ในหน้า Queue UI ไม่ผ่านทางนี้ จึงไม่ถูกจำกัด */
      if (!alert_window_is_open('had')) {
        had_out('SKIP SEND: นอกช่วงเวลาแจ้งเตือน (' . alert_window_summary('had') . ') — '
              . 'คิวค้างไว้ก่อน จะเริ่มตรวจส่งอีกครั้งเวลา ' . alert_window_next_open('had') . ' น. '
              . '(เฉพาะแถวที่เกิดในช่วงเวลาเท่านั้นที่จะถูกส่งอัตโนมัติ)');
      } else {
        had_send_pending($dbcon, AW_SEND_LIMIT);
      }
    }
  }
  had_out('=== HAD DONE ===');
} catch (Throwable $e) {
  had_out('ERROR: ' . $e->getMessage());
  if (!$isCli) http_response_code(500);
  exit(1);
}
