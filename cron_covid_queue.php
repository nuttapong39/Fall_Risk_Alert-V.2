<?php
// cron_covid_queue.php
// ใช้: php cron_covid_queue.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--hosp=XXXXX] [--dry-run]

require_once __DIR__ . '/config.php';

// ---------- CLI args ----------
$args   = getopt('', ['start::','end::','hosp::','dry-run']);
$start  = $args['start'] ?? date('Y-m-d', strtotime('-'.DEFAULT_LOOKBACK_DAYS.' days'));
$end    = $args['end']   ?? date('Y-m-d');
$hosp   = trim($args['hosp'] ?? DEFAULT_HOSP_CODE);
$dryRun = array_key_exists('dry-run', $args);

function logln($msg){ echo '['.date('Y-m-d H:i:s')."] $msg\n"; }

// ---------- Step 1: Ingest new rows into queue ----------
logln("Ingest: $start ~ $end" . ($hosp ? " | hosp=$hosp" : ""));

$where  = [];
$params = [];

$where[] = "ov.vstdate BETWEEN :start AND :end";
$params[':start'] = $start;
$params[':end']   = $end;

$where[] = "l.lab_items_code IN ('3066','3082','3084','3088')";
$where[] = "l.lab_order_result = 'Positive'";

// TODO: ถ้าฐานคุณไม่ได้ใช้ ov.hospmain ให้เปลี่ยนเป็นคอลัมน์ที่ถูกต้อง
if ($hosp !== '') {
  $where[] = "ov.hospmain = :hosp";
  $params[':hosp'] = $hosp;
}

$sqlText = "
  SELECT 
    pt.hn,
    CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
    TIMESTAMPDIFF(YEAR, pt.birthday, CURDATE()) AS age,
    pt.cid,
    pt.informaddr,
    pt.hometel,
    ov.vstdate,
    d.name AS doctor,
    ov.pdx,
    l.lab_order_result,
    h.lab_order_number,
    h.report_date
  FROM lab_order l
  INNER JOIN lab_head h ON l.lab_order_number = h.lab_order_number
  LEFT JOIN vn_stat ov ON ov.vn = h.vn
  LEFT JOIN doctor d ON ov.dx_doctor = d.CODE
  INNER JOIN patient pt ON pt.hn = ov.hn
  LEFT JOIN covid_queue q ON q.lab_order_number = h.lab_order_number
  WHERE " . implode(' AND ', $where) . "
    AND q.lab_order_number IS NULL
  GROUP BY h.lab_order_number, pt.hn, fullname, age, pt.cid, pt.informaddr, pt.hometel, ov.vstdate, d.name, ov.pdx, l.lab_order_result, h.report_date
  ORDER BY h.report_date DESC
";

$stmt = $dbcon->prepare($sqlText);
$stmt->execute($params);
$newRows = $stmt->fetchAll();

logln("Found new rows to queue: " . count($newRows));

