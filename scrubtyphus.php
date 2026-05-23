
<?php

require_once ('server.php');
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
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<title>คนไข้โรคสครับไทฟัส</title>
</head>
<style>
    .alert-date {
        /* font-size: large; */
        color: red;
    }
</style>
<body>
<div class="container">
    <div class="row mt-2">
            <div class="heading mt-2 mb-2" role="alert" style="text-align:center">
                <h3>คนไข้โรค Scrubtyphus!</h3>
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
                
                $hosxp = $dbcon->query("SELECT ov.hn,CONCAT(pt.pname,pt.fname,'  ',pt.lname) as 'fullname',timestampdiff(year,birthday,curdate()) as age,ov.cid,pt.informaddr,pt.hometel,ov.vstdate,d.name as 'doctor',i.name as 'disease',ov.pdx as 'icd10',l.lab_order_result as 'result'
                                        from vn_stat ov 
                                        left outer join ovst ovst on ovst.vn = ov.vn
                                        left outer join patient pt on pt.hn = ov.hn
                                        left outer join icd101 i on i.code = ov.pdx
                                        left outer join icd101 i1 on i1.code = ov.dx0
                                        left outer join doctor d on d.code = ov.dx_doctor
                                        inner join lab_head h on  h.vn = ov.vn
                                        inner join lab_order l on l.lab_order_number = h.lab_order_number
                                        
                                        where ov.hn=pt.hn and l.lab_items_code = '291'  and  l.lab_order_result  = 'Positive' and ov.pdx = 'A753' 
                                        GROUP BY ov.hn ORDER BY ov.vstdate DESC ");                             
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
                window.location.href='scrubtyphus.php'
            }
        })
            })

        $.ajax({
            url: 'sentscrub.php',
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