<?php
/**
 * covid_queue_action.php — Action handler สำหรับ covid_queue_ui.php
 *  - import_hosxp  : AJAX, ไม่ต้องใช้ CSRF token
 *  - send_now      : ส่งซ้ำทันที (ใช้ send_one_by_id() จาก covid_lib.php)
 *  - requeue       : ตั้งสถานะเป็น 0
 *  - clear_error   : ล้าง last_error
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/covid_lib.php'; // to_utf8(), row_to_utf8(), send_one_by_id(), extract_moph_message_id()
date_default_timezone_set('Asia/Bangkok');

// ให้ action สร้าง UI_ACTION_TOKEN แบบเดียวกับหน้า UI
if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', hash('sha256', __DIR__ . '/covid_queue_ui.php' . php_uname() . date('Y-m-d')));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

$action = trim($_POST['action'] ?? '');

/* ══ AJAX ── import_hosxp (no CSRF required) ═══════════════════════════════ */
if ($action === 'import_hosxp') {
  header('Content-Type: application/json; charset=utf-8');

  $impStart    = trim($_POST['start']     ?? date('Y-m-d', strtotime('-7 days')));
  $impEnd      = trim($_POST['end']       ?? date('Y-m-d'));
  $labCodesRaw = trim($_POST['lab_codes'] ?? '3066,3082,3084,3088');

  // Validate — digits only (guard against SQL injection via IN clause)
  $labCodes = array_values(array_filter(
    array_map('trim', explode(',', $labCodesRaw)),
    fn($c) => ctype_digit($c) && $c !== ''
  ));

  if (empty($labCodes)) {
    echo json_encode(['ok'=>false, 'msg'=>'รหัส lab_items_code ไม่ถูกต้อง — ต้องเป็นตัวเลขคั่นด้วย ,']);
    exit;
  }

  try {
    $place = implode(',', array_fill(0, count($labCodes), '?'));

    /* ── Query HOSxP (ใช้ $dbcon เดียวกัน — HOSxP อยู่บน server เดียวกัน) ── */
    $sql = "SELECT
               pt.hn,
               CONCAT(pt.pname, pt.fname, ' ', pt.lname)       AS fullname,
               TIMESTAMPDIFF(YEAR, pt.birthday, CURDATE())      AS age,
               pt.cid,
               pt.informaddr,
               pt.hometel,
               DATE(ov.vstdate)                                  AS vstdate,
               d.name                                            AS doctor,
               ov.pdx,
               l.lab_order_result,
               h.lab_order_number
            FROM   lab_order  l
            INNER JOIN lab_head    h   ON h.lab_order_number = l.lab_order_number
            LEFT  JOIN vn_stat     ov  ON ov.vn  = h.vn
            LEFT  JOIN doctor      d   ON d.CODE  = ov.dx_doctor
            INNER JOIN patient     pt  ON pt.hn   = ov.hn
            WHERE  DATE(ov.vstdate) BETWEEN ? AND ?
            AND    l.lab_items_code IN ($place)
            AND    LOWER(l.lab_order_result) IN ('positive', 'detected', '+')
            AND    h.lab_order_number IS NOT NULL
            AND    h.lab_order_number != ''
            ORDER  BY h.report_date DESC
            LIMIT  2000";

    $params = array_merge([$impStart, $impEnd], $labCodes);
    $stmt   = $dbcon->prepare($sql);
    $stmt->execute($params);
    $hosxpRows = $stmt->fetchAll();

    /* ── Upsert into covid_queue ── */
    $ins = $dbcon->prepare(
      "INSERT INTO covid_queue
         (lab_order_number, hn, fullname, age, cid, informaddr, hometel,
          vstdate, doctor, pdx, lab_order_result, status, attempt, created_at)
       VALUES
         (:lon, :hn, :fn, :age, :cid, :ia, :ht,
          :vd, :dr, :pdx, :lor, 0, 0, NOW())
       ON DUPLICATE KEY UPDATE
         fullname   = VALUES(fullname),
         informaddr = VALUES(informaddr),
         hometel    = VALUES(hometel)"
    );

    $existStmt = $dbcon->prepare(
      "SELECT id FROM covid_queue WHERE lab_order_number = ? LIMIT 1"
    );

    $imported = 0; $newRows = 0; $skipped = 0;

    foreach ($hosxpRows as $hr) {
      $hr  = row_to_utf8($hr);
      $lon = trim((string)($hr['lab_order_number'] ?? ''));
      $hn  = trim((string)($hr['hn']               ?? ''));
      if ($lon === '' || $hn === '') { $skipped++; continue; }

      $existStmt->execute([$lon]);
      $isNew = !$existStmt->fetch();

      $ins->execute([
        ':lon' => $lon,
        ':hn'  => $hn,
        ':fn'  => $hr['fullname']         ?? '',
        ':age' => is_numeric($hr['age']) ? (int)$hr['age'] : null,
        ':cid' => $hr['cid']              ?? '',
        ':ia'  => $hr['informaddr']       ?? '',
        ':ht'  => $hr['hometel']          ?? '',
        ':vd'  => $hr['vstdate']          ?: null,
        ':dr'  => $hr['doctor']           ?? '',
        ':pdx' => $hr['pdx']              ?? '',
        ':lor' => $hr['lab_order_result'] ?? '',
      ]);

      $imported++;
      if ($isNew) $newRows++;
    }

    $skipNote = $skipped > 0 ? " (ข้าม {$skipped} แถว ไม่มี lab_order_number/HN)" : '';
    echo json_encode([
      'ok'       => true,
      'imported' => $imported,
      'new'      => $newRows,
      'skipped'  => $skipped,
      'msg'      => "นำเข้าสำเร็จ {$imported} รายการ (ใหม่ {$newRows} รายการ){$skipNote}",
    ]);

  } catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'msg'=>'เกิดข้อผิดพลาด: '.$e->getMessage()]);
  }
  exit;
}

/* ── CSRF check (สำหรับ bulk actions จาก form) ─────────────────────────── */
if (!isset($_POST['token']) || !defined('UI_ACTION_TOKEN') || $_POST['token'] !== UI_ACTION_TOKEN) {
  http_response_code(403); exit('Forbidden');
}

$ids = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
$ids = array_values(array_filter($ids, fn($x)=>ctype_digit((string)$x)));
if (!$ids) { header('Location: covid_queue_ui.php?msg=no_ids'); exit; }

/* ── Execute bulk action ────────────────────────────────────────────────── */
try {
  if ($action === 'requeue') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $dbcon->prepare(
      "UPDATE covid_queue
       SET status=0, attempt=0, last_attempt_at=NULL,
           last_error=NULL, out_ref=NULL, line_message_id=NULL
       WHERE id IN ($place)"
    );
    $stmt->execute($ids);
    header('Location: covid_queue_ui.php?msg=requeued&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'clear_error') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $dbcon->prepare("UPDATE covid_queue SET last_error=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: covid_queue_ui.php?msg=cleared&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'send_now') {
    $ok = 0; $fail = 0;
    foreach ($ids as $id) {
      [$o, $r, $e] = send_one_by_id($dbcon, (int)$id);
      if ($o) $ok++; else $fail++;
    }
    header('Location: covid_queue_ui.php?msg=sendnow&ok='.$ok.'&fail='.$fail); exit;

  } else {
    header('Location: covid_queue_ui.php?msg=bad_action'); exit;
  }
} catch (Throwable $e) {
  header('Location: covid_queue_ui.php?msg=err&detail='.urlencode($e->getMessage()));
}
