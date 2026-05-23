<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/covid_lib.php';
require_once ('index1.html');
date_default_timezone_set('Asia/Bangkok');

/* ---- รับพารามิเตอร์ ---- */
$start = isset($_GET['start']) && $_GET['start'] ? $_GET['start'] : date('Y-m-d', strtotime('-7 days'));
$end   = isset($_GET['end'])   && $_GET['end']   ? $_GET['end']   : date('Y-m-d');
$doctor= trim($_GET['doctor'] ?? '');
$icd10 = strtoupper(trim($_GET['icd10'] ?? ''));
$result= trim($_GET['result'] ?? ''); // Positive/Negative/ว่าง

/* ---- สร้าง where ---- */
$w = ["created_at BETWEEN :s AND :e"];
$p = [':s'=>$start.' 00:00:00', ':e'=>$end.' 23:59:59'];
if ($doctor !== '') { $w[]="doctor LIKE :d"; $p[':d']="%$doctor%"; }
if ($icd10 !== '')  { $w[]="pdx LIKE :i";    $p[':i']="$icd10%"; }
if ($result !== '') { $w[]="lab_order_result=:r"; $p[':r']=$result; }
$where = implode(' AND ', $w);

/* ---- สรุปต่อวัน ---- */
$q = $dbcon->prepare("SELECT DATE(created_at) d,
  COUNT(*) total,
  SUM(status=1) sent_ok,
  SUM(status=0) pending,
  SUM(CASE WHEN last_error IS NOT NULL THEN 1 ELSE 0 END) failed
  FROM covid_queue WHERE $where GROUP BY DATE(created_at) ORDER BY d");
$q->execute($p);
$rows = $q->fetchAll();

/* ---- Top doctor / ICD10 ---- */
$q2 = $dbcon->prepare("SELECT doctor, COUNT(*) c FROM covid_queue WHERE $where GROUP BY doctor ORDER BY c DESC LIMIT 10");
$q2->execute($p); $topDoc = $q2->fetchAll();

$q3 = $dbcon->prepare("SELECT pdx, COUNT(*) c FROM covid_queue WHERE $where GROUP BY pdx ORDER BY c DESC LIMIT 10");
$q3->execute($p); $topIcd = $q3->fetchAll();

/* ---- เตรียมข้อมูล Chart ---- */
$labels=[]; $total=[]; $sent=[]; $pend=[]; $fail=[];
foreach ($rows as $r) { $labels[]=$r['d']; $total[]=(int)$r['total']; $sent[]=(int)$r['sent_ok']; $pend[]=(int)$r['pending']; $fail[]=(int)$r['failed']; }
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>COVID Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,"Kanit",sans-serif}
  .card{border-radius:14px}
</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <h3 class="mb-3">COVID Dashboard</h3>

  <form class="row g-2 mb-3" method="get">
    <div class="col-auto">
      <label class="form-label">ตั้งแต่</label>
      <input type="date" class="form-control" name="start" value="<?=htmlspecialchars($start)?>">
    </div>
    <div class="col-auto">
      <label class="form-label">ถึง</label>
      <input type="date" class="form-control" name="end" value="<?=htmlspecialchars($end)?>">
    </div>
    <div class="col-auto">
      <label class="form-label">แพทย์</label>
      <input type="text" class="form-control" name="doctor" placeholder="พิมพ์บางส่วน" value="<?=htmlspecialchars($doctor)?>">
    </div>
    <div class="col-auto">
      <label class="form-label">ICD10</label>
      <input type="text" class="form-control" name="icd10" placeholder="เช่น U07" value="<?=htmlspecialchars($icd10)?>">
    </div>
    <div class="col-auto">
      <label class="form-label">ผลตรวจ</label>
      <select class="form-select" name="result">
        <option value=""  <?=$result===''?'selected':''?>>ทั้งหมด</option>
        <option value="Positive" <?=$result==='Positive'?'selected':''?>>Positive</option>
        <option value="Negative" <?=$result==='Negative'?'selected':''?>>Negative</option>
      </select>
    </div>
    <div class="col-auto align-self-end">
      <button class="btn btn-primary">กรองข้อมูล</button>
      <a class="btn btn-outline-secondary" href="sum_dashboard.php">รีเซ็ต</a>
    </div>
  </form>

  <div class="card p-3 mb-3">
    <div class="fw-bold mb-2">กราฟสรุปต่อวัน</div>
    <canvas id="lineChart" height="90"></canvas>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card p-3">
        <div class="fw-bold mb-2">Top แพทย์</div>
        <table class="table table-sm mb-0">
          <thead><tr><th>แพทย์</th><th class="text-end">จำนวน</th></tr></thead>
          <tbody>
          <?php foreach($topDoc as $r): ?>
            <tr><td><?=htmlspecialchars(to_utf8($r['doctor']?:'-'))?></td><td class="text-end"><?=$r['c']?></td></tr>
          <?php endforeach; if(!$topDoc) echo '<tr><td colspan="2" class="text-center text-secondary">-</td></tr>'; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-3">
        <div class="fw-bold mb-2">Top ICD10</div>
        <table class="table table-sm mb-0">
          <thead><tr><th>ICD10</th><th class="text-end">จำนวน</th></tr></thead>
          <tbody>
          <?php foreach($topIcd as $r): ?>
            <tr><td><?=htmlspecialchars($r['pdx']?:'-')?></td><td class="text-end"><?=$r['c']?></td></tr>
          <?php endforeach; if(!$topIcd) echo '<tr><td colspan="2" class="text-center text-secondary">-</td></tr>'; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
const labels = <?=json_encode($labels, JSON_UNESCAPED_UNICODE)?>;
const dataAll = <?=json_encode($total)?>;
const dataSent= <?=json_encode($sent)?>;
const dataPend= <?=json_encode($pend)?>;
const dataFail= <?=json_encode($fail)?>;
const ctx = document.getElementById('lineChart');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: labels,
    datasets: [
      {label:'ทั้งหมด', data:dataAll},
      {label:'ส่งสำเร็จ', data:dataSent},
      {label:'ค้างส่ง', data:dataPend},
      {label:'ล้มเหลว', data:dataFail},
    ]
  },
  options: {
    responsive:true,
    interaction:{mode:'index', intersect:false},
    plugins:{legend:{position:'top'}},
    scales:{y:{beginAtZero:true}}
  }
});
</script>
</body>
</html>
