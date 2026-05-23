<?php

    require_once 'server.php';
    require_once __DIR__ . '/auth_guard.php';
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
    <title>รายการคนไข้กลุ่มเสี่ยงกินยา</title>
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
                <h3>คนไข้โรคไข้กลุ่มเสี่ยงกินยาอันตราย!</h3>
            </div>
            <table id="table">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>HN</th>
                            <th>ชื่อ - สกุล</th>
                            <th>ชื่อยา</th> 
                            <th>วันที่มารับบริการ</th>
                            <th>HPI</th> 
                        </tr>
                    </thead>
                    <tbody>
                <?php
                    
                    $hosxp = $dbcon->query("SELECT ovst.hn , CONCAT( pt.pname, pt.fname, ' ', pt.lname ) AS 'fullname',
                                    d.NAME,
                                    ovst.vstdate,
                                    cast(opd.hpi as char(200)) as 'hpi' 
                                FROM
                                    ovst ovst
                                    LEFT OUTER JOIN opdscreen opd ON opd.vn = ovst.vn
                                    LEFT OUTER JOIN patient pt ON pt.hn = ovst.hn
                                    LEFT OUTER JOIN opitemrece op on op.hn = ovst.hn 
                                    LEFT OUTER JOIN drugitems d on d.icode = op.icode
                                WHERE
                                    ovst.vn IN ( SELECT vn FROM opitemrece WHERE icode = '1483860' ) 
                                GROUP BY
                                    ovst.vn 
                                ORDER BY
                                    vstdate DESC 
                                    LIMIT 20");  
                                            
                    $hosxp->execute();

                    $user = $hosxp->fetchAll();
                    for ($x = 0 ; $x < count($user) ; $x++) {
                        $hn = "'".$user[$x]['hn']."'";
                        $fullname = "'".$user[$x]['fullname']."'";
                        $NAME = "'".$user[$x]['NAME']."'";
                        $vstdate = "'".$user[$x]['vstdate']."'";
                        $hpi = "'".$user[$x]['hpi']."'";                        

                ?>
                    <tr >
                        <td><button class="btn btn-success btn-lg" onclick="Sentdata(<?php echo $hn ?>,<?php echo $fullname ?>, 
                        <?php echo $NAME ?>,<?php echo $vstdate ?>,<?php echo $hpi ?>)"></button></td> 
                        <td><?php echo $user[$x]['hn'] ?></td>
                        <td><?php echo $user[$x]['fullname'] ?></td>
                        <td><?php echo $user[$x]['NAME'] ?></td>
                        <td><?php echo $user[$x]['vstdate'] ?></td>
                        <td><?php echo $user[$x]['hpi'] ?></td>

                        
                    </tr>
                <?php
                    } 
                ?>  
            </table>
    </div> 
</tbody>

        
    </div>
    <script src="https://code.jquery.com/jquery-3.6.4.js"></script>
    <script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script>
        $(document).ready( function () {
            $('#table').DataTable();
        } );

        function Sentdata(hn, fullname,NAME,vstdate, hpi) {
            let body = {
                hn: hn,
                fullname: fullname,
                NAME: NAME,
                vstdate: vstdate,
                hpi: hpi,
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
                    window.location.href='drugitems01.php'
                }
            })
                })

            $.ajax({
                url: 'sentdrug.php',
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
//        function Testclick(){
 //           $(document).ready(function() {
 //               Swal.fire({
 //               title: 'success',
//                text: 'ดำเนินการส่งข้อมูลเรียบร้อยแล้ว!',
//                icon: 'success',
 //               timer: 2500,
 //                showConfirmButton: false
 //               });
 //           })
  //      }


    
    </script>
</body>
</html>