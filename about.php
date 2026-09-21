<?php
/**
 * about.php — "เกี่ยวกับระบบ"
 *   รวมหน้า "มีอะไรใหม่" (docs/release-notes.html), คู่มือติดตั้ง (docs/install-guide.html)
 *   และคู่มืออัปเดต (docs/update-guide.html) ไว้ในหน้าเดียวของแอป
 *   ฝังผ่าน <iframe> (ไม่ inline เนื้อหา) เพราะทั้งสามไฟล์มี CSS ของตัวเองเต็มรูปแบบ
 *   (คนละ design system จากแอปหลัก) การรวมตรงๆ จะเสี่ยง class ชนกัน
 *
 *   แท็บ "มีอะไรใหม่" เป็นแท็บแรก เพราะ badge บน sidebar พาคนมาที่นี่ —
 *   และการเปิดหน้านี้คือสิ่งที่เคลียร์ badge (ดู <script> ท้ายไฟล์)
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
    สิ่งที่เปลี่ยนในแต่ละรุ่น พร้อมคู่มือติดตั้งและอัปเดตระบบ MedAlert
  </p>
</div>

<ul class="nav about-tabs mb-3" id="aboutTab" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="tab-news-btn" data-bs-toggle="tab" data-bs-target="#tab-news"
            type="button" role="tab" aria-controls="tab-news" aria-selected="true">
      <span class="msi">campaign</span>มีอะไรใหม่
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-install-btn" data-bs-toggle="tab" data-bs-target="#tab-install"
            type="button" role="tab" aria-controls="tab-install" aria-selected="false">
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
  <div class="tab-pane fade show active" id="tab-news" role="tabpanel" aria-labelledby="tab-news-btn">
    <div class="d-flex justify-content-end mb-2">
      <span class="about-openlink">
        <span class="msi" style="font-size:1rem">open_in_new</span>
        <a href="docs/release-notes.html" target="_blank" rel="noopener">เปิดหน้ามีอะไรใหม่ในแท็บใหม่</a>
      </span>
    </div>
    <div class="about-frame-wrap">
      <iframe class="about-frame" src="docs/release-notes.html" title="มีอะไรใหม่ใน MedAlert"></iframe>
    </div>
  </div>
  <div class="tab-pane fade" id="tab-install" role="tabpanel" aria-labelledby="tab-install-btn">
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

<?php
/* เปิดหน้านี้ = ถือว่าอ่าน release note แล้ว → จำเวอร์ชันใหม่สุดไว้ แล้วซ่อน badge ทันที
   (ไม่ต้องรอ refresh หน้าอื่น) · ถ้าไฟล์ release-notes.html หายไปจะได้ค่าว่าง แล้วข้ามทั้งบล็อก */
$rnLatest = release_notes_versions()[0] ?? '';
if ($rnLatest !== ''):
?>
<script>
(function () {
  try { localStorage.setItem('ckh-seen-release', <?= json_encode($rnLatest, JSON_UNESCAPED_UNICODE) ?>); } catch (e) {}
  var b = document.getElementById('navAboutBadge');
  if (b) b.hidden = true;
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
