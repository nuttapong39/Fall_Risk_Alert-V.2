<?php
/**
 * Source Query: Pharm Lab (Lab วิกฤต/เฝ้าระวังห้องยา)
 *   INR(539)≥5, Depakin(2368)>150, Lithium(697/2388)>1.2, Phenytoin(2370)>20
 *   + ผลที่เป็น text (ไม่ขึ้นต้นด้วยตัวเลข) ของ 2368/697/2388/2370 = แจ้งเสมอ
 * ดู docs/adr/0001 + CONTEXT.md "Source Query"
 *
 * ใช้ร่วมโดย pharm_lab_queue_action.php (import) และ pharm_lab_queue_ui.php (inline sync)
 * Dialect:
 *   - อายุ: TIMESTAMPDIFF→age()
 *   - threshold: MySQL ใช้ `result + 0` (cast เลขนำหน้า) + `NOT REGEXP '^[0-9]'`
 *                PostgreSQL ใช้ substring(...)::numeric + `!~ '^[0-9]'`
 * Row shape: lab_order_number, hn, fullname, age, lab_date, lab_time, doctor,
 *            lab_items_code, result, patient_type
 * (การ map lab_items_code → lab_name ทำใน PHP ฝั่ง consumer; กันซ้ำที่ฝั่งเขียน MedAlert_DB)
 */

if (!function_exists('pharm_lab_source_rows')) {
  function pharm_lab_source_rows(string $start, string $end): array {
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';

    if ($driver === 'pgsql') {
      $ageExpr = "EXTRACT(YEAR FROM age(h.order_date, pt.birthday))";
      // เลขนำหน้าของผล เช่น '9.26 R' → 9.26 ; ผล text → NULL
      // PostgreSQL: substring(... from pattern) คืนเฉพาะ "กลุ่มแรก" ถ้ามีวงเล็บจับกลุ่ม
      // จึงต้องใช้ non-capturing group (?:...) เพื่อให้คืนเลขเต็มตัว (เช่น '6.54 R' → '6.54' ไม่ใช่ '.54')
      $num     = "COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\\.[0-9]+)?'),'')::numeric, 0)";
      $isText  = "l.lab_order_result !~ '^[0-9]'";
      $threshold = "(
          (l.lab_items_code = '539'  AND {$num} >= 5)
          OR (l.lab_items_code = '2368' AND ({$isText} OR {$num} > 150))
          OR (l.lab_items_code IN ('697','2388') AND ({$isText} OR {$num} > 1.2))
          OR (l.lab_items_code = '2370' AND ({$isText} OR {$num} > 20))
        )";
    } else {
      $ageExpr = "TIMESTAMPDIFF(YEAR, pt.birthday, h.order_date)";
      $threshold = "(
          (l.lab_items_code = '539'  AND l.lab_order_result + 0 >= 5)
          OR (l.lab_items_code = '2368' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 150))
          OR (l.lab_items_code IN ('697','2388') AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 1.2))
          OR (l.lab_items_code = '2370' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 20))
        )";
    }

    $sql = "SELECT
              h.lab_order_number,
              h.hn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              {$ageExpr} AS age,
              h.report_date AS lab_date,
              h.report_time AS lab_time,
              d.name        AS doctor,
              l.lab_items_code,
              l.lab_order_result AS result,
              'OPD' AS patient_type
            FROM   lab_head  h
            INNER JOIN lab_order l   ON l.lab_order_number = h.lab_order_number
            LEFT  JOIN vn_stat  vs   ON vs.vn              = h.vn
            LEFT  JOIN patient  pt   ON pt.hn              = h.hn
            LEFT  JOIN doctor   d    ON d.code             = vs.dx_doctor
            WHERE  h.order_date BETWEEN ? AND ?
            AND    l.lab_order_result IS NOT NULL
            AND    l.lab_order_result <> ''
            AND    {$threshold}
            ORDER  BY h.order_date DESC";
    $st = $db->prepare($sql);
    $st->execute([$start, $end]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('pharm_lab_worker_sqls')) {
  /**
   * SQL ต้นแบบของ worker (pharm_lab.php) — แยก OPD (ผ่าน ov.vn) / IPD (ผ่าน s.an)
   * ไม่กรอง threshold ใน SQL (worker คัดเกณฑ์ใน PHP ด้วย pharm_classify_row)
   * คืน ['opd'=>sql, 'ipd'=>sql] โดยใส่ ? placeholder ของ lab_items_code ตาม $placeholders
   * Dialect: TIMESTAMPDIFF(...CURDATE())→age(). params ต่อ query: [start, end, ...codes]
   */
  function pharm_lab_worker_sqls(string $placeholders): array {
    $driver  = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $ageExpr = ($driver === 'pgsql')
      ? "EXTRACT(YEAR FROM age(pt.birthday))"
      : "TIMESTAMPDIFF(YEAR, pt.birthday, CURDATE())";
    $opd = "
      SELECT
        pt.hn,
        CONCAT(COALESCE(pt.pname,''),' ',COALESCE(pt.fname,''),' ',COALESCE(pt.lname,'')) AS fullname,
        {$ageExpr} AS age,
        h.report_date AS lab_date,
        h.report_time AS lab_time,
        d.name        AS doctor,
        l.lab_items_code   AS lab_items_code,
        l.lab_order_result AS result,
        l.lab_order_number AS lab_order_number,
        'OPD'         AS patient_type
      FROM ovst s
        INNER JOIN vn_stat  ov ON ov.vn = s.vn
        LEFT  JOIN patient  pt ON pt.hn = s.hn
        LEFT  JOIN doctor   d  ON d.code = ov.dx_doctor
        INNER JOIN lab_head  h ON h.vn  = ov.vn
        INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number
      WHERE h.order_date BETWEEN ? AND ?
        AND l.lab_items_code IN ($placeholders)
        AND l.lab_order_result IS NOT NULL
        AND l.lab_order_result <> ''
    ";
    $ipd = "
      SELECT
        pt.hn,
        CONCAT(COALESCE(pt.pname,''),' ',COALESCE(pt.fname,''),' ',COALESCE(pt.lname,'')) AS fullname,
        {$ageExpr} AS age,
        h1.report_date AS lab_date,
        h1.report_time AS lab_time,
        d.name         AS doctor,
        l1.lab_items_code   AS lab_items_code,
        l1.lab_order_result AS result,
        l1.lab_order_number AS lab_order_number,
        'IPD'          AS patient_type
      FROM ovst s
        INNER JOIN vn_stat  ov ON ov.vn = s.vn
        LEFT  JOIN patient  pt ON pt.hn = s.hn
        LEFT  JOIN doctor   d  ON d.code = ov.dx_doctor
        INNER JOIN lab_head  h1 ON h1.vn = s.an
        INNER JOIN lab_order l1 ON l1.lab_order_number = h1.lab_order_number
      WHERE h1.order_date BETWEEN ? AND ?
        AND s.an IS NOT NULL AND s.an <> ''
        AND l1.lab_items_code IN ($placeholders)
        AND l1.lab_order_result IS NOT NULL
        AND l1.lab_order_result <> ''
    ";
    return ['opd' => $opd, 'ipd' => $ipd];
  }
}
