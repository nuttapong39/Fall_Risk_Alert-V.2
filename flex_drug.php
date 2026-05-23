<?php
/**
 * flex_drug.php
 * ไลบรารีกลาง: ประกอบ Flex message LINE สำหรับแจ้งเตือนผู้ป่วยกลุ่มเสี่ยงยาอันตราย
 * (High-Alert Medication Alert)
 * — ใช้ร่วมกันโดย drug_queue_action.php
 *
 * ต้องการ:
 *   to_utf8(), row_to_utf8()  — หากยังไม่ define จะ define ให้ภายในไฟล์นี้
 */

/* -------------------- Default constants -------------------- */
if (!defined('DRUG_TITLE'))       define('DRUG_TITLE',       'แจ้งเตือนผู้ป่วยกลุ่มเสี่ยงยาอันตราย');
if (!defined('DRUG_SUBTITLE'))    define('DRUG_SUBTITLE',    'High-Alert Medication Alert · รพ.เชียงกลาง');
if (!defined('DRUG_HEADER_URL'))  define('DRUG_HEADER_URL',  'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
if (!defined('DRUG_ICON_URL'))    define('DRUG_ICON_URL',    'https://www.ckhospital.net/home/PDF/Logo_ck.png');
if (!defined('DRUG_SYSTEM_NAME')) define('DRUG_SYSTEM_NAME', 'ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยง • รพ.เชียงกลาง');

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
if (!function_exists('dr_thai_date')) {
  function dr_thai_date(?string $ymd): string {
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
if (!function_exists('dr_info_row')) {
  function dr_info_row(string $label, ?string $value, array $opts = []): array {
    $v = ($value === null || $value === '') ? '-' : (string)$value;
    return [
      "type"=>"box", "layout"=>"baseline", "spacing"=>"sm", "margin"=>"sm",
      "contents"=>[
        ["type"=>"text","text"=>$label,"size"=>"sm","color"=>"#6B7280","flex"=>3,"weight"=>"regular"],
        ["type"=>"text","text"=>$v,"size"=>$opts['value_size']??"sm","color"=>$opts['value_color']??"#111827",
         "weight"=>$opts['value_weight']??"regular","flex"=>5,"wrap"=>true,"align"=>"end"],
      ]
    ];
  }
}

/* -------------------- Flex section helper -------------------- */
if (!function_exists('dr_section')) {
  function dr_section(string $title, array $rows, array $opts = []): array {
    $bg   = $opts['bg']     ?? '#FFFFFF';
    $bd   = $opts['bd']     ?? '#E5E7EB';
    $icon = $opts['icon']   ?? '';
    $acc  = $opts['accent'] ?? '#2563EB';
    $titleBox = [
      "type"=>"box","layout"=>"baseline","spacing"=>"sm",
      "contents"=>[
        ["type"=>"text","text"=>($icon?"$icon  ":'').$title,
         "size"=>"xs","color"=>$acc,"weight"=>"bold","flex"=>1],
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
if (!function_exists('buildDrugPayload')) {

/**
 * ประกอบ LINE Flex payload จาก row เดียวของ drug_queue
 * @param  array $row  — แถวจาก drug_queue
 * @return array       — payload พร้อมส่ง MOPH Alert (มี messages[])
 */
function buildDrugPayload(array $row): array {
  $row = row_to_utf8($row);

  /* ---------- Normalize ---------- */
  $hn        = $row['hn']         ?? '-';
  $fullname  = $row['fullname']   ?? '-';
  $age       = $row['age']        ?? '';
  $sex       = $row['sex']        ?? '';
  $address   = $row['address']    ?? '-';
  $tel       = $row['hometel']    ?? '-';
  $drugCode  = $row['drug_code']  ?? '-';
  $drugName  = $row['drug_name']  ?? '-';
  $vstdate   = dr_thai_date($row['vstdate'] ?? null);
  $dept      = $row['department'] ?? '-';
  $station   = $row['mainstation']?? '-';
  $refId     = $row['visit_vn']   ?? ($row['id'] ?? '');

  $ageSex = match(true) {
    $age !== '' && $sex !== '' => "{$age} ปี · {$sex}",
    $age !== ''                => "{$age} ปี",
    $sex !== ''                => (string)$sex,
    default                    => '-',
  };

  /* ---------- HEADER BANNER ---------- */
  $header = [
    "type"=>"box","layout"=>"vertical","paddingAll"=>"0px",
    "contents"=> DRUG_HEADER_URL ? [[
      "type"=>"image","url"=>DRUG_HEADER_URL,
      "size"=>"full","aspectRatio"=>"3120:885","aspectMode"=>"cover",
    ]] : [],
  ];

  /* ---------- TITLE STRIP (red-orange gradient = danger) ---------- */
  $titleStrip = [
    "type"=>"box","layout"=>"vertical",
    "paddingAll"=>"16px","backgroundColor"=>"#7C0A02","cornerRadius"=>"0px",
    "contents"=>[
      ["type"=>"box","layout"=>"horizontal","contents"=>[
        ["type"=>"text","text"=>"💊  แจ้งเตือนยาอันตราย",
         "size"=>"xs","color"=>"#FDE68A","weight"=>"bold","flex"=>1],
        ["type"=>"text","text"=>"High-Alert Drug",
         "size"=>"xs","color"=>"#FECACA","align"=>"end","flex"=>0],
      ]],
      ["type"=>"text","text"=>DRUG_TITLE,
       "size"=>"xl","color"=>"#FFFFFF","weight"=>"bold","wrap"=>true,"margin"=>"sm"],
      ["type"=>"text","text"=>DRUG_SUBTITLE,
       "size"=>"xs","color"=>"#FCA5A5","wrap"=>true,"margin"=>"xs"],
    ],
  ];

  /* ---------- PRIORITY BADGE ---------- */
  $priority = [
    "type"=>"box","layout"=>"baseline","spacing"=>"sm",
    "paddingAll"=>"10px","backgroundColor"=>"#FEE2E2","cornerRadius"=>"8px","margin"=>"md",
    "contents"=>[
      ["type"=>"text","text"=>"⚠","size"=>"md","flex"=>0,"color"=>"#B91C1C"],
      ["type"=>"text","text"=>"ผู้ป่วยได้รับยาในกลุ่มยาอันตราย (High-Alert Medication) ที่ต้องติดตามอย่างใกล้ชิด",
       "size"=>"xs","color"=>"#991B1B","weight"=>"bold","wrap"=>true,"flex"=>1],
    ],
  ];

  /* ---------- SECTION: ข้อมูลผู้ป่วย ---------- */
  $sPatient = dr_section('ข้อมูลผู้ป่วย', [
    dr_info_row('HN', $hn, ['value_weight'=>'bold','value_color'=>'#111827','value_size'=>'md']),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    dr_info_row('ชื่อ-สกุล', $fullname, ['value_weight'=>'bold']),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    dr_info_row('อายุ / เพศ', $ageSex),
  ], ['icon'=>'🧑‍⚕️','accent'=>'#7C0A02']);

  /* ---------- SECTION: ยาที่ได้รับ (amber) ---------- */
  $drugCodeBox = [
    "type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"sm",
    "contents"=>[
      ["type"=>"text","text"=>"รหัสยา","size"=>"xs","color"=>"#92400E","weight"=>"bold","flex"=>0],
      ["type"=>"text","text"=>$drugCode,"size"=>"lg","color"=>"#78350F",
       "weight"=>"bold","flex"=>1,"align"=>"end"],
    ]
  ];
  $sDrug = dr_section('รายการยาที่ได้รับ', [
    $drugCodeBox,
    ["type"=>"separator","margin"=>"sm","color"=>"#FDE68A"],
    dr_info_row('ชื่อยา', $drugName, ['value_weight'=>'bold','value_color'=>'#78350F']),
    dr_info_row('วันที่รับบริการ', $vstdate),
    dr_info_row('แผนก / สถานะ', $dept),
    dr_info_row('สถานบริการหลัก', $station),
  ], ['icon'=>'💊','accent'=>'#92400E','bg'=>'#FFFBEB','bd'=>'#FDE68A']);

  /* ---------- SECTION: ติดต่อ / เยี่ยมบ้าน (green) ---------- */
  $sContact = dr_section('ข้อมูลสำหรับติดตามเยี่ยมบ้าน', [
    [
      "type"=>"box","layout"=>"vertical","spacing"=>"xs","margin"=>"sm",
      "contents"=>[
        ["type"=>"text","text"=>"📍 ที่อยู่","size"=>"xs","color"=>"#6B7280","weight"=>"bold"],
        ["type"=>"text","text"=>$address,"size"=>"sm","color"=>"#111827","wrap"=>true],
      ]
    ],
    [
      "type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"md",
      "contents"=>[
        ["type"=>"text","text"=>"📞 เบอร์โทร","size"=>"xs","color"=>"#6B7280","weight"=>"bold","flex"=>3],
        ["type"=>"text","text"=>($tel?:'-'),"size"=>"md","color"=>"#065F46",
         "weight"=>"bold","flex"=>5,"align"=>"end","wrap"=>true],
      ]
    ],
  ], ['icon'=>'🏠','accent'=>'#065F46','bg'=>'#ECFDF5','bd'=>'#A7F3D0']);

  /* ---------- SECTION: คำแนะนำ (blue) ---------- */
  $sAction = dr_section('คำแนะนำสำหรับเจ้าหน้าที่', [
    ["type"=>"text","text"=>"โปรดติดตามผู้ป่วยภายใน 7 วัน",
     "size"=>"sm","color"=>"#1E3A8A","weight"=>"bold","margin"=>"sm"],
    ["type"=>"text","text"=>"• ตรวจสอบประวัติการใช้ยาและผลข้างเคียง",
     "size"=>"xs","color"=>"#1F2937","wrap"=>true,"margin"=>"sm"],
    ["type"=>"text","text"=>"• ประเมินความสามารถในการใช้ยาด้วยตนเองของผู้ป่วย",
     "size"=>"xs","color"=>"#1F2937","wrap"=>true,"margin"=>"xs"],
    ["type"=>"text","text"=>"• ให้ความรู้เกี่ยวกับยาและการสังเกตอาการผิดปกติ",
     "size"=>"xs","color"=>"#1F2937","wrap"=>true,"margin"=>"xs"],
    ["type"=>"text","text"=>"• บันทึกผลการเยี่ยมในระบบ HDC / JHCIS",
     "size"=>"xs","color"=>"#1F2937","wrap"=>true,"margin"=>"xs"],
  ], ['icon'=>'📋','accent'=>'#1E3A8A','bg'=>'#EFF6FF','bd'=>'#BFDBFE']);

  /* ---------- BODY ---------- */
  $body = [
    "type"=>"box","layout"=>"vertical","spacing"=>"none","paddingAll"=>"0px",
    "contents"=>[
      $titleStrip,
      [
        "type"=>"box","layout"=>"vertical","paddingAll"=>"14px","spacing"=>"none",
        "backgroundColor"=>"#F9FAFB",
        "contents"=>[$priority, $sPatient, $sDrug, $sContact, $sAction],
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
        ["type"=>"text","text"=>DRUG_SYSTEM_NAME,"size"=>"xxs","color"=>"#6B7280","flex"=>3,"wrap"=>true],
        ["type"=>"text","text"=>date('j M Y H:i'),"size"=>"xxs","color"=>"#6B7280","align"=>"end","flex"=>2],
      ]],
      $refId ? ["type"=>"text","text"=>"Ref: ".(string)$refId,"size"=>"xxs","color"=>"#9CA3AF","margin"=>"xs"]
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
  $altText = sprintf('[แจ้งเตือน] ยาอันตราย HN %s %s (%s)', $hn, $fullname, $drugName);
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
