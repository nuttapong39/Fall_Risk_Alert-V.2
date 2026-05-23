<?php
// config.php

// ถ้าต้องการให้ไฟล์อื่นเรียกใช้ config.php แต่ "ไม่ต่อ DB"
// ให้ define CONFIG_SKIP_DB ก่อน require ไฟล์นี้
//   define('CONFIG_SKIP_DB', true);
//   require_once __DIR__ . '/config.php';

// -- Force IP (ถ้าใช้ reverse proxy / NAT) --
define('MOPH_FORCE_IP', '43.229.149.136');

date_default_timezone_set('Asia/Bangkok');

// โหลดคีย์ MOPH ALERT จาก secrets/moph_keys.json
require_once __DIR__ . '/moph_keys_loader.php';

// สำหรับปุ่มใน UI (เปลี่ยนเป็นค่าเฉพาะของคุณ)
if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', 'change-me-very-secret');
}

/* =========================================================
 *  Database Config (อ่านจาก secrets/db_config.json ได้)
 * ========================================================= */
$DB_CFG_DIR  = __DIR__ . DIRECTORY_SEPARATOR . 'secrets';
$DB_CFG_FILE = $DB_CFG_DIR . DIRECTORY_SEPARATOR . 'db_config.json';

// ค่า default เผื่อกรณียังไม่เคยตั้งผ่านหน้าเว็บ
$dbCfg = [
  'driver' => 'mysql',
  'host'   => '192.168.1.249',
  'port'   => 3306,
  'name'   => 'hosxp',
  'user'   => 'root',
  'pass'   => 'comsci',
];

// ======================================================
//  First-run detection: ถ้าไม่มีไฟล์ config ให้ redirect
//  ไปหน้า setup ทันที (ยกเว้นกรณี CONFIG_SKIP_DB = ตั้งค่า
//  หรือ CONFIG_SETUP_PAGE = เราอยู่บนหน้า setup อยู่แล้ว)
// ======================================================
if (!is_readable($DB_CFG_FILE)
    && !defined('CONFIG_SKIP_DB')
    && !defined('CONFIG_SETUP_PAGE')) {
  // ยังไม่มีการตั้งค่า DB → ส่งไปหน้า setup
  header('Location: db_config_admin.php');
  exit;
}

// ถ้ามีไฟล์ config จากหน้าเว็บให้โหลดทับค่า default
if (is_readable($DB_CFG_FILE)) {
  $j = json_decode(@file_get_contents($DB_CFG_FILE), true);
  if (is_array($j)) {
    $dbCfg['driver'] = in_array($j['driver'] ?? '', ['mysql','pgsql'], true)
                       ? $j['driver'] : $dbCfg['driver'];
    $dbCfg['host'] = $j['host'] ?? $dbCfg['host'];
    $dbCfg['port'] = isset($j['port']) ? (int)$j['port'] : $dbCfg['port'];
    $dbCfg['name'] = $j['name'] ?? $dbCfg['name'];
    $dbCfg['user'] = $j['user'] ?? $dbCfg['user'];
    $dbCfg['pass'] = $j['pass'] ?? $dbCfg['pass'];
  }
}

// กระจายเป็นตัวแปรเดิมเพื่อให้ไฟล์อื่นใช้ต่อได้
$DB_DRIVER = $dbCfg['driver'];
$DB_HOST   = $dbCfg['host'];
$DB_PORT   = $dbCfg['port'];
$DB_NAME   = $dbCfg['name'];
$DB_USER   = $dbCfg['user'];
$DB_PASS   = $dbCfg['pass'];

// สร้าง PDO เฉพาะกรณีที่ไม่ได้ขอให้ข้ามการต่อ DB
if (!defined('CONFIG_SKIP_DB')) {
  // สร้าง DSN ตาม driver ที่เลือก
  if ($DB_DRIVER === 'pgsql') {
    $dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME}";
  } else {
    $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
  }
  $options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ];
  try {
    $dbcon = new PDO($dsn, $DB_USER, $DB_PASS, $options);
  } catch (Throwable $e) {
    http_response_code(500);
    die("DB connect failed: ".$e->getMessage());
  }
  if ($DB_DRIVER === 'mysql') {
    $dbcon->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  }
}

/* =========================================================
 *  Delivery / MOPH Alert Config
 * ========================================================= */

// ใช้ 'moph_alert' เพื่อยิงไป MOPH Alert API (LINE)
define('DELIVERY_DRIVER', 'moph_alert'); // 'moph_alert' | 'file' | 'email'

// === MOPH Alert keys (ใช้ทั้ง manual & auto) ===
if (!defined('MOPH_API_URL')) {
  define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');
}

// *** ไม่ต้อง define FRACTURE_CLIENT_KEY/SECRET_KEY ตรงนี้แล้ว ***
// ปล่อยให้ moph_keys_loader.php เป็นคนกำหนดตามไฟล์ secrets/moph_keys.json

/** ---------- Flex message decorations (ตัวอย่าง COVID เดิม) ---------- */
define('LINE_TITLE',       'ผู้ป่วย Covid-19 รพ.เชียงกลาง');
define('LINE_HEADER_URL',  'https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg');
define('LINE_ICON_URL',    'https://www.ckhospital.net/home/PDF/covid.png');

// --- Bridge สำหรับโมดูลอื่นที่อ้าง MOPH_CLIENT_KEY/SECRET_KEY ---
if (!defined('MOPH_CLIENT_KEY') && defined('FRACTURE_CLIENT_KEY')) {
  define('MOPH_CLIENT_KEY', FRACTURE_CLIENT_KEY);
}
if (!defined('MOPH_SECRET_KEY') && defined('FRACTURE_SECRET_KEY')) {
  define('MOPH_SECRET_KEY', FRACTURE_SECRET_KEY);
}
if (!defined('MOPH_TIMEOUT')) define('MOPH_TIMEOUT', 30);
if (!defined('MOPH_API_URL')) define('MOPH_API_URL', 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes');

/** ---------- Defaults for cron/filter ---------- */
define('DEFAULT_LOOKBACK_DAYS', 7); // ดึงย้อนหลัง N วันถ้าไม่ระบุช่วง
define('DEFAULT_HOSP_CODE',     ''); // เว้นว่าง = ทุก รพ.

/** ---------- Optional: file/email fallback (ไม่บังคับ) ---------- */
define('OUTBOX_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'outbox');
define('MAIL_TO',   'covid-team@intra.local');
define('MAIL_FROM', 'covidbot@intra.local');
define('MAIL_SUBJ', 'แจ้งเตือนผู้ป่วย Covid-19 (ระบบอัตโนมัติ)');
