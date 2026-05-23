<?php
/**
 * pharm_flex_preview.php
 * หน้าแสดงตัวอย่าง Flex message สำหรับเภสัชกร (Pharm Lab Alert)
 *   - /pharm_flex_preview.php             → ใช้ข้อมูลตัวอย่าง (INR วิกฤต)
 *   - /pharm_flex_preview.php?id=123      → ดึงข้อมูลจริงของแถว id=123 ใน pharm_lab_queue
 *   - /pharm_flex_preview.php?sample=INR|Depakin|Lithium|Phenytoin → เลือก preset
 *
 * ใช้ HTML/CSS mock ของ Flex bubble บนมือถือ เพื่อให้เภสัชตรวจรูปแบบก่อนส่งจริง
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flex_pharm.php';

date_default_timezone_set('Asia/Bangkok');

/* ---------- โหลดข้อมูล ---------- */
$row = null;
$id  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
  try {
    $stmt = $dbcon->prepare("SELECT * FROM pharm_lab_queue WHERE id=:id");
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch();
    if ($row) $row = row_to_utf8($row);
  } catch (Throwable $e) {
    $row = null;
  }
}

/* presets */
$sample = strtoupper(trim((string)($_GET['sample'] ?? '')));
$samples = [
  'INR' => [
    'id'=>'ตัวอย่าง-INR', 'hn'=>'22345678', 'fullname'=>'นาย ประเสริฐ รักษา', 'age'=>72,
    'lab_name'=>'INR', 'result'=>'5.8',
    'lab_date'=>date('Y-m-d'), 'lab_time'=>'10:32:00',
    'doctor'=>'นพ.ธนากร วิริยะ', 'patient_type'=>'OPD',
    'lab_order_number'=>'LO240422-0001',
  ],
  'DEPAKIN' => [
    'id'=>'ตัวอย่าง-Depakin', 'hn'=>'33445566', 'fullname'=>'นาง วาสนา ใจเย็น', 'age'=>54,
    'lab_name'=>'Depakin level', 'result'=>'172.5',
    'lab_date'=>date('Y-m-d'), 'lab_time'=>'09:10:00',
    'doctor'=>'พญ.อรอนงค์ เจริญ', 'patient_type'=>'IPD',
    'lab_order_number'=>'LO240422-0002',
  ],
  'LITHIUM' => [
    'id'=>'ตัวอย่าง-Lithium', 'hn'=>'44556677', 'fullname'=>'นาย สมหมาย บุญมี', 'age'=>41,
    'lab_name'=>'Lithium level', 'result'=>'1.35',
    'lab_date'=>date('Y-m-d'), 'lab_time'=>'08:45:00',
    'doctor'=>'นพ.อนุชา ไชยมงคล', 'patient_type'=>'OPD',
    'lab_order_number'=>'LO240422-0003',
  ],
  'PHENYTOIN' => [
    'id'=>'ตัวอย่าง-Phenytoin', 'hn'=>'55667788', 'fullname'=>'นาง มณี ปลั่งศรี', 'age'=>66,
    'lab_name'=>'Phenytoin level', 'result'=>'24.2',
    'lab_date'=>date('Y-m-d'), 'lab_time'=>'11:05:00',
    'doctor'=>'นพ.ปิยะ อยู่ดี', 'patient_type'=>'OPD',
    'lab_order_number'=>'LO240422-0004',
  ],
];
if (!$row) {
  $row = $samples[$sample] ?? $samples['INR'];
}

/* ---------- คำนวณ risk + สร้าง payload ---------- */
$risk = pharmRisk((string)($row['lab_name'] ?? ''), $row['result'] ?? null);
$payload = buildPharmPayload($row);
$jsonPretty = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

