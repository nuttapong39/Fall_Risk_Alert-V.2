<?php
require_once __DIR__ . '/config.php';
require_once('index1.html');
date_default_timezone_set('Asia/Bangkok');

// ---------- helper UTF-8 ----------
function to_utf8($s){
  if ($s === null || $s === '' || !is_string($s)) return $s;
  if (mb_check_encoding($s, 'UTF-8')) return $s;
  foreach (['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc) {
    $t = @iconv($enc,'UTF-8//IGNORE',$s);
    if ($t!==false && $t!=='') return $t;
    $t = @mb_convert_encoding($s,'UTF-8',$enc);
    if ($t!==false && $t!=='') return $t;
  }
  return @iconv('UTF-8','UTF-8//IGNORE',$s);
}
function row_to_utf8(array $r){ foreach($r as $k=>$v){ if(is_string($v)) $r[$k]=to_utf8($v); } return $r; }

// ---------- input ----------
$day = isset($_GET['date']) && $_GET['date'] ? $_GET['date'] : date('Y-m-d');
$start = $day . ' 00:00:00';
$end   = $day . ' 23:59:59';

$exportCsv = isset($_GET['export']) && $_GET['export']==='csv';

// ---------- queries ----------
$Q1 = $dbcon->prepare("SELECT
    SUM(DATE(created_at)=DATE(:d))                              AS detected,
    SUM(status=1 AND DATE(sent_at)=DATE(:d))                    AS sent_ok,
    SUM(status=0 AND created_at BETWEEN :s AND :e)              AS pending_now,
    SUM(status=0 AND last_error IS NOT NULL AND (last_attempt_at BETWEEN :s AND :e OR created_at BETWEEN :s AND :e)) AS failed_today
  FROM covid_queue");
$Q1->execute([':d'=>$day, ':s'=>$start, ':e'=>$end]);
$sum = $Q1->fetch() ?: ['detected'=>0,'sent_ok'=>0,'pending_now'=>0,'failed_today'=>0];

$Q2 = $dbcon->prepare("SELECT HOUR(created_at) h, COUNT(*) c
  FROM covid_queue WHERE created_at BETWEEN :s AND :e GROUP BY HOUR(created_at) ORDER BY h");
$Q2->execute([':s'=>$start, ':e'=>$end]);
$byHour = $Q2->fetchAll();

$Q3 = $dbcon->prepare("SELECT doctor, COUNT(*) c
  FROM covid_queue WHERE created_at BETWEEN :s AND :e GROUP BY doctor ORDER BY c DESC LIMIT 10");
$Q3->execute([':s'=>$start, ':e'=>$end]);
$byDoctor = $Q3->fetchAll();

$Qp = $dbcon->prepare("SELECT * FROM covid_queue
  WHERE status=0 AND created_at<=:e ORDER BY created_at ASC LIMIT 200");
$Qp->execute([':e'=>$end]);
$pending = array_map('row_to_utf8', $Qp->fetchAll());

$Qf = $dbcon->prepare("SELECT * FROM covid_queue
  WHERE last_error IS NOT NULL AND (last_attempt_at BETWEEN :s AND :e OR created_at BETWEEN :s AND :e)
  ORDER BY last_attempt_at DESC LIMIT 200");
$Qf->execute([':s'=>$start, ':e'=>$end]);
$failed = array_map('row_to_utf8', $Qf->fetchAll());

// ---------- CSV export ----------
if ($exportCsv) {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="covid_summary_'.$day.'.csv"');
  $out = fopen('php://output', 'w');
  fputs($out, "\xEF\xBB\xBF"); // BOM
  fputcsv($out, ['สรุปรายวัน', $day]);
  fputcsv($out, ['พบทั้งหมดวันนี้', $sum['detected']]);
  fputcsv($out, ['ส่งสำเร็จวันนี้', $sum['sent_ok']]);
  fputcsv($out, ['ค้างส่ง (ปัจจุบัน)', $sum['pending_now']]);
  fputcsv($out, ['ล้มเหลววันนี้', $sum['failed_today']]);
  fputcsv($out, []);
  fputcsv($out, ['Pending รายการ (สูงสุด 200)']);
  fputcsv($out, ['id','hn','fullname','vstdate','doctor','pdx','lab_order_result','created_at','attempt','last_error']);
  foreach($pending as $r){
    fputcsv($out, [$r['id'],$r['hn'],$r['fullname'],$r['vstdate'],$r['doctor'],$r['pdx'],$r['lab_order_result'],$r['created_at'],$r['attempt'],$r['last_error']]);
  }
  fputcsv($out, []);
  fputcsv($out, ['Failed วันนี้ (สูงสุด 200)']);
  fputcsv($out, ['id','hn','fullname','vstdate','doctor','pdx','lab_order_result','attempt','last_attempt_at','last_error']);
  foreach($failed as $r){
    fputcsv($out, [$r['id'],$r['hn'],$r['fullname'],$r['vstdate'],$r['doctor'],$r['pdx'],$r['lab_order_result'],$r['attempt'],$r['last_attempt_at'],$r['last_error']]);
  }
  fclose($out);
  exit;
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>สรุปรายวัน COVID Queue</title>
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
  <h3 class="mb-3">สรุปรายวัน COVID Queue</h3>

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
        <div class="fw-bold mb-2">Top แพทย์ (ตาม created_at)</div>
        <table class="table table-sm mb-0">
          <thead><tr><th>แพทย์</th><th class="text-end">จำนวน</th></tr></thead>
          <tbody>
          <?php foreach($byDoctor as $r): $r=row_to_utf8($r); ?>
            <tr><td><?=htmlspecialchars($r['doctor']?:'-')?></td><td class="text-end"><?=$r['c']?></td></tr>
          <?php endforeach; if(!$byDoctor) echo '<tr><td colspan="2" class="text-center text-secondary">-</td></tr>'; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="p-3 bg-white shadow-sm metric mb-3">
    <div class="d-flex justify-content-between align-items-center">
      <div class="fw-bold">รายการค้างส่ง (สูงสุด 200)</div>
      <a class="btn btn-sm btn-outline-primary" href="queue_ui.php?status=0">เปิดดูใน UI</a>
    </div>
    <div class="table-responsive mt-2">
      <table class="table table-striped table-fixed">
        <thead class="table-light"><tr>
          <th>ID</th><th>HN</th><th>ชื่อ-สกุล</th><th>vstdate</th><th>แพทย์</th><th>ICD10</th><th>ผล</th><th>สร้างเมื่อ</th><th>attempt</th><th>error ล่าสุด</th>
        </tr></thead>
        <tbody>
        <?php if(!$pending){ echo '<tr><td colspan="10" class="text-center text-secondary">- ไม่มี -</td></tr>'; }
        foreach($pending as $r): ?>
          <tr>
            <td><?=$r['id']?></td>
            <td><?=htmlspecialchars($r['hn'])?></td>
            <td><?=htmlspecialchars($r['fullname'])?></td>
            <td><?=$r['vstdate']?></td>
            <td><?=htmlspecialchars($r['doctor'])?></td>
            <td><?=$r['pdx']?></td>
            <td><?=$r['lab_order_result']?></td>
            <td><?=$r['created_at']?></td>
            <td><?=$r['attempt']?></td>
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
          <th>ID</th><th>HN</th><th>ชื่อ-สกุล</th><th>vstdate</th><th>แพทย์</th><th>ICD10</th><th>ผล</th><th>attempt</th><th>last_attempt_at</th><th>error</th>
        </tr></thead>
        <tbody>
        <?php if(!$failed){ echo '<tr><td colspan="10" class="text-center text-secondary">- ไม่มี -</td></tr>'; }
        foreach($failed as $r): ?>
          <tr>
            <td><?=$r['id']?></td>
            <td><?=htmlspecialchars($r['hn'])?></td>
            <td><?=htmlspecialchars($r['fullname'])?></td>
            <td><?=$r['vstdate']?></td>
            <td><?=htmlspecialchars($r['doctor'])?></td>
            <td><?=$r['pdx']?></td>
            <td><?=$r['lab_order_result']?></td>
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
