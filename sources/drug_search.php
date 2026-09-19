<?php
/**
 * sources/drug_search.php — ค้นหา/เติมชื่อยาจากตาราง drugitems (HOSxP, อ่านอย่างเดียว)
 *
 * ใช้โดย search_drug.php (endpoint สำหรับ tag-input picker ของ module drugs_alert)
 * และ partials/filter_drugpicker.php (field type 'drugpicker' ของ filter modal)
 *
 * คืนเฉพาะ 8 คอลัมน์ที่ต้องใช้แสดงในการ์ด/dropdown: icode, name, strength, units,
 * dosageform, unitprice, drugcategory, therapeutic (+ istatus ใช้ภายในเพื่อแยกยาเลิกใช้)
 *
 * SELECT อย่างเดียว (ADR-0001) — ห้ามเขียนกลับ HOSxP เด็ดขาด
 */

if (!function_exists('drug_search')) {
  /** ค้นจาก icode หรือชื่อยา (name) แบบ case-insensitive · ขั้นต่ำ 2 ตัวอักษร */
  function drug_search(string $q, int $limit = 20, bool $includeInactive = false): array {
    $q = trim($q);
    if (mb_strlen($q, 'UTF-8') < 2) return [];

    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $like   = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

    // escape wildcard ของผู้ใช้เอง กัน % / _ ในคำค้นทำ pattern เพี้ยน
    $needle = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

    $statusWhere = $includeInactive ? '' : "AND istatus = 'Y'";

    $sql = "SELECT icode, name, strength, units, dosageform, unitprice, drugcategory, therapeutic, istatus
            FROM   drugitems
            WHERE  (icode {$like} ? OR name {$like} ?)
            {$statusWhere}
            ORDER BY (istatus = 'Y') DESC, name ASC
            LIMIT {$limit}";
    $st = $db->prepare($sql);
    $st->execute([$needle, $needle]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('drug_lookup')) {
  /** เติมชื่อ/รายละเอียดยาให้ tag ที่บันทึกไว้แล้ว (icode ล้วน ไม่ค้นข้อความ) */
  function drug_lookup(array $icodes): array {
    $icodes = function_exists('mf_codes') ? mf_codes($icodes) : array_values(array_filter($icodes));
    $icodes = array_slice($icodes, 0, 200);
    if (!$icodes) return [];

    $db    = hosxp_db();
    $place = implode(',', array_fill(0, count($icodes), '?'));
    $sql   = "SELECT icode, name, strength, units, dosageform, unitprice, drugcategory, therapeutic, istatus
              FROM   drugitems
              WHERE  icode IN ($place)";
    $st = $db->prepare($sql);
    $st->execute($icodes);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('drug_dispense_counts')) {
  /**
   * นับจำนวนครั้งที่จ่ายยาแต่ละ icode ในช่วงวันที่ (จาก opitemrece) — ใช้โชว์ตัวเลข
   * "จ่ายเมื่อวาน+วันนี้ N ราย" ก่อนกดบันทึก tag ใหม่ ไม่เกี่ยวกับ drugs_alert_queue
   */
  function drug_dispense_counts(array $icodes, string $start, string $end): array {
    $icodes = function_exists('mf_codes') ? mf_codes($icodes) : array_values(array_filter($icodes));
    if (!$icodes) return [];

    $db    = hosxp_db();
    $place = implode(',', array_fill(0, count($icodes), '?'));
    $sql   = "SELECT icode, COUNT(*) AS n
              FROM   opitemrece
              WHERE  vstdate BETWEEN ? AND ?
              AND    icode IN ($place)
              GROUP BY icode";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$start, $end], $icodes));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['icode']] = (int)$r['n'];
    foreach ($icodes as $ic) if (!isset($out[$ic])) $out[$ic] = 0;
    return $out;
  }
}
