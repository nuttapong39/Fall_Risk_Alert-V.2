<?php
/**
 * flex_sexual.php
 * ไลบรารีกลาง: ประกอบ LINE Flex message สำหรับแจ้งเตือนผู้ถูกทำร้ายร่างกาย / ข่มขืน
 * (Sexual Assault / Domestic Violence Alert)
 * — ใช้ร่วมกันโดย sexual.php, sexual_action.php
 */

/* -------------------- Constants -------------------- */
if (!defined('LAB_CODE_SEXUAL'))    define('LAB_CODE_SEXUAL',    '2811');

if (!defined('SEXUAL_TITLE'))       define('SEXUAL_TITLE',       'แจ้งเตือนผู้ถูกกระทำความรุนแรง');
if (!defined('SEXUAL_SUBTITLE'))    define('SEXUAL_SUBTITLE',    'Sexual Assault Alert · รพ.เชียงกลาง');
if (!defined('SEXUAL_HEADER_URL'))  define('SEXUAL_HEADER_URL',  'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
if (!defined('SEXUAL_ICON_URL'))    define('SEXUAL_ICON_URL',    'https://www.ckhospital.net/home/PDF/Logo_ck.png');
if (!defined('SEXUAL_SYSTEM_NAME')) define('SEXUAL_SYSTEM_NAME', 'ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยง • รพ.เชียงกลาง');

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
    return @iconv('UTF-8', 'UTF-8//IGNORE', $s) ?: $s;
  }
}
if (!function_exists('row_to_utf8')) {
  function row_to_utf8(array $row): array {
    foreach ($row as $k => $v) if (is_string($v)) $row[$k] = to_utf8($v);
    return $row;
  }
}

/* -------------------- Thai date helper -------------------- */
if (!function_exists('sx_thai_date')) {
  function sx_thai_date(?string $ymd): string {
    if (!$ymd) return '-';
    $ts = strtotime($ymd);
    if ($ts === false) return $ymd;
    static $months = [
      1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',
      7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.',
    ];
    return sprintf('%d %s %d', (int)date('j',$ts), $months[(int)date('n',$ts)]??'', (int)date('Y',$ts)+543);
  }
}

/* -------------------- Flex row helper -------------------- */
if (!function_exists('sx_info_row')) {
  function sx_info_row(string $label, ?string $value, array $opts = []): array {
    $v = ($value === null || $value === '') ? '-' : (string)$value;
    return [
      "type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"sm",
      "contents"=>[
        ["type"=>"text","text"=>$label,"size"=>"md","color"=>"#6B7280","flex"=>3,"weight"=>"regular"],
        ["type"=>"text","text"=>$v,"size"=>$opts['value_size']??"md","color"=>$opts['value_color']??"#111827",
         "weight"=>$opts['value_weight']??"regular","flex"=>5,"wrap"=>true,"align"=>"end"],
      ]
    ];
  }
}

/* -------------------- Flex section helper -------------------- */
if (!function_exists('sx_section')) {
  function sx_section(string $title, array $rows, array $opts = []): array {
    $bg   = $opts['bg']     ?? '#FFFFFF';
    $bd   = $opts['bd']     ?? '#E5E7EB';
    $icon = $opts['icon']   ?? '';
    $acc  = $opts['accent'] ?? '#881337';
    $titleBox = [
      "type"=>"box","layout"=>"baseline","spacing"=>"sm",
      "contents"=>[
        ["type"=>"text","text"=>($icon?"$icon  ":'').$title,
         "size"=>"sm","color"=>$acc,"weight"=>"bold","flex"=>1],
      ]
    ];
    return [
      "type"=>"box","layout"=>"vertical",
      "paddingAll"=>"14px","cornerRadius"=>"12px","margin"=>"md","spacing"=>"xs",
      "backgroundColor"=>$bg,"borderColor"=>$bd,"borderWidth"=>"1px",
      "contents"=>array_merge([$titleBox], $rows),
    ];
  }
}

