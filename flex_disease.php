<?php
/**
 * flex_disease.php
 * ไลบรารีกลาง: ประกอบ LINE Flex message สำหรับโรคติดต่อ (dengue / lepto / scrub)
 *
 * Usage: buildDiseasePayload(array $row, string $type): array   ($type = 'dengue'|'lepto'|'scrub')
 *
 * ดีไซน์ (config-driven): หัวการ์ด gradient + สี/ข้อความจาก secrets/flex_themes.json (แก้ผ่าน flex_editor.php)
 *   - ไม่มีรูป banner (ใช้ได้หลาย รพ.) · ไม่มี section คำแนะนำ
 *   - label เทา + value เข้ม, ช่องว่างแบ่ง section, icon monochrome (☎)
 *   - สีทั้งหมดเป็น hex เท่านั้น (LINE reject rgba/ชื่อสีเงียบ ๆ)
 */

require_once __DIR__ . '/flex_theme.php';

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

/* ─── MAIN builder (config-driven, clean) ────────────────────────────────── */
if (!function_exists('buildDiseasePayload')) {
  function buildDiseasePayload(array $row, string $type = 'dengue'): array {
    $row = row_to_utf8($row);
    $t   = flex_theme($type);
    $g   = flex_theme_global();
    $L   = $g['labels'];

    $hn       = $row['hn']       ?? '-';
    $fullname = $row['fullname'] ?? '-';
    $age      = $row['age']      ?? '';
    $sex      = $row['sex']      ?? '';
    $cid      = $row['cid']      ?? '-';
    $address  = $row['address']  ?? ($row['informaddr'] ?? '-');
    $tel      = $row['hometel']  ?? '-';
    $vstdate  = dis_thai_date($row['vstdate'] ?? null);
    $doctor   = $row['doctor']   ?? '-';
    $disease  = $row['disease']  ?? '-';
    $icd10    = $row['icd10']    ?? '-';
    $result   = $row['result']   ?? '-';
    $vn       = $row['vn']       ?? '';

    $accent   = $t['accent'];
    $accentBg = (strlen($accent) === 7) ? $accent.'22' : '#FEE2E2';  // สีพื้น pill = accent จาง (~13% alpha)
    $ageSex = trim(($age!==''?"{$age} ปี":'').($age!==''&&$sex!==''?' · ':'').($sex!==''?(string)$sex:'')) ?: '-';

    // kv row: label เทา (flex 4) / value เข้ม align end (flex 6)
    $kv = function(string $k, ?string $v, array $o = []) {
      $v = ($v === null || $v === '') ? '-' : (string)$v;
      return ["type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"md","contents"=>[
        ["type"=>"text","text"=>$k,"size"=>"sm","color"=>"#6B7280","flex"=>4],
        ["type"=>"text","text"=>$v,"size"=>$o['size']??"sm","color"=>$o['color']??"#111827",
         "weight"=>$o['weight']??"bold","align"=>"end","wrap"=>true,"flex"=>6],
      ]];
    };
    // section label (เทาตัวเล็ก + ช่องว่างด้านบน)
    $seclabel = fn(string $s) => ["type"=>"text","text"=>$s,"size"=>"xs","weight"=>"bold","color"=>"#9CA3AF","margin"=>"xl"];

    /* HEADER — gradient + watermark icon (absolute, ข้างหลัง text เมื่อมี bg_icon_url) */
    $sub = trim(($t['subtitle']??'') . ((($t['subtitle']??'')!=='' && ($t['urgency']??'')!=='') ? '   ·   ' : '') . ($t['urgency']??''));
    $headerContents = [];
    if (($t['bg_icon_url'] ?? '') !== '') {
      $headerContents[] = ["type"=>"image","url"=>$t['bg_icon_url'],
        "size"=>"72px","aspectMode"=>"cover","aspectRatio"=>"1:1",
        "position"=>"absolute","offsetTop"=>"6px","offsetEnd"=>"4px"];
    }
    $headerContents[] = ["type"=>"text","text"=>$t['title'],"size"=>"md","weight"=>"bold","color"=>"#FFFFFF","wrap"=>true];
    if ($sub !== '') {
      $headerContents[] = ["type"=>"text","text"=>$sub,"size"=>"xs","color"=>"#FFFFFFDD","wrap"=>true,"margin"=>"xs"];
    }
    $header = ["type"=>"box","layout"=>"vertical","paddingAll"=>"13px","cornerRadius"=>"14px",
               "background"=>flex_gradient($t),"contents"=>$headerContents];

    /* BODY */
    $c = [];
    // ผู้ป่วย
    $c[] = ["type"=>"text","text"=>$L['sec_patient'],"size"=>"xs","weight"=>"bold","color"=>"#9CA3AF"];
    $c[] = ["type"=>"text","text"=>"HN {$hn}","size"=>"sm","weight"=>"bold","color"=>"#111827","margin"=>"xs"];
    $c[] = ["type"=>"text","text"=>$fullname,"size"=>"md","weight"=>"bold","color"=>"#111827","wrap"=>true,"margin"=>"xs"];
    $c[] = ["type"=>"text","text"=>"{$ageSex}   ·   {$cid}","size"=>"xs","color"=>"#6B7280","wrap"=>true,"margin"=>"xs"];
    // การวินิจฉัย
    $c[] = $seclabel($L['sec_diagnosis']);
    $c[] = ["type"=>"box","layout"=>"baseline","margin"=>"sm","contents"=>[
        ["type"=>"text","text"=>$L['icd'],"size"=>"sm","weight"=>"bold","color"=>$accent,"flex"=>1],
        ["type"=>"text","text"=>$icd10,"size"=>"xxl","weight"=>"bold","color"=>"#111827","align"=>"end","flex"=>0],
      ]];
    $c[] = $kv($L['disease'], $disease);
    // ผล LAB — pill โค้ง (bg accent จาง)
    $c[] = ["type"=>"box","layout"=>"horizontal","spacing"=>"sm","margin"=>"md","contents"=>[
        ["type"=>"text","text"=>$L['lab'],"size"=>"sm","color"=>"#6B7280","flex"=>1],
        ["type"=>"box","layout"=>"horizontal","flex"=>0,
         "paddingStart"=>"10px","paddingEnd"=>"10px","paddingTop"=>"3px","paddingBottom"=>"3px",
         "backgroundColor"=>$accent,"cornerRadius"=>"6px",
         "contents"=>[["type"=>"text","text"=>($result===''?'-':$result),"size"=>"sm","weight"=>"bold","color"=>"#FFFFFF","wrap"=>true]]],
      ]];
    $c[] = $kv($L['vstdate'], $vstdate, ['weight'=>'regular','color'=>'#374151']);
    $c[] = $kv($L['doctor'], $doctor, ['weight'=>'regular','color'=>'#374151']);
    // ติดตาม
    $c[] = $seclabel($L['sec_contact']);
    $c[] = $kv($L['address'], $address, ['weight'=>'regular','color'=>'#374151']);
    $c[] = ["type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"md","contents"=>[
        ["type"=>"text","text"=>"☎","size"=>"sm","color"=>"#9CA3AF","flex"=>0],
        ["type"=>"text","text"=>($tel?:'-'),"size"=>"md","weight"=>"bold","color"=>"#111827","flex"=>1,"margin"=>"sm"],
      ]];

    $content = ["type"=>"box","layout"=>"vertical","spacing"=>"none",
                "paddingStart"=>"4px","paddingEnd"=>"4px","paddingTop"=>"14px","contents"=>$c];

    /* FOOTER (ในการ์ด) */
    $footText = $g['footer_text'] . (($g['hospital_name']??'')!=='' ? '  ·  '.$g['hospital_name'] : '');
    $footer = ["type"=>"box","layout"=>"horizontal","paddingTop"=>"14px","paddingStart"=>"4px","paddingEnd"=>"4px","contents"=>[
        ["type"=>"text","text"=>$footText,"size"=>"xxs","color"=>"#9CA3AF","flex"=>1,"wrap"=>true],
        ["type"=>"text","text"=>date('j M Y'),"size"=>"xxs","color"=>"#9CA3AF","align"=>"end","flex"=>0],
      ]];

    // การ์ด: bubble mega + body ขาว + header gradient โค้งมนลอยในการ์ด → ขอบโค้งขึ้น + เล็กลง
    $bubble = ["type"=>"bubble","size"=>"mega",
      "body"=>["type"=>"box","layout"=>"vertical","spacing"=>"none","paddingAll"=>"12px","backgroundColor"=>"#FFFFFF",
               "contents"=>[$header,$content,$footer]]];

    $altText = sprintf('[แจ้งเตือน] %s HN %s %s (%s)', $t['title'], $hn, $fullname, $icd10);
    if (mb_strlen($altText) > 400) $altText = mb_substr($altText, 0, 397).'...';
    return ["messages"=>[["type"=>"flex","altText"=>$altText,"contents"=>$bubble]]];
  }
}

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
