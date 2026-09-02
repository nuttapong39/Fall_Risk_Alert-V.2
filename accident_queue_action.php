<?php
require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

$action = trim($_POST['action'] ?? '');

/* ══ AJAX ── import_hosxp (no CSRF required) ══════════════════════════════ */
if ($action === 'import_hosxp') {
  header('Content-Type: application/json; charset=utf-8');
  $impStart   = trim($_POST['start']   ?? date('Y-m-d', strtotime('-7 days')));
  $impEnd     = trim($_POST['end']     ?? date('Y-m-d'));
  // default = เงื่อนไขจาก store (แก้ผ่าน modal ในหน้า queue_ui) — ส่ง pttypes มา override ต่อครั้งได้
  $pttypesRaw = trim($_POST['pttypes'] ?? implode(',', module_filter('accident')['pttypes']));

  // Validate — เก็บเฉพาะ alphanumeric (กัน SQL injection ใน IN clause)
  $ptArr = mf_codes(preg_split('/[\s,]+/', $pttypesRaw));
  if (!$ptArr) {
    echo json_encode(['ok'=>false, 'msg'=>'ไม่ได้ระบุรหัสสิทธิ (pttype)']);
    exit;
  }

  // row_to_utf8 is provided by flex_accident.php — but require it here so it's available
  require_once __DIR__ . '/flex_accident.php';

  try {
    require_once __DIR__ . '/sources/accident_source.php';
    $hosxpRows = accident_source_ovst_rows($impStart, $impEnd, $ptArr);

    $ins = $dbcon->prepare(
      "INSERT INTO accident_queue (an, hn, fullname, regdate, regtime, pttype, pttname)
       VALUES (:an,:hn,:fn,:rd,:rt,:pt,:ptn)
       ON DUPLICATE KEY UPDATE
         fullname=VALUES(fullname), pttname=VALUES(pttname), regtime=VALUES(regtime)"
    );
    $existStmt = $dbcon->prepare("SELECT id FROM accident_queue WHERE an=?");
    $imported = 0; $newRows = 0; $skipped = 0;

    foreach ($hosxpRows as $hr) {
      $hr = row_to_utf8($hr);
      $an = trim((string)($hr['an'] ?? ''));
      $hn = trim((string)($hr['hn'] ?? ''));
      if ($an === '' || $hn === '') { $skipped++; continue; }

      $existStmt->execute([$an]);
      $isNew = !$existStmt->fetch();

      $ins->execute([
        ':an'  => $an,
        ':hn'  => $hn,
        ':fn'  => $hr['fullname'] ?? '',
        ':rd'  => $hr['regdate']  ?: null,
        ':rt'  => $hr['regtime']  ?? '',
        ':pt'  => $hr['pttype']   ?? '',
        ':ptn' => $hr['pttname']  ?? '',
      ]);
      $imported++;
      if ($isNew) $newRows++;
    }

    $skipNote = $skipped > 0 ? " (ข้าม {$skipped} แถว)" : '';
    echo json_encode(['ok'=>true, 'imported'=>$imported, 'new'=>$newRows, 'skipped'=>$skipped,
      'msg'=>"นำเข้าสำเร็จ {$imported} รายการ (ใหม่ {$newRows} รายการ){$skipNote}"]);

  } catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'msg'=>'เกิดข้อผิดพลาด: '.$e->getMessage()]);
  }
  exit;
}

/* ===================== Flex + shared helpers ===================== */
require_once __DIR__ . '/flex_accident.php';
// Provides: to_utf8(), row_to_utf8(), acc_thai_date(), buildAccidentPayload()

/* ===================== Token (same seed as UI) ===================== */
if (!defined('ACCIDENT_UI_ACTION_TOKEN')) {
  $uiFile = __DIR__ . DIRECTORY_SEPARATOR . 'accident_queue_ui.php';
  define('ACCIDENT_UI_ACTION_TOKEN', hash('sha256', $uiFile . php_uname() . date('Y-m-d')));
}
if (!isset($_POST['token']) || $_POST['token'] !== ACCIDENT_UI_ACTION_TOKEN) {
  http_response_code(403); exit('Forbidden');
}

$action = $_POST['action'] ?? '';
$ids    = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
$ids    = array_values(array_filter($ids, fn($x)=>ctype_digit((string)$x)));
if (!$ids) { header('Location: accident_queue_ui.php?msg=no_ids'); exit; }

/* ===================== CONFIG ===================== */
if (!defined('MOPH_TIMEOUT'))         define('MOPH_TIMEOUT',         20);
if (!defined('MOPH_CONNECT_TIMEOUT')) define('MOPH_CONNECT_TIMEOUT', 8);

