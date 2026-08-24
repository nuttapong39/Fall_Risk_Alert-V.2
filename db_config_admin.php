<?php
// db_config_admin.php
// หน้าตั้งค่าฐานข้อมูล — สอง datastore (ดู docs/adr/0001)
//   • HOSxP Source : MySQL (V3) หรือ PostgreSQL (XE4) — อ่านอย่างเดียว
//   • MedAlert_DB  : MySQL เสมอ — อ่าน-เขียน (users + Queue)
// First-run: ข้าม auth ถ้ายังไม่ได้ตั้งค่า MedAlert_DB

define('CONFIG_SKIP_DB',    true);   // ไม่ต่อ DB ขณะโหลด config
define('CONFIG_SETUP_PAGE', true);   // กัน redirect วนลูป
require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Bangkok');

$dir  = __DIR__ . DIRECTORY_SEPARATOR . 'secrets';
$file = $dir    . DIRECTORY_SEPARATOR . 'db_config.json';

// ─── First-run = ยังไม่ได้ตั้งค่า MedAlert_DB ───────────────────────────────
$isFirstRun = empty($medalertConfigured);

if ($isFirstRun) {
  if (session_status() === PHP_SESSION_NONE) session_start();
} else {
  require_once __DIR__ . '/auth_guard.php';
}

/* ─── helper: เขียน db_config.json (2 ก้อน) ───────────────────────────────── */
function da_write_config(string $file, string $dir, array $hosxp, array $medalert): bool {
  $payload = [
    'hosxp'    => $hosxp,
    'medalert' => $medalert,
    '_meta'    => ['updated_at' => date('Y-m-d H:i:s')],
  ];
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $ok = @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  if ($ok !== false) { @chmod($file, 0660); return true; }
  return false;
}

/* ─── helper: อ่าน connection จาก POST prefix (hx_ / ma_) ──────────────────── */
function da_conn_from_post(string $prefix, string $defDriver): array {
  $driver = $defDriver;
  if ($defDriver !== 'mysql') { // เฉพาะ HOSxP ที่เลือก driver ได้
    $driver = in_array($_POST[$prefix.'driver'] ?? '', ['mysql','pgsql'], true)
              ? $_POST[$prefix.'driver'] : 'mysql';
  }
  return [
    'driver' => $driver,
    'host'   => trim($_POST[$prefix.'host'] ?? ''),
    'port'   => (int)($_POST[$prefix.'port'] ?? ($driver === 'pgsql' ? 5432 : 3306)),
    'name'   => trim($_POST[$prefix.'name'] ?? ''),
    'user'   => trim($_POST[$prefix.'user'] ?? ''),
    'pass'   => (string)($_POST[$prefix.'pass'] ?? ''),
  ];
}

/* ════════════════════════════════════════════════════════════════════════════
 *  AJAX: ทดสอบการเชื่อมต่อ (ใช้ได้ทั้งสองการ์ด)
 * ════════════════════════════════════════════════════════════════════════════ */
if (($_POST['action'] ?? '') === 'test_connection') {
  header('Content-Type: application/json; charset=utf-8');
  $driver = in_array($_POST['driver'] ?? '', ['mysql','pgsql'], true) ? $_POST['driver'] : 'mysql';
  $host = trim($_POST['host'] ?? ''); $port = (int)($_POST['port'] ?? 0);
  $name = trim($_POST['name'] ?? ''); $user = trim($_POST['user'] ?? '');
  $pass = (string)($_POST['pass'] ?? '');
  if (!$host || !$name || !$user) { echo json_encode(['ok'=>false,'msg'=>'กรุณากรอก Host, Database และ Username ให้ครบ']); exit; }
  if (!$port) $port = ($driver === 'pgsql' ? 5432 : 3306);
  try {
    $dsn = $driver === 'pgsql'
      ? "pgsql:host={$host};port={$port};dbname={$name}"
      : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo json_encode(['ok'=>true,'msg'=>"เชื่อมต่อสำเร็จ ✓  Server version: {$ver}"]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'msg'=>'เชื่อมต่อไม่ได้: '.$e->getMessage()]);
  }
  exit;
}

/* ════════════════════════════════════════════════════════════════════════════
 *  AJAX: Setup MedAlert_DB — CREATE DATABASE → deploy 9 schema → seed admin
 *  แล้วบันทึก db_config.json ทั้งสองก้อน
 * ════════════════════════════════════════════════════════════════════════════ */
