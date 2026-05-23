<?php
/**
 * HR-CENTER 4.0 Layout — partials/header.php
 *
 * Variables:
 *   $PAGE_TITLE — page title (also shown in topbar breadcrumb)
 *   $PAGE_KEY   — active nav key: home, fracture_dash, patient, drug, sexual,
 *                  accident, fracture, covid, dengue, lepto, scrub, pharm,
 *                  db_config, moph_keys
 *   $EXTRA_HEAD — extra HTML injected into <head>
 */
if (session_status() === PHP_SESSION_NONE) session_start();

$PAGE_TITLE = $PAGE_TITLE ?? 'Fall Risk Alert';
$PAGE_KEY   = $PAGE_KEY   ?? '';
$EXTRA_HEAD = $EXTRA_HEAD ?? '';

$displayName = $_SESSION['full_name']
    ?? ($_SESSION['user']['full_name'] ?? ($_SESSION['username'] ?? 'ผู้ใช้งาน'));
$position = $_SESSION['position'] ?? ($_SESSION['user']['position'] ?? 'เจ้าหน้าที่ระบบ');
$initials = mb_substr($displayName, 0, 1, 'UTF-8');

function ckh_active($key, $cur) { return $key === $cur ? ' active' : ''; }
function ckh_group_open($keys, $cur) { return in_array($cur, $keys, true) ? ' open' : ''; }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($PAGE_TITLE) ?> | Fall Risk Alert · รพ.เชียงกลาง</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Apply theme & font-size before render to prevent flash -->
<script>
(function(){
  var t  = localStorage.getItem('ckh-theme')      || 'light';
  var f  = localStorage.getItem('ckh-fontsize')   || 'normal';
  var ic = localStorage.getItem('ckh-icon-color');
  var h  = document.documentElement;
  if (t !== 'light') h.setAttribute('data-theme', t);
  if (f !== 'normal') h.setAttribute('data-fontsize', f);
  if (ic) { h.style.setProperty('--icon-color', ic); h.setAttribute('data-iconcolor','1'); }
})();
</script>

<style>
/* ===========================
   DESIGN SYSTEM — HR-CENTER 4.0
   =========================== */
:root {
  --sw: 260px;
  --th: 64px;
  /* ---- Accent ---- */
  --blue: #1d4ed8;
  --blue-50: #eff6ff;
  --blue-100: #dbeafe;
  --blue-600: #2563eb;
  --green: #059669;
  --red: #dc2626;
  --amber: #d97706;
  --indigo: #4f46e5;
  --teal: #0891b2;
  --purple: #7c3aed;
  /* ---- Layout ---- */
  --page-bg: #f8fafc;
  --sidebar-bg: #ffffff;
  --topbar-bg: #ffffff;
  --border: #e2e8f0;
  --text: #0f172a;
  --muted: #64748b;
  --section-lbl: #94a3b8;
  /* ---- Component ---- */
  --card-bg: #ffffff;
  --card-border: #e2e8f0;
  --nav-ic-bg: #f1f5f9;
  --th-head-bg: #f8fafc;
  --input-bg: #ffffff;
  --card-shadow: 0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.05);
  --card-shadow-hover: 0 4px 16px rgba(0,0,0,.10);
  /* ---- Font size (overridden by data-fontsize) ---- */
  --fs: 16px;
}

/* ===== THEME: DARK ===== */
html[data-theme="dark"] {
  --blue: #60a5fa;
  --blue-50: rgba(96,165,250,.13);
  --blue-100: rgba(96,165,250,.22);
  --page-bg: #0f172a;
  --sidebar-bg: #1e293b;
  --topbar-bg: #1e293b;
  --border: #334155;
  --text: #f1f5f9;
  --muted: #94a3b8;
  --section-lbl: #475569;
  --card-bg: #1e293b;
  --card-border: #334155;
  --nav-ic-bg: rgba(255,255,255,.08);
  --th-head-bg: #0f172a;
  --input-bg: #0f172a;
  --card-shadow: 0 1px 3px rgba(0,0,0,.3), 0 1px 2px rgba(0,0,0,.2);
  --card-shadow-hover: 0 4px 16px rgba(0,0,0,.4);
}

