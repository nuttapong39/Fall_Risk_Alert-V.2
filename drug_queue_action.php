<?php
/**
 * drug_queue_action.php
 * ตัวจัดการ bulk-action สำหรับ drugitems01.php
 *
 * Actions (POST):
 *   send_now      — ส่งแจ้งเตือน LINE ทันที
 *   requeue       — รีเซ็ต status → 0
 *   clear_error   — ล้าง last_error
 *
 * Actions (POST + JSON response):
 *   import_hosxp  — ดึงข้อมูลจาก HOSxP แล้ว upsert เข้า drug_queue (AJAX)
 */

require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/flex_drug.php';

date_default_timezone_set('Asia/Bangkok');

if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', hash('sha256', __DIR__ . '/drugitems01.php' . php_uname() . date('Y-m-d')));
}

// ── MOPH ALERT config ────────────────────────────────────────────────────────
if (!defined('MOPH_API_URL'))  define('MOPH_API_URL',  'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT'))  define('MOPH_TIMEOUT',  30);

// ใช้ DRUG_CLIENT_KEY หรือ fallback ไป MOPH_CLIENT_KEY
if (!defined('DRUG_CLIENT_KEY'))
  define('DRUG_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('DRUG_SECRET_KEY'))
  define('DRUG_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

// ── Logging ──────────────────────────────────────────────────────────────────
$LOG_DIR  = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = $LOG_DIR . '/moph_alert_drug.log';

function drug_log($row, $code, $resp, $err = null) {
  global $LOG_FILE;
  $line = sprintf("[%s] id=%s hn=%s http=%s err=%s resp=%s\n",
    date('Y-m-d H:i:s'), $row['id']??'-', $row['hn']??'-',
    $code, $err?:'-', mb_substr($resp??'', 0, 2000));
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

// ── Validate request ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); exit('Method not allowed');
}

$action = $_POST['action'] ?? '';

// ── AJAX: Import from HOSxP ──────────────────────────────────────────────────
if ($action === 'import_hosxp') {
  header('Content-Type: application/json; charset=utf-8');

  $icodesRaw = trim($_POST['icodes'] ?? '1483860');
  $impStart  = $_POST['start']  ?? date('Y-m-d', strtotime('-30 days'));
  $impEnd    = $_POST['end']    ?? date('Y-m-d');

  // แยก icode ด้วย , หรือ เว้นวรรค
  $icodeArr = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $icodesRaw))));
  if (!$icodeArr) {
    echo json_encode(['ok'=>false,'msg'=>'ไม่ได้ระบุรหัสยา (icode)']);
    exit;
  }

  try {
    // ---- Query HOSxP ----
    $place = implode(',', array_fill(0, count($icodeArr), '?'));
    /*
     * Query HOSxP — ปรับให้เหมือน Query เดิมที่ทำงานได้:
     *   - JOIN ovst ด้วย hn+vstdate (ไม่ใช่ vn เพราะ opitemrece.vn อาจเป็น NULL)
     *   - สร้าง visit_vn แบบ synthetic key = hn-YYYYMMDD-icode เมื่อ opi.vn ว่าง
     *   - GROUP BY hn, vstdate, icode เพื่อ deduplicate
     */
    $sql = "SELECT
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

    $params = array_merge([$impStart, $impEnd], $icodeArr);
    $stmt   = $dbcon->prepare($sql);
    $stmt->execute($params);
    $hosxpRows = $stmt->fetchAll();

    // ---- Upsert into drug_queue ----
    $ins = $dbcon->prepare(
      "INSERT INTO drug_queue
         (visit_vn, hn, fullname, cid, hometel, age, sex, address,
          drug_code, drug_name, vstdate, department, mainstation)
       VALUES (:vn,:hn,:fn,:cid,:tel,:age,:sex,:addr,:dc,:dn,:vd,:dept,:ms)
       ON DUPLICATE KEY UPDATE
         fullname=VALUES(fullname), hometel=VALUES(hometel),
         drug_name=VALUES(drug_name), department=VALUES(department)"
    );

    $imported = 0; $newRows = 0; $skipped = 0;
    // เตรียม stmt ตรวจ exist ครั้งเดียว (ประสิทธิภาพดีกว่า prepare ใน loop)
    $existStmt = $dbcon->prepare("SELECT id FROM drug_queue WHERE visit_vn=?");

    foreach ($hosxpRows as $hr) {
      $hr = row_to_utf8($hr);

      // ── Guard: ข้ามแถวที่ vn หรือ hn ว่าง ──
      $vn = trim((string)($hr['visit_vn'] ?? ''));
      $hn = trim((string)($hr['hn']       ?? ''));
      if ($vn === '' || $hn === '') { $skipped++; continue; }

      $existStmt->execute([$vn]);
      $isNew = !$existStmt->fetch();

      $ins->execute([
        ':vn'  => $vn,
        ':hn'  => $hn,
        ':fn'  => $hr['fullname']   ?? '',
        ':cid' => $hr['cid']        ?? '',
        ':tel' => $hr['hometel']    ?? '',
        ':age' => is_numeric($hr['age']) ? (int)$hr['age'] : null,
        ':sex' => $hr['sex']        ?? '',
        ':addr'=> $hr['address']    ?? '',
        ':dc'  => $hr['drug_code']  ?? '',
        ':dn'  => $hr['drug_name']  ?? '',
        ':vd'  => $hr['vstdate']    ?: null,
        ':dept'=> $hr['department'] ?? '',
        ':ms'  => $hr['mainstation']?? '',
      ]);
      $imported++;
      if ($isNew) $newRows++;
    }

    $skipNote = $skipped > 0 ? " (ข้าม {$skipped} แถวที่ไม่มี VN)" : '';
    echo json_encode(['ok'=>true, 'imported'=>$imported, 'new'=>$newRows, 'skipped'=>$skipped,
      'msg'=>"นำเข้าสำเร็จ {$imported} รายการ (ใหม่ {$newRows} รายการ){$skipNote}"]);

  } catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'msg'=>'เกิดข้อผิดพลาด: '.$e->getMessage()]);
  }
  exit;
}