if (($_POST['action'] ?? '') === 'setup_medalert') {
  header('Content-Type: application/json; charset=utf-8');

  $hosxp    = da_conn_from_post('hx_', 'pgsql');  // HOSxP เลือก driver ได้
  $medalert = da_conn_from_post('ma_', 'mysql');  // MedAlert ล็อก mysql
  $adminUser = trim($_POST['admin_user'] ?? '');
  $adminPass = (string)($_POST['admin_pass'] ?? '');

  if (!$medalert['host'] || !$medalert['name'] || !$medalert['user']) {
    echo json_encode(['ok'=>false,'msg'=>'กรอก MedAlert_DB: Host, Database, Username ให้ครบ']); exit;
  }
  if ($isFirstRun && ($adminUser === '' || strlen($adminPass) < 4)) {
    echo json_encode(['ok'=>false,'msg'=>'กรอก Username admin และรหัสผ่าน (≥4 ตัว) เพื่อสร้างบัญชีแรก']); exit;
  }

  $steps = [];
  try {
    // 1) เชื่อมต่อ server (ไม่ระบุ dbname) เพื่อ CREATE DATABASE
    $srvDsn = "mysql:host={$medalert['host']};port={$medalert['port']}";
    try {
      $srv = new PDO($srvDsn, $medalert['user'], $medalert['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
      $dbq = str_replace('`','', $medalert['name']);
      $srv->exec("CREATE DATABASE IF NOT EXISTS `{$dbq}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
      $steps[] = "✓ CREATE DATABASE `{$dbq}`";
    } catch (Throwable $e) {
      // ไม่มีสิทธิ์สร้าง DB — fallback: ให้ผู้ใช้สร้างเองแล้วลองใหม่ (ถ้า DB มีอยู่จะผ่านขั้นถัดไป)
      $steps[] = "⚠ ข้าม CREATE DATABASE (".$e->getMessage()."). จะลอง deploy ลง DB ที่มีอยู่";
    }

    // 2) เชื่อมต่อเข้า MedAlert_DB จริง
    $dsn = "mysql:host={$medalert['host']};port={$medalert['port']};dbname={$medalert['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $medalert['user'], $medalert['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 3) deploy schema ทุกไฟล์ที่เจอ (idempotent) — logic กลางอยู่ที่ db_migrate.php
    //    (ใช้ glob *_queue.sql แทน list ตายตัว → ตารางใหม่ในอนาคตถูก deploy อัตโนมัติ)
    require_once __DIR__ . '/db_migrate.php';
    foreach (deploy_missing_schema($pdo, __DIR__) as $line) { $steps[] = $line; }

    // 4) seed admin (เฉพาะ first-run และถ้ายังไม่มี user ชื่อนี้)
    if ($isFirstRun && $adminUser !== '') {
      $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
      $chk->execute([$adminUser]);
      if ($chk->fetch()) {
        $steps[] = "• ข้าม seed admin (มี user '{$adminUser}' อยู่แล้ว)";
      } else {
        $ins = $pdo->prepare("INSERT INTO users (username,password_hash,first_name,last_name,position_name,is_active)
                              VALUES (?,?,?,?,?,1)");
        $ins->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 'Admin', 'System', 'ผู้ดูแลระบบ']);
        $steps[] = "✓ สร้างบัญชี admin '{$adminUser}'";
      }
    }

    // 5) บันทึก config ทั้งสองก้อน
    if (!da_write_config($file, $dir, $hosxp, $medalert)) {
      $steps[] = "✗ บันทึก db_config.json ไม่สำเร็จ (ตรวจสิทธิ์ secrets/)";
      echo json_encode(['ok'=>false,'msg'=>implode("\n", $steps)]); exit;
    }
    $steps[] = "✓ บันทึก secrets/db_config.json";

    echo json_encode(['ok'=>true,'msg'=>implode("\n", $steps),'done'=>true]);
  } catch (Throwable $e) {
    $steps[] = "✗ ".$e->getMessage();
    echo json_encode(['ok'=>false,'msg'=>implode("\n", $steps)]);
  }
  exit;
}

/* ════════════════════════════════════════════════════════════════════════════
 *  POST: บันทึกค่า (ไม่ deploy) — ทั้งสองก้อน
 * ════════════════════════════════════════════════════════════════════════════ */
$flashMsg = $flashErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
  $tokenOk = $isFirstRun
    || (!empty($_POST['token']) && $_POST['token'] === (defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : ''));
  if (!$tokenOk) {
    $flashErr = 'Invalid token — กรุณา refresh หน้าแล้วลองใหม่';
  } else {
    $hosxp    = da_conn_from_post('hx_', 'pgsql');
    $medalert = da_conn_from_post('ma_', 'mysql');
    if (!$medalert['host'] || !$medalert['name'] || !$medalert['user']) {
      $flashErr = 'กรอก MedAlert_DB: Host, Database, Username ให้ครบ';
    } elseif (da_write_config($file, $dir, $hosxp, $medalert)) {
      $flashMsg = 'บันทึกค่าฐานข้อมูลทั้งสองก้อนสำเร็จ';
      $DB_HOSXP = $hosxp; $DB_MEDALERT = $medalert;
      $isFirstRun = false;
    } else {
      $flashErr = 'บันทึกไม่สำเร็จ — กรุณาตรวจสิทธิ์โฟลเดอร์ secrets/';
    }
  }
}

// ค่าปัจจุบันสำหรับ pre-fill (จาก config.php ที่ normalize แล้ว)
$hx = $DB_HOSXP;
$ma = $DB_MEDALERT;

// ─── Page variables ──────────────────────────────────────────────────────────
$PAGE_TITLE = 'ตั้งค่าฐานข้อมูล';
$PAGE_KEY   = 'db_config';
$EXTRA_HEAD = <<<'HTML'
<style>
.driver-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.driver-card { position:relative; cursor:pointer; border-radius:12px; border:2px solid var(--card-border);
  background:var(--card-bg); padding:16px 14px 14px; transition:border-color .15s, box-shadow .15s; user-select:none; }
.driver-card:hover { border-color:var(--blue); }
.driver-card.selected { border-color:var(--blue); box-shadow:0 0 0 3px var(--blue-100); background:var(--blue-50); }
.driver-card input[type=radio] { position:absolute; opacity:0; width:0; height:0; }
.driver-logo { width:44px; height:44px; border-radius:10px; display:grid; place-items:center; margin-bottom:10px; font-size:1.5rem; }
.driver-logo.mysql-logo { background:linear-gradient(135deg,#00758f,#005d73); color:#fff; }
.driver-logo.pgsql-logo { background:linear-gradient(135deg,#336791,#1a4566); color:#fff; }
.driver-name { font-weight:700; font-size:.95rem; color:var(--text); margin-bottom:2px; }
.driver-desc { font-size:.76rem; color:var(--muted); }
.driver-check { position:absolute; top:10px; right:10px; width:22px; height:22px; border-radius:50%;
  border:2px solid var(--border); background:var(--card-bg); display:grid; place-items:center; transition:all .15s; }
.driver-card.selected .driver-check { background:var(--blue); border-color:var(--blue); color:#fff; }
.setup-banner { background:linear-gradient(135deg,#1d4ed8 0%,#0891b2 100%); border-radius:14px; padding:20px 24px;
  color:#fff; display:flex; align-items:center; gap:16px; margin-bottom:24px; }
.setup-banner-icon { width:52px; height:52px; border-radius:12px; background:rgba(255,255,255,.18);
  display:grid; place-items:center; flex-shrink:0; font-size:1.6rem; }
.setup-banner h2 { font-size:1.05rem; font-weight:700; margin:0 0 2px; }
.setup-banner p { font-size:.83rem; margin:0; opacity:.88; }
.role-badge { display:inline-flex; align-items:center; gap:5px; border-radius:999px; font-size:.72rem;
  font-weight:600; padding:3px 10px; }
.role-read  { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
.role-write { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.role-lock  { background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; }
.section-head { font-size:.72rem; font-weight:600; letter-spacing:.08em; text-transform:uppercase;
  color:var(--section-lbl); margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid var(--border); }
.test-result { display:none; border-radius:8px; padding:8px 12px; font-size:.82rem; font-weight:500; margin-top:10px; white-space:pre-wrap; }
.test-result.ok { background:#dcfce7; color:#166534; } .test-result.err { background:#fee2e2; color:#991b1b; }
.pw-wrap { position:relative; } .pw-wrap input { padding-right:40px; }
.pw-wrap .pw-toggle { position:absolute; right:10px; top:50%; transform:translateY(-50%); border:none;
  background:transparent; cursor:pointer; color:var(--muted); padding:0; line-height:1; }
.pw-wrap .pw-toggle:hover { color:var(--blue); }
</style>
HTML;

require_once __DIR__ . '/partials/header.php';
?>

<?php if ($isFirstRun): ?>
<div class="setup-banner">
  <div class="setup-banner-icon"><span class="msi">rocket_launch</span></div>
  <div>
    <h2>ตั้งค่าเริ่มต้น MedAlert</h2>
    <p>กรอกการเชื่อมต่อ <strong>HOSxP</strong> (อ่านข้อมูลผู้ป่วย) และ <strong>MedAlert_DB</strong> (ฐานข้อมูลระบบ) แล้วกด “Setup MedAlert_DB” เพื่อสร้างตารางและบัญชีผู้ดูแลคนแรก</p>
  </div>
</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1><span class="msi me-2" style="color:var(--blue)">storage</span>ตั้งค่าฐานข้อมูล</h1>
    <div class="mt-1 d-flex align-items-center gap-2 flex-wrap" style="font-size:.82rem; color:var(--muted)">
      <span class="msi" style="font-size:1rem">schedule</span> อัปเดตล่าสุด:
      <?php
        if (is_readable($file)) { $j = json_decode(@file_get_contents($file), true); echo htmlspecialchars($j['_meta']['updated_at'] ?? '—'); }
        else { echo '(ยังไม่มีการตั้งค่า)'; }
      ?>
    </div>
  </div>
</div>

<?php if ($flashMsg): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-3" style="border-radius:10px; font-size:.9rem;">
  <span class="msi">check_circle</span> <?= htmlspecialchars($flashMsg) ?>
</div>
<?php endif; ?>
<?php if ($flashErr): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; font-size:.9rem;">
  <span class="msi">error</span> <?= htmlspecialchars($flashErr) ?>
</div>
<?php endif; ?>

<form method="post" id="dbForm" autocomplete="off">
  <?php if (!$isFirstRun): ?>
  <input type="hidden" name="token" value="<?= htmlspecialchars(defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : '') ?>">
  <?php endif; ?>

  <div class="row g-3">

    <!-- ══════════ HOSxP Source ══════════ -->
    <div class="col-lg-6">
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:var(--blue)">cloud_download</span> HOSxP Source
          <span class="role-badge role-read ms-auto"><span class="msi" style="font-size:.9rem">visibility</span> อ่านอย่างเดียว</span>
        </div>
        <div class="card-body">
          <div class="driver-grid mb-3" id="hxDriverGrid">
            <label class="driver-card<?= $hx['driver']==='mysql'?' selected':'' ?>" id="hx-card-mysql">
              <input type="radio" name="hx_driver" value="mysql" <?= $hx['driver']==='mysql'?'checked':'' ?> onchange="onHxDriver('mysql')">
              <div class="driver-logo mysql-logo"><span class="msi">database</span></div>
              <div class="driver-name">MySQL / MariaDB</div>
              <div class="driver-desc">HOSxP V3, HOSxP PCU</div>
              <div class="driver-check"><?php if($hx['driver']==='mysql'):?><span class="msi" style="font-size:.75rem">check</span><?php endif;?></div>
            </label>
            <label class="driver-card<?= $hx['driver']==='pgsql'?' selected':'' ?>" id="hx-card-pgsql">
              <input type="radio" name="hx_driver" value="pgsql" <?= $hx['driver']==='pgsql'?'checked':'' ?> onchange="onHxDriver('pgsql')">
              <div class="driver-logo pgsql-logo"><span class="msi">hub</span></div>
              <div class="driver-name">PostgreSQL</div>
              <div class="driver-desc">HOSxPXE4</div>
              <div class="driver-check"><?php if($hx['driver']==='pgsql'):?><span class="msi" style="font-size:.75rem">check</span><?php endif;?></div>
            </label>
          </div>

          <div class="row g-2">
            <div class="col-8"><label class="form-label fw-semibold">Host / IP</label>
              <input type="text" id="hx_host" name="hx_host" class="form-control" value="<?= htmlspecialchars($hx['host']) ?>" placeholder="เช่น 192.168.1.115" required></div>
            <div class="col-4"><label class="form-label fw-semibold">Port</label>
              <input type="number" id="hx_port" name="hx_port" class="form-control" value="<?= htmlspecialchars($hx['port']) ?>" min="1" max="65535" required></div>
          </div>
          <div class="mt-2"><label class="form-label fw-semibold">Database</label>
            <input type="text" id="hx_name" name="hx_name" class="form-control" value="<?= htmlspecialchars($hx['name']) ?>" placeholder="เช่น chk_hostrain" required></div>
          <div class="mt-2"><label class="form-label fw-semibold">Username</label>
            <input type="text" id="hx_user" name="hx_user" class="form-control" value="<?= htmlspecialchars($hx['user']) ?>" autocomplete="username" required></div>
          <div class="mt-2"><label class="form-label fw-semibold">Password</label>
            <div class="pw-wrap">
              <input type="password" id="hx_pass" name="hx_pass" class="form-control" value="<?= htmlspecialchars($hx['pass']) ?>" autocomplete="off">
              <button type="button" class="pw-toggle" onclick="togglePw('hx_pass',this)"><span class="msi">visibility</span></button>
            </div></div>
          <button type="button" class="btn btn-outline-secondary btn-sm mt-3" onclick="testConn('hx')">
            <span class="msi me-1" id="hxTestIcon">network_check</span>ทดสอบการเชื่อมต่อ
          </button>
          <div class="test-result" id="hxTestResult"></div>
        </div>
      </div>
    </div>

    <!-- ══════════ MedAlert_DB ══════════ -->
    <div class="col-lg-6">
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:#059669">save</span> MedAlert_DB
          <span class="role-badge role-write"><span class="msi" style="font-size:.9rem">edit</span> อ่าน-เขียน</span>
          <span class="role-badge role-lock ms-auto"><span class="msi" style="font-size:.9rem">lock</span> MySQL</span>
        </div>
        <div class="card-body">
          <p style="font-size:.8rem; color:var(--muted); margin-bottom:12px">
            ฐานข้อมูลของระบบ (users + Queue ทั้งหมด) — ต้องเป็น MySQL/MariaDB เท่านั้น (XAMPP, MAMP, MariaDB ก็ได้)
          </p>
          <div class="row g-2">
            <div class="col-8"><label class="form-label fw-semibold">Host / IP</label>
              <input type="text" id="ma_host" name="ma_host" class="form-control" value="<?= htmlspecialchars($ma['host']) ?>" placeholder="เช่น 127.0.0.1" required></div>
            <div class="col-4"><label class="form-label fw-semibold">Port</label>
              <input type="number" id="ma_port" name="ma_port" class="form-control" value="<?= htmlspecialchars($ma['port']) ?>" min="1" max="65535" required></div>
          </div>
          <div class="mt-2"><label class="form-label fw-semibold">Database</label>
            <input type="text" id="ma_name" name="ma_name" class="form-control" value="<?= htmlspecialchars($ma['name']) ?>" placeholder="เช่น MedAlert_DB" required></div>
          <div class="mt-2"><label class="form-label fw-semibold">Username</label>
            <input type="text" id="ma_user" name="ma_user" class="form-control" value="<?= htmlspecialchars($ma['user']) ?>" autocomplete="username" required></div>
          <div class="mt-2"><label class="form-label fw-semibold">Password</label>
            <div class="pw-wrap">
              <input type="password" id="ma_pass" name="ma_pass" class="form-control" value="<?= htmlspecialchars($ma['pass']) ?>" autocomplete="off">
              <button type="button" class="pw-toggle" onclick="togglePw('ma_pass',this)"><span class="msi">visibility</span></button>
            </div></div>
          <button type="button" class="btn btn-outline-secondary btn-sm mt-3" onclick="testConn('ma')">
            <span class="msi me-1" id="maTestIcon">network_check</span>ทดสอบการเชื่อมต่อ
          </button>
          <div class="test-result" id="maTestResult"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════ Setup / Save ══════════ -->
  <div class="card mb-3">
    <div class="card-header d-flex align-items-center gap-2">
      <span class="msi" style="color:var(--blue)">build</span> ติดตั้ง / บันทึก
    </div>
    <div class="card-body">
      <?php if ($isFirstRun): ?>
      <div class="row g-2 mb-3">
        <div class="col-md-4"><label class="form-label fw-semibold">Admin Username</label>
          <input type="text" id="admin_user" name="admin_user" class="form-control" placeholder="admin" autocomplete="off"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Admin Password</label>
          <div class="pw-wrap">
            <input type="password" id="admin_pass" name="admin_pass" class="form-control" placeholder="≥4 ตัวอักษร" autocomplete="new-password">
            <button type="button" class="pw-toggle" onclick="togglePw('admin_pass',this)"><span class="msi">visibility</span></button>
          </div></div>
      </div>
      <?php endif; ?>

      <div class="d-flex gap-2 flex-wrap align-items-center">
        <button type="button" class="btn btn-primary px-4" onclick="doSetup()">
          <span class="msi me-1" id="setupIcon">build</span>Setup MedAlert_DB
        </button>
        <span style="font-size:.78rem; color:var(--muted)">สร้าง database + ตาราง 9 ตัว<?= $isFirstRun?' + บัญชี admin':'' ?> แล้วบันทึกค่า</span>
        <button type="submit" class="btn btn-outline-secondary ms-auto">
          <span class="msi me-1">save</span>บันทึกค่าอย่างเดียว
        </button>
        <?php if (!$isFirstRun): ?>
        <a href="index.php" class="btn btn-outline-secondary"><span class="msi me-1">home</span>กลับหน้าหลัก</a>
        <?php endif; ?>
      </div>
      <div class="test-result" id="setupResult"></div>
    </div>
  </div>
</form>

<?php
$EXTRA_FOOTER = <<<'JS'
<script>
function onHxDriver(driver){
  ['mysql','pgsql'].forEach(d=>{
    const card=document.getElementById('hx-card-'+d), chk=card.querySelector('.driver-check');
    if(d===driver){card.classList.add('selected');chk.innerHTML='<span class="msi" style="font-size:.75rem">check</span>';}
    else{card.classList.remove('selected');chk.innerHTML='';}
  });
  const p=document.getElementById('hx_port');
  if(driver==='pgsql'&&p.value==='3306')p.value='5432';
  if(driver==='mysql'&&p.value==='5432')p.value='3306';
}
function togglePw(id,btn){const i=document.getElementById(id);const s=i.type==='password';i.type=s?'text':'password';btn.querySelector('.msi').textContent=s?'visibility_off':'visibility';}

function testConn(which){
  const drv = which==='hx' ? (document.querySelector('input[name="hx_driver"]:checked')?.value||'mysql') : 'mysql';
  const icon=document.getElementById(which+'TestIcon'), res=document.getElementById(which+'TestResult');
  icon.textContent='sync'; icon.classList.add('msi-spin'); res.style.display='none';
  const fd=new FormData();
  fd.append('action','test_connection'); fd.append('driver',drv);
  fd.append('host',document.getElementById(which+'_host').value);
  fd.append('port',document.getElementById(which+'_port').value);
  fd.append('name',document.getElementById(which+'_name').value);
  fd.append('user',document.getElementById(which+'_user').value);
  fd.append('pass',document.getElementById(which+'_pass').value);
  fetch('db_config_admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    icon.textContent='network_check'; icon.classList.remove('msi-spin');
    res.style.display='block'; res.className='test-result '+(d.ok?'ok':'err');
    res.textContent=d.msg;
  }).catch(e=>{icon.textContent='network_check';icon.classList.remove('msi-spin');res.style.display='block';res.className='test-result err';res.textContent='ผิดพลาด: '+e;});
}

function doSetup(){
  const icon=document.getElementById('setupIcon'), res=document.getElementById('setupResult');
  icon.textContent='sync'; icon.classList.add('msi-spin'); res.style.display='none';
  const fd=new FormData(document.getElementById('dbForm'));
  fd.append('action','setup_medalert');
  fd.set('hx_driver', document.querySelector('input[name="hx_driver"]:checked')?.value||'mysql');
  fetch('db_config_admin.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    icon.textContent='build'; icon.classList.remove('msi-spin');
    res.style.display='block'; res.className='test-result '+(d.ok?'ok':'err'); res.textContent=d.msg;
    if(d.ok && d.done){ setTimeout(()=>{ window.location.href='index.php'; }, 1800); }
  }).catch(e=>{icon.textContent='build';icon.classList.remove('msi-spin');res.style.display='block';res.className='test-result err';res.textContent='ผิดพลาด: '+e;});
}
</script>
JS;
require_once __DIR__ . '/partials/footer.php';