/* ===== THEME: PASTEL ===== */
html[data-theme="pastel"] {
  --blue: #7c3aed;
  --blue-50: #f5f3ff;
  --blue-100: #ede9fe;
  --page-bg: #faf5ff;
  --sidebar-bg: #fdf4ff;
  --topbar-bg: #ffffff;
  --border: #e9d5ff;
  --text: #1e1b4b;
  --muted: #6d28d9;
  --section-lbl: #a78bfa;
  --card-bg: #ffffff;
  --card-border: #e9d5ff;
  --nav-ic-bg: #faf5ff;
  --th-head-bg: #faf5ff;
  --input-bg: #ffffff;
}

/* ===== THEME: CLASSIC ===== */
html[data-theme="classic"] {
  --blue: #059669;
  --blue-50: #f0fdf4;
  --blue-100: #dcfce7;
  --page-bg: #f0fdf4;
  --sidebar-bg: #ffffff;
  --topbar-bg: #ffffff;
  --border: #a7f3d0;
  --text: #064e3b;
  --muted: #047857;
  --section-lbl: #6ee7b7;
  --card-bg: #ffffff;
  --card-border: #a7f3d0;
  --nav-ic-bg: #ecfdf5;
  --th-head-bg: #f0fdf4;
  --input-bg: #ffffff;
}

/* ===== FONT SIZES ===== */
/* Must set font-size on html so Bootstrap rem units scale with it */
html[data-fontsize="small"]  { font-size: 14px; --fs: 14px; }
html[data-fontsize="normal"] { font-size: 16px; --fs: 16px; }
html[data-fontsize="large"]  { font-size: 18px; --fs: 18px; }
html[data-fontsize="xlarge"] { font-size: 20px; --fs: 20px; }

/* ===== MATERIAL SYMBOLS ===== */
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
.msi-2x { font-size: 2em; }
@keyframes msi-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.msi-spin { animation: msi-spin .8s linear infinite; display: inline-block; }
/* Icon color override — applied when user sets a color in settings */
html[data-iconcolor] .msi { color: var(--icon-color) !important; }

*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; padding: 0; }
body {
  font-family: "Kanit", system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-size: var(--fs, 16px);
  font-weight: 300;
  color: var(--text);
  background: var(--page-bg);
  -webkit-font-smoothing: antialiased;
  transition: background .25s, color .25s;
}
h1, h2, h3, h4, h5, h6 { font-weight: 700; }

/* ---------- SCROLLBAR (Webkit) ---------- */
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

/* ===========================
   SIDEBAR
   =========================== */
#ckh-sidebar {
  position: fixed;
  top: 0; left: 0; bottom: 0;
  width: var(--sw);
  background: var(--sidebar-bg);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  z-index: 1040;
  overflow-y: auto;
  overflow-x: hidden;
  transition: transform .3s cubic-bezier(.4,0,.2,1);
}

