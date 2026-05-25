<?php
/**
 * flex_disease.php
 * ไลบรารีกลาง: ประกอบ LINE Flex message สำหรับโรคติดต่อ
 * รองรับ: dengue (ไข้เลือดออก), lepto (เลปโตสไปโรสิส), scrub (สครับไทฟัส)
 *
 * Usage: buildDiseasePayload(array $row, string $type): array
 *   $type = 'dengue' | 'lepto' | 'scrub'
 */

if (!defined('DISEASE_SYSTEM_NAME'))
  define('DISEASE_SYSTEM_NAME', 'ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยง • รพ.เชียงกลาง');
if (!defined('DISEASE_HEADER_URL'))
  define('DISEASE_HEADER_URL', 'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');

/* ─── Encoding helpers (guarded) ─────────────────────────────────────────── */
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

/* ─── Thai date helper ───────────────────────────────────────────────────── */
if (!function_exists('dis_thai_date')) {
  function dis_thai_date(?string $ymd): string {
    if (!$ymd) return '-';
    $ts = strtotime($ymd);
    if ($ts === false) return $ymd;
    static $m = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',
                 7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    return sprintf('%d %s %d', (int)date('j',$ts), $m[(int)date('n',$ts)]??'', (int)date('Y',$ts)+543);
  }
}

