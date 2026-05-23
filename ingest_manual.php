<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Bangkok');

$today = date('Y-m-d');
$start = isset($_GET['start']) && $_GET['start'] ? $_GET['start'] : date('Y-m-d', strtotime('-7 days'));
$end   = isset($_GET['end'])   && $_GET['end']   ? $_GET['end']   : $today;
$hosp  = isset($_GET['hosp'])  ? trim($_GET['hosp']) : '';
$job   = isset($_GET['job'])   ? $_GET['job'] : 'covid'; // covid|fracture

$target = $job === 'fracture' ? 'fracture.php' : 'covid.php';
$ingestUrl = "{$target}?mode=ingest&start=".urlencode($start)."&end=".urlencode($end)."&hosp=".urlencode($hosp);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>Manual Ingest</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,"Kanit",sans-serif}</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <h3 class="mb-3">Manual Ingest</h3>
  <form class="row g-3 mb-3" method="get">
    <div class="col-md-3">
      <label class="form-label">ชนิดงาน</label>
      <select class="form-select" name="job">
        <option value="covid" <?=$job==='covid'?'selected':''?>>Covid</option>
        <option value="fracture" <?=$job==='fracture'?'selected':''?>>Fracture</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">ตั้งแต่</label>
      <input type="date" class="form-control" name="start" value="<?=htmlspecialchars($start)?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">ถึง</label>
      <input type="date" class="form-control" name="end" value="<?=htmlspecialchars($end)?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">รหัส รพ. (ถ้ามี)</label>
      <input type="text" class="form-control" name="hosp" value="<?=htmlspecialchars($hosp)?>">
    </div>
    <div class="col-12">
      <button class="btn btn-primary">เตรียมลิงก์</button>
      <?php if (isset($_GET['job'])): ?>
        <a class="btn btn-success ms-2" href="<?=$ingestUrl?>">Ingest now</a>
      <?php endif; ?>
    </div>
  </form>

  <?php if (isset($_GET['job'])): ?>
  <div class="alert alert-info">
    ระบบจะเรียก: <code><?=htmlspecialchars($ingestUrl)?></code>
  </div>
  <?php endif; ?>

  <p class="text-muted mt-4">หมายเหตุ: โหมด ingest จะทำเฉพาะการ “ดึงข้อมูลเข้า queue” (STEP 1) ไม่ส่งข้อความไป MOPH Alert</p>
</div>
</body>
</html>
