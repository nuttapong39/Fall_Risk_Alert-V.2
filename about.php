<?php
/**
 * about.php — "เกี่ยวกับระบบ"
 *   รวมคู่มือติดตั้ง (docs/install-guide.html) และคู่มืออัปเดต (docs/update-guide.html)
 *   ไว้ในหน้าเดียวของแอป ให้ผู้ใช้เข้ามาอ่าน/ทำความเข้าใจระบบได้โดยไม่ต้องออกจากแอป
 *   ฝังผ่าน <iframe> (ไม่ inline เนื้อหา) เพราะทั้งสองไฟล์มี CSS ของตัวเองเต็มรูปแบบ
 *   (คนละ design system จากแอปหลัก) การรวมตรงๆ จะเสี่ยง class ชนกัน
 */
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/config.php';

$PAGE_TITLE = 'เกี่ยวกับระบบ';
$PAGE_KEY   = 'about';
$EXTRA_HEAD = <<<'CSS'
<style>
.about-tabs .nav-link {
  font-family: var(--font-heading, inherit);
  font-weight: 600;
  font-size: .9rem;
  color: var(--muted);
  border: none;
  border-bottom: 3px solid transparent;
  border-radius: 0;
  padding: 10px 4px;
  margin-right: 24px;
}
.about-tabs .nav-link .msi { font-size: 1.1rem; vertical-align: -3px; margin-right: 5px }
.about-tabs .nav-link.active { color: var(--blue); border-bottom-color: var(--blue); background: none }
.about-frame-wrap { border: 1px solid var(--card-border); border-radius: 12px; overflow: hidden; background: #fff; }
.about-frame { width: 100%; height: calc(100vh - 250px); min-height: 520px; border: 0; display: block; }
.about-openlink { font-size: .82rem; color: var(--muted); display: flex; align-items: center; gap: 5px; }
.about-openlink a { color: var(--blue); font-weight: 600 }
</style>
CSS;

require_once __DIR__ . '/partials/header.php';
?>

<div class="page-header">
  <h1><span class="msi me-2" style="color:var(--blue)">info</span><?= htmlspecialchars($PAGE_TITLE) ?></h1>
  <p style="margin:4px 0 0; font-size:.88rem; color:var(--muted)">
    คู่มือติดตั้งและอัปเดตระบบ MedAlert — สำหรับผู้ดูแลระบบ / IT โรงพยาบาล
  </p>
</div>

<ul class="nav about-tabs mb-3" id="aboutTab" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="tab-install-btn" data-bs-toggle="tab" data-bs-target="#tab-install"
            type="button" role="tab" aria-controls="tab-install" aria-selected="true">
      <span class="msi">rocket_launch</span>คู่มือติดตั้ง
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-update-btn" data-bs-toggle="tab" data-bs-target="#tab-update"
            type="button" role="tab" aria-controls="tab-update" aria-selected="false">
      <span class="msi">cloud_sync</span>คู่มืออัปเดต
    </button>
  </li>
</ul>

<div class="tab-content" id="aboutTabContent">
  <div class="tab-pane fade show active" id="tab-install" role="tabpanel" aria-labelledby="tab-install-btn">
    <div class="d-flex justify-content-end mb-2">
      <span class="about-openlink">
        <span class="msi" style="font-size:1rem">open_in_new</span>
        <a href="docs/install-guide.html" target="_blank" rel="noopener">เปิดคู่มือติดตั้งในแท็บใหม่</a>
      </span>
    </div>
    <div class="about-frame-wrap">
      <iframe class="about-frame" src="docs/install-guide.html" title="คู่มือติดตั้ง MedAlert" loading="lazy"></iframe>
    </div>
  </div>
  <div class="tab-pane fade" id="tab-update" role="tabpanel" aria-labelledby="tab-update-btn">
    <div class="d-flex justify-content-end mb-2">
      <span class="about-openlink">
        <span class="msi" style="font-size:1rem">open_in_new</span>
        <a href="docs/update-guide.html" target="_blank" rel="noopener">เปิดคู่มืออัปเดตในแท็บใหม่</a>
      </span>
    </div>
    <div class="about-frame-wrap">
      <iframe class="about-frame" src="docs/update-guide.html" title="คู่มืออัปเดต MedAlert" loading="lazy"></iframe>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