/* ─── Row / Section helpers ─────────────────────────────────────────────── */
if (!function_exists('dis_row')) {
  function dis_row(string $label, ?string $value, array $opts = []): array {
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
if (!function_exists('dis_section')) {
  function dis_section(string $title, array $rows, array $opts = []): array {
    $bg   = $opts['bg']     ?? '#FFFFFF';
    $bd   = $opts['bd']     ?? '#E5E7EB';
    $icon = $opts['icon']   ?? '';
    $acc  = $opts['accent'] ?? '#1d4ed8';
    return [
      "type"=>"box","layout"=>"vertical",
      "paddingAll"=>"14px","cornerRadius"=>"12px","margin"=>"md","spacing"=>"xs",
      "backgroundColor"=>$bg,"borderColor"=>$bd,"borderWidth"=>"1px",
      "contents"=>array_merge([[
        "type"=>"box","layout"=>"baseline","spacing"=>"sm",
        "contents"=>[
          ["type"=>"text","text"=>($icon?"$icon  ":'').$title,
           "size"=>"sm","color"=>$acc,"weight"=>"bold","flex"=>1],
        ]
      ]], $rows),
    ];
  }
}

/* ─── Disease config ────────────────────────────────────────────────────── */
function _disease_cfg(string $type): array {
  return match(true) {
    $type === 'dengue' => [
      'emoji'       => '🦟',
      'badge'       => 'Dengue Fever',
      'title'       => 'แจ้งเตือนผู้ป่วยไข้เลือดออก',
      'subtitle'    => 'Dengue Fever Alert · รพ.เชียงกลาง',
      'color'       => '#92400E',
      'color_bg'    => '#FEF3C7',
      'color_bd'    => '#FDE68A',
      'color_head'  => '#78350F',
      'priority'    => 'ผู้ป่วยได้รับการวินิจฉัยโรคไข้เลือดออก กรุณาติดตามและรายงานหน่วยงานที่เกี่ยวข้อง',
      'priority_bg' => '#FFFBEB',
      'priority_co' => '#92400E',
      'instructions'=> [
        'รายงาน สสจ. / สสอ. ภายใน 24 ชั่วโมง',
        'ประสานทีมควบคุมโรค (DC) เพื่อสอบสวนโรค',
        'ให้คำแนะนำการป้องกันยุงแก่ผู้ป่วยและครอบครัว',
        'บันทึกข้อมูลรายงาน 506 ใน HDC',
      ],
    ],
    $type === 'lepto' => [
      'emoji'       => '🐀',
      'badge'       => 'Leptospirosis',
      'title'       => 'แจ้งเตือนผู้ป่วยเลปโตสไปโรสิส',
      'subtitle'    => 'Leptospirosis Alert · รพ.เชียงกลาง',
      'color'       => '#0E7490',
      'color_bg'    => '#ECFEFF',
      'color_bd'    => '#A5F3FC',
      'color_head'  => '#164E63',
      'priority'    => 'ผู้ป่วยได้รับการวินิจฉัยโรคเลปโตสไปโรสิส กรุณาติดตามและรายงานหน่วยงานที่เกี่ยวข้อง',
      'priority_bg' => '#ECFEFF',
      'priority_co' => '#0E7490',
      'instructions'=> [
        'รายงาน สสจ. / สสอ. ภายใน 24 ชั่วโมง',
        'ประสานทีมควบคุมโรค (DC) เพื่อสอบสวนโรค',
        'แนะนำการหลีกเลี่ยงการสัมผัสน้ำขังหรือโคลนตม',
        'บันทึกข้อมูลรายงาน 506 ใน HDC',
      ],
    ],
    default /* scrub */ => [
      'emoji'       => '🦗',
      'badge'       => 'Scrub Typhus',
      'title'       => 'แจ้งเตือนผู้ป่วยสครับไทฟัส',
      'subtitle'    => 'Scrub Typhus Alert · รพ.เชียงกลาง',
      'color'       => '#065F46',
      'color_bg'    => '#ECFDF5',
      'color_bd'    => '#A7F3D0',
      'color_head'  => '#064E3B',
      'priority'    => 'ผู้ป่วยได้รับการวินิจฉัยโรคสครับไทฟัส กรุณาติดตามและรายงานหน่วยงานที่เกี่ยวข้อง',
      'priority_bg' => '#ECFDF5',
      'priority_co' => '#065F46',
      'instructions'=> [
        'รายงาน สสจ. / สสอ. ภายใน 24 ชั่วโมง',
        'ประสานทีมควบคุมโรค (DC) เพื่อสอบสวนโรค',
        'แนะนำการหลีกเลี่ยงพื้นที่รก ป่าทึบ',
        'บันทึกข้อมูลรายงาน 506 ใน HDC',
      ],
    ],
  };
}

/* ─── MAIN builder ───────────────────────────────────────────────────────── */
if (!function_exists('buildDiseasePayload')) {
function buildDiseasePayload(array $row, string $type = 'dengue'): array {
  $row = row_to_utf8($row);
  $cfg = _disease_cfg($type);

  $hn       = $row['hn']        ?? '-';
  $fullname = $row['fullname']  ?? '-';
  $age      = $row['age']       ?? '';
  $sex      = $row['sex']       ?? '';
  $cid      = $row['cid']       ?? '-';
  $address  = $row['address']   ?? ($row['informaddr'] ?? '-');
  $tel      = $row['hometel']   ?? '-';
  $vstdate  = dis_thai_date($row['vstdate'] ?? null);
  $doctor   = $row['doctor']    ?? '-';
  $disease  = $row['disease']   ?? '-';
  $icd10    = $row['icd10']     ?? '-';
  $result   = $row['result']    ?? '-';
  $vn       = $row['vn']        ?? '';

  $ageSex = match(true) {
    $age !== '' && $sex !== '' => "{$age} ปี · {$sex}",
    $age !== ''                => "{$age} ปี",
    $sex !== ''                => (string)$sex,
    default                    => '-',
  };

  /* HEADER */
  $header = [
    "type"=>"box","layout"=>"vertical","paddingAll"=>"0px",
    "contents"=> DISEASE_HEADER_URL ? [[
      "type"=>"image","url"=>DISEASE_HEADER_URL,
      "size"=>"full","aspectRatio"=>"3120:885","aspectMode"=>"cover",
    ]] : [],
  ];

  /* TITLE STRIP */
  $titleStrip = [
    "type"=>"box","layout"=>"vertical",
    "paddingAll"=>"16px","backgroundColor"=>$cfg['color_head'],"cornerRadius"=>"0px",
    "contents"=>[
      ["type"=>"box","layout"=>"horizontal","contents"=>[
        ["type"=>"text","text"=>$cfg['emoji'].'  '.$cfg['title'],
         "size"=>"sm","color"=>"#FFFFFF","weight"=>"bold","flex"=>1],
        ["type"=>"text","text"=>$cfg['badge'],
         "size"=>"sm","color"=>"rgba(255,255,255,.7)","align"=>"end","flex"=>0],
      ]],
      ["type"=>"text","text"=>$cfg['title'],
       "size"=>"xxl","color"=>"#FFFFFF","weight"=>"bold","wrap"=>true,"margin"=>"sm"],
      ["type"=>"text","text"=>$cfg['subtitle'],
       "size"=>"sm","color"=>"rgba(255,255,255,.75)","wrap"=>true,"margin"=>"xs"],
    ],
  ];

  /* PRIORITY BADGE */
  $priority = [
    "type"=>"box","layout"=>"baseline","spacing"=>"sm",
    "paddingAll"=>"10px","backgroundColor"=>$cfg['priority_bg'],"cornerRadius"=>"8px","margin"=>"md",
    "contents"=>[
      ["type"=>"text","text"=>"⚠","size"=>"lg","flex"=>0,"color"=>$cfg['priority_co']],
      ["type"=>"text","text"=>$cfg['priority'],
       "size"=>"sm","color"=>$cfg['color_head'],"weight"=>"bold","wrap"=>true,"flex"=>1],
    ],
  ];

  /* SECTION 1: ข้อมูลผู้ป่วย */
  $sPatient = dis_section('ข้อมูลผู้ป่วย', [
    dis_row('HN', $hn, ['value_weight'=>'bold','value_size'=>'lg']),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    dis_row('ชื่อ-สกุล', $fullname, ['value_weight'=>'bold']),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    dis_row('อายุ / เพศ', $ageSex),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    dis_row('เลขบัตรประชาชน', $cid),
  ], ['icon'=>'🧑‍⚕️','accent'=>$cfg['color_head']]);

  /* SECTION 2: ผลตรวจ */
  $diagBox = [
    "type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"sm",
    "contents"=>[
      ["type"=>"text","text"=>"ICD-10","size"=>"sm","color"=>$cfg['color'],"weight"=>"bold","flex"=>0],
      ["type"=>"text","text"=>$icd10,"size"=>"xl","color"=>$cfg['color_head'],
       "weight"=>"bold","flex"=>1,"align"=>"end"],
    ]
  ];
  $sDiag = dis_section('ผลการวินิจฉัยและตรวจ', [
    $diagBox,
    ["type"=>"separator","margin"=>"sm","color"=>$cfg['color_bd']],
    dis_row('ชื่อโรค', $disease, ['value_weight'=>'bold','value_color'=>$cfg['color_head'],'value_size'=>'md']),
    dis_row('ผล LAB', $result, ['value_weight'=>'bold','value_color'=>$cfg['color']]),
    dis_row('วันที่รับบริการ', $vstdate),
    dis_row('แพทย์ผู้ตรวจ', $doctor),
  ], ['icon'=>'🔬','accent'=>$cfg['color'],'bg'=>$cfg['color_bg'],'bd'=>$cfg['color_bd']]);

  /* SECTION 3: ติดต่อ */
  $sContact = dis_section('ข้อมูลสำหรับติดตาม', [
    ["type"=>"box","layout"=>"vertical","spacing"=>"xs","margin"=>"sm","contents"=>[
      ["type"=>"text","text"=>"📍 ที่อยู่","size"=>"sm","color"=>"#6B7280","weight"=>"bold"],
      ["type"=>"text","text"=>$address,"size"=>"md","color"=>"#111827","wrap"=>true],
    ]],
    ["type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"md","contents"=>[
      ["type"=>"text","text"=>"📞 เบอร์โทร","size"=>"sm","color"=>"#6B7280","weight"=>"bold","flex"=>3],
      ["type"=>"text","text"=>($tel?:'-'),"size"=>"lg","color"=>"#065F46",
       "weight"=>"bold","flex"=>5,"align"=>"end","wrap"=>true],
    ]],
  ], ['icon'=>'🏠','accent'=>'#065F46','bg'=>'#ECFDF5','bd'=>'#A7F3D0']);

  /* SECTION 4: คำแนะนำ */
  $items = [
    ["type"=>"text","text"=>"โปรดดำเนินการภายใน 24 ชั่วโมง",
     "size"=>"md","color"=>"#1E3A8A","weight"=>"bold","margin"=>"sm"],
  ];
  foreach ($cfg['instructions'] as $ins) {
    $items[] = ["type"=>"text","text"=>"• $ins",
                "size"=>"sm","color"=>"#1F2937","wrap"=>true,"margin"=>"xs"];
  }
  $sAction = dis_section('คำแนะนำสำหรับเจ้าหน้าที่', $items,
    ['icon'=>'📋','accent'=>'#1E3A8A','bg'=>'#EFF6FF','bd'=>'#BFDBFE']);

  /* BODY */
  $body = [
    "type"=>"box","layout"=>"vertical","spacing"=>"none","paddingAll"=>"0px",
    "contents"=>[
      $titleStrip,
      ["type"=>"box","layout"=>"vertical","paddingAll"=>"14px","spacing"=>"none",
       "backgroundColor"=>"#F9FAFB",
       "contents"=>[$priority, $sPatient, $sDiag, $sContact, $sAction]],
    ],
  ];

  /* FOOTER */
  $footer = [
    "type"=>"box","layout"=>"vertical",
    "paddingStart"=>"14px","paddingEnd"=>"14px","paddingTop"=>"10px","paddingBottom"=>"12px",
    "backgroundColor"=>"#F3F4F6",
    "contents"=>[
      ["type"=>"separator","color"=>"#E5E7EB"],
      ["type"=>"box","layout"=>"horizontal","margin"=>"md","contents"=>[
        ["type"=>"text","text"=>DISEASE_SYSTEM_NAME,"size"=>"xs","color"=>"#6B7280","flex"=>3,"wrap"=>true],
        ["type"=>"text","text"=>date('j M Y H:i'),"size"=>"xs","color"=>"#6B7280","align"=>"end","flex"=>2],
      ]],
      $vn ? ["type"=>"text","text"=>"Ref VN: $vn","size"=>"xs","color"=>"#9CA3AF","margin"=>"xs"]
           : ["type"=>"filler"],
    ],
  ];

  /* BUBBLE */
  $bubble = [
    "type"=>"bubble","size"=>"giga",
    "header"=>$header,"body"=>$body,"footer"=>$footer,
    "styles"=>[
      "header"=>["backgroundColor"=>"#FFFFFF"],
      "body"=>  ["backgroundColor"=>"#F9FAFB"],
      "footer"=>["backgroundColor"=>"#F3F4F6"],
    ],
  ];

  $altText = sprintf('[แจ้งเตือน] %s HN %s %s (%s)', $cfg['badge'], $hn, $fullname, $icd10);
  if (mb_strlen($altText) > 400) $altText = mb_substr($altText, 0, 397).'...';

  return ["messages"=>[["type"=>"flex","altText"=>$altText,"contents"=>$bubble]]];
}
} // end function_exists guard

/* ─── extract_moph_message_id (guarded) ─────────────────────────────────── */
if (!function_exists('extract_moph_message_id')) {
  function extract_moph_message_id($json) {
    if (!is_array($json)) return null;
    $paths = [['messageId'],['data','messageId'],['result','messageId'],
              ['messages',0,'messageId'],['messages',0,'id']];
    foreach ($paths as $path) {
      $t = $json;
      foreach ($path as $k) {
        if (is_array($t) && array_key_exists($k,$t)) $t = $t[$k]; else { $t=null; break; }
      }
      if (is_scalar($t) && $t !== '') return (string)$t;
    }
    return null;
  }
}
