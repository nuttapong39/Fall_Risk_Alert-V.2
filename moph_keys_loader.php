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
  ];

  foreach ($modules as $jsonKey => $const) {
    $mc = $data[$jsonKey]['client'] ?? $defClient ?? MOPH_CLIENT_KEY ?? null;
    $ms = $data[$jsonKey]['secret'] ?? $defSecret ?? MOPH_SECRET_KEY ?? null;
    _define_if_not($const['CLIENT'], $mc);
    _define_if_not($const['SECRET'], $ms);
  }

} catch (Throwable $e) {
  // เงียบไว้ไม่ให้กระทบ flow หลัก
}
