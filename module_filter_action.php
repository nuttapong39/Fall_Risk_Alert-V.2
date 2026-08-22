<?php
/**
 * module_filter_action.php — บันทึกเงื่อนไขดึงข้อมูลราย module (จาก modal ใน *_queue_ui.php)
 *   action=save  : parse ฟิลด์ตาม schema → clean → เขียน secrets/module_filters.json
 *   action=reset : ลบ config ของ module นั้น → กลับไปใช้ค่า default
 * ปลอดภัย: auth_guard + UI_ACTION_TOKEN (CSRF) + validate ทุกค่า (bind param ตอนใช้)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
date_default_timezone_set('Asia/Bangkok');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

/* หน้าที่จะ redirect กลับ (queue_ui ของ module) — อนุญาตเฉพาะ .php ในเครื่อง */
$back = (string)($_POST['back'] ?? 'index.php');
if (!preg_match('/^[A-Za-z0-9_.-]+\.php(\?[A-Za-z0-9_=&%.-]*)?$/', $back)) $back = 'index.php';
$rd = fn(string $flag) => $back . (strpos($back, '?') !== false ? '&' : '?') . 'flt=' . $flag;

$mod    = (string)($_POST['module'] ?? '');
$schema = module_filter_schema($mod);
if (!$schema) { header('Location: ' . $rd('badmod')); exit; }

if (($_POST['token'] ?? '') !== (defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : '')) {
  header('Location: ' . $rd('badtoken')); exit;
}

$action = ($_POST['action'] ?? 'save') === 'reset' ? 'reset' : 'save';

$file   = MODULE_FILTERS_FILE;
$stored = is_readable($file) ? json_decode(@file_get_contents($file), true) : [];
if (!is_array($stored)) $stored = [];

if ($action === 'reset') {
  unset($stored[$mod]);                 // → module_filter() จะ fallback เป็น default
} else {
  $cfg = [];
  foreach ($schema['fields'] as $f) {
    $k = $f['key']; $raw = (string)($_POST['f_' . $k] ?? '');
    switch ($f['type']) {
      case 'codes':    $cfg[$k] = mf_codes(preg_split('/[\s,]+/', trim($raw)));      break;
      case 'results':  $cfg[$k] = mf_texts(preg_split('/[\r\n,]+/', trim($raw)));    break; // ผลมีช่องว่างได้
      case 'single':   $c = mf_codes([$raw]); $cfg[$k] = $c[0] ?? '';                break;
      case 'int':      $cfg[$k] = max(0, (int)$raw);                                 break;
      case 'patterns': $cfg[$k] = mf_text_to_patterns($raw);                         break;
      default:         // 'rules' (pharm_lab) — จัดการใน phase ถัดไป; คงค่าที่ใช้อยู่
                       $cur = module_filter($mod); if (isset($cur[$k])) $cfg[$k] = $cur[$k];
    }
  }
  $stored[$mod] = $cfg;
}
$stored['_meta'] = ['updated_at' => date('Y-m-d H:i:s')];

if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
if (is_file($file)) @copy($file, $file . '.bak');
$ok = @file_put_contents($file, json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

header('Location: ' . $rd($ok !== false ? $action : 'err'));
exit;
