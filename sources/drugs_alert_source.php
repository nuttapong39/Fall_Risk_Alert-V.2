<?php
/**
 * sources/drugs_alert_source.php — แหล่งข้อมูล Drug Alert (ยาเฝ้าระวังที่เภสัชเลือกเอง) จาก HOSxP
 *
 * รหัสยา (icode) มาจาก module_filter('drugs_alert')['icodes'] เท่านั้น — เภสัชเลือกยาผ่าน
 * tag-input picker ในหน้า drugs_alert.php (ค้นจาก drugitems, sources/drug_search.php)
 * ไม่มี default ฝังไว้โดยตั้งใจ (fail-closed แบบ lab_hemato) — list ว่าง = ไม่ดึงอะไรเลย
 * จนกว่าเภสัชจะเลือกยาจริงผ่านหน้าเว็บ
 *
 * Dialect (ADR 0001): อายุ TIMESTAMPDIFF(...) ↔ EXTRACT(YEAR FROM age(...))
 * unique key ของ drugs_alert_queue = (hn, icode, vstdate) — คนไข้ 1 รายรับยาเฝ้าระวังหลาย
 * ชนิดวันเดียวกันได้ (แพทเทิร์นเดียวกับ had_queue)
 */

if (!function_exists('drugs_alert_source_rows')) {
  function drugs_alert_source_rows(string $start, string $end, ?array $icodes = null): array {
    if ($icodes === null) {
      $icodes = function_exists('module_filter') ? (module_filter('drugs_alert')['icodes'] ?? []) : [];
    }
    $icodes = array_values(array_filter($icodes, fn($x) => $x !== ''));
    if (!$icodes) return []; // ยังไม่ได้เลือกยา — ไม่ดึงอะไรเลย

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
              d.strength,
              d.units,
              o.vstdate,
              o.qty,
              o.sum_price
            FROM   opitemrece o
            JOIN   drugitems  d ON o.icode = d.icode
            JOIN   patient    p ON o.hn    = p.hn
            WHERE  o.vstdate BETWEEN ? AND ?
            AND    d.icode IN ($place)
            AND    o.hn IS NOT NULL AND o.hn <> ''
            ORDER BY o.hn, o.vstdate, d.icode";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$start, $end], $icodes));
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
