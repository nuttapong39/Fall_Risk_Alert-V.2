<?php 

require_once('server.php');
require_once('index1.html');

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
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> 
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.2/css/jquery.dataTables.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Niramit:wght@500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=K2D:wght@300&display=swap" rel="stylesheet">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
    <title>Document</title>

<style>
    .alert-date {
        /* font-size: large; */
        color: red;
    }
</style>
</head>

<body>
    <div class="container">
        <div class="row mt-4">
            <div class="heading mt-2 mb-2" role="alert" style="text-align:center">
                <h3>คนไข้โรคกลุ่มเสี่ยงถูกทำร้ายร่างกาย / ข่มขื่น!</h3>
            </div>
            <table id="table" class="mt-4">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>เลข VN</th>
                        <th>เลข HN</th>
                        <th>ชื่อ - นามสกุล</th>
                        <th>เลขบัตรประชาชน</th>
                        <th>วันที่สั่ง LAB</th>
                        <th>ชื่อรายการ LAB</th>
                        <th>ผลตรวจ</th>
                    </tr>
                </thead>
                    <tbody>
                            <?php 
                            
                            $hosxp = $dbcon->query("SELECT
                            h.vn,
                            pt.hn,
                            concat( pt.pname, pt.fname, '    ', pt.lname ) AS fullname,
                            pt.cid,
                            h.order_date,
                            lab_items_name_ref,
                            lab_order_result 
                        FROM
                            lab_order l
                            INNER JOIN lab_head h ON l.lab_order_number = h.lab_order_number
                            LEFT OUTER JOIN patient pt ON pt.hn = h.hn 
                        WHERE
                            lab_items_code = '2811' AND order_date BETWEEN '2025-06-01' AND  CURDATE()
                        ORDER BY
                            order_date DESC 
                            LIMIT 100");
                        $hosxp->execute();
                        $user = $hosxp->fetchAll();
                        for ($x = 0 ; $x < count($user) ; $x++) {
                            $vn = "'".$user[$x]['vn']."'";
                            $hn = "'".$user[$x]['hn']."'";
                            $fullname = "'".$user[$x]['fullname']."'";
                            $cid = "'".$user[$x]['cid']."'";
                            $order_date = "'".$user[$x]['order_date']."'";      
                            $lab_items_name_ref = "'".$user[$x]['lab_items_name_ref']."'"; 
                            $lab_order_result = "'".$user[$x]['lab_order_result']."'"; 
                    ?>
                        <tr >
                            <td>
                                <button class="btn btn-success btn-lg" onclick="Sentdata(<?php echo $user[$x]['vn'] ?>, <?php echo $hn ?>,<?php echo $fullname ?>, 
                                <?php echo $cid ?>, <?php echo $order_date ?>, <?php echo $lab_items_name_ref ?>, <?php echo $lab_order_result ?>)"><span class='msi' style='font-size:36px;'>chat</span></button>
                            </td> 
                            <td><?php echo $user[$x]['vn'] ?></td>
                            <td><?php echo $user[$x]['hn'] ?></td>
                            <td><?php echo $user[$x]['fullname'] ?></td>
                            <td><?php echo $user[$x]['cid'] ?></td>
                            <td class="alert-date"><?php echo $user[$x]['order_date'] ?></td>
                            <td><?php echo $user[$x]['lab_items_name_ref'] ?></td>
                            <td><?php echo $user[$x]['lab_order_result'] ?></td>
                        </tr>
                    <?php
                        }
                    ?>   
                        
                    </tbody>
            </table>
        </div>
    </div>    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.3.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script>
         $(document).ready( function () {
            $('#table').DataTable();
        } );

        function Sentdata(vn,hn, fullname , cid, order_date, lab_items_name_ref, lab_order_result) {
            let body = {
                vn: vn,
                hn: hn,
                fullname: fullname,
                cid: cid,
                order_date: order_date,
                lab_items_name_ref: lab_items_name_ref,
                lab_order_result: lab_order_result,
            }
            $(document).ready(function() {
                Swal.fire({
                title: 'success',
                text: 'ดำเนินการส่งข้อมูลเรียบร้อยแล้ว !!',
                icon: 'success',
                timer: 2500,
                showConfirmButton: false
            }).then((result)=>{
                if(result.isDismissed){
                    window.location.href='sexual.php'
                }
            })
                }) 

            $.ajax({
                url: 'sentsex2.php',
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