<?php
/**
 * Source Query: Leptospirosis (lab_items_code 290, pdx A270/A278/A279/A418)
 * ดู docs/adr/0001 + CONTEXT.md "Source Query"
 * ใช้โดย Leptospira.php (display ช่วงวัน) และ lepto_action.php (lookup ราย VN)
 * Dialect: TIMESTAMPDIFF→age(), GROUP BY ov.vn → DISTINCT ON
 */

if (!function_exists('lepto_source_rows')) {
  function lepto_source_rows(string $start, string $end, ?string $labCode = null, ?array $pdxCodes = null): array {
    // null = ใช้เงื่อนไขจาก store (module_filter) — แก้ผ่าน modal ในหน้า queue_ui
    $mf = function_exists('module_filter') ? module_filter('lepto') : [];
    if ($labCode  === null) $labCode  = $mf['lab_code']  ?? '290';
    if ($pdxCodes === null) $pdxCodes = $mf['pdx_codes'] ?? ['A270','A278','A279','A418'];
    $pdxCodes = function_exists('mf_codes')
      ? mf_codes($pdxCodes)
      : array_values(array_filter($pdxCodes, fn($x) => $x !== ''));
    if (!$pdxCodes) return [];
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
               AND  ov.pdx IN ($pdxPlace)";
    $sql = ($driver === 'pgsql')
      ? "SELECT * FROM (SELECT DISTINCT ON (ov.vn) {$cols} {$from} ORDER BY ov.vn, ov.vstdate DESC) t ORDER BY vstdate DESC"
      : "SELECT {$cols} {$from} GROUP BY ov.vn ORDER BY ov.vstdate DESC";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$start, $end, $labCode], $pdxCodes));
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('lepto_source_by_vn')) {
  function lepto_source_by_vn(string $vn, ?string $labCode = null): ?array {
    if ($labCode === null) {
      $labCode = function_exists('module_filter')
        ? (module_filter('lepto')['lab_code'] ?? '290')
        : '290';
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
