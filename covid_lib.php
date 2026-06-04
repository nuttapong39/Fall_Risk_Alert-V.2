<?php
// covid_lib.php — helpers สำหรับ COVID queue UI (โค้ดนี้ตั้งชื่อฟังก์ชันเป็น covid_* เพื่อไม่ชนกับไฟล์อื่น)
date_default_timezone_set('Asia/Bangkok');

/* -------------------- Encoding helpers -------------------- */
if (!function_exists('to_utf8')) {
  function to_utf8($s) {
    if ($s === null || $s === '') return $s;
    if (!is_string($s)) return $s;
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    foreach (['TIS-620','TIS620','Windows-874','CP874','ISO-8859-11','ISO-8859-1'] as $enc) {
      $t = @iconv($enc, 'UTF-8//IGNORE', $s);
      if ($t !== false && $t !== '' && mb_check_encoding($t, 'UTF-8')) return $t;
      $t = @mb_convert_encoding($s, 'UTF-8', $enc);
      if ($t !== false && $t !== '' && mb_check_encoding($t, 'UTF-8')) return $t;
    }
    $t = @iconv('UTF-8','UTF-8//IGNORE',$s);
    return $t !== false ? $t : $s;
  }
}
if (!function_exists('row_to_utf8')) {
  function row_to_utf8(array $row): array {
    foreach ($row as $k=>$v) if (is_string($v)) $row[$k]=to_utf8($v);
    return $row;
  }
}

/* -------------------- Small utils -------------------- */
if (!function_exists('extract_moph_message_id')) {
  function extract_moph_message_id($json) {
    if (!is_array($json)) return null;
    $paths = [
      ['messageId'], ['data','messageId'], ['result','messageId'],
      ['messages',0,'messageId'], ['messages',0,'id'],
    ];
    foreach ($paths as $path){
      $t = $json;
      foreach ($path as $k){ if (is_array($t) && array_key_exists($k,$t)) $t=$t[$k]; else { $t=null; break; } }
      if (is_scalar($t) && $t!=='') return (string)$t;
    }
    return null;
  }
}
if (!function_exists('log_moph_response')) {
  function log_moph_response($row, $code, $resp, $err=null) {
    $line = sprintf("[%s] id=%s hn=%s http=%s err=%s resp=%s\n",
      date('Y-m-d H:i:s'), $row['id']??'-', $row['hn']??'-',
      $code, $err ?: '-', mb_substr($resp ?? '', 0, 2000)
    );
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    $ok = @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'moph_alert.log', $line, FILE_APPEND);
    if ($ok === false) {
      $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'moph_alert.log';
      $ok  = @file_put_contents($tmp, $line, FILE_APPEND);
    }
    if ($ok === false) @error_log($line);
    if (PHP_SAPI === 'cli') echo $line;
  }
}

/* -------------------- Flex (COVID) — helpers -------------------- */

if (!function_exists('covid_thai_date')) {
  function covid_thai_date(?string $ymd): string {
    if (!$ymd) return '-';
    $ts = strtotime($ymd);
    if ($ts === false) return $ymd;
    static $m = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',
                 7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    return sprintf('%d %s %d', (int)date('j',$ts), $m[(int)date('n',$ts)] ?? '', (int)date('Y',$ts)+543);
  }
}

if (!function_exists('covid_flex_row')) {
  function covid_flex_row(string $label, ?string $value, array $opts = []): array {
    $v = ($value === null || $value === '') ? '-' : (string)$value;
    return [
      "type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"sm",
      "contents"=>[
        ["type"=>"text","text"=>$label,
         "size"=>"sm","color"=>"#6B7280","flex"=>4,"weight"=>"regular"],
        ["type"=>"text","text"=>$v,
         "size"=>$opts['size'] ?? "sm","color"=>$opts['color'] ?? "#111827",
         "weight"=>$opts['weight'] ?? "regular","flex"=>6,
         "wrap"=>true,"align"=>"end"],
      ],
    ];
  }
}

if (!function_exists('covid_flex_section')) {
  function covid_flex_section(string $title, array $rows, array $opts = []): array {
    $bg   = $opts['bg']     ?? '#FFFFFF';
    $bd   = $opts['bd']     ?? '#E5E7EB';
    $icon = $opts['icon']   ?? '';
    $acc  = $opts['accent'] ?? '#3730A3';
    return [
      "type"=>"box","layout"=>"vertical",
      "paddingAll"=>"14px","cornerRadius"=>"12px","margin"=>"md","spacing"=>"xs",
      "backgroundColor"=>$bg,"borderColor"=>$bd,"borderWidth"=>"1px",
      "contents"=>array_merge([[
        "type"=>"box","layout"=>"baseline","spacing"=>"sm",
        "contents"=>[
          ["type"=>"text","text"=>($icon ? "$icon  " : '').$title,
           "size"=>"sm","color"=>$acc,"weight"=>"bold","flex"=>1],
        ],
      ]], $rows),
    ];
  }
}

