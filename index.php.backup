<?php 
require_once __DIR__ . '/auth_guard.php';
session_start();
if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ระบบแจ้งเตือนข้อมูลคนไข้ | รพ.เชียงกลาง</title>

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600&family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root{
      --shadow: 0 10px 30px rgba(0,0,0,.18);

      /* Light theme (เพิ่มคอนทราสต์/ความชัด) */
      --txt: #0b253a;
      --txt-muted:#334155;
      --overlay-1: rgba(255,255,255,.75);
      --overlay-2: rgba(255,255,255,.55);
      --search-bg: rgba(255,255,255,.96);
      --search-border: rgba(15,23,42,.12);
      --search-txt: #0b253a;
      --footer-txt: #0b253a;
      --chip-bg: rgba(255,255,255,.55);
      --chip-bg-hover: rgba(255,255,255,.85);
      --ring: 2px solid rgba(255,255,255,.9);
    }
    [data-theme="dark"]{
      --txt:#eaf2ff;
      --txt-muted:#c6d5ee;
      --overlay-1: rgba(2,6,23,.78);
      --overlay-2: rgba(2,6,23,.58);
      --search-bg: rgba(17,24,39,.92);
      --search-border: rgba(226,232,240,.22);
      --search-txt:#eaf2ff;
      --footer-txt:#c6d5ee;
      --chip-bg: rgba(255,255,255,.14);
      --chip-bg-hover: rgba(255,255,255,.24);
      --ring: 3px solid rgba(99, 102, 241, .85);
    }

    html,body{height:100%}
    body{
      font-family:'Prompt','Kanit',system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
      background:#0e1726 url("img/hospital.jpg") center/cover no-repeat fixed;
      color:var(--txt);
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      text-rendering: optimizeLegibility;
    }

    /* Background overlay (blur) */
    .bg-overlay{
      position:fixed; inset:0;
      backdrop-filter: blur(6px);
      background: linear-gradient(180deg, var(--overlay-1), var(--overlay-2));
      pointer-events:none;
      z-index:0;
    }

    /* Header */
    .app-header{
      background: rgba(255,255,255,.12);
      border-radius: 18px;
      padding: 12px 20px;
      display:flex; align-items:center; justify-content:space-between;
      position:relative;
      z-index:5;
      color:#fff;
      box-shadow: var(--shadow);
      border: 1px solid rgba(255,255,255,.18);
      backdrop-filter: blur(6px);
    }
    .brand-title{font-weight:700; margin:0; letter-spacing:.2px}

    /* Theme toggle */
    .theme-toggle{
      position:fixed;
      top:14px; right:18px;
      z-index:9999;
      background: rgba(0,0,0,.55);
      backdrop-filter: blur(6px);
      border:1px solid rgba(255,255,255,.18);
      border-radius: 28px;
      padding:6px 10px;
      display:flex; align-items:center; gap:.45rem;
      color:#fff;
      box-shadow: var(--shadow);
    }
    .theme-toggle .form-check-input{
      width:3rem; height:1.5rem; cursor:pointer;
    }

    /* Search */
    .search-wrap{
      background: var(--search-bg);
      border:1px solid var(--search-border);
      border-radius: 16px;
      padding:12px 14px;
      box-shadow: var(--shadow);
      z-index:5;
      color:var(--search-txt);
    }
    .search-input{
      background:transparent; border:0; outline:0; width:100%;
      color:var(--search-txt);
      font-size: 1rem;
    }
    .search-input::placeholder{color:#6b7b8f}

    /* Tiles */
    .tile{
      display:block; text-decoration:none;
      border-radius: 22px;
      padding: 22px;
      color:#fff;
      box-shadow: var(--shadow);
      min-height:180px;
      transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
      border:1px solid rgba(255,255,255,.18);
      position: relative;
      isolation: isolate;
    }
    .tile .icon-wrap{
      width:74px; height:74px; border-radius:16px;
      display:grid; place-items:center;
      background: var(--chip-bg);
      margin-bottom:14px;
      box-shadow: inset 0 0 1px rgba(0,0,0,.12);
    }
    .tile h5{margin:0; font-weight:800; letter-spacing:.2px; text-shadow: 0 1px 2px rgba(0,0,0,.28)}
    .tile small{opacity:.98; text-shadow: 0 1px 2px rgba(0,0,0,.25)}
    .tile:hover{transform:translateY(-4px); box-shadow: 0 14px 44px rgba(0,0,0,.28); filter: saturate(1.05)}
    .tile:focus-visible{outline: none; box-shadow: 0 0 0 0 var(--ring); }
    .tile:after{
      content:""; position:absolute; inset:0; border-radius:22px;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,.15);
      pointer-events:none;
    }

    /* Gradient palettes (เพิ่มความสด/อ่านง่าย) */
    .tile.red    { background: linear-gradient(135deg,#ff5a5f 0%,#ff2d55 100%);}
    .tile.orange { background: linear-gradient(135deg,#ffb02e 0%,#ff7a00 100%);}
    .tile.green  { background: linear-gradient(135deg,#27d17b 0%,#0fb870 100%);}
    .tile.blue   { background: linear-gradient(135deg,#4da3ff 0%,#0d6efd 100%);}
    .tile.teal   { background: linear-gradient(135deg,#38e1d9 0%,#16a3a5 100%);} /* สำหรับงานเภสัชกรรม */

    /* Footer */
    .app-footer{color:var(--footer-txt); opacity:.95}

    /* ปรับช่องไฟบนจอเล็กให้อ่านง่ายขึ้น */
    @media (max-width: 575.98px){
      .tile{min-height:160px; padding:18px}
      .tile .icon-wrap{width:66px;height:66px}
      .brand-title{font-size:1rem}
    }

    /* เคารพ preference ผู้ใช้ */
    @media (prefers-reduced-motion: reduce){
      *{scroll-behavior:auto !important; transition:none !important}
    }
  </style>
</head>
<body>
  <div class="bg-overlay" aria-hidden="true"></div>

  <!-- Floating Theme Toggle -->
  <div class="theme-toggle" role="group" aria-label="สลับธีม">
    <i class="fa-solid fa-sun" aria-hidden="true"></i>
    <input id="themeSwitch" class="form-check-input" type="checkbox" role="switch" aria-label="สลับธีมมืด">
    <i class="fa-solid fa-moon" aria-hidden="true"></i>
  </div>

  <main class="container py-4 py-lg-5">

    <!-- Header -->
    <header class="app-header" role="banner">
      <div class="d-flex align-items-center gap-3">
        <img src="img/Logo_CKHospital.png" alt="โลโก้โรงพยาบาลเชียงกลาง" width="54" height="54" class="bg-white rounded-circle p-1">
        <h1 class="h5 brand-title">ระบบแจ้งเตือนข้อมูลคนไข้ โรงพยาบาลเชียงกลาง</h1>
      </div>
      <div class="d-none d-sm-flex align-items-center gap-2" aria-hidden="true">
        <img src="img/Logo_CKHospital.png" alt="" width="38" height="38" class="bg-white rounded-circle p-1">
      </div>
    </header>

    <!-- Search -->
    <section class="mt-3" role="search" aria-label="ค้นหารายการ">
      <div class="search-wrap d-flex align-items-center gap-2">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input id="search" class="search-input" type="search"
               placeholder="พิมพ์เพื่อค้นหา เช่น จิตเวช, พ.ร.บ., Covid, ไข้เลือดออก…" aria-label="ค้นหารายการ">
        <button id="clearBtn" class="btn btn-sm btn-outline-secondary ms-auto">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i> ล้าง
        </button>
      </div>
    </section>

    <!-- Tiles Grid -->
    <section class="mt-4">
      <div id="tiles" class="row g-3 g-sm-4">

        <!-- 1 -->
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="tile red" href="patient.php" data-label="จิตเวช กลุ่มเสี่ยง ติดตาม เฝ้าระวัง">
            <div class="icon-wrap"><i class="fa-solid fa-user-doctor fa-2x" aria-hidden="true"></i></div>
            <h5>รายการคนไข้กลุ่มเสี่ยงจิตเวช</h5>
            <small>ติดตามและเฝ้าระวัง</small>
          </a>
        </div>

        <!-- 2 -->
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="tile red" href="drugitems01.php" data-label="ยาอันตราย กลุ่มเสี่ยง ตรวจสอบยา">
            <div class="icon-wrap"><i class="fa-solid fa-kit-medical fa-2x" aria-hidden="true"></i></div>
            <h5>รายการคนไข้กลุ่มเสี่ยงกินยาอันตราย</h5>
            <small>ตรวจสอบรายการยา</small>
          </a>
        </div>

        <!-- 3 -->
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="tile red" href="sexual.php" data-label="ถูกข่มขืน ทำร้าย ร่างกาย เร่งด่วน">
            <div class="icon-wrap"><i class="fa-solid fa-user-xmark fa-2x" aria-hidden="true"></i></div>
            <h5>ผู้ถูกข่มขืน / ทำร้ายร่างกาย</h5>
            <small>เร่งด่วนและละเอียดอ่อน</small>
          </a>
        </div>

        <!-- 4 -->
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="tile orange" href="accident_queue_ui.php" data-label="พ.ร.บ ประกัน อุบัติเหตุ รถ">
            <div class="icon-wrap"><i class="fa-solid fa-car-burst fa-2x" aria-hidden="true"></i></div>
            <h5>รายการคนไข้ พ.ร.บ.</h5>
            <small>อุบัติเหตุและสิทธิประกัน</small>
          </a> 
        </div>

        <!-- 5 -->
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="tile orange" href="covid.php" data-label="โควิด covid ผู้ติดเชื้อ เคสล่าสุด">
            <div class="icon-wrap"><i class="fa-solid fa-virus-covid fa-2x" aria-hidden="true"></i></div>
            <h5>ผู้ติดเชื้อ Covid-19</h5>
            <small>อัปเดตเคสล่าสุด</small>
          </a>
        </div>

        <!-- 6 -->
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="tile green" href="fracture_queue_ui.php" data-label="พลัดตก หกล้ม กระดูก ลื่นล้ม">
            <div class="icon-wrap"><i class="fa-solid fa-calendar-check fa-2x" aria-hidden="true"></i></div>
            <h5>พลัดตก / หกล้ม</h5>
            <small>ป้องกันซ้ำและติดตามผล</small>
          </a>
        </div>

        <!-- 7 -->
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="tile blue" href="dengue.php" data-label="ไข้เลือดออก dengue ยุงลาย">
            <div class="icon-wrap"><i class="fa-solid fa-bug-slash fa-2x" aria-hidden="true"></i></div>
            <h5>โรคไข้เลือดออก</h5>
            <small>Dengue Surveillance</small>
          </a>
        </div>

        <!-- 8 -->
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="tile blue" href="scrubtyphus.php" data-label="สครับไทฟัส ไรอ่อน ไข้ป่า">
            <div class="icon-wrap"><i class="fa-solid fa-mosquito fa-2x" aria-hidden="true"></i></div>
            <h5>โรคสครับไทฟัส</h5>
            <small>ติดตามพื้นที่เสี่ยง</small>
          </a>
        </div>

        <!-- 9 -->
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="tile blue" href="Leptospira.php" data-label="เลปโต ฉี่หนู น้ำท่วม หน้าฝน">
            <div class="icon-wrap"><i class="fa-solid fa-paw fa-2x" aria-hidden="true"></i></div>
            <h5>โรคเลปโตสไปโรสิส</h5>
            <small>เฝ้าระวังช่วงหน้าฝน</small>
          </a>
        </div>

        <!-- 10 (ใหม่) งานเภสัชกรรม -->
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="tile teal" href="pharm_lab_queue_ui.php" data-label="เภสัช ยา คลังยา จ่ายยา ตรวจสอบสต๊อก">
            <div class="icon-wrap"><i class="fa-solid fa-pills fa-2x" aria-hidden="true"></i></div>
            <h5>งานเภสัชกรรม</h5>
            <small>คลังยา · จ่ายยา · ตรวจสอบ</small>
          </a>
        </div>

      </div>

      <p id="emptyState" class="text-center mt-3 fw-semibold" style="display:none; color:var(--txt-muted)" aria-live="polite">
        ไม่พบรายการที่ตรงกับคำค้น
      </p>
    </section>

    <footer class="app-footer text-center mt-4 small">
      <span>© <span id="yy"></span> ChiangKang Hospital · ระบบแจ้งเตือนข้อมูลคนไข้</span>
    </footer>
  </main>

  <script>
    // ปีอัตโนมัติ
    document.getElementById('yy').textContent = new Date().getFullYear();

    // ===== Theme Switch =====
    const root = document.documentElement;
    const themeSwitch = document.getElementById('themeSwitch');
    (function initTheme(){
      const saved = localStorage.getItem('ckh-theme');
      const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      const theme = saved || (prefersDark ? 'dark':'light');
      setTheme(theme);
      themeSwitch.checked = theme === 'dark';
    })();
    function setTheme(theme){
      if(theme==='dark') root.setAttribute('data-theme','dark');
      else root.removeAttribute('data-theme');
      localStorage.setItem('ckh-theme', theme);
    }
    themeSwitch.addEventListener('change', e => setTheme(e.target.checked?'dark':'light'));

    // ===== Search filter =====
    const search = document.getElementById('search');
    const clearBtn = document.getElementById('clearBtn');
    const tiles = Array.from(document.querySelectorAll('#tiles .col-12'));
    const emptyState = document.getElementById('emptyState');
    function normalize(s){ return (s||'').toString().toLowerCase().trim(); }
    function applyFilter(q){
      const query = normalize(q);
      let visible = 0;
      tiles.forEach(col=>{
        const a = col.querySelector('.tile');
        const text = normalize(a.innerText + ' ' + (a.dataset.label||''));
        const show = !query || text.includes(query);
        col.style.display = show ? '' : 'none';
        if(show) visible++;
      });
      emptyState.style.display = (visible===0) ? '' : 'none';
    }
    let t=null;
    search.addEventListener('input', e=>{
      clearTimeout(t);
      t=setTimeout(()=>applyFilter(e.target.value),120);
    });
    clearBtn.addEventListener('click', ()=>{
      search.value=''; applyFilter(''); search.focus();
    });

    // ===== Toast before navigation =====
    document.querySelectorAll('.tile').forEach(tile=>{
      tile.addEventListener('click', function(e){
        e.preventDefault();
        const target = this.getAttribute('href');
        Swal.fire({
          title: 'ส่งคำขอเรียบร้อย',
          text: 'กำลังเปิดหน้ารายการ…',
          icon: 'success',
          timer: 1100,
          showConfirmButton: false,
          timerProgressBar: true
        }).then(()=>{ window.location.href = target; });
      });
    });
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
