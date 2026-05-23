<?php
// register.php
session_start();
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Bangkok');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $password2  = $_POST['password2'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $position   = trim($_POST['position_name'] ?? '');

    if ($username==='' || $password==='' || $password2==='' || $first_name==='' || $last_name==='') {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } elseif ($password !== $password2) {
        $error = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
    } else {
        // ตรวจซ้ำ username
        $stmt = $dbcon->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
        $stmt->execute([':u'=>$username]);
        if ($stmt->fetch()) {
            $error = 'มีชื่อผู้ใช้นี้ในระบบแล้ว กรุณาใช้ชื่ออื่น';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $dbcon->prepare("
              INSERT INTO users (username,password_hash,first_name,last_name,position_name)
              VALUES (:u,:p,:f,:l,:pos)
            ");
            $ins->execute([
              ':u'=>$username,
              ':p'=>$hash,
              ':f'=>$first_name,
              ':l'=>$last_name,
              ':pos'=>$position ?: null,
            ]);
            $success = 'สมัครใช้งานสำเร็จ สามารถเข้าสู่ระบบได้แล้ว';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สมัครใช้งาน | Fall Risk Alert</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:system-ui,-apple-system,Segoe UI,Roboto,"Kanit",sans-serif;
    background:#e0f5f2 url('assets/fall_login_bg.png') no-repeat center center fixed;
    background-size:cover;
  }
  .card{
    width:100%;
    max-width:480px;
    background:#ffffffee;
    border-radius:24px;
    padding:28px 32px 24px;
    box-shadow:0 18px 40px rgba(15,23,42,0.18);
  }
  h2{
    text-align:center;
    font-size:20px;
    color:#047857;
    margin-bottom:4px;
  }
  .subtitle{
    text-align:center;
    font-size:13px;
    color:#6b7280;
    margin-bottom:18px;
  }
  .field{margin-bottom:12px;}
  label{
    font-size:13px;
    color:#374151;
    display:block;
    margin-bottom:3px;
  }
  input[type=text],
  input[type=password]{
    width:100%;
    padding:9px 12px;
    border-radius:999px;
    border:1px solid #d1d5db;
    font-size:14px;
    outline:none;
  }
  input:focus{
    border-color:#22c55e;
    box-shadow:0 0 0 2px rgba(34,197,94,0.25);
  }
  .row{
    display:flex;
    gap:10px;
  }
  .row .field{flex:1;}
  .btn-primary{
    width:100%;
    border:none;
    margin-top:6px;
    padding:10px 14px;
    border-radius:999px;
    background:linear-gradient(135deg,#10b981,#059669);
    color:white;
    font-size:15px;
    font-weight:600;
    cursor:pointer;
  }
  .link-back{
    margin-top:10px;
    text-align:center;
    font-size:13px;
    color:#6b7280;
  }
  .link-back a{
    color:#059669;
    text-decoration:none;
    font-weight:500;
  }
  .error,.success{
    margin-bottom:10px;
    padding:8px 12px;
    border-radius:12px;
    font-size:13px;
  }
  .error{background:#fee2e2;color:#b91c1c;}
  .success{background:#dcfce7;color:#166534;}
</style>
</head>
<body>
<div class="card">
  <h2>สมัครใช้งานระบบ Fall Risk Alert</h2>
  <div class="subtitle">สำหรับเจ้าหน้าที่ที่ดูแลผู้ป่วยกระดูกหัก / พลัดตกหกล้ม</div>

  <?php if($error): ?>
    <div class="error"><?=htmlspecialchars($error)?></div>
  <?php elseif($success): ?>
    <div class="success"><?=htmlspecialchars($success)?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <div class="field">
      <label for="username">ชื่อผู้ใช้ (Username)</label>
      <input type="text" id="username" name="username"
             value="<?=htmlspecialchars($_POST['username'] ?? '')?>">
    </div>

    <div class="row">
      <div class="field">
        <label for="password">รหัสผ่าน</label>
        <input type="password" id="password" name="password">
      </div>
      <div class="field">
        <label for="password2">ยืนยันรหัสผ่าน</label>
        <input type="password" id="password2" name="password2">
      </div>
    </div>

    <div class="row">
      <div class="field">
        <label for="first_name">ชื่อ</label>
        <input type="text" id="first_name" name="first_name"
               value="<?=htmlspecialchars($_POST['first_name'] ?? '')?>">
      </div>
      <div class="field">
        <label for="last_name">นามสกุล</label>
        <input type="text" id="last_name" name="last_name"
               value="<?=htmlspecialchars($_POST['last_name'] ?? '')?>">
      </div>
    </div>

    <div class="field">
      <label for="position_name">ตำแหน่ง (เช่น พยาบาล, แพทย์, PT ฯลฯ)</label>
      <input type="text" id="position_name" name="position_name"
             value="<?=htmlspecialchars($_POST['position_name'] ?? '')?>">
    </div>

    <button class="btn-primary" type="submit">สมัครใช้งาน</button>
  </form>

  <div class="link-back">
    มีบัญชีอยู่แล้ว? <a href="login.php">กลับไปหน้าเข้าสู่ระบบ</a>
  </div>
</div>
</body>
</html>
