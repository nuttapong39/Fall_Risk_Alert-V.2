<?php
// config
$host = "192.168.1.249";
$dbname = "hosxp";
$user = "root";
$pass = "comsci";
$client_key = "5f9f001dbabc7794ebbe5769a02dfc636782e1f2";
$secret_key = "YLNQE2A65PEIZQXA72JMQ7CQEDYY";
$api_url = "https://morpromt2f.moph.go.th/api/notify/send";

try {
  $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Connection failed: " . $e->getMessage());
}

// ฟังก์ชัน export CSV
function exportCSV($filename, $data, $header = []) {
  header('Content-Type: text/csv; charset=utf-8');
  header("Content-Disposition: attachment; filename=\"$filename\"");
  $output = fopen('php://output', 'w');
  if (!empty($header)) {
    fputcsv($output, $header);
  }
  foreach ($data as $row) {
    fputcsv($output, $row);
  }
  fclose($output);
  exit;
}

// รับพารามิเตอร์จาก GET
$tab = $_GET['tab'] ?? 'data'; // เลือกแท็บ: data หรือ log
$filterDoctor = $_GET['doctor'] ?? '';
$startDate = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end'] ?? date('Y-m-d');
$filterResult = $_GET['result'] ?? '';

// ถ้าเป็น export log
if (isset($_GET['export_log']) && $_GET['export_log'] == '1') {
  $sql_log = "SELECT * FROM notify_log WHERE 1=1 ";
  $params = [];
  if ($filterDoctor) {
    $sql_log .= " AND doctor_name = :doctor ";
    $params[':doctor'] = $filterDoctor;
  }
  if ($startDate) {
    $sql_log .= " AND vstdate >= :start ";
    $params[':start'] = $startDate;
  }
  if ($endDate) {
    $sql_log .= " AND vstdate <= :end ";
    $params[':end'] = $endDate;
  }
  if ($filterResult) {
    $sql_log .= " AND result = :result ";
    $params[':result'] = $filterResult;
  }
  $sql_log .= " ORDER BY sent_at DESC";

  $stmt_log = $conn->prepare($sql_log);
  $stmt_log->execute($params);
  $logs = $stmt_log->fetchAll(PDO::FETCH_ASSOC);

  $header = ['ID','Lab Order Number','HN','วันที่รับบริการ','เวลาส่งแจ้ง','แพทย์','ICD10','ผลตรวจ'];
  $rows = [];
  foreach ($logs as $r) {
    $rows[] = [
      $r['id'], $r['lab_order_number'], $r['hn'], $r['vstdate'], $r['sent_at'],
      $r['doctor_name'], $r['pdx'], $r['result']
    ];
  }
  exportCSV("notify_log_" . date('Ymd_His') . ".csv", $rows, $header);
}

// --- ดึงข้อมูล covid positive ---
require_once __DIR__ . '/sources/covid_source.php';
$results = covid_source_rows($startDate, $endDate, ['3066','3082','3084','3088'], false, $filterDoctor ?: '');

// --- ดึง log แจ้งเตือน ---
$sql_log = "SELECT * FROM notify_log WHERE 1=1 ";
$params_log = [];
if ($filterDoctor) {
  $sql_log .= " AND doctor_name = :doctor ";
  $params_log[':doctor'] = $filterDoctor;
}
if ($startDate) {
  $sql_log .= " AND vstdate >= :start ";
  $params_log[':start'] = $startDate;
}
if ($endDate) {
  $sql_log .= " AND vstdate <= :end ";
  $params_log[':end'] = $endDate;
}
if ($filterResult) {
  $sql_log .= " AND result = :result ";
  $params_log[':result'] = $filterResult;
}
$sql_log .= " ORDER BY sent_at DESC";

$stmt_log = $conn->prepare($sql_log);
$stmt_log->execute($params_log);
$logs = $stmt_log->fetchAll(PDO::FETCH_ASSOC);

// --- ส่งแจ้งเตือนอัตโนมัติ + แสดงตารางผล ---
echo "<h2>ระบบแจ้งเตือน COVID-19 พร้อมกรองและ Export Log</h2>";

// แท็บเมนูง่ายๆ
echo "<a href='?tab=data'>ข้อมูลผู้ป่วย</a> | <a href='?tab=log'>Log การแจ้งเตือน</a>";
echo "<hr>";

