<?php
require_once __DIR__ . '/auth_guard.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user']) && empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Bangkok');

$displayName = $_SESSION['full_name']
    ?? ($_SESSION['user']['full_name'] ?? ($_SESSION['username'] ?? 'ผู้ใช้งาน'));
$position    = $_SESSION['position'] ?? ($_SESSION['user']['position'] ?? 'เจ้าหน้าที่ระบบ');

/* ---- Quick KPI from fracture_queue ---- */
$kpiTotal = $kpiPending = $kpiSent = $kpiToday = 0;
try {
    $today = date('Y-m-d');
    $r = $dbcon->query("SELECT
        COUNT(*) total,
        SUM(status=0) pending,
        SUM(status=1) sent,
        SUM(DATE(created_at)='$today') today
        FROM fracture_queue")->fetch();
    if ($r) {
        $kpiTotal   = (int)$r['total'];
        $kpiPending = (int)$r['pending'];
        $kpiSent    = (int)$r['sent'];
        $kpiToday   = (int)$r['today'];
    }
} catch (Exception $e) { /* ไม่มีตาราง ไม่เป็นไร */ }

/* ---- Recent pending (fracture) ---- */
$recentPending = [];
try {
    $stmt = $dbcon->query("SELECT id, fullname, pdx_name, vstdate, created_at
        FROM fracture_queue WHERE status=0 ORDER BY id DESC LIMIT 5");
    $recentPending = $stmt->fetchAll();
} catch (Exception $e) {}

/* ---- Recent sent ---- */
$recentSent = [];
try {
    $stmt = $dbcon->query("SELECT id, fullname, pdx_name, sent_at
        FROM fracture_queue WHERE status=1 ORDER BY sent_at DESC LIMIT 5");
    $recentSent = $stmt->fetchAll();
} catch (Exception $e) {}

/* ---- Thai greeting by hour ---- */
$hr = (int)date('H');
if ($hr < 12)      $greeting = 'สวัสดีตอนเช้า';
elseif ($hr < 17)  $greeting = 'สวัสดีตอนบ่าย';
else               $greeting = 'สวัสดีตอนเย็น';

$PAGE_TITLE = 'หน้าหลัก';
$PAGE_KEY   = 'home';

$EXTRA_HEAD = '
<style>
/* ===== Welcome Banner ===== */
.welcome-banner {
  background: linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 40%, #059669 100%);
  border-radius: 16px;
  padding: 28px 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  color: #fff;
  margin-bottom: 28px;
  flex-wrap: wrap;
  box-shadow: 0 8px 32px rgba(29,78,216,.25);
  position: relative;
  overflow: hidden;
}
.welcome-banner::before {
  content: "";
  position: absolute; top: -40px; right: -40px;
  width: 220px; height: 220px;
  border-radius: 50%;
  background: rgba(255,255,255,.08);
  pointer-events: none;
}
.welcome-banner::after {
  content: "";
  position: absolute; bottom: -50px; right: 20%;
  width: 160px; height: 160px;
  border-radius: 50%;
  background: rgba(255,255,255,.06);
  pointer-events: none;
}
.wb-left { display: flex; align-items: center; gap: 18px; }
.wb-avatar {
  width: 64px; height: 64px;
  border-radius: 50%;
  background: rgba(255,255,255,.2);
  border: 3px solid rgba(255,255,255,.4);
  display: grid; place-items: center;
  font-size: 1.75rem; font-weight: 700; color: #fff;
  flex-shrink: 0;
}
.wb-greeting { font-size: .85rem; opacity: .9; }
.wb-name { font-size: 1.35rem; font-weight: 700; line-height: 1.2; }
.wb-pos {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(255,255,255,.15);
  border: 1px solid rgba(255,255,255,.2);
  border-radius: 999px;
  padding: 3px 12px; font-size: .8rem;
  margin-top: 6px;
}
.wb-right { text-align: right; }
.wb-date-label { font-size: .75rem; opacity: .8; }
.wb-date { font-size: 1.05rem; font-weight: 600; }

/* ===== Section heading ===== */
.section-head {
  font-size: .78rem; font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 14px;
  display: flex; align-items: center; gap: 8px;
}
.section-head::after {
  content: ""; flex: 1; height: 1px;
  background: var(--border);
}

/* ===== Quick Shortcut Cards ===== */
.shortcut-card {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 22px 18px;
  display: flex; flex-direction: column; align-items: flex-start; gap: 14px;
  text-decoration: none; color: var(--text);
  box-shadow: var(--card-shadow);
  transition: box-shadow .2s, transform .2s, border-color .2s;
  height: 100%;
}
.shortcut-card:hover {
  box-shadow: var(--card-shadow-hover);
  transform: translateY(-2px);
  border-color: var(--blue-100);
  color: var(--text);
}
.shortcut-card:active { transform: translateY(0); }
.sc-icon {
  width: 56px; height: 56px;
  border-radius: 14px;
  display: grid; place-items: center;
  font-size: 1.4rem; color: #fff;
  flex-shrink: 0;
}
.sc-title { font-weight: 700; font-size: .97rem; line-height: 1.3; }
.sc-desc  { font-size: .78rem; color: var(--muted); margin-top: 2px; }

/* ===== Status section cards ===== */
.status-card {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: var(--card-shadow);
  overflow: hidden;
}
.status-card-head {
  padding: 14px 18px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  font-weight: 700; font-size: .92rem;
}
.status-card-body { padding: 0; }
.status-item {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 12px 18px; border-bottom: 1px solid #f1f5f9;
}
.status-item:last-child { border-bottom: 0; }
.status-item-dot {
  width: 8px; height: 8px; border-radius: 50%;
  margin-top: 6px; flex-shrink: 0;
}
.dot-pending { background: #f59e0b; }
.dot-sent    { background: #10b981; }
.status-item-name { font-size: .88rem; font-weight: 500; line-height: 1.3; }
.status-item-meta { font-size: .75rem; color: var(--muted); margin-top: 2px; }
.status-empty {
  display: flex; flex-direction: column; align-items: center;
  padding: 36px 20px; color: var(--muted); gap: 10px;
}
.status-empty i { font-size: 2rem; opacity: .4; }
.status-empty p { font-size: .85rem; margin: 0; }
</style>
';

require_once __DIR__ . '/partials/header.php';
?>

<!-- ===== WELCOME BANNER ===== -->
<div class="welcome-banner">
  <div class="wb-left">
    <div class="wb-avatar"><?= htmlspecialchars(mb_substr($displayName, 0, 1, 'UTF-8')) ?></div>
    <div>
      <div class="wb-greeting"><?= $greeting ?></div>
      <div class="wb-name"><?= htmlspecialchars($displayName) ?></div>
      <div class="wb-pos">
        <span class="msi" style="font-size:.7rem">security</span>
        <?= htmlspecialchars($position ?: 'เจ้าหน้าที่ระบบ') ?>
      </div>
    </div>
  </div>
  <div class="wb-right">
    <div class="wb-date-label">วันนี้</div>
    <div class="wb-date" id="wbDate">—</div>
  </div>
</div>

<!-- ===== KPI ROW ===== -->
<div class="row g-3 mb-4">
  <div class="col-6 col-sm-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-slate"><span class="msi">checklist</span></div>
      <div>
        <p class="kpi-label">คิวทั้งหมด</p>
        <p class="kpi-value"><?= number_format($kpiTotal) ?></p>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-amber"><span class="msi">schedule</span></div>
      <div>
        <p class="kpi-label">ค้างส่ง</p>
        <p class="kpi-value" style="color:#d97706"><?= number_format($kpiPending) ?></p>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-green"><span class="msi">check_circle</span></div>
      <div>
        <p class="kpi-label">ส่งสำเร็จ</p>
        <p class="kpi-value" style="color:#059669"><?= number_format($kpiSent) ?></p>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="kpi-card">
      <div class="kpi-icon bg-blue"><span class="msi">today</span></div>
      <div>
        <p class="kpi-label">วันนี้</p>
        <p class="kpi-value" style="color:#1d4ed8"><?= number_format($kpiToday) ?></p>
      </div>
    </div>
  </div>
</div>

<!-- ===== QUICK SHORTCUTS ===== -->
<div class="section-head">
  <span class="msi" style="color:var(--blue)">grid_view</span>
  เมนูลัด (Quick Shortcuts)
</div>

<div class="row g-3 mb-4">

  <div class="col-12 col-sm-6 col-xl-4">
    <a href="patient.php" class="shortcut-card"
       data-label="จิตเวช กลุ่มเสี่ยง ติดตาม เฝ้าระวัง">
      <div class="sc-icon" style="background:linear-gradient(135deg,#ff5a5f,#e11d48)">
        <span class="msi">stethoscope</span>
      </div>
      <div>
        <div class="sc-title">กลุ่มเสี่ยงจิตเวช</div>
        <div class="sc-desc">ติดตาม · เฝ้าระวัง</div>
      </div>
    </a>
  </div>

  <div class="col-12 col-sm-6 col-xl-4">
    <a href="drugitems01.php" class="shortcut-card"
       data-label="ยาอันตราย กลุ่มเสี่ยง ตรวจสอบยา">
      <div class="sc-icon" style="background:linear-gradient(135deg,#fb923c,#ea580c)">
        <span class="msi">medical_services</span>
      </div>
      <div>
        <div class="sc-title">กลุ่มเสี่ยงยาอันตราย</div>
        <div class="sc-desc">ตรวจสอบรายการยา</div>
      </div>
    </a>
  </div>

  <div class="col-12 col-sm-6 col-xl-4">
    <a href="sexual.php" class="shortcut-card"
       data-label="ถูกข่มขืน ทำร้าย ร่างกาย เร่งด่วน">
      <div class="sc-icon" style="background:linear-gradient(135deg,#f472b6,#db2777)">
        <span class="msi">shield_person</span>
      </div>
      <div>
        <div class="sc-title">ผู้ถูกข่มขืน / ทำร้ายร่างกาย</div>
        <div class="sc-desc">เร่งด่วน · ละเอียดอ่อน</div>
      </div>
    </a>
  </div>

  <div class="col-12 col-sm-6 col-xl-4">
    <a href="accident_queue_ui.php" class="shortcut-card"
       data-label="พ.ร.บ ประกัน อุบัติเหตุ รถ">
      <div class="sc-icon" style="background:linear-gradient(135deg,#fbbf24,#d97706)">
        <span class="msi">car_crash</span>
      </div>
      <div>
        <div class="sc-title">คนไข้ พ.ร.บ.</div>
        <div class="sc-desc">อุบัติเหตุ · สิทธิประกัน</div>
      </div>
    </a>
  </div>

  <div class="col-12 col-sm-6 col-xl-4">
    <a href="fracture_queue_ui.php" class="shortcut-card"
       data-label="พลัดตก หกล้ม กระดูก fall risk fracture">
      <div class="sc-icon" style="background:linear-gradient(135deg,#34d399,#059669)">
        <span class="msi">falling</span>
      </div>
      <div>
        <div class="sc-title">พลัดตก / หกล้ม</div>
        <div class="sc-desc">ป้องกันซ้ำ · ติดตามผล</div>
      </div>
    </a>
  </div>

  <div class="col-12 col-sm-6 col-xl-4">
    <a href="fracture_dashboard.php" class="shortcut-card"
       data-label="dashboard สรุป กราฟ สถิติ">
      <div class="sc-icon" style="background:linear-gradient(135deg,#60a5fa,#1d4ed8)">
        <span class="msi">show_chart</span>
      </div>
      <div>
        <div class="sc-title">Dashboard</div>
        <div class="sc-desc">สรุปข้อมูล · กราฟสถิติ</div>
      </div>
    </a>
  </div>

  <div class="col-12 col-sm-6 col-xl-4">
    <a href="pharm_lab_queue_ui.php" class="shortcut-card"
       data-label="เภสัช ยา คลังยา จ่ายยา">
      <div class="sc-icon" style="background:linear-gradient(135deg,#22d3ee,#0891b2)">
        <span class="msi">medication</span>
      </div>
      <div>
        <div class="sc-title">งานเภสัชกรรม</div>
        <div class="sc-desc">LabAlert · ตรวจสอบยา</div>
      </div>
    </a>
  </div>

  <div class="col-12 col-sm-6 col-xl-4">
    <a href="covid.php" class="shortcut-card"
       data-label="โควิด covid ผู้ติดเชื้อ เคส">
      <div class="sc-icon" style="background:linear-gradient(135deg,#fb923c,#ea580c)">
        <span class="msi">coronavirus</span>
      </div>
      <div>
        <div class="sc-title">ผู้ติดเชื้อ Covid-19</div>
        <div class="sc-desc">อัปเดตเคสล่าสุด</div>
      </div>
    </a>
  </div>

  <div class="col-12 col-sm-6 col-xl-4">
    <a href="dengue.php" class="shortcut-card"
       data-label="ไข้เลือดออก dengue ยุงลาย">
      <div class="sc-icon" style="background:linear-gradient(135deg,#4da3ff,#1d4ed8)">
        <span class="msi">pest_control</span>
      </div>
      <div>
        <div class="sc-title">โรคไข้เลือดออก</div>
        <div class="sc-desc">Dengue Surveillance</div>
      </div>
    </a>
  </div>

</div>

<!-- ===== STATUS SECTIONS (2-col) ===== -->
<div class="section-head">
  <span class="msi" style="color:var(--blue)">history</span>
  ติดตามสถานะแจ้งเตือน (ล่าสุด)
</div>

<div class="row g-3 mb-2">

  <!-- Pending -->
  <div class="col-12 col-lg-6">
    <div class="status-card">
      <div class="status-card-head">
        <span><span class="msi text-warning me-2">hourglass_empty</span>คิวรอส่ง (ล่าสุด)</span>
        <a href="fracture_queue_ui.php" class="btn btn-sm btn-outline-secondary"
           style="font-size:.75rem; padding:3px 10px">ดูทั้งหมด</a>
      </div>
      <div class="status-card-body">
        <?php if ($recentPending): ?>
          <?php foreach ($recentPending as $r): ?>
            <div class="status-item">
              <div class="status-item-dot dot-pending"></div>
              <div>
                <div class="status-item-name"><?= htmlspecialchars($r['fullname'] ?? '—') ?></div>
                <div class="status-item-meta">
                  <?= htmlspecialchars($r['pdx_name'] ?? '—') ?>
                  · <?= htmlspecialchars(substr($r['created_at'] ?? '', 0, 10)) ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="status-empty">
            <span class="msi">check_circle</span>
            <p>ยังไม่มีคิวรอส่งในขณะนี้</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Sent -->
  <div class="col-12 col-lg-6">
    <div class="status-card">
      <div class="status-card-head">
        <span><span class="msi text-success me-2">check_circle</span>ส่งสำเร็จล่าสุด</span>
        <a href="fracture_queue_ui.php?status=1" class="btn btn-sm btn-outline-secondary"
           style="font-size:.75rem; padding:3px 10px">ดูทั้งหมด</a>
      </div>
      <div class="status-card-body">
        <?php if ($recentSent): ?>
          <?php foreach ($recentSent as $r): ?>
            <div class="status-item">
              <div class="status-item-dot dot-sent"></div>
              <div>
                <div class="status-item-name"><?= htmlspecialchars($r['fullname'] ?? '—') ?></div>
                <div class="status-item-meta">
                  <?= htmlspecialchars($r['pdx_name'] ?? '—') ?>
                  · <?= htmlspecialchars(substr($r['sent_at'] ?? '', 0, 10)) ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="status-empty">
            <span class="msi">mail</span>
            <p>ยังไม่มีประวัติการส่งในขณะนี้</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<script>
(function () {
  const el = document.getElementById('wbDate');
  if (!el) return;
  const thMonths = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
                    'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  const thDays   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
  const now = new Date();
  el.textContent = `วัน${thDays[now.getDay()]}ที่ ${now.getDate()} ${thMonths[now.getMonth()]} ${now.getFullYear()+543}`;
})();

/* Quick search filter */
document.addEventListener('DOMContentLoaded', function () {
  const cards = Array.from(document.querySelectorAll('.shortcut-card'));
  const searchBox = document.querySelector('#quickSearch');
  if (!searchBox) return;
  searchBox.addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    cards.forEach(c => {
      const text = (c.innerText + ' ' + (c.dataset.label || '')).toLowerCase();
      c.closest('.col-12').style.display = (!q || text.includes(q)) ? '' : 'none';
    });
  });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
