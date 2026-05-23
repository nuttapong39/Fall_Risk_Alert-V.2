<?php
/**
 * patient_flex_preview.php
 * หน้าแสดงตัวอย่าง Flex message สำหรับเจ้าหน้าที่ (จิตเวช / ทำร้ายตนเอง)
 *   - /patient_flex_preview.php            → ใช้ข้อมูลตัวอย่าง
 *   - /patient_flex_preview.php?id=123     → ดึงข้อมูลจริงของแถว id=123 ใน patient_queue
 *
 * ใช้ HTML/CSS แทน Line Flex จริง แต่จัดวางเหมือน Flex บนมือถือ
 * เพื่อให้ จนท. ตรวจรูปแบบก่อนส่งจริง
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_patient.php';

date_default_timezone_set('Asia/Bangkok');

/* ---------- โหลดข้อมูล ---------- */
$row = null;
$id  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
  try {
    $stmt = $dbcon->prepare("SELECT * FROM patient_queue WHERE id=:id");
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch();
    if ($row) $row = row_to_utf8($row);
  } catch (Throwable $e) {
    $row = null;
  }
}

/* ตัวอย่าง (ใช้เมื่อไม่มี id หรือไม่พบ) */
if (!$row) {
  $row = [
    'id'          => 'ตัวอย่าง',
    'visit_vn'    => '690423001234',
    'hn'          => '22345678',
    'fullname'    => 'นาย สมชาย ใจดี',
    'age'         => 34,
    'sex'         => 'ชาย',
    'address'     => '456 ม.3 ต.เชียงกลาง อ.เชียงกลาง จ.น่าน 55160',
    'hometel'     => '082-345-6789',
    'pdx_code'    => 'X84',
    'pdx_name'    => 'Intentional self-harm by unspecified means',
    'vstdate'     => date('Y-m-d'),
    'mainstation' => 'รพ.สต.บ้านเชียงกลาง (07631)',
  ];
}

/* ---------- สร้าง payload ---------- */
$payload = buildPatientPayload($row);
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

/* ป้ายกลุ่ม ICD (เหมือนกับใน flex_patient.php) */
$code = strtoupper(trim((string)$pdxCode));
if (strpos($code,'T71')===0)       $categoryLabel = 'Asphyxiation (T71)';
elseif (strpos($code,'X60')===0)   $categoryLabel = 'Self-poisoning (X60)';
elseif (strpos($code,'X61')===0)   $categoryLabel = 'Self-poisoning — Antiepileptic/sedative (X61)';
elseif (strpos($code,'X62')===0)   $categoryLabel = 'Self-poisoning — Narcotics (X62)';
elseif (strpos($code,'X63')===0)   $categoryLabel = 'Self-poisoning — Other nervous system drugs (X63)';
elseif (strpos($code,'X64')===0)   $categoryLabel = 'Self-poisoning — Other medications (X64)';
elseif (strpos($code,'X65')===0)   $categoryLabel = 'Self-poisoning — Alcohol (X65)';
elseif (strpos($code,'X66')===0)   $categoryLabel = 'Self-poisoning — Organic solvents (X66)';
elseif (strpos($code,'X67')===0)   $categoryLabel = 'Self-poisoning — Gases/vapours (X67)';
elseif (strpos($code,'X68')===0)   $categoryLabel = 'Self-poisoning — Pesticides (X68)';
elseif (strpos($code,'X69')===0)   $categoryLabel = 'Self-poisoning — Other chemicals (X69)';
elseif (strpos($code,'X70')===0)   $categoryLabel = 'Self-harm by hanging (X70)';
elseif (strpos($code,'X84')===0)   $categoryLabel = 'Self-harm — Unspecified means (X84)';
else                                $categoryLabel = 'ผู้ป่วยกลุ่มเสี่ยงจิตเวช';