/* ---------- ค่าจัดรูปแบบเพื่อ render HTML mock ---------- */
$hn          = $row['hn']           ?? '-';
$fullname    = $row['fullname']     ?? '-';
$age         = $row['age']          ?? '';
$labName     = $row['lab_name']     ?? '-';
$result      = $row['result']       ?? '-';
$labDate     = fr_thai_date($row['lab_date'] ?? null);
$labTime     = $row['lab_time']     ?? '-';
if (is_string($labTime) && strlen($labTime) >= 5) $labTime = substr($labTime, 0, 5);
$doctor      = $row['doctor']       ?? '-';
$patientType = $row['patient_type'] ?? '-';
$labOrder    = $row['lab_order_number'] ?? '';
$refId       = $row['id'] ?? '';
$ageTxt      = ($age !== '' && $age !== null) ? ((string)$age." ปี") : '-';

$reportedOk =
  (int)($row['reported_by_id'] ?? 0) > 0 &&
  !empty($row['reported_by_name']) &&
  !empty($row['reported_date']) &&
  !empty($row['reported_time']);

$PAGE_TITLE = 'ตัวอย่าง Flex message — Pharm Lab Alert';
$PAGE_KEY   = 'pharm';
$EXTRA_HEAD = '<style>
  /* ---------- Preview stage ---------- */
  .flex-preview-wrap { display:grid; grid-template-columns: minmax(340px, 420px) 1fr; gap: 1.25rem; align-items:start }
  @media (max-width: 1100px){ .flex-preview-wrap { grid-template-columns: 1fr } }

  .stage { background:#E5E7EB; padding:1.5rem; border-radius:1rem; min-height:420px;
           display:flex; justify-content:center; align-items:flex-start; border:1px solid #D1D5DB }

  .flex-bubble {
    width:100%; max-width:420px; background:#F9FAFB; border-radius:14px;
    box-shadow: 0 12px 30px rgba(15,23,42,.15); overflow:hidden;
    font-family: "Kanit", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    font-size: 1.02rem;
  }
  .flex-header img { width:100%; display:block }
  .flex-header.placeholder {
    height:70px; background:linear-gradient(135deg,#1E1B4B,#312E81);
    display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:.9rem;
  }

  /* Title strip (indigo) */
  .flex-title { background:#1E1B4B; color:#fff; padding:16px }
  .flex-title .meta { display:flex; justify-content:space-between; font-size:.72rem }
  .flex-title .meta .left  { color:#FDE68A; font-weight:700 }
  .flex-title .meta .right { color:#C7D2FE }
  .flex-title h2 { margin:.35rem 0 .15rem; font-size:1.15rem; font-weight:700; line-height:1.35 }
  .flex-title p  { margin:0; font-size:.72rem; color:#C7D2FE }

  .flex-body { padding:14px; background:#F9FAFB }
  .priority { border-radius:8px; padding:10px;
              display:flex; gap:.5rem; align-items:flex-start; font-weight:700; font-size:.75rem }
  .priority .icon { font-size:1rem; line-height:1 }

  /* Section card */
  .sect { border:1px solid #E5E7EB; background:#fff; border-radius:12px; padding:14px; margin-top:12px }
  .sect h4 { margin:0 0 .5rem; font-size:.7rem; text-transform:none; letter-spacing:.2px; font-weight:700 }
  .sect.patient h4 { color:#1E1B4B }
  .sect.report-ok  { background:#ECFDF5; border-color:#A7F3D0 }
  .sect.report-ok h4 { color:#065F46 }
  .sect.report-none { background:#EFF6FF; border-color:#BFDBFE }
  .sect.report-none h4 { color:#1E3A8A }
  .sect.action  { background:#EFF6FF; border-color:#BFDBFE }
  .sect.action h4  { color:#1E3A8A }

  .kv { display:flex; align-items:baseline; gap:.75rem; padding:.35rem 0 }
  .kv + .kv { border-top:1px solid #F3F4F6 }
  .kv .k { color:#6B7280; font-size:.75rem; flex:3 }
  .kv .v { color:#111827; font-size:.85rem; flex:5; text-align:right; word-break:break-word }
  .kv .v.lg { font-size:1rem; font-weight:700 }
  .kv .v.bold { font-weight:700 }

  .result-big { font-size:1.75rem; font-weight:800; text-align:right }
  .lab-title-row { display:flex; justify-content:space-between; align-items:baseline; margin-top:.25rem }
  .lab-title-row .k { font-size:.7rem; font-weight:700 }
  .lab-title-row .v { font-size:1rem; font-weight:700 }

  .action-list { margin:.25rem 0 0; padding-left:1rem; color:#1F2937; font-size:.75rem }
  .action-list li { margin-bottom:.25rem }
  .action-cta { color:#1E3A8A; font-weight:700; font-size:.85rem; margin:.35rem 0 .15rem }

  .report-btn {
    display:block; width:100%; background:#2563EB; color:#fff; border:0;
    padding:.55rem 1rem; border-radius:8px; text-align:center; font-weight:700; font-size:.85rem;
    text-decoration:none; margin-top:.5rem;
  }
  .report-btn:hover { background:#1D4ED8; color:#fff }

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
  .meta-card .badge-risk { font-size:.7rem; padding:.2rem .55rem; border-radius:999px; font-weight:600; color:#fff }

  .sample-switcher .btn { border-radius:999px }
</style>';

require_once __DIR__ . '/partials/header.php';

/* สีสำหรับ mock priority banner ตามระดับ */
$chipBg     = htmlspecialchars($risk['bg']);
$chipBd     = htmlspecialchars($risk['bd']);
$chipAccent = htmlspecialchars($risk['accent']);
$chipColor  = htmlspecialchars($risk['color']);
$chipIcon   = $risk['level']==='high' ? '⚠' : ($risk['level']==='mid' ? 'ℹ' : '✔');
?>

<div class="page-header">
  <h1><span class="msi text-primary me-2">chat</span>ตัวอย่าง Flex message — Pharm Lab Alert</h1>
  <div class="d-flex gap-2">
    <a href="pharm_lab_queue_ui.php" class="btn btn-outline-secondary">
      <span class="msi me-1">arrow_back</span> กลับคิว Lab
    </a>
  </div>
</div>

<?php if ($id > 0 && (!isset($row['id']) || !ctype_digit((string)$row['id']))): ?>
  <div class="alert alert-warning">ไม่พบ id <?= (int)$id ?> ในคิว — แสดงเป็น <strong>ข้อมูลตัวอย่าง</strong>แทน</div>
<?php elseif ($id === 0): ?>
  <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
      <span class="msi me-1">info</span>
      แสดง <strong>ข้อมูลตัวอย่าง</strong> — เพิ่ม <code>?id=XXX</code> ต่อท้าย URL เพื่อดูของแถวจริง
      (หรือเปิดจากปุ่ม <em>ดูตัวอย่าง</em> ในหน้าคิว)
    </div>
    <div class="sample-switcher d-flex gap-1">
      <?php foreach (['INR','Depakin','Lithium','Phenytoin'] as $sKey):
        $active = strcasecmp($sKey, $sample)===0 || ($sample==='' && $sKey==='INR');
      ?>
        <a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-primary' ?>"
           href="?sample=<?= urlencode($sKey) ?>"><?= $sKey ?></a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="flex-preview-wrap">

  <!-- ===== Phone preview (HTML mock ของ Flex bubble) ===== -->
  <div class="stage">
    <div class="flex-bubble">
      <!-- header banner -->
      <div class="flex-header <?= defined('PHARM_HEADER_URL') && PHARM_HEADER_URL ? '' : 'placeholder' ?>">
        <?php if (defined('PHARM_HEADER_URL') && PHARM_HEADER_URL): ?>
          <img src="<?= htmlspecialchars(PHARM_HEADER_URL) ?>" alt="MOPH banner"
               onerror="this.parentElement.classList.add('placeholder'); this.remove(); this.parentElement.innerHTML='กระทรวงสาธารณสุข';">
        <?php else: ?>
          กระทรวงสาธารณสุข
        <?php endif; ?>
      </div>

      <!-- title strip -->
      <div class="flex-title">
        <div class="meta">
          <span class="left">💊 แจ้งเตือน Lab ห้องยา</span>
          <span class="right">Pharm Lab Alert</span>
        </div>
        <h2><?= htmlspecialchars(PHARM_TITLE) ?></h2>
        <p><?= htmlspecialchars(PHARM_SUBTITLE) ?></p>
      </div>

      <!-- body -->
      <div class="flex-body">
        <!-- risk banner -->
        <div class="priority"
             style="background:<?=$chipBg?>; border:1px solid <?=$chipBd?>; color:<?=$chipAccent?>">
          <span class="icon"><?= $chipIcon ?></span>
          <span><?= htmlspecialchars($risk['chip']) ?></span>
        </div>

        <!-- ข้อมูลผู้ป่วย -->
        <div class="sect patient">
          <h4>🧑‍⚕️  ข้อมูลผู้ป่วย</h4>
          <div class="kv"><span class="k">HN</span><span class="v lg"><?= htmlspecialchars($hn) ?></span></div>
          <div class="kv"><span class="k">ชื่อ-สกุล</span><span class="v bold"><?= htmlspecialchars($fullname) ?></span></div>
          <div class="kv"><span class="k">อายุ</span><span class="v"><?= htmlspecialchars($ageTxt) ?></span></div>
          <div class="kv"><span class="k">ประเภทผู้ป่วย</span><span class="v"><?= htmlspecialchars($patientType) ?></span></div>
        </div>

        <!-- ผลตรวจ Lab -->
        <div class="sect" style="background:<?=$chipBg?>; border-color:<?=$chipBd?>">
          <h4 style="color:<?=$chipAccent?>">🧪  ผลตรวจ Lab</h4>
          <div class="lab-title-row">
            <span class="k" style="color:<?=$chipAccent?>">Lab</span>
            <span class="v" style="color:<?=$chipAccent?>"><?= htmlspecialchars($labName) ?></span>
          </div>
          <div class="kv" style="border-top:1px solid <?=$chipBd?>">
            <span class="k" style="color:<?=$chipAccent?>; font-weight:700">ผลตรวจ</span>
            <span class="result-big" style="color:<?=$chipColor?>"><?= htmlspecialchars($result) ?></span>
          </div>
          <div class="kv"><span class="k">วันที่ออกผล</span><span class="v"><?= htmlspecialchars($labDate) ?></span></div>
          <div class="kv"><span class="k">เวลาออกผล</span><span class="v"><?= htmlspecialchars($labTime) ?></span></div>
          <div class="kv"><span class="k">แพทย์ผู้สั่ง</span><span class="v"><?= htmlspecialchars($doctor) ?></span></div>
          <?php if ($labOrder !== ''): ?>
            <div class="kv"><span class="k">Lab Order #</span><span class="v"><?= htmlspecialchars($labOrder) ?></span></div>
          <?php endif; ?>
        </div>

        <!-- รายงาน / CTA -->
        <?php if ($reportedOk): ?>
          <?php
            $rTime = (string)$row['reported_time'];
            if (strlen($rTime) >= 5) $rTime = substr($rTime, 0, 5);
          ?>
          <div class="sect report-ok">
            <h4>✅  บันทึกการรายงานโดยเภสัชกร</h4>
            <div class="kv"><span class="k">ผู้รายงาน</span><span class="v bold"><?= htmlspecialchars($row['reported_by_name']) ?></span></div>
            <div class="kv"><span class="k">วันที่รายงาน</span><span class="v"><?= htmlspecialchars(th_date($row['reported_date'])) ?></span></div>
            <div class="kv"><span class="k">เวลารายงาน</span><span class="v"><?= htmlspecialchars($rTime) ?></span></div>
          </div>
        <?php else: ?>
          <div class="sect report-none">
            <h4>📝  ยังไม่ได้บันทึกรายงาน</h4>
            <p class="mb-2" style="font-size:.75rem; color:#1F2937">
              กรุณากดปุ่มด้านล่างเพื่อเปิดแบบฟอร์มรายงาน และบันทึกการทบทวน/ปรับยา
            </p>
            <a class="report-btn" href="#" onclick="return false;"
               title="เมื่อส่งจริงจะ link ไปยังหน้ารายงานพร้อมลายเซ็น HMAC">
              รายงานการแจ้งเตือน
            </a>
          </div>
        <?php endif; ?>

        <!-- คำแนะนำ -->
        <div class="sect action">
          <h4>📋  คำแนะนำสำหรับเภสัชกร</h4>
          <div class="action-cta">ข้อแนะนำในการดำเนินการ</div>
          <ul class="action-list">
            <li>ทบทวนขนาดยา / อันตรกิริยายาที่อาจเป็นสาเหตุ</li>
            <li>เฝ้าระวังอาการไม่พึงประสงค์ และบันทึกใน HOSxP</li>
            <li>ประสานแพทย์เจ้าของไข้เพื่อพิจารณาปรับแผนการรักษา</li>
            <li>บันทึกผลการรายงานผ่านปุ่ม "รายงานการแจ้งเตือน"</li>
          </ul>
        </div>
      </div>

      <!-- footer -->
      <div class="flex-footer">
        <span><?= htmlspecialchars(PHARM_SYSTEM_NAME) ?></span>
        <span><?= date('j M Y H:i') ?></span>
        <?php if ($refId !== ''): ?>
          <span class="ref">Ref: #<?= htmlspecialchars((string)$refId) ?><?= $labOrder ? "  •  Lab Order: ".htmlspecialchars($labOrder) : '' ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ===== Side panel: meta + raw JSON ===== -->
  <div>
    <div class="meta-card">
      <h5>รายละเอียดที่จะส่ง
        <span class="badge-risk" style="background:<?=$chipColor?>"><?= htmlspecialchars($risk['chip']) ?></span>
      </h5>
      <table class="table table-sm mb-0">
        <tr><td class="text-muted" style="width:36%">HN</td><td><strong><?= htmlspecialchars($hn) ?></strong></td></tr>
        <tr><td class="text-muted">ชื่อ-สกุล</td><td><?= htmlspecialchars($fullname) ?></td></tr>
        <tr><td class="text-muted">อายุ</td><td><?= htmlspecialchars($ageTxt) ?></td></tr>
        <tr><td class="text-muted">Lab</td><td><?= htmlspecialchars($labName) ?></td></tr>
        <tr><td class="text-muted">ผลตรวจ</td><td><strong style="color:<?=$chipColor?>"><?= htmlspecialchars($result) ?></strong></td></tr>
        <tr><td class="text-muted">ระดับ</td><td><?= htmlspecialchars($risk['chip']) ?></td></tr>
        <tr><td class="text-muted">วัน/เวลาออกผล</td><td><?= htmlspecialchars($labDate) ?> <?= htmlspecialchars($labTime) ?></td></tr>
        <tr><td class="text-muted">แพทย์</td><td><?= htmlspecialchars($doctor) ?></td></tr>
        <tr><td class="text-muted">ประเภท</td><td><?= htmlspecialchars($patientType) ?></td></tr>
        <tr><td class="text-muted">รายงานแล้ว?</td>
            <td><?= $reportedOk ? '<span class="text-success">รายงานแล้ว</span>' : '<span class="text-warning">ยังไม่รายงาน</span>' ?></td></tr>
        <tr><td class="text-muted">ขนาด JSON</td>
            <td><?= number_format(strlen($jsonPretty)) ?> ตัวอักษร
                <span class="text-muted">(จำกัด 50,000 ของ LINE Flex)</span></td></tr>
      </table>
    </div>

    <div class="meta-card">
      <h5>ขั้นตอนถัดไป</h5>
      <ol class="mb-0 small">
        <li>ตรวจสอบข้อมูลผู้ป่วย / ผลตรวจ / แพทย์ผู้สั่งให้ถูกต้อง</li>
        <li>กลับไปหน้าคิว กด <strong>"ส่งซ้ำทันที"</strong> เพื่อส่งเข้า MOPH Notify</li>
        <li>เภสัชกรกดปุ่ม <strong>"รายงานการแจ้งเตือน"</strong> ใน LINE เพื่อบันทึกการทบทวน</li>
        <li>ตรวจสถานะคิวว่าเปลี่ยนเป็น <em>ส่งแล้ว</em> และมี Message ID</li>
      </ol>
    </div>

    <details class="meta-card">
      <summary class="fw-bold" style="cursor:pointer">ดู JSON payload ที่จะส่ง MOPH</summary>
      <pre class="json-panel mt-2 mb-0"><?= htmlspecialchars($jsonPretty) ?></pre>
    </details>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
