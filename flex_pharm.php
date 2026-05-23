<?php
/**
 * flex_pharm.php
 * ไลบรารีกลาง: ประกอบ Flex message สำหรับแจ้งเตือน Lab วิกฤต / ต้องเฝ้าระวังของห้องยา
 * (Pharm Lab Alert) — ใช้ร่วมกันโดย
 *   - pharm_lab.php              (ingest + send orchestrator)
 *   - pharm_lab_queue_action.php (bulk action)
 *   - pharm_lab_queue_ui.php     (preview link)
 *   - pharm_flex_preview.php     (mock preview)
 *
 * ออกแบบให้:
 *   - ดูเป็นทางการ เหมือน fracture / patient alert
 *   - จัดกลุ่มข้อมูลเป็น section (ข้อมูลผู้ป่วย / ผลตรวจ Lab / เภสัช / คำแนะนำ)
 *   - รองรับ "risk chip" (สีเขียว/ส้ม/แดง) สำหรับ INR, Depakin, Lithium, Phenytoin
 *   - CTA ปุ่ม "รายงานการแจ้งเตือน" (signed URL) เมื่อยังไม่ได้บันทึกผู้รายงาน
 */

/* -------------------- Default constants -------------------- */
if (!defined('PHARM_TITLE'))       define('PHARM_TITLE',       'แจ้งเตือน Lab วิกฤต / เฝ้าระวังห้องยา');
if (!defined('PHARM_SUBTITLE'))    define('PHARM_SUBTITLE',    'Pharm Lab Alert สำหรับเภสัชกรและทีมสหวิชาชีพ');
if (!defined('PHARM_HEADER_URL'))  define('PHARM_HEADER_URL',  'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
if (!defined('PHARM_ICON_URL'))    define('PHARM_ICON_URL',    'https://www.ckhospital.net/home/PDF/Logo_ck.png');
if (!defined('PHARM_SYSTEM_NAME')) define('PHARM_SYSTEM_NAME', 'ระบบแจ้งเตือน Lab ห้องยา • รพ.เชียงกลาง');

// ----- Report Button Config (ควรตั้งค่าจริงใน config.php ของโปรดักชัน) -----
if (!defined('PHARM_REPORT_URL_BASE')) define('PHARM_REPORT_URL_BASE', 'http://192.168.1.25:8080/HOSxLine/pharm_lab_report.php');
if (!defined('PHARM_REPORT_SIGN_KEY')) define('PHARM_REPORT_SIGN_KEY', 'put_a_random_secret_here');

/* -------------------- Encoding helpers (guarded) -------------------- */
if (!function_exists('to_utf8')) {
  function to_utf8($s) {
    if ($s === null || $s === '' || !is_string($s)) return $s;
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    foreach (['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc) {
      $t = @iconv($enc, 'UTF-8//IGNORE', $s);
      if ($t !== false && $t !== '' && mb_check_encoding($t, 'UTF-8')) return $t;
      $t = @mb_convert_encoding($s, 'UTF-8', $enc);
      if ($t !== false && $t !== '' && mb_check_encoding($t, 'UTF-8')) return $t;
    }
    $t = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    return $t !== false ? $t : $s;
  }
}
if (!function_exists('ensure_utf8')) {
  function ensure_utf8($s){ return to_utf8($s); }
}
if (!function_exists('row_to_utf8')) {
  function row_to_utf8(array $row): array {
    foreach ($row as $k => $v) if (is_string($v)) $row[$k] = to_utf8($v);
    return $row;
  }
}

/* -------------------- Thai date helper -------------------- */
if (!function_exists('fr_thai_date')) {
  function fr_thai_date(?string $ymd): string {
    if (!$ymd) return '-';
    $ts = strtotime($ymd);
    if ($ts === false) return $ymd;
    static $months = [
      1=>'ม.ค.', 2=>'ก.พ.', 3=>'มี.ค.', 4=>'เม.ย.', 5=>'พ.ค.', 6=>'มิ.ย.',
      7=>'ก.ค.', 8=>'ส.ค.', 9=>'ก.ย.', 10=>'ต.ค.', 11=>'พ.ย.', 12=>'ธ.ค.',
    ];
    $d = (int)date('j', $ts);
    $m = (int)date('n', $ts);
    $y = (int)date('Y', $ts) + 543;
    return sprintf('%d %s %d', $d, $months[$m] ?? '', $y);
  }
}

/* แปลง Y-m-d -> d/m/พ.ศ. (สั้น) — ใช้กับผู้รายงาน */
if (!function_exists('th_date')) {
  function th_date($ymd){
    if(!$ymd) return '-';
    $ts = strtotime($ymd);
    if($ts === false) return $ymd;
    $y = (int)date('Y',$ts) + 543;
    return date('d/m',$ts).'/'.$y;
  }
}

/* -------------------- Param / number helpers (guarded) -------------------- */
if (!function_exists('readParam')) {
  function readParam($k,$d=null){
    if(PHP_SAPI==='cli'){
      static $a; if($a===null) $a=getopt('',['start::','end::','dry-run','mode::']);
      if($k==='dry-run') return array_key_exists('dry-run',$a);
      return $a[$k]??$d;
    } else {
      if($k==='dry-run') return isset($_GET['dry-run']);
      return $_GET[$k]??$d;
    }
  }
}
if (!function_exists('normalize_date_ymd')) {
  function normalize_date_ymd($d,$fb){
    if(!is_string($d)||$d==='') return $fb;
    if(preg_match('/^\s*(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\s*$/',$d,$m)){
      $y=(int)$m[1]; $mo=(int)$m[2]; $da=(int)$m[3];
      if($y>2400) $y-=543;
      if($y<1900||$y>2100||$mo<1||$mo>12||$da<1||$da>31) return $fb;
      return sprintf('%04d-%02d-%02d',$y,$mo,$da);
    }
    return $fb;
  }
}
if (!function_exists('extract_moph_message_id')) {
  function extract_moph_message_id($json){
    if(!is_array($json)) return null;
    $paths = [
      ['messageId'], ['data','messageId'], ['result','messageId'],
      ['messages',0,'messageId'], ['messages',0,'id']
    ];
    foreach($paths as $p){
      $t=$json; foreach($p as $k){ if(is_array($t)&&array_key_exists($k,$t)) $t=$t[$k]; else { $t=null; break; } }
      if(is_scalar($t)&&$t!=='') return (string)$t;
    }
    return null;
  }
}

/* -------------------- Pharm-specific risk helpers -------------------- */
if (!function_exists('toNumberOrNull')) {
  function toNumberOrNull($s){
    if ($s===null) return null;
    if (is_numeric($s)) return (float)$s;
    if (preg_match('/-?\d+(?:\.\d+)?/u', (string)$s, $m)) return (float)$m[0];
    return null;
  }
}

if (!function_exists('pharmRisk')) {
  /**
   * คำนวณระดับความเสี่ยงของผล lab
   * return: ['level'=>ok|mid|high, 'chip'=>string, 'color'=>'#HEX', 'accent'=>'#HEX', 'bg'=>'#HEX', 'bd'=>'#HEX']
   */
  function pharmRisk(string $labName, $rawResult): array {
    $x = toNumberOrNull($rawResult);
    $lab = mb_strtolower($labName, 'UTF-8');

    $ok   = ['level'=>'ok',   'chip'=>'ปกติ',           'color'=>'#10B981',
             'accent'=>'#065F46', 'bg'=>'#ECFDF5', 'bd'=>'#A7F3D0'];
    $mid  = ['level'=>'mid',  'chip'=>'ตรวจสอบผล',     'color'=>'#F59E0B',
             'accent'=>'#92400E', 'bg'=>'#FFFBEB', 'bd'=>'#FDE68A'];
    $high = ['level'=>'high', 'chip'=>'วิกฤต / สูงมาก', 'color'=>'#DC2626',
             'accent'=>'#991B1B', 'bg'=>'#FEF2F2', 'bd'=>'#FECACA'];

    if (strpos($lab,'inr') !== false) {
      if ($x === null) return array_merge($mid,  ['chip'=>'INR (ตรวจสอบ)']);
      if ($x >= 5.0)   return array_merge($high, ['chip'=>'INR ≥ 5 • วิกฤต']);
      if ($x >= 3.5)   return array_merge($mid,  ['chip'=>'INR ≥ 3.5 • สูง']);
      return $ok;
    }
    if (strpos($lab,'depakin') !== false || strpos($lab,'valproate') !== false) {
      if ($x === null) return array_merge($mid,  ['chip'=>'Depakin (ตรวจสอบ)']);
      if ($x > 150)    return array_merge($high, ['chip'=>'Depakin > 150 • สูง']);
      return $ok;
    }
    if (strpos($lab,'lithium') !== false) {
      if ($x === null) return array_merge($mid,  ['chip'=>'Lithium (ตรวจสอบ)']);
      if ($x > 1.2)    return array_merge($high, ['chip'=>'Lithium > 1.2 • สูง']);
      return $ok;
    }
    if (strpos($lab,'phenytoin') !== false || strpos($lab,'dilantin') !== false) {
      if ($x === null) return array_merge($mid,  ['chip'=>'Phenytoin (ตรวจสอบ)']);
      if ($x > 20)     return array_merge($high, ['chip'=>'Phenytoin > 20 • สูง']);
      return $ok;
    }
    return $mid;
  }
}

if (!function_exists('riskChip')) {
  function riskChip(string $text, string $hex){
    return [
      "type"=>"box","layout"=>"vertical","cornerRadius"=>"12px",
      "backgroundColor"=>$hex,"paddingAll"=>"6px",
      "contents"=>[["type"=>"text","text"=>$text,"weight"=>"bold",
                    "size"=>"xs","align"=>"center","color"=>"#FFFFFF"]]
    ];
  }
}

if (!function_exists('iconRow')) {
  function iconRow(string $emoji, string $label, $value){
    $text = $emoji.' '.$label.' : '.(string)(($value===null||$value==='')?'-':$value);
    return [
      "type"=>"box","layout"=>"baseline","spacing"=>"sm",
      "contents"=>[[ "type"=>"text","text"=>$text,"wrap"=>true,"size"=>"sm","color"=>"#1F2937" ]]
    ];
  }
}

if (!function_exists('build_report_signed_url')) {
  /**
   * สร้าง URL พร้อมลายเซ็น HMAC สำหรับปุ่ม "รายงานการแจ้งเตือน"
   * ใช้ PHARM_REPORT_URL_BASE + PHARM_REPORT_SIGN_KEY
   */
  function build_report_signed_url(array $r): string {
    $id  = isset($r['id']) ? (string)$r['id'] : '';
    $hn  = (string)($r['hn'] ?? '');
    $lab = (string)($r['lab_order_number'] ?? '');
    $ts  = time();

    $payload = http_build_query(['id'=>$id,'hn'=>$hn,'lab'=>$lab,'ts'=>$ts], '', '&', PHP_QUERY_RFC3986);
    $sig = hash_hmac('sha256', $payload, PHARM_REPORT_SIGN_KEY);
    return PHARM_REPORT_URL_BASE . '?' . $payload . '&sig=' . $sig . '&openExternalBrowser=1';
  }
}

/* -------------------- Flex row + section helpers (guarded, shared with flex_fracture) -------------------- */
if (!function_exists('fr_info_row')) {
  function fr_info_row(string $label, ?string $value, array $opts = []): array {
    $v = ($value === null || $value === '') ? '-' : (string)$value;
    $valueColor  = $opts['value_color']  ?? '#111827';
    $valueWeight = $opts['value_weight'] ?? 'regular';
    $valueSize   = $opts['value_size']   ?? 'sm';
    return [
      "type" => "box", "layout" => "baseline", "spacing" => "sm", "margin" => "sm",
      "contents" => [
        [ "type"=>"text", "text"=>$label, "size"=>"sm", "color"=>"#6B7280", "flex"=>3, "weight"=>"regular" ],
        [ "type"=>"text", "text"=>$v,    "size"=>$valueSize, "color"=>$valueColor,
          "weight"=>$valueWeight, "flex"=>5, "wrap"=>true, "align"=>"end" ],
      ]
    ];
  }
}
if (!function_exists('fr_section')) {
  function fr_section(string $title, array $rows, array $opts = []): array {
    $bg     = $opts['bg']     ?? '#FFFFFF';
    $bd     = $opts['bd']     ?? '#E5E7EB';
    $icon   = $opts['icon']   ?? '';
    $accent = $opts['accent'] ?? '#2563EB';
    $titleBox = [
      "type"=>"box", "layout"=>"baseline", "spacing"=>"sm",
      "contents"=>[
        [ "type"=>"text", "text"=>($icon ? $icon.'  ' : '').$title,
          "size"=>"xs", "color"=>$accent, "weight"=>"bold", "flex"=>1 ],
      ]
    ];
    return [
      "type"=>"box", "layout"=>"vertical",
      "paddingAll"=>"14px", "cornerRadius"=>"12px",
      "backgroundColor"=>$bg, "borderColor"=>$bd, "borderWidth"=>"1px",
      "margin"=>"md", "spacing"=>"xs",
      "contents"=> array_merge([$titleBox], $rows),
    ];
  }
}

/* -------------------- MAIN: Build the Flex payload -------------------- */
if (!function_exists('buildPharmPayload')) {

/**
 * ประกอบ Flex payload จาก row เดียวของ pharm_lab_queue
 * รูปแบบเดียวกับ flex_fracture / flex_patient (title strip + sections + footer signature)
 * แต่เพิ่ม risk chip + ปุ่ม "รายงานการแจ้งเตือน" เฉพาะงานเภสัช
 */
function buildPharmPayload(array $r): array {
  $r = row_to_utf8($r);

  /* ---------- Normalize values ---------- */
  $hn          = $r['hn']           ?? '-';
  $fullname    = $r['fullname']     ?? '-';
  $age         = $r['age']          ?? '';
  $labName     = $r['lab_name']     ?? '-';
  $result      = $r['result']       ?? '-';
  $labDate     = fr_thai_date($r['lab_date'] ?? null);
  $labTime     = $r['lab_time']     ?? '-';
  if (is_string($labTime) && strlen($labTime) >= 5) $labTime = substr($labTime, 0, 5);
  $doctor      = $r['doctor']       ?? '-';
  $patientType = $r['patient_type'] ?? '-';
  $labOrder    = $r['lab_order_number'] ?? '';
  $refId       = $r['id'] ?? '';

  $risk = pharmRisk((string)$labName, $result);

  /* ---------- HEADER BANNER (image) ---------- */
  $header = [
    "type"=>"box", "layout"=>"vertical", "paddingAll"=>"0px",
    "contents"=> PHARM_HEADER_URL ? [[
      "type"=>"image", "url"=>PHARM_HEADER_URL,
      "size"=>"full", "aspectRatio"=>"3120:885", "aspectMode"=>"cover",
    ]] : [],
  ];

  /* ---------- TITLE STRIP (indigo gradient look via dark-navy) ---------- */
  $titleStrip = [
    "type"=>"box", "layout"=>"vertical",
    "paddingAll"=>"16px", "backgroundColor"=>"#1E1B4B", "cornerRadius"=>"0px",
    "contents"=>[
      [ "type"=>"box", "layout"=>"horizontal", "contents"=>[
          [ "type"=>"text", "text"=>"💊  แจ้งเตือน Lab ห้องยา",
            "size"=>"xs", "color"=>"#FDE68A", "weight"=>"bold", "flex"=>1 ],
          [ "type"=>"text", "text"=>"Pharm Lab Alert",
            "size"=>"xs", "color"=>"#C7D2FE", "align"=>"end", "flex"=>0 ],
      ]],
      [ "type"=>"text", "text"=>PHARM_TITLE,
        "size"=>"xl", "color"=>"#FFFFFF", "weight"=>"bold", "wrap"=>true, "margin"=>"sm" ],
      [ "type"=>"text", "text"=>PHARM_SUBTITLE,
        "size"=>"xs", "color"=>"#C7D2FE", "wrap"=>true, "margin"=>"xs" ],
    ],
  ];

  /* ---------- RISK BADGE (แดง/ส้ม/เขียว) ---------- */
  $priority = [
    "type"=>"box", "layout"=>"baseline", "spacing"=>"sm",
    "paddingAll"=>"10px", "backgroundColor"=>$risk['bg'], "cornerRadius"=>"8px", "margin"=>"md",
    "contents"=>[
      [ "type"=>"text", "text"=>($risk['level']==='high' ? "⚠" : ($risk['level']==='mid' ? "ℹ" : "✔")),
        "size"=>"md", "flex"=>0, "color"=>$risk['accent'] ],
      [ "type"=>"text", "text"=>$risk['chip'],
        "size"=>"xs", "color"=>$risk['accent'], "weight"=>"bold", "wrap"=>true, "flex"=>1 ],
    ],
  ];

  /* ---------- SECTION: ข้อมูลผู้ป่วย ---------- */
  $ageTxt = ($age !== '' && $age !== null) ? ((string)$age." ปี") : '-';
  $patientRows = [
    fr_info_row('HN', $hn, ['value_weight'=>'bold','value_color'=>'#111827','value_size'=>'md']),
    [ "type"=>"separator", "margin"=>"sm", "color"=>"#F3F4F6" ],
    fr_info_row('ชื่อ-สกุล', $fullname, ['value_weight'=>'bold']),
    [ "type"=>"separator", "margin"=>"sm", "color"=>"#F3F4F6" ],
    fr_info_row('อายุ', $ageTxt),
    [ "type"=>"separator", "margin"=>"sm", "color"=>"#F3F4F6" ],
    fr_info_row('ประเภทผู้ป่วย', $patientType),
  ];
  $sectionPatient = fr_section('ข้อมูลผู้ป่วย', $patientRows,
    ['icon'=>'🧑‍⚕️', 'accent'=>'#1E1B4B']);

  /* ---------- SECTION: ผลตรวจ Lab (ไฮไลต์ตามระดับความเสี่ยง) ---------- */
  $labRows = [
    [ "type"=>"box", "layout"=>"baseline", "spacing"=>"sm", "margin"=>"sm",
      "contents"=>[
        [ "type"=>"text", "text"=>"Lab",
          "size"=>"xs", "color"=>$risk['accent'], "weight"=>"bold", "flex"=>0 ],
        [ "type"=>"text", "text"=>(string)$labName,
          "size"=>"md", "color"=>$risk['accent'], "weight"=>"bold", "flex"=>1, "align"=>"end", "wrap"=>true ],
      ]
    ],
    [ "type"=>"separator", "margin"=>"sm", "color"=>$risk['bd'] ],
    [ "type"=>"box", "layout"=>"baseline", "spacing"=>"sm", "margin"=>"sm",
      "contents"=>[
        [ "type"=>"text", "text"=>"ผลตรวจ",
          "size"=>"xs", "color"=>$risk['accent'], "weight"=>"bold", "flex"=>0 ],
        [ "type"=>"text", "text"=>(string)$result,
          "size"=>"xxl", "color"=>$risk['color'], "weight"=>"bold", "flex"=>1, "align"=>"end", "wrap"=>true ],
      ]
    ],
    [ "type"=>"separator", "margin"=>"sm", "color"=>$risk['bd'] ],
    fr_info_row('วันที่ออกผล', $labDate),
    fr_info_row('เวลาออกผล', $labTime),
    fr_info_row('แพทย์ผู้สั่ง', $doctor),
    $labOrder
      ? fr_info_row('Lab Order #', $labOrder, ['value_color'=>'#374151'])
      : null,
  ];
  $labRows = array_values(array_filter($labRows));
  $sectionLab = fr_section('ผลตรวจ Lab', $labRows,
    ['icon'=>'🧪', 'accent'=>$risk['accent'], 'bg'=>$risk['bg'], 'bd'=>$risk['bd']]);

  /* ---------- SECTION: ผู้รายงาน (ถ้ามี) หรือปุ่ม CTA ---------- */
  $reportedOk =
    (int)($r['reported_by_id'] ?? 0) > 0 &&
    !empty($r['reported_by_name']) &&
    !empty($r['reported_date']) &&
    !empty($r['reported_time']);

  if ($reportedOk) {
    $reportedTime = (string)$r['reported_time'];
    if (strlen($reportedTime) >= 5) $reportedTime = substr($reportedTime, 0, 5);
    $reportRows = [
      fr_info_row('ผู้รายงาน', (string)$r['reported_by_name'], ['value_weight'=>'bold']),
      [ "type"=>"separator", "margin"=>"sm", "color"=>"#D1FAE5" ],
      fr_info_row('วันที่รายงาน', th_date($r['reported_date'] ?? null)),
      fr_info_row('เวลารายงาน', $reportedTime),
    ];
    $sectionReport = fr_section('บันทึกการรายงานโดยเภสัชกร', $reportRows,
      ['icon'=>'✅', 'accent'=>'#065F46', 'bg'=>'#ECFDF5', 'bd'=>'#A7F3D0']);
  } else {
    $reportUrl = build_report_signed_url($r);
    $sectionReport = [
      "type"=>"box", "layout"=>"vertical",
      "paddingAll"=>"14px", "cornerRadius"=>"12px",
      "backgroundColor"=>"#EFF6FF", "borderColor"=>"#BFDBFE", "borderWidth"=>"1px",
      "margin"=>"md", "spacing"=>"sm",
      "contents"=>[
        [ "type"=>"box", "layout"=>"baseline", "spacing"=>"sm",
          "contents"=>[
            [ "type"=>"text", "text"=>"📝  ยังไม่ได้บันทึกรายงาน",
              "size"=>"xs", "color"=>"#1E3A8A", "weight"=>"bold", "flex"=>1 ],
          ]
        ],
        [ "type"=>"text", "text"=>"กรุณากดปุ่มด้านล่างเพื่อเปิดแบบฟอร์มรายงาน และบันทึกการทบทวน/ปรับยา",
          "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
        [ "type"=>"box", "layout"=>"vertical", "margin"=>"md",
          "contents"=>[[
            "type"=>"button", "style"=>"primary", "color"=>"#2563EB", "height"=>"sm",
            "action"=>["type"=>"uri","label"=>"รายงานการแจ้งเตือน","uri"=>$reportUrl]
          ]]
        ],
      ]
    ];
  }

  /* ---------- SECTION: คำแนะนำ / Action ---------- */
  $actions = [
    [ "type"=>"text", "text"=>"ข้อแนะนำในการดำเนินการ",
      "size"=>"sm", "color"=>"#1E3A8A", "weight"=>"bold", "margin"=>"sm" ],
    [ "type"=>"text", "text"=>"• ทบทวนขนาดยา / อันตรกิริยายาที่อาจเป็นสาเหตุ",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"sm" ],
    [ "type"=>"text", "text"=>"• เฝ้าระวังอาการไม่พึงประสงค์ และบันทึกใน HOSxP",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
    [ "type"=>"text", "text"=>"• ประสานแพทย์เจ้าของไข้เพื่อพิจารณาปรับแผนการรักษา",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
    [ "type"=>"text", "text"=>"• บันทึกผลการรายงานผ่านปุ่ม \"รายงานการแจ้งเตือน\"",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
  ];
  $sectionAction = fr_section('คำแนะนำสำหรับเภสัชกร', $actions,
    ['icon'=>'📋', 'accent'=>'#1E3A8A', 'bg'=>'#EFF6FF', 'bd'=>'#BFDBFE']);

  /* ---------- BODY ---------- */
  $body = [
    "type"=>"box", "layout"=>"vertical", "spacing"=>"none", "paddingAll"=>"0px",
    "contents"=>[
      $titleStrip,
      [ "type"=>"box", "layout"=>"vertical", "paddingAll"=>"14px", "spacing"=>"none",
        "backgroundColor"=>"#F9FAFB",
        "contents"=>[
          $priority,
          $sectionPatient,
          $sectionLab,
          $sectionReport,
          $sectionAction,
        ]
      ],
    ],
  ];

  /* ---------- FOOTER (signature + timestamp) ---------- */
  $footer = [
    "type"=>"box", "layout"=>"vertical",
    "paddingStart"=>"14px", "paddingEnd"=>"14px", "paddingTop"=>"10px", "paddingBottom"=>"12px",
    "backgroundColor"=>"#F3F4F6",
    "contents"=>[
      [ "type"=>"separator", "color"=>"#E5E7EB" ],
      [ "type"=>"box", "layout"=>"horizontal", "margin"=>"md",
        "contents"=>[
          [ "type"=>"text", "text"=>PHARM_SYSTEM_NAME,
            "size"=>"xxs", "color"=>"#6B7280", "flex"=>3, "wrap"=>true ],
          [ "type"=>"text", "text"=>date('j M Y H:i'),
            "size"=>"xxs", "color"=>"#6B7280", "align"=>"end", "flex"=>2 ],
        ]
      ],
      $refId ? [
        "type"=>"text", "text"=>"Ref: #".(string)$refId.($labOrder ? "  •  Lab Order: $labOrder" : ""),
        "size"=>"xxs", "color"=>"#9CA3AF", "margin"=>"xs"
      ] : [ "type"=>"filler" ],
    ],
  ];

  /* ---------- BUBBLE ---------- */
  $bubble = [
    "type"=>"bubble", "size"=>"giga",
    "header"=>$header,
    "body"=>$body,
    "footer"=>$footer,
    "styles"=>[
      "header"=>[ "backgroundColor"=>"#FFFFFF" ],
      "body"=>  [ "backgroundColor"=>"#F9FAFB" ],
      "footer"=>[ "backgroundColor"=>"#F3F4F6" ],
    ],
  ];

  /* ---------- FULL PAYLOAD (messages[]) ---------- */
  $altText = sprintf('[Pharm Lab Alert] HN %s %s • %s = %s',
    $hn, $fullname, $labName, $result);
  if (mb_strlen($altText) > 400) $altText = mb_substr($altText, 0, 397).'...';

  return [
    "messages"=>[[
      "type"=>"flex",
      "altText"=>$altText,
      "contents"=>$bubble,
    ]]
  ];
}

} // end function_exists guard
