<?php
// moph_keys_admin.php
// หน้าตั้งค่า MOPH ALERT API Keys สำหรับแต่ละโมดูล
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';

date_default_timezone_set('Asia/Bangkok');

$dir  = __DIR__ . DIRECTORY_SEPARATOR . 'secrets';
$file = $dir    . DIRECTORY_SEPARATOR . 'moph_keys.json';

$now     = date('Y-m-d H:i:s');
$flashMsg = $flashErr = '';

// ─── โหลดค่าปัจจุบัน ────────────────────────────────────────────────────────
$modules = [
  'default'   => ['label'=>'ค่าเริ่มต้น (Default)',  'icon'=>'settings',       'color'=>'#64748b', 'grad'=>'135deg,#64748b,#334155'],
  'covid'     => ['label'=>'COVID-19',               'icon'=>'coronavirus',     'color'=>'#ea580c', 'grad'=>'135deg,#f97316,#ea580c'],
  'fracture'  => ['label'=>'พลัดตก / หกล้ม',          'icon'=>'falling',         'color'=>'#059669', 'grad'=>'135deg,#10b981,#059669'],
  'accident'  => ['label'=>'พ.ร.บ. / อุบัติเหตุ',    'icon'=>'car_crash',       'color'=>'#d97706', 'grad'=>'135deg,#f59e0b,#d97706'],
  'pharm_lab' => ['label'=>'เภสัชกรรม / Lab',         'icon'=>'medication',      'color'=>'#0891b2', 'grad'=>'135deg,#22d3ee,#0891b2'],
];

$current = [];
foreach (array_keys($modules) as $k) {
  $current[$k] = ['client'=>'', 'secret'=>''];
}

if (is_readable($file)) {
  $j = json_decode(@file_get_contents($file), true);
  if (is_array($j)) {
    foreach ($current as $k => $_) {
      $current[$k]['client'] = $j[$k]['client'] ?? '';
      $current[$k]['secret'] = $j[$k]['secret'] ?? '';
    }
  }
}

// ─── Handle POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['token']) || $_POST['token'] !== (defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : '')) {
    http_response_code(403);
    $flashErr = 'Invalid token — กรุณา refresh หน้าแล้วลองใหม่';
  } else {
    $payload = [
      'default'   => ['client'=>trim($_POST['default_client']??''),   'secret'=>trim($_POST['default_secret']??'')],
      'covid'     => ['client'=>trim($_POST['covid_client']??''),     'secret'=>trim($_POST['covid_secret']??'')],
      'fracture'  => ['client'=>trim($_POST['fracture_client']??''),  'secret'=>trim($_POST['fracture_secret']??'')],
      'accident'  => ['client'=>trim($_POST['accident_client']??''),  'secret'=>trim($_POST['accident_secret']??'')],
      'pharm_lab' => ['client'=>trim($_POST['pharm_client']??''),     'secret'=>trim($_POST['pharm_secret']??'')],
      '_meta'     => ['updated_at'=>$now],
    ];
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ok = @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($ok !== false) {
      @chmod($file, 0660);
      $flashMsg = 'บันทึก MOPH Keys สำเร็จ';
      foreach (array_keys($current) as $k) {
        $current[$k] = $payload[$k] ?? $current[$k];
      }
    } else {
      $flashErr = 'บันทึกไม่สำเร็จ — กรุณาตรวจสิทธิ์โฟลเดอร์ secrets/';
    }
  }
}

// ─── Page variables ──────────────────────────────────────────────────────────
$PAGE_TITLE = 'ตั้งค่า MOPH Keys';
$PAGE_KEY   = 'moph_keys';

$EXTRA_HEAD = <<<'HTML'
<style>
/* ── Module card ── */
.module-card {
  background: var(--card-bg);
  border: 1px solid var(--card-border);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: var(--card-shadow);
  transition: box-shadow .2s, background .25s;
}
.module-card:hover { box-shadow: var(--card-shadow-hover); }

.module-card-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px 12px;
  border-bottom: 1px solid var(--card-border);
}
.module-icon {
  width: 40px; height: 40px; border-radius: 10px;
  display: grid; place-items: center;
  color: #fff; font-size: 1.1rem; flex-shrink: 0;
}
.module-card-title {
  font-weight: 700; font-size: .95rem; color: var(--text); margin: 0;
}
.module-card-body { padding: 14px 16px 16px; }

