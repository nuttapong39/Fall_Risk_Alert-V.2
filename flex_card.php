<?php
/**
 * flex_card.php — ตัวประกอบ LINE Flex card กลาง (ใช้ร่วมทุก module)
 *   flex_render_card($module, $data): array
 *   layout สะอาดเดียวกัน (gradient header โค้ง + section เว้นวรรค + pill accent + mega)
 *   theme/สี/ข้อความหัว-footer มาจาก flex_theme() (แก้ผ่าน flex_editor.php)
 *
 * $data = [
 *   'patient' => ['hn','fullname','agesex','cid'],
 *   'mid'     => [ ['label'=>'การวินิจฉัย','items'=>[ [type,label,value], ... ]], ... ],
 *   'contact' => ['address','phone'],
 *   'alt'     => 'altText',
 * ]
 * item type: 'big' (ICD ตัวใหญ่) | 'pill' (badge สี accent) | 'kv' (label/value เข้ม) | 'kvlight' (value จาง)
 */
require_once __DIR__ . '/flex_theme.php';

if (!function_exists('flex_agesex')) {
  function flex_agesex($age, $sex): string {
    $age = trim((string)$age); $sex = trim((string)$sex);
    return (trim(($age!==''?"{$age} ปี":'').($age!==''&&$sex!==''?' · ':'').($sex!==''?$sex:'')) ?: '-');
  }
}
if (!function_exists('flex_thai_date')) {
  function flex_thai_date($ymd): string {
    $ymd = trim((string)$ymd);
    if ($ymd === '') return '-';
    $ts = strtotime($ymd);
    if ($ts === false) return $ymd;
    static $m = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',
                 7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    return sprintf('%d %s %d', (int)date('j',$ts), $m[(int)date('n',$ts)]??'', (int)date('Y',$ts)+543);
  }
}