/* -------------------- MAIN: Build Flex payload -------------------- */
if (!function_exists('buildSexualPayload')) {

/**
 * ประกอบ LINE Flex payload สำหรับผู้ถูกทำร้ายร่างกาย / ข่มขืน
 * @param  array $row  — แถวจาก HOSxP (lab_order JOIN lab_head JOIN patient)
 * @return array       — payload พร้อมส่ง MOPH Alert
 */
function buildSexualPayload(array $row): array {
  $row = row_to_utf8($row);

  /* ---------- Normalize ---------- */
  $vn         = $row['vn']               ?? '-';
  $hn         = $row['hn']               ?? '-';
  $fullname   = $row['fullname']         ?? '-';
  $age        = $row['age']              ?? '';
  $sex        = $row['sex']              ?? '';
  $cid        = $row['cid']              ?? '-';
  $tel        = $row['hometel']          ?? '-';
  $address    = $row['address']          ?? '-';
  $labName    = $row['lab_items_name_ref'] ?? '-';
  $labResult  = $row['lab_order_result'] ?? '-';
  $orderDate  = sx_thai_date($row['order_date'] ?? null);

  $ageSex = match(true) {
    $age !== '' && $sex !== '' => "{$age} ปี · {$sex}",
    $age !== ''                => "{$age} ปี",
    $sex !== ''                => (string)$sex,
    default                    => '-',
  };

  /* ---------- HEADER BANNER ---------- */
  $header = [
    "type"=>"box","layout"=>"vertical","paddingAll"=>"0px",
    "contents"=> SEXUAL_HEADER_URL ? [[
      "type"=>"image","url"=>SEXUAL_HEADER_URL,
      "size"=>"full","aspectRatio"=>"3120:885","aspectMode"=>"cover",
    ]] : [],
  ];

  /* ---------- TITLE STRIP (rose/crimson) ---------- */
  $titleStrip = [
    "type"=>"box","layout"=>"vertical",
    "paddingAll"=>"16px","backgroundColor"=>"#881337","cornerRadius"=>"0px",
    "contents"=>[
      ["type"=>"box","layout"=>"horizontal","contents"=>[
        ["type"=>"text","text"=>"🚨  แจ้งเตือนด่วน",
         "size"=>"sm","color"=>"#FECDD3","weight"=>"bold","flex"=>1],
        ["type"=>"text","text"=>"Sexual Assault",
         "size"=>"sm","color"=>"#FDA4AF","align"=>"end","flex"=>0],
      ]],
      ["type"=>"text","text"=>SEXUAL_TITLE,
       "size"=>"xxl","color"=>"#FFFFFF","weight"=>"bold","wrap"=>true,"margin"=>"sm"],
      ["type"=>"text","text"=>SEXUAL_SUBTITLE,
       "size"=>"sm","color"=>"#FDA4AF","wrap"=>true,"margin"=>"xs"],
    ],
  ];

  /* ---------- PRIORITY BADGE ---------- */
  $priority = [
    "type"=>"box","layout"=>"baseline","spacing"=>"sm",
    "paddingAll"=>"10px","backgroundColor"=>"#FFF1F2","cornerRadius"=>"8px","margin"=>"md",
    "contents"=>[
      ["type"=>"text","text"=>"🚨","size"=>"lg","flex"=>0,"color"=>"#BE123C"],
      ["type"=>"text","text"=>"ผู้ป่วยได้รับบริการตรวจทางนิติวิทยาศาสตร์ กรุณาติดตามและให้การดูแลอย่างเร่งด่วน",
       "size"=>"sm","color"=>"#9F1239","weight"=>"bold","wrap"=>true,"flex"=>1],
    ],
  ];

  /* ---------- SECTION: ข้อมูลผู้ป่วย ---------- */
  $sPatient = sx_section('ข้อมูลผู้ป่วย', [
    sx_info_row('HN', $hn, ['value_weight'=>'bold','value_color'=>'#111827','value_size'=>'lg']),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    sx_info_row('ชื่อ-สกุล', $fullname, ['value_weight'=>'bold']),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    sx_info_row('อายุ / เพศ', $ageSex),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    sx_info_row('เลขบัตรประชาชน', $cid),
  ], ['icon'=>'🧑‍⚕️','accent'=>'#881337']);

  /* ---------- SECTION: ผลตรวจ LAB (rose) ---------- */
  $labResultBox = [
    "type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"sm",
    "contents"=>[
      ["type"=>"text","text"=>"ผลตรวจ","size"=>"sm","color"=>"#9F1239","weight"=>"bold","flex"=>0],
      ["type"=>"text","text"=>$labResult,"size"=>"xl","color"=>"#7F1D1D",
       "weight"=>"bold","flex"=>1,"align"=>"end"],
    ]
  ];
  $sLab = sx_section('ผลตรวจ LAB', [
    $labResultBox,
    ["type"=>"separator","margin"=>"sm","color"=>"#FECDD3"],
    sx_info_row('รายการ LAB', $labName, ['value_weight'=>'bold','value_color'=>'#7F1D1D','value_size'=>'md']),
    sx_info_row('วันที่สั่ง LAB', $orderDate),
  ], ['icon'=>'🔬','accent'=>'#9F1239','bg'=>'#FFF1F2','bd'=>'#FECDD3']);

  /* ---------- SECTION: ติดต่อ / เยี่ยมบ้าน (green) ---------- */
  $sContact = sx_section('ข้อมูลสำหรับติดตาม', [
    [
      "type"=>"box","layout"=>"vertical","spacing"=>"xs","margin"=>"sm",
      "contents"=>[
        ["type"=>"text","text"=>"📍 ที่อยู่","size"=>"sm","color"=>"#6B7280","weight"=>"bold"],
        ["type"=>"text","text"=>$address,"size"=>"md","color"=>"#111827","wrap"=>true],
      ]
    ],
    [
      "type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"md",
      "contents"=>[
        ["type"=>"text","text"=>"📞 เบอร์โทร","size"=>"sm","color"=>"#6B7280","weight"=>"bold","flex"=>3],
        ["type"=>"text","text"=>($tel?:'-'),"size"=>"lg","color"=>"#065F46",
         "weight"=>"bold","flex"=>5,"align"=>"end","wrap"=>true],
      ]
    ],
  ], ['icon'=>'🏠','accent'=>'#065F46','bg'=>'#ECFDF5','bd'=>'#A7F3D0']);

  /* ---------- SECTION: คำแนะนำ (blue) ---------- */
  $sAction = sx_section('คำแนะนำสำหรับเจ้าหน้าที่', [
    ["type"=>"text","text"=>"โปรดติดตามผู้ป่วยอย่างเร่งด่วน",
     "size"=>"md","color"=>"#1E3A8A","weight"=>"bold","margin"=>"sm"],
    ["type"=>"text","text"=>"• ประสานงานทีมสหวิชาชีพ (แพทย์ พยาบาล นักสังคมสงเคราะห์)",
     "size"=>"sm","color"=>"#1F2937","wrap"=>true,"margin"=>"sm"],
    ["type"=>"text","text"=>"• ประเมินความปลอดภัยและให้การคุ้มครองผู้ป่วย",
     "size"=>"sm","color"=>"#1F2937","wrap"=>true,"margin"=>"xs"],
    ["type"=>"text","text"=>"• ติดตามผลตรวจและให้ยาป้องกันการติดเชื้อ HIV / STI",
     "size"=>"sm","color"=>"#1F2937","wrap"=>true,"margin"=>"xs"],
    ["type"=>"text","text"=>"• บันทึกผลการติดตามในระบบ HDC / JHCIS",
     "size"=>"sm","color"=>"#1F2937","wrap"=>true,"margin"=>"xs"],
  ], ['icon'=>'📋','accent'=>'#1E3A8A','bg'=>'#EFF6FF','bd'=>'#BFDBFE']);

  /* ---------- BODY ---------- */
  $body = [
    "type"=>"box","layout"=>"vertical","spacing"=>"none","paddingAll"=>"0px",
    "contents"=>[
      $titleStrip,
      [
        "type"=>"box","layout"=>"vertical","paddingAll"=>"14px","spacing"=>"none",
        "backgroundColor"=>"#F9FAFB",
        "contents"=>[$priority, $sPatient, $sLab, $sContact, $sAction],
      ],
    ],
  ];

  /* ---------- FOOTER ---------- */
  $footer = [
    "type"=>"box","layout"=>"vertical",
    "paddingStart"=>"14px","paddingEnd"=>"14px","paddingTop"=>"10px","paddingBottom"=>"12px",
    "backgroundColor"=>"#F3F4F6",
    "contents"=>[
      ["type"=>"separator","color"=>"#E5E7EB"],
      ["type"=>"box","layout"=>"horizontal","margin"=>"md","contents"=>[
        ["type"=>"text","text"=>SEXUAL_SYSTEM_NAME,"size"=>"xs","color"=>"#6B7280","flex"=>3,"wrap"=>true],
        ["type"=>"text","text"=>date('j M Y H:i'),"size"=>"xs","color"=>"#6B7280","align"=>"end","flex"=>2],
      ]],
      $vn && $vn !== '-'
        ? ["type"=>"text","text"=>"Ref VN: ".(string)$vn,"size"=>"xs","color"=>"#9CA3AF","margin"=>"xs"]
        : ["type"=>"filler"],
    ],
  ];

  /* ---------- BUBBLE ---------- */
  $bubble = [
    "type"=>"bubble","size"=>"giga",
    "header"=>$header,
    "body"=>$body,
    "footer"=>$footer,
    "styles"=>[
      "header"=>["backgroundColor"=>"#FFFFFF"],
      "body"=>  ["backgroundColor"=>"#F9FAFB"],
      "footer"=>["backgroundColor"=>"#F3F4F6"],
    ],
  ];

  /* ---------- FULL PAYLOAD ---------- */
  $altText = sprintf('[แจ้งเตือนด่วน] ผู้ถูกกระทำความรุนแรง HN %s %s (%s)', $hn, $fullname, $labName);
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

/* -------------------- Extract messageId (guarded) -------------------- */
if (!function_exists('extract_moph_message_id')) {
  function extract_moph_message_id($json) {
    if (!is_array($json)) return null;
    $paths = [['messageId'],['data','messageId'],['result','messageId'],
              ['messages',0,'messageId'],['messages',0,'id']];
    foreach ($paths as $path) {
      $t = $json;
      foreach ($path as $k) {
        if (is_array($t) && array_key_exists($k,$t)) $t = $t[$k];
        else { $t = null; break; }
      }
      if (is_scalar($t) && $t !== '') return (string)$t;
    }
    return null;
  }
}
