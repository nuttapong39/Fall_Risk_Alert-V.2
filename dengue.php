
<?php

require_once ('server.php');
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
<!-- <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> -->
<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/jquery.dataTables.min.css">
<!-- <link href="https://fonts.googleapis.com/css2?family=Niramit:wght@500&display=swap" rel="stylesheet"> -->
<!-- <link href="https://fonts.googleapis.com/css2?family=K2D:wght@300&display=swap" rel="stylesheet"> -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300&display=swap" rel="stylesheet">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<title>คนไข้โรคไข้เลือดออก</title>
</head>
<body>
<style>
    .alert-date {
        /* font-size: large; */
        color: red;
    }
</style>
<div class="container">
    <div class="row mt-2">
            <div class="heading mt-2 mb-2" role="alert" style="text-align:center">
                <h3>คนไข้โรคไข้เลือดออก!</h3>
            </div>
        <table id="table">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>HN</th>
                        <th>ชื่อ - สกุล</th>
                        <th>อายุ</th>
                        <th>เลขบัตรประชาชน</th>
                        <th>ที่อยู่</th>
                        <th>เบอร์โทรติดต่อ</th>
                        <th>วันที่รับบริการ</th>
                        <th>แพทย์ผู้ตรวจ</th>
                        <th>ชื่อโรค</th>
                        <th>ICD10</th>
                        <th>ผลตรวจ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        
                        $hosxp = $dbcon->query("SELECT
                        ov.hn,
                        CONCAT( pt.pname, pt.fname, '  ', pt.lname ) AS 'fullname',
                        timestampdiff(
                            YEAR,
                            birthday,
                        curdate()) AS age,
                        ov.cid,
                        pt.informaddr,
                        pt.hometel,
                        ov.vstdate,
                        d.NAME AS 'doctor',
                        i.NAME AS 'disease',
                        ov.pdx AS 'icd10',
                        l.lab_order_result AS 'result' 
                    FROM
                        vn_stat ov
                        LEFT OUTER JOIN ovst ovst ON ovst.vn = ov.vn
                        LEFT OUTER JOIN patient pt ON pt.hn = ov.hn
                        LEFT OUTER JOIN icd101 i ON i.CODE = ov.pdx
                        LEFT OUTER JOIN icd101 i1 ON i1.CODE = ov.dx0
                        LEFT OUTER JOIN doctor d ON d.CODE = ov.dx_doctor
                        INNER JOIN lab_head h ON h.vn = ov.vn
                        INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number 
                    WHERE
                        ov.hn = pt.hn
                        AND ov.vstdate BETWEEN '2025-06-01' AND  CURDATE()
                        AND l.lab_items_code = '2891' 
                        AND ( l.lab_order_result = 'Positive' OR l.lab_order_result = 'Weakly Positive IgM' ) 
                        AND (
                            ( ov.pdx >= 'A90' AND ov.pdx <= 'A99' ) 
                            OR ( ov.dx0 >= 'A90' AND ov.dx0 <= 'A99' ) 
                            OR ( ov.dx1 >= 'A90' AND ov.dx1 <= 'A99' ) 
                            OR ( ov.dx2 >= 'A90' AND ov.dx2 <= 'A99' ) 
                            OR ( ov.dx3 >= 'A90' AND ov.dx3 <= 'A99' ) 
                            OR ( ov.dx4 >= 'A90' AND ov.dx4 <= 'A99' ) 
                            OR ( ov.dx5 >= 'A90' AND ov.dx5 <= 'A99' ) 
                        ) 
                    GROUP BY
                        ov.hn 
                    ORDER BY
                        ov.vstdate DESC 
                        LIMIT 100");                             
                $hosxp->execute();

                $user = $hosxp->fetchAll();
                for ($x = 0 ; $x < count($user) ; $x++) {
                    $hn = "'".$user[$x]['hn']."'";
                    $fullname = "'".$user[$x]['fullname']."'";
                    $age = "'".$user[$x]['age']."'";
                    $cid = "'".$user[$x]['cid']."'";        
                    $informaddr = "'".$user[$x]['informaddr']."'";
                    $hometel = "'".$user[$x]['hometel']."'";
                    $vstdate = "'".$user[$x]['vstdate']."'";                  
                    $doctor = "'".$user[$x]['doctor']."'";                  
                    $disease = "'".$user[$x]['disease']."'";                  
                    $icd10 = "'".$user[$x]['icd10']."'";                  
                    $result = "'".$user[$x]['result']."'";                  
            ?>
                <tr >
                    <td><button class="btn btn-success btn-lg" onclick="Sentdata(<?php echo $hn ?>, <?php echo $fullname ?>, <?php echo $age ?>,  <?php echo $cid ?> , <?php echo $informaddr ?>, <?php echo $hometel ?> , <?php echo $vstdate ?> , <?php echo $disease ?> , <?php echo $icd10 ?> , <?php echo $doctor ?> , <?php echo $result ?>)">
                    <span class='msi' style='font-size:36px;'>chat</span></button></td> 

                    <td><?php echo $user[$x]['hn'] ?></td>
                    <td><?php echo $user[$x]['fullname'] ?></td>
                    <td><?php echo $user[$x]['age'] ?></td>
                    <td><?php echo $user[$x]['cid'] ?></td>
                    <td><?php echo $user[$x]['informaddr'] ?></td>
                    <td><?php echo $user[$x]['hometel'] ?></td>
                    <td class="alert-date"><?php echo $user[$x]['vstdate'] ?></td>
                    <td><?php echo $user[$x]['doctor'] ?></td>
                    <td><?php echo $user[$x]['disease'] ?></td>
                    <td><?php echo $user[$x]['icd10'] ?></td>
                    <td><?php echo $user[$x]['result'] ?></td>
                </tr>
            <?php
                } 
            ?>   
                    </tbody>
        </table>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.4.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script>
    $(document).ready( function () {
        $('#table').DataTable();
    } );

    function Sentdata( hn, fullname , age, cid, informaddr, hometel, vstdate, disease, icd10, doctor, result ) {
        let body = {
            hn: hn,
            fullname : fullname,
            age: age,
            cid: cid,
            informaddr: informaddr,
            hometel: hometel,
            vstdate: vstdate,
            disease: disease,
            icd10: icd10,
            doctor: doctor,
            result: result,
            
        }
        $(document).ready(function() {
            Swal.fire({
            title: 'success',
            text: 'ดำเนินการส่งข้อมูลเรียบร้อยแล้ว!',
            icon: 'success',
            timer: 2500,
            showConfirmButton: false
        }).then((result) =>{
            if(result.isDismissed){
                window.location.href='dengue.php'
            }
        })
            })

        $.ajax({
            url: 'sentdengue.php',
            type: "POST",
            data: body,
            success: function(data) {
                console.log(data);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log(jqXHR);
                console.log(textStatus);
                console.log(errorThrown);
            }
        })
        
    }
</script>
</body>
</html>