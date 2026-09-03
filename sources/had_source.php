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

if (!function_exists('had_source_rows')) {
  function had_source_rows(string $start, string $end, ?array $icodes = null): array {
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

    $sql = "SELECT
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
              o.sum_price
            FROM   opitemrece o
            JOIN   drugitems  d ON o.icode = d.icode
            JOIN   patient    p ON o.hn    = p.hn
            WHERE  o.vstdate BETWEEN ? AND ?
            AND    d.icode IN ($place)
            ORDER BY o.hn, o.vstdate, d.icode";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$start, $end], $icodes));
    return $st->fetchAll(PDO::FETCH_ASSOC);
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
