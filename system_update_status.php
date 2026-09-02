<?php
/**
 * system_update_status.php — คืนสถานะล่าสุดของการอัปเดต (อ่าน logs/update_status.json)
 * ให้หน้า settings.php poll หลังกด "อัปเดตตอนนี้" จนกว่าจะ done/error
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
header('Content-Type: application/json; charset=utf-8');

$file = __DIR__ . '/logs/update_status.json';
if (!is_readable($file)) {
  echo json_encode(['ok'=>true,'status'=>'idle','message'=>'','updatedAt'=>null]);
  exit;
}

// update.ps1 เขียนไฟล์นี้ด้วย Set-Content -Encoding UTF8 ซึ่ง PowerShell 5.1 ใส่ UTF-8 BOM
// ให้เสมอ — json_decode มองว่า BOM (EF BB BF) เป็นอักขระเกินหน้า '{' จึง parse ไม่ผ่าน
// ทำให้ endpoint นี้ตกไป fallback ตอบ 'idle' ตลอดกาล (คือสาเหตุที่ progress bar เดิม
// ไม่เคยเห็นสถานะจริงเลย จนต้องมีโหมดเดา % จากเวลามาคั่น) ตัด BOM ทิ้งก่อน decode
$raw = (string)@file_get_contents($file);
if (substr($raw, 0, 3) === "ï»¿") { $raw = substr($raw, 3); }
$j = json_decode($raw, true);
if (!is_array($j)) {
  echo json_encode(['ok'=>true,'status'=>'idle','message'=>'','updatedAt'=>null]);
  exit;
}

echo json_encode(['ok'=>true] + $j);