/* ── Key status dot ── */
.key-dot {
  display: inline-block; width: 8px; height: 8px; border-radius: 50%;
  vertical-align: middle; margin-right: 4px;
}
.key-dot.filled { background: #22c55e; }
.key-dot.empty  { background: #cbd5e1; }

/* ── pw-wrap ── */
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 38px; font-family: 'Courier New', monospace; font-size: .82rem; }
.pw-wrap .pw-toggle {
  position: absolute; right: 9px; top: 50%; transform: translateY(-50%);
  border: none; background: transparent; cursor: pointer;
  color: var(--muted); padding: 0; line-height: 1;
}
.pw-wrap .pw-toggle:hover { color: var(--blue); }

/* ── Client key field ── */
.client-field { font-family: 'Courier New', monospace; font-size: .82rem; }

/* ── Fallback chain visualizer ── */
.fallback-chain {
  display: flex; flex-direction: column; gap: 0;
}
.fallback-step {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 9px 0;
  border-bottom: 1px dashed var(--border);
  font-size: .82rem; color: var(--text);
}
.fallback-step:last-child { border-bottom: none; }
.fallback-num {
  width: 22px; height: 22px; border-radius: 50%;
  background: var(--blue); color: #fff;
  font-size: .72rem; font-weight: 700;
  display: grid; place-items: center; flex-shrink: 0;
}
.fallback-num.last { background: #94a3b8; }

/* ── Info chip ── */
.info-chip {
  display: inline-flex; align-items: center; gap: 5px;
  background: var(--blue-50); border: 1px solid var(--blue-100);
  color: var(--blue); border-radius: 999px;
  font-size: .75rem; font-weight: 500; padding: 3px 10px;
}

/* ── Keys status overview ── */
.key-overview {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
}
.key-status-chip {
  display: flex; align-items: center; gap: 7px;
  padding: 7px 10px; border-radius: 8px;
  border: 1px solid var(--card-border);
  font-size: .78rem; font-weight: 500; color: var(--text);
  background: var(--card-bg);
}
.key-status-chip .chip-icon {
  width: 26px; height: 26px; border-radius: 7px;
  display: grid; place-items: center;
  font-size: .85rem; color: #fff; flex-shrink: 0;
}
</style>
HTML;

require_once __DIR__ . '/partials/header.php';
?>

<!-- ═══════════════════════════════════════════════════
     PAGE CONTENT
═══════════════════════════════════════════════════ -->

<!-- Page header -->
<div class="page-header">
  <div>
    <h1><span class="msi me-2" style="color:var(--blue)">key</span>ตั้งค่า MOPH ALERT Keys</h1>
    <div class="mt-1 d-flex align-items-center gap-2 flex-wrap"
         style="font-size:.82rem; color:var(--muted)">
      <span class="msi" style="font-size:1rem">schedule</span> อัปเดตล่าสุด:
      <?php
        if (is_readable($file)) {
          $jm = json_decode(@file_get_contents($file), true);
          echo htmlspecialchars($jm['_meta']['updated_at'] ?? '—');
        } else { echo '(ยังไม่มีการตั้งค่า)'; }
      ?>
      &nbsp;|&nbsp;
      <span class="info-chip">
        <span class="msi" style="font-size:1rem">folder</span>
        secrets/moph_keys.json
      </span>
    </div>
  </div>
  <div>
    <a href="https://morpromt2f.moph.go.th" target="_blank" rel="noopener"
       class="btn btn-outline-secondary btn-sm">
      <span class="msi me-1">open_in_new</span>MOPH Portal
    </a>
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
<form method="post" id="keysForm" autocomplete="off">
  <input type="hidden" name="token"
         value="<?= htmlspecialchars(defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : '') ?>">

  <div class="row g-3">

    <!-- LEFT: Key input cards -->
    <div class="col-lg-8">

      <!-- ① Default keys -->
      <div class="module-card mb-3">
        <div class="module-card-header">
          <div class="module-icon" style="background:linear-gradient(<?= $modules['default']['grad'] ?>)">
            <span class="msi"><?= $modules['default']['icon'] ?></span>
          </div>
          <div>
            <div class="module-card-title"><?= $modules['default']['label'] ?></div>
            <div style="font-size:.75rem; color:var(--muted)">
              ใช้เป็น Fallback เมื่อโมดูลอื่นไม่ได้กรอกไว้
            </div>
          </div>
          <div class="ms-auto">
            <?php $hasDefault = !empty($current['default']['client']); ?>
            <span class="status-badge <?= $hasDefault ? 'status-ok' : 'status-pending' ?>">
              <span class="msi"><?= $hasDefault ? 'check_circle' : 'pending' ?></span>
              <?= $hasDefault ? 'ตั้งค่าแล้ว' : 'ยังไม่ได้ตั้งค่า' ?>
            </span>
          </div>
        </div>
        <div class="module-card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="defaultClient">
                <span class="msi me-1" style="font-size:1rem">badge</span>Client Key
              </label>
              <input type="text" id="defaultClient" name="default_client"
                     class="form-control client-field"
                     value="<?= htmlspecialchars($current['default']['client']) ?>"
                     placeholder="MOPH_CLIENT_KEY">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="defaultSecret">
                <span class="msi me-1" style="font-size:1rem">lock</span>Secret Key
              </label>
              <div class="pw-wrap">
                <input type="password" id="defaultSecret" name="default_secret"
                       class="form-control"
                       value="<?= htmlspecialchars($current['default']['secret']) ?>"
                       placeholder="MOPH_SECRET_KEY">
                <button type="button" class="pw-toggle" onclick="togglePw(this)"
                        aria-label="แสดง/ซ่อน">
                  <span class="msi">visibility</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ② Module keys (2×2 grid) -->
      <div class="row g-3">
        <?php
        $moduleKeys = ['covid','fracture','accident','pharm_lab'];
        $fieldMap   = [
          'covid'     => ['client'=>'covid_client',    'secret'=>'covid_secret'],
          'fracture'  => ['client'=>'fracture_client', 'secret'=>'fracture_secret'],
          'accident'  => ['client'=>'accident_client', 'secret'=>'accident_secret'],
          'pharm_lab' => ['client'=>'pharm_client',    'secret'=>'pharm_secret'],
        ];
        foreach ($moduleKeys as $mk):
          $m = $modules[$mk];
          $f = $fieldMap[$mk];
          $hasCfg = !empty($current[$mk]['client']);
        ?>
        <div class="col-md-6">
          <div class="module-card h-100">
            <div class="module-card-header">
              <div class="module-icon"
                   style="background:linear-gradient(<?= $m['grad'] ?>)">
                <span class="msi"><?= $m['icon'] ?></span>
              </div>
              <div>
                <div class="module-card-title"><?= htmlspecialchars($m['label']) ?></div>
                <div style="font-size:.72rem; color:var(--muted)">
                  <span class="key-dot <?= $hasCfg ? 'filled' : 'empty' ?>"></span>
                  <?= $hasCfg ? 'มีคีย์เฉพาะ' : 'ใช้ Default' ?>
                </div>
              </div>
            </div>
            <div class="module-card-body">
              <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size:.82rem">
                  <span class="msi me-1" style="font-size:.95rem">badge</span>Client Key
                </label>
                <input type="text" name="<?= $f['client'] ?>"
                       class="form-control client-field"
                       value="<?= htmlspecialchars($current[$mk]['client']) ?>"
                       placeholder="(ว่าง = ใช้ Default)">
              </div>
              <div>
                <label class="form-label fw-semibold" style="font-size:.82rem">
                  <span class="msi me-1" style="font-size:.95rem">lock</span>Secret Key
                </label>
                <div class="pw-wrap">
                  <input type="password" name="<?= $f['secret'] ?>"
                         class="form-control"
                         value="<?= htmlspecialchars($current[$mk]['secret']) ?>"
                         placeholder="(ว่าง = ใช้ Default)">
                  <button type="button" class="pw-toggle" onclick="togglePw(this)"
                          aria-label="แสดง/ซ่อน">
                    <span class="msi">visibility</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div><!-- /.row module cards -->

      <!-- Action buttons -->
      <div class="d-flex gap-2 mt-3 flex-wrap">
        <button type="submit" class="btn btn-primary px-4">
          <span class="msi me-1">save</span>บันทึก Keys ทั้งหมด
        </button>
        <button type="button" class="btn btn-outline-secondary"
                onclick="toggleAllSecrets()">
          <span class="msi me-1" id="toggleAllIcon">visibility</span>
          <span id="toggleAllText">แสดง Secret Keys</span>
        </button>
        <a href="index.php" class="btn btn-outline-secondary ms-auto">
          <span class="msi me-1">home</span>กลับหน้าหลัก
        </a>
      </div>

    </div><!-- /.col-lg-8 -->

    <!-- RIGHT: info + status -->
    <div class="col-lg-4">

      <!-- Key status overview -->
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:var(--blue)">checklist</span>
          สถานะคีย์ทั้งหมด
        </div>
        <div class="card-body">
          <div class="key-overview">
            <?php foreach ($modules as $mk => $m):
              $hasCfg = !empty($current[$mk]['client']);
            ?>
            <div class="key-status-chip">
              <div class="chip-icon"
                   style="background:linear-gradient(<?= $m['grad'] ?>)">
                <span class="msi" style="font-size:.85rem"><?= $m['icon'] ?></span>
              </div>
              <div>
                <div style="font-size:.76rem; font-weight:600; line-height:1.2">
                  <?= htmlspecialchars($mk === 'default' ? 'Default' :
                      ($mk === 'pharm_lab' ? 'Pharm' : ucfirst($mk))) ?>
                </div>
                <div style="font-size:.7rem; color:<?= $hasCfg ? '#059669' : '#94a3b8' ?>">
                  <?= $hasCfg ? 'ตั้งค่าแล้ว ✓' : 'ว่าง' ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Fallback chain -->
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:#d97706">account_tree</span>
          ลำดับการ Fallback
        </div>
        <div class="card-body px-3 py-2">
          <div class="fallback-chain">
            <div class="fallback-step">
              <div class="fallback-num">1</div>
              <div>
                <strong>คีย์เฉพาะโมดูล</strong><br>
                <span style="color:var(--muted); font-size:.76rem">
                  เช่น <code>fracture.client</code> / <code>fracture.secret</code>
                </span>
              </div>
            </div>
            <div class="fallback-step">
              <div class="fallback-num">2</div>
              <div>
                <strong>Default Key</strong><br>
                <span style="color:var(--muted); font-size:.76rem">
                  <code>default.client</code> / <code>default.secret</code>
                </span>
              </div>
            </div>
            <div class="fallback-step">
              <div class="fallback-num last">3</div>
              <div>
                <strong>ค่าจาก <code>config.php</code></strong><br>
                <span style="color:var(--muted); font-size:.76rem">
                  <code>MOPH_CLIENT_KEY</code> / <code>MOPH_SECRET_KEY</code>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Constants defined -->
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:var(--blue)">terminal</span>
          PHP Constants ที่ได้
        </div>
        <div class="card-body" style="font-size:.76rem; color:var(--muted); line-height:1.9">
          <?php
          $constMap = [
            'covid'     => ['COVID_CLIENT_KEY',    'COVID_SECRET_KEY'],
            'fracture'  => ['FRACTURE_CLIENT_KEY', 'FRACTURE_SECRET_KEY'],
            'accident'  => ['ACCIDENT_CLIENT_KEY', 'ACCIDENT_SECRET_KEY'],
            'pharm_lab' => ['PHARM_CLIENT_KEY',    'PHARM_SECRET_KEY'],
          ];
          foreach ($constMap as $mk => [$c, $s]):
          ?>
          <div class="mb-1">
            <code style="color:var(--blue)"><?= $c ?></code><br>
            <code style="color:var(--blue)"><?= $s ?></code>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- File status -->
      <div class="card">
        <div class="card-header d-flex align-items-center gap-2">
          <span class="msi" style="color:var(--blue)">folder_open</span>
          สถานะไฟล์
        </div>
        <div class="card-body" style="font-size:.83rem;">
          <?php
          $fileRows = [
            ['secrets/moph_keys.json',  is_readable($file)],
            ['moph_keys_loader.php',    is_readable(__DIR__.'/moph_keys_loader.php')],
          ];
          foreach ($fileRows as [$name, $exists]):
          ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="msi" style="color:<?= $exists ? '#059669' : '#dc2626' ?>">
              <?= $exists ? 'check_circle' : 'cancel' ?>
            </span>
            <code><?= htmlspecialchars($name) ?></code>
            <span class="ms-auto"
                  style="color:<?= $exists ? '#059669' : '#dc2626' ?>; font-weight:600">
              <?= $exists ? 'พบไฟล์' : 'ไม่พบ' ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- /.col-lg-4 -->
  </div><!-- /.row -->
</form>

<?php
$EXTRA_FOOTER = <<<'JS'
<script>
// ── Toggle individual password field ─────────────────────────────────────────
function togglePw(btn) {
  const inp  = btn.closest('.pw-wrap').querySelector('input');
  const icon = btn.querySelector('.msi');
  const show = inp.type === 'password';
  inp.type         = show ? 'text' : 'password';
  icon.textContent = show ? 'visibility_off' : 'visibility';
}

// ── Toggle ALL secret fields at once ─────────────────────────────────────────
let allShown = false;
function toggleAllSecrets() {
  allShown = !allShown;
  document.querySelectorAll('.pw-wrap input[type]').forEach(inp => {
    inp.type = allShown ? 'text' : 'password';
  });
  document.querySelectorAll('.pw-wrap .pw-toggle .msi').forEach(icon => {
    icon.textContent = allShown ? 'visibility_off' : 'visibility';
  });
  document.getElementById('toggleAllIcon').textContent = allShown ? 'visibility_off' : 'visibility';
  document.getElementById('toggleAllText').textContent = allShown ? 'ซ่อน Secret Keys' : 'แสดง Secret Keys';
}
</script>
JS;

require_once __DIR__ . '/partials/footer.php';
