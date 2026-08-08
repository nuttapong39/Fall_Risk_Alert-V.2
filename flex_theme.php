<?php
/**
 * flex_theme.php — Theme loader สำหรับ LINE Flex (config-driven)
 *   อ่าน theme ต่อ module จาก secrets/flex_themes.json (แก้ผ่าน flex_editor.php)
 *   มี default ฝังในตัว → ทำงานได้แม้ไฟล์ JSON หาย/เสีย
 *   sanitize สีให้เป็น hex เท่านั้น (#RGB/#RRGGBB/#RRGGBBAA) — กันค่า rgba() ที่ LINE reject เงียบ
 *
 * API:
 *   flex_theme(string $module): array   — theme ของ module (merge default)
 *   flex_theme_global(): array          — footer/labels ที่ใช้ร่วมทุก module
 *   flex_theme_all(): array             — ทั้งก้อน (สำหรับ editor)
 *   flex_gradient(array $theme): array  — object background linearGradient สำหรับ LINE
 *   flex_hex(string $c, string $fb): string — คืน $c ถ้าเป็น hex ที่ถูก ไม่งั้น $fb
 */

if (!defined('FLEX_THEMES_FILE')) {
  define('FLEX_THEMES_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'flex_themes.json');
}

if (!function_exists('flex_hex')) {
  function flex_hex($c, string $fallback = '#334155'): string {
    $c = is_string($c) ? trim($c) : '';
    return preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $c) ? $c : $fallback;
  }
}

if (!function_exists('_flex_theme_defaults')) {
  function _flex_theme_defaults(): array {
    // สี: color_start (อ่อน) → color_end (เข้ม) สำหรับ gradient หัวการ์ด ; accent = สีเน้น ICD/LAB
    $mk = fn($t,$s,$u,$cs,$ce,$ac,$ang=135) =>
      ['title'=>$t,'subtitle'=>$s,'urgency'=>$u,'color_start'=>$cs,'color_end'=>$ce,'accent'=>$ac,'gradient_angle'=>$ang,'bg_icon_url'=>''];
    return [
      '_global' => [
        'footer_text'   => 'ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยง',
        'hospital_name' => '',            // เว้นว่าง = ใช้ได้หลาย รพ.
        'labels' => [
          'sec_patient'   => 'ผู้ป่วย',
          'sec_diagnosis' => 'การวินิจฉัย',
          'sec_contact'   => 'ติดตาม',
          'icd'           => 'ICD-10',
          'disease'       => 'ชื่อโรค',
          'lab'           => 'ผล LAB',
          'vstdate'       => 'รับบริการ',
          'doctor'        => 'แพทย์',
          'address'       => 'ที่อยู่',
          'phone'         => 'โทร',
        ],
      ],
      // ── 10 modules (hue wheel, แยกสีชัด) ──
      'dengue'    => $mk('ไข้เลือดออก','Dengue Fever','ด่วน · 24 ชม.','#EF4444','#B91C1C','#B91C1C'),
      'accident'  => $mk('อุบัติเหตุ (พ.ร.บ.)','Accident / Traffic','ด่วน','#F97316','#C2410C','#C2410C'),
      'fracture'  => $mk('หกล้ม / กระดูกหัก','Fall & Fracture Risk','ด่วน','#F59E0B','#B45309','#B45309'),
      'scrub'     => $mk('สครับไทฟัส','Scrub Typhus','ด่วน · 24 ชม.','#22C55E','#15803D','#15803D'),
      'lepto'     => $mk('เลปโตสไปโรสิส','Leptospirosis','ด่วน · 24 ชม.','#14B8A6','#0F766E','#0F766E'),
      'covid'     => $mk('COVID-19','COVID-19 Lab Positive','Positive','#0EA5E9','#0369A1','#0369A1'),
      'patient'   => $mk('จิตเวช / ทำร้ายตนเอง','Psychiatric / Self-harm','ด่วน · 24 ชม.','#6366F1','#4338CA','#4338CA'),
      'pharm_lab' => $mk('Lab วิกฤต (ห้องยา)','Critical Pharmacy Lab','วิกฤต','#8B5CF6','#6D28D9','#6D28D9'),
      'sexual'    => $mk('ความรุนแรงทางเพศ','Sexual Assault','ด่วน · ลับ','#C026D3','#86198F','#86198F'),
      'drug'      => $mk('ยาอันตราย (High-Alert)','High-Alert Medication','ด่วน','#EC4899','#BE185D','#BE185D'),
    ];
  }
}

if (!function_exists('flex_theme_all')) {
  function flex_theme_all(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $def = _flex_theme_defaults();
    $raw = is_readable(FLEX_THEMES_FILE) ? json_decode(@file_get_contents(FLEX_THEMES_FILE), true) : null;
    if (!is_array($raw)) $raw = [];
    $out = $def;
    foreach ($raw as $k => $v) {
      if (!is_array($v)) continue;
      if ($k === '_global') {
        $out['_global'] = array_merge($def['_global'], $v);
        if (isset($v['labels']) && is_array($v['labels'])) {
          $out['_global']['labels'] = array_merge($def['_global']['labels'], $v['labels']);
        }
      } else {
        $out[$k] = array_merge($def[$k] ?? [], $v);
      }
    }
    return $cache = $out;
  }
}

if (!function_exists('flex_theme')) {
  function flex_theme(string $module): array {
    $all = flex_theme_all();
    $t = $all[$module] ?? [];
    $t = array_merge(
      ['title'=>$module,'subtitle'=>'','urgency'=>'','color_start'=>'#334155','color_end'=>'#0F172A','accent'=>'#0F172A','gradient_angle'=>135,'bg_icon_url'=>''],
      $t
    );
    // sanitize สี (กัน rgba/ค่าเพี้ยนหลุดไป LINE)
    $t['color_start'] = flex_hex($t['color_start'], '#334155');
    $t['color_end']   = flex_hex($t['color_end'],   '#0F172A');
    $t['accent']      = flex_hex($t['accent'],      $t['color_end']);
    $a = (int)($t['gradient_angle'] ?? 135);
    $t['gradient_angle'] = ($a >= 0 && $a <= 360) ? $a : 135;
    return $t;
  }
}

if (!function_exists('flex_theme_global')) {
  function flex_theme_global(): array { return flex_theme_all()['_global']; }
}

if (!function_exists('flex_gradient')) {
  /** object background linearGradient สำหรับ box ใน LINE Flex */
  function flex_gradient(array $t): array {
    return [
      "type"       => "linearGradient",
      "angle"      => ((int)($t['gradient_angle'] ?? 135)) . "deg",
      "startColor" => flex_hex($t['color_start'] ?? '', '#334155'),
      "endColor"   => flex_hex($t['color_end']   ?? '', '#0F172A'),
    ];
  }
}