// ── CSRF Token check (bulk actions only) ─────────────────────────────────────
if (!isset($_POST['token']) || $_POST['token'] !== UI_ACTION_TOKEN) {
  http_response_code(403); exit('Forbidden');
}

$ids = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
$ids = array_values(array_filter($ids, fn($x) => ctype_digit((string)$x)));
if (!$ids) { header('Location: drugitems01.php?msg=no_ids'); exit; }

// ── send_one_now helper ───────────────────────────────────────────────────────
function drug_send_one(PDO $db, int $id): array {
  $get = $db->prepare("SELECT * FROM drug_queue WHERE id=:id");
  $get->execute([':id'=>$id]);
  $row = $get->fetch();
  if (!$row) return [false, null, "id not found"];
  $row = row_to_utf8($row);

  $payload = buildDrugPayload($row);
  $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false) {
    $jsonErr = json_last_error_msg();
    drug_log($row, 0, null, "JSON_ENCODE_FAIL: $jsonErr");
    $db->prepare("UPDATE drug_queue SET last_attempt_at=NOW(),attempt=attempt+1,last_error=:e WHERE id=:id")
       ->execute([':e'=>"JSON encode failed: $jsonErr", ':id'=>$id]);
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

  drug_log($row, $code, $resp, $err);

  if ($err) {
    $db->prepare("UPDATE drug_queue SET last_attempt_at=NOW(),attempt=attempt+1,last_error=:e WHERE id=:id")
       ->execute([':e'=>"CURL: $err", ':id'=>$id]);
    return [false, null, "CURL: $err"];
  }

  $json      = json_decode($resp, true);
  $mid       = extract_moph_message_id($json);
  $apiStatus = is_array($json) && isset($json['status'])  ? $json['status']  : null;
  $apiMsg    = is_array($json) && isset($json['message']) ? (string)$json['message'] : null;
  $ok        = $mid
            || (is_numeric($apiStatus) && (int)$apiStatus === 200)
            || ($apiMsg && preg_match('/succ(e|)ss/i', $apiMsg));

  if (($code >= 200 && $code < 300) && $ok) {
    $ref = $mid ?: ($apiStatus ? "status:$apiStatus" : "HTTP$code");
    $db->prepare("UPDATE drug_queue SET status=1,sent_at=NOW(),last_attempt_at=NOW(),attempt=attempt+1,last_error=NULL,out_ref=:r,line_message_id=:r WHERE id=:id")
       ->execute([':r'=>$ref, ':id'=>$id]);
    return [true, $ref, null];
  }

  $detail = "HTTP=$code";
  if ($apiStatus !== null) $detail .= " status=$apiStatus";
  if ($apiMsg)             $detail .= " msg=$apiMsg";
  $db->prepare("UPDATE drug_queue SET last_attempt_at=NOW(),attempt=attempt+1,last_error=:e WHERE id=:id")
     ->execute([':e'=>"MOPH error: $detail", ':id'=>$id]);
  return [false, null, "MOPH error: $detail"];
}

// ── Execute bulk action ───────────────────────────────────────────────────────
try {
  $place = implode(',', array_fill(0, count($ids), '?'));

  if ($action === 'requeue') {
    $stmt = $dbcon->prepare("UPDATE drug_queue SET status=0,attempt=0,last_attempt_at=NULL,last_error=NULL,out_ref=NULL,line_message_id=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: drugitems01.php?msg=requeued&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'clear_error') {
    $stmt = $dbcon->prepare("UPDATE drug_queue SET last_error=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: drugitems01.php?msg=cleared&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'send_now') {
    $ok = 0; $fail = 0;
    foreach ($ids as $id) {
      [$o] = drug_send_one($dbcon, (int)$id);
      if ($o) $ok++; else $fail++;
    }
    header('Location: drugitems01.php?msg=sendnow&ok='.$ok.'&fail='.$fail); exit;

  } else {
    header('Location: drugitems01.php?msg=bad_action'); exit;
  }
} catch (Throwable $e) {
  header('Location: drugitems01.php?msg=err&detail='.urlencode($e->getMessage()));
}