if (!function_exists('flex_render_card')) {
  function flex_render_card(string $module, array $d): array {
    $t = flex_theme($module);
    $g = flex_theme_global();
    $L = $g['labels'];
    $accent = $t['accent'];

    $kv = function(string $k, ?string $v, array $o = []) {
      $v = ($v === null || $v === '') ? '-' : (string)$v;
      return ["type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"md","contents"=>[
        ["type"=>"text","text"=>$k,"size"=>"sm","color"=>"#6B7280","flex"=>4],
        ["type"=>"text","text"=>$v,"size"=>$o['size']??"sm","color"=>$o['color']??"#111827",
         "weight"=>$o['weight']??"bold","align"=>"end","wrap"=>true,"flex"=>6],
      ]];
    };
    $seclabel = fn(string $s) => ["type"=>"text","text"=>$s,"size"=>"xs","weight"=>"bold","color"=>"#9CA3AF","margin"=>"xl"];

    $mkItem = function(array $it) use ($accent, $kv) {
      $type = $it[0] ?? 'kv'; $k = (string)($it[1] ?? ''); $v = $it[2] ?? '';
      $v = ($v === '' || $v === null) ? '-' : (string)$v;
      if ($type === 'big') {
        return ["type"=>"box","layout"=>"baseline","margin"=>"sm","contents"=>[
          ["type"=>"text","text"=>$k,"size"=>"sm","weight"=>"bold","color"=>$accent,"flex"=>1],
          ["type"=>"text","text"=>$v,"size"=>"xxl","weight"=>"bold","color"=>"#111827","align"=>"end","flex"=>0],
        ]];
      }
      if ($type === 'pill') {
        // ค่าผลตรวจ = ข้อความสี accent ตัวหนา (flex:0 box ใน LINE ยุบจนตัวอักษรหาย จึงใช้ text)
        return ["type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"md","contents"=>[
          ["type"=>"text","text"=>$k,"size"=>"sm","color"=>"#6B7280","flex"=>4],
          ["type"=>"text","text"=>$v,"size"=>"md","weight"=>"bold","color"=>$accent,"align"=>"end","wrap"=>true,"flex"=>6],
        ]];
      }
      if ($type === 'kvlight') return $kv($k, $v, ['weight'=>'regular','color'=>'#374151']);
      return $kv($k, $v);
    };

    /* HEADER — gradient (+ watermark absolute ถ้ามี bg_icon_url) */
    $sub = trim(($t['subtitle']??'') . ((($t['subtitle']??'')!=='' && ($t['urgency']??'')!=='') ? '   ·   ' : '') . ($t['urgency']??''));
    $hc = [];
    if (($t['bg_icon_url'] ?? '') !== '') {
      $hc[] = ["type"=>"image","url"=>$t['bg_icon_url'],"size"=>"72px","aspectMode"=>"cover","aspectRatio"=>"1:1",
               "position"=>"absolute","offsetTop"=>"6px","offsetEnd"=>"4px"];
    }
    $hc[] = ["type"=>"text","text"=>$t['title'],"size"=>"md","weight"=>"bold","color"=>"#FFFFFF","wrap"=>true];
    if ($sub !== '') $hc[] = ["type"=>"text","text"=>$sub,"size"=>"xs","color"=>"#FFFFFFDD","wrap"=>true,"margin"=>"xs"];
    $header = ["type"=>"box","layout"=>"vertical","paddingAll"=>"13px","cornerRadius"=>"14px",
               "background"=>flex_gradient($t),"contents"=>$hc];

    /* CONTENT */
    $p = $d['patient'] ?? [];
    $c = [];
    $c[] = ["type"=>"text","text"=>$L['sec_patient'],"size"=>"xs","weight"=>"bold","color"=>"#9CA3AF"];
    $c[] = ["type"=>"text","text"=>"HN ".($p['hn'] ?? '-'),"size"=>"sm","weight"=>"bold","color"=>"#111827","margin"=>"xs"];
    $c[] = ["type"=>"text","text"=>($p['fullname'] ?? '-'),"size"=>"md","weight"=>"bold","color"=>"#111827","wrap"=>true,"margin"=>"xs"];
    $c[] = ["type"=>"text","text"=>($p['agesex'] ?? '-')."   ·   ".($p['cid'] ?? '-'),"size"=>"xs","color"=>"#6B7280","wrap"=>true,"margin"=>"xs"];
    foreach (($d['mid'] ?? []) as $sec) {
      $c[] = $seclabel($sec['label'] ?? '');
      foreach (($sec['items'] ?? []) as $it) $c[] = $mkItem($it);
    }
    $ct = $d['contact'] ?? [];
    if (($ct['address'] ?? '') !== '' || ($ct['phone'] ?? '') !== '') {
      $c[] = $seclabel($L['sec_contact']);
      $c[] = $kv($L['address'], $ct['address'] ?? '-', ['weight'=>'regular','color'=>'#374151']);
      $c[] = ["type"=>"box","layout"=>"baseline","spacing"=>"sm","margin"=>"md","contents"=>[
          ["type"=>"text","text"=>"☎","size"=>"sm","color"=>"#9CA3AF","flex"=>0],
          ["type"=>"text","text"=>(($ct['phone'] ?? '') ?: '-'),"size"=>"md","weight"=>"bold","color"=>"#111827","flex"=>1,"margin"=>"sm"],
        ]];
    }
    $content = ["type"=>"box","layout"=>"vertical","spacing"=>"none",
                "paddingStart"=>"4px","paddingEnd"=>"4px","paddingTop"=>"14px","contents"=>$c];

    /* FOOTER */
    $footText = $g['footer_text'] . (($g['hospital_name']??'')!=='' ? '  ·  '.$g['hospital_name'] : '');
    $footer = ["type"=>"box","layout"=>"horizontal","paddingTop"=>"14px","paddingStart"=>"4px","paddingEnd"=>"4px","contents"=>[
        ["type"=>"text","text"=>$footText,"size"=>"xxs","color"=>"#9CA3AF","flex"=>1,"wrap"=>true],
        ["type"=>"text","text"=>date('j M Y'),"size"=>"xxs","color"=>"#9CA3AF","align"=>"end","flex"=>0],
      ]];

    $bubble = ["type"=>"bubble","size"=>"mega",
      "body"=>["type"=>"box","layout"=>"vertical","spacing"=>"none","paddingAll"=>"12px","backgroundColor"=>"#FFFFFF",
               "contents"=>[$header,$content,$footer]]];

    $alt = $d['alt'] ?? ('[แจ้งเตือน] '.$t['title']);
    if (mb_strlen($alt) > 400) $alt = mb_substr($alt, 0, 397).'...';
    return ["messages"=>[["type"=>"flex","altText"=>$alt,"contents"=>$bubble]]];
  }
}
