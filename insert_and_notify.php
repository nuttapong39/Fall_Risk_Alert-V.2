<?php
require 'sentcovid_notify.php'; // สำหรับส่ง MOPH Notify

// 1. เชื่อมต่อฐานข้อมูล
$conn = new mysqli("localhost", "root", "", "your_database");
if ($conn->connect_error) {
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
}

// 2. รับค่าจากฟอร์ม
$hn = $_POST['hn'];
$fullname = $_POST['fullname'];
$vstdate = $_POST['vstdate'];
$covidresult = $_POST['covidresult'];
$covidlab = $_POST['covidlab'];
$doctorname = $_POST['doctorname'];
$icd10 = $_POST['icd10'];

// 3. บันทึกข้อมูลลงตาราง
$stmt = $conn->prepare("INSERT INTO covid_data (hn, fullname, vstdate, covidresult, covidlab, doctorname, icd10)
                        VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssss", $hn, $fullname, $vstdate, $covidresult, $covidlab, $doctorname, $icd10);

if ($stmt->execute()) {
    echo "✅ บันทึกข้อมูลสำเร็จ<br>";

    // 4. ส่งแจ้งเตือน MOPH Notify
    $notify_data = [
        "hn" => $hn,
        "fullname" => $fullname,
        "vstdate" => $vstdate,
        "covidresult" => $covidresult,
        "covidlab" => $covidlab,
        "doctorname" => $doctorname,
        "icd10" => $icd10
    ];
    $success = sendCovidNotify($notify_data);

    echo $success ? "✅ แจ้งเตือนไลน์เรียบร้อย" : "❌ แจ้งเตือนไม่สำเร็จ";
} else {
    echo "❌ บันทึกข้อมูลล้มเหลว: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
