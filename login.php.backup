<?php
require_once __DIR__.'/config.php';
session_start();

$error = '';
$login_success = false;
$redirect_to = 'fracture_queue_ui.php'; // เปลี่ยนได้ตามต้องการ

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        // ดึงข้อมูล user
        $stmt = $dbcon->prepare("SELECT * FROM users WHERE username = :u AND is_active = 1");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // ====== สำคัญ: เก็บ session ======
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['first_name'].' '.$user['last_name'];
            $_SESSION['position']  = $user['position_name'];

            // แทนที่จะ header('Location: ...') ให้ยิง SweetAlert ก่อน แล้วค่อย redirect ด้วย JS
            $login_success = true;
        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เข้าสู่ระบบ | Fall Risk Alert</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:system-ui,-apple-system,Segoe UI,Roboto,"Kanit",sans-serif;
    background:#e0f5f2 url('img/fall_login.png') no-repeat center center fixed;
    background-size:cover;
  }
  .card{
    width:100%;
    max-width:420px;
    background:#ffffffee;
    border-radius:24px;
    padding:32px 32px 28px;
    box-shadow:0 18px 40px rgba(15,23,42,0.18);
    text-align:center;
  }
  .app-title{
    font-size:20px;
    font-weight:700;
    color:#047857;
    margin-bottom:4px;
  }
  .app-subtitle{
    font-size:14px;
    color:#6b7280;
    margin-bottom:22px;
  }
  .logo{
    margin-bottom:12px;
  }
  .logo span{
    font-size:26px;
    font-weight:800;
    color:#059669;
  }
  .field{
    text-align:left;
    margin-bottom:14px;
  }
  label{
    font-size:14px;
    color:#374151;
    display:block;
    margin-bottom:4px;
  }
  input[type=text],
  input[type=password]{
    width:100%;
    padding:10px 12px;
    border-radius:999px;
    border:1px solid #d1d5db;
    font-size:14px;
    outline:none;
    transition:border-color .2s, box-shadow .2s;
  }
  input:focus{
    border-color:#22c55e;
    box-shadow:0 0 0 2px rgba(34,197,94,0.25);
  }
  .btn-primary{
    width:100%;
    border:none;
    margin-top:10px;
    padding:10px 14px;
    border-radius:999px;
    background:linear-gradient(135deg,#10b981,#059669);
    color:white;
    font-size:15px;
    font-weight:600;
    cursor:pointer;
  }
  .btn-primary:hover{
    filter:brightness(1.03);
  }
  .meta{
    margin-top:14px;
    font-size:13px;
    color:#6b7280;
  }
  .meta1{
    margin-top:14px;
    font-size:11px;
    color:#6b7280;
  }
  .meta a{
    color:#059669;
    text-decoration:none;
    font-weight:500;
  }
  .error{
    margin-bottom:10px;
    padding:8px 12px;
    border-radius:999px;
    background:#fee2e2;
    color:#b91c1c;
    font-size:13px;
  }
  .meta2{
    margin-top:14px;
    font-size:13px;
    color:#6b7280;
  }
</style>
</head>
<body>

<div class="card">
  <div class="logo">
    <span>โรงพยาบาลเชียงกลาง</span>
  </div>
  <div class="app-title">ระบบแจ้งเตือนผู้ป่วยกระดูกหัก พลัดตก/หกล้ม อัตโนมัติ</div>
  <div class="app-title">Fall Risk Alert Web Portal</div>

  <!-- (ยังคงแสดง error แบบเดิมได้ แต่เราจะให้ SweetAlert แจ้งด้วย) -->
  <?php if($error): ?>
    <div class="error"><?=htmlspecialchars($error)?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off" id="loginForm">
    <div class="field">
      <label for="username">ชื่อผู้ใช้ (Username)</label>
      <input type="text" id="username" name="username"
             placeholder="กรอกชื่อผู้ใช้ของคุณ"
             value="<?=htmlspecialchars($_POST['username'] ?? '')?>">
    </div>
    <div class="field">
      <label for="password">รหัสผ่าน (Password)</label>
      <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน">
    </div>

    <button class="btn-primary" type="submit">เข้าสู่ระบบ</button>
  </form>

  <div class="meta">
    ยังไม่มีบัญชีผู้ใช้?
    <a href="register.php">สมัครใช้งานระบบ</a>
  </div>
  <div class="meta2">-----------------------------------------------------------------</div>
  <div class="meta1">พัฒนาโดย นายณัฐพงษ์ นิลคง นักวิชาการคอมพิวเตอร์ รพ.เชียงกลาง</div>
</div>

<script>
  // ===== SweetAlert: แจ้งผลหลัง submit (ทำงานหลัง server ประมวลผล) =====
  document.addEventListener('DOMContentLoaded', function () {
    const loginSuccess = <?= $login_success ? 'true' : 'false' ?>;
    const errorMsg = <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>;
    const redirectTo = <?= json_encode($redirect_to, JSON_UNESCAPED_UNICODE) ?>;

    if (loginSuccess) {
      Swal.fire({
        icon: 'success',
        title: 'เข้าสู่ระบบสำเร็จ',
        text: 'กำลังเข้าสู่หน้าระบบ...',
        timer: 1200,
        showConfirmButton: false
      }).then(() => {
        window.location.href = redirectTo;
      });
      return;
    }

    if (errorMsg) {
      Swal.fire({
        icon: 'error',
        title: 'เข้าสู่ระบบไม่สำเร็จ',
        text: errorMsg,
        confirmButtonText: 'ตกลง'
      });
    }
  });
</script>

</body>
</html>
