<?php
/**
 * fracture_flex_preview.php
 * หน้าแสดงตัวอย่าง Flex message สำหรับเจ้าหน้าที่
 *   - /fracture_flex_preview.php            → ใช้ข้อมูลตัวอย่าง
 *   - /fracture_flex_preview.php?id=123     → ดึงข้อมูลจริงของแถว id=123
 *
 * ใช้ HTML/CSS แทน Line Flex จริง แต่จัดวางเหมือน Flex บนมือถือ
 * เพื่อให้ จนท. ตรวจรูปแบบก่อนส่งจริง
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_fracture.php';

date_default_timezone_set('Asia/Bangkok');

/* ---------- โหลดข้อมูล ---------- */
$row = null;
$id  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
  $stmt = $dbcon->prepare("SELECT * FROM fracture_queue WHERE id=:id");
  $stmt->execute([':id'=>$id]);
  $row = $stmt->fetch();
  if ($row) $row = row_to_utf8($row);
}

/* ตัวอย่าง (ใช้เมื่อไม่มี id หรือไม่พบ) */
if (!$row) {
  $row = [
    'id'          => 'ตัวอย่าง',
    'visit_vn'    => '680422001234',
    'hn'          => '12345678',
    'fullname'    => 'นาง สมศรี ใจดี',
    'age'         => 72,
    'sex'         => 'หญิง',
    'address'     => '123 ม.5 ต.เชียงกลาง อ.เชียงกลาง จ.น่าน 55160',
    'hometel'     => '081-234-5678',
    'pdx_code'    => 'S7200',
    'pdx_name'    => 'Fracture of neck of femur, closed',
    'vstdate'     => date('Y-m-d'),
    'mainstation' => 'รพ.สต.บ้านเชียงกลาง (07631)',
  ];
}

/* ---------- สร้าง payload เพื่อใช้ทั้งในการ preview + debug JSON ---------- */
$payload = buildFracturePayload($row);
$jsonPretty = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

/* ---------- ค่าจัดรูปแบบเพื่อ render HTML mock ---------- */
$hn       = $row['hn'] ?? '-';
$fullname = $row['fullname'] ?? '-';
$age      = $row['age'] ?? '';
$sex      = $row['sex'] ?? '';
$ageSex   = ($age !== '' && $sex !== '') ? "{$age} ปี · {$sex}" : (string)($age ?: $sex ?: '-');
$address  = $row['address'] ?? '-';
$tel      = $row['hometel'] ?? '-';
$pdxCode  = $row['pdx_code'] ?? '-';
$pdxName  = $row['pdx_name'] ?? '';
$vstdate  = fr_thai_date($row['vstdate'] ?? null);
$station  = $row['mainstation'] ?? '-';
$refId    = $row['visit_vn'] ?? ($row['id'] ?? '');

