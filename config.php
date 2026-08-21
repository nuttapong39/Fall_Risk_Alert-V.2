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

// โหลดชื่อโรงพยาบาลจาก secrets/site_config.json → HOSPITAL_SHORT / HOSPITAL_FULL
require_once __DIR__ . '/site_config_loader.php';

// สำหรับปุ่มใน UI (เปลี่ยนเป็นค่าเฉพาะของคุณ)
if (!defined('UI_ACTION_TOKEN')) {
  define('UI_ACTION_TOKEN', 'change-me-very-secret');
}

/* =========================================================
 *  Database Config — สอง datastore (ดู docs/adr/0001)
 *    - HOSxP      : แหล่งข้อมูลต้นทาง อ่านอย่างเดียว (MySQL V3 หรือ PostgreSQL XE4)
 *                   เข้าถึงผ่าน hosxp_db() แบบ lazy
 *    - MedAlert_DB: ฐานข้อมูลของระบบเอง อ่าน-เขียน (MySQL เสมอ)
 *                   เข้าถึงผ่าน $dbcon
 *  อ่านค่าจาก secrets/db_config.json (รูปแบบ 2 ก้อน: hosxp + medalert)
 *  รองรับไฟล์รูปแบบเก่า (flat ก้อนเดียว) โดยตีความเป็น hosxp อัตโนมัติ
 * ========================================================= */
$DB_CFG_DIR  = __DIR__ . DIRECTORY_SEPARATOR . 'secrets';
$DB_CFG_FILE = $DB_CFG_DIR . DIRECTORY_SEPARATOR . 'db_config.json';

// normalize หนึ่งก้อน connection ให้ครบทุก key พร้อม default
if (!function_exists('_db_normalize_conn')) {
  function _db_normalize_conn($c, array $def): array {
    $c = is_array($c) ? $c : [];
    return [
      'driver' => in_array($c['driver'] ?? '', ['mysql','pgsql'], true) ? $c['driver'] : $def['driver'],
      'host'   => $c['host'] ?? $def['host'],
      'port'   => isset($c['port']) ? (int)$c['port'] : $def['port'],
      'name'   => $c['name'] ?? $def['name'],
      'user'   => $c['user'] ?? $def['user'],
      'pass'   => $c['pass'] ?? $def['pass'],
    ];
  }
}

$rawCfg = is_readable($DB_CFG_FILE) ? json_decode(@file_get_contents($DB_CFG_FILE), true) : null;
if (!is_array($rawCfg)) $rawCfg = [];

// ไฟล์เก่า = flat ก้อนเดียว (มี driver ที่ระดับบนสุด ไม่มี hosxp/medalert)
$isLegacyFlat = isset($rawCfg['driver']) && !isset($rawCfg['hosxp']) && !isset($rawCfg['medalert']);

$DB_HOSXP = _db_normalize_conn(
  $isLegacyFlat ? $rawCfg : ($rawCfg['hosxp'] ?? []),
  ['driver'=>'mysql','host'=>'192.168.1.246','port'=>3306,'name'=>'hosxp','user'=>'root','pass'=>'sa']
);
$DB_MEDALERT = _db_normalize_conn(
  $isLegacyFlat ? [] : ($rawCfg['medalert'] ?? []),
  ['driver'=>'mysql','host'=>'127.0.0.1','port'=>3306,'name'=>'MedAlert_DB','user'=>'root','pass'=>'']
);
$DB_MEDALERT['driver'] = 'mysql'; // ล็อก — MedAlert_DB เป็น MySQL เสมอ (ADR 0001)

// MedAlert_DB ถูกตั้งค่าแล้วหรือยัง (ใช้ตัดสิน first-run)
$medalertConfigured = !$isLegacyFlat
  && isset($rawCfg['medalert']) && is_array($rawCfg['medalert'])
  && !empty($rawCfg['medalert']['host']) && !empty($rawCfg['medalert']['name']);

// ======================================================
//  First-run detection: ถ้ายังไม่ได้ตั้งค่า MedAlert_DB
//  ให้ redirect ไปหน้า setup (ยกเว้น CONFIG_SKIP_DB / CONFIG_SETUP_PAGE)
// ======================================================
if (!$medalertConfigured
    && !defined('CONFIG_SKIP_DB')
    && !defined('CONFIG_SETUP_PAGE')) {
  header('Location: db_config_admin.php');
  exit;
}

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// สร้าง $dbcon = MedAlert_DB (MySQL, eager) เฉพาะเมื่อไม่ได้ขอข้าม DB
if (!defined('CONFIG_SKIP_DB')) {
  $dsn = "mysql:host={$DB_MEDALERT['host']};port={$DB_MEDALERT['port']};dbname={$DB_MEDALERT['name']};charset=utf8mb4";
  try {
    $dbcon = new PDO($dsn, $DB_MEDALERT['user'], $DB_MEDALERT['pass'], $options);
    $dbcon->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  } catch (Throwable $e) {
    http_response_code(500);
    die("MedAlert_DB connect failed: ".$e->getMessage());
  }
}

/**
 * hosxp_db() — connection ไป HOSxP source (อ่านอย่างเดียว) แบบ lazy
 * เชื่อมต่อครั้งแรกที่เรียกเท่านั้น เพื่อให้หน้าที่ไม่แตะ HOSxP ไม่ล่มถ้า source ดับ
 * ฝั่ง PostgreSQL บังคับ read-only ระดับ session (ADR 0001 ชั้น 2)
 */
if (!function_exists('hosxp_db')) {
  function hosxp_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    global $DB_HOSXP;
    $c = $DB_HOSXP;
    if (($c['driver'] ?? 'mysql') === 'pgsql') {
      $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}";
    } else {
      $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
    }
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if (($c['driver'] ?? 'mysql') === 'pgsql') {
      $pdo->exec("SET client_encoding TO 'UTF8'");   // HOSxPXE4 server_encoding มักเป็น WIN874 → ให้ PG แปลงเป็น UTF-8 ให้
      $pdo->exec("SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY");
    } else {
      $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    return $pdo;
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
