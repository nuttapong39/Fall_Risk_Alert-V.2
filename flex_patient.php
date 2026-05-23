<?php
/**
 * flex_patient.php
 * ไลบรารีกลาง: ประกอบ Flex message สำหรับแจ้งเตือนผู้ป่วยกลุ่มเสี่ยงจิตเวช / ทำร้ายตัวเอง
 * (Psychiatric / Self-harm Risk Alert)
 *   — ถูกใช้ร่วมกันโดย patient.php, patient_action.php และ patient_ingest.php
 *
 * กลุ่มเป้าหมาย (ICD-10):
 *   T71        — Asphyxiation (การขาดอากาศ)
 *   X60–X69    — Intentional self-poisoning
 *   X70        — Intentional self-harm by hanging/strangulation
 *   X84        — Intentional self-harm by unspecified means
 *
 * ออกแบบให้:
 *   - ดูเป็นทางการ โทนสีแดง-ส้มเพื่อเน้นความเร่งด่วน (crisis/urgent)
 *   - มี CTA ชัดเจนสำหรับทีมเยี่ยมบ้านจิตเวช รพ.สต. (ติดตามภายใน 24–48 ชม.)
 *   - ใช้โครงเดียวกับ flex_fracture.php — section card + key-value rows
 *   - ฟังก์ชัน helper ทั้งหมดมี `function_exists` guard เพื่อให้โหลดคู่กับ flex_fracture.php ได้
 */

