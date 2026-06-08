# UX/UI Template Reference — HR-CENTER 4.0
> Design System ของระบบ MedAlert — นำไปใช้กับโปรเจกต์อื่นได้เลย

---

## สารบัญ

1. [Stack & Dependencies](#1-stack--dependencies)
2. [Design Tokens (CSS Variables)](#2-design-tokens-css-variables)
3. [Theme System](#3-theme-system)
4. [Layout Structure](#4-layout-structure)
5. [Typography & Icons](#5-typography--icons)
6. [Components](#6-components)
7. [Page Template (PHP)](#7-page-template-php)
8. [JavaScript Patterns](#8-javascript-patterns)
9. [LINE Flex Message Design](#9-line-flex-message-design)
10. [Queue UI Page Template](#10-queue-ui-page-template)

---

## 1. Stack & Dependencies

```html
<!-- Bootstrap 5.3 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Google Fonts: Kanit (Thai) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<!-- Material Symbols Outlined (Icons) -->
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- DataTables (ถ้าต้องการตาราง) -->
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
```

---

## 2. Design Tokens (CSS Variables)

วาง `:root` นี้ใน `<style>` ก่อน render:

```css
:root {
  /* ---- Layout ---- */
  --sw: 260px;          /* sidebar width */
  --th: 64px;           /* topbar height */

  /* ---- Accent Colors ---- */
  --blue:     #1d4ed8;
  --blue-50:  #eff6ff;
  --blue-100: #dbeafe;
  --blue-600: #2563eb;
  --green:    #059669;
  --red:      #dc2626;
  --amber:    #d97706;
  --indigo:   #4f46e5;
  --teal:     #0891b2;
  --purple:   #7c3aed;

  /* ---- Layout Colors (Light theme default) ---- */
  --page-bg:    #f8fafc;
  --sidebar-bg: #ffffff;
  --topbar-bg:  #ffffff;
  --border:     #e2e8f0;
  --text:       #0f172a;
  --muted:      #64748b;
  --section-lbl:#94a3b8;

  /* ---- Component Colors ---- */
  --card-bg:          #ffffff;
  --card-border:      #e2e8f0;
  --nav-ic-bg:        #f1f5f9;
  --th-head-bg:       #f8fafc;
  --input-bg:         #ffffff;
  --card-shadow:      0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.05);
  --card-shadow-hover:0 4px 16px rgba(0,0,0,.10);

  /* ---- Font Size ---- */
  --fs: 16px;
}
```

---

## 3. Theme System

ระบบรองรับ 4 theme เปลี่ยนผ่าน `data-theme` บน `<html>`

```js
// บันทึกธีมใน localStorage
localStorage.setItem('ckh-theme', 'dark'); // light | dark | pastel | classic
document.documentElement.setAttribute('data-theme', 'dark');
```

### ตัวแปรที่ต้องกำหนดในแต่ละ Theme

```css
/* DARK */
html[data-theme="dark"] {
  --blue: #60a5fa;
  --page-bg: #0f172a;
  --sidebar-bg: #1e293b;
  --topbar-bg: #1e293b;
  --border: #334155;
  --text: #f1f5f9;
  --muted: #94a3b8;
  --card-bg: #1e293b;
  --card-border: #334155;
  --th-head-bg: #0f172a;
  --input-bg: #0f172a;
}

/* PASTEL (Purple) */
html[data-theme="pastel"] {
  --blue: #7c3aed;
  --page-bg: #faf5ff;
  --sidebar-bg: #fdf4ff;
  --border: #e9d5ff;
  --text: #1e1b4b;
  --card-bg: #ffffff;
}

/* CLASSIC (Green) */
html[data-theme="classic"] {
  --blue: #059669;
  --page-bg: #f0fdf4;
  --sidebar-bg: #ffffff;
  --border: #a7f3d0;
  --text: #064e3b;
  --card-bg: #ffffff;
}
```

### Font Size System

```css
html[data-fontsize="small"]  { font-size: 14px; }
html[data-fontsize="normal"] { font-size: 16px; }
html[data-fontsize="large"]  { font-size: 18px; }
html[data-fontsize="xlarge"] { font-size: 20px; }
```

```js
// บันทึก font size
localStorage.setItem('ckh-fontsize', 'large');
document.documentElement.setAttribute('data-fontsize', 'large');
```

### Anti-Flash Script (ใส่ใน `<head>` ก่อน CSS)

```html
<script>
(function(){
  var t  = localStorage.getItem('ckh-theme')    || 'light';
  var f  = localStorage.getItem('ckh-fontsize') || 'normal';
  var ic = localStorage.getItem('ckh-icon-color');
  var h  = document.documentElement;
  if (t !== 'light') h.setAttribute('data-theme', t);
  if (f !== 'normal') h.setAttribute('data-fontsize', f);
  if (ic) { h.style.setProperty('--icon-color', ic); h.setAttribute('data-iconcolor','1'); }
})();
</script>
```

---

## 4. Layout Structure

```
┌─────────────────────────────────────┐
│  #ckh-sidebar  (260px, fixed left)  │   ← aside
│  ┌─────────────────────────────┐    │
│  │ sidebar-brand               │    │
│  ├─────────────────────────────┤    │
│  │ sidebar-nav                 │    │
│  │  nav-section-label          │    │
│  │  a.nav-item  (.active)      │    │
│  ├─────────────────────────────┤    │
│  │ sidebar-footer (logout)     │    │
│  └─────────────────────────────┘    │
├─────────────────────────────────────┤
│  #ckh-main  (margin-left: 260px)    │
│  ┌─────────────────────────────┐    │
│  │ #ckh-topbar  (64px, fixed)  │    │   ← header
│  │  topbar-toggle | breadcrumb │    │
│  │  topbar-right: datetime+user│    │
│  ├─────────────────────────────┤    │
│  │ #ckh-content                │    │
│  │  .content-inner             │    │   ← main
│  │    PAGE CONTENT HERE        │    │
│  └─────────────────────────────┘    │
└─────────────────────────────────────┘
```

### HTML Skeleton

```html
<body>
<div id="sidebarOverlay" onclick="ckhCloseSidebar()"></div>

<aside id="ckh-sidebar">
  <div class="sidebar-brand"> ... </div>
  <nav class="sidebar-nav">
    <div class="nav-section-label">หมวดหมู่</div>
    <a href="#" class="nav-item active">
      <span class="nav-ic"><span class="msi">home</span></span>
      <span>หน้าหลัก</span>
    </a>
  </nav>
  <div class="sidebar-footer"> ... </div>
</aside>

<div id="ckh-main">
  <header id="ckh-topbar"> ... </header>
  <main id="ckh-content">
    <div class="content-inner">
      <!-- เนื้อหาหน้าที่นี่ -->
    </div>
  </main>
</div>
</body>
```

---

## 5. Typography & Icons

### Font: Kanit

```css
body {
  font-family: "Kanit", system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-weight: 300;  /* ตัวบางเป็น default */
}
h1, h2, h3, h4, h5, h6 { font-weight: 700; }
```

### Material Symbols Outlined

```css
.msi {
  font-family: 'Material Symbols Outlined';
  font-size: 1.15em;
  vertical-align: -0.2em;
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
  user-select: none;
}
.msi-o   { font-variation-settings: 'FILL' 0, 'wght' 300, 'GRAD' 0, 'opsz' 20; }  /* outline */
.msi-2x  { font-size: 2em; }
.msi-spin { animation: msi-spin .8s linear infinite; }  /* loading */
```

**การใช้งาน:**
```html
<!-- ชื่อ icon ดูได้ที่ https://fonts.google.com/icons -->
<span class="msi">home</span>
<span class="msi">check_circle</span>
<span class="msi">warning</span>
<span class="msi msi-o">info</span>          <!-- outline style -->
<span class="msi msi-spin">sync</span>       <!-- loading spinner -->
<span class="msi msi-2x">favorite</span>     <!-- 2x size -->
```

**Icon สีตามบริบท:**
```html
<span class="msi text-primary">info</span>
<span class="msi text-success">check_circle</span>
<span class="msi text-danger">error</span>
<span class="msi text-warning">warning</span>
<span class="msi text-muted">schedule</span>
```

---

## 6. Components

### 6.1 KPI Cards

```html
<div class="row g-3 mb-3">
  <!-- Slate -->
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-slate"><span class="msi">list</span></div>
      <div>
        <p class="kpi-label">ทั้งหมด</p>
        <p class="kpi-value">41</p>
      </div>
    </div>
  </div>
  <!-- Amber (warning) -->
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-amber"><span class="msi">schedule</span></div>
      <div>
        <p class="kpi-label">ค้างส่ง</p>
        <p class="kpi-value text-warning">5</p>
      </div>
    </div>
  </div>
  <!-- Green (success) -->
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-green"><span class="msi">check</span></div>
      <div>
        <p class="kpi-label">ส่งสำเร็จ</p>
        <p class="kpi-value text-success">36</p>
      </div>
    </div>
  </div>
  <!-- Blue -->
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-blue"><span class="msi">person</span></div>
      <div>
        <p class="kpi-label">วันนี้</p>
        <p class="kpi-value text-primary">3</p>
      </div>
    </div>
  </div>
</div>
```

**KPI icon color variants:** `bg-slate` `bg-amber` `bg-green` `bg-red` `bg-blue` `bg-indigo` `bg-teal`

```css
.kpi-card {
  background: var(--card-bg);
  border: 1px solid var(--card-border);
  border-radius: 12px;
  padding: 16px 18px;
  display: flex; gap: 14px; align-items: center;
  box-shadow: var(--card-shadow);
}
.kpi-icon {
  width: 48px; height: 48px; border-radius: 12px;
  display: grid; place-items: center;
  color: #fff; font-size: 1.2rem; flex-shrink: 0;
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
```

---

### 6.2 Status Badges

```html
<span class="status-badge status-ok">
  <span class="msi">check</span> ส่งแล้ว
</span>

<span class="status-badge status-pending">
  <span class="msi">schedule</span> ค้างส่ง
</span>

<span class="status-badge status-fail">
  <span class="msi">close</span> ล้มเหลว
</span>
```

```css
.status-badge {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .22rem .6rem; border-radius: 999px;
  font-size: .76rem; font-weight: 600;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-ok      { background: #dcfce7; color: #166534; }
.status-fail    { background: #fee2e2; color: #991b1b; }
```

---

### 6.3 Page Header

```html
<div class="page-header">
  <h1>
    <span class="msi text-primary me-2">medication</span>
    ชื่อหน้า
  </h1>
  <div class="d-flex gap-2">
    <button class="btn btn-primary btn-sm">
      <span class="msi me-1">add</span> เพิ่มรายการ
    </button>
    <button class="btn btn-outline-secondary btn-sm">
      <span class="msi me-1">download</span> Export
    </button>
  </div>
</div>
```

```css
.page-header {
  display: flex; align-items: center; justify-content: space-between;
  gap: .75rem; margin-bottom: 1.25rem; flex-wrap: wrap;
}
.page-header h1 {
  font-size: 1.3rem; font-weight: 700; margin: 0;
}
```

---

### 6.4 Flash Alert (POST-Redirect-GET)

```php
<?php if ($flash):
  [$ft, $fm] = explode(':', $flash, 2) + ['info',''];
  $map  = ['success'=>'alert-success','info'=>'alert-info','warning'=>'alert-warning','danger'=>'alert-danger'];
  $icon = ['success'=>'check_circle','info'=>'info','warning'=>'warning','danger'=>'error'];
?>
<div class="alert <?= $map[$ft]??'alert-info' ?> d-flex align-items-center gap-2 mb-3"
     style="border-radius:10px; font-size:.9rem">
  <span class="msi"><?= $icon[$ft]??'info' ?></span>
  <?= htmlspecialchars($fm) ?>
</div>
<?php endif; ?>
```

```php
// สร้าง flash message
$msg = match($_GET['msg']) {
  'sendnow'   => "success:ส่งสำเร็จ {$ok} รายการ",
  'requeued'  => "info:Requeue สำเร็จ {$aff} รายการ",
  'no_ids'    => "warning:ยังไม่ได้เลือกรายการ",
  'err'       => "danger:เกิดข้อผิดพลาด: ".htmlspecialchars($_GET['detail']??''),
  default     => '',
};
```

---

### 6.5 Filter Card

```html
<div class="card filter-card">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-sm-6 col-md-3">
      <label class="form-label" for="f-start">ตั้งแต่วันที่</label>
      <input type="date" id="f-start" class="form-control" name="start" value="<?= $start ?>">
    </div>
    <div class="col-sm-6 col-md-3">
      <label class="form-label" for="f-end">ถึงวันที่</label>
      <input type="date" id="f-end" class="form-control" name="end" value="<?= $end ?>">
    </div>
    <div class="col-sm-6 col-md-2">
      <label class="form-label" for="f-status">สถานะ</label>
      <select id="f-status" class="form-select" name="status">
        <option value="all">ทั้งหมด</option>
        <option value="0">ค้างส่ง</option>
        <option value="1">ส่งแล้ว</option>
      </select>
    </div>
    <div class="col-sm-12 col-md-2 d-flex gap-2">
      <button class="btn btn-primary flex-grow-1">
        <span class="msi me-1">search</span> ค้นหา
      </button>
      <a class="btn btn-outline-secondary" href="?" title="รีเซ็ต">
        <span class="msi">undo</span>
      </a>
    </div>
  </form>
</div>
```

```css
.filter-card { padding: 1rem 1.15rem; margin-bottom: 1rem; }
.filter-card label { font-size: .82rem; color: #64748b; margin-bottom: .25rem; }
```

---

### 6.6 Sticky Bulk Action Bar

```html
<div class="action-bar" id="actionBar">
  <span class="selected-count" id="selectedCount">เลือก 0 รายการ</span>
  <button type="submit" name="action" value="send_now" class="btn btn-primary btn-sm">
    <span class="msi me-1">send</span> ส่งทันที
  </button>
  <button type="submit" name="action" value="requeue"
          class="btn btn-outline-warning btn-sm">
    <span class="msi me-1">refresh</span> Requeue
  </button>
  <button type="submit" name="action" value="clear_error"
          class="btn btn-outline-secondary btn-sm">
    <span class="msi me-1">delete_sweep</span> ล้าง error
  </button>
</div>
```

```css
.action-bar {
  position: sticky; bottom: 1rem; z-index: 50;
  background: #fff; border: 1px solid #e2e8f0;
  border-radius: 14px; padding: .75rem 1rem;
  box-shadow: 0 10px 25px rgba(15,23,42,.12);
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
}
.action-bar .selected-count { font-weight: 600; color: #0f172a; margin-right: auto; }
```

---

### 6.7 Modal (Gradient Header)

```html
<div class="modal fade" id="myModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:14px; overflow:hidden">

      <!-- Gradient header -->
      <div class="modal-header"
           style="background:linear-gradient(135deg,#1d4ed8,#1e3a8a); color:#fff; border:none">
        <h5 class="modal-title">
          <span class="msi me-2">sync</span> ชื่อ Modal
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <!-- Body -->
      <div class="modal-body">
        <!-- เนื้อหา -->

        <!-- Info box ภายใน modal -->
        <div class="p-2 rounded"
             style="background:#eff6ff; border:1px solid #bfdbfe; font-size:.8rem; color:#1e40af">
          <span class="msi me-1">info</span>
          ข้อความ info ตรงนี้
        </div>

        <!-- Result area (แสดงผลหลัง AJAX) -->
        <div id="syncResult" style="display:none; border-radius:8px; padding:10px 14px;
             font-size:.85rem; font-weight:500; margin-top:10px"></div>
      </div>

      <!-- Footer -->
      <div class="modal-footer" style="border:none; padding-top:0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-primary px-4" id="confirmBtn">
          <span class="msi me-1" id="confirmIcon">check</span>
          <span id="confirmText">ยืนยัน</span>
        </button>
      </div>

    </div>
  </div>
</div>
```

---

### 6.8 DataTable Setup

```js
$('#tbl').DataTable({
  responsive: true,
  pageLength: 25,
  order: [[0,'desc']],
  language: {
    search: 'ค้นหา:', paginate: { previous:'ก่อน', next:'ถัดไป' },
    lengthMenu: 'แสดง _MENU_ รายการ', info: 'แสดง _START_–_END_ จาก _TOTAL_ รายการ',
    zeroRecords: 'ไม่พบข้อมูล', emptyTable: 'ไม่มีข้อมูล',
  },
  dom: "<'row align-items-center mb-2'<'col-sm-6'l><'col-sm-6 text-end'f>>" +
       "<'row'<'col-12'tr>>" +
       "<'row align-items-center mt-2'<'col-sm-5'i><'col-sm-7 text-end'p>>",
  columnDefs: [
    { orderable: false, targets: [0] }  // ปิด sort คอลัมน์ checkbox
  ]
});
```

---

### 6.9 Select-All Checkbox Pattern

```js
// Select-all
document.getElementById('chkAll').addEventListener('change', function(){
  document.querySelectorAll('.chk').forEach(c => c.checked = this.checked);
  updateCount();
});
document.querySelectorAll('.chk').forEach(c => c.addEventListener('change', updateCount));

function updateCount(){
  const n = document.querySelectorAll('.chk:checked').length;
  document.getElementById('selectedCount').textContent = 'เลือก ' + n + ' รายการ';
}
```

---

## 7. Page Template (PHP)

### การตั้งค่าตัวแปรก่อน include header

```php
<?php
require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/auth_guard.php';  // เปิดถ้าต้องการ login

$PAGE_TITLE = 'ชื่อหน้า';
$PAGE_ICON  = 'medication';           // Material Symbol icon name
$PAGE_KEY   = 'pharm';               // ดู sidebar nav ใน partials/header.php

// CSS/JS เพิ่มเติมเฉพาะหน้านี้
$EXTRA_HEAD = '
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
  /* page-specific styles */
</style>
';

require_once __DIR__ . '/partials/header.php';
?>

<!-- PAGE CONTENT -->
<div class="page-header"> ... </div>
<div class="row g-3 mb-3"> ... KPI cards ... </div>
<div class="card filter-card"> ... </div>
<div class="card p-3"> ... table ... </div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
```

### PAGE_KEY ที่มีในระบบ

| PAGE_KEY | หน้า |
|---|---|
| `home` | index.php |
| `fracture_dash` | fracture_dashboard.php |
| `patient` | patient.php |
| `drug` | drugitems01.php |
| `sexual` | sexual.php |
| `accident` | accident_queue_ui.php |
| `fracture` | fracture_queue_ui.php |
| `covid` | covid_queue_ui.php |
| `dengue` | dengue_queue_ui.php |
| `lepto` | Leptospira.php |
| `scrub` | scrubtyphus.php |
| `pharm` | pharm_lab_queue_ui.php |
| `settings` | settings.php |
| `db_config` | db_config_admin.php |
| `moph_keys` | moph_keys_admin.php |

---

## 8. JavaScript Patterns

### 8.1 SweetAlert2 — Confirm ก่อน Submit

```js
document.getElementById('actionForm').addEventListener('submit', function(e){
  const n = document.querySelectorAll('.chk:checked').length;
  if (!n) { e.preventDefault(); Swal.fire('แจ้งเตือน','กรุณาเลือกรายการก่อน','warning'); return; }

  const action = e.submitter?.value ?? '';
  if (action === 'send_now') {
    e.preventDefault();
    Swal.fire({
      title: 'ยืนยันการส่ง?',
      text: `ส่ง LINE แจ้งเตือน ${n} รายการ`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'ส่งเลย',
      cancelButtonText: 'ยกเลิก',
      confirmButtonColor: '#1d4ed8',
    }).then(r => { if (r.isConfirmed) e.target.submit(); });
  }
});
```

### 8.2 AJAX Sync Modal Pattern

```js
async function doSync() {
  const btn     = document.getElementById('syncBtn');
  const icon    = document.getElementById('syncIcon');
  const txt     = document.getElementById('syncBtnText');
  const result  = document.getElementById('syncResult');

  // Loading state
  btn.disabled = true;
  icon.classList.add('msi-spin');
  icon.textContent = 'sync';
  txt.textContent = 'กำลัง Sync...';
  result.style.display = 'none';

  try {
    const fd = new FormData();
    fd.append('action', 'import_hosxp');
    fd.append('start', document.getElementById('syncStart').value);
    fd.append('end', document.getElementById('syncEnd').value);

    const res  = await fetch('your_action.php', { method:'POST', body: fd });
    const json = await res.json();

    result.style.display = 'block';
    if (json.ok) {
      result.className = 'ok';
      result.textContent = json.msg;
    } else {
      result.className = 'err';
      result.textContent = '❌ ' + json.msg;
    }
  } catch(err) {
    result.style.display = 'block';
    result.className = 'err';
    result.textContent = 'เกิดข้อผิดพลาด: ' + err.message;
  } finally {
    btn.disabled = false;
    icon.classList.remove('msi-spin');
    icon.textContent = 'sync';
    txt.textContent = 'Sync ข้อมูล';
  }
}
```

### 8.3 Topbar Clock

```js
function updateClock() {
  const now  = new Date();
  const days = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัส','ศุกร์','เสาร์'];
  const months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
  const buddhistYear = now.getFullYear() + 543;
  const dateStr = `${days[now.getDay()]}ที่ ${now.getDate()} ${months[now.getMonth()]} พ.ศ. ${buddhistYear}`;
  const timeStr = now.toTimeString().slice(0, 8) + ' น.';
  document.getElementById('tbDate').textContent = dateStr;
  document.getElementById('tbTime').textContent = timeStr;
}
updateClock();
setInterval(updateClock, 1000);
```

### 8.4 Sidebar Toggle (Responsive)

```js
function ckhToggleSidebar() {
  document.getElementById('ckh-sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('show');
}
function ckhCloseSidebar() {
  document.getElementById('ckh-sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('show');
}
```

---

## 9. LINE Flex Message Design

### 9.1 กฎที่ LINE รองรับ

| รายการ | ✅ รองรับ | ❌ ไม่รองรับ |
|---|---|---|
| สี | `#RRGGBB`, `#RRGGBBAA` | `rgba()`, `rgb()`, CSS named colors |
| Font family | ไม่ระบุ (ใช้ default) | `"th-sarabun"`, custom fonts |
| Bubble size | `nano` `micro` `kilo` `mega` `giga` | ค่าอื่น |
| Nesting | สูงสุด 10 ระดับ | เกิน 10 |

> **⚠️ สำคัญ:** MOPH Alert API ตอบ `200 OK` เสมอแม้ Flex JSON จะผิด — LINE เป็นฝ่าย drop แบบ silent

### 9.2 โครงสร้าง Bubble มาตรฐาน

```php
$bubble = [
  "type" => "bubble",
  "size" => "giga",
  "header" => [
    "type" => "box", "layout" => "vertical", "paddingAll" => "0px",
    "contents" => [[
      "type" => "image",
      "url" => "https://example.com/banner.jpg",
      "size" => "full", "aspectRatio" => "3120:885", "aspectMode" => "cover"
    ]]
  ],
  "body" => [
    "type" => "box", "layout" => "vertical",
    "spacing" => "none", "paddingAll" => "0px",
    "contents" => [
      $titleStrip,          // แถบชื่อสีเข้ม
      $innerBox,            // กล่องเนื้อหา (priority + sections)
    ]
  ],
  "footer" => $footer,      // system name + timestamp
  "styles" => [
    "header" => ["backgroundColor" => "#FFFFFF"],
    "body"   => ["backgroundColor" => "#F9FAFB"],
    "footer" => ["backgroundColor" => "#F3F4F6"],
  ]
];

return ["messages" => [["type" => "flex", "altText" => $altText, "contents" => $bubble]]];
```

### 9.3 Title Strip Pattern

```php
$titleStrip = [
  "type" => "box", "layout" => "vertical",
  "paddingAll" => "16px",
  "backgroundColor" => "#1E3A8A",  // hex เท่านั้น
  "cornerRadius" => "0px",
  "contents" => [
    ["type" => "box", "layout" => "horizontal", "contents" => [
      ["type" => "text", "text" => "🏥  ชื่อโมดูล",
       "size" => "sm", "color" => "#FFFFFF", "weight" => "bold", "flex" => 1],
      ["type" => "text", "text" => "Module Name",
       "size" => "sm", "color" => "#FFFFFFB3",  // 70% opacity แทน rgba()
       "align" => "end", "flex" => 0],
    ]],
    ["type" => "text", "text" => "หัวข้อหลัก",
     "size" => "xxl", "color" => "#FFFFFF", "weight" => "bold",
     "wrap" => true, "margin" => "sm"],
    ["type" => "text", "text" => "Subtitle · รพ.ชื่อโรงพยาบาล",
     "size" => "sm", "color" => "#FFFFFFBF",  // 75% opacity
     "wrap" => true, "margin" => "xs"],
  ]
];
```

### 9.4 Section Card Helper

```php
function flex_section(string $title, array $rows, array $opts = []): array {
  $bg   = $opts['bg']     ?? '#FFFFFF';
  $bd   = $opts['bd']     ?? '#E5E7EB';
  $icon = $opts['icon']   ?? '';
  $acc  = $opts['accent'] ?? '#1E3A8A';
  return [
    "type" => "box", "layout" => "vertical",
    "paddingAll" => "14px", "cornerRadius" => "12px",
    "margin" => "md", "spacing" => "xs",
    "backgroundColor" => $bg, "borderColor" => $bd, "borderWidth" => "1px",
    "contents" => array_merge([[
      "type" => "box", "layout" => "baseline", "spacing" => "sm",
      "contents" => [[
        "type" => "text",
        "text" => ($icon ? "$icon  " : '') . $title,
        "size" => "sm", "color" => $acc, "weight" => "bold", "flex" => 1
      ]]
    ]], $rows)
  ];
}
```

### 9.5 Row Helper (Label + Value)

```php
function flex_row(string $label, ?string $value, array $opts = []): array {
  $v = ($value === null || $value === '') ? '-' : (string)$value;
  return [
    "type" => "box", "layout" => "baseline",
    "spacing" => "sm", "margin" => "sm",
    "contents" => [
      ["type" => "text", "text" => $label,
       "size" => "sm", "color" => "#6B7280", "flex" => 4, "weight" => "regular"],
      ["type" => "text", "text" => $v,
       "size"   => $opts['size']   ?? "sm",
       "color"  => $opts['color']  ?? "#111827",
       "weight" => $opts['weight'] ?? "regular",
       "flex" => 6, "wrap" => true, "align" => "end"],
    ]
  ];
}
```

### 9.6 Color Palette ต่อโมดูล

| โมดูล | Accent | Head | Bg | Border |
|---|---|---|---|---|
| Fall Risk | `#2563EB` | `#1D4ED8` | `#EFF6FF` | `#BFDBFE` |
| COVID-19 | `#4338CA` | `#3730A3` | `#EEF2FF` | `#C7D2FE` |
| Accident | `#DC2626` | `#991B1B` | `#FEF2F2` | `#FECACA` |
| Drug | `#7C3AED` | `#6D28D9` | `#F5F3FF` | `#DDD6FE` |
| Pharm Lab | `#0891B2` | `#0E7490` | `#ECFEFF` | `#A5F3FC` |
| Patient | `#B45309` | `#92400E` | `#FFFBEB` | `#FDE68A` |
| Dengue | `#D97706` | `#B45309` | `#FFFBEB` | `#FDE68A` |
| Sexual | `#BE185D` | `#9D174D` | `#FDF2F8` | `#FBCFE8` |

### 9.7 ค่า opacity สำหรับ hex 8-digit

| ความโปร่งใส | hex suffix |
|---|---|
| 90% | `E6` |
| 80% | `CC` |
| 75% | `BF` |
| 70% | `B3` |
| 60% | `99` |
| 50% | `80` |

ตัวอย่าง: `#FFFFFF` + 70% = `#FFFFFFB3`

---

## 10. Queue UI Page Template

เมื่อต้องการสร้างหน้า Queue ใหม่ (เช่น `newmodule_queue_ui.php`) ใช้โครงสร้างนี้:

```
newmodule_queue_ui.php
├── require config.php
├── ตั้งค่า filters ($start, $end, $status)
├── Query จาก newmodule_queue WHERE ... LIMIT 2000
├── คำนวณ KPI (total / pending / sent / failed)
├── ตั้ง $PAGE_TITLE, $PAGE_KEY, $EXTRA_HEAD
├── require partials/header.php
│
├── Flash alert
├── .page-header  (title + action buttons)
├── .row.g-3      (KPI cards × 4)
├── .card.filter-card (form filter)
├── <form> + <table id="tbl">  (DataTable)
├── Modal (Sync from HOSxP)
├── .action-bar  (sticky bulk actions)
│
├── <script> DataTable + SweetAlert + Select-all + AJAX sync
└── require partials/footer.php
```

### Action Handler Pattern (`newmodule_queue_action.php`)

```php
<?php
require_once __DIR__ . '/config.php';

// CSRF Token (เหมือนกันทุกโมดูล)
if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', hash('sha256', __DIR__ . '/newmodule_queue_ui.php' . php_uname() . date('Y-m-d')));
}

$action = trim($_POST['action'] ?? '');

// ── AJAX (ไม่ต้อง CSRF) ────────────────────────────────────────
if ($action === 'import_hosxp') {
  header('Content-Type: application/json; charset=utf-8');
  // query HOSxP → upsert → json response
  exit;
}

// ── Bulk actions (ต้อง CSRF) ───────────────────────────────────
if ($_POST['token'] !== UI_ACTION_TOKEN) { http_response_code(403); exit('Forbidden'); }

$ids = array_values(array_filter((array)($_POST['ids']??[]), fn($x)=>ctype_digit((string)$x)));
if (!$ids) { header('Location: newmodule_queue_ui.php?msg=no_ids'); exit; }

match($action) {
  'send_now'    => /* ส่ง LINE แล้ว redirect */ null,
  'requeue'     => /* reset status=0 */ null,
  'clear_error' => /* ล้าง last_error */ null,
  default       => header('Location: newmodule_queue_ui.php?msg=bad_action'),
};
```

---

> **หมายเหตุ:** ไฟล์ `partials/header.php` และ `partials/footer.php` เป็น shared layout ของทุกหน้า  
> ทุกหน้าใหม่ต้องกำหนด `$PAGE_TITLE`, `$PAGE_KEY`, `$EXTRA_HEAD` ก่อน `require_once` เสมอ
