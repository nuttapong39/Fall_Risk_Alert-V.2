<?php
/**
 * sources/lab_hemato_source.php — แหล่งข้อมูล Hematocrit Alert (อ่านจาก HOSxP อย่างเดียว)
 *
 * เงื่อนไขมาจาก module_filter('lab_hemato')['groups'] ทั้งหมด (แก้ผ่าน modal ในหน้า
 * lab_hemato_queue_ui.php) — ไม่มีรหัส Lab หรือค่า threshold hardcode ในไฟล์นี้เลย
 *
 * Dialect (ADR 0001 — HOSxP เป็น MySQL V3 หรือ PostgreSQL XE4):
 *   - อายุ  : TIMESTAMPDIFF(YEAR,...)  ↔  EXTRACT(YEAR FROM age(...))
 *   - ตัวเลข: l.lab_order_result + 0   ↔  l.lab_order_result::numeric
 *   - guard : NOT REGEXP / REGEXP      ↔  !~ / ~
 *
 * ⚠️ ต้อง guard ว่าผลเป็นตัวเลขล้วน "ก่อน" cast เสมอ — PostgreSQL จะ throw ทั้ง query
 *    ถ้าเจอ row ที่ cast ไม่ได้ แม้ row นั้นจะไม่เข้าเงื่อนไขก็ตาม
 */

if (!function_exists('lab_hemato_source_dialect')) {
  /** คืนนิพจน์ที่ต่างกันตาม driver — ใช้ร่วมทั้ง rows() และ count() */
  function lab_hemato_source_dialect(): array {
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    if ($driver === 'pgsql') {
      return [
        'age'      => "EXTRACT(YEAR FROM age(h.order_date, pt.birthday))",
        'num'      => "l.lab_order_result::numeric",
        'isNumber' => "l.lab_order_result ~ '^[0-9]+(\\.[0-9]+)?$'",
      ];
    }
    return [
      'age'      => "TIMESTAMPDIFF(YEAR, pt.birthday, h.order_date)",
      'num'      => "l.lab_order_result + 0",
      'isNumber' => "l.lab_order_result REGEXP '^[0-9]+(\\\\.[0-9]+)?$'",
    ];
  }
}

if (!function_exists('lab_hemato_source_where')) {
  /**
   * ท่อน WHERE ร่วม (ไม่รวม SELECT/JOIN) + params — ใช้ทั้งดึงจริงและนับ preview
   * @param array $params  (by-ref) params ที่จะ bind ต่อท้าย [start, end]
   */
  function lab_hemato_source_where(array &$params, ?array $groups = null): string {
    $d = lab_hemato_source_dialect();
    if ($groups === null) {
      $groups = function_exists('module_filter') ? (module_filter('lab_hemato')['groups'] ?? []) : [];
    }
    // mf_labconds_clause คืน '1=0' ถ้าไม่มีเงื่อนไขใช้ได้ → ไม่ดึงอะไรเลย (ปลอดภัยกว่าดึงหมด)
    $cond = mf_labconds_clause($params, $groups, $d['num']);
    return "h.order_date BETWEEN ? AND ?
            AND    l.lab_order_result IS NOT NULL
            AND    l.lab_order_result <> ''
            AND    {$d['isNumber']}
            AND    {$cond}";
  }
}

if (!function_exists('lab_hemato_source_rows')) {
  /** ดึงรายการที่เข้าเงื่อนไขจาก HOSxP (คืน array ของ row) */
  function lab_hemato_source_rows(string $start, string $end, ?array $groups = null): array {
    $db = hosxp_db();
    $d  = lab_hemato_source_dialect();

    $params = [$start, $end];
    $where  = lab_hemato_source_where($params, $groups);

    $sql = "SELECT
              h.lab_order_number,
              h.hn,
              h.vn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              {$d['age']} AS age,
              pt.sex        AS sex,
              pt.hometel    AS hometel,
              h.report_date AS lab_date,
              h.report_time AS lab_time,
              d.name        AS doctor,
              l.lab_items_code,
              l.lab_order_result AS result,
              'OPD' AS patient_type
            FROM   lab_head  h
            INNER JOIN lab_order l  ON l.lab_order_number = h.lab_order_number
            LEFT  JOIN vn_stat  vs  ON vs.vn              = h.vn
            LEFT  JOIN patient  pt  ON pt.hn              = h.hn
            LEFT  JOIN doctor   d   ON d.code             = vs.dx_doctor
            WHERE  {$where}
            ORDER  BY h.order_date DESC";

    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('lab_hemato_source_count')) {
  /** นับจำนวนที่เข้าเงื่อนไข — ใช้โดยปุ่ม "ทดสอบ" ใน modal (ยังไม่บันทึกค่า) */
  function lab_hemato_source_count(string $start, string $end, ?array $groups = null): int {
    $db     = hosxp_db();
    $params = [$start, $end];
    $where  = lab_hemato_source_where($params, $groups);

    $sql = "SELECT COUNT(*) AS c
            FROM   lab_head  h
            INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number
            WHERE  {$where}";
    $st = $db->prepare($sql);
    $st->execute($params);
    return (int)($st->fetchColumn() ?: 0);
  }
}
