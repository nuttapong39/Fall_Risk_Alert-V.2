<?php
/**
 * flex_disease.php
 * ไลบรารีกลาง: ประกอบ LINE Flex message สำหรับโรคติดต่อ (dengue / lepto / scrub)
 * Usage: buildDiseasePayload(array $row, string $type): array   ($type = 'dengue'|'lepto'|'scrub')
 *
 * ดีไซน์/สี/ข้อความ มาจาก flex_theme() (แก้ผ่าน flex_editor.php) · layout ใช้ flex_render_card()
 */

require_once __DIR__ . '/flex_card.php';

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

/* ─── MAIN builder (thin — layout อยู่ใน flex_render_card) ─────────────────── */
if (!function_exists('buildDiseasePayload')) {
  function buildDiseasePayload(array $row, string $type = 'dengue'): array {
    $row = row_to_utf8($row);
    $L   = flex_theme_global()['labels'];
    $hn  = $row['hn'] ?? '-'; $fullname = $row['fullname'] ?? '-'; $icd10 = $row['icd10'] ?? '-';
    return flex_render_card($type, [
      'patient' => [
        'hn'       => $hn,
        'fullname' => $fullname,
        'agesex'   => flex_agesex($row['age'] ?? '', $row['sex'] ?? ''),
        'cid'      => $row['cid'] ?? '-',
      ],
      'mid' => [[
        'label' => $L['sec_diagnosis'],
        'items' => [
          ['big',     $L['icd'],     $icd10],
          ['kv',      $L['disease'], $row['disease'] ?? '-'],
          ['pill',    $L['lab'],     $row['result'] ?? '-'],
          ['kvlight', $L['vstdate'], dis_thai_date($row['vstdate'] ?? null)],
          ['kvlight', $L['doctor'],  $row['doctor'] ?? '-'],
        ],
      ]],
      'contact' => [
        'address' => $row['address'] ?? ($row['informaddr'] ?? '-'),
        'phone'   => $row['hometel'] ?? '-',
      ],
      'alt' => sprintf('[แจ้งเตือน] %s HN %s %s (%s)', flex_theme($type)['title'], $hn, $fullname, $icd10),
    ]);
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
