<?php
/**
 * flex_accident.php
 * ไลบรารีกลาง: ประกอบ Flex Message สำหรับแจ้งเตือนผู้ป่วยอุบัติเหตุ
 * (สิทธิ์ พ.ร.บ./ประกันสังคมต่างจังหวัด)
 *
 * ถูกใช้ร่วมกันโดย:
 *   - accident_worker.php       (ingest + send cron)
 *   - accident_queue_action.php (bulk send_now จาก UI)
 *
 * รูปแบบ: section-card พร้อม ▌ accent bar, ICD/สิทธิ์ pill badge,
 *         alert ribbon URGENT, checklist actions, dark footer
 */

/* ==================== Constants ==================== */
if (!defined('ACC_TITLE'))        define('ACC_TITLE',       'ผู้ป่วยอุบัติเหตุ (สิทธิ์ พ.ร.บ./ประกันสังคมต่างจังหวัด)');
if (!defined('ACC_SUBTITLE'))     define('ACC_SUBTITLE',    'Accident Alert สำหรับเจ้าหน้าที่ รพ.สต. เครือข่าย');
if (!defined('ACC_SYSTEM_NAME'))  define('ACC_SYSTEM_NAME', 'ระบบแจ้งเตือนอุบัติเหตุ • รพ.เชียงกลาง');
if (!defined('FALL_HEADER_URL'))  define('FALL_HEADER_URL', 'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
if (!defined('FALL_ICON_URL'))    define('FALL_ICON_URL',   'https://www.ckhospital.net/home/PDF/Logo_ck.png');

/* ==================== Encoding helpers ==================== */
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
  function row_to_utf8(array $r): array {
    foreach ($r as $k => $v) { if (is_string($v)) $r[$k] = to_utf8($v); }
    return $r;
  }
}

/* ==================== Thai date helper ==================== */
if (!function_exists('acc_thai_date')) {
  /**
   * แปลง YYYY-MM-DD (ค.ศ.) → "23 เม.ย. 2569" (พ.ศ., เดือนย่อ)
   * ถ้าพาร์สไม่สำเร็จ คืนค่าเดิม
   */
  function acc_thai_date(?string $ymd): string {
    if (!$ymd) return '-';
    $ts = strtotime($ymd);
    if ($ts === false) return $ymd;
    static $months = [
      1=>'ม.ค.',  2=>'ก.พ.',  3=>'มี.ค.', 4=>'เม.ย.', 5=>'พ.ค.',  6=>'มิ.ย.',
      7=>'ก.ค.',  8=>'ส.ค.',  9=>'ก.ย.',  10=>'ต.ค.', 11=>'พ.ย.', 12=>'ธ.ค.',
    ];
    return sprintf('%d %s %d',
      (int)date('j', $ts),
      $months[(int)date('n', $ts)] ?? '',
      (int)date('Y', $ts) + 543
    );
  }
}

