<?php
/**
 * lab_hemato_queue_action.php — endpoint ของหน้า lab_hemato_queue_ui.php
 *   action=import_hosxp : AJAX (JSON) — sync จาก HOSxP เข้า lab_hemato_queue
 *   action=send_now     : ส่งซ้ำทันที (bypass cooldown)
 *   action=requeue      : รีเซ็ต status=0 attempt=0
 *   action=clear_error  : ล้างข้อความ error
 * 3 ตัวหลังต้องมี token + ids[] แล้ว redirect กลับ UI พร้อม ?msg=
 */
require_once __DIR__ . '/config.php';          // ← ต้องมาก่อน define คีย์เสมอ (ดู skill medalert-workers)
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

require_once __DIR__ . '/flex_builders.php';   // buildLabHematoPayload()
require_once __DIR__ . '/covid_lib.php';       // to_utf8(), row_to_utf8(), extract_moph_message_id()

$action = trim($_POST['action'] ?? '');

/* ══ AJAX — import_hosxp ════════════════════════════════════════════════════ */
if ($action === 'import_hosxp') {
  header('Content-Type: application/json; charset=utf-8');
  $start = trim($_POST['start'] ?? date('Y-m-d', strtotime('-7 days')));
  $end   = trim($_POST['end']   ?? date('Y-m-d'));

  try {
    require_once __DIR__ . '/sources/lab_hemato_source.php';
    $rows = lab_hemato_source_rows($start, $end);

    // UNIQUE (hn, lab_order_number, lab_items_code) — 1 ใบสั่งมีได้หลายรายการตรวจ
    $ins = $dbcon->prepare(
      "INSERT INTO lab_hemato_queue
         (hn, vn, fullname, age, sex, hometel, lab_order_number, lab_items_code,
          result, lab_date, lab_time, doctor, patient_type)
       VALUES (:hn,:vn,:fn,:age,:sex,:tel,:ord,:code,:res,:ld,:lt,:doc,:pt)
       ON DUPLICATE KEY UPDATE
         fullname=VALUES(fullname), result=VALUES(result),
         lab_date=VALUES(lab_date), lab_time=VALUES(lab_time), doctor=VALUES(doctor)"
    );
    $exist = $dbcon->prepare(
      "SELECT id FROM lab_hemato_queue WHERE hn=? AND lab_order_number=? AND lab_items_code=?"
    );

    $imported = 0; $newRows = 0; $skipped = 0;
    foreach ($rows as $r) {
      $r  = row_to_utf8($r);
      $hn = trim((string)($r['hn'] ?? ''));
      $on = trim((string)($r['lab_order_number'] ?? ''));
      if ($hn === '' || $on === '') { $skipped++; continue; }

      $exist->execute([$hn, $on, (string)($r['lab_items_code'] ?? '')]);
      $isNew = !$exist->fetch();

      $ins->execute([
        ':hn'   => $hn,
        ':vn'   => $r['vn'] ?? null,
        ':fn'   => $r['fullname'] ?? '',
        ':age'  => is_numeric($r['age'] ?? null) ? (int)$r['age'] : null,
        ':sex'  => $r['sex'] ?? '',
        ':tel'  => $r['hometel'] ?? '',
        ':ord'  => $on,
        ':code' => $r['lab_items_code'] ?? '',
        ':res'  => $r['result'] ?? '',
        ':ld'   => $r['lab_date'] ?: null,
        ':lt'   => $r['lab_time'] ?: null,
        ':doc'  => $r['doctor'] ?? '',
        ':pt'   => $r['patient_type'] ?? 'OPD',
      ]);
      $imported++;
      if ($isNew) $newRows++;
    }

    $note = $skipped > 0 ? " (ข้าม {$skipped} แถว)" : '';
    echo json_encode(['ok'=>true, 'imported'=>$imported, 'new'=>$newRows, 'skipped'=>$skipped,
      'msg'=>"นำเข้าสำเร็จ {$imported} รายการ (ใหม่ {$newRows} รายการ){$note}"], JSON_UNESCAPED_UNICODE);

  } catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'msg'=>'เกิดข้อผิดพลาด: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

/* ══ Token (seed เดียวกับหน้า UI) ═══════════════════════════════════════════ */
if (!defined('LAB_HEMATO_UI_ACTION_TOKEN')) {
  $uiFile = __DIR__ . DIRECTORY_SEPARATOR . 'lab_hemato_queue_ui.php';
  define('LAB_HEMATO_UI_ACTION_TOKEN', hash('sha256', $uiFile . php_uname() . date('Y-m-d')));
}
if (!isset($_POST['token']) || $_POST['token'] !== LAB_HEMATO_UI_ACTION_TOKEN) {
  http_response_code(403); exit('Forbidden');
}

$ids = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
$ids = array_values(array_filter($ids, fn($x) => ctype_digit((string)$x)));
if (!$ids) { header('Location: lab_hemato_queue_ui.php?msg=no_ids'); exit; }

/* ══ CONFIG ════════════════════════════════════════════════════════════════ */
if (!defined('MOPH_TIMEOUT'))         define('MOPH_TIMEOUT',         20);
if (!defined('MOPH_CONNECT_TIMEOUT')) define('MOPH_CONNECT_TIMEOUT', 8);
// คีย์ราย module — moph_keys_loader โหลด default ไว้แล้วตอน config.php
if (!defined('LAB_HEMATO_CLIENT_KEY')) define('LAB_HEMATO_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('LAB_HEMATO_SECRET_KEY')) define('LAB_HEMATO_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$SEND_LOG = $LOG_DIR . '/moph_alert_lab_hemato.log';

function lh_log_send($row, $code, $resp, $err = null) {
  global $SEND_LOG;
  @file_put_contents($SEND_LOG, sprintf(
    "[%s] id=%s hn=%s code=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'), $row['id'] ?? '-', $row['hn'] ?? '-',
    $row['lab_items_code'] ?? '-', $code, $err ?: '-', mb_substr($resp ?? '', 0, 2000)
  ), FILE_APPEND);
}

/* ══ ส่ง 1 รายการ ═══════════════════════════════════════════════════════════ */
function lh_send_one(PDO $db, int $id): array {
  $get = $db->prepare("SELECT * FROM lab_hemato_queue WHERE id=:id");
  $get->execute([':id' => $id]);
  $row = $get->fetch(PDO::FETCH_ASSOC);
  if (!$row) return [false, null, 'id not found'];
  $row = row_to_utf8($row);

  $body = json_encode(buildLabHematoPayload($row), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false) {
    $e = json_last_error_msg();
    lh_log_send($row, 0, null, "JSON_ENCODE_FAIL: $e");
    $db->prepare("UPDATE lab_hemato_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id")
       ->execute([':e' => "JSON encode failed: $e", ':id' => $id]);
    return [false, null, "JSON encode failed: $e"];
  }

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => MOPH_API_URL,          // มาจาก secrets/moph_endpoint.json
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
      'client-key: ' . LAB_HEMATO_CLIENT_KEY,
      'secret-key: ' . LAB_HEMATO_SECRET_KEY,
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json',
      'Expect:',
    ],
    CURLOPT_TIMEOUT        => MOPH_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => MOPH_CONNECT_TIMEOUT,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  lh_log_send($row, $code, $resp, $err ?: null);

  if ($err) {
    $db->prepare("UPDATE lab_hemato_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id")
       ->execute([':e' => "CURL: $err", ':id' => $id]);
    return [false, null, "CURL: $err"];
  }

  // MOPH ตอบ 200 เสมอ แม้ body จะเป็น error → ต้องอ่าน body ประกอบทุกครั้ง
  $json = @json_decode($resp, true);
  $mid  = extract_moph_message_id($json);
  $st   = is_array($json) && array_key_exists('status', $json)  ? $json['status'] : null;
  $msg  = is_array($json) && array_key_exists('message', $json) ? (string)$json['message'] : null;
  $ok   = ($mid) || (is_numeric($st) && (int)$st === 200) || ($msg && preg_match('/succ(e|)ss/i', $msg));

  if ($code >= 200 && $code < 300 && $ok) {
    $ref = $mid ?: ($st ? "status:$st" : 'HTTP' . $code);
    $db->prepare("UPDATE lab_hemato_queue
                  SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1,
                      last_error=NULL, out_ref=:r, line_message_id=:r
                  WHERE id=:id")->execute([':r' => $ref, ':id' => $id]);
    return [true, $ref, null];
  }

  $detail = "HTTP=$code" . ($st !== null ? " status=$st" : '') . ($msg ? " msg=$msg" : '');
  $db->prepare("UPDATE lab_hemato_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id")
     ->execute([':e' => "MOPH error: $detail", ':id' => $id]);
  return [false, null, "MOPH error: $detail"];
}

/* ══ Execute ═══════════════════════════════════════════════════════════════ */
try {
  $place = implode(',', array_fill(0, count($ids), '?'));

  if ($action === 'requeue') {
    $st = $dbcon->prepare("UPDATE lab_hemato_queue
                           SET status=0, attempt=0, last_attempt_at=NULL, last_error=NULL,
                               out_ref=NULL, line_message_id=NULL, sent_at=NULL
                           WHERE id IN ($place)");
    $st->execute($ids);
    header('Location: lab_hemato_queue_ui.php?msg=requeued&affected=' . $st->rowCount()); exit;

  } elseif ($action === 'clear_error') {
    $st = $dbcon->prepare("UPDATE lab_hemato_queue SET last_error=NULL WHERE id IN ($place)");
    $st->execute($ids);
    header('Location: lab_hemato_queue_ui.php?msg=cleared&affected=' . $st->rowCount()); exit;

  } elseif ($action === 'send_now') {
    $ok = 0; $fail = 0;
    foreach ($ids as $id) {
      [$o] = lh_send_one($dbcon, (int)$id);
      $o ? $ok++ : $fail++;
      usleep(random_int(10, 80) * 1000);   // เว้นจังหวะกัน rate limit ฝั่ง MOPH
    }
    header('Location: lab_hemato_queue_ui.php?msg=sendnow&ok=' . $ok . '&fail=' . $fail); exit;

  } else {
    header('Location: lab_hemato_queue_ui.php?msg=bad_action'); exit;
  }
} catch (Throwable $e) {
  header('Location: lab_hemato_queue_ui.php?msg=err&detail=' . urlencode($e->getMessage())); exit;
}