/* Brand area */
.sidebar-brand {
  padding: 14px 16px 12px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.brand-link {
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
  color: inherit;
}
.brand-logo {
  width: 40px; height: 40px;
  border-radius: 10px;
  object-fit: cover;
  flex-shrink: 0;
}
.brand-logo-ic {
  width: 40px; height: 40px;
  border-radius: 10px;
  background: linear-gradient(135deg, #1d4ed8 0%, #10b981 100%);
  display: grid; place-items: center;
  color: #fff; font-size: 1.1rem;
  flex-shrink: 0;
}
.brand-title { font-weight: 700; font-size: .93rem; color: var(--text); line-height: 1.2; }
.brand-sub   { font-size: .72rem; color: var(--muted); }

/* Nav area */
.sidebar-nav { flex: 1; padding: 10px 8px 8px; overflow-y: auto; }

.nav-section-label {
  padding: 14px 8px 4px;
  font-size: .68rem;
  font-weight: 600;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--section-lbl);
  user-select: none;
}
.nav-section-label:first-child { padding-top: 4px; }

a.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 8px;
  color: var(--text);
  text-decoration: none;
  font-size: .92rem;
  font-weight: 500;
  transition: background .15s, color .15s;
  margin-bottom: 2px;
  border-left: 3px solid transparent;
}
a.nav-item:hover {
  background: var(--blue-50);
  color: var(--blue);
}
a.nav-item.active {
  background: var(--blue-50);
  color: var(--blue);
  border-left-color: var(--blue);
  font-weight: 600;
}
a.nav-item.active .nav-ic,
a.nav-item:hover .nav-ic {
  background: var(--blue-100);
}

.nav-ic {
  width: 28px; height: 28px;
  border-radius: 7px;
  display: grid; place-items: center;
  font-size: .82rem;
  flex-shrink: 0;
  background: var(--nav-ic-bg);
  transition: background .15s;
}

/* Sidebar footer */
.sidebar-footer {
  padding: 8px;
  border-top: 1px solid var(--border);
  flex-shrink: 0;
}
a.nav-item.logout-item {
  color: #dc2626;
}
a.nav-item.logout-item:hover {
  background: #fef2f2;
  color: #dc2626;
}
a.nav-item.logout-item .nav-ic {
  color: #dc2626;
  background: #fee2e2;
}
a.nav-item.logout-item:hover .nav-ic {
  background: #fee2e2;
}

/* ===========================
   OVERLAY (mobile)
   =========================== */
#sidebarOverlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.45);
  z-index: 1035;
  cursor: pointer;
}

/* ===========================
   TOPBAR
   =========================== */
#ckh-topbar {
  position: fixed;
  top: 0; left: var(--sw); right: 0;
  height: var(--th);
  background: var(--topbar-bg);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  padding: 0 20px;
  gap: 10px;
  z-index: 1030;
  transition: left .3s cubic-bezier(.4,0,.2,1);
}
.topbar-toggle {
  width: 36px; height: 36px;
  border-radius: 8px;
  border: none;
  background: transparent;
  color: var(--muted);
  display: grid; place-items: center;
  cursor: pointer;
  font-size: 1rem;
  transition: background .15s, color .15s;
  flex-shrink: 0;
}
.topbar-toggle:hover { background: var(--blue-50); color: var(--blue); }

.topbar-breadcrumb {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: .82rem;
  color: var(--muted);
}
.topbar-breadcrumb a { color: var(--muted); text-decoration: none; }
.topbar-breadcrumb a:hover { color: var(--blue); }
.topbar-breadcrumb .sep { color: #cbd5e1; }
.topbar-breadcrumb .current { color: var(--text); font-weight: 600; }

.topbar-right {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 6px;
}
.topbar-datetime { text-align: right; padding-right: 4px; line-height: 1.3; }
.topbar-date { font-size: .8rem; font-weight: 600; color: var(--blue); }
.topbar-time { font-size: .73rem; color: var(--muted); }

.topbar-btn {
  width: 36px; height: 36px;
  border-radius: 8px;
  border: none; background: transparent;
  color: var(--muted);
  display: grid; place-items: center;
  cursor: pointer; font-size: 1rem;
  transition: background .15s, color .15s;
  position: relative;
}
.topbar-btn:hover { background: var(--blue-50); color: var(--blue); }

.topbar-user {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 10px 5px 6px;
  border-radius: 10px;
  cursor: pointer;
  transition: background .15s;
  border: 1px solid transparent;
}
.topbar-user:hover { background: var(--blue-50); border-color: var(--blue-100); }

.user-av {
  width: 34px; height: 34px;
  border-radius: 50%;
  background: linear-gradient(135deg, #1d4ed8, #10b981);
  color: #fff;
  display: grid; place-items: center;
  font-weight: 700; font-size: .9rem;
  flex-shrink: 0;
}
.user-av-img {
  width: 34px; height: 34px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
}
.user-name-label { font-weight: 600; font-size: .85rem; color: var(--text); line-height: 1.2; }
.user-role-label {
  font-size: .72rem; color: var(--muted);
  display: flex; align-items: center; gap: 4px;
}
.role-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #22c55e; display: inline-block;
}

/* ===========================
   MAIN WRAPPER
   =========================== */
#ckh-main {
  margin-left: var(--sw);
  transition: margin-left .3s cubic-bezier(.4,0,.2,1);
}
#ckh-content {
  padding-top: var(--th);
  min-height: 100vh;
}
.content-inner { padding: 24px 24px 40px; }

