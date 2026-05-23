<?php

    require_once 'server.php';
    require_once 'index1.html';

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
    <link href="https://fonts.googleapis.com/css2?family=Niramit:wght@500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=K2D:wght@300&display=swap" rel="stylesheet">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <title>รายการคนไข้ Fall Risk Alert</title>
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
                <h3>คนไข้พลัดตกหกล้ม Fall Risk Alert</h3>
            </div>
            <table id="table">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>HN</th>
                            <th>ชื่อ - สกุล</th>
                            <!-- <th>เลขบัตรประชาชน</th> -->
                            <th>เบอร์โทร</th> 
                            <th>อายุ</th> 
                            <th>เพศ</th> 
                            <th>ที่อยู่</th> 
                            <th>ผลวินิจฉัย</th>
                            <th>สถานบริการหลัก</th> 
                            <th>วันที่มารับบริการ</th> 
                            <!-- <th>Dianostic</th>  -->
                        </tr>
                    </thead>
                    <tbody>
                <?php
                    
                    $hosxp = $dbcon->query("SELECT
                    pt.hn,
                    concat( pt.pname, pt.fname, '  ', pt.lname ) AS fullname,
                    pt.cid,
                    pt.hometel,
                    ov.age_y AS 'age',
                    se.NAME AS 'sex',
                    pt.informaddr AS 'address',
                    ic.NAME AS 'PDX_EN',
                    ov.vstdate,
                    h.NAME AS 'mainstation',
                    po.hospsub,
                    ov.pdx,
                    ov.dx0,
                    ov.dx1,
                    ov.dx2,
                    ov.dx3,
                    ov.dx4,
                    ov.dx5 
                FROM
                    vn_stat ov
                    INNER JOIN er_regist e ON e.vn = ov.vn
                    LEFT OUTER JOIN patient pt ON ov.hn = pt.hn
                    LEFT OUTER JOIN sex se ON pt.sex = se.
                    CODE LEFT OUTER JOIN icd101 ic ON ov.pdx = ic.
                    CODE LEFT OUTER JOIN ovst ovst ON ovst.vn = ov.vn
                    LEFT OUTER JOIN hospcode h ON h.hospcode = ovst.hospsub
                    LEFT OUTER JOIN pttypeno po ON po.hn = ovst.hn 
                    AND po.pttype = ovst.pttype 
                WHERE
                    ov.age_y >= '60'  AND ov.vstdate BETWEEN '2025-08-01' AND CURDATE()
                    
                    AND (
                        ( ov.pdx >= 'w00' AND ov.pdx <= 'w109' ) 
                        OR ( ov.dx0 >= 'w00' AND ov.dx0 <= 'w109' ) 
                        OR ( ov.dx1 >= 'w00' AND ov.dx1 <= 'w109' ) 
                        OR ( ov.dx2 >= 'w00' AND ov.dx2 <= 'w109' ) 
                        OR ( ov.dx3 >= 'w00' AND ov.dx3 <= 'w109' ) 
                        OR ( ov.dx4 >= 'w00' AND ov.dx4 <= 'w109' ) 
                        OR ( ov.dx5 >= 'w00' AND ov.dx5 <= 'w109' ) 
                        OR ( ov.dx0 >= 'w18' AND ov.dx0 <= 'w199' ) 
                        OR ( ov.dx1 >= 'w18' AND ov.dx1 <= 'w199' ) 
                        OR ( ov.dx2 >= 'w18' AND ov.dx2 <= 'w199' ) 
                        OR ( ov.dx3 >= 'w18' AND ov.dx3 <= 'w199' ) 
                        OR ( ov.dx4 >= 'w18' AND ov.dx4 <= 'w199' ) 
                        OR ( ov.dx5 >= 'w18' AND ov.dx5 <= 'w199' ) 
                    ) 
                    AND (
                        ( ov.pdx LIKE 'S720%' ) 
                        OR ( ov.dx0 LIKE 'S720%' ) 
                        OR ( ov.dx1 LIKE 'S720%' ) 
                        OR ( ov.dx2 LIKE 'S720%' ) 
                        OR ( ov.dx3 LIKE 'S720%' ) 
                        OR ( ov.dx4 LIKE 'S720%' ) 
                        OR ( ov.dx5 LIKE 'S720%' ) 
                        OR ( ov.pdx LIKE 'S721%' ) 
                        OR ( ov.dx0 LIKE 'S721%' ) 
                        OR ( ov.dx1 LIKE 'S721%' ) 
                        OR ( ov.dx2 LIKE 'S721%' ) 
                        OR ( ov.dx3 LIKE 'S721%' ) 
                        OR ( ov.dx4 LIKE 'S721%' ) 
                        OR ( ov.dx5 LIKE 'S721%' ) 
                        OR ( ov.pdx LIKE 'S722%' ) 
                        OR ( ov.dx0 LIKE 'S722%' ) 
                        OR ( ov.dx1 LIKE 'S722%' ) 
                        OR ( ov.dx2 LIKE 'S722%' ) 
                        OR ( ov.dx3 LIKE 'S722%' ) 
                        OR ( ov.dx4 LIKE 'S722%' ) 
                        OR ( ov.dx5 LIKE 'S722%' ) 
                        OR ( ov.pdx LIKE 'S525%' ) 
                        OR ( ov.dx0 LIKE 'S525%' ) 
                        OR ( ov.dx1 LIKE 'S525%' ) 
                        OR ( ov.dx2 LIKE 'S525%' ) 
                        OR ( ov.dx3 LIKE 'S525%' ) 
                        OR ( ov.dx4 LIKE 'S525%' ) 
                        OR ( ov.dx5 LIKE 'S525%' ) 
                        OR ( ov.pdx LIKE 'S526%' ) 
                        OR ( ov.dx0 LIKE 'S526%' ) 
                        OR ( ov.dx1 LIKE 'S526%' ) 
                        OR ( ov.dx2 LIKE 'S526%' ) 
                        OR ( ov.dx3 LIKE 'S526%' ) 
                        OR ( ov.dx4 LIKE 'S526%' ) 
                        OR ( ov.dx5 LIKE 'S526%' ) 
                        OR ( ov.pdx LIKE 'S422%' ) 
                        OR ( ov.dx0 LIKE 'S422%' ) 
                        OR ( ov.dx1 LIKE 'S422%' ) 
                        OR ( ov.dx2 LIKE 'S422%' ) 
                        OR ( ov.dx3 LIKE 'S422%' ) 
                        OR ( ov.dx4 LIKE 'S422%' ) 
                        OR ( ov.dx5 LIKE 'S422%' ) 
                        OR ( ov.pdx LIKE 'S220%' ) 
                        OR ( ov.dx0 LIKE 'S220%' ) 
                        OR ( ov.dx1 LIKE 'S220%' ) 
                        OR ( ov.dx2 LIKE 'S220%' ) 
                        OR ( ov.dx3 LIKE 'S220%' ) 
                        OR ( ov.dx4 LIKE 'S220%' ) 
                        OR ( ov.dx5 LIKE 'S220%' ) 
                        OR ( ov.pdx LIKE 'S221%' ) 
                        OR ( ov.dx0 LIKE 'S221%' ) 
                        OR ( ov.dx1 LIKE 'S221%' ) 
                        OR ( ov.dx2 LIKE 'S221%' ) 
                        OR ( ov.dx3 LIKE 'S221%' ) 
                        OR ( ov.dx4 LIKE 'S221%' ) 
                        OR ( ov.dx5 LIKE 'S221%' ) 
                        OR ( ov.pdx LIKE 'S320%' ) 
                        OR ( ov.dx0 LIKE 'S320%' ) 
                        OR ( ov.dx1 LIKE 'S320%' ) 
                        OR ( ov.dx2 LIKE 'S320%' ) 
                        OR ( ov.dx3 LIKE 'S320%' ) 
                        OR ( ov.dx4 LIKE 'S320%' ) 
                        OR ( ov.dx5 LIKE 'S320%' ) 
                        OR ( ov.pdx LIKE 'S327%' ) 
                        OR ( ov.dx0 LIKE 'S327%' ) 
                        OR ( ov.dx1 LIKE 'S327%' ) 
                        OR ( ov.dx2 LIKE 'S327%' ) 
                        OR ( ov.dx3 LIKE 'S327%' ) 
                        OR ( ov.dx4 LIKE 'S327%' ) 
                        OR ( ov.dx5 LIKE 'S327%' ) 
                    ) 
                ORDER BY
                   date(ovst.vstdate) DESC 
                    ");
                                            
                    $hosxp->execute();

                    $user = $hosxp->fetchAll();
                    for ($x = 0 ; $x < count($user) ; $x++) {
                        $hn = "'".$user[$x]['hn']."'";
                        $fullname = "'".$user[$x]['fullname']."'";
                        $sex = "'".$user[$x]['sex']."'";
                        $age = "'".$user[$x]['age']."'";
                        $address = "'".$user[$x]['address']."'";
                        // $cid = "'".$user[$x]['cid']."'";
                        $hometel = "'".$user[$x]['hometel']."'";
                        $PDX_EN = "'".$user[$x]['PDX_EN']."'";     
                        $vstdate = "'".$user[$x]['vstdate']."'";
                        $mainstation = "'".$user[$x]['mainstation']."'";                        
                        // $PDX_EN = "'".$user[$x]['pdx']."'";                        
                ?>
                    <tr>

                        <td>
                            <button class="btn btn-success btn-lg" onclick="Sentdata(<?php echo $hn ?>, <?php echo $fullname ?>, <?php echo $sex ?>, <?php echo $age ?>,
                            <?php echo $address ?>, <?php echo $hometel ?>, <?php echo $PDX_EN ?>, <?php echo $vstdate ?>, <?php echo $mainstation ?>)">
                            <span class='msi' style='font-size:36px;'>chat</span></button>
                        </td> 
                        <td><?php echo $user[$x]['hn'] ?></td>
                        <td><?php echo $user[$x]['fullname'] ?></td>
                        <td><?php echo $user[$x]['sex'] ?></td>
                        <td><?php echo $user[$x]['age'] ?></td>
                        <td><?php echo $user[$x]['address'] ?></td>
                        <td><?php echo $user[$x]['hometel'] ?></td>
                        <td><?php echo $user[$x]['PDX_EN'] ?></td>
                        <td><?php echo $user[$x]['mainstation']?></td>
                        <td class="alert-date"><?php echo $user[$x]['vstdate'] ?></td>
                        
                        
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

        function Sentdata(hn, fullname, age, sex, address, hometel, PDX_EN, vstdate, mainstation) {
            let body = {
                hn: hn,
                fullname: fullname,
                sex: sex,
                age: age,
                address: address,
                hometel: hometel,
                PDX_EN: PDX_EN,
                mainstation: mainstation,
                vstdate: vstdate,
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
                    window.location.href='fracture.php' 
                }
            })
                })

            $.ajax({
                url: 'sent_fracture.php',
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