<?php
/**
 * Source Query: Dengue (ไข้เลือดออก — lab_items_code 2891, ผล Positive/Weakly Positive, pdx A90–A99)
 * ดู docs/adr/0001 + CONTEXT.md "Source Query"
 *
 * ใช้ร่วมโดย dengue_ingest.php (worker), dengue.php (display), dengue_queue_action.php (import)
 * → คืน superset ของคอลัมน์ + alias ครบทั้งชุด ingest (icd10_name/pdx/lab_order_result)
 *   และชุด display (disease/icd10/result) เพื่อให้ consumer เดิมใช้ key เดียวกันได้
 * Dialect: TIMESTAMPDIFF→age(), GROUP BY ov.vn → DISTINCT ON (ov.vn)
 * กันซ้ำที่ฝั่งเขียน MedAlert_DB
 */

if (!function_exists('dengue_source_rows')) {
  function dengue_source_rows(string $start, string $end, ?string $labCode = null, ?array $results = null, ?array $pdxPatterns = null): array {
    // null = ใช้เงื่อนไขจาก store (module_filter) — แก้ผ่าน modal ในหน้า queue_ui
    $mf = function_exists('module_filter') ? module_filter('dengue') : [];
    if ($labCode     === null) $labCode     = $mf['lab_code'] ?? '2891';
    if ($results     === null) $results     = $mf['results']  ?? ['Positive','Weakly Positive'];
    if ($pdxPatterns === null) $pdxPatterns = $mf['pdx']      ?? [['t'=>'range','from'=>'A90','to'=>'A99']];
    $results = function_exists('mf_texts') ? mf_texts($results) : array_values(array_filter($results, fn($x) => $x !== ''));
    if (!$results) return [];
    $resPlace = implode(',', array_fill(0, count($results), '?'));
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $ageExpr = ($driver === 'pgsql')
      ? "EXTRACT(YEAR FROM age(ov.vstdate, pt.birthday))"
      : "TIMESTAMPDIFF(YEAR, pt.birthday, ov.vstdate)";

    $cols = "ov.vn,
             ov.hn,
             CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
             {$ageExpr} AS age,
             CASE WHEN pt.sex='1' THEN 'ชาย' WHEN pt.sex='2' THEN 'หญิง' ELSE '' END AS sex,
             ov.cid,
             pt.informaddr AS address,
             pt.hometel,
             ov.vstdate,
             d.name AS doctor,
             i.name AS icd10_name,
             i.name AS disease,
             ov.pdx,
             ov.pdx AS icd10,
             l.lab_order_result,
             l.lab_order_result AS result,
             l.lab_order_number";

    // ICD range/prefix/exact ครอบ pdx+dx0-3 (bound params) — สร้างก่อนประกอบ WHERE
    $pdxParams = [];
    $pdxClause = mf_pdx_clause($pdxParams, ['ov.pdx','ov.dx0','ov.dx1','ov.dx2','ov.dx3'], $pdxPatterns);

    $from = "FROM   vn_stat ov
             LEFT  JOIN patient pt ON pt.hn  = ov.hn
             LEFT  JOIN icd101  i  ON i.code = ov.pdx
             LEFT  JOIN doctor  d  ON d.code = ov.dx_doctor
             INNER JOIN lab_head  h ON h.vn               = ov.vn
             INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number
             WHERE  ov.vstdate       BETWEEN ? AND ?
               AND  l.lab_items_code = ?
               AND  l.lab_order_result IN ($resPlace)
               AND  {$pdxClause}";

    if ($driver === 'pgsql') {
      $sql = "SELECT * FROM (SELECT DISTINCT ON (ov.vn) {$cols} {$from}
                ORDER BY ov.vn, ov.vstdate DESC) t ORDER BY vstdate DESC";
    } else {
      $sql = "SELECT {$cols} {$from} GROUP BY ov.vn ORDER BY ov.vstdate DESC";
    }
    $st = $db->prepare($sql);
    $st->execute(array_merge([$start, $end, $labCode], $results, $pdxParams));
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('dengue_source_by_vn')) {
  /** lookup รายเดียวตาม VN (ใช้โดย dengue_action.php) — คืน row เดียวหรือ null */
  function dengue_source_by_vn(string $vn, ?string $labCode = null): ?array {
    if ($labCode === null) {
      $labCode = function_exists('module_filter')
        ? (module_filter('dengue')['lab_code'] ?? '2891')
        : '2891';
    }
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $ageExpr = ($driver === 'pgsql')
      ? "EXTRACT(YEAR FROM age(ov.vstdate, pt.birthday))"
      : "TIMESTAMPDIFF(YEAR, pt.birthday, ov.vstdate)";
    $sql = "SELECT
              ov.vn, ov.hn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              {$ageExpr} AS age,
              CASE WHEN pt.sex='1' THEN 'ชาย' WHEN pt.sex='2' THEN 'หญิง' ELSE '' END AS sex,
              ov.cid, pt.informaddr AS address, pt.hometel, ov.vstdate,
              d.name AS doctor, i.name AS icd10_name, i.name AS disease,
              ov.pdx, ov.pdx AS icd10, l.lab_order_result, l.lab_order_result AS result, l.lab_order_number
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
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}
