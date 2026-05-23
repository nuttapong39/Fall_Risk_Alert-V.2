<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/covid_lib.php'; // ใช้ to_utf8()/row_to_utf8()
require_once('index1.html');
date_default_timezone_set('Asia/Bangkok');

// ---------- input ----------
$day = isset($_GET['date']) && $_GET['date'] ? $_GET['date'] : date('Y-m-d');
$start = $day . ' 00:00:00';
$end   = $day . ' 23:59:59';

$exportCsv = isset($_GET['export']) && $_GET['export']==='csv';

// ---------- queries (fracture_queue) ----------
$Q1 = $dbcon->prepare("
  SELECT
    SUM(DATE(created_at)=DATE(:d))                              AS detected,
    SUM(status=1 AND DATE(sent_at)=DATE(:d))                    AS sent_ok,
    SUM(status=0 AND created_at BETWEEN :s AND :e)              AS pending_now,
    SUM(status=0 AND last_error IS NOT NULL AND (last_attempt_at BETWEEN :s AND :e OR created_at BETWEEN :s AND :e)) AS failed_today
  FROM fracture_queue
");
$Q1->execute([':d'=>$day, ':s'=>$start, ':e'=>$end]);
$sum = $Q1->fetch() ?: ['detected'=>0,'sent_ok'=>0,'pending_now'=>0,'failed_today'=>0];

$Q2 = $dbcon->prepare("
  SELECT HOUR(created_at) h, COUNT(*) c
  FROM fracture_queue
  WHERE created_at BETWEEN :s AND :e
  GROUP BY HOUR(created_at) ORDER BY h
");
$Q2->execute([':s'=>$start, ':e'=>$end]);
$byHour = $Q2->fetchAll();

$Qm = $dbcon->prepare("
  SELECT COALESCE(mainstation,'-') mainstation, COUNT(*) c
  FROM fracture_queue
  WHERE created_at BETWEEN :s AND :e
  GROUP BY mainstation
  ORDER BY c DESC LIMIT 10
");
$Qm->execute([':s'=>$start, ':e'=>$end]);
$byStation = array_map('row_to_utf8', $Qm->fetchAll());

$Qp = $dbcon->prepare("
  SELECT * FROM fracture_queue
  WHERE status=0 AND created_at<=:e
  ORDER BY created_at ASC
  LIMIT 200
");
$Qp->execute([':e'=>$end]);
$pending = array_map('row_to_utf8', $Qp->fetchAll());

$Qf = $dbcon->prepare("
  SELECT * FROM fracture_queue
  WHERE last_error IS NOT NULL
    AND (last_attempt_at BETWEEN :s AND :e OR created_at BETWEEN :s AND :e)
  ORDER BY last_attempt_at DESC
  LIMIT 200
");
$Qf->execute([':s'=>$start, ':e'=>$end]);
$failed = array_map('row_to_utf8', $Qf->fetchAll());

// ---------- CSV export ----------
if ($exportCsv) {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="fracture_summary_'.$day.'.csv"');
  $out = fopen('php://output', 'w');
  fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM
  fputcsv($out, ['สรุปรายวัน (Fracture)', $day]);
  fputcsv($out, ['พบทั้งหมดวันนี้', $sum['detected'] ?: 0]);
  fputcsv($out, ['ส่งสำเร็จวันนี้', $sum['sent_ok'] ?: 0]);
  fputcsv($out, ['ค้างส่ง (ปัจจุบัน)', $sum['pending_now'] ?: 0]);
  fputcsv($out, ['ล้มเหลววันนี้', $sum['failed_today'] ?: 0]);

  fputcsv($out, []);
  fputcsv($out, ['Pending รายการ (สูงสุด 200)']);
  fputcsv($out, ['id','visit_vn','hn','fullname','vstdate','mainstation','pdx_code','pdx_name','age','sex','attempt','created_at','last_error']);
  foreach($pending as $r){
    fputcsv($out, [$r['id'],$r['visit_vn'],$r['hn'],$r['fullname'],$r['vstdate'],$r['mainstation'],$r['pdx_code'],$r['pdx_name'],$r['age'],$r['sex'],$r['attempt'],$r['created_at'],$r['last_error']]);
  }

  fputcsv($out, []);
  fputcsv($out, ['Failed วันนี้ (สูงสุด 200)']);
  fputcsv($out, ['id','visit_vn','hn','fullname','vstdate','mainstation','pdx_code','pdx_name','attempt','last_attempt_at','last_error']);
  foreach($failed as $r){
    fputcsv($out, [$r['id'],$r['visit_vn'],$r['hn'],$r['fullname'],$r['vstdate'],$r['mainstation'],$r['pdx_code'],$r['pdx_name'],$r['attempt'],$r['last_attempt_at'],$r['last_error']]);
  }
  fclose($out);
  exit;
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>สรุปรายวัน Fracture Queue</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,"Kanit",sans-serif}
  .metric{border-radius:14px}
  .table-fixed{table-layout:fixed}
  .table-fixed td{word-wrap:break-word}
</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <h3 class="mb-3">สรุปรายวัน Fracture Queue</h3>

  <form class="row g-2 mb-3" method="get">
    <div class="col-auto">
      <label class="form-label">วันที่</label>
      <input type="date" class="form-control" name="date" value="<?=htmlspecialchars($day)?>">
    </div>
    <div class="col-auto align-self-end">
      <button class="btn btn-primary">ดูสรุป</button>
      <a class="btn btn-outline-secondary" href="?date=<?=htmlspecialchars($day)?>&export=csv">Export CSV</a>
    </div>
  </form>

  <div class="row g-3 mb-4">
    <div class="col-12 col-md-3">
      <div class="p-3 bg-white shadow-sm metric">
        <div class="text-secondary">พบทั้งหมดวันนี้</div>
        <div class="fs-3 fw-bold"><?=$sum['detected']?:0?></div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="p-3 bg-white shadow-sm metric">
        <div class="text-secondary">ส่งสำเร็จวันนี้</div>
        <div class="fs-3 fw-bold text-success"><?=$sum['sent_ok']?:0?></div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="p-3 bg-white shadow-sm metric">
        <div class="text-secondary">ค้างส่ง (ปัจจุบัน)</div>
        <div class="fs-3 fw-bold text-warning"><?=$sum['pending_now']?:0?></div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="p-3 bg-white shadow-sm metric">
        <div class="text-secondary">ล้มเหลววันนี้</div>
        <div class="fs-3 fw-bold text-danger"><?=$sum['failed_today']?:0?></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="p-3 bg-white shadow-sm metric">
        <div class="fw-bold mb-2">จำนวนรายชั่วโมง (สร้างเวลา ingest)</div>
        <div class="small text-secondary">วัน <?=$day?></div>
        <table class="table table-sm mb-0">
          <thead><tr><th>ชั่วโมง</th><th class="text-end">จำนวน</th></tr></thead>
          <tbody>
          <?php foreach($byHour as $r): ?>
            <tr><td><?=str_pad($r['h'],2,'0',STR_PAD_LEFT)?>:00</td><td class="text-end"><?=$r['c']?></td></tr>
          <?php endforeach; if(!$byHour) echo '<tr><td colspan="2" class="text-center text-secondary">-</td></tr>'; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="col-md-6">
      <div class="p-3 bg-white shadow-sm metric">
        <div class="fw-bold mb-2">Top สถานบริการหลัก</div>
        <table class="table table-sm mb-0">
          <thead><tr><th>สถานบริการหลัก</th><th class="text-end">จำนวน</th></tr></thead>
          <tbody>
          <?php foreach($byStation as $r): ?>
            <tr><td><?=htmlspecialchars($r['mainstation']?:'-')?></td><td class="text-end"><?=$r['c']?></td></tr>
          <?php endforeach; if(!$byStation) echo '<tr><td colspan="2" class="text-center text-secondary">-</td></tr>'; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="p-3 bg-white shadow-sm metric mb-3">
    <div class="d-flex justify-content-between align-items-center">
      <div class="fw-bold">รายการค้างส่ง (สูงสุด 200)</div>
      <a class="btn btn-sm btn-outline-primary" href="fracture_queue_ui.php?status=0">เปิดดูใน UI</a>
    </div>
    <div class="table-responsive mt-2">
      <table class="table table-striped table-fixed">
        <thead class="table-light"><tr>
          <th>ID</th><th>VN</th><th>HN</th><th>ชื่อ-สกุล</th><th>อายุ</th><th>เพศ</th>
          <th>vstdate</th><th>mainstation</th><th>pdx_code</th><th>pdx_name</th>
          <th>attempt</th><th>สร้างเมื่อ</th><th>error ล่าสุด</th>
        </tr></thead>
        <tbody>
        <?php if(!$pending){ echo '<tr><td colspan="13" class="text-center text-secondary">- ไม่มี -</td></tr>'; }
        foreach($pending as $r): ?>
          <tr>
            <td><?=$r['id']?></td>
            <td><?=$r['visit_vn']?></td>
            <td><?=htmlspecialchars($r['hn'])?></td>
            <td><?=htmlspecialchars($r['fullname'])?></td>
            <td><?=$r['age']?></td>
            <td><?=htmlspecialchars($r['sex'])?></td>
            <td><?=$r['vstdate']?></td>
            <td><?=htmlspecialchars($r['mainstation'])?></td>
            <td><?=$r['pdx_code']?></td>
            <td><?=htmlspecialchars($r['pdx_name'])?></td>
            <td><?=$r['attempt']?></td>
            <td><?=$r['created_at']?></td>
            <td><?=htmlspecialchars($r['last_error']?:'-')?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="p-3 bg-white shadow-sm metric mb-5">
    <div class="fw-bold">ล้มเหลววันนี้ (สูงสุด 200)</div>
    <div class="table-responsive mt-2">
      <table class="table table-striped table-fixed">
        <thead class="table-light"><tr>
          <th>ID</th><th>VN</th><th>HN</th><th>ชื่อ-สกุล</th><th>vstdate</th><th>mainstation</th>
          <th>pdx_code</th><th>pdx_name</th><th>attempt</th><th>last_attempt_at</th><th>error</th>
        </tr></thead>
        <tbody>
        <?php if(!$failed){ echo '<tr><td colspan="11" class="text-center text-secondary">- ไม่มี -</td></tr>'; }
        foreach($failed as $r): ?>
          <tr>
            <td><?=$r['id']?></td>
            <td><?=$r['visit_vn']?></td>
            <td><?=htmlspecialchars($r['hn'])?></td>
            <td><?=htmlspecialchars($r['fullname'])?></td>
            <td><?=$r['vstdate']?></td>
            <td><?=htmlspecialchars($r['mainstation'])?></td>
            <td><?=$r['pdx_code']?></td>
            <td><?=htmlspecialchars($r['pdx_name'])?></td>
            <td><?=$r['attempt']?></td>
            <td><?=$r['last_attempt_at']?></td>
            <td><?=htmlspecialchars($r['last_error'])?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>
