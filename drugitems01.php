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
                            <!-- <th>ชื่อ</th>
                            <th>นามสกุล</th> -->
                            <th>วันที่มารับบริการ</th>
                            <th>ชื่อยา</th> 
                            <th>สถานะ</th> 
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            
                            $hosxp = $dbcon->query("SELECT
                            pt.hn,
                            concat( pt.pname, pt.fname, ' ', pt.lname ) AS fullname,
                            opi.vstdate,
                            d.NAME,
                            concat( t.NAME, ' : ', k.department ) AS Statusdep 
                        FROM
                            opitemrece opi
                            LEFT OUTER JOIN patient pt ON pt.hn = opi.hn
                            LEFT OUTER JOIN drugitems d ON d.icode = opi.icode
                            LEFT OUTER JOIN ovst ovst ON ovst.hn = opi.hn
                            LEFT OUTER JOIN ovstost t ON t.ovstost = ovst.ovstost
                            LEFT OUTER JOIN kskdepartment k ON k.depcode = ovst.cur_dep 
                        WHERE
                            opi.vstdate BETWEEN '2025-06-01' AND  CURDATE()
                            AND opi.icode = '1483860' 
                        GROUP BY
                            ovst.hn 
                        ORDER BY
                            opi.vstdate DESC 
                            LIMIT 100");
                                                    
                            $hosxp->execute();

                            $user = $hosxp->fetchAll();
                            for ($x = 0 ; $x < count($user) ; $x++) {
                            // $vn = "'".$user[$x]['vn']."'";
                                $hn = "'".$user[$x]['hn']."'";
                                $fullname = "'".$user[$x]['fullname']."'";
                                // $fname = "'".$user[$x]['fname']."'";
                                // $lname = "'".$user[$x]['lname']."'";
                                //$pdx = "'".$user[$x]['pdx']."'";
                                $vstdate = "'".$user[$x]['vstdate']."'";
                                $name = "'".$user[$x]['NAME']."'";
                                $Statusdep = "'".$user[$x]['Statusdep']."'";                    
                        ?>
                            <tr >
                                <td><button class="btn btn-success btn-lg" onclick="Sentdata(<?php echo $vstdate ?>, <?php echo $hn ?>,<?php echo $fullname ?>, <?php echo $name ?>, <?php echo $Statusdep ?>)">
                                <span class='msi' style='font-size:36px;'>chat</span></button></td> 
                                <td><?php echo $user[$x]['hn'] ?></td>
                                <td><?php echo $user[$x]['fullname'] ?></td>
                                <td class="alert-date"><?php echo $user[$x]['vstdate'] ?></td>
                                <td><?php echo $user[$x]['NAME'] ?></td>
                                <td><?php echo $user[$x]['Statusdep'] ?></td>
                                
                                
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

        function Sentdata(vstdate,hn, fullname, name, Statusdep) {
            let body = {
                vstdate: vstdate,
                hn: hn,
                fullname: fullname,
                name: name,
                Statusdep: Statusdep
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
                url: 'sentdrug01.php',
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