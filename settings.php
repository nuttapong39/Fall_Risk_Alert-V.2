<?php
require_once __DIR__ . '/auth_guard.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']) && empty($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}

$PAGE_TITLE = 'ตั้งค่าระบบ';
$PAGE_KEY   = 'settings';
$EXTRA_HEAD = <<<'CSS'
<style>
/* ========== Settings Page ========== */
.settings-section {
  background: var(--card-bg);
  border: 1px solid var(--card-border);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: var(--card-shadow);
  margin-bottom: 24px;
  transition: background .25s, border-color .25s;
}
.settings-section-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 18px 24px 14px;
  border-bottom: 1px solid var(--card-border);
}
.settings-section-icon {
  width: 36px; height: 36px;
  border-radius: 10px;
  display: grid; place-items: center;
  font-size: .95rem; color: #fff; flex-shrink: 0;
}
.settings-section-title  { font-weight: 700; font-size: 1rem; color: var(--text); }
.settings-section-desc   { font-size: .82rem; color: var(--muted); margin-top: 1px; }
.settings-section-body   { padding: 22px 24px 24px; }

/* ---- Font size picker ---- */
.fontsize-grid {
  display: flex; gap: 12px; flex-wrap: wrap;
}
.fontsize-btn {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  padding: 14px 20px;
  border-radius: 12px;
  border: 2px solid var(--card-border);
  background: var(--card-bg);
  cursor: pointer;
  transition: border-color .15s, background .15s, box-shadow .15s;
  min-width: 90px;
  color: var(--text);
}
.fontsize-btn:hover {
  border-color: var(--blue);
  background: var(--blue-50);
}
.fontsize-btn.active {
  border-color: var(--blue);
  background: var(--blue-50);
  box-shadow: 0 0 0 3px var(--blue-100);
}
.fontsize-btn .fs-preview {
  font-weight: 700;
  line-height: 1;
  color: var(--blue);
}
.fontsize-btn .fs-label {
  font-size: .78rem;
  color: var(--muted);
  font-weight: 500;
}
.fontsize-btn .fs-check {
  width: 18px; height: 18px;
  border-radius: 50%;
  background: var(--blue);
  color: #fff;
  display: none;
  place-items: center;
  font-size: .65rem;
}
.fontsize-btn.active .fs-check { display: grid; }

/* ---- Theme grid ---- */
.theme-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
}
@media (max-width: 768px) { .theme-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 480px) { .theme-grid { grid-template-columns: 1fr 1fr; } }

.theme-card {
  border: 2px solid var(--card-border);
  border-radius: 14px;
  overflow: hidden;
  cursor: pointer;
  transition: border-color .15s, box-shadow .15s;
  position: relative;
}
.theme-card:hover { border-color: var(--blue); }
.theme-card.active {
  border-color: var(--blue);
  box-shadow: 0 0 0 3px var(--blue-100);
}
.theme-check {
  position: absolute; top: 8px; right: 8px;
  width: 22px; height: 22px;
  border-radius: 50%;
  background: var(--blue);
  color: #fff;
  display: none;
  place-items: center;
  font-size: .7rem;
  z-index: 2;
  box-shadow: 0 2px 6px rgba(0,0,0,.2);
}
.theme-card.active .theme-check { display: grid; }

