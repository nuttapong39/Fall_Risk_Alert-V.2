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

/* -------------------- Flex (COVID) helpers -------------------- */
if (!function_exists('cfg')) {
  function cfg(string $name, $default = null) {
    return defined($name) ? constant($name) : $default;
  }
}
if (!function_exists('covid_icon')) {
  function covid_icon(string $key): string {
    $map = [
      'hn' => "🏥", 'fullname' => "🧑‍⚕️", 'addr' => "📍", 'tel' => "📞",
      'cid' => "🆔", 'vstdate' => "📅", 'doctor' => "👨‍⚕️", 'icd10' => "🧾",
      'lab' => "🧪",
    ];
    return $map[$key] ?? "•";
  }
}
if (!function_exists('covid_row_compact')) {
  function covid_row_compact(string $icon, string $label, ?string $value): array {
    $val = ($value === null || $value === '') ? '-' : $value;
    return [
      "type"=>"box","layout"=>"horizontal","spacing"=>"md","margin"=>"sm",
      "contents"=>[
        [ "type"=>"text","text"=>$icon,"size"=>"sm","flex"=>0,"align"=>"start" ],
        [
          "type"=>"box","layout"=>"vertical","flex"=>1,"contents"=>[
            [ "type"=>"text","text"=>$label,"size"=>"sm","color"=>"#6B7280","weight"=>"bold" ],
            [ "type"=>"text","text"=>$val,"size"=>"md","color"=>"#111827","wrap"=>true ]
          ]
        ]
      ]
    ];
  }
}
if (!function_exists('covid_divider_sm')) {
  function covid_divider_sm(): array {
    return [ "type"=>"separator","margin"=>"sm","color"=>"#E5E7EB" ];
  }
}

/* -------------------- Flex (COVID) — compact single card + clear dividers -------------------- */
function covid_buildMophPayload(array $row): array {
  $row = row_to_utf8($row);

  $titleText = cfg('LINE_TITLE', 'Lab Alert ห้องยา');
  $headerUrl = cfg('LINE_HEADER_URL', '');
  $iconUrl   = cfg('LINE_ICON_URL', '');

  $header = [
    "type" => "box","layout" => "vertical","paddingAll" => "0px",
    "contents" => $headerUrl ? [[
      "type" => "image","url" => $headerUrl,"size" => "full",
      "aspectRatio" => "3120:885","aspectMode" => "cover"
    ]] : []
  ];

  $titleRow = [
    "type"=>"box","layout"=>"horizontal","margin"=>"md","contents"=>[
      [
        "type"=>"box","layout"=>"vertical","flex"=>3,"contents"=>[
          [ "type"=>"text","text"=>$titleText,'weight'=>'bold','size'=>'xl','color'=>'#1F2937' ]
        ]
      ],
      $iconUrl ? [ "type"=>"image","url"=>$iconUrl,"flex"=>1,"size"=>"sm","align"=>"end","gravity"=>"center" ] : [
        "type"=>"filler","flex"=>1
      ]
    ]
  ];

  $rows = [];
  $pairs = [
    [covid_icon('hn'),      'HN',                 $row['hn'] ?? null],
    [covid_icon('fullname'),'ชื่อ-สกุล',          $row['fullname'] ?? null],
    [covid_icon('addr'),    'ที่อยู่',             $row['informaddr'] ?? null],
    [covid_icon('tel'),     'เบอร์โทร',            $row['hometel'] ?? null],
    [covid_icon('cid'),     'เลขบัตรประชาชน',      $row['cid'] ?? null],
    [covid_icon('vstdate'), 'วันที่เข้ารับบริการ',   $row['vstdate'] ?? null],
    [covid_icon('doctor'),  'แพทย์ผู้ตรวจ',         $row['doctor'] ?? null],
    [covid_icon('icd10'),   'ICD-10',              $row['pdx'] ?? null],
    [covid_icon('lab'),     'ผลตรวจ',              $row['lab_order_result'] ?? null],
  ];
  foreach ($pairs as $i => [$icon,$label,$val]) {
    if ($i > 0) $rows[] = covid_divider_sm();
    $rows[] = covid_row_compact($icon, $label, $val);
  }

  $singleCard = [
    "type"=>"box","layout"=>"vertical",
    "cornerRadius"=>"14px","paddingAll"=>"12px",
    "backgroundColor"=>"#FFFFFF","borderColor"=>"#E5E7EB","borderWidth"=>"1px",
    "contents"=>$rows
  ];

  $stamp = [
    "type"=>"box","layout"=>"horizontal","margin"=>"md",
    "contents"=>[[ "type"=>"text","text"=>date('Y-m-d H:i'),"size"=>"xs","color"=>"#9CA3AF","align"=>"end","flex"=>1 ]]
  ];

  return [
    "messages" => [[
      "type" => "flex",
      "altText" => "ข้อมูลผู้ป่วยจากโรงพยาบาลเชียงกลาง",
      "contents" => [
        "type" => "bubble",
        "size" => "giga",
        "header" => $header,
        "body" => [
          "type" => "box","layout" => "vertical","spacing" => "sm",
          "contents" => [ $titleRow, $singleCard, $stamp ]
        ],
        "styles" => [ "body" => [ "backgroundColor" => "#F9FAFB" ] ]
      ]
    ]]
  ];
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
