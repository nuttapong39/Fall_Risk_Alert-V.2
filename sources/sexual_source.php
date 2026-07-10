<?php
/**
 * Source Query: Sexual (โรคติดต่อทางเพศสัมพันธ์ — lab_items_code = LAB_CODE_SEXUAL)
 * ดู docs/adr/0001 + CONTEXT.md "Source Query"
 * ใช้ร่วมโดย sexual_ingest.php (worker) และ sexual_action.php (UI import) — query เดียวกัน
 * ไม่มี GROUP BY (1 แถวต่อ lab order) — dialect ที่ต่างมีแค่การคำนวณอายุ
 * Row shape: vn, hn, fullname, cid, hometel, address, age, sex,
 *            lab_date, lab_time, lab_items_name_ref, lab_order_result, lab_order_number
 * กันซ้ำที่ฝั่งเขียน MedAlert_DB (ON DUPLICATE KEY / existence check)
 */

if (!function_exists('sexual_source_rows')) {
  function sexual_source_rows(string $start, string $end, string $labCode): array {
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $ageExpr = ($driver === 'pgsql')
      ? "EXTRACT(YEAR FROM age(h.order_date, pt.birthday))"
      : "TIMESTAMPDIFF(YEAR, pt.birthday, h.order_date)";

    $sql = "SELECT
              h.vn,
              h.hn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              pt.cid,
              pt.hometel,
              pt.addrpart AS address,
              {$ageExpr}  AS age,
              CASE WHEN pt.sex='1' THEN 'ชาย' WHEN pt.sex='2' THEN 'หญิง' ELSE '' END AS sex,
              h.order_date  AS lab_date,
              h.report_time AS lab_time,
              l.lab_items_name_ref,
              l.lab_order_result,
              l.lab_order_number
            FROM   lab_order   l
            INNER JOIN lab_head  h  ON h.lab_order_number = l.lab_order_number
            LEFT  JOIN patient   pt ON pt.hn              = h.hn
            WHERE  l.lab_items_code = ?
            AND    h.order_date     BETWEEN ? AND ?
            AND    l.lab_order_result IS NOT NULL
            AND    l.lab_order_result <> ''
            ORDER  BY h.order_date DESC";
    $st = $db->prepare($sql);
    $st->execute([$labCode, $start, $end]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('sexual_source_by_vn')) {
  /** lookup รายเดียวตาม VN (ใช้โดย sexual_action.php สำหรับ preview/ส่งราย VN) */
  function sexual_source_by_vn(string $vn, string $labCode): ?array {
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $ageExpr = ($driver === 'pgsql')
      ? "EXTRACT(YEAR FROM age(h.order_date, pt.birthday))"
      : "TIMESTAMPDIFF(YEAR, pt.birthday, h.order_date)";
    $sql = "SELECT h.vn, h.hn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              pt.cid, pt.hometel, pt.addrpart AS address,
              {$ageExpr} AS age,
              CASE WHEN pt.sex='1' THEN 'ชาย' WHEN pt.sex='2' THEN 'หญิง' ELSE '' END AS sex,
              h.order_date, l.lab_items_name_ref, l.lab_order_result
            FROM   lab_order l
            INNER JOIN lab_head h  ON l.lab_order_number = h.lab_order_number
            LEFT  JOIN patient  pt ON pt.hn = h.hn
            WHERE  h.vn = ? AND l.lab_items_code = ?
            ORDER  BY l.lab_order_number DESC
            LIMIT 1";
    $st = $db->prepare($sql);
    $st->execute([$vn, $labCode]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}