$PAGE_TITLE = 'ตัวอย่าง Flex message';
$PAGE_KEY   = 'patient';
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
    height:70px; background:linear-gradient(135deg,#7F1D1D,#991B1B);
    display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:.9rem;
  }

  /* Title strip (deep red) */
  .flex-title { background:#7F1D1D; color:#fff; padding:16px }
  .flex-title .meta { display:flex; justify-content:space-between; font-size:.72rem }
  .flex-title .meta .left  { color:#FDE68A; font-weight:700 }
  .flex-title .meta .right { color:#FECACA }
  .flex-title h2 { margin:.35rem 0 .15rem; font-size:1.15rem; font-weight:700; line-height:1.35 }
  .flex-title p  { margin:0; font-size:.72rem; color:#FECACA }

  .flex-body { padding:14px; background:#F9FAFB }
  .priority {
    background:#FEE2E2; color:#7F1D1D; border-radius:8px; padding:10px;
    display:flex; gap:.5rem; align-items:flex-start; font-weight:700; font-size:.72rem;
  }
  .priority .icon { color:#B91C1C; font-size:1rem; line-height:1 }

  /* Section card */
  .sect { border:1px solid #E5E7EB; background:#fff; border-radius:12px; padding:14px; margin-top:12px }
  .sect h4 { margin:0 0 .5rem; font-size:.7rem; text-transform:none; letter-spacing:.2px; font-weight:700 }
  .sect.patient h4 { color:#7F1D1D }
  .sect.dx      { background:#FFF1F2; border-color:#FECDD3 }
  .sect.dx h4   { color:#9F1239 }
  .sect.contact { background:#ECFDF5; border-color:#A7F3D0 }
  .sect.contact h4 { color:#065F46 }
  .sect.action  { background:#FFF7ED; border-color:#FED7AA }
  .sect.action h4  { color:#7F1D1D }

  .kv { display:flex; align-items:baseline; gap:.75rem; padding:.35rem 0 }
  .kv + .kv { border-top:1px solid #F3F4F6 }
  .kv .k { color:#6B7280; font-size:.75rem; flex:3 }
  .kv .v { color:#111827; font-size:.85rem; flex:5; text-align:right; word-break:break-word }
  .kv .v.lg { font-size:1rem; font-weight:700 }
  .kv .v.bold { font-weight:700 }
  .kv .v.money { color:#065F46; font-weight:700; font-size:1rem }

  .dx-code-row { display:flex; justify-content:space-between; align-items:center;
                 padding:.35rem 0; border-top:1px solid #FECDD3; margin-top:.35rem }
  .dx-code-row .label { color:#9F1239; font-weight:700; font-size:.72rem }
  .dx-code-row .code  { color:#881337; font-weight:800; font-size:1.15rem }
  .dx-category { color:#9F1239; font-size:.72rem; margin-top:.2rem }
  .dx-name { color:#1F2937; font-size:.82rem; margin-top:.3rem }

  .contact-addr .addr-label { color:#6B7280; font-size:.72rem; font-weight:700 }
  .contact-addr p { margin:.2rem 0 0; font-size:.85rem; color:#111827 }

  .action-list { margin:.25rem 0 0; padding-left:1rem; color:#1F2937; font-size:.75rem }
  .action-list li { margin-bottom:.25rem }
  .action-cta { color:#7F1D1D; font-weight:700; font-size:.85rem; margin:.35rem 0 .15rem }

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
  .meta-card .badge { font-size:.7rem; background:#fef2f2; color:#7f1d1d; padding:.2rem .55rem; border-radius:999px; font-weight:600 }
</style>';

require_once __DIR__ . '/partials/header.php';
?>

<div class="page-header">
  <h1><span class="msi text-danger me-2">chat</span>ตัวอย่าง Flex message — แจ้งเตือนผู้ป่วยจิตเวช / ทำร้ายตนเอง</h1>
  <div class="d-flex gap-2">
    <a href="patient.php" class="btn btn-outline-secondary">
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
      <div class="flex-header <?= defined('PSYCH_HEADER_URL') && PSYCH_HEADER_URL ? '' : 'placeholder' ?>">
        <?php if (defined('PSYCH_HEADER_URL') && PSYCH_HEADER_URL): ?>
          <img src="<?= htmlspecialchars(PSYCH_HEADER_URL) ?>" alt="MOPH banner"
               onerror="this.parentElement.classList.add('placeholder'); this.remove(); this.parentElement.innerHTML='กระทรวงสาธารณสุข';">
        <?php else: ?>
          กระทรวงสาธารณสุข
        <?php endif; ?>
      </div>

      <!-- deep-red title strip -->
      <div class="flex-title">
        <div class="meta">
          <span class="left">🆘 แจ้งเตือนเร่งด่วน</span>
          <span class="right">Psych / Self-harm</span>
        </div>
        <h2><?= htmlspecialchars(PSYCH_TITLE) ?></h2>
        <p><?= htmlspecialchars(PSYCH_SUBTITLE) ?></p>
      </div>

      <!-- body -->
      <div class="flex-body">
        <!-- priority -->
        <div class="priority">
          <span class="icon">⚠</span>
          <span>กลุ่มเสี่ยงสูง · T71 / X60–X69 / X70 / X84 · ต้องติดตามภายใน 24–48 ชม.</span>
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
          <div class="dx-category">กลุ่ม: <?= htmlspecialchars($categoryLabel) ?></div>
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
          <div class="action-cta">โปรดดำเนินการภายใน 24–48 ชั่วโมง</div>
          <ul class="action-list">
            <li>ประสานทีมจิตเวช/สุขภาพจิตชุมชน เพื่อเยี่ยมบ้านประเมินภาวะเสี่ยง</li>
            <li>ประเมินความเสี่ยงฆ่าตัวตายซ้ำด้วย 8Q / SU (Suicide Screening)</li>
            <li>ตรวจสอบการเข้าถึงวิธีทำร้ายตนเอง (สารเคมี/ยา/เชือก) และลดอันตรายในบ้าน</li>
            <li>ให้ข้อมูลสายด่วนสุขภาพจิต 1323 และช่องทางติดต่อทีมดูแลต่อเนื่อง</li>
            <li>บันทึกผลการเยี่ยม/ส่งต่อในระบบ HDC / JHCIS</li>
          </ul>
        </div>
      </div>

      <!-- footer -->
      <div class="flex-footer">
        <span><?= htmlspecialchars(PSYCH_SYSTEM_NAME) ?></span>
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
        <tr><td class="text-muted">กลุ่ม</td><td><?= htmlspecialchars($categoryLabel) ?></td></tr>
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
        <li>ตรวจสอบว่าข้อมูลครบถ้วน ถูกต้อง โดยเฉพาะเบอร์โทรและที่อยู่</li>
        <li>กลับไปหน้าคิว แล้วกด <strong>"ส่งซ้ำทันที"</strong> เพื่อส่งเข้า MOPH Notify</li>
        <li>ตรวจดูสถานะในคิวว่าเปลี่ยนเป็น <em>ส่งแล้ว</em> และมี Message ID</li>
        <li>ประสานทีมจิตเวช/สุขภาพจิตให้ดำเนินการภายใน 24–48 ชม.</li>
      </ol>
    </div>

    <details class="meta-card">
      <summary class="fw-bold" style="cursor:pointer">ดู JSON payload ที่จะส่ง MOPH</summary>
      <pre class="json-panel mt-2 mb-0"><?= htmlspecialchars($jsonPretty) ?></pre>
    </details>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
