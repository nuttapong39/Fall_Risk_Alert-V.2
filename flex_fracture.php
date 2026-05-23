<?php
/**
 * flex_fracture.php
 * ไลบรารีกลาง: ประกอบ Flex message สำหรับแจ้งเตือนผู้ป่วยกลุ่มเสี่ยงพลัดตก/หกล้ม
 * (Fall Risk Alert)  — ถูกใช้ร่วมกันโดย fracture.php และ fracture_queue_action.php
 *
 * ออกแบบให้:
 *   - ดูเป็นทางการ เหมาะกับระบบสุขภาพของ รพ.สต.
 *   - อ่านง่าย จัดกลุ่มข้อมูลเป็น section (ข้อมูลผู้ป่วย / การวินิจฉัย / ติดต่อเยี่ยมบ้าน / คำแนะนำ)
 *   - มี call-to-action ชัดเจน (เยี่ยมบ้านใน 7 วัน ประเมินความเสี่ยงในบ้าน)
 *   - ใช้สี/ไอคอนตามมาตรฐาน LINE Flex ไม่ต้องโหลดรูปเพิ่ม
 */

/* -------------------- Default constants -------------------- */
if (!defined('FALL_TITLE'))      define('FALL_TITLE',      'แจ้งเตือนผู้ป่วยกลุ่มเสี่ยงพลัดตก / หกล้ม');
if (!defined('FALL_SUBTITLE'))   define('FALL_SUBTITLE',   'Fall Risk Alert สำหรับเจ้าหน้าที่ รพ.สต. เครือข่าย');
if (!defined('FALL_HEADER_URL')) define('FALL_HEADER_URL', 'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
if (!defined('FALL_ICON_URL'))   define('FALL_ICON_URL',   'https://www.ckhospital.net/home/PDF/Logo_ck.png');
if (!defined('FALL_SYSTEM_NAME')) define('FALL_SYSTEM_NAME', 'ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยง • รพ.เชียงกลาง');

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
if (!function_exists('row_to_utf8')) {
  function row_to_utf8(array $row): array {
    foreach ($row as $k => $v) if (is_string($v)) $row[$k] = to_utf8($v);
    return $row;
  }
}

/* -------------------- Thai date helper -------------------- */
if (!function_exists('fr_thai_date')) {
  /**
   * Format YYYY-MM-DD (ค.ศ.) → "23 เม.ย. 2569"  (พ.ศ., เดือนย่อ)
   * ถ้าพาร์สไม่สำเร็จ ส่งคืนค่าเดิม
   */
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

/* -------------------- Flex row helper -------------------- */
if (!function_exists('fr_info_row')) {
  /**
   * สร้าง 1 แถวข้อมูล (label ซ้าย, value ขวา) ที่จัดชิดซ้าย-ขวาเหมือน key-value
   */
  function fr_info_row(string $label, ?string $value, array $opts = []): array {
    $v = ($value === null || $value === '') ? '-' : (string)$value;
    $valueColor = $opts['value_color'] ?? '#111827';
    $valueWeight = $opts['value_weight'] ?? 'regular';
    $valueSize = $opts['value_size'] ?? 'sm';
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

/* -------------------- Flex section card -------------------- */
if (!function_exists('fr_section')) {
  /**
   * กล่อง section หนึ่งก้อน: label เล็ก ๆ ด้านบน + เนื้อหาด้านล่าง
   */
  function fr_section(string $title, array $rows, array $opts = []): array {
    $bg   = $opts['bg']   ?? '#FFFFFF';
    $bd   = $opts['bd']   ?? '#E5E7EB';
    $icon = $opts['icon'] ?? '';
    $accent = $opts['accent'] ?? '#2563EB'; // ฟ้า default
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
if (!function_exists('buildFracturePayload')) {

/**
 * ประกอบ Flex payload จาก row เดียวของ fracture_queue
 * @param array $row
 * @return array  payload พร้อมส่ง MOPH Alert (มี messages[])
 */
function buildFracturePayload(array $row): array {
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

  /* ---------- HEADER BANNER (image) ---------- */
  $header = [
    "type"=>"box", "layout"=>"vertical", "paddingAll"=>"0px",
    "contents"=> FALL_HEADER_URL ? [[
      "type"=>"image", "url"=>FALL_HEADER_URL,
      "size"=>"full", "aspectRatio"=>"3120:885", "aspectMode"=>"cover",
    ]] : [],
  ];

  /* ---------- TITLE STRIP (navy gradient) ---------- */
  $titleStrip = [
    "type"=>"box", "layout"=>"vertical",
    "paddingAll"=>"16px", "backgroundColor"=>"#0F2A5B", "cornerRadius"=>"0px",
    "contents"=>[
      [ "type"=>"box", "layout"=>"horizontal", "contents"=>[
          [ "type"=>"text", "text"=>"🚨  แจ้งเตือนผู้ป่วยกลุ่มเสี่ยง",
            "size"=>"xs", "color"=>"#FDE68A", "weight"=>"bold", "flex"=>1 ],
          [ "type"=>"text", "text"=>"Fall Risk Alert",
            "size"=>"xs", "color"=>"#BFDBFE", "align"=>"end", "flex"=>0 ],
      ]],
      [ "type"=>"text", "text"=>FALL_TITLE,
        "size"=>"xl", "color"=>"#FFFFFF", "weight"=>"bold", "wrap"=>true, "margin"=>"sm" ],
      [ "type"=>"text", "text"=>FALL_SUBTITLE,
        "size"=>"xs", "color"=>"#CBD5E1", "wrap"=>true, "margin"=>"xs" ],
    ],
  ];

  /* ---------- PRIORITY BADGE (red strip) ---------- */
  $priority = [
    "type"=>"box", "layout"=>"baseline", "spacing"=>"sm",
    "paddingAll"=>"10px", "backgroundColor"=>"#FEE2E2", "cornerRadius"=>"8px", "margin"=>"md",
    "contents"=>[
      [ "type"=>"text", "text"=>"⚠", "size"=>"md", "flex"=>0, "color"=>"#B91C1C" ],
      [ "type"=>"text", "text"=>"ผู้สูงอายุ ≥ 60 ปี · วินิจฉัยกลุ่ม W00–W19 / S-codes กระดูกหัก",
        "size"=>"xs", "color"=>"#991B1B", "weight"=>"bold", "wrap"=>true, "flex"=>1 ],
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
    ['icon'=>'🧑‍⚕️', 'accent'=>'#0F2A5B']);

  /* ---------- SECTION: การวินิจฉัย (amber tinted) ---------- */
  $dxCodeBox = [
    "type"=>"box", "layout"=>"baseline", "spacing"=>"sm", "margin"=>"sm",
    "contents"=>[
      [ "type"=>"text", "text"=>"ICD-10", "size"=>"xs", "color"=>"#92400E", "weight"=>"bold", "flex"=>0 ],
      [ "type"=>"text", "text"=>$pdxCode, "size"=>"lg", "color"=>"#78350F",
        "weight"=>"bold", "flex"=>1, "align"=>"end" ],
    ]
  ];
  $dxNameBox = $pdxName
    ? [ "type"=>"text", "text"=>$pdxName, "size"=>"sm", "color"=>"#1F2937",
        "wrap"=>true, "margin"=>"sm" ]
    : null;
  $dxDateBox = [
    "type"=>"separator", "margin"=>"sm", "color"=>"#FDE68A"
  ];
  $dxVisitRow = fr_info_row('วันที่รับบริการ', $vstdate);
  $dxStationRow = fr_info_row('สถานบริการหลัก', $station);
  $dxRows = array_values(array_filter([$dxCodeBox, $dxNameBox, $dxDateBox, $dxVisitRow, $dxStationRow]));
  $sectionDx = fr_section('การวินิจฉัยและการรับบริการ', $dxRows,
    ['icon'=>'🩺', 'accent'=>'#92400E', 'bg'=>'#FFFBEB', 'bd'=>'#FDE68A']);

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
    [ "type"=>"text", "text"=>"โปรดดำเนินการภายใน 7 วัน",
      "size"=>"sm", "color"=>"#1E3A8A", "weight"=>"bold", "margin"=>"sm" ],
    [ "type"=>"text", "text"=>"• ติดตามเยี่ยมบ้าน ประเมินการฟื้นตัวของผู้ป่วย",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"sm" ],
    [ "type"=>"text", "text"=>"• ประเมินปัจจัยเสี่ยงในบ้าน (พื้นลื่น แสงสว่าง ราวจับ ห้องน้ำ)",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
    [ "type"=>"text", "text"=>"• ให้ความรู้การป้องกันการพลัดตกซ้ำ",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
    [ "type"=>"text", "text"=>"• บันทึกผลการเยี่ยมในระบบ HDC / JHCIS",
      "size"=>"xs", "color"=>"#1F2937", "wrap"=>true, "margin"=>"xs" ],
  ];
  $sectionAction = fr_section('คำแนะนำสำหรับเจ้าหน้าที่ รพ.สต.', $actions,
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
          [ "type"=>"text", "text"=>FALL_SYSTEM_NAME,
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
  $altText = sprintf('[แจ้งเตือน] ผู้ป่วยกลุ่มเสี่ยงหกล้ม HN %s %s (ICD %s)',
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

/* -------------------- Extract messageId helper (shared) -------------------- */
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
