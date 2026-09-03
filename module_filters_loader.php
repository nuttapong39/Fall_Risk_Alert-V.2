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
  // HAD Alert (High Alert Drug) — default = รายการยา HAD จริงของ รพ.
  // ยืนยันจากข้อมูลจริงใน HOSxP: drugitems.name ของทุก icode นี้มีคำว่า "[HAD]" ต่อท้าย
  // (ต่างจาก lab_hemato ที่ query ต้นแบบเป็น placeholder ให้กรอกเอง — อันนี้เป็นรายการจริง)
  'had'       => ['icodes' => [
                    '1431108','1590004','1481247','1483900','1483983',
                    '1431107','1510045','1483846','1510030','1483896',
                    '1000196','1000010','1000118','1000177','1650074',
                    '1000114','1483814',
                  ]],
  // Hematocrit Alert — กลุ่มเงื่อนไขค่าตัวเลขต่อชุด lab_items_code
  // ops = เซ็ตของ lt/gt/eq ที่ติ๊กไว้ → ประกอบเป็น < > = <= >= <>
  // ในกลุ่มรวมด้วย OR · ระหว่างกลุ่มรวมด้วย OR (ค่าผิดปกติ = ต่ำเกินหรือสูงเกิน)
  // default = ว่าง โดยตั้งใจ → mf_labconds_clause คืน '1=0' ไม่ดึงอะไรเลยจนกว่าจะตั้งค่า
  // (ถ้าใส่รหัสตัวอย่างไว้ แล้วบังเอิญตรงกับรหัสจริงของ รพ. ที่ deploy ไป จะยิงแจ้งเตือน
  //  ผิดเคสทันทีที่เปิด Task Scheduler — ทดสอบแล้วเจอจริง: รหัส 51 คืน 163 เคส/7 วัน
  //  ซึ่งเป็นค่า RBC ไม่ใช่ Hematocrit) ตั้งรหัสจริงผ่านหน้า lab_hemato_queue_ui.php
  'lab_hemato'=> ['groups' => []],
  'pharm_lab'=> ['rules' => [
                   ['name'=>'INR',             'codes'=>['539'],       'op'=>'>=','value'=>5,   'also_text'=>false],
                   ['name'=>'Depakin level',   'codes'=>['2368'],      'op'=>'>', 'value'=>150, 'also_text'=>true],
                   ['name'=>'Lithium level',   'codes'=>['697','2388'],'op'=>'>', 'value'=>1.2, 'also_text'=>true],
                   ['name'=>'Phenytoin level', 'codes'=>['2370'],      'op'=>'>', 'value'=>20,  'also_text'=>true],
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
  'had'       => ['label'=>'HAD Alert (High Alert Drug)', 'fields'=>[
                   ['key'=>'icodes','type'=>'codes','label'=>'รหัสยา (icode)','hint'=>'คั่นด้วย , เช่น 1431108, 1590004'],
                 ]],
  'lab_hemato'=> ['label'=>'Hematocrit Alert',    'fields'=>[
                   ['key'=>'groups','type'=>'labconds','label'=>'เงื่อนไขค่าผลตรวจต่อรหัส Lab',
                    'hint'=>'1 ฟอร์ม = 1 ชุด lab_items_code · ในฟอร์มเพิ่มเงื่อนไขได้หลายแถว (รวมกันแบบ OR) · ติ๊ก < กับ = พร้อมกัน = <='],
                 ]],
  'pharm_lab'=> ['label'=>'Lab วิกฤต ห้องยา',      'fields'=>[
                   ['key'=>'rules','type'=>'rules','label'=>'เกณฑ์ค่าวิกฤตต่อรหัส Lab',
                    'hint'=>'บรรทัดละ 1 เกณฑ์: ชื่อ | รหัส Lab (คั่นด้วย ,) | เงื่อนไข (>= หรือ >) | ค่า | แจ้งเมื่อผลเป็นข้อความ (yes/no)'],
                 ]],
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

/**
 * แปลงค่าดิบจาก modal (f_<key> ใน $post) → cfg array ตาม schema (validate ทุกฟิลด์)
 * ใช้ร่วมโดย module_filter_action.php (save) และ module_filter_preview.php (นับผลก่อนบันทึก)
 */
if (!function_exists('module_filter_parse_post')) {
  function module_filter_parse_post(string $mod, array $post): array {
    $schema = module_filter_schema($mod);
    $cfg = [];
    foreach ($schema['fields'] ?? [] as $f) {
      $k = $f['key']; $rawAny = $post['f_' . $k] ?? '';
      // labconds รับค่าเป็น array ซ้อน — cast เป็น string ตรงนี้จะได้ warning
      // "Array to string conversion" จึง cast เฉพาะชนิดที่เป็น string จริง
      $raw = is_array($rawAny) ? '' : (string)$rawAny;
      switch ($f['type']) {
        case 'codes':    $cfg[$k] = mf_codes(preg_split('/[\s,]+/', trim($raw)));      break;
        case 'results':  $cfg[$k] = mf_texts(preg_split('/[\r\n,]+/', trim($raw)));    break;
        case 'single':   $c = mf_codes([$raw]); $cfg[$k] = $c[0] ?? '';                break;
        case 'int':      $cfg[$k] = max(0, (int)$raw);                                 break;
        case 'patterns': $cfg[$k] = mf_text_to_patterns($raw);                         break;
        case 'rules':    $cfg[$k] = mf_text_to_rules($raw);                            break;
        // labconds รับเป็น array ซ้อน (f_groups[i][codes], f_groups[i][conds][j][ops][]) ไม่ใช่ string
        case 'labconds': $cfg[$k] = mf_parse_labconds($post['f_' . $k] ?? []);          break;
        default:         $cur = module_filter($mod); if (isset($cur[$k])) $cfg[$k] = $cur[$k];
      }
    }
    return $cfg;
  }
}

/* ── helpers สร้าง SQL อย่างปลอดภัย (ใช้โดย sources/*_source.php ทุก module) ──── */
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
if (!function_exists('mf_pdx_clause')) {
  /**
   * สร้างเงื่อนไข ICD จาก pattern list × หลายคอลัมน์ (pdx/dx0-3) แบบ OR + bind params
   *   exact  {v}       → col = ?
   *   prefix {v}       → col LIKE ?      (v + '%')
   *   range  {from,to} → (col >= ? AND col <= ?)
   * คืน "(term OR term ...)" (เติม $params) หรือ '1=0' ถ้าว่าง — ปลอดภัยจาก injection (bind หมด)
   * วน column นอก pattern ใน → คงลำดับเดิม (pdx ก่อน แล้ว dx0..dx3)
   * $upper=true → เทียบแบบ UPPER(col) และ uppercase ค่า (patient เดิมใช้ UPPER)
   */
  function mf_pdx_clause(array &$params, array $cols, array $patterns, bool $upper = false): string {
    $san = fn($s) => preg_replace('/[^A-Za-z0-9]/', '', (string)$s);
    $val = fn($s) => $upper ? strtoupper($s) : $s;
    $terms = [];
    foreach ($cols as $col) {
      $lhs = $upper ? "UPPER($col)" : $col;
      foreach ($patterns as $p) {
        $t = $p['t'] ?? 'exact';
        if ($t === 'range') {
          $from = $san($p['from'] ?? ''); $to = $san($p['to'] ?? '');
          if ($from === '' || $to === '') continue;
          $terms[] = "($lhs >= ? AND $lhs <= ?)";
          $params[] = $val($from); $params[] = $val($to);
        } elseif ($t === 'prefix') {
          $v = $san($p['v'] ?? '');
          if ($v === '') continue;
          $terms[] = "$lhs LIKE ?";
          $params[] = $val($v) . '%';
        } else {
          $v = $san($p['v'] ?? '');
          if ($v === '') continue;
          $terms[] = "$lhs = ?";
          $params[] = $val($v);
        }
      }
    }
    return $terms ? '(' . implode(' OR ', $terms) . ')' : '1=0';
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
if (!function_exists('mf_rules_to_text')) {
  /** [{name,codes,op,value,also_text}] → "ชื่อ | 539 | >= | 5 | no" (บรรทัดละ 1 เกณฑ์) — สำหรับ pharm_lab */
  function mf_rules_to_text(array $rules): string {
    $lines = [];
    foreach ($rules as $r) {
      $name  = (string)($r['name'] ?? '');
      $codes = implode(',', (array)($r['codes'] ?? []));
      $op    = ($r['op'] ?? '>') === '>=' ? '>=' : '>';
      $value = (string)($r['value'] ?? 0);
      $also  = !empty($r['also_text']) ? 'yes' : 'no';
      $lines[] = "{$name} | {$codes} | {$op} | {$value} | {$also}";
    }
    return implode("\n", $lines);
  }
}
if (!function_exists('mf_text_to_rules')) {
  /** "ชื่อ | 539 | >= | 5 | no" (บรรทัดละ 1 เกณฑ์) → [{name,codes,op,value,also_text}] (validate) */
  function mf_text_to_rules(string $text): array {
    $sanName = fn($s) => trim(preg_replace('/[^\p{L}\p{N} .+_\-]/u', '', (string)$s));
    $out = [];
    foreach (preg_split('/\r?\n/', trim($text)) as $line) {
      $line = trim($line);
      if ($line === '') continue;
      $parts = array_map('trim', explode('|', $line));
      [$name, $codesRaw, $op, $value, $also] = array_pad($parts, 5, '');
      $name  = $sanName($name);
      $codes = mf_codes(preg_split('/[\s,]+/', $codesRaw));
      $op    = trim($op) === '>=' ? '>=' : '>';
      $value = is_numeric(trim($value)) ? (float)trim($value) : null;
      $also  = in_array(strtolower(trim($also)), ['yes','1','true'], true);
      if ($name === '' || !$codes || $value === null) continue;
      $out[] = ['name'=>$name, 'codes'=>$codes, 'op'=>$op, 'value'=>$value, 'also_text'=>$also];
    }
    return $out;
  }
}

/* ══ labconds — เงื่อนไขค่าตัวเลขต่อชุด lab code (ใช้โดย lab_hemato) ═══════════
 * โครงที่เก็บ: [ ['codes'=>['51'], 'conds'=>[ ['ops'=>['lt','eq'],'value'=>25.0], ... ] ], ... ]
 * ops เก็บเป็น "เซ็ตของ lt/gt/eq ที่ติ๊ก" ไม่ใช่ operator สำเร็จรูป — เพื่อให้ checkbox
 * ใน UI กับค่าที่เก็บเป็นรูปเดียวกัน ไม่ต้องแปลงกลับไปกลับมาให้พลาด
 */

if (!function_exists('mf_ops_to_sql')) {
  /** เซ็ต ops → operator SQL · คืน '' ถ้าไม่มีความหมาย (ว่าง หรือติ๊กครบ 3 = จริงเสมอ) */
  function mf_ops_to_sql(array $ops): string {
    $lt = in_array('lt', $ops, true);
    $gt = in_array('gt', $ops, true);
    $eq = in_array('eq', $ops, true);
    if ($lt && $gt && $eq) return '';        // < หรือ > หรือ = → จริงเสมอ ไม่ใช่เงื่อนไข
    if ($lt && $gt)  return '<>';
    if ($lt && $eq)  return '<=';
    if ($gt && $eq)  return '>=';
    if ($lt)         return '<';
    if ($gt)         return '>';
    if ($eq)         return '=';
    return '';
  }
}

if (!function_exists('mf_parse_labconds')) {
  /** ค่าดิบจาก modal (array ซ้อน) → โครง groups ที่ validate แล้ว */
  function mf_parse_labconds($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $g) {
      if (!is_array($g)) continue;
      $codes = mf_codes(preg_split('/[\s,]+/', trim((string)($g['codes'] ?? ''))));
      if (!$codes) continue;                              // ไม่มี lab code = ทิ้งทั้งกลุ่ม
      $conds = [];
      foreach ((array)($g['conds'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $ops    = array_values(array_intersect(['lt','gt','eq'], (array)($c['ops'] ?? [])));
        $valRaw = trim((string)($c['value'] ?? ''));
        if (!$ops || $valRaw === '' || !is_numeric($valRaw)) continue;
        if (mf_ops_to_sql($ops) === '') continue;         // ติ๊กครบ 3 = ข้าม
        $conds[] = ['ops' => $ops, 'value' => (float)$valRaw];
      }
      if (!$conds) continue;                              // ไม่มีเงื่อนไขใช้ได้ = ทิ้งกลุ่ม
      $out[] = ['codes' => $codes, 'conds' => $conds];
    }
    return $out;
  }
}

if (!function_exists('mf_labconds_clause')) {
  /**
   * groups → ชิ้นส่วน WHERE + params (bind ทุกค่า ไม่ concat ค่าผู้ใช้ลง SQL)
   * $numExpr = นิพจน์ที่แปลงผลเป็นตัวเลขแล้ว (ต่างกันตาม dialect)
   * คืน '1=0' ถ้าไม่มีเงื่อนไขใช้ได้เลย — กันดึงทั้งตารางออกมาโดยไม่ตั้งใจ
   */
  function mf_labconds_clause(array &$params, array $groups, string $numExpr, string $codeCol = 'l.lab_items_code'): string {
    $parts = [];
    foreach ($groups as $g) {
      $codes = mf_codes((array)($g['codes'] ?? []));
      if (!$codes) continue;
      $ors = []; $orParams = [];
      foreach ((array)($g['conds'] ?? []) as $c) {
        $op = mf_ops_to_sql(array_values((array)($c['ops'] ?? [])));
        if ($op === '' || !isset($c['value']) || !is_numeric($c['value'])) continue;
        $ors[]      = "{$numExpr} {$op} ?";
        $orParams[] = (float)$c['value'];
      }
      if (!$ors) continue;
      $place   = implode(',', array_fill(0, count($codes), '?'));
      $parts[] = "({$codeCol} IN ($place) AND (" . implode(' OR ', $ors) . '))';
      foreach ($codes as $cd) $params[] = $cd;
      foreach ($orParams as $v) $params[] = $v;
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
  }
}

if (!function_exists('mf_labconds_summary')) {
  /** สรุปเงื่อนไขเป็นข้อความสั้น ใช้โชว์บน UI / log */
  function mf_labconds_summary(array $groups): string {
    $out = [];
    foreach ($groups as $g) {
      $codes = implode(',', mf_codes((array)($g['codes'] ?? [])));
      $cs = [];
      foreach ((array)($g['conds'] ?? []) as $c) {
        $op = mf_ops_to_sql(array_values((array)($c['ops'] ?? [])));
        if ($op !== '') $cs[] = $op . ' ' . (float)($c['value'] ?? 0);
      }
      if ($codes !== '' && $cs) $out[] = "[{$codes}] " . implode(' หรือ ', $cs);
    }
    return $out ? implode(' · ', $out) : '(ยังไม่ได้ตั้งเงื่อนไข)';
  }
}
