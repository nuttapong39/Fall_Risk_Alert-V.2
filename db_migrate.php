<?php
/**
 * db_migrate.php
 * ตัว deploy schema กลาง (idempotent) — ใช้ร่วมโดย db_config_admin.php (ปุ่ม "Setup MedAlert_DB")
 * และ task/update.ps1 (หลังอัปเดตเวอร์ชัน เผื่อรีลีสใหม่เพิ่มตาราง queue)
 *
 * ใช้ glob("*_queue.sql") + "users.sql" แทน hardcoded list เดิม — ตารางใหม่ในอนาคต
 * (เช่น lepto_queue.sql/scrub_queue.sql ที่เคยตกหล่นจาก list ตายตัว) จะถูก deploy อัตโนมัติ
 * โดยไม่ต้องแก้โค้ดทุกครั้งที่เพิ่ม module
 *
 * เรียกเป็น CLI ได้ตรงๆ: php db_migrate.php  (อ่านค่า DB จาก secrets/db_config.json)
 */

if (!function_exists('db_migrate_schema_files')) {
  /** รายชื่อไฟล์ .sql ที่จะ deploy (users.sql ก่อน แล้วตามด้วย *_queue.sql เรียงตามชื่อ) */
  function db_migrate_schema_files(string $baseDir): array {
    $files = [];
    if (is_readable($baseDir . '/users.sql')) $files[] = 'users.sql';
    $queueFiles = glob($baseDir . '/*_queue.sql') ?: [];
    sort($queueFiles);
    foreach ($queueFiles as $p) $files[] = basename($p);
    return $files;
  }
}

if (!function_exists('deploy_missing_schema')) {
  /**
   * Deploy schema ทุกไฟล์ที่เจอ (idempotent — กดซ้ำ/เรียกซ้ำได้ปลอดภัย)
   *   - ตัด DROP TABLE / INSERT INTO ออก (กันลบข้อมูล/seed ซ้ำ)
   *   - แปลง CREATE TABLE → CREATE TABLE IF NOT EXISTS
   * คืน array ของบรรทัดผลลัพธ์ (ไทย, สำหรับแสดงในหน้าเว็บ/log)
   */
  function deploy_missing_schema(PDO $pdo, ?string $baseDir = null): array {
    $baseDir = $baseDir ?? __DIR__;
    $steps = [];
    foreach (db_migrate_schema_files($baseDir) as $sf) {
      $path = $baseDir . '/' . $sf;
      if (!is_readable($path)) { $steps[] = "✗ ไม่พบไฟล์ {$sf}"; continue; }
      $sqlText = file_get_contents($path);
      $sqlText = preg_replace('/DROP\s+TABLE\s+IF\s+EXISTS[^;]*;/i', '', $sqlText);
      $sqlText = preg_replace('/INSERT\s+INTO[\s\S]*?;/i', '', $sqlText);
      $sqlText = preg_replace('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $sqlText);
      try { $pdo->exec($sqlText); $steps[] = "✓ deploy {$sf}"; }
      catch (Throwable $e) { $steps[] = "✗ {$sf}: " . $e->getMessage(); }
    }
    return $steps;
  }
}

/* ── CLI entrypoint: `php db_migrate.php` — อ่าน secrets/db_config.json เอง ── */
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
  $cfgFile = __DIR__ . '/secrets/db_config.json';
  if (!is_readable($cfgFile)) {
    fwrite(STDERR, "ไม่พบ secrets/db_config.json — ยังไม่ได้ตั้งค่า DB ข้าม migration\n");
    exit(0); // ไม่ถือเป็น error ร้ายแรง (เช่น รันตอนติดตั้งใหม่ก่อนตั้งค่า DB)
  }
  $j = json_decode(file_get_contents($cfgFile), true);
  $ma = $j['medalert'] ?? null;
  if (!is_array($ma) || empty($ma['host']) || empty($ma['name']) || empty($ma['user'])) {
    fwrite(STDERR, "db_config.json ไม่มีค่า MedAlert_DB ครบ — ข้าม migration\n");
    exit(0);
  }
  try {
    $dsn = "mysql:host={$ma['host']};port=" . ($ma['port'] ?? 3306) . ";dbname={$ma['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $ma['user'], $ma['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach (deploy_missing_schema($pdo, __DIR__) as $line) echo $line . "\n";
  } catch (Throwable $e) {
    fwrite(STDERR, "migration ล้มเหลว: " . $e->getMessage() . "\n");
    exit(1);
  }
}
