<?php
/**
 * moph_endpoint_loader.php
 * โหลด endpoint ของ MOPH Alert API จาก secrets/moph_endpoint.json แล้ว define ให้ทั้งระบบ
 *   MOPH_API_URL — URL ที่ใช้ยิง Flex Message ทุก module
 * ถ้าไม่มีไฟล์/ค่าว่าง → ใช้ค่า default (พฤติกรรมเดิม)
 *
 * เดิม URL นี้ถูก hardcode ซ้ำ 20+ จุดทั่วโปรเจกต์ ทำให้ตอน MOPH ย้าย endpoint
 * หรือต้องชี้ไป URL สำรองชั่วคราว ต้องไล่แก้ทีละไฟล์ — ย้ายมาไว้ที่เดียวตรงนี้
 */
$file = __DIR__ . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'moph_endpoint.json';
$url  = 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes';

if (is_readable($file)) {
  $j = json_decode(@file_get_contents($file), true);
  if (is_array($j) && !empty($j['api_url'])) $url = (string)$j['api_url'];
}

if (!defined('MOPH_API_URL')) define('MOPH_API_URL', $url);
