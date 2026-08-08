<?php
/**
 * flex_editor.php — หน้าแก้ Flex Theme (config-driven)
 *   แก้ต่อ module: title/subtitle/urgency/สี(gradient+accent)/มุม gradient/bg_icon_url
 *   แก้ global: footer + label ในการ์ด
 *   Live preview (สด) + validate hex (กัน rgba) + Save เขียน secrets/flex_themes.json
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/flex_theme.php';
date_default_timezone_set('Asia/Bangkok');

$MODS = ['dengue','accident','fracture','scrub','lepto','covid','patient','pharm_lab','sexual','drug'];
$LABEL_KEYS = ['sec_patient','sec_diagnosis','sec_contact','icd','disease','lab','vstdate','doctor','address','phone'];

$msg = ''; $err = '';

/* ── SAVE ────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
  $file = FLEX_THEMES_FILE;
  $raw  = is_readable($file) ? json_decode(@file_get_contents($file), true) : [];
  if (!is_array($raw)) $raw = [];
  $m = $_POST['module'] ?? '';
  $hexok = fn($c) => (bool)preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$c);

  $errs = [];
  if (in_array($m, $MODS, true)) {
    foreach (['color_start','color_end','accent'] as $ck) {
      if (!$hexok($_POST[$ck] ?? '')) $errs[] = "สี {$ck} ต้องเป็น hex #RRGGBB (ห้าม rgba/ชื่อสี)";
    }
    if (($_POST['bg_icon_url'] ?? '') !== '' && !preg_match('~^https://~i', $_POST['bg_icon_url'])) {
      $errs[] = "bg_icon_url ต้องเป็น https:// (LINE ต้องการรูปสาธารณะ)";
    }
  } else {
    $errs[] = "module ไม่ถูกต้อง";
  }

  if (!$errs) {
    $raw[$m]['title']          = trim($_POST['title'] ?? '');
    $raw[$m]['subtitle']       = trim($_POST['subtitle'] ?? '');
    $raw[$m]['urgency']        = trim($_POST['urgency'] ?? '');
    $raw[$m]['color_start']    = $_POST['color_start'];
    $raw[$m]['color_end']      = $_POST['color_end'];
    $raw[$m]['accent']         = $_POST['accent'];
    $raw[$m]['gradient_angle'] = max(0, min(360, (int)($_POST['gradient_angle'] ?? 135)));
    $raw[$m]['bg_icon_url']    = trim($_POST['bg_icon_url'] ?? '');
    // global
    $raw['_global']['footer_text']   = trim($_POST['g_footer'] ?? '');
    $raw['_global']['hospital_name'] = trim($_POST['g_hospital'] ?? '');
    foreach ($LABEL_KEYS as $lk) {
      if (isset($_POST["lb_$lk"])) $raw['_global']['labels'][$lk] = trim($_POST["lb_$lk"]);
    }
    $raw['_meta']['updated_at'] = date('Y-m-d H:i:s');
    if (@file_put_contents($file, json_encode($raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) !== false) {
      header('Location: flex_editor.php?m=' . urlencode($m) . '&saved=1'); exit;
    }
    $err = 'เขียนไฟล์ไม่สำเร็จ — ตรวจสิทธิ์ ' . htmlspecialchars($file);
  } else {
    $err = implode(' · ', $errs);
  }
}

/* ── LOAD ────────────────────────────────────────────────────────── */
$all = flex_theme_all(true);
$cur = $_GET['m'] ?? 'dengue';
if (!in_array($cur, $MODS, true)) $cur = 'dengue';
$t = $all[$cur] + ['title'=>'','subtitle'=>'','urgency'=>'','color_start'=>'#334155','color_end'=>'#0F172A','accent'=>'#0F172A','gradient_angle'=>135,'bg_icon_url'=>''];
$g = $all['_global'];
$L = $g['labels'];
if (isset($_GET['saved'])) $msg = 'บันทึกแล้ว ✓ (มีผลกับ Flex ที่ยิงครั้งถัดไปทันที)';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="th"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flex Theme Editor</title>
<style>
  *{box-sizing:border-box} body{margin:0;font-family:"Segoe UI","Sarabun",system-ui,sans-serif;background:#F1F5F9;color:#0F172A}
  .top{background:#0F172A;color:#fff;padding:12px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
  .top h1{font-size:16px;margin:0;font-weight:700} .top .sp{flex:1}
  .top select{padding:6px 10px;border-radius:8px;border:0;font-size:14px}
  .wrap{display:flex;gap:22px;padding:22px;align-items:flex-start;flex-wrap:wrap}
  .form{flex:1 1 420px;max-width:560px;background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
  .prev{flex:0 0 340px;position:sticky;top:22px}
  fieldset{border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin:0 0 16px}
  legend{font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.04em;padding:0 6px}
  label{display:block;font-size:12px;color:#475569;font-weight:600;margin:10px 0 4px}
  input[type=text]{width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px} .grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
  .colorfield{display:flex;align-items:center;gap:8px} input[type=color]{width:42px;height:34px;border:1px solid #CBD5E1;border-radius:8px;padding:2px;background:#fff}
  .hex{font-family:monospace;font-size:12px;color:#64748B}
  input[type=range]{width:100%}
  .save{background:#2563EB;color:#fff;border:0;border-radius:10px;padding:11px 20px;font-size:15px;font-weight:700;cursor:pointer;width:100%}
  .banner{padding:10px 14px;border-radius:10px;margin:0 0 16px;font-size:14px}
  .ok{background:#DCFCE7;color:#166534} .bad{background:#FEE2E2;color:#991B1B}
  details{margin-top:4px} summary{cursor:pointer;font-size:13px;color:#2563EB;font-weight:600;margin-bottom:8px}
  .hint{font-size:11px;color:#94A3B8;margin-top:3px}
  /* preview card */
  .card{background:#fff;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.14);padding:12px;font-size:13px}
  .hd{border-radius:14px;padding:13px 15px;color:#fff;position:relative;overflow:hidden}
  .hd h2{margin:0;font-size:16px;font-weight:800;position:relative} .hd .en{font-size:11px;opacity:.85;margin-top:2px;position:relative}
  .bd{padding:12px 6px 2px} .lb{font-size:10px;font-weight:700;text-transform:uppercase;color:#94A3B8;margin:14px 0 5px}
  .lb:first-child{margin-top:2px}
  .hn{font-size:14px;font-weight:800} .nm{font-size:15px;font-weight:700} .mt{color:#64748B;font-size:12px;margin-top:2px}
  .kv{display:flex;justify-content:space-between;gap:10px;padding:3px 0} .kv .k{color:#64748B} .kv .v{font-weight:700;text-align:right}
  .icd{display:flex;justify-content:space-between;align-items:center;margin:4px 0} .icd .l{font-weight:700} .icd .r{font-size:20px;font-weight:800}
  .pill{display:inline-block;padding:2px 10px;border-radius:6px;color:#fff;font-weight:800;font-size:12px}
  .ph{font-size:15px;font-weight:800;margin-top:6px}.ph .i{color:#94A3B8;font-weight:400}
  .ft{border-top:1px solid #F1F5F9;margin-top:12px;padding-top:9px;color:#94A3B8;font-size:10px;display:flex;justify-content:space-between}
</style></head><body>

<form method="post" id="f">
<input type="hidden" name="action" value="save">
<input type="hidden" name="module" value="<?=$e($cur)?>">

<div class="top">
  <h1>🎨 Flex Theme Editor</h1>
  <label style="color:#cbd5e1;margin:0;font-weight:600">Module:</label>
  <select onchange="location.href='flex_editor.php?m='+this.value">
    <?php foreach ($MODS as $mm): ?>
      <option value="<?=$e($mm)?>" <?=$mm===$cur?'selected':''?>><?=$e($all[$mm]['title'] ?? $mm)?> (<?=$e($mm)?>)</option>
    <?php endforeach; ?>
  </select>
  <span class="sp"></span>
  <button class="save" type="submit" style="width:auto">💾 บันทึก</button>
</div>

<div class="wrap">
  <div class="form">
    <?php if ($msg): ?><div class="banner ok"><?=$e($msg)?></div><?php endif; ?>
    <?php if ($err): ?><div class="banner bad"><?=$e($err)?></div><?php endif; ?>

    <fieldset><legend>หัวการ์ด — <?=$e($cur)?></legend>
      <label>ชื่อ (title)</label><input type="text" name="title" id="i_title" value="<?=$e($t['title'])?>">
      <label>บรรทัดรอง (subtitle)</label><input type="text" name="subtitle" id="i_sub" value="<?=$e($t['subtitle'])?>">
      <label>ป้ายความด่วน (urgency)</label><input type="text" name="urgency" id="i_urg" value="<?=$e($t['urgency'])?>">
    </fieldset>

    <fieldset><legend>สี (gradient + accent)</legend>
      <div class="grid3">
        <div><label>สีเริ่ม (อ่อน)</label><div class="colorfield"><input type="color" name="color_start" id="i_cs" value="<?=$e($t['color_start'])?>"><span class="hex" id="h_cs"><?=$e($t['color_start'])?></span></div></div>
        <div><label>สีจบ (เข้ม)</label><div class="colorfield"><input type="color" name="color_end" id="i_ce" value="<?=$e($t['color_end'])?>"><span class="hex" id="h_ce"><?=$e($t['color_end'])?></span></div></div>
        <div><label>Accent (ICD/LAB)</label><div class="colorfield"><input type="color" name="accent" id="i_ac" value="<?=$e($t['accent'])?>"><span class="hex" id="h_ac"><?=$e($t['accent'])?></span></div></div>
      </div>
      <label>มุม gradient: <b id="v_ang"><?=$e($t['gradient_angle'])?></b>°</label>
      <input type="range" name="gradient_angle" id="i_ang" min="0" max="360" step="15" value="<?=$e($t['gradient_angle'])?>">
      <div class="hint">0° = ล่างขึ้นบน · 90° = ซ้ายไปขวา · 135° = เฉียง</div>
    </fieldset>

    <fieldset><legend>Watermark (ถ้ามี)</legend>
      <label>bg_icon_url (PNG สาธารณะ https)</label><input type="text" name="bg_icon_url" id="i_bg" value="<?=$e($t['bg_icon_url'])?>" placeholder="เว้นว่าง = ไม่มี watermark">
      <div class="hint">LINE ใช้ PNG/JPEG เท่านั้น (ไม่รับ SVG) · ต้อง host สาธารณะ</div>
    </fieldset>

    <details>
      <summary>⚙️ ตั้งค่า Global (footer + label — ใช้ทุก module)</summary>
      <fieldset><legend>Footer</legend>
        <label>ข้อความ footer</label><input type="text" name="g_footer" id="i_ft" value="<?=$e($g['footer_text'])?>">
        <label>ชื่อ รพ. (เว้นว่างถ้าใช้หลาย รพ.)</label><input type="text" name="g_hospital" id="i_hosp" value="<?=$e($g['hospital_name'])?>">
      </fieldset>
      <fieldset><legend>Label ในการ์ด</legend>
        <div class="grid2">
          <?php foreach ($LABEL_KEYS as $lk): ?>
            <div><label><?=$e($lk)?></label><input type="text" name="lb_<?=$e($lk)?>" value="<?=$e($L[$lk] ?? '')?>"></div>
          <?php endforeach; ?>
        </div>
      </fieldset>
    </details>

    <button class="save" type="submit">💾 บันทึก theme</button>
  </div>

  <div class="prev">
    <div class="card">
      <div class="hd" id="p_hd">
        <h2 id="p_title"><?=$e($t['title'])?></h2>
        <div class="en" id="p_sub"></div>
      </div>
      <div class="bd">
        <div class="lb"><?=$e($L['sec_patient'])?></div>
        <div class="hn">HN 0012345</div><div class="nm">นายตัวอย่าง ทดสอบ</div><div class="mt">45 ปี · ชาย</div>
        <div class="lb"><?=$e($L['sec_diagnosis'])?></div>
        <div class="icd"><span class="l" id="p_icdlab" style="color:<?=$e($t['accent'])?>"><?=$e($L['icd'])?></span><span class="r">A90</span></div>
        <div class="kv"><span class="k"><?=$e($L['disease'])?></span><span class="v">ไข้เลือดออก</span></div>
        <div class="kv"><span class="k"><?=$e($L['lab'])?></span><span class="v"><span class="pill" id="p_pill" style="background:<?=$e($t['accent'])?>">Positive</span></span></div>
        <div class="kv"><span class="k"><?=$e($L['vstdate'])?></span><span class="v">8 ส.ค. 2569</span></div>
        <div class="lb"><?=$e($L['sec_contact'])?></div>
        <div class="kv"><span class="k"><?=$e($L['address'])?></span><span class="v">99 ม.5 ต.เชียงกลาง</span></div>
        <div class="ph"><span class="i">☎</span> 08X-XXX-XXXX</div>
        <div class="ft"><span id="p_ft"><?=$e($g['footer_text'])?></span><span>8 ส.ค. 2569</span></div>
      </div>
    </div>
    <div class="hint" style="text-align:center;margin-top:8px">พรีวิว (ข้อมูลตัวอย่าง) · gradient/สี/ข้อความ อัปเดตสด</div>
  </div>
</div>
</form>

<script>
(function(){
  var $=function(id){return document.getElementById(id)};
  function upd(){
    var cs=$('i_cs').value, ce=$('i_ce').value, ac=$('i_ac').value, ang=$('i_ang').value;
    $('h_cs').textContent=cs; $('h_ce').textContent=ce; $('h_ac').textContent=ac; $('v_ang').textContent=ang;
    $('p_hd').style.background='linear-gradient('+ang+'deg,'+cs+','+ce+')';
    $('p_title').textContent=$('i_title').value||'—';
    var sub=$('i_sub').value, urg=$('i_urg').value;
    $('p_sub').textContent=(sub&&urg)?(sub+'   ·   '+urg):(sub||urg||'');
    $('p_icdlab').style.color=ac; $('p_pill').style.background=ac;
    $('p_ft').textContent=$('i_ft').value + ($('i_hosp').value?('  ·  '+$('i_hosp').value):'');
  }
  ['i_title','i_sub','i_urg','i_cs','i_ce','i_ac','i_ang','i_ft','i_hosp'].forEach(function(id){
    var el=$(id); if(el){ el.addEventListener('input',upd); }
  });
  upd();
})();
</script>
</body></html>
