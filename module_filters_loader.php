<?php
/**
 * module_filters_loader.php
 * ตัวโหลด "เงื่อนไขดึงข้อมูล" (source filter) ราย module จาก secrets/module_filters.json
 * มี default ฝังในตัว (= ค่าปัจจุบันของ sources/*_source.php) → ไฟล์หาย/ว่าง = พฤติกรรมเดิม
 *
 * ใช้เป็น single source of truth ให้ทั้ง worker (อัตโนมัติ) และปุ่ม Import (manual)
 * แก้ผ่าน UI: ปุ่ม+modal ในหน้า *_queue_ui.php → module_filter_action.php
 *
 * pattern entry (สำหรับ pdx/ICD): {t:'exact',v} | {t:'prefix',v} | {t:'range',from,to}
 */

if (!defined('MODULE_FILTERS_FILE')) {
  define('MODULE_FILTERS_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'module_filters.json');
}

/* ── ค่า default = เงื่อนไขปัจจุบันเป๊ะ (ห้ามเปลี่ยน = behavior-preserving) ─────── */
$GLOBALS['MODULE_FILTER_DEFAULTS'] = [
  'covid'    => ['lab_codes' => ['3066','3082','3084','3088']],
  'accident' => ['pttypes'   => ['33','35','36','39']],
  'drug'     => ['icodes'    => ['1483860']],
  'sexual'   => ['lab_code'  => '2811'],
  'lepto'    => ['lab_code'  => '290',  'pdx_codes' => ['A270','A278','A279','A418']],
  'scrub'    => ['lab_code'  => '291',  'results' => ['Positive'], 'pdx_codes' => ['A753']],
  'dengue'   => ['lab_code'  => '2891', 'results' => ['Positive','Weakly Positive'],
                 'pdx' => [['t'=>'range','from'=>'A90','to'=>'A99']]],
  'patient'  => ['icd' => [
                   ['t'=>'exact','v'=>'T71'],
                   ['t'=>'prefix','v'=>'X60'],['t'=>'prefix','v'=>'X61'],['t'=>'prefix','v'=>'X62'],
                   ['t'=>'prefix','v'=>'X63'],['t'=>'prefix','v'=>'X64'],['t'=>'prefix','v'=>'X65'],
                   ['t'=>'prefix','v'=>'X66'],['t'=>'prefix','v'=>'X67'],['t'=>'prefix','v'=>'X68'],
                   ['t'=>'prefix','v'=>'X69'],['t'=>'prefix','v'=>'X70'],['t'=>'prefix','v'=>'X84'],
                 ]],
  'fracture' => ['min_age' => 60, 'icd' => [
                   ['t'=>'range','from'=>'W00','to'=>'W19'],
                   ['t'=>'prefix','v'=>'S720'],['t'=>'prefix','v'=>'S721'],['t'=>'prefix','v'=>'S722'],
                   ['t'=>'prefix','v'=>'S525'],['t'=>'prefix','v'=>'S526'],['t'=>'prefix','v'=>'S422'],
                   ['t'=>'prefix','v'=>'S220'],['t'=>'prefix','v'=>'S221'],['t'=>'prefix','v'=>'S320'],
                   ['t'=>'prefix','v'=>'S327'],
                 ]],
  'pharm_lab'=> ['rules' => [
                   ['codes'=>['539'],       'op'=>'>=','value'=>5,   'also_text'=>false],
                   ['codes'=>['2368'],      'op'=>'>', 'value'=>150, 'also_text'=>true],
                   ['codes'=>['697','2388'],'op'=>'>', 'value'=>1.2, 'also_text'=>true],
                   ['codes'=>['2370'],      'op'=>'>', 'value'=>20,  'also_text'=>true],
                 ]],
];

/* ── schema: อธิบายฟิลด์ที่ modal แก้ได้ (ขับทั้ง UI + parse ตอน save) ─────────── */
$GLOBALS['MODULE_FILTER_SCHEMA'] = [
  'covid'    => ['label'=>'COVID-19',              'fields'=>[['key'=>'lab_codes','type'=>'codes','label'=>'รหัส Lab (lab_items_code)','hint'=>'ผลตรวจ Positive']]],
  'accident' => ['label'=>'อุบัติเหตุ พ.ร.บ.',     'fields'=>[['key'=>'pttypes','type'=>'codes','label'=>'รหัสสิทธิ (pttype)','hint'=>'ผู้ป่วยใน ipt']]],
  'drug'     => ['label'=>'ยาอันตราย',             'fields'=>[['key'=>'icodes','type'=>'codes','label'=>'รหัสยา (icode)']]],
  'sexual'   => ['label'=>'โรคติดต่อทางเพศ',        'fields'=>[['key'=>'lab_code','type'=>'single','label'=>'รหัส Lab (lab_items_code)']]],
  'lepto'    => ['label'=>'เลปโตสไปโรซิส',          'fields'=>[
                   ['key'=>'lab_code','type'=>'single','label'=>'รหัส Lab (lab_items_code)'],
                   ['key'=>'pdx_codes','type'=>'codes','label'=>'รหัส ICD (pdx)'],
                 ]],
  'scrub'    => ['label'=>'สครับไทฟัส',            'fields'=>[
                   ['key'=>'lab_code','type'=>'single','label'=>'รหัส Lab (lab_items_code)'],
                   ['key'=>'results','type'=>'results','label'=>'ผล Lab ที่รับ (lab_order_result)'],
                   ['key'=>'pdx_codes','type'=>'codes','label'=>'รหัส ICD (pdx)'],
                 ]],
  'dengue'   => ['label'=>'ไข้เลือดออก',           'fields'=>[
                   ['key'=>'lab_code','type'=>'single','label'=>'รหัส Lab (lab_items_code)'],
                   ['key'=>'results','type'=>'results','label'=>'ผล Lab ที่รับ (lab_order_result)'],
                   ['key'=>'pdx','type'=>'patterns','label'=>'ICD (pdx/dx0-3)','hint'=>'ช่วง A90-A99 · prefix ใส่ * · ระบุตรง ๆ'],
                 ]],
  'patient'  => ['label'=>'จิตเวช/ทำร้ายตนเอง',    'fields'=>[
                   ['key'=>'icd','type'=>'patterns','label'=>'ICD (pdx/dx0-3)','hint'=>'เช่น T71 · X60* (prefix) · A90-A99 (ช่วง)'],
                 ]],
  'fracture' => ['label'=>'พลัดตก/หกล้ม',          'fields'=>[
                   ['key'=>'min_age','type'=>'int','label'=>'อายุขั้นต่ำ (ปี)'],
                   ['key'=>'icd','type'=>'patterns','label'=>'ICD (pdx)','hint'=>'ช่วง W00-W19 · prefix เช่น S720*'],
                 ]],
  'pharm_lab'=> ['label'=>'Lab วิกฤต ห้องยา',      'fields'=>[['key'=>'rules','type'=>'rules','label'=>'เกณฑ์ค่าวิกฤตต่อรหัส Lab']]],
];

/* ── โหลด store (รอบเดียว) ─────────────────────────────────────────────────── */
if (!function_exists('_module_filters_stored')) {
  function _module_filters_stored(): array {
    static $data = null;
    if ($data !== null) return $data;
    $data = [];
    if (is_readable(MODULE_FILTERS_FILE)) {
      $j = json_decode(@file_get_contents(MODULE_FILTERS_FILE), true);
      if (is_array($j)) $data = $j;
    }
    return $data;
  }
}

/** เงื่อนไขของ module (merge stored ทับ default ระดับ field) */
if (!function_exists('module_filter')) {
  function module_filter(string $mod): array {
    $def = $GLOBALS['MODULE_FILTER_DEFAULTS'][$mod] ?? [];
    $st  = _module_filters_stored()[$mod] ?? [];
    if (!is_array($st)) $st = [];
    // ใช้ค่า stored เฉพาะ key ที่ schema รู้จัก + ไม่ว่าง; นอกนั้น fallback default
    $out = $def;
    foreach ($def as $k => $_) {
      if (array_key_exists($k, $st) && $st[$k] !== null && $st[$k] !== '' && $st[$k] !== []) {
        $out[$k] = $st[$k];
      }
    }
    return $out;
  }
}
if (!function_exists('module_filter_schema')) {
  function module_filter_schema(string $mod): array { return $GLOBALS['MODULE_FILTER_SCHEMA'][$mod] ?? []; }
}
if (!function_exists('module_filter_defaults')) {
  function module_filter_defaults(string $mod): array { return $GLOBALS['MODULE_FILTER_DEFAULTS'][$mod] ?? []; }
}

/* ── helpers สร้าง SQL อย่างปลอดภัย (ใช้ตอน refactor source ใน phase ถัดไป) ──── */
if (!function_exists('mf_codes')) {
  /** clean list ของรหัส: trim, เก็บเฉพาะ [A-Za-z0-9._-], ตัดว่าง, unique (กัน injection ชั้นสอง) */
  function mf_codes(array $arr): array {
    $out = [];
    foreach ($arr as $x) {
      $x = trim((string)$x);
      $x = preg_replace('/[^A-Za-z0-9._-]/', '', $x);
      // เก็บเป็น string เสมอ + dedup ด้วย in_array (กัน PHP แปลง key "3066"→int)
      if ($x !== '' && !in_array($x, $out, true)) $out[] = (string)$x;
    }
    return $out;
  }
}
if (!function_exists('mf_texts')) {
  /** clean list ของค่าข้อความ (เช่น ผล Lab 'Weakly Positive') — คงช่องว่าง/+/- แต่ตัดอักขระอันตราย */
  function mf_texts(array $arr): array {
    $out = [];
    foreach ($arr as $x) {
      $x = trim((string)$x);
      $x = preg_replace('/[^\p{L}\p{N} .+_\-]/u', '', $x);  // ตัด quote/;/\\/control ออก (bind อยู่แล้วแต่กันไว้)
      $x = trim($x);
      if ($x !== '' && !in_array($x, $out, true)) $out[] = (string)$x;   // คง string + dedup
    }
    return $out;
  }
}
if (!function_exists('mf_in')) {
  /**
   * สร้าง "col IN (?,?..)" + เติมค่าลง $params (bind) — ปลอดภัยจาก injection
   * ถ้า list ว่าง → คืน '1=0' (ไม่ match อะไร)
   */
  function mf_in(array &$params, string $col, array $list): string {
    $list = mf_codes($list);
    if (!$list) return '1=0';
    $ph = implode(',', array_fill(0, count($list), '?'));
    foreach ($list as $v) $params[] = $v;
    return "$col IN ($ph)";
  }
}

/* ── แปลง pattern list <-> text (mini-syntax) สำหรับ modal/action ──────────── */
if (!function_exists('mf_patterns_to_text')) {
  /** [{t:exact,v},{t:prefix,v},{t:range,from,to}] → "T71, X60*, A90-A99" */
  function mf_patterns_to_text(array $patterns): string {
    $parts = [];
    foreach ($patterns as $p) {
      $t = $p['t'] ?? 'exact';
      if ($t === 'range')      $parts[] = ($p['from'] ?? '') . '-' . ($p['to'] ?? '');
      elseif ($t === 'prefix') $parts[] = ($p['v'] ?? '') . '*';
      else                     $parts[] = ($p['v'] ?? '');
    }
    return implode(', ', array_filter($parts, fn($x) => trim($x, ' -*') !== ''));
  }
}
if (!function_exists('mf_text_to_patterns')) {
  /** "T71, X60*, A90-A99" → [{t:exact,v},{t:prefix,v},{t:range,from,to}] (validate alnum) */
  function mf_text_to_patterns(string $text): array {
    $clean = fn($s) => preg_replace('/[^A-Za-z0-9]/', '', (string)$s);
    $out = [];
    foreach (preg_split('/[\s,]+/', trim($text)) as $tok) {
      $tok = trim($tok);
      if ($tok === '') continue;
      if (strpos($tok, '-') !== false && substr($tok, -1) !== '*') {
        [$a, $b] = array_pad(explode('-', $tok, 2), 2, '');
        $a = $clean($a); $b = $clean($b);
        if ($a !== '' && $b !== '') { $out[] = ['t'=>'range','from'=>$a,'to'=>$b]; }
      } elseif (substr($tok, -1) === '*') {
        $v = $clean(substr($tok, 0, -1));
        if ($v !== '') $out[] = ['t'=>'prefix','v'=>$v];
      } else {
        $v = $clean($tok);
        if ($v !== '') $out[] = ['t'=>'exact','v'=>$v];
      }
    }
    return $out;
  }
}
