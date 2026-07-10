<?php
require_once('server.php');
require_once('index1.html');
$filterDoctor = $_GET['doctor'] ?? '';
$filterStart = $_GET['start_date'] ?? date('Y-m-01');
$filterEnd = $_GET['end_date'] ?? date('Y-m-d');

// ดึงรายชื่อแพทย์ทั้งหมด
$doctorStmt = $dbcon->query("SELECT DISTINCT d.name FROM doctor d INNER JOIN vn_stat ov ON ov.dx_doctor = d.CODE");
$doctorList = $doctorStmt->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/sources/covid_source.php';
$data = covid_source_rows($filterStart, $filterEnd, ['3066','3082','3084','3088'], false, $filterDoctor ?: '');
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>รายงานผู้ป่วย COVID-19</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap5.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Kanit&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Kanit', sans-serif; background-color: #f0f2f5; }
    .card { border-radius: 1rem; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
    .btn-line { background-color: #06c755; color: white; }
    .btn-line:hover { background-color: #059d48; }
    .filter-form label { font-weight: bold; }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="card p-4">
    <h3 class="text-center fw-bold mb-4 text-primary">📋 รายงานผู้ป่วย COVID-19</h3>

    <!-- ฟอร์มกรองข้อมูล -->
    <form class="row g-3 mb-4 filter-form" method="get">
      <div class="col-md-4">
        <label for="doctor">แพทย์:</label>
        <select class="form-select" id="doctor" name="doctor">
          <option value="">ทั้งหมด</option>
          <?php foreach ($doctorList as $doc): ?>
            <option value="<?= $doc ?>" <?= $filterDoctor === $doc ? 'selected' : '' ?>><?= $doc ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="col-md-3">
        <label for="start_date">วันที่เริ่มต้น:</label>
        <input type="date" class="form-control" name="start_date" value="<?= $filterStart ?>">
      </div>
      <div class="col-md-3">
        <label for="end_date">ถึงวันที่:</label>
        <input type="date" class="form-control" name="end_date" value="<?= $filterEnd ?>">
      </div>
      <div class="col-md-2 align-self-end">
        <button class="btn btn-primary w-100" type="submit"><i class="fas fa-search"></i> ค้นหา</button>
      </div>
    </form>

    <div class="table-responsive">
      <table id="covidTable" class="table table-bordered table-hover table-striped">
        <thead class="table-dark text-center">
          <tr>
            <th>#</th>
            <th>HN</th>
            <th>ชื่อ - สกุล</th>
            <th>อายุ</th>
            <th>เลข ปชช.</th>
            <th>ที่อยู่</th>
            <th>เบอร์โทร</th>
            <th>วันที่รับบริการ</th>
            <th>แพทย์</th>
            <th>ICD10</th>
            <th>ผลตรวจ</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($data as $index => $row): ?>
          <tr>
            <td class="text-center">
              <button class="btn btn-line btn-sm send-btn"
                data-hn="<?= $row['hn'] ?>"
                data-fullname="<?= htmlspecialchars($row['fullname']) ?>"
                data-age="<?= $row['age'] ?>"
                data-cid="<?= $row['cid'] ?>"
                data-informaddr="<?= htmlspecialchars($row['informaddr']) ?>"
                data-hometel="<?= $row['hometel'] ?>"
                data-vstdate="<?= $row['vstdate'] ?>"
                data-icd10="<?= $row['pdx'] ?>"
                data-doctor="<?= htmlspecialchars($row['doctor']) ?>"
                data-result="<?= $row['lab_order_result'] ?>"
              >
                <i class="fab fa-line fa-lg"></i>
              </button>
            </td>
            <td class="text-center"><?= $row['hn'] ?></td>
            <td><?= htmlspecialchars($row['fullname']) ?></td>
            <td class="text-center"><?= $row['age'] ?></td>
            <td><?= $row['cid'] ?></td>
            <td><?= htmlspecialchars($row['informaddr']) ?></td>
            <td><?= $row['hometel'] ?></td>
            <td class="text-center text-danger fw-bold"><?= $row['vstdate'] ?></td>
            <td><?= htmlspecialchars($row['doctor']) ?></td>
            <td class="text-center"><?= $row['pdx'] ?></td>
            <td class="text-center fw-bold"><?= $row['lab_order_result'] ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
  $(document).ready(function () {
    $('#covidTable').DataTable({
      language: {
        search: "ค้นหา:",
        lengthMenu: "แสดง _MENU_ รายการ",
        info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
        paginate: {
          first: "หน้าแรก",
          last: "หน้าสุดท้าย",
          next: "ถัดไป",
          previous: "ก่อนหน้า"
        }
      }
    });

    $(document).on('click', '.send-btn', function () {
      const btn = $(this);
      const data = {
        hn: btn.data('hn'),
        fullname: btn.data('fullname'),
        age: btn.data('age'),
        cid: btn.data('cid'),
        informaddr: btn.data('informaddr'),
        hometel: btn.data('hometel'),
        vstdate: btn.data('vstdate'),
        icd10: btn.data('pdx'),
        doctor: btn.data('doctor'),
        // pdx: btn.data('pdx'),
        result: btn.data('result'),
        disease: ''
      };

      Swal.fire({
        title: 'ส่งข้อมูลสำเร็จ!',
        text: 'ดำเนินการส่งข้อมูลเรียบร้อยแล้ว!',
        icon: 'success',
        timer: 2000,
        showConfirmButton: false
      });

      $.post('sentcovid.php', data, function (response) {
        console.log('ส่งข้อมูลแล้ว:', response);
      });
    });
  });
</script>
</body>
</html>
