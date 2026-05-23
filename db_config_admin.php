<?php
// db_config_admin.php
// หน้าตั้งค่าฐานข้อมูล — รองรับ MySQL และ PostgreSQL
// First-run: ข้าม auth ถ้ายังไม่มีไฟล์ db_config.json

define('CONFIG_SKIP_DB',    true);   // ไม่ต่อ DB ขณะโหลด config
define('CONFIG_SETUP_PAGE', true);   // บอก config.php ว่าเราอยู่บนหน้านี้ (ไม่ redirect วนลูป)
require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Bangkok');

$dir  = __DIR__ . DIRECTORY_SEPARATOR . 'secrets';
$file = $dir    . DIRECTORY_SEPARATOR . 'db_config.json';

// ─── First-run detection ────────────────────────────────────────────────────
$isFirstRun = !is_readable($file);

if ($isFirstRun) {
  // ยังไม่มี config → ข้าม auth
  if (session_status() === PHP_SESSION_NONE) session_start();
} else {
  // มี config แล้ว → ต้องเข้าสู่ระบบ
  require_once __DIR__ . '/auth_guard.php';
}

// ─── Handle AJAX: ทดสอบการเชื่อมต่อ ─────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'test_connection') {
  header('Content-Type: application/json; charset=utf-8');
  $driver = in_array($_POST['driver'] ?? '', ['mysql','pgsql'], true)
            ? $_POST['driver'] : 'mysql';
  $host   = trim($_POST['host'] ?? '');
  $port   = (int)($_POST['port'] ?? ($driver === 'pgsql' ? 5432 : 3306));
  $name   = trim($_POST['name'] ?? '');
  $user   = trim($_POST['user'] ?? '');
  $pass   = trim($_POST['pass'] ?? '');

  if (!$host || !$name || !$user) {
    echo json_encode(['ok'=>false,'msg'=>'กรุณากรอก Host, Database และ Username ให้ครบ']);
    exit;
  }
  try {
    if ($driver === 'pgsql') {
      $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
    } else {
      $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    }
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo json_encode(['ok'=>true,'msg'=>"เชื่อมต่อสำเร็จ ✓  Server version: {$ver}"]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'msg'=>'เชื่อมต่อไม่ได้: '.$e->getMessage()]);
  }
  exit;
}

// ─── Handle POST: บันทึกค่า ─────────────────────────────────────────────────
$now = date('Y-m-d H:i:s');
$flashMsg = $flashErr = '';

