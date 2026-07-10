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
<title>เข้าสู่ระบบ | MedAlert · รพ.เชียงกลาง</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* ── Material Symbols ─────────────────────────────────────── */
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

/* ── MedAlert Design Tokens ───────────────────────────────── */
:root {
  --primary:   #1A6FBB;   /* MedAlert blue    */
  --primary-2: #2a85d0;   /* lighter blue     */
  --alert:     #E63946;   /* alert red        */
  --dark:      #1C2B3A;   /* dark navy        */
  --text:      #0f172a;
  --muted:     #64748b;
  --border:    #e2e8f0;

  /* legacy aliases (used by Bootstrap overrides) */
  --blue:   var(--primary);
  --blue-2: var(--primary-2);
}

*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; padding: 0; }
body {
  font-family: "Kanit", system-ui, -apple-system, sans-serif;
  font-size: 16px;
  font-weight: 300;
  -webkit-font-smoothing: antialiased;
  background: #eef4fb;
  display: flex;
  align-items: stretch;
  min-height: 100vh;
}
h1, h2, h3, h4, h5, h6 { font-weight: 700; }

/* ── LEFT panel ───────────────────────────────────────────── */
.login-left {
  flex: 1;
  background: linear-gradient(150deg, var(--dark) 0%, var(--primary) 55%, var(--primary-2) 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 40px;
  position: relative;
  overflow: hidden;
}

/* decorative blobs */
.login-left::before {
  content: "";
  position: absolute; top: -80px; left: -80px;
  width: 340px; height: 340px; border-radius: 50%;
  background: rgba(255,255,255,.06);
  pointer-events: none;
}
.login-left::after {
  content: "";
  position: absolute; bottom: -70px; right: -70px;
  width: 260px; height: 260px; border-radius: 50%;
  background: rgba(255,255,255,.05);
  pointer-events: none;
}
/* extra decorative ring (top-right) */
.login-left .deco-ring {
  position: absolute; top: 30%; right: -40px;
  width: 160px; height: 160px; border-radius: 50%;
  border: 2px solid rgba(255,255,255,.08);
  pointer-events: none;
}

/* logo wrapper with glow ring */
.ll-logo-wrap {
  position: relative;
  width: 300px; height: 300px;
  display: grid; place-items: center;
  margin-bottom: 28px;
}
.ll-logo-wrap::before {
  content: "";
  position: absolute; inset: 0;
  border-radius: 50%;
  background: rgba(255,255,255,.08);
  border: 2px solid rgba(255,255,255,.2);
}
/* outer glow pulse ring */
.ll-logo-wrap::after {
  content: "";
  position: absolute; inset: -12px;
  border-radius: 50%;
  border: 1px solid rgba(255,255,255,.09);
  pointer-events: none;
}
.ll-logo-wrap img {
  width: 240px; height: 240px;
  object-fit: contain;
  border-radius: 28px;
  filter: drop-shadow(0 8px 32px rgba(0,0,0,.5));
  position: relative; z-index: 1;
}

.ll-title {
  font-size: 2.2rem; font-weight: 700; color: #fff;
  text-align: center; line-height: 1.2;
  letter-spacing: -.01em;
}
.ll-title em {
  font-style: normal;
  color: var(--alert);
}
.ll-sub {
  font-size: 1rem; color: rgba(255,255,255,.78);
  text-align: center; margin-top: 10px; max-width: 340px;
  line-height: 1.65;
}
.ll-divider {
  width: 160px; height: 3px;
  background: linear-gradient(90deg, transparent, var(--alert), transparent);
  border-radius: 2px;
  margin: 22px auto 20px;
}
.credit-divider {
  width: 120px; height: 2px;
  background: linear-gradient(90deg, transparent, var(--alert), transparent);
  border-radius: 2px;
  margin: 20px auto 16px;
}
.ll-badges {
  display: flex; gap: 8px; flex-wrap: wrap;
  justify-content: center; margin-top: 4px;
}
.ll-badge {
  display: flex; align-items: center; gap: 6px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.15);
  border-radius: 999px;
  padding: 5px 13px;
  font-size: .78rem; color: rgba(255,255,255,.92);
  backdrop-filter: blur(4px);
}
.ll-badge .msi { font-size: .95rem; }
.ll-badge.is-alert {
  background: rgba(230,57,70,.2);
  border-color: rgba(230,57,70,.35);
}

/* ── RIGHT form ───────────────────────────────────────────── */
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

/* logo box — white card with shadow */
.form-logo {
  width: 160px; height: 160px;
  border-radius: 28px;
  background: #fff;
  border: 2px solid rgba(26,111,187,.14);
  display: grid; place-items: center;
  margin: 0 auto 20px;
  padding: 12px;
  box-shadow: 0 12px 36px rgba(26,111,187,.2), 0 3px 8px rgba(0,0,0,.07);
  overflow: hidden;
}
.form-logo img {
  width: 100%; height: 100%;
  object-fit: contain;
}