/* -------------------- Default constants (Psych) -------------------- */
if (!defined('PSYCH_TITLE'))      define('PSYCH_TITLE',      'แจ้งเตือนผู้ป่วยกลุ่มเสี่ยงจิตเวช / ทำร้ายตนเอง');
if (!defined('PSYCH_SUBTITLE'))   define('PSYCH_SUBTITLE',   'Psychiatric / Self-harm Alert สำหรับเจ้าหน้าที่ รพ.สต. เครือข่าย');
if (!defined('PSYCH_HEADER_URL')) define('PSYCH_HEADER_URL', defined('FALL_HEADER_URL') ? FALL_HEADER_URL : 'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
if (!defined('PSYCH_ICON_URL'))   define('PSYCH_ICON_URL',   defined('FALL_ICON_URL')   ? FALL_ICON_URL   : 'https://www.ckhospital.net/home/PDF/Logo_ck.png');
if (!defined('PSYCH_SYSTEM_NAME')) define('PSYCH_SYSTEM_NAME', 'ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยง • รพ.เชียงกลาง');

/* -------------------- Encoding helpers (guarded — shared with flex_fracture) -------------------- */
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
if (!function_exists('row_to_utf8')) {
  function row_to_utf8(array $row): array {
    foreach ($row as $k => $v) if (is_string($v)) $row[$k] = to_utf8($v);
    return $row;
  }
}

/* -------------------- Thai date helper (guarded) -------------------- */
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

/* -------------------- Flex row helper (guarded) -------------------- */
if (!function_exists('fr_info_row')) {
  function fr_info_row(string $label, ?string $value, array $opts = []): array {
    $v = ($value === null || $value === '') ? '-' : (string)$value;
    $valueColor  = $opts['value_color']  ?? '#111827';
    $valueWeight = $opts['value_weight'] ?? 'regular';
    $valueSize   = $opts['value_size']   ?? 'sm';
    return [
      "type" => "box",
      "layout" => "baseline",
      "spacing" => "sm",
      "margin" => "sm",
      "contents" => [
        [ "type"=>"text", "text"=>$label, "size"=>"sm", "color"=>"#6B7280", "flex"=>3, "weight"=>"regular" ],
        [ "type"=>"text", "text"=>$v,    "size"=>$valueSize, "color"=>$valueColor,
          "weight"=>$valueWeight, "flex"=>5, "wrap"=>true, "align"=>"end" ],
      ]
    ];
  }
}

/* -------------------- Flex section card (guarded) -------------------- */
if (!function_exists('fr_section')) {
  function fr_section(string $title, array $rows, array $opts = []): array {
    $bg   = $opts['bg']   ?? '#FFFFFF';
    $bd   = $opts['bd']   ?? '#E5E7EB';
    $icon = $opts['icon'] ?? '';
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

/* -------------------- MAIN: Build the Patient Flex payload -------------------- */
if (!function_exists('buildPatientPayload')) {

/**
 * ประกอบ Flex payload จาก row เดียวของ patient_queue
 * @param array $row
 * @return array  payload พร้อมส่ง MOPH Alert (มี messages[])
 */
function buildPatientPayload(array $row): array {
  $row = row_to_utf8($row);

  /* ---------- Normalize values ---------- */
  $hn       = $row['hn']       ?? '-';
  $fullname = $row['fullname'] ?? '-';
  $age      = $row['age']      ?? '';
  $sex      = $row['sex']      ?? '';
  $address  = $row['address']  ?? '-';
  $tel      = $row['hometel']  ?? '-';
  $pdxCode  = $row['pdx_code'] ?? '-';
  $pdxName  = $row['pdx_name'] ?? '';
  $vstdate  = fr_thai_date($row['vstdate'] ?? null);
  $station  = $row['mainstation'] ?? '-';
  $refId    = $row['visit_vn'] ?? ($row['id'] ?? '');

  $ageSex = '-';
  if ($age !== '' && $sex !== '') $ageSex = "{$age} ปี · {$sex}";
  elseif ($age !== '')            $ageSex = "{$age} ปี";
  elseif ($sex !== '')            $ageSex = (string)$sex;

  /* ---------- Category badge จาก ICD ---------- */
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

  /* ---------- HEADER BANNER (image) ---------- */
  $header = [
    "type"=>"box", "layout"=>"vertical", "paddingAll"=>"0px",
    "contents"=> PSYCH_HEADER_URL ? [[
      "type"=>"image", "url"=>PSYCH_HEADER_URL,
      "size"=>"full", "aspectRatio"=>"3120:885", "aspectMode"=>"cover",
    ]] : [],
  ];

  /* ---------- TITLE STRIP (deep-red gradient) ---------- */
  $titleStrip = [
    "type"=>"box", "layout"=>"vertical",
    "paddingAll"=>"16px", "backgroundColor"=>"#7F1D1D", "cornerRadius"=>"0px",
    "contents"=>[
      [ "type"=>"box", "layout"=>"horizontal", "contents"=>[
          [ "type"=>"text", "text"=>"🆘  แจ้งเตือนเร่งด่วน",
            "size"=>"xs", "color"=>"#FDE68A", "weight"=>"bold", "flex"=>1 ],
          [ "type"=>"text", "text"=>"Psych / Self-harm",
            "size"=>"xs", "color"=>"#FECACA", "align"=>"end", "flex"=>0 ],
      ]],
      [ "type"=>"text", "text"=>PSYCH_TITLE,
        "size"=>"xl", "color"=>"#FFFFFF", "weight"=>"bold", "wrap"=>true, "margin"=>"sm" ],
      [ "type"=>"text", "text"=>PSYCH_SUBTITLE,
        "size"=>"xs", "color"=>"#FECACA", "wrap"=>true, "margin"=>"xs" ],
    ],
  ];

  /* ---------- PRIORITY BADGE (urgent strip) ---------- */
  $priority = [
    "type"=>"box", "layout"=>"baseline", "spacing"=>"sm",
    "paddingAll"=>"10px", "backgroundColor"=>"#FEE2E2", "cornerRadius"=>"8px", "margin"=>"md",
    "contents"=>[
      [ "type"=>"text", "text"=>"⚠", "size"=>"md", "flex"=>0, "color"=>"#B91C1C" ],
      [ "type"=>"text", "text"=>"กลุ่มเสี่ยงสูง · T71 / X60–X69 / X70 / X84 · ต้องติดตามภายใน 24–48 ชม.",
        "size"=>"xs", "color"=>"#7F1D1D", "weight"=>"bold", "wrap"=>true, "flex"=>1 ],
    ],
  ];

  /* ---------- SECTION: ข้อมูลผู้ป่วย ---------- */
  $patientRows = [
    fr_info_row('HN', $hn, ['value_weight'=>'bold','value_color'=>'#111827','value_size'=>'md']),
    [ "type"=>"separator", "margin"=>"sm", "color"=>"#F3F4F6" ],
    fr_info_row('ชื่อ-สกุล', $fullname, ['value_weight'=>'bold']),
    [ "type"=>"separator", "margin"=>"sm", "color"=>"#F3F4F6" ],
    fr_info_row('อายุ / เพศ', $ageSex),
  ];
  $sectionPatient = fr_section('ข้อมูลผู้ป่วย', $patientRows,
    ['icon'=>'🧑‍⚕️', 'accent'=>'#7F1D1D']);

  /* ---------- SECTION: การวินิจฉัย (rose tinted) ---------- */
  $dxCodeBox = [
    "type"=>"box", "layout"=>"baseline", "spacing"=>"sm", "margin"=>"sm",
    "contents"=>[
      [ "type"=>"text", "text"=>"ICD-10", "size"=>"xs", "color"=>"#9F1239", "weight"=>"bold", "flex"=>0 ],
      [ "type"=>"text", "text"=>$pdxCode, "size"=>"lg", "color"=>"#881337",
        "weight"=>"bold", "flex"=>1, "align"=>"end" ],
    ]
  ];
  $dxCategoryBox = [
    "type"=>"text", "text"=>'กลุ่ม: '.$categoryLabel,
    "size"=>"xs", "color"=>"#9F1239", "wrap"=>true, "margin"=>"xs",
  ];
  $dxNameBox = $pdxName
    ? [ "type"=>"text", "text"=>$pdxName, "size"=>"sm", "color"=>"#1F2937",
        "wrap"=>true, "margin"=>"sm" ]
    : null;
  $dxSep = [ "type"=>"separator", "margin"=>"sm", "color"=>"#FECDD3" ];
  $dxVisitRow   = fr_info_row('วันที่รับบริการ', $vstdate);
  $dxStationRow = fr_info_row('สถานบริการหลัก', $station);
  $dxRows = array_values(array_filter([$dxCodeBox, $dxCategoryBox, $dxNameBox, $dxSep, $dxVisitRow, $dxStationRow]));
  $sectionDx = fr_section('การวินิจฉัยและการรับบริการ', $dxRows,
    ['icon'=>'🩺', 'accent'=>'#9F1239', 'bg'=>'#FFF1F2', 'bd'=>'#FECDD3']);

  /* ---------- SECTION: ติดต่อ / เยี่ยมบ้าน ---------- */
  $addrRow = [
    "type"=>"box", "layout"=>"vertical", "spacing"=>"xs", "margin"=>"sm",
    "contents"=>[
      [ "type"=>"text", "text"=>"📍 ที่อยู่", "size"=>"xs", "color"=>"#6B7280", "weight"=>"bold" ],
      [ "type"=>"text", "text"=>$address,    "size"=>"sm", "color"=>"#111827", "wrap"=>true ],
    ]
  ];
  $telRow = [
    "type"=>"box", "layout"=>"baseline", "spacing"=>"sm", "margin"=>"md",
    "contents"=>[
      [ "type"=>"text", "text"=>"📞 เบอร์โทร", "size"=>"xs", "color"=>"#6B7280", "weight"=>"bold", "flex"=>3 ],
      [ "type"=>"text", "text"=>($tel ?: '-'), "size"=>"md", "color"=>"#065F46",
        "weight"=>"bold", "flex"=>5, "align"=>"end", "wrap"=>true ],
    ]
  ];
  $sectionContact = fr_section('ข้อมูลสำหรับติดตามเยี่ยมบ้าน', [$addrRow, $telRow],
    ['icon'=>'🏠', 'accent'=>'#065F46', 'bg'=>'#ECFDF5', 'bd'=>'#A7F3D0']);

  /* ---------- SECTION: คำแนะนำ / Action ---------- */
  $actions = [
    [ "type"=>"text", "text"=>"โปรดดำเนินการภายใน 24–48 ชั่วโมง",
      "size"=>"sm", "color"=>"#7F1D1D", "weight"=>"bold", "margin"=>"sm" ],
    [ "type"=>"text", "text"=>"• ประสานทีมจิตเวช/สุขภาพจิตชุมชน เพื่อเยี่ยมบ้านประเมินภาวะเสี่ยง",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"sm" ],
    [ "type"=>"text", "text"=>"• ประเมินความเสี่ยงฆ่าตัวตายซ้ำด้วย 8Q / SU (Suicide Screening)",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
    [ "type"=>"text", "text"=>"• ตรวจสอบการเข้าถึงวิธีทำร้ายตนเอง (สารเคมี/ยา/เชือก) และลดอันตรายในบ้าน",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
    [ "type"=>"text", "text"=>"• ให้ข้อมูลสายด่วนสุขภาพจิต 1323 และช่องทางติดต่อทีมดูแลต่อเนื่อง",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
    [ "type"=>"text", "text"=>"• บันทึกผลการเยี่ยม/ส่งต่อในระบบ HDC / JHCIS",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
  ];
  $sectionAction = fr_section('คำแนะนำสำหรับเจ้าหน้าที่ รพ.สต.', $actions,
    ['icon'=>'📋', 'accent'=>'#7F1D1D', 'bg'=>'#FFF7ED', 'bd'=>'#FED7AA']);

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
          $sectionDx,
          $sectionContact,
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
          [ "type"=>"text", "text"=>PSYCH_SYSTEM_NAME,
            "size"=>"xxs", "color"=>"#6B7280", "flex"=>3, "wrap"=>true ],
          [ "type"=>"text", "text"=>date('j M Y H:i'),
            "size"=>"xxs", "color"=>"#6B7280", "align"=>"end", "flex"=>2 ],
        ]
      ],
      $refId ? [
        "type"=>"text", "text"=>"Ref: ".(string)$refId,
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
  $altText = sprintf('[แจ้งเตือนเร่งด่วน] ผู้ป่วยจิตเวช/ทำร้ายตนเอง HN %s %s (ICD %s)',
    $hn, $fullname, $pdxCode);
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

/* -------------------- Extract messageId helper (guarded) -------------------- */
if (!function_exists('extract_moph_message_id')) {
  function extract_moph_message_id($json) {
    if (!is_array($json)) return null;
    $paths = [
      ['messageId'],
      ['data','messageId'],
      ['result','messageId'],
      ['messages',0,'messageId'],
      ['messages',0,'id'],
    ];
    foreach ($paths as $path) {
      $t = $json;
      foreach ($path as $k) {
        if (is_array($t) && array_key_exists($k, $t)) $t = $t[$k];
        else { $t = null; break; }
      }
      if (is_scalar($t) && $t !== '') return (string)$t;
    }
    return null;
  }
}