// โหลดค่าปัจจุบันจากไฟล์ (ถ้ามี) หรือ default
$current = [
  'driver' => $DB_DRIVER ?? 'mysql',
  'host'   => $DB_HOST   ?? '192.168.1.249',
  'port'   => $DB_PORT   ?? 3306,
  'name'   => $DB_NAME   ?? 'hosxp',
  'user'   => $DB_USER   ?? 'root',
  'pass'   => $DB_PASS   ?? '',
];
if (is_readable($file)) {
  $j = json_decode(@file_get_contents($file), true);
  if (is_array($j)) {
    $current['driver'] = in_array($j['driver'] ?? '', ['mysql','pgsql'], true)
                         ? $j['driver'] : $current['driver'];
    $current['host'] = $j['host'] ?? $current['host'];
    $current['port'] = $j['port'] ?? $current['port'];
    $current['name'] = $j['name'] ?? $current['name'];
    $current['user'] = $j['user'] ?? $current['user'];
    $current['pass'] = $j['pass'] ?? $current['pass'];
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
  // CSRF token check (ข้ามสำหรับ first-run ที่ยังไม่มี token)
  $tokenOk = $isFirstRun
    || (!empty($_POST['token']) && $_POST['token'] === (defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : ''));
  if (!$tokenOk) {
    $flashErr = 'Invalid token — กรุณา refresh หน้าแล้วลองใหม่';
  } else {
    $driver = in_array($_POST['db_driver'] ?? '', ['mysql','pgsql'], true)
              ? $_POST['db_driver'] : 'mysql';
    $payload = [
      'driver' => $driver,
      'host'   => trim($_POST['db_host'] ?? ''),
      'port'   => (int)($_POST['db_port'] ?? ($driver === 'pgsql' ? 5432 : 3306)),
      'name'   => trim($_POST['db_name'] ?? ''),
      'user'   => trim($_POST['db_user'] ?? ''),
      'pass'   => trim($_POST['db_pass'] ?? ''),
      '_meta'  => ['updated_at' => $now],
    ];
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ok = @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($ok !== false) {
      @chmod($file, 0660);
      $flashMsg = 'บันทึกค่าฐานข้อมูลสำเร็จ';
      $current  = array_merge($current, $payload);
      $isFirstRun = false;
    } else {
      $flashErr = 'บันทึกไม่สำเร็จ — กรุณาตรวจสิทธิ์โฟลเดอร์ secrets/';
    }
  }
}

// ─── Page variables ──────────────────────────────────────────────────────────
$PAGE_TITLE = 'ตั้งค่าฐานข้อมูล';
$PAGE_KEY   = 'db_config';

// Extra CSS for this page
$EXTRA_HEAD = <<<'HTML'
<style>
/* ── Driver selector cards ── */
.driver-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.driver-card {
  position:relative; cursor:pointer; border-radius:12px;
  border:2px solid var(--card-border); background:var(--card-bg);
  padding:16px 14px 14px; transition:border-color .15s, box-shadow .15s;
  user-select:none;
}
.driver-card:hover { border-color:var(--blue); }
.driver-card.selected {
  border-color:var(--blue);
  box-shadow:0 0 0 3px var(--blue-100);
  background:var(--blue-50);
}
.driver-card input[type=radio] {
  position:absolute; opacity:0; width:0; height:0;
}
.driver-logo {
  width:44px; height:44px; border-radius:10px;
  display:grid; place-items:center; margin-bottom:10px;
  font-size:1.5rem;
}
.driver-logo.mysql-logo  { background:linear-gradient(135deg,#00758f,#005d73); color:#fff; }
.driver-logo.pgsql-logo  { background:linear-gradient(135deg,#336791,#1a4566); color:#fff; }
.driver-name  { font-weight:700; font-size:.95rem; color:var(--text); margin-bottom:2px; }
.driver-desc  { font-size:.76rem; color:var(--muted); }
.driver-check {
  position:absolute; top:10px; right:10px;
  width:22px; height:22px; border-radius:50%;
  border:2px solid var(--border);
  background:var(--card-bg);
  display:grid; place-items:center;
  transition:all .15s;
}
.driver-card.selected .driver-check {
  background:var(--blue); border-color:var(--blue); color:#fff;
}

/* ── Setup wizard banner (first-run) ── */
.setup-banner {
  background:linear-gradient(135deg,#1d4ed8 0%,#0891b2 100%);
  border-radius:14px; padding:20px 24px; color:#fff;
  display:flex; align-items:center; gap:16px; margin-bottom:24px;
}
.setup-banner-icon {
  width:52px; height:52px; border-radius:12px;
  background:rgba(255,255,255,.18);
  display:grid; place-items:center; flex-shrink:0; font-size:1.6rem;
}
.setup-banner h2 { font-size:1.05rem; font-weight:700; margin:0 0 2px; }
.setup-banner p  { font-size:.83rem; margin:0; opacity:.88; }

/* ── DSN preview ── */
.dsn-preview {
  font-family: 'Courier New', Courier, monospace;
  font-size: .78rem;
  background: var(--th-head-bg);
  border: 1px solid var(--card-border);
  border-radius: 8px;
  padding: 10px 12px;
  color: var(--muted);
  word-break: break-all;
  margin-top: 12px;
}
.dsn-preview .dsn-key   { color: var(--blue); }
.dsn-preview .dsn-val   { color: #059669; }

/* ── Section heading ── */
.section-head {
  font-size:.72rem; font-weight:600; letter-spacing:.08em;
  text-transform:uppercase; color:var(--section-lbl);
  margin-bottom:10px; padding-bottom:6px;
  border-bottom:1px solid var(--border);
}

/* ── Test result badge ── */
#testResult {
  display:none; border-radius:8px; padding:10px 14px;
  font-size:.85rem; font-weight:500; margin-top:10px;
}
#testResult.ok  { background:#dcfce7; color:#166534; }
#testResult.err { background:#fee2e2; color:#991b1b; }

/* ── Password toggle wrapper ── */
.pw-wrap { position:relative; }
.pw-wrap .pw-toggle {
  position:absolute; right:10px; top:50%; transform:translateY(-50%);
  border:none; background:transparent; cursor:pointer;
  color:var(--muted); padding:0; line-height:1;
}
.pw-wrap .pw-toggle:hover { color:var(--blue); }
.pw-wrap input { padding-right:40px; }

/* ── Info chip ── */
.info-chip {
  display:inline-flex; align-items:center; gap:5px;
  background:var(--blue-50); border:1px solid var(--blue-100);
  color:var(--blue); border-radius:999px;
  font-size:.75rem; font-weight:500; padding:3px 10px;
}
</style>
HTML;

require_once __DIR__ . '/partials/header.php';
?>

<!-- ═══════════════════════════════════════════════════
     PAGE CONTENT
═══════════════════════════════════════════════════ -->

<?php if ($isFirstRun): ?>
<!-- First-run banner -->
<div class="setup-banner">
  <div class="setup-banner-icon"><span class="msi">rocket_launch</span></div>
  <div>
    <h2>ยินดีต้อนรับสู่ Fall Risk Alert</h2>
    <p>ยังไม่พบการตั้งค่าฐานข้อมูล — กรุณากรอกข้อมูลการเชื่อมต่อด้านล่างเพื่อเริ่มใช้งานระบบ</p>
  </div>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="page-header">
  <div>
    <h1><span class="msi me-2" style="color:var(--blue)">storage</span>ตั้งค่าฐานข้อมูล</h1>
    <div class="mt-1 d-flex align-items-center gap-2 flex-wrap" style="font-size:.82rem; color:var(--muted)">
      <span class="msi" style="font-size:1rem">schedule</span> อัปเดตล่าสุด:
      <?php
        if (is_readable($file)) {
          $j = json_decode(@file_get_contents($file), true);
          echo htmlspecialchars($j['_meta']['updated_at'] ?? '—');
        } else { echo '(ยังไม่มีการตั้งค่า)'; }
      ?>
      &nbsp;|&nbsp;
      <span class="info-chip">
        <span class="msi" style="font-size:1rem">folder</span>
        secrets/db_config.json
      </span>
    </div>
  </div>
</div>

<!-- Flash messages -->
<?php if ($flashMsg): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-3"
     style="border-radius:10px; font-size:.9rem;">
  <span class="msi">check_circle</span> <?= htmlspecialchars($flashMsg) ?>
</div>
<?php endif; ?>
<?php if ($flashErr): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3"
     style="border-radius:10px; font-size:.9rem;">
  <span class="msi">error</span> <?= htmlspecialchars($flashErr) ?>
</div>
<?php endif; ?>

<!-- ─── Main form ─────────────────────────────────────── -->
<form method="post" id="dbForm" autocomplete="off">
  <?php if (!$isFirstRun): ?>
  <input type="hidden" name="token"
         value="<?= htmlspecialchars(defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : '') ?>">
  <?php endif; ?>

  <div class="row g-3">

    <!-- LEFT: connection settings -->
    <div class="col-lg-7">

      <!-- ① Driver selector -->
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:var(--blue)">database</span>
          ชนิดฐานข้อมูล
        </div>
        <div class="card-body">
          <div class="driver-grid" id="driverGrid">

            <!-- MySQL -->
            <label class="driver-card<?= $current['driver']==='mysql' ? ' selected' : '' ?>"
                   id="card-mysql">
              <input type="radio" name="db_driver" value="mysql"
                     <?= $current['driver']==='mysql' ? 'checked' : '' ?>
                     onchange="onDriverChange('mysql')">
              <div class="driver-logo mysql-logo">
                <span class="msi">database</span>
              </div>
              <div class="driver-name">MySQL / MariaDB</div>
              <div class="driver-desc">HOSxP, HOSxP PCU, iMed@Home</div>
              <div class="driver-check">
                <?php if($current['driver']==='mysql'): ?>
                <span class="msi" style="font-size:.75rem">check</span>
                <?php endif; ?>
              </div>
            </label>

            <!-- PostgreSQL -->
            <label class="driver-card<?= $current['driver']==='pgsql' ? ' selected' : '' ?>"
                   id="card-pgsql">
              <input type="radio" name="db_driver" value="pgsql"
                     <?= $current['driver']==='pgsql' ? 'checked' : '' ?>
                     onchange="onDriverChange('pgsql')">
              <div class="driver-logo pgsql-logo">
                <span class="msi">hub</span>
              </div>
              <div class="driver-name">PostgreSQL</div>
              <div class="driver-desc">HIS อื่นๆ ที่ใช้ PostgreSQL</div>
              <div class="driver-check">
                <?php if($current['driver']==='pgsql'): ?>
                <span class="msi" style="font-size:.75rem">check</span>
                <?php endif; ?>
              </div>
            </label>

          </div><!-- /.driver-grid -->
        </div>
      </div>

      <!-- ② Connection details -->
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:var(--blue)">settings_ethernet</span>
          รายละเอียดการเชื่อมต่อ
        </div>
        <div class="card-body">

          <div class="row g-3">
            <!-- Host -->
            <div class="col-sm-8">
              <label class="form-label fw-semibold" for="dbHost">
                <span class="msi me-1" style="font-size:1rem">dns</span>Host / IP
              </label>
              <input type="text" id="dbHost" name="db_host" class="form-control"
                     value="<?= htmlspecialchars($current['host']) ?>"
                     placeholder="เช่น 192.168.1.249 หรือ localhost" required
                     oninput="updateDsnPreview()">
            </div>
            <!-- Port -->
            <div class="col-sm-4">
              <label class="form-label fw-semibold" for="dbPort">
                <span class="msi me-1" style="font-size:1rem">lan</span>Port
              </label>
              <input type="number" id="dbPort" name="db_port" class="form-control"
                     value="<?= htmlspecialchars($current['port']) ?>"
                     min="1" max="65535" required
                     oninput="updateDsnPreview()">
            </div>
          </div>

          <!-- Database name -->
          <div class="mt-3">
            <label class="form-label fw-semibold" for="dbName">
              <span class="msi me-1" style="font-size:1rem">table_chart</span>ชื่อฐานข้อมูล (Database)
            </label>
            <input type="text" id="dbName" name="db_name" class="form-control"
                   value="<?= htmlspecialchars($current['name']) ?>"
                   placeholder="เช่น hosxp" required
                   oninput="updateDsnPreview()">
          </div>

          <!-- Username -->
          <div class="mt-3">
            <label class="form-label fw-semibold" for="dbUser">
              <span class="msi me-1" style="font-size:1rem">person</span>Username
            </label>
            <input type="text" id="dbUser" name="db_user" class="form-control"
                   value="<?= htmlspecialchars($current['user']) ?>"
                   placeholder="เช่น root" required
                   autocomplete="username"
                   oninput="updateDsnPreview()">
          </div>

          <!-- Password -->
          <div class="mt-3">
            <label class="form-label fw-semibold" for="dbPass">
              <span class="msi me-1" style="font-size:1rem">key</span>Password
            </label>
            <div class="pw-wrap">
              <input type="password" id="dbPass" name="db_pass" class="form-control"
                     value="<?= htmlspecialchars($current['pass']) ?>"
                     placeholder="รหัสผ่าน" autocomplete="current-password">
              <button type="button" class="pw-toggle" id="pwToggle"
                      aria-label="แสดง/ซ่อนรหัสผ่าน"
                      onclick="togglePassword()">
                <span class="msi" id="pwIcon">visibility</span>
              </button>
            </div>
          </div>

        </div><!-- /.card-body -->
      </div><!-- /.card -->

      <!-- Action buttons -->
      <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary px-4">
          <span class="msi me-1">save</span>บันทึกการตั้งค่า
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="testConnection()">
          <span class="msi me-1" id="testSpinIcon">network_check</span>ทดสอบการเชื่อมต่อ
        </button>
        <?php if (!$isFirstRun): ?>
        <a href="index.php" class="btn btn-outline-secondary ms-auto">
          <span class="msi me-1">home</span>กลับหน้าหลัก
        </a>
        <?php endif; ?>
      </div>

      <!-- Test result -->
      <div id="testResult"></div>

    </div><!-- /.col-lg-7 -->

    <!-- RIGHT: preview + info -->
    <div class="col-lg-5">

      <!-- DSN preview card -->
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:var(--blue)">code</span>
          Connection String (DSN)
        </div>
        <div class="card-body">
          <p class="mb-1" style="font-size:.8rem; color:var(--muted)">
            PHP PDO DSN ที่ระบบจะสร้างให้อัตโนมัติ:
          </p>
          <div class="dsn-preview" id="dsnPreview">—</div>
        </div>
      </div>

      <!-- Info card -->
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:#d97706">info</span>
          คำแนะนำ
        </div>
        <div class="card-body" style="font-size:.83rem; color:var(--muted); line-height:1.7">
          <p class="mb-2">
            <span class="msi me-1" style="color:#059669">check_circle</span>
            แนะนำให้ใช้ <strong>Slave DB</strong> (Read Replica) เพื่อลดภาระ Production
          </p>
          <p class="mb-2">
            <span class="msi me-1" style="color:#059669">check_circle</span>
            MySQL default port: <code>3306</code>, PostgreSQL: <code>5432</code>
          </p>
          <p class="mb-2">
            <span class="msi me-1" style="color:#059669">check_circle</span>
            ค่าจะถูกบันทึกที่ <code>secrets/db_config.json</code>
            ซึ่งโฟลเดอร์ secrets/ ควรอยู่นอก web root
          </p>
          <p class="mb-0">
            <span class="msi me-1" style="color:#d97706">warning</span>
            หากเปลี่ยน driver ต้องกด <strong>"ทดสอบ"</strong> ก่อนบันทึกทุกครั้ง
          </p>
        </div>
      </div>

      <!-- File status card -->
      <div class="card">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:var(--blue)">folder_open</span>
          สถานะไฟล์
        </div>
        <div class="card-body" style="font-size:.83rem;">
          <?php
          $fileRows = [
            ['secrets/db_config.json', is_readable($file)],
            ['config.php',             is_readable(__DIR__.'/config.php')],
          ];
          foreach ($fileRows as [$name, $exists]):
          ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="msi" style="color:<?= $exists ? '#059669' : '#dc2626' ?>">
              <?= $exists ? 'check_circle' : 'cancel' ?>
            </span>
            <code><?= htmlspecialchars($name) ?></code>
            <span class="ms-auto" style="color:<?= $exists ? '#059669' : '#dc2626' ?>; font-weight:600">
              <?= $exists ? 'พบไฟล์' : 'ไม่พบ' ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- /.col-lg-5 -->
  </div><!-- /.row -->
</form>

<?php
// ─── Extra JS ──────────────────────────────────────────────────────────────
$EXTRA_FOOTER = <<<'JS'
<script>
// ── Driver card toggle ──────────────────────────────────────────────────────
function onDriverChange(driver) {
  // Update card selected state
  ['mysql','pgsql'].forEach(d => {
    const card  = document.getElementById('card-' + d);
    const check = card.querySelector('.driver-check');
    if (d === driver) {
      card.classList.add('selected');
      check.innerHTML = '<span class="msi" style="font-size:.75rem">check</span>';
    } else {
      card.classList.remove('selected');
      check.innerHTML = '';
    }
  });
  // Auto-switch port
  const portEl = document.getElementById('dbPort');
  if (driver === 'pgsql'  && portEl.value === '3306') portEl.value = '5432';
  if (driver === 'mysql'  && portEl.value === '5432') portEl.value = '3306';
  updateDsnPreview();
}

// ── DSN preview ──────────────────────────────────────────────────────────────
function updateDsnPreview() {
  const driver = document.querySelector('input[name="db_driver"]:checked')?.value || 'mysql';
  const host   = document.getElementById('dbHost').value || 'localhost';
  const port   = document.getElementById('dbPort').value || (driver === 'pgsql' ? 5432 : 3306);
  const name   = document.getElementById('dbName').value || 'dbname';
  const user   = document.getElementById('dbUser').value || 'user';

  let dsn;
  if (driver === 'pgsql') {
    dsn = `pgsql:host=${host};port=${port};dbname=${name}`;
  } else {
    dsn = `mysql:host=${host};port=${port};dbname=${name};charset=utf8mb4`;
  }
  document.getElementById('dsnPreview').textContent = dsn;
}

// ── Password toggle ──────────────────────────────────────────────────────────
function togglePassword() {
  const inp  = document.getElementById('dbPass');
  const icon = document.getElementById('pwIcon');
  const show = inp.type === 'password';
  inp.type       = show ? 'text' : 'password';
  icon.textContent = show ? 'visibility_off' : 'visibility';
}

// ── Test connection ──────────────────────────────────────────────────────────
function testConnection() {
  const resultEl = document.getElementById('testResult');
  const spinIcon = document.getElementById('testSpinIcon');
  const driver = document.querySelector('input[name="db_driver"]:checked')?.value || 'mysql';

  spinIcon.textContent = 'sync';
  spinIcon.classList.add('msi-spin');
  resultEl.style.display = 'none';
  resultEl.className = '';

  const fd = new FormData();
  fd.append('action',  'test_connection');
  fd.append('driver',  driver);
  fd.append('host',    document.getElementById('dbHost').value);
  fd.append('port',    document.getElementById('dbPort').value);
  fd.append('name',    document.getElementById('dbName').value);
  fd.append('user',    document.getElementById('dbUser').value);
  fd.append('pass',    document.getElementById('dbPass').value);

  fetch('db_config_admin.php', { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      spinIcon.textContent = 'network_check';
      spinIcon.classList.remove('msi-spin');
      resultEl.style.display = 'block';
      resultEl.className = data.ok ? 'ok' : 'err';
      resultEl.innerHTML = `<span class="msi me-1">${data.ok ? 'check_circle' : 'error'}</span>${data.msg}`;
    })
    .catch(err => {
      spinIcon.textContent = 'network_check';
      spinIcon.classList.remove('msi-spin');
      resultEl.style.display = 'block';
      resultEl.className = 'err';
      resultEl.innerHTML = '<span class="msi me-1">error</span>เกิดข้อผิดพลาด: ' + err;
    });
}

// ── Init ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  updateDsnPreview();
});
</script>
JS;

require_once __DIR__ . '/partials/footer.php';
