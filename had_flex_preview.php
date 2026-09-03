<?php
/**
 * had_flex_preview.php — ดูตัวอย่างการ์ด Flex ของ HAD Alert
 *
 * ใช้หน้าตาการ์ดชุดเดียวกับ flex_editor.php (ดีไซน์ปัจจุบัน) — สี/หัวเรื่อง/footer
 * อ่านจาก flex_theme('had') ทุกจุด จึงเปลี่ยนตามที่แก้ใน flex_editor ทันที
 *
 * ?id=<queue id>  → ใช้ข้อมูลแถวจริงจากคิว · ไม่ระบุ = ใช้แถวล่าสุด หรือข้อมูลตัวอย่าง
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_builders.php';
require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$row = null;
try {
  if (!empty($_GET['id']) && ctype_digit((string)$_GET['id'])) {
    $st = $dbcon->prepare("SELECT * FROM had_queue WHERE id=?");
    $st->execute([(int)$_GET['id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
  if (!$row) {
    $row = $dbcon->query("SELECT * FROM had_queue ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
  }
} catch (Throwable $ex) { $row = null; }

$isSample = ($row === null);
if ($isSample) {
  $row = ['hn'=>'0000150','fullname'=>'นางตัวอย่าง ทดสอบ','age'=>68,'sex'=>'2',
          'icode'=>'1431107','drug_name'=>'WARFARIN (สีฟ้า)[HAD]','vstdate'=>date('Y-m-d'),
          'qty'=>24,'sum_price'=>78.00,'hometel'=>'08X-XXX-XXXX'];
}

$t = flex_theme('had');
$g = flex_theme_global();
$L = $g['labels'];

$payload = buildHadPayload($row);
$json    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

$badColors = [];
array_walk_recursive($payload, function ($v, $k) use (&$badColors) {
  if (in_array($k, ['color','backgroundColor','startColor','endColor'], true)
      && $v !== '' && !preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string)$v)) $badColors[] = "$k = $v";
});

$grad = 'linear-gradient(' . (int)$t['gradient_angle'] . 'deg,' . $t['color_start'] . ',' . $t['color_end'] . ')';

$PAGE_TITLE = 'ตัวอย่าง Flex — HAD Alert';
$PAGE_KEY   = 'had';
$EXTRA_HEAD = <<<HTML
<style>
.fe{max-width:420px}
.fe .fe-card{background:#fff;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.14);padding:12px;font-size:13px;color:#0F172A}
.fe .hd{border-radius:14px;padding:13px 15px;color:#fff;position:relative;overflow:hidden}
.fe .hd h2{margin:0;font-size:16px;font-weight:800;position:relative}
.fe .hd .en{font-size:11px;opacity:.85;margin-top:2px;position:relative}
.fe .bd{padding:12px 6px 2px}
.fe .lb{font-size:10px;font-weight:700;text-transform:uppercase;color:#94A3B8;margin:14px 0 5px}
.fe .lb:first-child{margin-top:2px}
.fe .hn{font-size:14px;font-weight:800} .fe .nm{font-size:15px;font-weight:700} .fe .mt{color:#64748B;font-size:12px;margin-top:2px}
.fe .kv{display:flex;justify-content:space-between;gap:10px;padding:3px 0} .fe .kv .k{color:#64748B} .fe .kv .v{font-weight:700;text-align:right}
.fe .icd{display:flex;justify-content:space-between;align-items:center;margin:4px 0} .fe .icd .l{font-weight:700} .fe .icd .r{font-size:20px;font-weight:800}
.fe .pill{display:inline-block;padding:2px 10px;border-radius:6px;color:#fff;font-weight:800;font-size:12px}
.fe .ph{font-size:15px;font-weight:800;margin-top:6px}.fe .ph .i{color:#94A3B8;font-weight:400}
.fe .ft{border-top:1px solid #F1F5F9;margin-top:12px;padding-top:9px;color:#94A3B8;font-size:10px;display:flex;justify-content:space-between}
.hadp-json{background:#0f172a;color:#e2e8f0;border-radius:12px;padding:14px;font-size:11.5px;max-height:460px;overflow:auto}
</style>
HTML;

require_once __DIR__ . '/partials/header.php';

$thai = function_exists('flex_thai_date') ? flex_thai_date($row['vstdate'] ?? '') : (string)($row['vstdate'] ?? '');
$sex  = function_exists('flex_agesex') ? flex_agesex($row['age'] ?? '', $row['sex'] ?? '') : (string)($row['age'] ?? '');
$qtyLine = trim(($row['qty'] ?? '-') . (($row['sum_price'] ?? null) !== null ? ' · ' . number_format((float)$row['sum_price'], 2) . ' บาท' : ''));
?>

<div class="page-header">
  <h1><span class="msi me-2" style="color:<?= $e($t['accent']) ?>">smartphone</span><?= $e($PAGE_TITLE) ?></h1>
  <div class="d-flex gap-2">
    <a href="flex_editor.php?m=had" class="btn btn-outline-primary btn-sm">
      <span class="msi me-1">palette</span>แก้สี/ข้อความการ์ด
    </a>
    <a href="had_queue_ui.php" class="btn btn-outline-secondary btn-sm">
      <span class="msi me-1">arrow_back</span>กลับหน้าคิว
    </a>
  </div>
</div>

<?php if ($isSample): ?>
  <div class="alert alert-info py-2" style="border-radius:10px;font-size:.85rem">
    <span class="msi me-1">info</span>ยังไม่มีข้อมูลในคิว — กำลังแสดงด้วย<b>ข้อมูลตัวอย่าง</b>
  </div>
<?php endif; ?>

<?php if ($badColors): ?>
  <div class="alert alert-danger py-2" style="border-radius:10px;font-size:.85rem">
    <span class="msi me-1">error</span><b>พบสีที่ LINE ไม่รองรับ <?= count($badColors) ?> จุด</b> —
    การ์ดจะไม่ขึ้นในไลน์ทั้งใบ (แต่ MOPH ยังตอบ 200): <?= $e(implode(', ', $badColors)) ?>
  </div>
<?php else: ?>
  <div class="alert alert-success py-2" style="border-radius:10px;font-size:.85rem">
    <span class="msi me-1">check_circle</span>ตรวจสีแล้วเป็น hex ครบทุกจุด — ปลอดภัยกับ LINE
  </div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="fe">
      <div class="fe-card">
        <div class="hd" style="background:<?= $e($grad) ?>">
          <h2><?= $e($t['title']) ?></h2>
          <div class="en"><?= $e(trim($t['subtitle'] . ($t['urgency'] ? '   ·   ' . $t['urgency'] : ''))) ?></div>
        </div>
        <div class="bd">
          <div class="lb"><?= $e($L['sec_patient']) ?></div>
          <div class="hn">HN <?= $e($row['hn']) ?></div>
          <div class="nm"><?= $e($row['fullname']) ?></div>
          <div class="mt"><?= $e($sex) ?></div>

          <div class="lb">รายการยา HAD</div>
          <div class="icd">
            <span class="l" style="color:<?= $e($t['accent']) ?>">รหัสยา (icode)</span>
            <span class="r" style="font-size:16px"><?= $e($row['icode']) ?></span>
          </div>
          <div class="kv"><span class="k">ชื่อยา</span><span class="v"><?= $e($row['drug_name']) ?></span></div>
          <div class="kv"><span class="k">จำนวน</span><span class="v"><?= $e($qtyLine) ?></span></div>
          <div class="kv"><span class="k">วันที่รับยา</span><span class="v"><?= $e($thai) ?></span></div>

          <?php if (!empty($row['hometel'])): ?>
            <div class="lb"><?= $e($L['sec_contact']) ?></div>
            <div class="ph"><span class="i">☎</span> <?= $e($row['hometel']) ?></div>
          <?php endif; ?>

          <div class="ft">
            <span><?= $e($g['footer_text'] . ($g['hospital_name'] ? '  ·  ' . $g['hospital_name'] : '')) ?></span>
            <span><?= $e($thai) ?></span>
          </div>
        </div>
      </div>
      <div class="text-center text-muted mt-2" style="font-size:.78rem">
        พรีวิว — สี/หัวเรื่อง/footer อ่านจาก <code>flex_theme('had')</code> ตัวเดียวกับที่ส่งจริง
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h6 class="mb-0">Payload ที่จะส่งจริง</h6>
      <span class="text-muted" style="font-size:.78rem">altText: <?= $e(mb_substr($payload['messages'][0]['altText'] ?? '', 0, 90)) ?></span>
    </div>
    <pre class="hadp-json"><?= $e($json) ?></pre>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
