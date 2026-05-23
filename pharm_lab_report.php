<?php
require_once __DIR__ . '/config.php';
/* สำคัญ: กันไม่ให้ main ingest+send block ใน pharm_lab.php ทำงานตอน include ผ่านหน้า report */
if (!defined('PHARM_LIB_ONLY')) define('PHARM_LIB_ONLY', true);
require_once __DIR__ . '/pharm_lab.php'; // มี send_via_moph_alert_pharm(), + helpers (ensure_utf8, row_to_utf8, th_date) ผ่าน flex_pharm.php

// บังคับ session ของ MySQL ใช้ UTF-8 (ถ้าตั้งค่าระบบไว้แล้วจะไม่กระทบ)
try { $dbcon->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $e) {}

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Bangkok');

/* ===== Helpers เฉพาะไฟล์นี้ ===== */
function t($s){ return is_string($s) ? trim($s) : $s; }
function thdate_to_ymd($d){
  // รับ d/m/Y(พ.ศ./คริสต์) -> Y-m-d
  $d = trim((string)$d);
  if ($d==='') return null;
  $p = explode('/', $d);
  if (count($p) !== 3) return null;
  [$dd,$mm,$yy] = $p;
  $y = (int)$yy;
  if ($y > 2400) $y -= 543;
  return sprintf('%04d-%02d-%02d', $y, (int)$mm, (int)$dd);
}

/* ===== รับพารามิเตอร์ ===== */
$id  = isset($_GET['id'])  ? (int)$_GET['id'] : 0;
$hn  = isset($_GET['hn'])  ? t($_GET['hn'])   : '';
$lab = isset($_GET['lab']) ? t($_GET['lab'])  : '';

$errMsg = null; $okMsg = null;

/* ===== ดึงข้อมูลหัวบัตรจากคิว ===== */
$row = null;
if ($id > 0) {
  $st = $dbcon->prepare("SELECT * FROM pharm_lab_queue WHERE id=:id");
  $st->execute([':id'=>$id]);
  $row = $st->fetch();
}
if (!$row && $hn !== '' && $lab !== '') {
  $st = $dbcon->prepare("SELECT * FROM pharm_lab_queue WHERE hn=:hn AND lab_order_number=:lab ORDER BY id DESC LIMIT 1");
  $st->execute([':hn'=>$hn, ':lab'=>$lab]);
  $row = $st->fetch();
}
if (!$row) {
  http_response_code(400);
  echo "<pre>ไม่พบรายการคิวที่อ้างถึง</pre>";
  exit;
}

