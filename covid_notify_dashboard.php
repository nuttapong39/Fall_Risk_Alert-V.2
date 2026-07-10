<?php
require_once 'server.php'; // เชื่อมต่อฐานข้อมูล PDO $conn
require_once 'send_line_notify.php'; // ฟังก์ชัน send_line_notify($message): bool

// รับค่าจาก filter
$filterDoctor = $_GET['doctor'] ?? '';
$startDate = $_GET['start'] ?? date('Y-m-01');
$endDate = $_GET['end'] ?? date('Y-m-d');

// สร้าง SQL
require_once __DIR__ . '/sources/covid_source.php';
$results = covid_source_rows($startDate, $endDate, ['3066','3082','3084','3088'], false, $filterDoctor ?: '');

// ส่งแจ้งเตือนอัตโนมัติถ้ายังไม่เคยส่ง
foreach ($results as $row) {
    $check = $dbcon->prepare("SELECT 1 FROM covid_notify_log WHERE lab_order_number = ?");
    $check->execute([$row['lab_order_number']]);
    if (!$check->fetch()) {
        $message = "📌 แจ้งเตือนผลตรวจ COVID-19\n";
        $message .= "🧑‍⚕️ แพทย์: " . $row['doctor'] . "\n";
        $message .= "👤 ผู้ป่วย: " . $row['fullname'] . " (HN: " . $row['hn'] . ")\n";
        $message .= "🧪 ผลตรวจ: " . $row['lab_order_result'] . "\n";
        $message .= "📅 วันที่: " . $row['vstdate'] . "\n";

        if (send_line_notify($message)) {
            $log = $conn->prepare("INSERT INTO covid_notify_log (lab_order_number, hn, sent_at, doctor) VALUES (?, ?, NOW(), ?)");
            $log->execute([$row['lab_order_number'], $row['hn'], $row['doctor']]);
        }
    }
}

// ดึง log แจ้งเตือน
$logs = $dbcon->query("SELECT * FROM covid_notify_log ORDER BY sent_at DESC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <title>COVID Alert Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="p-4 bg-light">
  <div class="container">
    <h2 class="mb-4">📋 รายชื่อผู้ป่วย COVID-19 (Positive)</h2>
    <form class="row g-2 mb-4" method="GET">
      <div class="col-md-3">
        <input type="date" name="start" class="form-control" value="<?= htmlspecialchars($startDate) ?>" />
      </div>
      <div class="col-md-3">
        <input type="date" name="end" class="form-control" value="<?= htmlspecialchars($endDate) ?>" />
      </div>
      <div class="col-md-3">
        <input type="text" name="doctor" placeholder="ค้นหาแพทย์" class="form-control" value="<?= htmlspecialchars($filterDoctor) ?>" />
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary" type="submit">🔍 ค้นหา</button>
      </div>
      <div class="col-md-1">
        <a href="export_log.php" class="btn btn-success">⬇️ Export</a>
      </div>
    </form>

    <div class="table-responsive bg-white p-3 rounded shadow-sm">
      <table class="table table-bordered table-hover">
        <thead class="table-dark text-center">
          <tr>
            <th>วันที่</th>
            <th>HN</th>
            <th>ชื่อ</th>
            <th>อายุ</th>
            <th>แพทย์</th>
            <th>ผล</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $r): ?>
            <tr>
              <td class="text-center"><?= htmlspecialchars($r['vstdate']) ?></td>
              <td class="text-center"><?= htmlspecialchars($r['hn']) ?></td>
              <td><?= htmlspecialchars($r['fullname']) ?></td>
              <td class="text-center"><?= (int)$r['age'] ?></td>
              <td><?= htmlspecialchars($r['doctor']) ?></td>
              <td class="text-danger fw-bold text-center"><?= htmlspecialchars($r['lab_order_result']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <hr class="my-4" />

    <h4>📑 Log การแจ้งเตือน</h4>
    <div class="table-responsive bg-white p-3 rounded shadow-sm">
      <table class="table table-bordered table-hover">
        <thead class="table-secondary text-center">
          <tr>
            <th>วันที่ส่ง</th>
            <th>HN</th>
            <th>แพทย์</th>
            <th>รหัสตรวจ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td class="text-center"><?= htmlspecialchars($log['sent_at']) ?></td>
              <td class="text-center"><?= htmlspecialchars($log['hn']) ?></td>
              <td><?= htmlspecialchars($log['doctor']) ?></td>
              <td class="text-center"><?= htmlspecialchars($log['lab_order_number']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
