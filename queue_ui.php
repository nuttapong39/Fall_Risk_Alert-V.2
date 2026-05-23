<?php
require_once __DIR__ . '/config.php';
require_once('index1.html');
date_default_timezone_set('Asia/Bangkok');

// --- filters ---
$start = isset($_GET['start']) && $_GET['start'] ? $_GET['start'] : date('Y-m-d', strtotime('-7 days'));
$end   = isset($_GET['end'])   && $_GET['end']   ? $_GET['end']   : date('Y-m-d');
$status= isset($_GET['status']) ? $_GET['status'] : 'all'; // all | 0 | 1

$w = ["created_at BETWEEN :s AND :e"];
$p = [':s'=>$start.' 00:00:00', ':e'=>$end.' 23:59:59'];
if ($status==='0') { $w[]="status=0"; }
if ($status==='1') { $w[]="status=1"; }

$sql = "SELECT * FROM covid_queue WHERE ".implode(' AND ',$w)." ORDER BY id DESC LIMIT 2000";
$stmt = $dbcon->prepare($sql);
$stmt->execute($p);
$rows = $stmt->fetchAll();

// to utf8 for display
function to_utf8($s){ if(!is_string($s)) return $s; if(mb_check_encoding($s,'UTF-8')) return $s;
  foreach(['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc){
    $t=@iconv($enc,'UTF-8//IGNORE',$s); if($t!==false && $t!=='') return $t;
    $t=@mb_convert_encoding($s,'UTF-8',$enc); if($t!==false && $t!=='') return $t;
  } return @iconv('UTF-8','UTF-8//IGNORE',$s);
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>COVID Queue Monitor</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,"Kanit",sans-serif}
  td.small{font-size:.9rem}
  .badge-pending{background:#ffc107}
  .badge-ok{background:#28a745}
</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <h3 class="mb-3">COVID Queue Monitor</h3>

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
      <label class="form-label">สถานะ</label>
      <select class="form-select" name="status">
        <option value="all" <?=$status==='all'?'selected':''?>>ทั้งหมด</option>
        <option value="0" <?=$status==='0'?'selected':''?>>ค้างส่ง (0)</option>
        <option value="1" <?=$status==='1'?'selected':''?>>ส่งแล้ว (1)</option>
      </select>
    </div>
    <div class="col-auto align-self-end">
      <button class="btn btn-primary">ค้นหา</button>
      <a class="btn btn-outline-secondary" href="queue_ui.php">รีเซ็ต</a>
    </div>
  </form>

  <form method="post" action="queue_action.php" onsubmit="return confirm('ยืนยันดำเนินการกับรายการที่เลือก?');">
    <input type="hidden" name="token" value="<?=htmlspecialchars(UI_ACTION_TOKEN)?>">
    <div class="mb-2">
      <button class="btn btn-sm btn-success" name="action" value="send_now">ส่งซ้ำทันที</button>
      <button class="btn btn-sm btn-warning" name="action" value="requeue">Requeue (ตั้งสถานะเป็น 0)</button>
      <button class="btn btn-sm btn-outline-danger" name="action" value="clear_error">ล้าง error</button>
    </div>
    <div class="table-responsive">
      <table id="tbl" class="table table-striped table-hover">
        <thead class="table-light">
          <tr>
            <th><input type="checkbox" onclick="document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked)"></th>
            <th>ID</th><th>สถานะ</th><th>HN</th><th>ชื่อ-สกุล</th><th>vstdate</th>
            <th>แพทย์</th><th>ICD10</th><th>ผล</th><th>attempt</th><th>last_attempt</th>
            <th>out_ref</th><th>error</th><th>created_at</th><th>sent_at</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><input type="checkbox" class="chk" name="ids[]" value="<?=$r['id']?>"></td>
            <td><?=$r['id']?></td>
            <td><?= $r['status'] ? '<span class="badge badge-ok">1</span>' : '<span class="badge badge-pending">0</span>' ?></td>
            <td><?=htmlspecialchars($r['hn'])?></td>
            <td><?=htmlspecialchars(to_utf8($r['fullname']))?></td>
            <td><?=$r['vstdate']?></td>
            <td><?=htmlspecialchars(to_utf8($r['doctor']))?></td>
            <td><?=$r['pdx']?></td>
            <td><?=$r['lab_order_result']?></td>
            <td><?=$r['attempt']?></td>
            <td><?=$r['last_attempt_at']?></td>
            <td><?=htmlspecialchars($r['out_ref'])?></td>
            <td class="small"><?=htmlspecialchars($r['last_error'])?></td>
            <td><?=$r['created_at']?></td>
            <td><?=$r['sent_at']?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function(){
  $('#tbl').DataTable({
    pageLength: 25,
    order: [[1,'desc']],
    language: {
      search: "ค้นหา:", lengthMenu: "แสดง _MENU_ รายการ",
      info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
      paginate: { first:"หน้าแรก", last:"หน้าสุดท้าย", next:"ถัดไป", previous:"ก่อนหน้า" }
    }
  });
});
</script>
</body>
</html>