$PAGE_TITLE = 'ตัวอย่าง Flex message';
$PAGE_KEY   = 'fracture';
$EXTRA_HEAD = '<style>
  /* ---------- Preview stage ---------- */
  .flex-preview-wrap { display:grid; grid-template-columns: minmax(340px, 420px) 1fr; gap: 1.25rem; align-items:start }
  @media (max-width: 1100px){ .flex-preview-wrap { grid-template-columns: 1fr } }

  .stage { background:#E5E7EB; padding:1.5rem; border-radius:1rem; min-height:420px;
           display:flex; justify-content:center; align-items:flex-start; border:1px solid #D1D5DB }

  /* ---------- Phone frame (ดูเหมือนหน้า LINE) ---------- */
  .flex-bubble {
    width:100%; max-width:420px; background:#F9FAFB; border-radius:14px;
    box-shadow: 0 12px 30px rgba(15,23,42,.15); overflow:hidden;
    font-family: "Kanit", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    font-size: 1.02rem;
  }
  .flex-header img { width:100%; display:block }
  .flex-header.placeholder {
    height:70px; background:linear-gradient(135deg,#0F2A5B,#1E40AF);
    display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:.9rem;
  }

  /* Title strip (navy) */
  .flex-title { background:#0F2A5B; color:#fff; padding:16px }
  .flex-title .meta { display:flex; justify-content:space-between; font-size:.72rem }
  .flex-title .meta .left  { color:#FDE68A; font-weight:700 }
  .flex-title .meta .right { color:#BFDBFE }
  .flex-title h2 { margin:.35rem 0 .15rem; font-size:1.15rem; font-weight:700; line-height:1.35 }
  .flex-title p  { margin:0; font-size:.72rem; color:#CBD5E1 }

  .flex-body { padding:14px; background:#F9FAFB }
  .priority {
    background:#FEE2E2; color:#991B1B; border-radius:8px; padding:10px;
    display:flex; gap:.5rem; align-items:flex-start; font-weight:700; font-size:.72rem;
  }
  .priority .icon { color:#B91C1C; font-size:1rem; line-height:1 }

  /* Section card */
  .sect { border:1px solid #E5E7EB; background:#fff; border-radius:12px; padding:14px; margin-top:12px }
  .sect h4 { margin:0 0 .5rem; font-size:.7rem; text-transform:none; letter-spacing:.2px; font-weight:700 }
  .sect.patient h4 { color:#0F2A5B }
  .sect.dx      { background:#FFFBEB; border-color:#FDE68A }
  .sect.dx h4   { color:#92400E }
  .sect.contact { background:#ECFDF5; border-color:#A7F3D0 }
  .sect.contact h4 { color:#065F46 }
  .sect.action  { background:#EFF6FF; border-color:#BFDBFE }
  .sect.action h4  { color:#1E3A8A }

  .kv { display:flex; align-items:baseline; gap:.75rem; padding:.35rem 0 }
  .kv + .kv { border-top:1px solid #F3F4F6 }
  .kv .k { color:#6B7280; font-size:.75rem; flex:3 }
  .kv .v { color:#111827; font-size:.85rem; flex:5; text-align:right; word-break:break-word }
  .kv .v.lg { font-size:1rem; font-weight:700 }
  .kv .v.bold { font-weight:700 }
  .kv .v.money { color:#065F46; font-weight:700; font-size:1rem }

  .dx-code-row { display:flex; justify-content:space-between; align-items:center;
                 padding:.35rem 0; border-top:1px solid #FDE68A; margin-top:.35rem }
  .dx-code-row .label { color:#92400E; font-weight:700; font-size:.72rem }
  .dx-code-row .code  { color:#78350F; font-weight:800; font-size:1.15rem }
  .dx-name { color:#1F2937; font-size:.82rem; margin-top:.3rem }

  .contact-addr .addr-label { color:#6B7280; font-size:.72rem; font-weight:700 }
  .contact-addr p { margin:.2rem 0 0; font-size:.85rem; color:#111827 }

  .action-list { margin:.25rem 0 0; padding-left:1rem; color:#1F2937; font-size:.75rem }
  .action-list li { margin-bottom:.25rem }
  .action-cta { color:#1E3A8A; font-weight:700; font-size:.85rem; margin:.35rem 0 .15rem }

  .flex-footer {
    background:#F3F4F6; padding:10px 14px 12px; border-top:1px solid #E5E7EB;
    color:#6B7280; font-size:.65rem; display:flex; justify-content:space-between; gap:.5rem; flex-wrap:wrap;
  }
  .flex-footer .ref { color:#9CA3AF; width:100% }

  /* ---------- JSON & meta panel ---------- */
  .json-panel {
    background:#0f172a; color:#e2e8f0; border-radius:12px; padding:1rem;
    max-height: 680px; overflow:auto; font-size:.72rem; font-family: ui-monospace,Consolas,monospace;
  }
  .meta-card { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:1rem; margin-bottom:1rem }
  .meta-card h5 { margin:0 0 .5rem; font-size:.9rem; font-weight:700; color:#0f172a }
  .meta-card .badge { font-size:.7rem; background:#eef2ff; color:#3730a3; padding:.2rem .55rem; border-radius:999px; font-weight:600 }
</style>';

require_once __DIR__ . '/partials/header.php';
?>

<div class="page-header">
  <h1><span class="msi text-primary me-2">chat</span>ตัวอย่าง Flex message — แจ้งเตือนผู้ป่วยกลุ่มเสี่ยงหกล้ม</h1>
  <div class="d-flex gap-2">
    <a href="fracture_queue_ui.php" class="btn btn-outline-secondary">
      <span class="msi me-1">arrow_back</span> กลับคิวแจ้งเตือน
    </a>
  </div>
</div>

<?php if ($id > 0 && !isset($row['id'])): ?>
  <div class="alert alert-warning">ไม่พบ id <?= (int)$id ?> ในคิว — แสดงเป็น <strong>ข้อมูลตัวอย่าง</strong>แทน</div>
<?php elseif ($id === 0): ?>
  <div class="alert alert-info">
    <span class="msi me-1">info</span>
    แสดง <strong>ข้อมูลตัวอย่าง</strong> — เพิ่ม <code>?id=XXX</code> ต่อท้าย URL เพื่อดูของแถวจริง
    (หรือเปิดจากปุ่ม <em>ดูตัวอย่าง</em> ในหน้าคิว)
  </div>
<?php endif; ?>

<div class="flex-preview-wrap">

  <!-- ===== Phone preview (HTML mock ของ Flex bubble) ===== -->
  <div class="stage">
    <div class="flex-bubble">
      <!-- header banner -->
      <div class="flex-header <?= defined('FALL_HEADER_URL') && FALL_HEADER_URL ? '' : 'placeholder' ?>">
        <?php if (defined('FALL_HEADER_URL') && FALL_HEADER_URL): ?>
          <img src="<?= htmlspecialchars(FALL_HEADER_URL) ?>" alt="MOPH banner"
               onerror="this.parentElement.classList.add('placeholder'); this.remove(); this.parentElement.innerHTML='กระทรวงสาธารณสุข';">
        <?php else: ?>
          กระทรวงสาธารณสุข
        <?php endif; ?>
      </div>

      <!-- navy title strip -->
      <div class="flex-title">
        <div class="meta">
          <span class="left">🚨 แจ้งเตือนผู้ป่วยกลุ่มเสี่ยง</span>
          <span class="right">Fall Risk Alert</span>
        </div>
        <h2><?= htmlspecialchars(FALL_TITLE) ?></h2>
        <p><?= htmlspecialchars(FALL_SUBTITLE) ?></p>
      </div>

      <!-- body -->
      <div class="flex-body">
        <!-- priority -->
        <div class="priority">
          <span class="icon">⚠</span>
          <span>ผู้สูงอายุ ≥ 60 ปี · วินิจฉัยกลุ่ม W00–W19 / S-codes กระดูกหัก</span>
        </div>

        <!-- ข้อมูลผู้ป่วย -->
        <div class="sect patient">
          <h4>🧑‍⚕️  ข้อมูลผู้ป่วย</h4>
          <div class="kv"><span class="k">HN</span><span class="v lg"><?= htmlspecialchars($hn) ?></span></div>
          <div class="kv"><span class="k">ชื่อ-สกุล</span><span class="v bold"><?= htmlspecialchars($fullname) ?></span></div>
          <div class="kv"><span class="k">อายุ / เพศ</span><span class="v"><?= htmlspecialchars($ageSex) ?></span></div>
        </div>

        <!-- การวินิจฉัย -->
        <div class="sect dx">
          <h4>🩺  การวินิจฉัยและการรับบริการ</h4>
          <div class="dx-code-row">
            <span class="label">ICD-10</span>
            <span class="code"><?= htmlspecialchars($pdxCode) ?></span>
          </div>
          <?php if (!empty($pdxName)): ?>
            <div class="dx-name"><?= htmlspecialchars($pdxName) ?></div>
          <?php endif; ?>
          <div class="kv"><span class="k">วันที่รับบริการ</span><span class="v"><?= htmlspecialchars($vstdate) ?></span></div>
          <div class="kv"><span class="k">สถานบริการหลัก</span><span class="v"><?= htmlspecialchars($station) ?></span></div>
        </div>

        <!-- ติดต่อ / เยี่ยมบ้าน -->
        <div class="sect contact">
          <h4>🏠  ข้อมูลสำหรับติดตามเยี่ยมบ้าน</h4>
          <div class="contact-addr">
            <span class="addr-label">📍 ที่อยู่</span>
            <p><?= htmlspecialchars($address) ?></p>
          </div>
          <div class="kv">
            <span class="k">📞 เบอร์โทร</span>
            <span class="v money"><?= htmlspecialchars($tel) ?></span>
          </div>
        </div>

        <!-- คำแนะนำ -->
        <div class="sect action">
          <h4>📋  คำแนะนำสำหรับเจ้าหน้าที่ รพ.สต.</h4>
          <div class="action-cta">โปรดดำเนินการภายใน 7 วัน</div>
          <ul class="action-list">
            <li>ติดตามเยี่ยมบ้าน ประเมินการฟื้นตัวของผู้ป่วย</li>
            <li>ประเมินปัจจัยเสี่ยงในบ้าน (พื้นลื่น แสงสว่าง ราวจับ ห้องน้ำ)</li>
            <li>ให้ความรู้การป้องกันการพลัดตกซ้ำ</li>
            <li>บันทึกผลการเยี่ยมในระบบ HDC / JHCIS</li>
          </ul>
        </div>
      </div>

      <!-- footer -->
      <div class="flex-footer">
        <span><?= htmlspecialchars(FALL_SYSTEM_NAME) ?></span>
        <span><?= date('j M Y H:i') ?></span>
        <?php if ($refId !== ''): ?>
          <span class="ref">Ref: <?= htmlspecialchars((string)$refId) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ===== Side panel: meta + raw JSON ===== -->
  <div>
    <div class="meta-card">
      <h5>รายละเอียดที่จะส่ง <span class="badge">Preview</span></h5>
      <table class="table table-sm mb-0">
        <tr><td class="text-muted" style="width:36%">HN</td><td><strong><?= htmlspecialchars($hn) ?></strong></td></tr>
        <tr><td class="text-muted">ชื่อ-สกุล</td><td><?= htmlspecialchars($fullname) ?></td></tr>
        <tr><td class="text-muted">อายุ / เพศ</td><td><?= htmlspecialchars($ageSex) ?></td></tr>
        <tr><td class="text-muted">ICD-10</td><td><code><?= htmlspecialchars($pdxCode) ?></code> <?= htmlspecialchars($pdxName) ?></td></tr>
        <tr><td class="text-muted">วันรับบริการ</td><td><?= htmlspecialchars($vstdate) ?></td></tr>
        <tr><td class="text-muted">สถานบริการ</td><td><?= htmlspecialchars($station) ?></td></tr>
        <tr><td class="text-muted">โทรฯ</td><td><?= htmlspecialchars($tel) ?></td></tr>
        <tr><td class="text-muted">ขนาด JSON</td>
            <td><?= number_format(strlen($jsonPretty)) ?> ตัวอักษร
                <span class="text-muted">(จำกัด 50,000 ของ LINE Flex)</span></td></tr>
      </table>
    </div>

    <div class="meta-card">
      <h5>ขั้นตอนถัดไป</h5>
      <ol class="mb-0 small">
        <li>ตรวจสอบว่าข้อมูลครบถ้วน ถูกต้อง</li>
        <li>กลับไปหน้าคิว แล้วกด <strong>"ส่งซ้ำทันที"</strong> เพื่อส่งเข้า MOPH Notify</li>
        <li>ตรวจดูสถานะในคิวว่าเปลี่ยนเป็น <em>ส่งแล้ว</em> และมี Message ID</li>
      </ol>
    </div>

    <details class="meta-card">
      <summary class="fw-bold" style="cursor:pointer">ดู JSON payload ที่จะส่ง MOPH</summary>
      <pre class="json-panel mt-2 mb-0"><?= htmlspecialchars($jsonPretty) ?></pre>
    </details>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