/* -------------------- Flex (COVID) — New Design v2 -------------------- */
function covid_buildMophPayload(array $row): array {
  $row = row_to_utf8($row);

  // ── Constants ──────────────────────────────────────────────────
  $HEADER_URL  = 'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg';
  $SYSTEM_NAME = 'ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยง • รพ.เชียงกลาง';

  // ── Colors (Indigo — COVID theme) ──────────────────────────────
  $color      = '#4338CA'; // indigo-700
  $colorHead  = '#3730A3'; // indigo-800
  $colorBg    = '#EEF2FF'; // indigo-50
  $colorBd    = '#C7D2FE'; // indigo-200

  // ── Data ───────────────────────────────────────────────────────
  $hn       = $row['hn']               ?? '-';
  $fullname = $row['fullname']         ?? '-';
  $age      = isset($row['age']) && $row['age'] !== '' ? $row['age'].' ปี' : '-';
  $cid      = $row['cid']              ?? '-';
  $address  = $row['informaddr']       ?? ($row['address'] ?? '-');
  $tel      = $row['hometel']          ?? '-';
  $vstdate  = covid_thai_date($row['vstdate'] ?? null);
  $doctor   = $row['doctor']           ?? '-';
  $icd10    = $row['pdx']              ?? '-';
  $result   = $row['lab_order_result'] ?? '-';
  $refId    = isset($row['id']) ? 'ID: '.$row['id'] : '';

  // ── HEADER ─────────────────────────────────────────────────────
  $header = [
    "type"=>"box","layout"=>"vertical","paddingAll"=>"0px",
    "contents"=> $HEADER_URL ? [[
      "type"=>"image","url"=>$HEADER_URL,
      "size"=>"full","aspectRatio"=>"3120:885","aspectMode"=>"cover",
    ]] : [],
  ];

  // ── TITLE STRIP ────────────────────────────────────────────────
  $titleStrip = [
    "type"=>"box","layout"=>"vertical",
    "paddingAll"=>"16px","backgroundColor"=>$colorHead,"cornerRadius"=>"0px",
    "contents"=>[
      ["type"=>"box","layout"=>"horizontal","contents"=>[
        ["type"=>"text","text"=>"🦠  แจ้งเตือนผู้ป่วย COVID-19",
         "size"=>"sm","color"=>"#FFFFFF","weight"=>"bold","flex"=>1],
        ["type"=>"text","text"=>"COVID-19 Alert",
         "size"=>"sm","color"=>"#FFFFFFB3","align"=>"end","flex"=>0],
      ]],
      ["type"=>"text","text"=>"ผลตรวจ COVID-19 Positive",
       "size"=>"xxl","color"=>"#FFFFFF","weight"=>"bold","wrap"=>true,"margin"=>"sm"],
      ["type"=>"text","text"=>"COVID-19 Positive Alert · รพ.เชียงกลาง",
       "size"=>"sm","color"=>"#FFFFFFBF","wrap"=>true,"margin"=>"xs"],
    ],
  ];

  // ── PRIORITY BADGE ─────────────────────────────────────────────
  $priority = [
    "type"=>"box","layout"=>"baseline","spacing"=>"sm",
    "paddingAll"=>"10px","backgroundColor"=>$colorBg,
    "cornerRadius"=>"8px","margin"=>"md",
    "contents"=>[
      ["type"=>"text","text"=>"⚠","size"=>"lg","flex"=>0,"color"=>$color],
      ["type"=>"text",
       "text"=>"ผลตรวจ COVID-19 เป็น Positive กรุณาติดตามและรายงานหน่วยงานที่เกี่ยวข้อง",
       "size"=>"sm","color"=>$colorHead,"weight"=>"bold","wrap"=>true,"flex"=>1],
    ],
  ];

  // ── SECTION 1: ข้อมูลผู้ป่วย ───────────────────────────────────
  $sPatient = covid_flex_section('ข้อมูลผู้ป่วย', [
    covid_flex_row('HN', $hn, ['weight'=>'bold','size'=>'md','color'=>$colorHead]),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    covid_flex_row('ชื่อ-สกุล', $fullname, ['weight'=>'bold']),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    covid_flex_row('อายุ', $age),
    ["type"=>"separator","margin"=>"sm","color"=>"#F3F4F6"],
    covid_flex_row('เลขบัตรประชาชน', $cid),
  ], ['icon'=>'🧑‍⚕️','accent'=>$colorHead]);

  // ── SECTION 2: ผลการตรวจ ───────────────────────────────────────
  $diagBox = [
    "type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"sm",
    "contents"=>[
      ["type"=>"text","text"=>"ICD-10",
       "size"=>"sm","color"=>$color,"weight"=>"bold","flex"=>0],
      ["type"=>"text","text"=>$icd10,
       "size"=>"xl","color"=>$colorHead,"weight"=>"bold","flex"=>1,"align"=>"end"],
    ],
  ];
  $sDiag = covid_flex_section('ผลการวินิจฉัยและตรวจ', [
    $diagBox,
    ["type"=>"separator","margin"=>"sm","color"=>$colorBd],
    covid_flex_row('ผล COVID-19', $result, ['weight'=>'bold','color'=>$color]),
    covid_flex_row('วันที่รับบริการ', $vstdate),
    covid_flex_row('แพทย์ผู้ตรวจ', $doctor),
  ], ['icon'=>'🔬','accent'=>$color,'bg'=>$colorBg,'bd'=>$colorBd]);

  // ── SECTION 3: ข้อมูลสำหรับติดตาม ─────────────────────────────
  $sContact = covid_flex_section('ข้อมูลสำหรับติดตาม', [
    ["type"=>"box","layout"=>"vertical","spacing"=>"xs","margin"=>"sm","contents"=>[
      ["type"=>"text","text"=>"📍 ที่อยู่",
       "size"=>"sm","color"=>"#6B7280","weight"=>"bold"],
      ["type"=>"text","text"=>($address ?: '-'),
       "size"=>"sm","color"=>"#111827","wrap"=>true],
    ]],
    ["type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"md","contents"=>[
      ["type"=>"text","text"=>"📞 เบอร์โทร",
       "size"=>"sm","color"=>"#6B7280","weight"=>"bold","flex"=>4],
      ["type"=>"text","text"=>($tel ?: '-'),
       "size"=>"md","color"=>"#065F46","weight"=>"bold","flex"=>6,
       "align"=>"end","wrap"=>true],
    ]],
  ], ['icon'=>'🏠','accent'=>'#065F46','bg'=>'#ECFDF5','bd'=>'#A7F3D0']);

  // ── SECTION 4: คำแนะนำ ────────────────────────────────────────
  $instructions = [
    'รายงาน สสจ. / สสอ. ภายใน 24 ชั่วโมง',
    'ประสานทีมควบคุมโรค (DC) เพื่อสอบสวนโรค',
    'แนะนำการกักตัวและป้องกันการแพร่เชื้อ',
    'บันทึกข้อมูลรายงาน 506 ใน HDC',
  ];
  $instrItems = [
    ["type"=>"text","text"=>"โปรดดำเนินการภายใน 24 ชั่วโมง",
     "size"=>"sm","color"=>"#1E3A8A","weight"=>"bold","margin"=>"sm"],
  ];
  foreach ($instructions as $ins) {
    $instrItems[] = ["type"=>"text","text"=>"• $ins",
                     "size"=>"sm","color"=>"#1F2937","wrap"=>true,"margin"=>"xs"];
  }
  $sAction = covid_flex_section('คำแนะนำสำหรับเจ้าหน้าที่', $instrItems,
    ['icon'=>'📋','accent'=>'#1E3A8A','bg'=>'#EFF6FF','bd'=>'#BFDBFE']);

  // ── BODY ───────────────────────────────────────────────────────
  $body = [
    "type"=>"box","layout"=>"vertical","spacing"=>"none","paddingAll"=>"0px",
    "contents"=>[
      $titleStrip,
      [
        "type"=>"box","layout"=>"vertical","paddingAll"=>"14px","spacing"=>"none",
        "backgroundColor"=>"#F9FAFB",
        "contents"=>[$priority, $sPatient, $sDiag, $sContact, $sAction],
      ],
    ],
  ];

  // ── FOOTER ─────────────────────────────────────────────────────
  $footer = [
    "type"=>"box","layout"=>"vertical",
    "paddingStart"=>"14px","paddingEnd"=>"14px",
    "paddingTop"=>"10px","paddingBottom"=>"12px",
    "backgroundColor"=>"#F3F4F6",
    "contents"=>[
      ["type"=>"separator","color"=>"#E5E7EB"],
      ["type"=>"box","layout"=>"horizontal","margin"=>"md","contents"=>[
        ["type"=>"text","text"=>$SYSTEM_NAME,
         "size"=>"xs","color"=>"#6B7280","flex"=>3,"wrap"=>true],
        ["type"=>"text","text"=>date('j M Y H:i'),
         "size"=>"xs","color"=>"#6B7280","align"=>"end","flex"=>2],
      ]],
      $refId ? ["type"=>"text","text"=>"Ref $refId",
                "size"=>"xs","color"=>"#9CA3AF","margin"=>"xs"]
             : ["type"=>"filler"],
    ],
  ];

  // ── BUBBLE ─────────────────────────────────────────────────────
  $bubble = [
    "type"=>"bubble","size"=>"giga",
    "header"=>$header,"body"=>$body,"footer"=>$footer,
    "styles"=>[
      "header"=>["backgroundColor"=>"#FFFFFF"],
      "body"=>  ["backgroundColor"=>"#F9FAFB"],
      "footer"=>["backgroundColor"=>"#F3F4F6"],
    ],
  ];

  $altText = sprintf('[แจ้งเตือน] COVID-19 Positive HN %s %s', $hn, $fullname);
  if (mb_strlen($altText) > 400) $altText = mb_substr($altText, 0, 397).'...';

  return ["messages"=>[["type"=>"flex","altText"=>$altText,"contents"=>$bubble]]];
}

