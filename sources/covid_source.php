<?php
/**
 * Source Query: COVID-19 (lab_items_code 3066/3082/3084/3088, ผลบวก)
 * ดู docs/adr/0001 + CONTEXT.md "Source Query"
 *
 * ยุบจาก 6 สำเนาเดิม (covid.php, covid1.php, covid_alert_auto.php,
 *   covid_notify_dashboard.php, covid_queue_action.php, cron_covid_queue.php)
 * คืน superset ของคอลัมน์ให้ทุก consumer ใช้ key เดิมได้
 * Dialect: TIMESTAMPDIFF(...CURDATE())→age(), DATE(ov.vstdate)→::date, GROUP BY → DISTINCT ON
 *
 * @param $broadResult false = เฉพาะ 'Positive' (worker/dashboard เดิม)
 *                      true  = LOWER IN ('positive','detected','+') (ปุ่ม import เดิม)
 * กันซ้ำที่ฝั่งเขียน MedAlert_DB
 */

if (!function_exists('covid_source_rows')) {
  function covid_source_rows(string $start, string $end,
                             array $labCodes = ['3066','3082','3084','3088'],
                             bool $broadResult = false,
                             string $doctorName = ''): array {
    $labCodes = array_values(array_filter($labCodes, fn($x) => $x !== ''));
    if (!$labCodes) return [];
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $place  = implode(',', array_fill(0, count($labCodes), '?'));

    if ($driver === 'pgsql') {
      $ageExpr  = "EXTRACT(YEAR FROM age(pt.birthday))";
      $dateExpr = "ov.vstdate::date";
      $distinct = "DISTINCT ON (h.lab_order_number) ";
      $groupBy  = "";
      $orderBy  = "ORDER BY h.lab_order_number, h.report_date DESC";
      $wrapOpen = "SELECT * FROM ("; $wrapClose = ") t ORDER BY report_date DESC";
    } else {
      $ageExpr  = "TIMESTAMPDIFF(YEAR, pt.birthday, CURDATE())";
      $dateExpr = "DATE(ov.vstdate)";
      $distinct = "";
      $groupBy  = "GROUP BY h.lab_order_number";
      $orderBy  = "ORDER BY h.report_date DESC";
      $wrapOpen = ""; $wrapClose = "";
    }
    $resultCond = $broadResult
      ? "LOWER(l.lab_order_result) IN ('positive','detected','+')"
      : "l.lab_order_result = 'Positive'";

    $core = "SELECT {$distinct}
               h.lab_order_number,
               pt.hn,
               CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
               {$ageExpr}      AS age,
               pt.cid,
               pt.informaddr,
               pt.hometel,
               {$dateExpr}     AS vstdate,
               d.name          AS doctor,
               ov.pdx,
               l.lab_order_result,
               h.report_date
             FROM   lab_order  l
             INNER JOIN lab_head h  ON h.lab_order_number = l.lab_order_number
             LEFT  JOIN vn_stat  ov ON ov.vn  = h.vn
             LEFT  JOIN doctor   d  ON d.code  = ov.dx_doctor
             INNER JOIN patient  pt ON pt.hn   = ov.hn
             WHERE  {$dateExpr} BETWEEN ? AND ?
             AND    l.lab_items_code IN ($place)
             AND    {$resultCond}
             " . ($doctorName !== '' ? "AND d.name = ?" : "") . "
             {$groupBy}
             {$orderBy}";
    $sql = $wrapOpen . $core . $wrapClose;
    $params = array_merge([$start, $end], $labCodes);
    if ($doctorName !== '') $params[] = $doctorName;
    $st  = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