/* brand name above form */
.form-brand {
  font-size: 1.6rem; font-weight: 700;
  color: var(--primary);
  letter-spacing: -.01em;
  line-height: 1;
}
.form-brand em {
  font-style: normal;
  color: var(--alert);
}
.form-title { font-size: 1.1rem; font-weight: 600; color: var(--text); margin-top: 6px; }
.form-sub   { font-size: .82rem; color: var(--muted); margin-top: 3px; }

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
  border-color: var(--primary-2);
  box-shadow: 0 0 0 3px rgba(26,111,187,.15);
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
.pw-toggle:hover { color: var(--primary); }

.btn-login {
  width: 100%; border: none;
  padding: .75rem 1rem;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--primary-2), var(--primary));
  color: #fff; font-weight: 700; font-size: 1rem;
  font-family: inherit;
  cursor: pointer;
  box-shadow: 0 6px 20px rgba(26,111,187,.38);
  transition: filter .15s, transform .1s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
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
.register-link a { color: var(--primary); font-weight: 600; text-decoration: none; }
.register-link a:hover { text-decoration: underline; }

.credit { text-align: center; font-size: .72rem; color: #94a3b8; margin-top: 24px; line-height: 1.6; }

/* ── Responsive ───────────────────────────────────────────── */
@media (max-width: 767.98px) {
  body { flex-direction: column; }
  .login-left { padding: 32px 24px; }
  .ll-title { font-size: 1.6rem; }
  .ll-badges { display: none; }
  .login-right {
    width: 100%; padding: 32px 24px;
    border-left: none; border-top: 1px solid var(--border);
  }
}
</style>
</head>
<body>

<!-- ═══════════════ LEFT panel ═══════════════ -->
<div class="login-left d-none d-md-flex flex-column align-items-center justify-content-center">
  <div class="deco-ring"></div>

  <div class="ll-logo-wrap">
    <img src="img/New_Logo.png" alt="MedAlert Logo">
  </div>

  <h1 class="ll-title">Med<em>Alert</em></h1>
  <p class="ll-sub">
    ระบบแจ้งเตือนทางการแพทย์<br>
    โรงพยาบาลเชียงกลาง จ.น่าน
  </p>

  <div class="ll-divider"></div>

  <div class="ll-badges">
    <div class="ll-badge is-alert"><span class="msi">falling</span> พลัดตก / หกล้ม</div>
    <div class="ll-badge"><span class="msi">coronavirus</span> ไข้เลือดออก</div>
    <div class="ll-badge"><span class="msi">biotech</span> เลปโต / สครับ</div>
    <div class="ll-badge"><span class="msi">health_and_safety</span> STI</div>
    <div class="ll-badge"><span class="msi">medication</span> Drug Monitor</div>
    <div class="ll-badge"><span class="msi">monitor_heart</span> ผู้ป่วยเสี่ยง</div>
  </div>
</div>

<!-- ═══════════════ RIGHT form ═══════════════ -->
<div class="login-right">
  <div class="form-card">
    <div class="form-top">
      <div class="form-logo">
        <img src="img/New_Logo.png" alt="MedAlert">
      </div>
      <div class="form-brand">Med<em>Alert</em></div>
      <div class="form-title">เข้าสู่ระบบ</div>
      <div class="form-sub">โรงพยาบาลเชียงกลาง · จ.น่าน</div>
    </div>

    <form method="post" id="loginForm" novalidate>

      <div class="mb-3">
        <label class="lbl" for="username">
          <span class="msi me-1" style="color:var(--muted)">person</span>
          ชื่อผู้ใช้ (Username)
        </label>
        <div class="input-group">
          <span class="input-group-text"><span class="msi">account_circle</span></span>
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
        <span class="msi">login</span>เข้าสู่ระบบ
      </button>

    </form>

    <div class="divider">หรือ</div>

    <div class="register-link">
      ยังไม่มีบัญชีผู้ใช้?
      <a href="register.php">สมัครใช้งานระบบ</a>
    </div>

    <div class="credit-divider"></div>
    <div class="credit">
      พัฒนาโดย นายณัฐพงษ์ นิลคง<br>
      นักวิชาการคอมพิวเตอร์ · รพ.เชียงกลาง
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
    pwInput.type       = show ? 'text' : 'password';
    pwIcon.textContent = show ? 'visibility_off' : 'visibility';
  });

  /* Disable button on submit */
  const form      = document.getElementById('loginForm');
  const submitBtn = document.getElementById('submitBtn');
  form.addEventListener('submit', () => {
    submitBtn.disabled  = true;
    submitBtn.innerHTML = '<span class="msi msi-spin">progress_activity</span>กำลังเข้าสู่ระบบ…';
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
        confirmButtonColor: '#1A6FBB'
      }).then(() => { document.getElementById('username').focus(); });
    }
  });
</script>
</body>
</html>
