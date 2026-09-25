<?php
/**
 * sources/had_source.php — แหล่งข้อมูล HAD Alert (High Alert Drug) จาก HOSxP
 *
 * รหัสยา (icode) มาจาก module_filter('had')['icodes'] เท่านั้น — แก้ผ่าน modal
 * ในหน้า had_queue_ui.php · default ที่ฝังไว้ = รายการยา HAD จริงของ รพ. (ยืนยันแล้วว่า
 * drugitems.name ของทุก icode เหล่านี้มีคำว่า "[HAD]" ต่อท้ายชื่อยาจริงในระบบ)
 *
 * Dialect (ADR 0001): อายุ TIMESTAMPDIFF(...) ↔ EXTRACT(YEAR FROM age(...))
 * ไม่ต้องคำนวณ visit_vn แบบ sources/drug_source.php เพราะ unique key ของ had_queue
 * ใช้ (hn, icode, vstdate) ตรงๆ — คนไข้ 1 รายรับยา HAD 2 ชนิดวันเดียวกันเป็นเรื่องปกติ
 * (ยืนยันจากข้อมูลจริง: พบ HN ที่ได้ warfarin สีฟ้า+สีชมพู วันเดียวกัน) ถ้าใช้ visit_vn
 * เดี่ยวแบบ drug_source.php จะชนกันและเหลือแค่แถวเดียว
 */

if (!function_exists('had_rx_at')) {
  /**
   * Rx Time = rxdate + rxtime ของ HOSxP → 'Y-m-d H:i:s' หรือ null (docs/adr/0005)
   * validate เข้มเพราะค่าเพี้ยนที่หลุดไปถึง MySQL จะกลายเป็น 0000-00-00 00:00:00 ซึ่ง TIME = 00:00
   * ตกอยู่ในช่วงข้ามเที่ยงคืนแล้วถูกส่งอัตโนมัติผิดๆ · ตัดทศนิยมวินาทีของ PostgreSQL ทิ้ง
   */
  function had_rx_at($rxdate, $rxtime): ?string {
    $d = substr(trim((string)$rxdate), 0, 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) return null;
    if (!preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', (string)$rxtime, $tm)) return null;
    [$h, $i, $s] = [(int)$tm[1], (int)$tm[2], (int)($tm[3] ?? 0)];
    if ($h > 23 || $i > 59 || $s > 59) return null;
    return sprintf('%s %02d:%02d:%02d', $d, $h, $i, $s);
  }
}

if (!function_exists('had_source_rows')) {
  /**
   * คืนแถวพร้อม 'rx_at' (Rx Time หรือ null) — ถ้า HOSxP ดึง rxdate/rxtime ไม่ได้ (เช่น V3 บางเครื่อง
   * ไม่มีคอลัมน์ หรือไม่มีสิทธิ์) ถอยไปใช้ query เดิมแล้วตั้ง $GLOBALS['HAD_SOURCE_RX_FALLBACK']
   * — ingest ต้องไม่พังเพราะเรื่องนี้ (แถวจะใช้เวลาเข้าคิวแทนตาม ADR-0005)
   * $rxCols มีไว้ให้ทดสอบเส้นทาง fallback เท่านั้น — โค้ดจริงไม่ส่ง
   */
  function had_source_rows(string $start, string $end, ?array $icodes = null, string $rxCols = 'o.rxdate, o.rxtime'): array {
    if ($icodes === null) {
      $icodes = function_exists('module_filter') ? (module_filter('had')['icodes'] ?? []) : [];
    }
    $icodes = array_values(array_filter($icodes, fn($x) => $x !== ''));
    if (!$icodes) return [];

    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $place  = implode(',', array_fill(0, count($icodes), '?'));
    $ageExpr = $driver === 'pgsql'
      ? "EXTRACT(YEAR FROM age(o.vstdate, p.birthday))"
      : "TIMESTAMPDIFF(YEAR, p.birthday, o.vstdate)";

    $sql = fn(string $extra) => "SELECT
              o.hn,
              CONCAT(COALESCE(p.pname,''), COALESCE(p.fname,''), ' ', COALESCE(p.lname,'')) AS fullname,
              p.cid,
              p.hometel,
              {$ageExpr} AS age,
              CASE WHEN p.sex='1' THEN 'ชาย' WHEN p.sex='2' THEN 'หญิง' ELSE '' END AS sex,
              p.addrpart AS address,
              d.icode,
              d.name AS drug_name,
              o.vstdate,
              o.qty,
              o.sum_price{$extra}
            FROM   opitemrece o
            JOIN   drugitems  d ON o.icode = d.icode
            JOIN   patient    p ON o.hn    = p.hn
            WHERE  o.vstdate BETWEEN ? AND ?
            AND    d.icode IN ($place)
            ORDER BY o.hn, o.vstdate, d.icode";
    $params = array_merge([$start, $end], $icodes);

    unset($GLOBALS['HAD_SOURCE_RX_FALLBACK']);
    try {
      $st = $db->prepare($sql(", {$rxCols}"));   // pdo_pgsql พังได้ทั้งตอน prepare และ execute
      $st->execute($params);
    } catch (PDOException $e) {
      $GLOBALS['HAD_SOURCE_RX_FALLBACK'] = $e->getMessage();
      $st = $db->prepare($sql(''));              // ถ้า HOSxP ล่มจริง query นี้ก็พังต่อ = exception เดิม
      $st->execute($params);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
      $r['rx_at'] = had_rx_at($r['rxdate'] ?? null, $r['rxtime'] ?? null);
      unset($r['rxdate'], $r['rxtime']);
    }
    unset($r);
    return $rows;
  }
}

if (!function_exists('had_source_count')) {
  /** นับจำนวนที่เข้าเงื่อนไข — ใช้โดยปุ่ม "ทดสอบ" ใน modal */
  function had_source_count(string $start, string $end, ?array $icodes = null): int {
    if ($icodes === null) {
      $icodes = function_exists('module_filter') ? (module_filter('had')['icodes'] ?? []) : [];
    }
    $icodes = array_values(array_filter($icodes, fn($x) => $x !== ''));
    if (!$icodes) return 0;

    $db    = hosxp_db();
    $place = implode(',', array_fill(0, count($icodes), '?'));
    $sql = "SELECT COUNT(*) FROM opitemrece o JOIN drugitems d ON o.icode = d.icode
            WHERE o.vstdate BETWEEN ? AND ? AND d.icode IN ($place)";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$start, $end], $icodes));
    return (int)($st->fetchColumn() ?: 0);
  }
}