/* ===== POST actions ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {

  // เพิ่มรายชื่อเจ้าหน้าที่
  if (isset($_POST['action']) && $_POST['action']==='add_reporter') {
    $name = trim((string)($_POST['rep_name'] ?? '')); // รับ UTF-8 ตรง ๆ
    if ($name==='') {
      $errMsg = 'กรุณากรอกชื่อเจ้าหน้าที่';
    } else {
      $ins = $dbcon->prepare("INSERT INTO pharm_reporters (name) VALUES (:n)");
      $ins->execute([':n'=>$name]); // บันทึกเป็น UTF-8
      $okMsg = 'เพิ่มเจ้าหน้าที่เรียบร้อย';
    }
  }

  // บันทึกผู้รายงานผล + ส่ง Flex
  if (isset($_POST['action']) && $_POST['action']==='save_report') {
    $reported_by_id   = (int)($_POST['reported_by_id'] ?? 0);
    $reported_by_name = '';
    if ($reported_by_id > 0) {
      $q=$dbcon->prepare("SELECT name FROM pharm_reporters WHERE id=:i");
      $q->execute([':i'=>$reported_by_id]);
      $reported_by_name = (string)($q->fetchColumn() ?: '');
    }

    $date_th = t($_POST['reported_date'] ?? '');
    $time_hm = t($_POST['reported_time'] ?? '');
    $ymd = thdate_to_ymd($date_th);

    if (!$ymd)                 $errMsg = 'รูปแบบวันที่ไม่ถูกต้อง';
    elseif ($time_hm==='')     $errMsg = 'กรุณาระบุเวลา';
    elseif ($reported_by_id<=0)$errMsg = 'กรุณาเลือกผู้รายงานผล';

    if (!$errMsg) {
      $upd = $dbcon->prepare("
        UPDATE pharm_lab_queue
        SET reported_by_id=:rid,
            reported_by_name=:rname,
            reported_date=:rdate,
            reported_time=:rtime,
            reported_at=CONCAT(:rdate,' ',:rtime)
        WHERE id=:id
      ");
      $upd->execute([
        ':rid'  => $reported_by_id,
        ':rname'=> $reported_by_name,     // เก็บ UTF-8
        ':rdate'=> $ymd,
        ':rtime'=> $time_hm.':00',
        ':id'   => $row['id'],
      ]);

      // โหลดแถวล่าสุดเพื่อส่ง Flex (ให้ส่วน buildPharmPayload แสดง block ผู้รายงาน)
      $st = $dbcon->prepare("SELECT * FROM pharm_lab_queue WHERE id=:id");
      $st->execute([':id'=>$row['id']]);
      $row = $st->fetch();

      [$ok,$ref,$err] = send_via_moph_alert_pharm($row);
      if ($ok) $okMsg = "บันทึกเรียบร้อย";
      else     $errMsg = "บันทึกแล้ว แต่ส่งแจ้งเตือนล้มเหลว: $err";
    }
  }
}

/* ===== รายชื่อผู้รายงานสำหรับ dropdown ===== */
$reps = $dbcon->query("SELECT id,name FROM pharm_reporters ORDER BY name ASC")->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>บันทึกผู้รายงานผล</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
<style>
  :root { --brand:#107C41; --shadow:0 8px 24px rgba(2,6,23,.08); }
  body{
    font-family: "Kanit", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    background:#f8fafc;
    font-size: 1.05rem;   /* TH Kanit New ตัวเล็กกว่า ปรับให้อ่านง่าย */
  }
  h1,h2,h3,h4,h5,h6 { font-weight: 600; }
  .card-elev { border:0; box-shadow:var(--shadow); border-radius:16px; }
  .header-badge{ background:#DCE7FF; border-radius:12px; padding:.4rem .8rem; font-weight:600; }
  .btn-brand{ background:var(--brand); border-color:var(--brand); }
  .btn-brand:hover{ filter:brightness(.92); }
  .label{ color:#334155; font-size:.95rem; }
</style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0"><span class="msi me-2">assignment_turned_in</span>บันทึกผู้รายงานผล</h3>
    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addReporterModal">
      <span class="msi me-1">person_add</span> เพิ่มเจ้าหน้าที่
    </button>
  </div>

  <div class="card card-elev mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between">
        <div>
          <div class="header-badge mb-2"><span class="msi me-1">personal_injury</span> ข้อมูล Lab</div>
          <div class="small text-muted">HN: <span class="fw-semibold"><?=htmlspecialchars($row['hn'])?></span></div>
          <div class="small text-muted">Lab: <span class="fw-semibold"><?=htmlspecialchars($row['lab_name'] ?? 'INR')?></span> | ผล: <span class="fw-semibold"><?=htmlspecialchars($row['result']??'-')?></span></div>
          <div class="small text-muted">วันที่/เวลาออกผล: <span class="fw-semibold"><?=htmlspecialchars($row['lab_date'].' '.$row['lab_time'])?></span></div>
        </div>
      </div>
      <?php if($okMsg): ?>
        <div class="alert alert-success mt-3 mb-0"><span class="msi me-1">check_circle</span><?=$okMsg?></div>
      <?php endif; ?>
      <?php if($errMsg): ?>
        <div class="alert alert-danger mt-3 mb-0"><span class="msi me-1">warning</span><?=$errMsg?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card card-elev">
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="save_report">
        <div class="col-12">
          <label class="form-label label"><span class="msi me-1">manage_accounts</span>ผู้รายงานผล</label>
          <select name="reported_by_id" class="form-select" required>
            <option value="">— เลือก —</option>
            <?php foreach($reps as $rp): $nm = ensure_utf8($rp['name']); ?>
              <option value="<?=$rp['id']?>" <?=($row['reported_by_id']??null)==$rp['id']?'selected':''?>>
                <?=htmlspecialchars($nm)?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label label"><span class="msi me-1">today</span>วันที่ (วัน/เดือน/ปี พ.ศ.)</label>
          <?php
            $d = $row['reported_date'] ?? date('Y-m-d');
            $y = (int)date('Y', strtotime($d)); if ($y>0) $y += 543;
            $th = date('d/m', strtotime($d)).'/'.$y;
          ?>
          <input name="reported_date" class="form-control" value="<?=$th?>" placeholder="เช่น 03/11/2568" required>
        </div>
        <div class="col-md-6">
          <label class="form-label label"><span class="msi me-1">schedule</span>เวลา (ชั่วโมง:นาที)</label>
          <?php $tim = substr($row['reported_time'] ?? date('H:i'),0,5); ?>
          <input name="reported_time" class="form-control" value="<?=$tim?>" placeholder="เช่น 14:00" required>
        </div>
        <div class="col-12">
          <button class="btn btn-brand w-100"><span class="msi me-1">save</span>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal เพิ่มเจ้าหน้าที่ -->
<div class="modal fade" id="addReporterModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="add_reporter">
      <div class="modal-header">
        <h5 class="modal-title"><span class="msi me-1">person_add</span> เพิ่มชื่อเจ้าหน้าที่</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label class="form-label label">ชื่อ-สกุล เจ้าหน้าที่</label>
        <input name="rep_name" class="form-control" placeholder="เช่น เภสัชกร ณัฐพงษ์ ตัวอย่าง" required>
      </div>
      <div class="modal-footer">
        <button class="btn btn-brand"><span class="msi me-1">check</span>บันทึก</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