if ($tab == 'data') {
  echo "<h3>ข้อมูลผู้ป่วย COVID-19 Positive</h3>";
  echo "<form method='GET'>
          <input type='hidden' name='tab' value='data'>
          แพทย์: 
          <select name='doctor'>
            <option value=''>ทั้งหมด</option>
            <option value='นพ.สมชาย' " . ($filterDoctor == 'นพ.สมชาย' ? 'selected' : '') . ">นพ.สมชาย</option>
            <option value='พญ.สมหญิง' " . ($filterDoctor == 'พญ.สมหญิง' ? 'selected' : '') . ">พญ.สมหญิง</option>
          </select>
          วันที่เริ่ม: <input type='date' name='start' value='$startDate'>
          วันที่สิ้นสุด: <input type='date' name='end' value='$endDate'>
          <button type='submit'>ค้นหา</button>
        </form>";

  echo "<table border='1' cellpadding='5' cellspacing='0'>
          <tr>
            <th>HN</th><th>ชื่อ-สกุล</th><th>อายุ</th><th>ที่อยู่</th><th>โทร</th>
            <th>วันที่</th><th>แพทย์</th><th>Dx</th><th>ผลแลบ</th><th>สถานะแจ้งเตือน</th>
          </tr>";

  foreach ($results as $row) {
    $check = $conn->prepare("SELECT 1 FROM notify_log WHERE lab_order_number = ?");
    $check->execute([$row['lab_order_number']]);

    if ($check->rowCount() == 0) {
      // ส่งแจ้งเตือน
      $message = "📌 พบผู้ป่วย COVID-19\n"
               . "HN: {$row['hn']}\n"
               . "ชื่อ: {$row['fullname']} ({$row['age']} ปี)\n"
               . "ที่อยู่: {$row['informaddr']}\n"
               . "โทร: {$row['hometel']}\n"
               . "วันที่: {$row['vstdate']}\n"
               . "แพทย์: {$row['doctor']}\n"
               . "Diag: {$row['pdx']}\n"
               . "ผลแลบ: {$row['lab_order_result']}";

      $payload = [
        "client_key" => $client_key,
        "secret_key" => $secret_key,
        "message" => $message
      ];

      $ch = curl_init($api_url);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
      $response = curl_exec($ch);
      $curl_err = curl_error($ch);
      curl_close($ch);

      if ($response && !$curl_err) {
        $log = $conn->prepare("INSERT INTO notify_log (lab_order_number, hn, vstdate, doctor_name, pdx, result)
                               VALUES (?, ?, ?, ?, ?, ?)");
        $log->execute([
          $row['lab_order_number'],
          $row['hn'],
          $row['vstdate'],
          $row['doctor'],
          $row['pdx'],
          $row['lab_order_result']
        ]);
        $status = "<span style='color:green;'>ส่งแจ้งเตือนสำเร็จ</span>";
      } else {
        $status = "<span style='color:red;'>ส่งแจ้งเตือนไม่สำเร็จ: $curl_err</span>";
      }
    } else {
      $status = "<span style='color:gray;'>เคยส่งแจ้งเตือนแล้ว</span>";
    }

    echo "<tr>
            <td>{$row['hn']}</td>
            <td>{$row['fullname']}</td>
            <td>{$row['age']}</td>
            <td>{$row['informaddr']}</td>
            <td>{$row['hometel']}</td>
            <td>{$row['vstdate']}</td>
            <td>{$row['doctor']}</td>
            <td>{$row['pdx']}</td>
            <td>{$row['lab_order_result']}</td>
            <td>$status</td>
          </tr>";
  }
  echo "</table>";

} else if ($tab == 'log') {
  echo "<h3>Log การแจ้งเตือน</h3>";
  echo "<form method='GET'>
          <input type='hidden' name='tab' value='log'>
          แพทย์: 
          <select name='doctor'>
            <option value=''>ทั้งหมด</option>
            <option value='นพ.สมชาย' " . ($filterDoctor == 'นพ.สมชาย' ? 'selected' : '') . ">นพ.สมชาย</option>
            <option value='พญ.สมหญิง' " . ($filterDoctor == 'พญ.สมหญิง' ? 'selected' : '') . ">พญ.สมหญิง</option>
          </select>
          ผลตรวจ: 
          <select name='result'>
            <option value=''>ทั้งหมด</option>
            <option value='Positive' " . ($filterResult == 'Positive' ? 'selected' : '') . ">Positive</option>
            <option value='Negative' " . ($filterResult == 'Negative' ? 'selected' : '') . ">Negative</option>
          </select>
          วันที่เริ่ม: <input type='date' name='start' value='$startDate'>
          วันที่สิ้นสุด: <input type='date' name='end' value='$endDate'>
          <button type='submit'>ค้นหา</button>
          <button type='submit' name='export_log' value='1'>Export CSV</button>
        </form>";

  echo "<table border='1' cellpadding='5' cellspacing='0'>
          <tr>
            <th>ID</th><th>Lab Order Number</th><th>HN</th><th>วันที่รับบริการ</th>
            <th>เวลาส่งแจ้ง</th><th>แพทย์</th><th>ICD10</th><th>ผลตรวจ</th>
          </tr>";
  foreach ($logs as $r) {
    echo "<tr>
            <td>{$r['id']}</td>
            <td>{$r['lab_order_number']}</td>
            <td>{$r['hn']}</td>
            <td>{$r['vstdate']}</td>
            <td>{$r['sent_at']}</td>
            <td>{$r['doctor_name']}</td>
            <td>{$r['pdx']}</td>
            <td>{$r['result']}</td>
          </tr>";
  }
  echo "</table>";
} else {
  echo "ไม่พบแท็บข้อมูล";
}
?>
