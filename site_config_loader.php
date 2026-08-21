<?php
/**
 * site_config_loader.php
 * โหลดชื่อโรงพยาบาลจาก secrets/site_config.json แล้ว define คอนสแตนต์ให้ทั้งระบบ
 *   HOSPITAL_SHORT — ชื่อย่อ (ใช้ที่ sidebar / <title>)   เช่น "รพ.เชียงกลาง"
 *   HOSPITAL_FULL  — ชื่อเต็ม+จังหวัด (ใช้ที่หน้า login)   เช่น "โรงพยาบาลเชียงกลาง · จ.น่าน"
 * ถ้าไม่มีไฟล์/ค่าว่าง → ใช้ค่า default (พฤติกรรมเดิม) · แก้ได้ที่หน้า settings.php
 */
$file  = __DIR__ . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'site_config.json';
$short = 'รพ.เชียงกลาง';
$full  = 'โรงพยาบาลเชียงกลาง · จ.น่าน';

if (is_readable($file)) {
  $j = json_decode(@file_get_contents($file), true);
  if (is_array($j)) {
    if (!empty($j['hospital_short'])) $short = (string)$j['hospital_short'];
    if (!empty($j['hospital_full']))  $full  = (string)$j['hospital_full'];
  }
}

if (!defined('HOSPITAL_SHORT')) define('HOSPITAL_SHORT', $short);
if (!defined('HOSPITAL_FULL'))  define('HOSPITAL_FULL',  $full);