if (!$dryRun && !empty($newRows)) {
  $ins = $dbcon->prepare("
    INSERT INTO covid_queue
      (lab_order_number, hn, fullname, age, cid, informaddr, hometel, vstdate, doctor, pdx, lab_order_result, status, attempt, created_at)
    VALUES
      (:lab_order_number, :hn, :fullname, :age, :cid, :informaddr, :hometel, :vstdate, :doctor, :pdx, :lab_order_result, 0, 0, NOW())
    ON DUPLICATE KEY UPDATE lab_order_number = lab_order_number
  ");
  foreach ($newRows as $r) {
    $ins->execute([
      ':lab_order_number' => $r['lab_order_number'],
      ':hn'               => $r['hn'],
      ':fullname'         => $r['fullname'],
      ':age'              => (int)$r['age'],
      ':cid'              => $r['cid'],
      ':informaddr'       => $r['informaddr'],
      ':hometel'          => $r['hometel'],
      ':vstdate'          => $r['vstdate'],
      ':doctor'           => $r['doctor'],
      ':pdx'              => $r['pdx'],
      ':lab_order_result' => $r['lab_order_result'],
    ]);
  }
}

// ---------- Step 2: Send queued rows (status=0) ----------
logln("Sending queued rows...");

$getQ = $dbcon->prepare("
  SELECT * FROM covid_queue 
  WHERE status = 0 
  ORDER BY created_at ASC 
  LIMIT 50
");
$getQ->execute();
$queue = $getQ->fetchAll();

logln("To send: " . count($queue));

function makeTextBox($text){
  return [
    "type" => "box",
    "layout" => "horizontal",
    "margin" => "8px",
    "contents" => [[
      "type" => "text",
      "text" => $text,
      "size" => "14.5px",
      "align" => "start",
      "gravity" => "center",
      "wrap" => true,
      "weight" => "regular",
      "flex" => 2
    ]]
  ];
}

$updOk = $dbcon->prepare("
  UPDATE covid_queue
  SET status=1, sent_at=NOW(), last_attempt_at=NOW(), attempt=attempt+1, last_error=NULL, line_message_id=:mid
  WHERE id=:id
");

$updErr = $dbcon->prepare("
  UPDATE covid_queue
  SET last_attempt_at=NOW(), attempt=attempt+1, last_error=:err
  WHERE id=:id
");

foreach ($queue as $row) {
  // สร้างเนื้อหา Flex
  $lines = [];
  $lines[] = makeTextBox("HN: " . $row['hn']);
  $lines[] = makeTextBox("คนไข้: " . $row['fullname']);
  $lines[] = makeTextBox("ที่อยู่: " . $row['informaddr']);
  $lines[] = makeTextBox("เบอร์โทร: " . $row['hometel']);
  $lines[] = makeTextBox("เลขบัตรประชาชน: " . $row['cid']);
  $lines[] = makeTextBox("วันที่เข้ารับบริการ: " . $row['vstdate']);
  $lines[] = makeTextBox("แพทย์ผู้ตรวจ: " . $row['doctor']);
  $lines[] = makeTextBox("ICD10: " . $row['pdx']);
  $lines[] = makeTextBox("ผลตรวจ: " . $row['lab_order_result']);

  $payload = [
    "messages" => [[
      "type" => "flex",
      "altText" => "ข้อมูลผู้ป่วยจากโรงพยาบาลเชียงกลาง",
      "contents" => [
        "type" => "bubble",
        "size" => "mega",
        "header" => [
          "type" => "box",
          "layout" => "vertical",
          "contents" => [[
            "type" => "image",
            "url" => LINE_HEADER_URL,
            "size" => "full",
            "aspectRatio" => "3120:885",
            "aspectMode" => "cover"
          ]],
          "paddingAll" => "0px"
        ],
        "body" => [
          "type" => "box",
          "layout" => "vertical",
          "contents" => [
            [
              "type" => "box",
              "layout" => "vertical",
              "margin" => "8px",
              "contents" => [[
                "type" => "image",
                "url" => LINE_ICON_URL,
                "size" => "full",
                "aspectMode" => "cover",
                "align" => "center"
              ]],
              "cornerRadius" => "100px",
              "maxWidth" => "92px",
              "offsetStart" => "93px"
            ],
            [
              "type" => "box",
              "layout" => "vertical",
              "cornerRadius" => "15px",
              "margin" => "xs",
              "paddingTop" => "lg",
              "paddingBottom" => "lg",
              "paddingStart" => "8px",
              "paddingEnd" => "8px",
              "backgroundColor" => "#DCE7FF",
              "contents" => [[
                "type" => "text",
                "text" => LINE_TITLE,
                "weight" => "bold",
                "size" => "lg",
                "align" => "center",
                "color" => "#2D2D2D",
                "adjustMode" => "shrink-to-fit"
              ]]
            ],
            [
              "type" => "box",
              "layout" => "vertical",
              "margin" => "sm",
              "contents" => [[
                "type" => "text",
                "text" => "-------------------------------------",
                "weight" => "bold",
                "size" => "14px",
                "align" => "center"
              ]]
            ],

            ...$lines,

            [
              "type" => "box",
              "layout" => "vertical",
              "margin" => "sm",
              "contents" => [[
                "type" => "text",
                "text" => "-------------------------------------",
                "weight" => "bold",
                "size" => "14px",
                "align" => "center"
              ]]
            ],
          ]
        ]
      ]
    ]]
  ];

  if ($dryRun) {
    logln("DRY-RUN send lab_order_number={$row['lab_order_number']} (id={$row['id']})");
    continue;
  }

  // ส่งไปไลน์
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => [
      'client-key: ' . LINE_CLIENT_KEY,
      'secret-key: ' . LINE_SECRET_KEY,
      'Content-Type: application/json'
    ],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err) {
    logln("ERROR (id={$row['id']}): CURL $err");
    $updErr->execute([':id' => $row['id'], ':err' => $err]);
    continue;
  }

  $respJson = json_decode($resp, true);
  $msgId = $respJson['messageId'] ?? ($respJson['data']['messageId'] ?? null);

  if ($code >= 200 && $code < 300) {
    logln("OK (id={$row['id']}) status=$code msgId=" . ($msgId ?? '-'));
    $updOk->execute([':id' => $row['id'], ':mid' => $msgId]);
  } else {
    $errMsg = "HTTP $code: " . substr($resp, 0, 500);
    logln("FAIL (id={$row['id']}): $errMsg");
    $updErr->execute([':id' => $row['id'], ':err' => $errMsg]);
  }
}

logln("Done.");
