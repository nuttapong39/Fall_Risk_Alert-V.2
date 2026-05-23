<?php 

require_once ('config.php');
require_once __DIR__ . '/auth_guard.php';
require_once ('index1.html');

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/index.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/jquery.dataTables.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <title>Document</title>
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="headline mt-2" style="text-align: center;">
                <p style="font-size: 32px;">ภาพรวมทั้งหมด</p>
            </div>
                <div class="card text-white bg-primary mb-3 ml-3 col" style="max-width: 25rem;">
                        <div class="card-header" style="font-size:32px;">ประจำปี 2567</div>
                        <div class="card-body">
                            <h5 class="card-title" style="font-size:24px;">จำนวนคนไข้ทั้งหมด</h5>
                            <p class="card-text" style="font-size:24px;"> <span class='msi mt-4 mr-4' style='font-size:80px;'>stethoscope</span> 112 คน</p>
                        </div>
                    </div>
                    <div class="card text-white bg-success mb-3 ml-3 col" style="max-width: 25rem;">
                        <div class="card-header" style="font-size:32px;">รายเดือน</div>
                        <div class="card-body">
                            <h5 class="card-title" style="font-size:24px;">จำนวนคนไข้ทั้งหมด</h5>
                            <p class="card-text" style="font-size:24px;"> <span class='msi mt-4 mr-4' style='font-size:80px;'>stethoscope</span> 4 คน</p>
                        </div>
                    </div>
                    <div class="card text-white bg-danger mb-3 ml-3 col" style="max-width: 25rem;">
                        <div class="card-header" style="font-size:32px;">รายสัปดาห์</div>
                        <div class="card-body">
                            <h5 class="card-title" style="font-size:24px;">จำนวนคนไข้ทั้งหมด</h5>
                            <p class="card-text" style="font-size:24px;"> <span class='msi mt-4 mr-4' style='font-size:80px;'>stethoscope</span> 1 คน</p>
                        </div>
                    </div>
                    <div class="card text-white bg-warning mb-3 ml-3 col" style="max-width: 25rem;">
                        <div class="card-header" style="font-size:32px;">รายวัน</div>
                        <div class="card-body">
                            <h5 class="card-title" style="font-size:24px;">จำนวนคนไข้ทั้งหมด</h5>
                            <p class="card-text" style="font-size:24px;"> <span class='msi mt-4 mr-4' style='font-size:80px;'>stethoscope</span> 0 คน</p>
                        </div>
                    </div>
                </div>
                <!-- <div class="card text-white bg-primary mb-3 ml-3 col" style="max-width: 25rem;">
                        <div class="card-header" style="font-size:32px;">ประจำปี 2567</div>
                        <div class="card-body">
                            <h5 class="card-title" style="font-size:24px;">จำนวนคนไข้ทั้งหมด</h5>
                            <p class="card-text" style="font-size:24px;"> <span class='msi mt-4 mr-4' style='font-size:80px;'>stethoscope</span> 112 คน</p>
                        </div>
                    </div>
                    <div class="card text-white bg-success mb-3 ml-3 col" style="max-width: 25rem;">
                        <div class="card-header" style="font-size:32px;">รายเดือน</div>
                        <div class="card-body">
                            <h5 class="card-title" style="font-size:24px;">จำนวนคนไข้ทั้งหมด</h5>
                            <p class="card-text" style="font-size:24px;"> <span class='msi mt-4 mr-4' style='font-size:80px;'>stethoscope</span> 4 คน</p>
                        </div>
                    </div>
                    <div class="card text-white bg-danger mb-3 ml-3 col" style="max-width: 25rem;">
                        <div class="card-header" style="font-size:32px;">รายสัปดาห์</div>
                        <div class="card-body">
                            <h5 class="card-title" style="font-size:24px;">จำนวนคนไข้ทั้งหมด</h5>
                            <p class="card-text" style="font-size:24px;"> <span class='msi mt-4 mr-4' style='font-size:80px;'>stethoscope</span> 1 คน</p>
                        </div>
                    </div>
                    <div class="card text-white bg-warning mb-3 ml-3 col" style="max-width: 25rem;">
                        <div class="card-header" style="font-size:32px;">รายวัน</div>
                        <div class="card-body">
                            <h5 class="card-title" style="font-size:24px;">จำนวนคนไข้ทั้งหมด</h5>
                            <p class="card-text" style="font-size:24px;"> <span class='msi mt-4 mr-4' style='font-size:80px;'>stethoscope</span> 0 คน</p>
                        </div>
                    </div>
                </div>
        </div> -->
        <div class="col-lg mb-2 bg-light text-dark" style="max-width:800px;" style="max-height:800px;">
            ทดสอบพื้นหลัง
        </div> 
        <div class="mb-3">
            <label for="exampleFormControlInput1" class="form-label">Email address</label>
            <input type="email" class="form-control" id="exampleFormControlInput1" placeholder="name@example.com">
        </div>
        <div class="mb-3">
            <label for="exampleFormControlTextarea1" class="form-label">Example textarea</label>
            <textarea class="form-control" id="exampleFormControlTextarea1" rows="3"></textarea>
        </div>
    </div>
</body>
</html>