<?php
require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/covid_lib.php';     // มี to_utf8(), row_to_utf8(), extract_moph_message_id()
require_once __DIR__ . '/flex_fracture.php'; // ไลบรารี Flex กลาง — ต้องมาก่อนฟังก์ชันท้องถิ่น

date_default_timezone_set('Asia/Bangkok');

// ให้ action สร้าง UI_ACTION_TOKEN แบบเดียวกับหน้า UI
if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', hash('sha256', __DIR__ . '/fracture_queue_ui.php' . php_uname() . date('Y-m-d')));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

$action = trim($_POST['action'] ?? '');

/* ══ AJAX ── import_hosxp (no CSRF required) ══════════════════════════════ */
if ($action === 'import_hosxp') {
  header('Content-Type: application/json; charset=utf-8');
  $impStart = trim($_POST['start']   ?? date('Y-m-d', strtotime('-7 days')));
  $impEnd   = trim($_POST['end']     ?? date('Y-m-d'));
  $minAge   = max(0, (int)($_POST['min_age'] ?? 60));

  try {
    $sql = "SELECT
              vs.vn                                                       AS visit_vn,
              vs.hn,
              CONCAT(pt.pname, pt.fname, ' ', pt.lname)                  AS fullname,
              pt.cid,
              pt.hometel,
              TIMESTAMPDIFF(YEAR, pt.birthday, vs.vstdate)               AS age,
              CASE WHEN pt.sex='1' THEN 'ชาย'
                   WHEN pt.sex='2' THEN 'หญิง' ELSE '' END               AS sex,
              pt.informaddr                                               AS address,
              vs.pdx                                                      AS pdx_code,
              i.name                                                      AS pdx_name,
              vs.vstdate,
              COALESCE(ksk.name, '')                                      AS mainstation
            FROM   vn_stat vs
            LEFT JOIN patient       pt  ON pt.hn       = vs.hn
            LEFT JOIN icd101        i   ON i.code      = vs.pdx
            LEFT JOIN kskdepartment ksk ON ksk.depcode = vs.main_dep_code
            WHERE  vs.vstdate BETWEEN ? AND ?
            AND    TIMESTAMPDIFF(YEAR, pt.birthday, vs.vstdate) >= ?
            AND    (
              (UPPER(vs.pdx) BETWEEN 'W00' AND 'W19')
              OR UPPER(vs.pdx) LIKE 'S720%' OR UPPER(vs.pdx) LIKE 'S721%'
              OR UPPER(vs.pdx) LIKE 'S722%' OR UPPER(vs.pdx) LIKE 'S525%'
              OR UPPER(vs.pdx) LIKE 'S526%' OR UPPER(vs.pdx) LIKE 'S422%'
              OR UPPER(vs.pdx) LIKE 'S220%' OR UPPER(vs.pdx) LIKE 'S221%'
              OR UPPER(vs.pdx) LIKE 'S320%' OR UPPER(vs.pdx) LIKE 'S327%'
            )
            AND    vs.hn IS NOT NULL AND vs.hn != ''
            ORDER  BY vs.vstdate DESC
            LIMIT  2000";

    $stmt = $dbcon->prepare($sql);
    $stmt->execute([$impStart, $impEnd, $minAge]);
    $hosxpRows = $stmt->fetchAll();

    $ins = $dbcon->prepare(
      "INSERT INTO fracture_queue
         (visit_vn, hn, fullname, cid, hometel, age, sex, address,
          pdx_code, pdx_name, vstdate, mainstation)
       VALUES (:vn,:hn,:fn,:cid,:tel,:age,:sex,:addr,:dc,:dn,:vd,:ms)
       ON DUPLICATE KEY UPDATE
         fullname=VALUES(fullname), hometel=VALUES(hometel),
         pdx_name=VALUES(pdx_name), mainstation=VALUES(mainstation)"
    );
    $existStmt = $dbcon->prepare("SELECT id FROM fracture_queue WHERE visit_vn=?");
    $imported = 0; $newRows = 0; $skipped = 0;

    foreach ($hosxpRows as $hr) {
      $hr = row_to_utf8($hr);
      $vn = trim((string)($hr['visit_vn'] ?? ''));
      $hn = trim((string)($hr['hn']       ?? ''));
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
        ':dc'  => $hr['pdx_code']    ?? '',
        ':dn'  => $hr['pdx_name']    ?? '',
        ':vd'  => $hr['vstdate']     ?: null,
        ':ms'  => $hr['mainstation'] ?? '',
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

if (!isset($_POST['token']) || !defined('UI_ACTION_TOKEN') || $_POST['token'] !== UI_ACTION_TOKEN) {
  http_response_code(403); exit('Forbidden');
}

$action = $_POST['action'] ?? '';
$ids    = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
$ids    = array_values(array_filter($ids, fn($x)=>ctype_digit((string)$x)));
if (!$ids) { header('Location: fracture_queue_ui.php?msg=no_ids'); exit; }

// ====== CONFIG (สำหรับส่ง MOPH Alert ฝั่ง Fracture) ======
if (!defined('MOPH_API_URL')) define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);

// ใช้ key เดียวกับ MOPH ถ้าไม่ได้กำหนดแยก
if (!defined('FRACTURE_CLIENT_KEY')) {
  define('FRACTURE_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
}
if (!defined('FRACTURE_SECRET_KEY')) {
  define('FRACTURE_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');
}

// FALL_TITLE / FALL_HEADER_URL / FALL_ICON_URL — define ไว้แล้วใน flex_fracture.php

$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . '/moph_alert_fracture.log';

// ====== Helpers ======
// buildFracturePayload() / extract_moph_message_id() / to_utf8() / row_to_utf8()
// ถูก define ไว้ใน flex_fracture.php + covid_lib.php แล้ว — ไม่ประกาศซ้ำ

function log_moph_response($row, $code, $resp, $err=null) {
  global $LOG_FILE;
  $line = sprintf("[%s] id=%s hn=%s http=%s err=%s resp=%s\n",
  date('Y-m-d H:i:s'), $row['id']??'-', $row['hn']??'-',
  $code, $err ?: '-', mb_substr($resp ?? '', 0, 2000)
);

  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}
function send_one_now(PDO $db, int $id): array {
  $get = $db->prepare("SELECT * FROM fracture_queue WHERE id=:id");
  $get->execute([':id'=>$id]);
  $row = $get->fetch();
  if (!$row) return [false, null, "id not found"];
  $row = row_to_utf8($row);

  $payload = buildFracturePayload($row);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false) {
    $jsonErr = json_last_error_msg();
    log_moph_response($row, 0, null, "JSON_ENCODE_FAIL: ".$jsonErr);
    $upd = $db->prepare("UPDATE fracture_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
    $upd->execute([':e'=>"JSON encode failed: ".$jsonErr, ':id'=>$id]);
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

  if ($err) {
    $upd = $db->prepare("UPDATE fracture_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
    $upd->execute([':e'=>"CURL: $err", ':id'=>$id]);
    return [false, null, "CURL: $err"];
  }

  $json = json_decode($resp, true);
  $mid  = extract_moph_message_id($json);
  $apiStatus = is_array($json) && array_key_exists('status',$json) ? $json['status'] : null;
  $apiMsg    = is_array($json) && array_key_exists('message',$json) ? (string)$json['message'] : null;
  $looksSuccess = ($mid) || (is_numeric($apiStatus) && (int)$apiStatus===200) || ($apiMsg && preg_match('/succ(e|)ss/i',$apiMsg));

  if (($code>=200 && $code<300) && $looksSuccess) {
    $ref = $mid ?: ($apiStatus ? "status:$apiStatus" : 'HTTP'.$code);
    $upd = $db->prepare("UPDATE fracture_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r WHERE id=:id");
    $upd->execute([':r'=>$ref, ':id'=>$id]);
    return [true, $ref, null];
  }

  $detail = "HTTP=$code"; if ($apiStatus!==null) $detail.=" status=$apiStatus"; if ($apiMsg) $detail.=" msg=$apiMsg";
  $upd = $db->prepare("UPDATE fracture_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");
  $upd->execute([':e'=>"MOPH error: $detail", ':id'=>$id]);
  return [false, null, "MOPH error: $detail"];
}

// ====== Execute action ======
try {
  if ($action === 'requeue') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("UPDATE fracture_queue SET status=0, attempt=0,last_attempt_at=NULL, last_error=NULL, out_ref=NULL, line_message_id=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: fracture_queue_ui.php?msg=requeued&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'clear_error') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("UPDATE fracture_queue SET last_error=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: fracture_queue_ui.php?msg=cleared&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'send_now') {
    $ok=0; $fail=0;
    foreach ($ids as $id) {
      [$o,$r,$e] = send_one_now($dbcon, (int)$id);
      if ($o) $ok++; else $fail++;
    }
    header('Location: fracture_queue_ui.php?msg=sendnow&ok='.$ok.'&fail='.$fail); exit;

  } else {
    header('Location: fracture_queue_ui.php?msg=bad_action'); exit;
  }
} catch (Throwable $e) {
  header('Location: fracture_queue_ui.php?msg=err&detail='.urlencode($e->getMessage()));
}
