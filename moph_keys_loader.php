<?php
// moph_keys_loader.php
// โหลดคีย์ MOPH ALERT จาก secrets/moph_keys.json แล้ว define คอนสแตนต์สำหรับแต่ละโมดูล

$dir  = __DIR__ . DIRECTORY_SEPARATOR . 'secrets';
$file = $dir . DIRECTORY_SEPARATOR . 'moph_keys.json';

function _define_if_not(string $name, $value) {
  if (!defined($name) && $value !== null && $value !== '') define($name, $value);
}

try {
  $data = [];
  if (is_readable($file)) {
    $data = json_decode(@file_get_contents($file), true);
    if (!is_array($data)) $data = [];
  }

  // ---- default (ใช้เวลายังไม่เจอคีย์เฉพาะโมดูล) ----
  $defClient = $data['default']['client'] ?? null;
  $defSecret = $data['default']['secret'] ?? null;

  // กำหนดตัวกลาง (MOPH_CLIENT_KEY/SECRET) ถ้ายังไม่ได้กำหนดใน config.php
  _define_if_not('MOPH_CLIENT_KEY', $defClient);
  _define_if_not('MOPH_SECRET_KEY', $defSecret);

  // ---- module map: key ใน JSON -> ชื่อคอนสแตนต์ ----
  $modules = [
    'covid'     => ['CLIENT'=>'COVID_CLIENT_KEY',    'SECRET'=>'COVID_SECRET_KEY'],
    'fracture'  => ['CLIENT'=>'FRACTURE_CLIENT_KEY', 'SECRET'=>'FRACTURE_SECRET_KEY'],
    'accident'  => ['CLIENT'=>'ACCIDENT_CLIENT_KEY', 'SECRET'=>'ACCIDENT_SECRET_KEY'],
    'pharm_lab' => ['CLIENT'=>'PHARM_CLIENT_KEY',    'SECRET'=>'PHARM_SECRET_KEY'],
    'lab_hemato'=> ['CLIENT'=>'LAB_HEMATO_CLIENT_KEY','SECRET'=>'LAB_HEMATO_SECRET_KEY'],
    'had'       => ['CLIENT'=>'HAD_CLIENT_KEY',       'SECRET'=>'HAD_SECRET_KEY'],
    'drug'      => ['CLIENT'=>'DRUG_CLIENT_KEY',     'SECRET'=>'DRUG_SECRET_KEY'],
    'dengue'    => ['CLIENT'=>'DENGUE_CLIENT_KEY',   'SECRET'=>'DENGUE_SECRET_KEY'],
    'patient'   => ['CLIENT'=>'PATIENT_CLIENT_KEY',  'SECRET'=>'PATIENT_SECRET_KEY'],
    'lepto'     => ['CLIENT'=>'LEPTO_CLIENT_KEY',    'SECRET'=>'LEPTO_SECRET_KEY'],
    'scrub'     => ['CLIENT'=>'SCRUB_CLIENT_KEY',    'SECRET'=>'SCRUB_SECRET_KEY'],
    'sexual'    => ['CLIENT'=>'SEXUAL_CLIENT_KEY',   'SECRET'=>'SEXUAL_SECRET_KEY'],
    'system_update' => ['CLIENT'=>'SYSTEM_UPDATE_CLIENT_KEY', 'SECRET'=>'SYSTEM_UPDATE_SECRET_KEY'],
  ];

  foreach ($modules as $jsonKey => $const) {
    // NB: ?? ไม่กัน "undefined constant" (PHP 8 throw Error) — ต้องเช็ค defined() ก่อน
    //     เดิมเขียน `?? MOPH_CLIENT_KEY ??` ทำให้ลูปตายตั้งแต่ module แรกเมื่อไม่มีก้อน default
    //     ผลคือคีย์ราย module ที่ตั้งไว้จริงไม่ถูก define เลยสักตัว
    $mc = $data[$jsonKey]['client'] ?? $defClient ?? (defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : null);
    $ms = $data[$jsonKey]['secret'] ?? $defSecret ?? (defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : null);
    _define_if_not($const['CLIENT'], $mc);
    _define_if_not($const['SECRET'], $ms);
  }

} catch (Throwable $e) {
  // ไม่ให้กระทบ flow หลัก แต่ต้องทิ้งร่องรอยไว้ — เดิมเงียบสนิททำให้ไล่หาสาเหตุไม่ได้
  @error_log('moph_keys_loader: ' . $e->getMessage());
}
