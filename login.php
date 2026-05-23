<?php
require_once __DIR__.'/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$error         = '';
$login_success = false;
$redirect_to   = 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $stmt = $dbcon->prepare("SELECT * FROM users WHERE username = :u AND is_active = 1");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
            $_SESSION['position']  = $user['position_name'] ?? '';
            $_SESSION['user']      = [
                'id'        => $user['id'],
                'username'  => $user['username'],
                'full_name' => $_SESSION['full_name'],
                'position'  => $_SESSION['position'],
            ];
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เข้าสู่ระบบ | Fall Risk Alert · รพ.เชียงกลาง</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
.msi {
  font-family: 'Material Symbols Outlined';
  font-weight: normal; font-style: normal;
  font-size: 1.15em; line-height: 1;
  letter-spacing: normal; text-transform: none;
  display: inline-block; white-space: nowrap; direction: ltr;
  -webkit-font-smoothing: antialiased;
  vertical-align: -0.2em;
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
  user-select: none; pointer-events: none;
}
.msi-o { font-variation-settings: 'FILL' 0, 'wght' 300, 'GRAD' 0, 'opsz' 20; }
@keyframes msi-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.msi-spin { animation: msi-spin .8s linear infinite; display: inline-block; }
:root {
  --blue: #1d4ed8;
  --blue-2: #2563eb;
  --green: #059669;
  --green-2: #10b981;
  --text: #0f172a;
  --muted: #64748b;
  --border: #e2e8f0;
}
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; padding: 0; }
body {
  font-family: "Kanit", system-ui, -apple-system, sans-serif;
  font-size: 16px;
  font-weight: 300;
  -webkit-font-smoothing: antialiased;
  background: #f0f9ff;
  display: flex;
  align-items: stretch;
  min-height: 100vh;
}
h1, h2, h3, h4, h5, h6 { font-weight: 700; }

/* ---- Split layout ---- */
.login-left {
  flex: 1;
  background: linear-gradient(150deg, #0f172a 0%, #1e3a8a 40%, #0891b2 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 40px;
  position: relative;
  overflow: hidden;
}
.login-left::before {
  content: "";
  position: absolute; top: -80px; left: -80px;
  width: 320px; height: 320px; border-radius: 50%;
  background: rgba(255,255,255,.06);
  pointer-events: none;
}
.login-left::after {
  content: "";
  position: absolute; bottom: -60px; right: -60px;
  width: 240px; height: 240px; border-radius: 50%;
  background: rgba(255,255,255,.05);
  pointer-events: none;
}
.ll-logo {
  width: 90px; height: 90px;
  border-radius: 24px;
  background: rgba(255,255,255,.15);
  border: 2px solid rgba(255,255,255,.25);
  display: grid; place-items: center;
  font-size: 2.2rem; color: #fff;
  margin-bottom: 28px;
}
.ll-title {
  font-size: 1.9rem; font-weight: 700; color: #fff;
  text-align: center; line-height: 1.3;
}
.ll-sub {
  font-size: 1rem; color: rgba(255,255,255,.75);
  text-align: center; margin-top: 10px; max-width: 340px;
}
.ll-badges {
  display: flex; gap: 10px; flex-wrap: wrap;
  justify-content: center; margin-top: 32px;
}
.ll-badge {
  display: flex; align-items: center; gap: 8px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.15);
  border-radius: 999px;
  padding: 6px 14px;
  font-size: .8rem; color: rgba(255,255,255,.9);
}

/* ---- Right form ---- */
.login-right {
  width: 480px;
  max-width: 100%;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 40px;
  border-left: 1px solid var(--border);
}

.form-card { width: 100%; max-width: 380px; }

.form-top { text-align: center; margin-bottom: 32px; }
.form-logo {
  width: 56px; height: 56px;
  border-radius: 14px;
  background: linear-gradient(135deg, #1d4ed8, #059669);
  display: grid; place-items: center;
  color: #fff; font-size: 1.4rem;
  margin: 0 auto 16px;
  box-shadow: 0 8px 20px rgba(29,78,216,.25);
}
.form-title { font-size: 1.35rem; font-weight: 700; color: var(--text); }
.form-sub   { font-size: .85rem; color: var(--muted); margin-top: 4px; }

label.lbl { font-weight: 600; font-size: .85rem; color: #334155; margin-bottom: 6px; display: block; }

.input-group-text {
  background: #f8fafc;
  border-color: var(--border);
  color: var(--muted);
}
.form-control {
  border-color: var(--border);
  padding: .6rem .9rem;
  font-family: inherit;
  font-size: .95rem;
}
.form-control:focus {
  border-color: var(--blue-2);
  box-shadow: 0 0 0 3px rgba(37,99,235,.15);
}

.pw-toggle {
  background: #f8fafc;
  border: 1px solid var(--border);
  border-left: 0;
  border-radius: 0 .375rem .375rem 0;
  color: var(--muted);
  cursor: pointer; width: 44px;
  transition: color .15s;
}
.pw-toggle:hover { color: var(--blue); }

.btn-login {
  width: 100%; border: none;
  padding: .7rem 1rem;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--blue-2), var(--blue));
  color: #fff; font-weight: 700; font-size: 1rem;
  font-family: inherit;
  cursor: pointer;
  box-shadow: 0 6px 18px rgba(29,78,216,.3);
  transition: filter .15s, transform .1s;
}
.btn-login:hover  { filter: brightness(1.07); }
.btn-login:active { transform: translateY(1px); }
.btn-login:disabled { opacity: .7; cursor: wait; }