/* -------------------- Sender (COVID) -------------------- */
function covid_send_via_moph_alert(array $row): array {
  if (!defined('MOPH_API_URL'))   define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
  if (!defined('MOPH_TIMEOUT'))   define('MOPH_TIMEOUT', 30);
  if (!defined('MOPH_CLIENT_KEY')) {
    if (defined('FRACTURE_CLIENT_KEY')) define('MOPH_CLIENT_KEY', FRACTURE_CLIENT_KEY);
    else define('MOPH_CLIENT_KEY', '');
  }
  if (!defined('MOPH_SECRET_KEY')) {
    if (defined('FRACTURE_SECRET_KEY')) define('MOPH_SECRET_KEY', FRACTURE_SECRET_KEY);
    else define('MOPH_SECRET_KEY', '');
  }

  $payload = covid_buildMophPayload($row);
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($body === false) {
    $jsonErr = json_last_error_msg();
    log_moph_response($row, 0, null, "JSON_ENCODE_FAIL: ".$jsonErr);
    return [false, null, "JSON encode failed: ".$jsonErr];
  }

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => MOPH_API_URL,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => MOPH_TIMEOUT,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
      'client-key: ' . MOPH_CLIENT_KEY,
      'secret-key: ' . MOPH_SECRET_KEY,
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json'
    ],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  log_moph_response($row, $code, $resp, $err);
  if ($err) return [false, null, "CURL: $err"];

  $json = json_decode($resp, true);
  $mid  = extract_moph_message_id($json);

  $apiStatus = is_array($json) && array_key_exists('status',$json) ? $json['status'] : null;
  $apiMsg    = is_array($json) && array_key_exists('message',$json) ? (string)$json['message'] : null;

  $looksSuccess =
    ($mid) ||
    (is_numeric($apiStatus) && (int)$apiStatus === 200) ||
    ($apiMsg && preg_match('/succ(e|)ss/i', $apiMsg));

  if (($code >= 200 && $code < 300) && $looksSuccess) {
    $ref = $mid ?: ($apiStatus ? "status:$apiStatus" : 'HTTP'.$code);
    return [true, $ref, null];
  }

  $detail = "HTTP=$code";
  if ($apiStatus !== null) $detail .= " status=$apiStatus";
  if ($apiMsg)            $detail .= " msg=$apiMsg";
  return [false, null, "MOPH error: $detail"];
}

/* -------------------- Action for queue_ui (manual) -------------------- */
function send_one_by_id(PDO $dbcon, int $id): array {
  $stmt = $dbcon->prepare("SELECT * FROM covid_queue WHERE id=:id");
  $stmt->execute([':id'=>$id]);
  $row = $stmt->fetch();
  if (!$row) return [false, null, "id not found"];

  [$ok, $ref, $err] = covid_send_via_moph_alert($row);

  if ($ok) {
    $upd = $dbcon->prepare("
      UPDATE covid_queue
      SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1,
          last_error=NULL, out_ref=:r, line_message_id=:r
      WHERE id=:id
    ");
    $upd->execute([':r'=>$ref, ':id'=>$id]);
  } else {
    $upd = $dbcon->prepare("
      UPDATE covid_queue
      SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:e
      WHERE id=:id
    ");
    $upd->execute([':e'=>$err, ':id'=>$id]);
  }
  return [$ok, $ref, $err];
}