/* ===========================
   SHARED COMPONENTS
   =========================== */

/* KPI cards */
.kpi-card {
  background: var(--card-bg);
  border: 1px solid var(--card-border);
  border-radius: 12px;
  padding: 16px 18px;
  display: flex; gap: 14px; align-items: center;
  box-shadow: var(--card-shadow);
  transition: box-shadow .2s, background .25s;
}
.kpi-card:hover { box-shadow: var(--card-shadow-hover); }
.kpi-icon {
  width: 48px; height: 48px; border-radius: 12px;
  display: grid; place-items: center;
  font-size: 1.2rem; color: #fff; flex-shrink: 0;
}
.kpi-icon.bg-slate  { background: linear-gradient(135deg,#64748b,#334155); }
.kpi-icon.bg-amber  { background: linear-gradient(135deg,#f59e0b,#d97706); }
.kpi-icon.bg-green  { background: linear-gradient(135deg,#10b981,#059669); }
.kpi-icon.bg-red    { background: linear-gradient(135deg,#ef4444,#dc2626); }
.kpi-icon.bg-blue   { background: linear-gradient(135deg,#3b82f6,#1d4ed8); }
.kpi-icon.bg-indigo { background: linear-gradient(135deg,#818cf8,#4f46e5); }
.kpi-icon.bg-teal   { background: linear-gradient(135deg,#22d3ee,#0891b2); }
.kpi-label { color: #6c757d; font-size: .78rem; margin: 0; }
.kpi-value { font-size: 1.45rem; font-weight: 700; line-height: 1.1; margin: 0; }

/* Status badges */
.status-badge {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .22rem .6rem; border-radius: 999px;
  font-size: .76rem; font-weight: 600;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-ok      { background: #dcfce7; color: #166534; }
.status-fail    { background: #fee2e2; color: #991b1b; }

/* Page header */
.page-header, .page-header-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: .75rem; margin-bottom: 1.25rem; flex-wrap: wrap;
}
.page-header h1, .page-header-row h1 {
  font-size: 1.3rem; font-weight: 700; margin: 0; color: var(--text);
}

/* Cards */
.card {
  border: 1px solid var(--card-border);
  border-radius: 12px;
  background: var(--card-bg);
  box-shadow: var(--card-shadow);
  transition: background .25s, border-color .25s;
}
.card-header {
  background: transparent;
  border-bottom: 1px solid var(--card-border);
  padding: 14px 18px;
  font-weight: 600;
  color: var(--text);
  border-radius: 12px 12px 0 0 !important;
}

/* Tables */
.table thead th {
  white-space: nowrap;
  font-size: .8rem; color: var(--muted);
  background: var(--th-head-bg);
  border-bottom: 1px solid var(--border);
  font-weight: 600;
}
.table td { font-size: .86rem; vertical-align: middle; color: var(--text); }
.table { --bs-table-bg: transparent; color: var(--text); }

/* ===========================
   RESPONSIVE
   =========================== */
@media (max-width: 991.98px) {
  #ckh-sidebar {
    transform: translateX(calc(-1 * var(--sw)));
  }
  #ckh-sidebar.open {
    transform: translateX(0);
    box-shadow: 8px 0 30px rgba(0,0,0,.12);
  }
  #sidebarOverlay.show { display: block; }
  #ckh-main { margin-left: 0; }
  #ckh-topbar { left: 0; }
  .content-inner { padding: 16px 16px 32px; }
  .topbar-datetime { display: none; }
  .user-name-label, .user-role-label { display: none; }
  .topbar-user { padding: 5px; }
}

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition: none !important; animation: none !important; }
}
</style>
<?= $EXTRA_HEAD ?>
</head>
<body>

<!-- ===== SIDEBAR OVERLAY (mobile) ===== -->
<div id="sidebarOverlay" onclick="ckhCloseSidebar()" aria-hidden="true"></div>

<!-- ===== SIDEBAR ===== -->
<aside id="ckh-sidebar" aria-label="เมนูหลัก">

  <!-- Brand -->
  <div class="sidebar-brand">
    <a href="index.php" class="brand-link">
      <img src="img/Logo_CKHospital.png" alt="Logo" class="brand-logo"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='grid'">
      <div class="brand-logo-ic" style="display:none"><span class="msi">monitor_heart</span></div>
      <div>
        <div class="brand-title">Fall Risk Alert</div>
        <div class="brand-sub">รพ.เชียงกลาง</div>
      </div>
    </a>
  </div>

  <!-- Nav -->
  <nav class="sidebar-nav">

    <!-- เมนูหลัก -->
    <div class="nav-section-label">เมนูหลัก</div>

    <a href="index.php" class="nav-item<?= ckh_active('home', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#1d4ed8"><span class="msi">home</span></span>
      <span>หน้าหลัก</span>
    </a>
    <a href="fracture_dashboard.php" class="nav-item<?= ckh_active('fracture_dash', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#7c3aed"><span class="msi">show_chart</span></span>
      <span>Dashboard</span>
    </a>

    <!-- กลุ่มคนไข้ -->
    <div class="nav-section-label">กลุ่มคนไข้</div>

    <a href="patient.php" class="nav-item<?= ckh_active('patient', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#dc2626"><span class="msi">stethoscope</span></span>
      <span>กลุ่มเสี่ยงจิตเวช</span>
    </a>
    <a href="drugitems01.php" class="nav-item<?= ckh_active('drug', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#ea580c"><span class="msi">medical_services</span></span>
      <span>กลุ่มเสี่ยงยาอันตราย</span>
    </a>
    <a href="sexual.php" class="nav-item<?= ckh_active('sexual', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#db2777"><span class="msi">shield_person</span></span>
      <span>ผู้ถูกข่มขืน / ทำร้าย</span>
    </a>
    <a href="accident_queue_ui.php" class="nav-item<?= ckh_active('accident', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#d97706"><span class="msi">car_crash</span></span>
      <span>คนไข้ พ.ร.บ.</span>
    </a>
    <a href="fracture_queue_ui.php" class="nav-item<?= ckh_active('fracture', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#059669"><span class="msi">falling</span></span>
      <span>พลัดตก / หกล้ม</span>
    </a>

    <!-- เฝ้าระวังโรคติดต่อ -->
    <div class="nav-section-label">เฝ้าระวังโรคติดต่อ</div>

    <a href="covid.php" class="nav-item<?= ckh_active('covid', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#ea580c"><span class="msi">coronavirus</span></span>
      <span>Covid-19</span>
    </a>
    <a href="dengue.php" class="nav-item<?= ckh_active('dengue', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#1d4ed8"><span class="msi">pest_control</span></span>
      <span>ไข้เลือดออก</span>
    </a>
    <a href="Leptospira.php" class="nav-item<?= ckh_active('lepto', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#0891b2"><span class="msi">pets</span></span>
      <span>เลปโตสไปโรสิส</span>
    </a>
    <a href="scrubtyphus.php" class="nav-item<?= ckh_active('scrub', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#0891b2"><span class="msi">bug_report</span></span>
      <span>สครับไทฟัส</span>
    </a>

    <!-- งานสนับสนุน -->
    <div class="nav-section-label">งานสนับสนุน</div>

    <a href="pharm_lab_queue_ui.php" class="nav-item<?= ckh_active('pharm', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#0891b2"><span class="msi">medication</span></span>
      <span>งานเภสัชกรรม</span>
    </a>

    <!-- ตั้งค่าระบบ -->
    <div class="nav-section-label">ตั้งค่าระบบ</div>

    <a href="settings.php" class="nav-item<?= ckh_active('settings', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#7c3aed"><span class="msi">tune</span></span>
      <span>ตั้งค่าระบบ</span>
    </a>
    <a href="db_config_admin.php" class="nav-item<?= ckh_active('db_config', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#64748b"><span class="msi">storage</span></span>
      <span>ตั้งค่าฐานข้อมูล</span>
    </a>
    <a href="moph_keys_admin.php" class="nav-item<?= ckh_active('moph_keys', $PAGE_KEY) ?>">
      <span class="nav-ic" style="color:#64748b"><span class="msi">key</span></span>
      <span>MOPH Keys</span>
    </a>

  </nav>

  <!-- Sidebar footer: logout -->
  <div class="sidebar-footer">
    <a href="#" id="logoutBtn" class="nav-item logout-item">
      <span class="nav-ic"><span class="msi">logout</span></span>
      <span>ออกจากระบบ</span>
    </a>
  </div>

</aside>

<!-- ===== MAIN WRAPPER ===== -->
<div id="ckh-main">

  <!-- ===== TOPBAR ===== -->
  <header id="ckh-topbar" role="banner">

    <button class="topbar-toggle" onclick="ckhToggleSidebar()" aria-label="เปิด/ปิดเมนู">
      <span class="msi">menu</span>
    </button>

    <nav class="topbar-breadcrumb d-none d-md-flex" aria-label="breadcrumb">
      <a href="index.php"><span class="msi me-1">home</span>หน้าหลัก</a>
      <?php if ($PAGE_KEY !== 'home'): ?>
        <span class="sep"><span class="msi">chevron_right</span></span>
        <span class="current"><?= htmlspecialchars($PAGE_TITLE) ?></span>
      <?php endif; ?>
    </nav>

    <div class="topbar-right">

      <!-- Date & Time -->
      <div class="topbar-datetime d-none d-sm-block">
        <div class="topbar-date" id="tbDate">—</div>
        <div class="topbar-time" id="tbTime">—</div>
      </div>

      <!-- Notification -->
      <button class="topbar-btn" aria-label="การแจ้งเตือน" title="การแจ้งเตือน">
        <span class="msi">notifications</span>
      </button>

      <!-- User -->
      <div class="topbar-user" id="topbarUserBtn" role="button" tabindex="0"
           aria-label="เมนูผู้ใช้">
        <div class="user-av"><?= htmlspecialchars($initials) ?></div>
        <div class="d-none d-md-block">
          <div class="user-name-label"><?= htmlspecialchars($displayName) ?></div>
          <div class="user-role-label">
            <span class="role-dot"></span>
            <?= htmlspecialchars($position ?: 'เจ้าหน้าที่ระบบ') ?>
          </div>
        </div>
        <span class="msi ms-1 d-none d-md-block"
           style="font-size:.65rem; color:var(--muted)">expand_more</span>
      </div>

    </div>
  </header>

  <!-- ===== PAGE CONTENT ===== -->
  <main id="ckh-content">
    <div class="content-inner">
