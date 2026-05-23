<?php
session_start();

// ล้างค่า session ทั้งหมด
$_SESSION = [];
session_unset();
session_destroy();

// กลับไปหน้า login
header('Location: login.php');
exit;
