<?php

    $servername = "192.168.1.249"; //ชื่อ Server ของเรา ในที่นี้ใช้ Localhost ** XAMPP
    $username = "root"; // ชื่อ Servername XAMPP คือ root
    $password = "comsci"; 
    $dbname = "hosxp"; // ชื่อฐานข้อมูลของเราใน XAMPP
  //  $db_connection = "mysql";
  //  $db_host= "ckhospital.net/HOSxLine/index.php";
   // $db_port = "5600";
    
    //สร้างการเชื่อมต่อ
    try{

        $dbcon = new PDO("mysql:host=$servername;dbname=$dbname",$username,$password);
        $dbcon->exec("set names utf8mb4");
        $dbcon->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        //echo "Success to Connect Database HOSOffice";

    } catch(PDOexception $e) {

            echo "Fail Connect to database: " . $e->getMessage();

    }   


?>


