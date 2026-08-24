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

$j = json_decode((string)@file_get_contents($file), true);
if (!is_array($j)) {
  echo json_encode(['ok'=>true,'status'=>'idle','message'=>'','updatedAt'=>null]);
  exit;
}

echo json_encode(['ok'=>true] + $j);
