<?php
/**
 * Source Query: Scrub Typhus (lab_items_code 291, ผล Positive, pdx A753)
 * ดู docs/adr/0001 + CONTEXT.md "Source Query"
 * ใช้โดย scrubtyphus.php (display ช่วงวัน) และ scrub_action.php (lookup ราย VN)
 * Dialect: TIMESTAMPDIFF→age(), GROUP BY ov.vn → DISTINCT ON
 */

if (!function_exists('scrub_source_rows')) {
  function scrub_source_rows(string $start, string $end, ?string $labCode = null, ?array $results = null, ?array $pdxCodes = null): array {
    // null = ใช้เงื่อนไขจาก store (module_filter) — แก้ผ่าน modal ในหน้า queue_ui
    $mf = function_exists('module_filter') ? module_filter('scrub') : [];
    if ($labCode  === null) $labCode  = $mf['lab_code']  ?? '291';
    if ($results  === null) $results  = $mf['results']   ?? ['Positive'];
    if ($pdxCodes === null) $pdxCodes = $mf['pdx_codes'] ?? ['A753'];
    $results  = function_exists('mf_texts') ? mf_texts($results) : array_values(array_filter($results,  fn($x) => $x !== ''));
    $pdxCodes = function_exists('mf_codes') ? mf_codes($pdxCodes) : array_values(array_filter($pdxCodes, fn($x) => $x !== ''));
    if (!$results || !$pdxCodes) return [];
    $resPlace = implode(',', array_fill(0, count($results), '?'));
    $pdxPlace = implode(',', array_fill(0, count($pdxCodes), '?'));
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $ageExpr = ($driver === 'pgsql')
      ? "EXTRACT(YEAR FROM age(ov.vstdate, pt.birthday))"
      : "TIMESTAMPDIFF(YEAR, pt.birthday, ov.vstdate)";
    $cols = "ov.vn, ov.hn,
             CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
             {$ageExpr} AS age,
             CASE WHEN pt.sex='1' THEN 'ชาย' WHEN pt.sex='2' THEN 'หญิง' ELSE '' END AS sex,
             ov.cid, pt.informaddr AS address, pt.hometel, ov.vstdate,
             d.name AS doctor, i.name AS disease, ov.pdx AS icd10, l.lab_order_result AS result";
    $from = "FROM   vn_stat ov
             LEFT  JOIN patient pt ON pt.hn  = ov.hn
             LEFT  JOIN icd101  i  ON i.code = ov.pdx
             LEFT  JOIN doctor  d  ON d.code = ov.dx_doctor
             INNER JOIN lab_head  h ON h.vn               = ov.vn
             INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number
             WHERE  ov.vstdate       BETWEEN ? AND ?
               AND  l.lab_items_code = ?
               AND  l.lab_order_result IN ($resPlace)
               AND  ov.pdx IN ($pdxPlace)";
    $sql = ($driver === 'pgsql')
      ? "SELECT * FROM (SELECT DISTINCT ON (ov.vn) {$cols} {$from} ORDER BY ov.vn, ov.vstdate DESC) t ORDER BY vstdate DESC"
      : "SELECT {$cols} {$from} GROUP BY ov.vn ORDER BY ov.vstdate DESC";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$start, $end, $labCode], $results, $pdxCodes));
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('scrub_source_by_vn')) {
  function scrub_source_by_vn(string $vn, ?string $labCode = null): ?array {
    if ($labCode === null) {
      $labCode = function_exists('module_filter')
        ? (module_filter('scrub')['lab_code'] ?? '291')
        : '291';
    }
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $ageExpr = ($driver === 'pgsql')
      ? "EXTRACT(YEAR FROM age(ov.vstdate, pt.birthday))"
      : "TIMESTAMPDIFF(YEAR, pt.birthday, ov.vstdate)";
    $sql = "SELECT ov.vn, ov.hn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              {$ageExpr} AS age,
              CASE WHEN pt.sex='1' THEN 'ชาย' WHEN pt.sex='2' THEN 'หญิง' ELSE '' END AS sex,
              ov.cid, pt.informaddr AS address, pt.hometel, ov.vstdate,
              d.name AS doctor, i.name AS disease, ov.pdx AS icd10, l.lab_order_result AS result
            FROM   vn_stat ov
            LEFT  JOIN patient pt ON pt.hn  = ov.hn
            LEFT  JOIN icd101  i  ON i.code = ov.pdx
            LEFT  JOIN doctor  d  ON d.code = ov.dx_doctor
            INNER JOIN lab_head  h ON h.vn               = ov.vn
            INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number
            WHERE  ov.vn = ? AND l.lab_items_code = ?
            ORDER BY l.lab_order_number DESC
            LIMIT 1";
    $st = $db->prepare($sql);
    $st->execute([$vn, $labCode]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}
