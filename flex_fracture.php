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
 * v2 — redesigned: section cards พร้อม accent bars, ICD badge ขนาดใหญ่,
 *      risk ribbon, checklist actions, dark footer
 *
 * @param  array $row  แถวจาก fracture_queue
 * @return array       payload พร้อมส่ง MOPH Alert (มี messages[])
 */
function buildFracturePayload(array $row): array {
  $row = row_to_utf8($row);

  /* ── Normalize ─────────────────────────────────────────────────────── */
  $hn       = (string)($row['hn']         ?? '-');
  $fullname = (string)($row['fullname']    ?? '-');
  $age      = (string)($row['age']         ?? '');
  $sex      = (string)($row['sex']         ?? '');
  $address  = (string)($row['address']     ?? '-');
  $tel      = (string)($row['hometel']     ?? '');
  $pdxCode  = (string)($row['pdx_code']    ?? '-');
  $pdxName  = (string)($row['pdx_name']    ?? '');
  $vstdate  = fr_thai_date($row['vstdate'] ?? null);
  $station  = (string)($row['mainstation'] ?? '-');
  $refId    = (string)($row['visit_vn']    ?? ($row['id'] ?? ''));

  $ageSex = match(true) {
    $age !== '' && $sex !== '' => "{$age} ปี · {$sex}",
    $age !== ''                => "{$age} ปี",
    $sex !== ''                => $sex,
    default                    => '-',
  };
  $telDisplay = ($tel !== '' && $tel !== '-') ? $tel : 'ไม่พบเบอร์';
  $telColor   = ($tel !== '' && $tel !== '-') ? '#059669' : '#9CA3AF';

  /* ── Local helpers ──────────────────────────────────────────────────── */

  /** สร้าง section card พร้อม accent bar ซ้าย + divider หลัง header */
  $mkSec = function(string $icon, string $title, array $rows, array $o = []) {
    $bg     = $o['bg']     ?? '#FFFFFF';
    $bd     = $o['bd']     ?? '#E2E8F0';
    $accent = $o['accent'] ?? '#1E3A8A';
    $sep    = $o['sep']    ?? '#E2E8F0';

    /* accent bar + icon + title */
    $hdr = [
      'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm',
      'contents' => [
        [ 'type' => 'text', 'text' => '▌', 'size' => 'lg',
          'color' => $accent, 'flex' => 0, 'weight' => 'bold' ],
        [ 'type' => 'text', 'text' => $icon . '  ' . $title,
          'size' => 'sm', 'color' => $accent, 'weight' => 'bold',
          'flex' => 1, 'gravity' => 'center' ],
      ],
    ];
    $divider = [ 'type' => 'separator', 'margin' => 'sm', 'color' => $sep ];

    return [
      'type'            => 'box',
      'layout'          => 'vertical',
      'paddingAll'      => '14px',
      'cornerRadius'    => '12px',
      'backgroundColor' => $bg,
      'borderColor'     => $bd,
      'borderWidth'     => '1px',
      'margin'          => 'md',
      'spacing'         => 'xs',
      'contents'        => array_values(array_filter(
        array_merge([$hdr, $divider], $rows),
        fn($x) => $x !== null
      )),
    ];
  };

  /** แถว label (xs, slate) + value สองคอลัมน์ */
  $kv = fn(string $lbl, string $val, array $o = []) => [
    'type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'margin' => 'sm',
    'contents' => [
      [ 'type' => 'text', 'text' => $lbl,
        'size' => 'xs', 'color' => '#64748B', 'flex' => 3 ],
      [ 'type' => 'text', 'text' => ($val === '' ? '-' : $val),
        'size'   => $o['size']   ?? 'sm',
        'color'  => $o['color']  ?? '#1E293B',
        'weight' => $o['weight'] ?? 'regular',
        'flex' => 5, 'align' => 'end', 'wrap' => true ],
    ],
  ];

  /** separator ชนิดบาง */
  $sep = fn(string $c = '#F1F5F9') => [ 'type' => 'separator', 'margin' => 'sm', 'color' => $c ];

  /* ── HEADER IMAGE ───────────────────────────────────────────────────── */
  $header = FALL_HEADER_URL ? [
    'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '0px',
    'contents' => [[
      'type' => 'image', 'url' => FALL_HEADER_URL,
      'size' => 'full', 'aspectRatio' => '3120:885', 'aspectMode' => 'cover',
    ]],
  ] : null;

  /* ── TITLE STRIP (dark navy) ────────────────────────────────────────── */
  $titleStrip = [
    'type'            => 'box',
    'layout'          => 'vertical',
    'paddingStart'    => '16px',
    'paddingEnd'      => '16px',
    'paddingTop'      => '16px',
    'paddingBottom'   => '18px',
    'backgroundColor' => '#0F172A',
    'contents' => [
      /* badge row */
      [ 'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm', 'margin' => 'none',
        'contents' => [
          [ 'type' => 'box', 'layout' => 'vertical', 'flex' => 0,
            'backgroundColor' => '#DC2626', 'cornerRadius' => '6px',
            'paddingStart' => '8px', 'paddingEnd' => '8px',
            'paddingTop' => '4px', 'paddingBottom' => '4px',
            'contents' => [
              [ 'type' => 'text', 'text' => '🚨  แจ้งเตือน',
                'size' => 'xxs', 'color' => '#FFFFFF', 'weight' => 'bold' ],
            ]
          ],
          [ 'type' => 'text', 'text' => 'Fall Risk Alert',
            'size' => 'xs', 'color' => '#93C5FD',
            'flex' => 1, 'align' => 'end', 'gravity' => 'center' ],
        ]
      ],
      /* main title */
      [ 'type' => 'text', 'text' => FALL_TITLE,
        'size' => 'xl', 'color' => '#FFFFFF', 'weight' => 'bold',
        'wrap' => true, 'margin' => 'sm' ],
      [ 'type' => 'text', 'text' => FALL_SUBTITLE,
        'size' => 'xxs', 'color' => '#94A3B8', 'wrap' => true, 'margin' => 'xs' ],
    ],
  ];

  /* ── RISK RIBBON ────────────────────────────────────────────────────── */
  $riskRibbon = [
    'type'            => 'box',
    'layout'          => 'horizontal',
    'spacing'         => 'md',
    'paddingStart'    => '12px',
    'paddingEnd'      => '12px',
    'paddingTop'      => '10px',
    'paddingBottom'   => '10px',
    'backgroundColor' => '#FFF1F2',
    'borderColor'     => '#FECACA',
    'borderWidth'     => '1px',
    'cornerRadius'    => '10px',
    'margin'          => 'none',
    'contents' => [
      [ 'type' => 'text', 'text' => '⚠️',
        'size' => 'xxl', 'flex' => 0, 'gravity' => 'center' ],
      [ 'type' => 'box', 'layout' => 'vertical', 'flex' => 1, 'spacing' => 'xs',
        'contents' => [
          [ 'type' => 'text', 'text' => 'ความเสี่ยงสูง · ต้องติดตามด่วน',
            'size' => 'sm', 'color' => '#B91C1C', 'weight' => 'bold' ],
          [ 'type' => 'text', 'text' => 'ผู้สูงอายุ ≥ 60 ปี · กลุ่มพลัดตก / กระดูกหัก',
            'size' => 'xs', 'color' => '#9F1239', 'wrap' => true ],
        ]
      ],
      /* HIGH pill */
      [ 'type' => 'box', 'layout' => 'vertical',
        'flex' => 0, 'justifyContent' => 'center',
        'contents' => [[
          'type'            => 'box',
          'layout'          => 'vertical',
          'backgroundColor' => '#DC2626',
          'cornerRadius'    => '999px',
          'paddingStart'    => '10px',
          'paddingEnd'      => '10px',
          'paddingTop'      => '5px',
          'paddingBottom'   => '5px',
          'contents' => [[
            'type' => 'text', 'text' => 'HIGH',
            'size' => 'xxs', 'color' => '#FFFFFF',
            'weight' => 'bold', 'align' => 'center',
          ]],
        ]],
      ],
    ],
  ];

  /* ── SECTION 1: ข้อมูลผู้ป่วย ──────────────────────────────────────── */
  $secPatient = $mkSec('🧑‍⚕️', 'ข้อมูลผู้ป่วย', [
    /* HN — ใหญ่ */
    [ 'type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'margin' => 'sm',
      'contents' => [
        [ 'type' => 'text', 'text' => 'HN',
          'size' => 'xs', 'color' => '#64748B', 'flex' => 3 ],
        [ 'type' => 'text', 'text' => $hn,
          'size' => 'xl', 'color' => '#1E3A8A', 'weight' => 'bold',
          'flex' => 5, 'align' => 'end' ],
      ]
    ],
    $sep('#F1F5F9'),
    $kv('ชื่อ-สกุล', $fullname, ['weight' => 'bold', 'color' => '#1E293B']),
    $sep('#F1F5F9'),
    $kv('อายุ / เพศ', $ageSex),
  ], ['bg' => '#FFFFFF', 'bd' => '#E2E8F0', 'accent' => '#1E3A8A', 'sep' => '#CBD5E1']);

  /* ── SECTION 2: การวินิจฉัย (amber) ────────────────────────────────── */

  /* ICD badge — pill ตรงกลาง */
  $icdBadge = [
    'type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
    'contents' => [
      [ 'type' => 'filler' ],
      [ 'type' => 'box', 'layout' => 'vertical', 'flex' => 0,
        'backgroundColor' => '#FEF3C7', 'cornerRadius' => '14px',
        'paddingTop' => '12px', 'paddingBottom' => '12px',
        'paddingStart' => '28px', 'paddingEnd' => '28px',
        'contents' => [
          [ 'type' => 'text', 'text' => $pdxCode,
            'size' => 'xxl', 'color' => '#78350F',
            'weight' => 'bold', 'align' => 'center' ],
          [ 'type' => 'separator', 'margin' => 'sm', 'color' => '#FDE68A' ],
          [ 'type' => 'text', 'text' => 'รหัส ICD-10',
            'size' => 'xxs', 'color' => '#92400E',
            'align' => 'center', 'margin' => 'xs' ],
        ],
      ],
      [ 'type' => 'filler' ],
    ],
  ];

  $dxNameEl = ($pdxName !== '') ? [
    'type' => 'text', 'text' => '📌  ' . $pdxName,
    'size' => 'sm', 'color' => '#1F2937',
    'wrap' => true, 'margin' => 'sm', 'align' => 'center',
  ] : null;

  $secDx = $mkSec('🩺', 'การวินิจฉัยและการรับบริการ',
    array_values(array_filter([
      $icdBadge,
      $dxNameEl,
      $sep('#FDE68A'),
      $kv('วันที่รับบริการ', $vstdate, ['color' => '#78350F', 'weight' => 'bold']),
      $sep('#F3F4F6'),
      $kv('สถานบริการหลัก', $station),
    ])),
    ['bg' => '#FFFBEB', 'bd' => '#FDE68A', 'accent' => '#B45309', 'sep' => '#FEF3C7']
  );

  /* ── SECTION 3: ข้อมูลติดตามเยี่ยมบ้าน (green) ─────────────────────── */
  $secContact = $mkSec('🏠', 'ข้อมูลสำหรับติดตามเยี่ยมบ้าน', [
    /* address block */
    [ 'type' => 'box', 'layout' => 'vertical', 'spacing' => 'xs', 'margin' => 'sm',
      'contents' => [
        [ 'type' => 'text', 'text' => '📍  ที่อยู่',
          'size' => 'xs', 'color' => '#15803D', 'weight' => 'bold' ],
        [ 'type' => 'text', 'text' => $address,
          'size' => 'sm', 'color' => '#1F2937', 'wrap' => true ],
      ]
    ],
    $sep('#A7F3D0'),
    /* phone — ขนาดใหญ่ */
    [ 'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm', 'margin' => 'sm',
      'contents' => [
        [ 'type' => 'text', 'text' => '📞  เบอร์โทร',
          'size' => 'xs', 'color' => '#15803D', 'weight' => 'bold',
          'flex' => 3, 'gravity' => 'center' ],
        [ 'type' => 'text', 'text' => $telDisplay,
          'size' => 'xl', 'color' => $telColor,
          'weight' => 'bold', 'flex' => 5, 'align' => 'end', 'wrap' => true ],
      ]
    ],
  ], ['bg' => '#F0FDF4', 'bd' => '#86EFAC', 'accent' => '#15803D', 'sep' => '#D1FAE5']);

  /* ── SECTION 4: แนวทางการดำเนินการ (blue) ──────────────────────────── */
  $urgentBox = [
    'type'            => 'box',
    'layout'          => 'vertical',
    'margin'          => 'sm',
    'backgroundColor' => '#FEE2E2',
    'cornerRadius'    => '8px',
    'paddingAll'      => '8px',
    'contents' => [[
      'type'   => 'text',
      'text'   => '⏰  โปรดดำเนินการภายใน 7 วัน',
      'size'   => 'sm',
      'color'  => '#B91C1C',
      'weight' => 'bold',
      'align'  => 'center',
    ]],
  ];

  $checkItems = [
    'ติดตามเยี่ยมบ้าน ประเมินการฟื้นตัวของผู้ป่วย',
    'ประเมินปัจจัยเสี่ยงในบ้าน (พื้นลื่น แสงสว่าง ราวจับ)',
    'ให้ความรู้การป้องกันการพลัดตกซ้ำ',
    'บันทึกผลการเยี่ยมใน HDC / JHCIS',
  ];
  $actionRows = [$urgentBox];
  foreach ($checkItems as $i => $txt) {
    $actionRows[] = [
      'type' => 'box', 'layout' => 'horizontal',
      'spacing' => 'sm', 'margin' => 'sm',
      'contents' => [
        [ 'type' => 'text', 'text' => '☑',
          'size' => 'sm', 'color' => '#2563EB', 'flex' => 0 ],
        [ 'type' => 'text', 'text' => $txt,
          'size' => 'xs', 'color' => '#1E293B',
          'wrap' => true, 'flex' => 1 ],
      ],
    ];
    if ($i < count($checkItems) - 1) {
      $actionRows[] = $sep('#DBEAFE');
    }
  }
  $secAction = $mkSec('📋', 'แนวทางการดำเนินการ', $actionRows,
    ['bg' => '#EFF6FF', 'bd' => '#BFDBFE', 'accent' => '#1D4ED8', 'sep' => '#DBEAFE']
  );

  /* ── BODY ───────────────────────────────────────────────────────────── */
  $body = [
    'type'       => 'box',
    'layout'     => 'vertical',
    'spacing'    => 'none',
    'paddingAll' => '0px',
    'contents'   => [
      $titleStrip,
      [
        'type'            => 'box',
        'layout'          => 'vertical',
        'paddingAll'      => '14px',
        'spacing'         => 'none',
        'backgroundColor' => '#F1F5F9',
        'contents'        => [
          $riskRibbon,
          $secPatient,
          $secDx,
          $secContact,
          $secAction,
        ],
      ],
    ],
  ];

  /* ── FOOTER (dark slate) ────────────────────────────────────────────── */
  $footer = [
    'type'            => 'box',
    'layout'          => 'vertical',
    'paddingStart'    => '14px',
    'paddingEnd'      => '14px',
    'paddingTop'      => '10px',
    'paddingBottom'   => '12px',
    'backgroundColor' => '#1E293B',
    'contents'        => array_values(array_filter([
      [ 'type' => 'box', 'layout' => 'horizontal',
        'contents' => [
          [ 'type' => 'text', 'text' => FALL_SYSTEM_NAME,
            'size' => 'xxs', 'color' => '#94A3B8', 'flex' => 3, 'wrap' => true ],
          [ 'type' => 'text', 'text' => date('j M Y H:i'),
            'size' => 'xxs', 'color' => '#64748B',
            'align' => 'end', 'flex' => 2 ],
        ]
      ],
      $refId !== '' ? [
        'type'   => 'text',
        'text'   => 'Ref: ' . $refId,
        'size'   => 'xxs',
        'color'  => '#475569',
        'margin' => 'xs',
      ] : null,
    ])),
  ];

  /* ── BUBBLE ─────────────────────────────────────────────────────────── */
  $bubble = array_filter([
    'type'   => 'bubble',
    'size'   => 'giga',
    'header' => $header,
    'body'   => $body,
    'footer' => $footer,
    'styles' => [
      'header' => [ 'backgroundColor' => '#FFFFFF' ],
      'body'   => [ 'backgroundColor' => '#F1F5F9' ],
      'footer' => [ 'backgroundColor' => '#1E293B' ],
    ],
  ]);

  /* ── altText ────────────────────────────────────────────────────────── */
  $altText = sprintf('[แจ้งเตือน] ผู้ป่วยกลุ่มเสี่ยงหกล้ม HN %s %s (ICD %s)',
    $hn, $fullname, $pdxCode);
  if (mb_strlen($altText) > 400) $altText = mb_substr($altText, 0, 397) . '...';

  return [
    'messages' => [[
      'type'     => 'flex',
      'altText'  => $altText,
      'contents' => $bubble,
    ]],
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