.divider {
  display: flex; align-items: center; gap: .75rem;
  color: #cbd5e1; margin: 20px 0;
  font-size: .75rem;
}
.divider::before, .divider::after { content: ""; flex: 1; height: 1px; background: var(--border); }

.register-link { text-align: center; font-size: .88rem; color: var(--muted); }
.register-link a { color: var(--blue); font-weight: 600; text-decoration: none; }
.register-link a:hover { text-decoration: underline; }

.credit { text-align: center; font-size: .72rem; color: #94a3b8; margin-top: 24px; }

/* Responsive: stack on small screens */
@media (max-width: 767.98px) {
  body { flex-direction: column; }
  .login-left { padding: 32px 24px; }
  .ll-title { font-size: 1.4rem; }
  .ll-badges { display: none; }
  .login-right { width: 100%; padding: 32px 24px; border-left: none; border-top: 1px solid var(--border); }
}
</style>
</head>
<body>

<!-- ===== LEFT panel ===== -->
<div class="login-left d-none d-md-flex">
  <div class="ll-logo"><span class="msi">monitor_heart</span></div>
  <h1 class="ll-title">Fall Risk Alert<br>Web Portal</h1>
  <p class="ll-sub">ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยง<br>โรงพยาบาลเชียงกลาง จ.น่าน</p>
  <div class="ll-badges">
    <div class="ll-badge"><span class="msi">falling</span> พลัดตก / หกล้ม</div>
    <div class="ll-badge"><span class="msi">car_crash</span> คนไข้ พ.ร.บ.</div>
    <div class="ll-badge"><span class="msi">coronavirus</span> Covid-19</div>
    <div class="ll-badge"><span class="msi">stethoscope</span> จิตเวช</div>
    <div class="ll-badge"><span class="msi">medication</span> เภสัชกรรม</div>
  </div>
</div>

<!-- ===== RIGHT form ===== -->
<div class="login-right">
  <div class="form-card">
    <div class="form-top">
      <div class="form-logo"><span class="msi">security</span></div>
      <div class="form-title">เข้าสู่ระบบ</div>
      <div class="form-sub">Fall Risk Alert · โรงพยาบาลเชียงกลาง</div>
    </div>

    <form method="post" id="loginForm" novalidate>

      <div class="mb-3">
        <label class="lbl" for="username">
          <span class="msi me-1" style="color:var(--muted)">person</span>
          ชื่อผู้ใช้ (Username)
        </label>
        <div class="input-group">
          <span class="input-group-text"><span class="msi">alternate_email</span></span>
          <input type="text" class="form-control" id="username" name="username"
                 placeholder="กรอกชื่อผู้ใช้"
                 autocomplete="username" required autofocus
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
      </div>

      <div class="mb-4">
        <label class="lbl" for="password">
          <span class="msi me-1" style="color:var(--muted)">lock</span>
          รหัสผ่าน (Password)
        </label>
        <div class="input-group">
          <span class="input-group-text"><span class="msi">key</span></span>
          <input type="password" class="form-control" id="password" name="password"
                 placeholder="กรอกรหัสผ่าน"
                 autocomplete="current-password" required>
          <button type="button" class="pw-toggle" id="pwToggle" aria-label="แสดง/ซ่อนรหัสผ่าน">
            <span class="msi" id="pwIcon">visibility</span>
          </button>
        </div>
      </div>

      <button class="btn-login" type="submit" id="submitBtn">
        <span class="msi me-2">login</span>เข้าสู่ระบบ
      </button>

    </form>

    <div class="divider">หรือ</div>

    <div class="register-link">
      ยังไม่มีบัญชีผู้ใช้?
      <a href="register.php">สมัครใช้งานระบบ</a>
    </div>

    <div class="credit">
      พัฒนาโดย นายณัฐพงษ์ นิลคง · นักวิชาการคอมพิวเตอร์ · รพ.เชียงกลาง
    </div>
  </div>
</div>

<script>
  /* Show / hide password */
  const pwInput  = document.getElementById('password');
  const pwToggle = document.getElementById('pwToggle');
  const pwIcon   = document.getElementById('pwIcon');
  pwToggle.addEventListener('click', () => {
    const show = pwInput.type === 'password';
    pwInput.type  = show ? 'text' : 'password';
    pwIcon.textContent = show ? 'visibility_off' : 'visibility';
  });

  /* Disable button on submit */
  const form      = document.getElementById('loginForm');
  const submitBtn = document.getElementById('submitBtn');
  form.addEventListener('submit', () => {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="msi msi-spin me-2">progress_activity</span>กำลังเข้าสู่ระบบ…';
  });

  /* Server feedback */
  document.addEventListener('DOMContentLoaded', function () {
    const ok  = <?= $login_success ? 'true' : 'false' ?>;
    const err = <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>;
    const to  = <?= json_encode($redirect_to, JSON_UNESCAPED_UNICODE) ?>;

    if (ok) {
      Swal.fire({
        icon: 'success',
        title: 'เข้าสู่ระบบสำเร็จ',
        text: 'กำลังเข้าสู่หน้าระบบ…',
        timer: 900, showConfirmButton: false, timerProgressBar: true
      }).then(() => { window.location.href = to; });
      return;
    }
    if (err) {
      Swal.fire({
        icon: 'error',
        title: 'เข้าสู่ระบบไม่สำเร็จ',
        text: err,
        confirmButtonText: 'ตกลง',
        confirmButtonColor: '#1d4ed8'
      }).then(() => { document.getElementById('username').focus(); });
    }
  });
</script>
</body>
</html>
