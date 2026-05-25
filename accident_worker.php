<?php
/**
 * accident_worker.php — ส่งแจ้งเตือนอุบัติเหตุ (สิทธิ์ พ.ร.บ./ประกันสังคมต่างจังหวัด) ไป MOPH Alert
 * STEP 1: Ingest -> accident_queue
 * STEP 2: ส่ง Flex + อัปเดตสถานะ (มีคูลดาวน์/จำนวนครั้งสูงสุด)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

// Worker script — ต้องรันจนเสร็จ ไม่มี time limit
// (Apache/XAMPP default = 120s ไม่เพียงพอสำหรับ batch cURL)
@set_time_limit(0);
@ini_set('max_execution_time', '0');

/* ===================== CONFIG / DEFAULTS ===================== */
if (!defined('MOPH_API_URL'))            define('MOPH_API_URL',            'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
if (!defined('MOPH_TIMEOUT'))            define('MOPH_TIMEOUT',            20);  // total transfer timeout (s)
if (!defined('MOPH_CONNECT_TIMEOUT'))    define('MOPH_CONNECT_TIMEOUT',    8);   // TCP connect timeout (s) — fail-fast on dead IP

// ใช้คีย์เดียวกับ config.php (เหมือน fracture/covid)
if (!defined('ACCIDENT_CLIENT_KEY')) define('ACCIDENT_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('ACCIDENT_SECRET_KEY')) define('ACCIDENT_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

// ควบคุมนโยบายการส่งซ้ำ
if (!defined('ACCIDENT_RESEND_COOLDOWN_MIN')) define('ACCIDENT_RESEND_COOLDOWN_MIN', 1);
if (!defined('ACCIDENT_MAX_ATTEMPTS'))        define('ACCIDENT_MAX_ATTEMPTS', 8);
if (!defined('ACCIDENT_BATCH_LIMIT'))         define('ACCIDENT_BATCH_LIMIT', 50);

// default lookback
if (!defined('DEFAULT_LOOKBACK_DAYS')) define('DEFAULT_LOOKBACK_DAYS', 7);

// (ตัวเลือก) บังคับ IP ปลายทาง หากต้องการ pin IP ที่ดีไว้
if (!defined('MOPH_FORCE_IP')) define('MOPH_FORCE_IP', '');

// (ตัวเลือก) รายการ IP สำรองที่อยากให้ลองเพิ่มนอกเหนือจาก DNS (แก้ได้)
if (!defined('MOPH_SEED_IPS')) define('MOPH_SEED_IPS', '203.151.48.190,203.151.254.91,43.229.149.136');

/* ===================== LOG FILES ===================== */
$LOG_DIR  = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$RUN_LOG  = $LOG_DIR . '/accident_task_run.log';
$SEND_LOG = $LOG_DIR . '/moph_alert_accident.log';

function runlog($text){
  global $RUN_LOG;
  @file_put_contents($RUN_LOG, '['.date('Y-m-d H:i:s')."] $text\n", FILE_APPEND);
  if (PHP_SAPI==='cli') echo '['.date('Y-m-d H:i:s')."] $text\n";
}
function log_send($row,$code,$resp,$err=null,$note=''){
  global $SEND_LOG;
  $an  = $row['an']??'-';
  $id  = $row['id']??'-';
  $line = sprintf("[%s] id=%s an=%s http=%s err=%s %sresp=%s\n",
            date('Y-m-d H:i:s'), $id, $an, $code, $err?:'-',
            ($note!=='' ? $note.' ' : ''),
            mb_substr($resp??'',0,2000));
  @file_put_contents($SEND_LOG,$line,FILE_APPEND);
  if (PHP_SAPI==='cli') echo $line;
}

/* ===================== HELPERS (UTF-8/Params/Date) ===================== */
function to_utf8($s){
  if ($s===null || $s==='' || !is_string($s)) return $s;
  if (mb_check_encoding($s,'UTF-8')) return $s;
  foreach(['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc){
    $t=@iconv($enc,'UTF-8//IGNORE',$s); if($t!==false && $t!==''){ if(mb_check_encoding($t,'UTF-8')) return $t; }
    $t=@mb_convert_encoding($s,'UTF-8',$enc); if($t!==false && $t!==''){ if(mb_check_encoding($t,'UTF-8')) return $t; }
  }
  $t=@iconv('UTF-8','UTF-8//IGNORE',$s); return $t!==false ? $t : $s;
}
function row_to_utf8(array $r){ foreach($r as $k=>$v){ if(is_string($v)) $r[$k]=to_utf8($v); } return $r; }

function readParam($key,$default=null){
  if(PHP_SAPI==='cli'){
    static $args; if($args===null) $args=getopt('', ['start::','end::','dry-run']);
    if($key==='dry-run') return array_key_exists('dry-run',$args);
    return $args[$key]??$default;
  } else {
    if($key==='dry-run') return isset($_GET['dry-run']);
    return $_GET[$key]??$default;
  }
}
function normalize_date_ymd($d,$fallback){
  if(!is_string($d)||$d==='') return $fallback;
  if(preg_match('/^\s*(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\s*$/',$d,$m)){
    $y=(int)$m[1]; $mo=(int)$m[2]; $da=(int)$m[3];
    if($y>2400) $y-=543;
    if($y<1900||$y>2100||$mo<1||$mo>12||$da<1||$da>31) return $fallback;
    return sprintf('%04d-%02d-%02d',$y,$mo,$da);
  }
  return $fallback;
}

/* ===================== Flex Payload ===================== */
if (!defined('ACC_TITLE'))        define('ACC_TITLE',      'ผู้ป่วยอุบัติเหตุ (สิทธิ์ พ.ร.บ./ประกันสังคมต่างจังหวัด)');
if (!defined('ACC_SUBTITLE'))     define('ACC_SUBTITLE',   'Accident Alert สำหรับเจ้าหน้าที่ รพ.สต. เครือข่าย');
if (!defined('ACC_SYSTEM_NAME'))  define('ACC_SYSTEM_NAME','ระบบแจ้งเตือนอุบัติเหตุ • รพ.เชียงกลาง');
if (!defined('FALL_HEADER_URL'))  define('FALL_HEADER_URL','https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
if (!defined('FALL_ICON_URL'))    define('FALL_ICON_URL',  'https://www.ckhospital.net/home/PDF/Logo_ck.png');

/** แปลง YYYY-MM-DD → "23 เม.ย. 2569" (พ.ศ.) */
function acc_thai_date(?string $ymd): string {
  if (!$ymd) return '-';
  $ts = strtotime($ymd);
  if ($ts === false) return $ymd;
  static $months = [
    1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',
    7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.',
  ];
  return sprintf('%d %s %d', (int)date('j',$ts), $months[(int)date('n',$ts)]??'', (int)date('Y',$ts)+543);
}

/**
 * buildAccidentPayload()  v2 — Modern section-card Flex Message
 * ข้อมูล: AN, HN, ชื่อ-สกุล, วันที่ Admit, เวลา, สิทธิ์การรักษา
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

  $pttypeDisplay = trim($pttype . ($pttname !== '' ? '  ' . $pttname : ''));
  if ($pttypeDisplay === '') $pttypeDisplay = '-';

  /* ── Local helpers ── */

  /** section card with ▌ accent bar */
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
      'type'            => 'box', 'layout' => 'vertical',
      'paddingAll'      => '14px', 'cornerRadius' => '12px',
      'backgroundColor' => $bg, 'borderColor' => $bd, 'borderWidth' => '1px',
      'margin'          => 'md', 'spacing' => 'xs',
      'contents'        => array_values(array_filter(
        array_merge([$hdr, ['type'=>'separator','margin'=>'sm','color'=>$sep]], $rows),
        fn($x) => $x !== null
      )),
    ];
  };

  /** key-value row */
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

  $sep = fn(string $c = '#F1F5F9') => ['type' => 'separator', 'margin' => 'sm', 'color' => $c];

  /* ── HEADER IMAGE ── */
  $header = FALL_HEADER_URL ? [
    'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '0px',
    'contents' => [[
      'type' => 'image', 'url' => FALL_HEADER_URL,
      'size' => 'full', 'aspectRatio' => '3120:885', 'aspectMode' => 'cover',
    ]],
  ] : null;

  /* ── TITLE STRIP (deep orange-brown) ── */
  $titleStrip = [
    'type'            => 'box', 'layout' => 'vertical',
    'paddingStart'    => '16px', 'paddingEnd'  => '16px',
    'paddingTop'      => '16px', 'paddingBottom' => '18px',
    'backgroundColor' => '#431407',
    'contents' => [
      [ 'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm', 'margin' => 'none',
        'contents' => [
          [ 'type' => 'box', 'layout' => 'vertical', 'flex' => 0,
            'backgroundColor' => '#EA580C', 'cornerRadius' => '6px',
            'paddingStart' => '8px', 'paddingEnd' => '8px',
            'paddingTop' => '4px', 'paddingBottom' => '4px',
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
      [ 'type' => 'text', 'text' => ACC_TITLE,
        'size' => 'lg', 'color' => '#FFFFFF', 'weight' => 'bold',
        'wrap' => true, 'margin' => 'sm' ],
      [ 'type' => 'text', 'text' => ACC_SUBTITLE,
        'size' => 'xxs', 'color' => '#FED7AA', 'wrap' => true, 'margin' => 'xs' ],
    ],
  ];

  /* ── ALERT RIBBON (amber) ── */
  $alertRibbon = [
    'type'            => 'box', 'layout' => 'horizontal', 'spacing' => 'md',
    'paddingStart'    => '12px', 'paddingEnd'    => '12px',
    'paddingTop'      => '10px', 'paddingBottom' => '10px',
    'backgroundColor' => '#FFF7ED',
    'borderColor'     => '#FED7AA', 'borderWidth' => '1px',
    'cornerRadius'    => '10px', 'margin' => 'none',
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
      [ 'type' => 'box', 'layout' => 'vertical', 'flex' => 0, 'justifyContent' => 'center',
        'contents' => [[
          'type'            => 'box', 'layout' => 'vertical',
          'backgroundColor' => '#EA580C', 'cornerRadius' => '999px',
          'paddingStart'    => '10px', 'paddingEnd'    => '10px',
          'paddingTop'      => '5px',  'paddingBottom' => '5px',
          'contents' => [[
            'type' => 'text', 'text' => 'URGENT',
            'size' => 'xxs', 'color' => '#FFFFFF', 'weight' => 'bold', 'align' => 'center',
          ]],
        ]],
      ],
    ],
  ];

  /* ── SECTION 1: ข้อมูลผู้ป่วย (navy) ── */
  $secPatient = $mkSec('🧑‍⚕️', 'ข้อมูลผู้ป่วย', [
    /* AN — ใหญ่ */
    [ 'type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'margin' => 'sm',
      'contents' => [
        [ 'type' => 'text', 'text' => 'AN',
          'size' => 'xs', 'color' => '#64748B', 'flex' => 3 ],
        [ 'type' => 'text', 'text' => $an,
          'size' => 'xl', 'color' => '#9A3412', 'weight' => 'bold',
          'flex' => 5, 'align' => 'end' ],
      ]
    ],
    $sep('#F1F5F9'),
    $kv('HN', $hn),
    $sep('#F1F5F9'),
    $kv('ชื่อ-สกุล', $fullname, ['weight' => 'bold', 'color' => '#1E293B']),
  ], ['bg' => '#FFFFFF', 'bd' => '#E2E8F0', 'accent' => '#1E3A8A', 'sep' => '#CBD5E1']);

  /* ── SECTION 2: ข้อมูลการรับบริการ (amber-orange) ── */
  /* สิทธิ์ badge — centered */
  $pttypeBadge = [
    'type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
    'contents' => [
      [ 'type' => 'filler' ],
      [ 'type' => 'box', 'layout' => 'vertical', 'flex' => 0,
        'backgroundColor' => '#FFEDD5', 'cornerRadius' => '14px',
        'paddingTop' => '12px', 'paddingBottom' => '12px',
        'paddingStart' => '28px', 'paddingEnd' => '28px',
        'contents' => [
          [ 'type' => 'text', 'text' => $pttype !== '' ? $pttype : '-',
            'size' => 'xxl', 'color' => '#7C2D12', 'weight' => 'bold', 'align' => 'center' ],
          [ 'type' => 'separator', 'margin' => 'sm', 'color' => '#FED7AA' ],
          [ 'type' => 'text', 'text' => 'รหัสสิทธิ์',
            'size' => 'xxs', 'color' => '#9A3412', 'align' => 'center', 'margin' => 'xs' ],
        ],
      ],
      [ 'type' => 'filler' ],
    ],
  ];
  $pttNameEl = ($pttname !== '') ? [
    'type' => 'text', 'text' => '🏷️  ' . $pttname,
    'size' => 'sm', 'color' => '#1F2937', 'wrap' => true,
    'margin' => 'sm', 'align' => 'center',
  ] : null;

  $secAdmit = $mkSec('🏥', 'ข้อมูลการรับบริการ',
    array_values(array_filter([
      $pttypeBadge,
      $pttNameEl,
      $sep('#FED7AA'),
      $kv('วันที่ Admit',     $regdate, ['color' => '#7C2D12', 'weight' => 'bold']),
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
        [ 'type' => 'text', 'text' => '☑', 'size' => 'sm', 'color' => '#2563EB', 'flex' => 0 ],
        [ 'type' => 'text', 'text' => $txt, 'size' => 'xs', 'color' => '#1E293B', 'wrap' => true, 'flex' => 1 ],
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
    'type' => 'box', 'layout' => 'vertical', 'spacing' => 'none', 'paddingAll' => '0px',
    'contents' => [
      $titleStrip,
      [
        'type'            => 'box', 'layout' => 'vertical',
        'paddingAll'      => '14px', 'spacing' => 'none',
        'backgroundColor' => '#FFF7ED',
        'contents'        => [ $alertRibbon, $secPatient, $secAdmit, $secAction ],
      ],
    ],
  ];

  /* ── FOOTER (dark slate) ── */
  $footer = [
    'type'            => 'box', 'layout' => 'vertical',
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
    'type'   => 'bubble', 'size' => 'giga',
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

/* ===================== JSON helpers ===================== */
function extract_moph_message_id($json){
  if(!is_array($json)) return null;
  $paths=[['messageId'],['data','messageId'],['result','messageId'],['messages',0,'messageId'],['messages',0,'id']];
  foreach($paths as $p){ $t=$json; foreach($p as $k){ if(is_array($t)&&array_key_exists($k,$t)) $t=$t[$k]; else { $t=null; break; } } if(is_scalar($t)&&$t!=='') return (string)$t; }
  return null;
}

/* ===================== SENDER (มี fallback + บันทึก primary_ip) ===================== */
function send_via_moph_alert_accident(array $row): array {
  $row = row_to_utf8($row);
  $payload = buildAccidentPayload($row);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false) {
    $jsonErr = json_last_error_msg();
    log_send($row, 0, null, "JSON_ENCODE_FAIL: ".$jsonErr);
    return [false, null, "JSON encode failed: ".$jsonErr];
  }

  $host   = parse_url(MOPH_API_URL, PHP_URL_HOST);
  $dnsIps = @gethostbynamel($host) ?: [];
  $seed   = array_values(array_filter(array_map('trim', explode(',', (string)MOPH_SEED_IPS))));
  $order  = [];

  // ถ้ามี force-ip → พยายามลองอันนี้ก่อน
  if (defined('MOPH_FORCE_IP') && MOPH_FORCE_IP) $order[] = MOPH_FORCE_IP;
  // ตามด้วย DNS ปัจจุบัน
  foreach ($dnsIps as $ip) $order[] = $ip;
  // ตามด้วย seed list
  foreach ($seed as $ip)   $order[] = $ip;

  // unique & เก็บลำดับแรกสุดไว้ก่อน
  $order = array_values(array_unique($order));

  // helper: ส่ง 1 ครั้ง (optionally fix IP)
  $doSend = function(?string $ipOverride) use ($body, $host, $row) {
    $ch = curl_init();
    $opts = [
      CURLOPT_URL             => MOPH_API_URL,
      CURLOPT_RETURNTRANSFER  => true,
      CURLOPT_CONNECTTIMEOUT  => MOPH_CONNECT_TIMEOUT, // fail-fast ถ้า IP ไม่ตอบ
      CURLOPT_TIMEOUT         => MOPH_TIMEOUT,          // total transfer timeout
      CURLOPT_CUSTOMREQUEST   => 'POST',
      CURLOPT_POSTFIELDS     => $body,
      CURLOPT_HTTPHEADER     => [
        'client-key: ' . ACCIDENT_CLIENT_KEY,
        'secret-key: ' . ACCIDENT_SECRET_KEY,
        'Content-Type: application/json; charset=UTF-8',
        'Accept: application/json',
        'Expect:',
        'Connection: close',
      ],
      CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ];
    if ($ipOverride) {
      $opts[CURLOPT_RESOLVE] = [ $host.':443:'.$ipOverride ];
    }
    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $pIp  = curl_getinfo($ch, CURLINFO_PRIMARY_IP); // ip ที่ต่อจริง
    curl_close($ch);

    $note = 'primary_ip='.$pIp.($ipOverride? ' try_ip='.$ipOverride:'');
    log_send($row, $code, $resp, $err?:null, $note);

    return [$code,$resp,$err,$pIp];
  };

  /* 1) ยิงแบบปกติก่อน (ยกเว้นมี FORCE_IP จะลอง FORCE_IP ก่อน) */
  if (!MOPH_FORCE_IP) {
    [$code,$resp,$err, $pIp] = $doSend(null);
    if (!$err) {
      $json = json_decode($resp, true);
      $mid  = extract_moph_message_id($json);
      $apiS = is_array($json)&&array_key_exists('status',$json) ? $json['status'] : null;
      $apiM = is_array($json)&&array_key_exists('message',$json)? (string)$json['message'] : null;
      $looksSuccess = ($mid) || (is_numeric($apiS) && (int)$apiS===200) || ($apiM && preg_match('/succ(e|)ss/i',$apiM));
      if (($code>=200 && $code<300) && $looksSuccess) {
        $ref = $mid ?: ($apiS ? "status:$apiS" : 'HTTP'.$code);
        return [true, $ref, null];
      }
      $detail = "HTTP=$code"; if($apiS!==null)$detail.=" status=$apiS"; if($apiM)$detail.=" msg=$apiM";
      return [false, null, "MOPH error: $detail"];
    }
  }

  /* 2) ถ้า error/timeout → ลองทีละ IP จาก $order */
  foreach ($order as $ip) {
    [$code,$resp,$err, $pIp] = $doSend($ip);
    if ($err) continue;

    $json = json_decode($resp, true);
    $mid  = extract_moph_message_id($json);
    $apiS = is_array($json)&&array_key_exists('status',$json) ? $json['status'] : null;
    $apiM = is_array($json)&&array_key_exists('message',$json)? (string)$json['message'] : null;
    $looksSuccess = ($mid) || (is_numeric($apiS) && (int)$apiS===200) || ($apiM && preg_match('/succ(e|)ss/i',$apiM));
    if (($code>=200 && $code<300) && $looksSuccess) {
      $ref = $mid ?: ($apiS ? "status:$apiS" : 'HTTP'.$code);
      return [true, $ref, null];
    }
    $detail = "HTTP=$code"; if($apiS!==null)$detail.=" status=$apiS"; if($apiM)$detail.=" msg=$apiM";
    return [false, null, "MOPH error: $detail"];
  }

  return [false, null, "CURL: timeout/no response"];
}

/* ===================== PARAMS & DATE RANGE ===================== */
$start = readParam('start', date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days')));
$end   = readParam('end',   date('Y-m-d'));
$dry   = readParam('dry-run', false);

$today        = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days'));
$start = normalize_date_ymd($start,$defaultStart);
$end   = normalize_date_ymd($end,$today);
if(strtotime($start)===false || strtotime($end)===false || $start>$end){ $start=$defaultStart; $end=$today; }

runlog("Effective range: $start -> $end");

/* ===================== GLOBAL DB LOCK ===================== */
try{ $gotLock = (int)$dbcon->query("SELECT GET_LOCK('accident_send_lock', 1)")->fetchColumn(); }
catch(Throwable $e){ $gotLock=1; }
if($gotLock!==1){ runlog("skip: another instance running"); if(PHP_SAPI!=='cli') echo "<pre>skip: another instance</pre>"; return; }

/* ===================== STEP 1: INGEST ===================== */
// เกณฑ์: ipt.regdate ช่วงวัน + pttype IN (33,35,36,39)
$where  = ["ipt.regdate BETWEEN :s AND :e","ipt.pttype IN ('33','35','36','39')"];
$params = [":s"=>$start, ":e"=>$end];

$sql = $dbcon->prepare("
  SELECT
    ipt.an, pt.hn,
    CONCAT(COALESCE(pt.pname,''),COALESCE(pt.fname,''),' ',COALESCE(pt.lname,'')) AS fullname,
    ipt.regdate, ipt.regtime, ipt.pttype, ptt.name AS pttname
  FROM ipt
    LEFT JOIN patient pt ON pt.hn = ipt.hn
    LEFT JOIN pttype  ptt ON ptt.pttype = ipt.pttype
    LEFT JOIN accident_queue q ON q.an = ipt.an
  WHERE ".implode(' AND ',$where)."
    AND q.an IS NULL
  ORDER BY ipt.regdate DESC, ipt.regtime DESC, ipt.an DESC
  LIMIT 500
");
$sql->execute($params);
$newRows = $sql->fetchAll();
runlog("Ingest: found ".(is_array($newRows)?count($newRows):0)." new rows.");

if(!$dry && $newRows){
  $ins = $dbcon->prepare("
    INSERT INTO accident_queue
      (an, hn, fullname, regdate, regtime, pttype, pttname, status, attempt, created_at)
    VALUES
      (:an, :hn, :fullname, :regdate, :regtime, :pttype, :pttname, 0, 0, NOW())
    ON DUPLICATE KEY UPDATE an=an
  ");
  foreach($newRows as $r){
    $ins->execute([
      ':an'=>$r['an'], ':hn'=>$r['hn'], ':fullname'=>$r['fullname'],
      ':regdate'=>$r['regdate'], ':regtime'=>$r['regtime'],
      ':pttype'=>$r['pttype'], ':pttname'=>$r['pttname'],
    ]);
  }
}

/* ===================== STEP 2: SEND ===================== */
$cool = (int)ACCIDENT_RESEND_COOLDOWN_MIN;
$maxT = (int)ACCIDENT_MAX_ATTEMPTS;
$lim  = (int)ACCIDENT_BATCH_LIMIT;

$getQ = $dbcon->prepare("
  SELECT * FROM accident_queue
  WHERE status=0
    AND (last_attempt_at IS NULL OR TIMESTAMPDIFF(MINUTE,last_attempt_at,NOW()) >= :cd)
    AND attempt < :mx
  ORDER BY (last_attempt_at IS NULL) DESC, last_attempt_at ASC, created_at ASC
  LIMIT $lim
");
$getQ->execute([':cd'=>$cool, ':mx'=>$maxT]);
$queue = $getQ->fetchAll();
runlog("Send: to process ".count($queue)." rows (cooldown={$cool}m, maxTry={$maxT}).");

$updOk = $dbcon->prepare("UPDATE accident_queue SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1, last_error=NULL, out_ref=:r, line_message_id=:r WHERE id=:id");
$updNg = $dbcon->prepare("UPDATE accident_queue SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e WHERE id=:id");

foreach($queue as $row){
  if($dry){ runlog("DRY-RUN: would send id={$row['id']} an={$row['an']}"); continue; }
  usleep(random_int(10,80)*1000); // กันยิงพร้อมกัน

  [$ok,$ref,$err] = send_via_moph_alert_accident($row);

  if($ok){
    $updOk->execute([':r'=>$ref, ':id'=>$row['id']]);
    runlog("OK id={$row['id']} ref=".($ref??'-'));
  } else {
    $updNg->execute([':e'=>$err, ':id'=>$row['id']]);
    runlog("FAIL id={$row['id']} err=$err");
  }
}

/* ===================== UNLOCK & DONE ===================== */
try{ $dbcon->query("DO RELEASE_LOCK('accident_send_lock')"); } catch(Throwable $e){}
if(PHP_SAPI!=='cli'){ echo "<pre>Done</pre>"; }
