<?php
/**
 * version_loader.php
 * โหลดเลขเวอร์ชันปัจจุบันของระบบจากไฟล์ VERSION (root, ติดมากับโค้ด ไม่ใช่ per-install)
 *   APP_VERSION — เช่น "2026.08.24" (รูปแบบ YYYY.MM.DD)
 * ถ้าไฟล์หาย/อ่านไม่ได้ → ใช้ "0.0.0" (พฤติกรรม safe-default, แปลว่า "ยังไม่รู้เวอร์ชัน")
 */
$verFile = __DIR__ . DIRECTORY_SEPARATOR . 'VERSION';
$ver     = '0.0.0';

if (is_readable($verFile)) {
  $v = trim((string)@file_get_contents($verFile));
  if ($v !== '') $ver = $v;
}

if (!defined('APP_VERSION')) define('APP_VERSION', $ver);