/* ==================== Main Flex builder ==================== */
if (!function_exists('buildAccidentPayload')) {

/**
 * ประกอบ Flex payload จาก row เดียวของ accident_queue
 * v2 — Modern section-card design
 *
 * Fields used: an, hn, fullname, regdate, regtime, pttype, pttname
 *
 * @param  array $r   แถวจาก accident_queue (UTF-8 หรือ TIS-620)
 * @return array      payload พร้อมส่ง MOPH Alert (มี messages[])
 */
function buildAccidentPayload(array $r): array {
  $r = row_to_utf8($r);

  /* ── Normalize ── */
  $an       = (string)($r['an']       ?? '-');
  $hn       = (string)($r['hn']       ?? '-');
  $fullname = (string)($r['fullname'] ?? '-');
  $regdate  = acc_thai_date($r['regdate'] ?? null);
  $regtime  = (string)($r['regtime']  ?? '');
  $regtime  = ($regtime !== '') ? substr($regtime, 0, 5) : '-'; // HH:MM
  $pttype   = (string)($r['pttype']   ?? '');
  $pttname  = (string)($r['pttname']  ?? '');
  $refId    = $an;

  /* ── Local helpers ── */

  /** section card with ▌ accent bar + colored divider after header */
  $mkSec = function(string $icon, string $title, array $rows, array $o = []) {
    $bg     = $o['bg']     ?? '#FFFFFF';
    $bd     = $o['bd']     ?? '#E2E8F0';
    $accent = $o['accent'] ?? '#1E3A8A';
    $sep    = $o['sep']    ?? '#E2E8F0';

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
        array_merge([$hdr, ['type' => 'separator', 'margin' => 'sm', 'color' => $sep]], $rows),
        fn($x) => $x !== null
      )),
    ];
  };

  /** key-value row (label xs slate | value align-end) */
  $kv = fn(string $lbl, string $val, array $o = []) => [
    'type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'margin' => 'sm',
    'contents' => [
      [ 'type' => 'text', 'text' => $lbl,
        'size' => 'xs', 'color' => '#64748B', 'flex' => 3 ],
      [ 'type' => 'text', 'text' => ($val === '' ? '-' : $val),
        'size'   => $o['size']   ?? 'sm',
        'color'  => $o['color']  ?? '#1E293B',
        'weight' => $o['weight'] ?? 'regular',
        'flex'   => 5, 'align' => 'end', 'wrap' => true ],
    ],
  ];

  /** thin separator */
  $sep = fn(string $c = '#F1F5F9') => ['type' => 'separator', 'margin' => 'sm', 'color' => $c];

  /* ── HEADER IMAGE ── */
  $header = FALL_HEADER_URL ? [
    'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '0px',
    'contents' => [[
      'type' => 'image', 'url' => FALL_HEADER_URL,
      'size' => 'full', 'aspectRatio' => '3120:885', 'aspectMode' => 'cover',
    ]],
  ] : null;

  /* ── TITLE STRIP (deep orange-brown #431407) ── */
  $titleStrip = [
    'type'            => 'box',
    'layout'          => 'vertical',
    'paddingStart'    => '16px',
    'paddingEnd'      => '16px',
    'paddingTop'      => '16px',
    'paddingBottom'   => '18px',
    'backgroundColor' => '#431407',
    'contents' => [
      /* badge row */
      [ 'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm', 'margin' => 'none',
        'contents' => [
          [ 'type' => 'box', 'layout' => 'vertical', 'flex' => 0,
            'backgroundColor' => '#EA580C', 'cornerRadius' => '6px',
            'paddingStart' => '8px', 'paddingEnd' => '8px',
            'paddingTop'   => '4px', 'paddingBottom' => '4px',
            'contents' => [[
              'type' => 'text', 'text' => '🚑  แจ้งเตือน',
              'size' => 'xxs', 'color' => '#FFFFFF', 'weight' => 'bold',
            ]],
          ],
          [ 'type' => 'text', 'text' => 'Accident Alert',
            'size' => 'xs', 'color' => '#FED7AA',
            'flex' => 1, 'align' => 'end', 'gravity' => 'center' ],
        ]
      ],
      /* main title */
      [ 'type' => 'text', 'text' => ACC_TITLE,
        'size' => 'lg', 'color' => '#FFFFFF', 'weight' => 'bold',
        'wrap' => true, 'margin' => 'sm' ],
      [ 'type' => 'text', 'text' => ACC_SUBTITLE,
        'size' => 'xxs', 'color' => '#FED7AA', 'wrap' => true, 'margin' => 'xs' ],
    ],
  ];

  /* ── ALERT RIBBON (amber-orange) ── */
  $alertRibbon = [
    'type'            => 'box',
    'layout'          => 'horizontal',
    'spacing'         => 'md',
    'paddingStart'    => '12px', 'paddingEnd'    => '12px',
    'paddingTop'      => '10px', 'paddingBottom' => '10px',
    'backgroundColor' => '#FFF7ED',
    'borderColor'     => '#FED7AA',
    'borderWidth'     => '1px',
    'cornerRadius'    => '10px',
    'margin'          => 'none',
    'contents' => [
      [ 'type' => 'text', 'text' => '🚨',
        'size' => 'xxl', 'flex' => 0, 'gravity' => 'center' ],
      [ 'type' => 'box', 'layout' => 'vertical', 'flex' => 1, 'spacing' => 'xs',
        'contents' => [
          [ 'type' => 'text', 'text' => 'สิทธิ์พิเศษ · ต้องดำเนินการด่วน',
            'size' => 'sm', 'color' => '#C2410C', 'weight' => 'bold' ],
          [ 'type' => 'text', 'text' => 'พ.ร.บ. คุ้มครองผู้ประสบภัย / ประกันสังคมต่างจังหวัด',
            'size' => 'xs', 'color' => '#9A3412', 'wrap' => true ],
        ]
      ],
      /* URGENT pill */
      [ 'type' => 'box', 'layout' => 'vertical',
        'flex' => 0, 'justifyContent' => 'center',
        'contents' => [[
          'type'            => 'box', 'layout' => 'vertical',
          'backgroundColor' => '#EA580C', 'cornerRadius' => '999px',
          'paddingStart'    => '10px', 'paddingEnd'    => '10px',
          'paddingTop'      => '5px',  'paddingBottom' => '5px',
          'contents' => [[
            'type' => 'text', 'text' => 'URGENT',
            'size' => 'xxs', 'color' => '#FFFFFF',
            'weight' => 'bold', 'align' => 'center',
          ]],
        ]],
      ],
    ],
  ];

  /* ── SECTION 1: ข้อมูลผู้ป่วย (navy) ── */
  $secPatient = $mkSec('🧑‍⚕️', 'ข้อมูลผู้ป่วย', [
    /* AN — large */
    [ 'type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'margin' => 'sm',
      'contents' => [
        [ 'type' => 'text', 'text' => 'AN',
          'size' => 'xs', 'color' => '#64748B', 'flex' => 3 ],
        [ 'type' => 'text', 'text' => $an,
          'size' => 'xl', 'color' => '#9A3412',
          'weight' => 'bold', 'flex' => 5, 'align' => 'end' ],
      ]
    ],
    $sep('#F1F5F9'),
    $kv('HN', $hn),
    $sep('#F1F5F9'),
    $kv('ชื่อ-สกุล', $fullname, ['weight' => 'bold', 'color' => '#1E293B']),
  ], ['bg' => '#FFFFFF', 'bd' => '#E2E8F0', 'accent' => '#1E3A8A', 'sep' => '#CBD5E1']);

  /* ── SECTION 2: ข้อมูลการรับบริการ (amber-orange) ── */
  /* สิทธิ์ pill badge — centered */
  $pttypeBadge = [
    'type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
    'contents' => [
      [ 'type' => 'filler' ],
      [ 'type' => 'box', 'layout' => 'vertical', 'flex' => 0,
        'backgroundColor' => '#FFEDD5', 'cornerRadius' => '14px',
        'paddingTop' => '12px', 'paddingBottom' => '12px',
        'paddingStart' => '28px', 'paddingEnd' => '28px',
        'contents' => [
          [ 'type' => 'text', 'text' => ($pttype !== '' ? $pttype : '-'),
            'size' => 'xxl', 'color' => '#7C2D12',
            'weight' => 'bold', 'align' => 'center' ],
          [ 'type' => 'separator', 'margin' => 'sm', 'color' => '#FED7AA' ],
          [ 'type' => 'text', 'text' => 'รหัสสิทธิ์',
            'size' => 'xxs', 'color' => '#9A3412',
            'align' => 'center', 'margin' => 'xs' ],
        ],
      ],
      [ 'type' => 'filler' ],
    ],
  ];
  $pttNameEl = ($pttname !== '') ? [
    'type'  => 'text', 'text' => '🏷️  ' . $pttname,
    'size'  => 'sm', 'color' => '#1F2937',
    'wrap'  => true, 'margin' => 'sm', 'align' => 'center',
  ] : null;

  $secAdmit = $mkSec('🏥', 'ข้อมูลการรับบริการ',
    array_values(array_filter([
      $pttypeBadge,
      $pttNameEl,
      $sep('#FED7AA'),
      $kv('วันที่ Admit',  $regdate, ['color' => '#7C2D12', 'weight' => 'bold']),
      $sep('#F3F4F6'),
      $kv('เวลารับบริการ', $regtime),
    ])),
    ['bg' => '#FFF7ED', 'bd' => '#FED7AA', 'accent' => '#C2410C', 'sep' => '#FFEDD5']
  );

  /* ── SECTION 3: แนวทางการดำเนินการ (blue) ── */
  $urgentBox = [
    'type'            => 'box', 'layout' => 'vertical', 'margin' => 'sm',
    'backgroundColor' => '#FEE2E2', 'cornerRadius' => '8px', 'paddingAll' => '8px',
    'contents' => [[
      'type' => 'text', 'text' => '⏰  โปรดดำเนินการโดยด่วน',
      'size' => 'sm', 'color' => '#B91C1C', 'weight' => 'bold', 'align' => 'center',
    ]],
  ];
  $checkItems = [
    'แจ้งงานประกันสุขภาพ / พ.ร.บ. ดำเนินการเคลม',
    'ตรวจสอบและรวบรวมเอกสารสิทธิ์การรักษา',
    'ประสานงานบริษัทประกัน / สำนักงานประกันสังคม',
    'บันทึกข้อมูลและปิดใบแจ้งหนี้ในระบบ',
  ];
  $actionRows = [$urgentBox];
  foreach ($checkItems as $i => $txt) {
    $actionRows[] = [
      'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm', 'margin' => 'sm',
      'contents' => [
        [ 'type' => 'text', 'text' => '☑',
          'size' => 'sm', 'color' => '#2563EB', 'flex' => 0 ],
        [ 'type' => 'text', 'text' => $txt,
          'size' => 'xs', 'color' => '#1E293B', 'wrap' => true, 'flex' => 1 ],
      ],
    ];
    if ($i < count($checkItems) - 1) {
      $actionRows[] = $sep('#DBEAFE');
    }
  }
  $secAction = $mkSec('📋', 'แนวทางการดำเนินการ', $actionRows,
    ['bg' => '#EFF6FF', 'bd' => '#BFDBFE', 'accent' => '#1D4ED8', 'sep' => '#DBEAFE']
  );

  /* ── BODY ── */
  $body = [
    'type'       => 'box',
    'layout'     => 'vertical',
    'spacing'    => 'none',
    'paddingAll' => '0px',
    'contents'   => [
      $titleStrip,
      [
        'type'            => 'box', 'layout' => 'vertical',
        'paddingAll'      => '14px', 'spacing' => 'none',
        'backgroundColor' => '#FFF7ED',
        'contents'        => [ $alertRibbon, $secPatient, $secAdmit, $secAction ],
      ],
    ],
  ];

  /* ── FOOTER (dark slate #1E293B) ── */
  $footer = [
    'type'            => 'box',
    'layout'          => 'vertical',
    'paddingStart'    => '14px', 'paddingEnd'    => '14px',
    'paddingTop'      => '10px', 'paddingBottom' => '12px',
    'backgroundColor' => '#1E293B',
    'contents'        => array_values(array_filter([
      [ 'type' => 'box', 'layout' => 'horizontal', 'contents' => [
          [ 'type' => 'text', 'text' => ACC_SYSTEM_NAME,
            'size' => 'xxs', 'color' => '#94A3B8', 'flex' => 3, 'wrap' => true ],
          [ 'type' => 'text', 'text' => date('j M Y H:i'),
            'size' => 'xxs', 'color' => '#64748B', 'align' => 'end', 'flex' => 2 ],
      ]],
      $refId !== '' ? [
        'type'   => 'text', 'text' => 'Ref AN: ' . $refId,
        'size'   => 'xxs', 'color' => '#475569', 'margin' => 'xs',
      ] : null,
    ])),
  ];

  /* ── BUBBLE ── */
  $bubble = array_filter([
    'type'   => 'bubble',
    'size'   => 'giga',
    'header' => $header,
    'body'   => $body,
    'footer' => $footer,
    'styles' => [
      'header' => [ 'backgroundColor' => '#FFFFFF' ],
      'body'   => [ 'backgroundColor' => '#FFF7ED' ],
      'footer' => [ 'backgroundColor' => '#1E293B' ],
    ],
  ]);

  $altText = sprintf('[แจ้งเตือนอุบัติเหตุ] AN %s HN %s %s (สิทธิ์ %s)',
    $an, $hn, $fullname, $pttype);
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

/* ==================== Extract messageId helper (shared) ==================== */
if (!function_exists('extract_moph_message_id')) {
  /**
   * ค้นหา messageId จาก JSON response ของ MOPH Alert
   * รองรับหลาย path เพราะ API response format เปลี่ยนได้
   */
  function extract_moph_message_id($json) {
    if (!is_array($json)) return null;
    $paths = [
      ['messageId'],
      ['data',    'messageId'],
      ['result',  'messageId'],
      ['messages', 0, 'messageId'],
      ['messages', 0, 'id'],
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
