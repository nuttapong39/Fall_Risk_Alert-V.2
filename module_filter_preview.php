<?php
/**
 * module_filter_preview.php — นับผลลัพธ์จากเงื่อนไขที่กรอกใน modal (ก่อนบันทึก)
 *   รับ POST: module, token, f_<key>... (ค่าที่กำลังกรอกอยู่ ยังไม่บันทึกลง store)
 *   รันผ่าน *_source_rows() ตัวเดียวกับที่ worker/Import ใช้จริง (ไม่ query ซ้ำ)
 *   ช่วงวันที่ทดสอบ = 30 วันล่าสุด (fixed, read-only — ไม่เขียนอะไรลง MedAlert_DB/store)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$mod = (string)($_POST['module'] ?? '');
$schema = module_filter_schema($mod);
if (!$schema) { echo json_encode(['ok'=>false,'msg'=>'module ไม่ถูกต้อง']); exit; }

if (($_POST['token'] ?? '') !== (defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : '')) {
  echo json_encode(['ok'=>false,'msg'=>'Token ไม่ถูกต้อง — refresh หน้าแล้วลองใหม่']);
  exit;
}

$cfg = module_filter_parse_post($mod, $_POST);

/* ── dispatch: module → source function + args (ใช้ค่าที่เพิ่งกรอก ไม่ใช่ store) ── */
$sourceFile = __DIR__ . "/sources/{$mod}_source.php";
if (!is_file($sourceFile)) { echo json_encode(['ok'=>false,'msg'=>'ไม่พบ source ของ module นี้']); exit; }
require_once $sourceFile;

$fnMap = [
  'covid'     => ['covid_source_rows',    fn($c) => [$c['lab_codes']]],
  'accident'  => ['accident_source_ipt_rows', fn($c) => [$c['pttypes']]],
  'drug'      => ['drug_source_rows',     fn($c) => [$c['icodes']]],
  'sexual'    => ['sexual_source_rows',   fn($c) => [$c['lab_code']]],
  'lepto'     => ['lepto_source_rows',    fn($c) => [$c['lab_code'], $c['pdx_codes']]],
  'scrub'     => ['scrub_source_rows',    fn($c) => [$c['lab_code'], $c['results'], $c['pdx_codes']]],
  'dengue'    => ['dengue_source_rows',   fn($c) => [$c['lab_code'], $c['results'], $c['pdx']]],
  'patient'   => ['patient_source_rows',  fn($c) => [$c['icd']]],
  'fracture'  => ['fracture_source_rows', fn($c) => [$c['min_age'], $c['icd']]],
  'pharm_lab' => ['pharm_lab_source_rows',fn($c) => [$c['rules']]],
  'lab_hemato'=> ['lab_hemato_source_rows',fn($c) => [$c['groups']]],
];
if (!isset($fnMap[$mod])) { echo json_encode(['ok'=>false,'msg'=>'module นี้ยังไม่รองรับการทดสอบนับผล']); exit; }
[$fn, $argsFn] = $fnMap[$mod];

$days  = 30;
$start = date('Y-m-d', strtotime("-{$days} days"));
$end   = date('Y-m-d');

try {
  $rows = $fn($start, $end, ...$argsFn($cfg));
  echo json_encode(['ok'=>true, 'count'=>count($rows), 'days'=>$days]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'msg'=>'เชื่อมต่อ HOSxP ไม่สำเร็จ: '.$e->getMessage()]);
}