/* Mini layout preview */
.theme-preview {
  width: 100%; aspect-ratio: 4/3;
  position: relative; overflow: hidden;
}
/* Light */
.tp-light { background: #f8fafc; }
.tp-light .tp-sidebar { background: #fff; border-right: 1px solid #e2e8f0; }
.tp-light .tp-topbar  { background: #fff; border-bottom: 1px solid #e2e8f0; }
.tp-light .tp-accent  { background: #1d4ed8; }
.tp-light .tp-line    { background: #e2e8f0; }
/* Dark */
.tp-dark { background: #0f172a; }
.tp-dark .tp-sidebar { background: #1e293b; border-right: 1px solid #334155; }
.tp-dark .tp-topbar  { background: #1e293b; border-bottom: 1px solid #334155; }
.tp-dark .tp-accent  { background: #60a5fa; }
.tp-dark .tp-line    { background: #334155; }
/* Pastel */
.tp-pastel { background: #faf5ff; }
.tp-pastel .tp-sidebar { background: #fdf4ff; border-right: 1px solid #e9d5ff; }
.tp-pastel .tp-topbar  { background: #fff; border-bottom: 1px solid #e9d5ff; }
.tp-pastel .tp-accent  { background: #7c3aed; }
.tp-pastel .tp-line    { background: #e9d5ff; }
/* Classic */
.tp-classic { background: #f0fdf4; }
.tp-classic .tp-sidebar { background: #fff; border-right: 1px solid #a7f3d0; }
.tp-classic .tp-topbar  { background: #fff; border-bottom: 1px solid #a7f3d0; }
.tp-classic .tp-accent  { background: #059669; }
.tp-classic .tp-line    { background: #a7f3d0; }

/* Shared preview structure */
.tp-sidebar {
  position: absolute; left: 0; top: 0; bottom: 0;
  width: 28%; display: flex; flex-direction: column; padding: 8px 5px; gap: 3px;
}
.tp-topbar {
  position: absolute; left: 28%; right: 0; top: 0;
  height: 18%; display: flex; align-items: center; padding: 0 8px; gap: 4px;
}
.tp-content {
  position: absolute; left: 28%; right: 0; top: 18%; bottom: 0;
  padding: 8px;
  display: flex; flex-direction: column; gap: 5px;
}
.tp-nav-item {
  height: 8px; border-radius: 3px; opacity: .5;
}
.tp-nav-item.accent { opacity: 1; }
.tp-tb-circle { width: 10px; height: 10px; border-radius: 50%; margin-left: auto; }
.tp-content-row {
  height: 12px; border-radius: 4px; opacity: .35;
}
.tp-content-row.wide { width: 80%; }
.tp-content-row.med  { width: 55%; }
.tp-content-row.sm   { width: 35%; }

.theme-label {
  padding: 10px 12px 12px;
  background: var(--card-bg);
  transition: background .25s;
}
.theme-name { font-weight: 700; font-size: .88rem; color: var(--text); }
.theme-desc { font-size: .75rem; color: var(--muted); }

/* ---- Selected info bar ---- */
.selected-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 18px;
  padding: 12px 16px;
  background: var(--blue-50);
  border: 1px solid var(--blue-100);
  border-radius: 10px;
  font-size: .88rem;
  color: var(--text);
}
.selected-bar .sel-text { display: flex; align-items: center; gap: 8px; }
.sel-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--blue); }

/* ---- Apply button ---- */
.btn-apply {
  background: var(--blue);
  color: #fff; border: none;
  padding: 9px 22px; border-radius: 10px;
  font-weight: 700; font-size: .92rem;
  font-family: inherit;
  cursor: pointer;
  transition: filter .15s, transform .1s;
  display: inline-flex; align-items: center; gap: 8px;
}
.btn-apply:hover  { filter: brightness(1.1); }
.btn-apply:active { transform: scale(.97); }

/* ---- Divider ---- */
.settings-divider {
  height: 1px; background: var(--card-border); margin: 8px 0 20px;
}

/* ========== Icon Color Picker ========== */
.ic-color-row {
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px;
}
.ic-color-left {
  display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0;
}
.ic-color-swatch {
  width: 52px; height: 52px; border-radius: 14px;
  border: 2px solid var(--card-border);
  flex-shrink: 0; transition: background .2s;
}
.ic-color-input-wrap {
  display: flex; flex-direction: column; gap: 4px;
}
.ic-color-input-wrap label {
  font-size: .78rem; font-weight: 600; color: var(--muted);
}
input[type="color"].ic-color-native {
  width: 52px; height: 36px;
  padding: 2px 3px;
  border: 2px solid var(--card-border);
  border-radius: 8px;
  cursor: pointer;
  background: var(--card-bg);
}
.ic-rgb-badge {
  font-size: .82rem; color: var(--muted); font-family: monospace;
  background: var(--nav-ic-bg); border-radius: 6px;
  padding: 5px 12px; white-space: nowrap;
  border: 1px solid var(--card-border);
}
.btn-ic-reset {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 9px 18px; border-radius: 10px;
  border: 1px solid var(--card-border);
  background: var(--card-bg); color: var(--text);
  font-family: inherit; font-size: .88rem;
  cursor: pointer; transition: border-color .15s, background .15s, color .15s;
  white-space: nowrap;
}
.btn-ic-reset:hover { border-color: var(--blue); background: var(--blue-50); color: var(--blue); }

/* Preset swatches */
.ic-presets-label { font-size: .82rem; font-weight: 600; color: var(--muted); margin-bottom: 10px; }
.ic-presets { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
.ic-preset {
  width: 34px; height: 34px; border-radius: 50%;
  cursor: pointer; border: 3px solid transparent;
  transition: transform .15s, border-color .15s, box-shadow .15s;
  flex-shrink: 0;
}
.ic-preset:hover { transform: scale(1.18); box-shadow: 0 3px 10px rgba(0,0,0,.2); }
.ic-preset.active {
  border-color: var(--text);
  transform: scale(1.1);
  box-shadow: 0 0 0 2px var(--card-bg), 0 0 0 4px var(--text);
}

/* Preview row */
.ic-preview-strip {
  display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
  padding: 18px 20px; background: var(--nav-ic-bg);
  border-radius: 12px; border: 1px solid var(--card-border);
}
.ic-preview-item {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
}
.ic-preview-item .msi { font-size: 2rem !important; transition: color .25s; }
.ic-preview-item span:last-child { font-size: .65rem; color: var(--muted); }
</style>
CSS;

require_once __DIR__ . '/partials/header.php';
?>

<!-- Page header -->
<div class="page-header">
  <div>
    <h1><span class="msi me-2" style="color:var(--blue)">tune</span>ตั้งค่าระบบ</h1>
    <p style="margin:4px 0 0; font-size:.88rem; color:var(--muted)">
      ปรับแต่งธีม ขนาดตัวอักษร และการแสดงผลของระบบ
    </p>
  </div>
</div>

<!-- ========== FONT SIZE ========== -->
<div class="settings-section">
  <div class="settings-section-head">
    <div class="settings-section-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
      <span class="msi">format_size</span>
    </div>
    <div>
      <div class="settings-section-title">ขนาดตัวอักษร</div>
      <div class="settings-section-desc">เลือกขนาดตัวอักษรที่เหมาะสมกับหน้าจอของคุณ</div>
    </div>
  </div>
  <div class="settings-section-body">
    <div class="fontsize-grid" id="fontsizeGrid">

      <button class="fontsize-btn" data-size="small" onclick="setFontSize('small')">
        <span class="fs-preview" style="font-size:1.1rem">ก</span>
        <span class="fs-label">เล็ก (14px)</span>
        <span class="fs-check"><span class="msi">check</span></span>
      </button>

      <button class="fontsize-btn" data-size="normal" onclick="setFontSize('normal')">
        <span class="fs-preview" style="font-size:1.4rem">ก</span>
        <span class="fs-label">ปกติ (16px)</span>
        <span class="fs-check"><span class="msi">check</span></span>
      </button>

      <button class="fontsize-btn" data-size="large" onclick="setFontSize('large')">
        <span class="fs-preview" style="font-size:1.7rem">ก</span>
        <span class="fs-label">ใหญ่ (18px)</span>
        <span class="fs-check"><span class="msi">check</span></span>
      </button>

      <button class="fontsize-btn" data-size="xlarge" onclick="setFontSize('xlarge')">
        <span class="fs-preview" style="font-size:2rem">ก</span>
        <span class="fs-label">ใหญ่มาก (20px)</span>
        <span class="fs-check"><span class="msi">check</span></span>
      </button>

    </div>

    <div style="margin-top:14px; font-size:.82rem; color:var(--muted)">
      <span class="msi me-1">info</span>
      ขนาดตัวอักษรที่เลือกจะมีผลทันทีและจดจำสำหรับเบราว์เซอร์นี้
    </div>
  </div>
</div>

<!-- ========== THEME ========== -->
<div class="settings-section">
  <div class="settings-section-head">
    <div class="settings-section-icon" style="background:linear-gradient(135deg,#818cf8,#4f46e5)">
      <span class="msi">palette</span>
    </div>
    <div>
      <div class="settings-section-title">ธีมระบบ</div>
      <div class="settings-section-desc">เลือกธีมสีที่ต้องการสำหรับการแสดงผลระบบ</div>
    </div>
  </div>
  <div class="settings-section-body">

    <div class="theme-grid" id="themeGrid">

      <!-- Light -->
      <div class="theme-card" data-theme="light" onclick="selectTheme('light')">
        <div class="theme-check"><span class="msi">check</span></div>
        <div class="theme-preview tp-light">
          <div class="tp-sidebar tp-sidebar">
            <div class="tp-nav-item tp-accent" style="background:#1d4ed8; width:70%"></div>
            <div class="tp-nav-item tp-line" style="background:#e2e8f0; width:55%"></div>
            <div class="tp-nav-item tp-line" style="background:#e2e8f0; width:65%"></div>
            <div class="tp-nav-item tp-line" style="background:#e2e8f0; width:50%"></div>
          </div>
          <div class="tp-topbar tp-topbar">
            <div style="font-size:5px; color:#334155; font-weight:700">Fall Risk Alert</div>
            <div class="tp-tb-circle" style="background:#1d4ed8; width:10px; height:10px; border-radius:50%"></div>
          </div>
          <div class="tp-content">
            <div class="tp-content-row wide" style="background:#1d4ed8; height:10px; border-radius:4px; opacity:.15"></div>
            <div class="tp-content-row med"  style="background:#64748b"></div>
            <div class="tp-content-row sm"   style="background:#64748b"></div>
          </div>
        </div>
        <div class="theme-label">
          <div class="theme-name">Light</div>
          <div class="theme-desc">สว่าง (มาตรฐาน)</div>
        </div>
      </div>

      <!-- Dark -->
      <div class="theme-card" data-theme="dark" onclick="selectTheme('dark')">
        <div class="theme-check"><span class="msi">check</span></div>
        <div class="theme-preview tp-dark">
          <div class="tp-sidebar" style="background:#1e293b; border-right:1px solid #334155">
            <div class="tp-nav-item" style="background:#60a5fa; width:70%; height:8px; border-radius:3px"></div>
            <div class="tp-nav-item" style="background:#334155; width:55%; height:8px; border-radius:3px"></div>
            <div class="tp-nav-item" style="background:#334155; width:65%; height:8px; border-radius:3px"></div>
            <div class="tp-nav-item" style="background:#334155; width:50%; height:8px; border-radius:3px"></div>
          </div>
          <div class="tp-topbar" style="background:#1e293b; border-bottom:1px solid #334155">
            <div style="font-size:5px; color:#94a3b8; font-weight:700">Fall Risk Alert</div>
            <div style="background:#60a5fa; width:10px; height:10px; border-radius:50%; margin-left:auto"></div>
          </div>
          <div class="tp-content">
            <div style="background:#60a5fa; height:10px; border-radius:4px; opacity:.2; width:80%"></div>
            <div style="background:#475569; height:8px; border-radius:4px; width:55%"></div>
            <div style="background:#475569; height:8px; border-radius:4px; width:35%"></div>
          </div>
        </div>
        <div class="theme-label">
          <div class="theme-name">Dark</div>
          <div class="theme-desc">มืด</div>
        </div>
      </div>

      <!-- Pastel -->
      <div class="theme-card" data-theme="pastel" onclick="selectTheme('pastel')">
        <div class="theme-check"><span class="msi">check</span></div>
        <div class="theme-preview tp-pastel">
          <div class="tp-sidebar" style="background:#fdf4ff; border-right:1px solid #e9d5ff">
            <div class="tp-nav-item" style="background:#7c3aed; width:70%; height:8px; border-radius:3px"></div>
            <div class="tp-nav-item" style="background:#e9d5ff; width:55%; height:8px; border-radius:3px"></div>
            <div class="tp-nav-item" style="background:#e9d5ff; width:65%; height:8px; border-radius:3px"></div>
            <div class="tp-nav-item" style="background:#e9d5ff; width:50%; height:8px; border-radius:3px"></div>
          </div>
          <div class="tp-topbar" style="background:#fff; border-bottom:1px solid #e9d5ff">
            <div style="font-size:5px; color:#6d28d9; font-weight:700">Fall Risk Alert</div>
            <div style="background:#7c3aed; width:10px; height:10px; border-radius:50%; margin-left:auto"></div>
          </div>
          <div class="tp-content">
            <div style="background:#7c3aed; height:10px; border-radius:4px; opacity:.15; width:80%"></div>
            <div style="background:#c4b5fd; height:8px; border-radius:4px; width:55%"></div>
            <div style="background:#c4b5fd; height:8px; border-radius:4px; width:35%"></div>
          </div>
        </div>
        <div class="theme-label">
          <div class="theme-name">Pastel</div>
          <div class="theme-desc">พาสเทล</div>
        </div>
      </div>

      <!-- Classic -->
      <div class="theme-card" data-theme="classic" onclick="selectTheme('classic')">
        <div class="theme-check"><span class="msi">check</span></div>
        <div class="theme-preview tp-classic">
          <div class="tp-sidebar" style="background:#fff; border-right:1px solid #a7f3d0">
            <div class="tp-nav-item" style="background:#059669; width:70%; height:8px; border-radius:3px"></div>
            <div class="tp-nav-item" style="background:#a7f3d0; width:55%; height:8px; border-radius:3px"></div>
            <div class="tp-nav-item" style="background:#a7f3d0; width:65%; height:8px; border-radius:3px"></div>
            <div class="tp-nav-item" style="background:#a7f3d0; width:50%; height:8px; border-radius:3px"></div>
          </div>
          <div class="tp-topbar" style="background:#fff; border-bottom:1px solid #a7f3d0">
            <div style="font-size:5px; color:#047857; font-weight:700">Fall Risk Alert</div>
            <div style="background:#059669; width:10px; height:10px; border-radius:50%; margin-left:auto"></div>
          </div>
          <div class="tp-content">
            <div style="background:#059669; height:10px; border-radius:4px; opacity:.15; width:80%"></div>
            <div style="background:#6ee7b7; height:8px; border-radius:4px; width:55%"></div>
            <div style="background:#6ee7b7; height:8px; border-radius:4px; width:35%"></div>
          </div>
        </div>
        <div class="theme-label">
          <div class="theme-name">Classic</div>
          <div class="theme-desc">คลาสสิก</div>
        </div>
      </div>

    </div><!-- /theme-grid -->

    <!-- Selected info + apply -->
    <div class="selected-bar" id="selectedBar">
      <div class="sel-text">
        <span class="sel-dot"></span>
        <span>ธีมที่เลือก: <strong id="selectedThemeLabel">Light (ธีมสว่างมาตรฐาน)</strong></span>
      </div>
      <button class="btn-apply" onclick="applyTheme()">
        <span class="msi">check</span> ใช้งานธีมนี้
      </button>
    </div>

  </div>
</div>

<!-- ========== ICON COLOR ========== -->
<div class="settings-section">
  <div class="settings-section-head">
    <div class="settings-section-icon" style="background:linear-gradient(135deg,#f43f5e,#ec4899)">
      <span class="msi">format_color_fill</span>
    </div>
    <div>
      <div class="settings-section-title">สีไอคอนระบบ</div>
      <div class="settings-section-desc">กำหนดสีของไอคอนทั้งระบบตามต้องการ · เลือก RGB ได้โดยตรง</div>
    </div>
  </div>
  <div class="settings-section-body">

    <!-- Picker row -->
    <div class="ic-color-row">
      <div class="ic-color-left">
        <div class="ic-color-swatch" id="icSwatch"></div>
        <div class="ic-color-input-wrap">
          <label for="icNative">เลือกสี (RGB)</label>
          <input type="color" class="ic-color-native" id="icNative" value="#1d4ed8">
        </div>
        <div class="ic-rgb-badge" id="icRgb">R:29&nbsp; G:78&nbsp; B:216</div>
      </div>
      <button class="btn-ic-reset" onclick="clearIconColor()">
        <span class="msi">restart_alt</span> รีเซ็ตค่าเริ่มต้น
      </button>
    </div>

    <!-- Presets -->
    <div class="ic-presets-label">สีที่แนะนำ</div>
    <div class="ic-presets" id="icPresets"></div>

    <!-- Preview -->
    <div class="ic-preview-strip" id="icPreviewStrip">
      <div class="ic-preview-item"><span class="msi">home</span><span>หน้าหลัก</span></div>
      <div class="ic-preview-item"><span class="msi">notifications</span><span>แจ้งเตือน</span></div>
      <div class="ic-preview-item"><span class="msi">person</span><span>ผู้ใช้</span></div>
      <div class="ic-preview-item"><span class="msi">stethoscope</span><span>จิตเวช</span></div>
      <div class="ic-preview-item"><span class="msi">falling</span><span>หกล้ม</span></div>
      <div class="ic-preview-item"><span class="msi">medication</span><span>ยา</span></div>
      <div class="ic-preview-item"><span class="msi">car_crash</span><span>พ.ร.บ.</span></div>
      <div class="ic-preview-item"><span class="msi">search</span><span>ค้นหา</span></div>
      <div class="ic-preview-item"><span class="msi">tune</span><span>ตั้งค่า</span></div>
    </div>

    <div style="margin-top:14px; font-size:.82rem; color:var(--muted)">
      <span class="msi me-1" style="font-size:1em">info</span>
      สีที่เลือกจะมีผลกับไอคอนทั้งระบบทันที และจดจำสำหรับเบราว์เซอร์นี้
    </div>

  </div>
</div>

<script>
/* ================================================================
   Settings — Theme & Font Size
   ================================================================ */
const THEME_LABELS = {
  light:   'Light (ธีมสว่างมาตรฐาน)',
  dark:    'Dark (ธีมมืด)',
  pastel:  'Pastel (ธีมพาสเทล)',
  classic: 'Classic (ธีมคลาสสิก)',
};
const FONTSIZE_LABELS = {
  small:  'เล็ก (14px)',
  normal: 'ปกติ (16px)',
  large:  'ใหญ่ (18px)',
  xlarge: 'ใหญ่มาก (20px)',
};

let pendingTheme    = localStorage.getItem('ckh-theme')    || 'light';
let currentFontSize = localStorage.getItem('ckh-fontsize') || 'normal';

/* ---- Init ---- */
document.addEventListener('DOMContentLoaded', function () {
  highlightTheme(pendingTheme);
  highlightFont(currentFontSize);
  updateSelectedBar();
});

/* ---- Font Size ---- */
function setFontSize(size) {
  currentFontSize = size;
  localStorage.setItem('ckh-fontsize', size);
  document.documentElement.setAttribute('data-fontsize', size);
  highlightFont(size);
  showToast('ขนาดตัวอักษร: ' + FONTSIZE_LABELS[size]);
}
function highlightFont(size) {
  document.querySelectorAll('#fontsizeGrid .fontsize-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.size === size);
  });
}

/* ---- Theme (select only, apply on button click) ---- */
function selectTheme(theme) {
  pendingTheme = theme;
  highlightTheme(theme);
  updateSelectedBar();
}
function highlightTheme(theme) {
  document.querySelectorAll('#themeGrid .theme-card').forEach(card => {
    card.classList.toggle('active', card.dataset.theme === theme);
  });
}
function updateSelectedBar() {
  document.getElementById('selectedThemeLabel').textContent = THEME_LABELS[pendingTheme] || pendingTheme;
}
function applyTheme() {
  localStorage.setItem('ckh-theme', pendingTheme);
  const h = document.documentElement;
  if (pendingTheme === 'light') {
    h.removeAttribute('data-theme');
  } else {
    h.setAttribute('data-theme', pendingTheme);
  }
  showToast('เปลี่ยนธีมเป็น ' + THEME_LABELS[pendingTheme], 'success');
}

/* ---- Toast ---- */
function showToast(msg, icon) {
  Swal.fire({
    toast: true,
    position: 'top-end',
    icon: icon || 'info',
    title: msg,
    showConfirmButton: false,
    timer: 2000,
    timerProgressBar: true,
    customClass: { popup: 'swal2-toast-custom' }
  });
}

/* ================================================================
   Icon Color Picker
   ================================================================ */
const IC_PRESETS = [
  { hex: '#1d4ed8', name: 'Blue' },
  { hex: '#2563eb', name: 'Blue 2' },
  { hex: '#059669', name: 'Green' },
  { hex: '#10b981', name: 'Emerald' },
  { hex: '#dc2626', name: 'Red' },
  { hex: '#d97706', name: 'Amber' },
  { hex: '#f59e0b', name: 'Yellow' },
  { hex: '#7c3aed', name: 'Purple' },
  { hex: '#6d28d9', name: 'Indigo' },
  { hex: '#0891b2', name: 'Teal' },
  { hex: '#db2777', name: 'Pink' },
  { hex: '#64748b', name: 'Slate' },
  { hex: '#334155', name: 'Dark Slate' },
  { hex: '#0f172a', name: 'Navy' },
  { hex: '#78350f', name: 'Brown' },
  { hex: '#065f46', name: 'Forest' },
];

function hexToRgbParts(hex) {
  return {
    r: parseInt(hex.slice(1,3), 16),
    g: parseInt(hex.slice(3,5), 16),
    b: parseInt(hex.slice(5,7), 16)
  };
}

function applyIconColor(hex, save) {
  const { r, g, b } = hexToRgbParts(hex);
  document.getElementById('icSwatch').style.background = hex;
  document.getElementById('icNative').value = hex;
  document.getElementById('icRgb').innerHTML =
    `R:<b>${r}</b>&nbsp; G:<b>${g}</b>&nbsp; B:<b>${b}</b>`;

  /* Apply preview icons color in strip only (not whole page icons yet) */
  document.querySelectorAll('#icPreviewStrip .msi').forEach(el => {
    el.style.color = hex;
  });

  /* Apply to whole page via CSS variable */
  document.documentElement.style.setProperty('--icon-color', hex);
  document.documentElement.setAttribute('data-iconcolor', '1');

  document.querySelectorAll('#icPresets .ic-preset').forEach(el => {
    el.classList.toggle('active', el.dataset.color === hex);
  });

  if (save !== false) {
    localStorage.setItem('ckh-icon-color', hex);
    showToast('ตั้งสีไอคอน · ' + hex.toUpperCase(), 'success');
  }
}

function clearIconColor() {
  localStorage.removeItem('ckh-icon-color');
  document.documentElement.style.removeProperty('--icon-color');
  document.documentElement.removeAttribute('data-iconcolor');
  document.getElementById('icSwatch').style.background = 'var(--blue)';
  document.getElementById('icRgb').innerHTML = 'ค่าเริ่มต้น (ตามธีม)';
  document.querySelectorAll('#icPreviewStrip .msi').forEach(el => el.style.color = '');
  document.querySelectorAll('#icPresets .ic-preset').forEach(el => el.classList.remove('active'));
  showToast('รีเซ็ตสีไอคอนแล้ว', 'info');
}

/* Build preset swatches */
document.addEventListener('DOMContentLoaded', function () {
  const container = document.getElementById('icPresets');
  IC_PRESETS.forEach(p => {
    const el = document.createElement('div');
    el.className   = 'ic-preset';
    el.style.background = p.hex;
    el.dataset.color    = p.hex;
    el.title            = p.name;
    el.addEventListener('click', () => applyIconColor(p.hex));
    container.appendChild(el);
  });

  /* Init from localStorage */
  const saved = localStorage.getItem('ckh-icon-color');
  if (saved) {
    applyIconColor(saved, false);
  } else {
    /* Show default blue in swatch/preview without saving */
    const def = '#1d4ed8';
    document.getElementById('icSwatch').style.background = def;
    const { r, g, b } = hexToRgbParts(def);
    document.getElementById('icRgb').innerHTML =
      `R:<b>${r}</b>&nbsp; G:<b>${g}</b>&nbsp; B:<b>${b}</b> &nbsp;<span style="opacity:.6">(ค่าเริ่มต้น)</span>`;
  }
});

/* Live update on native color input change */
document.getElementById('icNative').addEventListener('input', function () {
  applyIconColor(this.value);
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