if (!defined('ACCIDENT_CLIENT_KEY')) define('ACCIDENT_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('ACCIDENT_SECRET_KEY')) define('ACCIDENT_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

/* ===================== LOG ===================== */
$LOG_DIR  = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$SEND_LOG = $LOG_DIR . '/moph_alert_accident.log';

function acc_q_log_send($row, $code, $resp, $err = null) {
  global $SEND_LOG;
  $line = sprintf(
    "[%s] id=%s an=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'),
    $row['id'] ?? '-',
    $row['an']  ?? '-',
    $code,
    $err ?: '-',
    mb_substr($resp ?? '', 0, 2000)
  );
  @file_put_contents($SEND_LOG, $line, FILE_APPEND);
}

/* ===================== MOPH message-id extractor ===================== */
if (!function_exists('extract_moph_message_id')) {
  function extract_moph_message_id($json) {
    if (!is_array($json)) return null;
    $paths = [
      ['messageId'],
      ['data','messageId'],
      ['result','messageId'],
      ['messages',0,'messageId'],
      ['messages',0,'id'],
    ];
    foreach ($paths as $path) {
      $t = $json;
      foreach ($path as $k) {
        if (is_array($t) && array_key_exists($k, $t)) $t = $t[$k];
        else { $t = null; break; }
      }
      if (is_scalar($t) && $t !== '') return (string)$t;
    }
    return null;
  }
}

/* ===================== send_one_now ===================== */
function send_one_now(PDO $db, int $id): array {
  $get = $db->prepare("SELECT * FROM accident_queue WHERE id=:id");
  $get->execute([':id' => $id]);
  $row = $get->fetch(PDO::FETCH_ASSOC);
  if (!$row) return [false, null, "id not found"];
  $row = row_to_utf8($row);

  $payload = buildAccidentPayload($row);   // v2 from flex_accident.php
  $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false) {
    $jsonErr = json_last_error_msg();
    acc_q_log_send($row, 0, null, "JSON_ENCODE_FAIL: " . $jsonErr);
    $upd = $db->prepare("UPDATE accident_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
    $upd->execute([':e' => "JSON encode failed: " . $jsonErr, ':id' => $id]);
    return [false, null, "JSON encode failed: " . $jsonErr];
  }

  $headers = [
    'client-key: ' . ACCIDENT_CLIENT_KEY,
    'secret-key: ' . ACCIDENT_SECRET_KEY,
    'Content-Type: application/json; charset=UTF-8',
    'Accept: application/json',
    'Expect:',
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

  acc_q_log_send($row, $code, $resp, $err ?: null);

  if ($err) {
    $upd = $db->prepare("UPDATE accident_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
    $upd->execute([':e' => "CURL: $err", ':id' => $id]);
    return [false, null, "CURL: $err"];
  }

  $json = @json_decode($resp, true);
  $mid  = extract_moph_message_id($json);
  $st   = is_array($json) && array_key_exists('status',  $json) ? $json['status']  : null;
  $msg  = is_array($json) && array_key_exists('message', $json) ? (string)$json['message'] : null;

  $looksSuccess = ($mid) || (is_numeric($st) && (int)$st === 200) || ($msg && preg_match('/succ(e|)ss/i', $msg));
  if (($code >= 200 && $code < 300) && $looksSuccess) {
    $ref = $mid ?: ($st ? "status:$st" : 'HTTP' . $code);
    $upd = $db->prepare("
      UPDATE accident_queue
      SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1,
          last_error=NULL, out_ref=:r, line_message_id=:r
      WHERE id=:id
    ");
    $upd->execute([':r' => $ref, ':id' => $id]);
    return [true, $ref, null];
  }

  $detail = "HTTP=$code";
  if ($st  !== null) $detail .= " status=$st";
  if ($msg)          $detail .= " msg=$msg";

  $upd = $db->prepare("UPDATE accident_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
  $upd->execute([':e' => "MOPH error: $detail", ':id' => $id]);
  return [false, null, "MOPH error: $detail"];
}

/* ===================== Execute action ===================== */
try {
  if ($action === 'requeue') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("
      UPDATE accident_queue
      SET status=0, attempt=0, last_attempt_at=NULL, last_error=NULL,
          out_ref=NULL, line_message_id=NULL, sent_at=NULL
      WHERE id IN ($place)
    ");
    $stmt->execute($ids);
    header('Location: accident_queue_ui.php?msg=requeued&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'clear_error') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("UPDATE accident_queue SET last_error=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: accident_queue_ui.php?msg=cleared&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'send_now') {
    $ok = 0; $fail = 0;
    foreach ($ids as $id) {
      [$o, $r, $e] = send_one_now($dbcon, (int)$id);
      if ($o) $ok++; else $fail++;
      usleep(random_int(10, 80) * 1000);
    }
    header('Location: accident_queue_ui.php?msg=sendnow&ok='.$ok.'&fail='.$fail); exit;

  } else {
    header('Location: accident_queue_ui.php?msg=bad_action'); exit;
  }
} catch (Throwable $e) {
  header('Location: accident_queue_ui.php?msg=err&detail='.urlencode($e->getMessage()));
}
