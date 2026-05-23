<?php

    require_once ('config.php');
    require_once ('index1.html');
?>

<!DOCTYPE html>
<html lang="en">
<head>
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
    
    <title>Document</title>
</head>
<body>
    <div class="container">
        <div class="row mt-2">
            <table id="table" class="table-default table-striped">
                <thead class="table-dark table-striped">
                    <tr>
                        <th>ID</th>
                        <th>หัวข้อที่ประชุม</th>
                        <th>ผู้จอง</th>
                        <th>ตำแหน่ง</th>
                        <th>กลุ่มงาน</th>
                        <th>วัน/เวลาที่ขอใช้</th>
                        <th>รายละเอียด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    
                        $hosoffice = $dbcon->query(" SELECT id,SERVICE_STORY, PERSON_REQUEST_NAME, PERSON_REQUEST_POSITION , PERSON_REQUEST_DEP, DATE_TIME_REQUEST FROM `room_service`
                        ORDER BY DATE_TIME_REQUEST desc LIMIT 10");

                        $hosoffice->execute();
                        $user = $hosoffice->fetchAll();
                        for($x = 0; $x < count($user); $x++) {
                            $id = ".".$user[$x]['id'].".";
                            $service_story = ".".$user[$x]['SERVICE_STORY'].".";
                            $PERSON_REQUEST_NAME = ".".$user[$x]['PERSON_REQUEST_NAME'].".";
                            $PERSON_REQUEST_POSITION = ".".$user[$x]['PERSON_REQUEST_POSITION'].".";
                            $person_request_dep = ".".$user[$x]['PERSON_REQUEST_DEP'].".";
                            $date_time_request = ".".$user[$x]['DATE_TIME_REQUEST'].".";      
                    ?>
                    <tr>
                        <td><?php echo$user[$x]['id'] ?></td>
                        <td><?php echo$user[$x]['SERVICE_STORY'] ?></td>
                        <td><?php echo$user[$x]['PERSON_REQUEST_NAME'] ?></td>
                        <td><?php echo$user[$x]['PERSON_REQUEST_POSITION'] ?></td>
                        <td><?php echo$user[$x]['PERSON_REQUEST_DEP'] ?></td>
                        <td><?php echo$user[$x]['DATE_TIME_REQUEST'] ?></td>
                        <td><a href="detail.php"><button type="button" class="btn btn-primary"><span class="msi" style="font-size:20px;">open_in_new</span></button></a></td>
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
        
    </script>
</body>
</html>