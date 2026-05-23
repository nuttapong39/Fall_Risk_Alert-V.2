<?php
// auth_guard.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ถ้าไม่มี user_id แสดงว่ายังไม่ได้ login → ส่งกลับไปหน้า login
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// สามารถใช้ตัวแปรเหล่านี้ในหน้าอื่นได้
$currentUserId       = $_SESSION['user_id'];
$currentUsername     = $_SESSION['username']  ?? '';
$currentUserFullname = $_SESSION['full_name'] ?? '';
$currentUserPosition = $_SESSION['position']  ?? '';
