<?php
/**
 * Source Query: Drug (ยาเฝ้าระวัง — opitemrece ตาม icode ที่กำหนด)
 * ดู docs/adr/0001 + CONTEXT.md "Source Query"
 *
 * ใช้ร่วมโดย drug_send.php (worker) และ drug_queue_action.php (UI import) — query เดียวกัน
 * อ่านผ่าน hosxp_db(). Dialect ที่ต่างกัน: คำนวณอายุ, DATE_FORMAT→to_char, GROUP BY→DISTINCT ON
 * Row shape: visit_vn, hn, fullname, cid, hometel, age, sex, address,
 *            drug_code, drug_name, vstdate, department, mainstation
 * กันซ้ำที่ฝั่งเขียน MedAlert_DB (ON DUPLICATE KEY)
 */

if (!function_exists('drug_source_rows')) {
  function drug_source_rows(string $start, string $end, array $icodes): array {
    $icodes = array_values(array_filter($icodes, fn($x) => $x !== ''));
    if (!$icodes) return [];
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    $place  = implode(',', array_fill(0, count($icodes), '?'));

    if ($driver === 'pgsql') {
      $vnExpr   = "COALESCE(NULLIF(TRIM(opi.vn),''), CONCAT(opi.hn,'-',to_char(opi.vstdate::timestamp,'YYYYMMDD'),'-',opi.icode))";
      $ageExpr  = "EXTRACT(YEAR FROM age(opi.vstdate, pt.birthday))";
      $distinct = "DISTINCT ON (opi.hn, opi.vstdate, opi.icode) ";
      $groupBy  = "";
      $orderBy  = "ORDER BY opi.hn, opi.vstdate, opi.icode";  // DISTINCT ON ต้องเรียงตาม key ก่อน
    } else {
      $vnExpr   = "COALESCE(NULLIF(TRIM(opi.vn),''), CONCAT(opi.hn,'-',DATE_FORMAT(opi.vstdate,'%Y%m%d'),'-',opi.icode))";
      $ageExpr  = "TIMESTAMPDIFF(YEAR, pt.birthday, opi.vstdate)";
      $distinct = "";
      $groupBy  = "GROUP BY opi.hn, opi.vstdate, opi.icode";
      $orderBy  = "ORDER BY opi.vstdate DESC";
    }

    $sql = "SELECT {$distinct}
              {$vnExpr}                                                   AS visit_vn,
              opi.hn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              pt.cid,
              pt.hometel,
              {$ageExpr}                                                  AS age,
              CASE WHEN pt.sex='1' THEN 'ชาย'
                   WHEN pt.sex='2' THEN 'หญิง' ELSE '' END                AS sex,
              pt.addrpart                                                 AS address,
              opi.icode                                                   AS drug_code,
              d.name                                                      AS drug_name,
              opi.vstdate,
              CONCAT(COALESCE(ost.name,''), ' : ', COALESCE(dep.department,'')) AS department,
              ''                                                          AS mainstation
            FROM   opitemrece opi
            LEFT JOIN patient        pt  ON pt.hn        = opi.hn
            LEFT JOIN drugitems      d   ON d.icode      = opi.icode
            LEFT JOIN ovst           ov  ON ov.hn        = opi.hn AND ov.vstdate = opi.vstdate
            LEFT JOIN ovstost        ost ON ost.ovstost  = ov.ovstost
            LEFT JOIN kskdepartment  dep ON dep.depcode  = ov.cur_dep
            WHERE  opi.vstdate BETWEEN ? AND ?
            AND    opi.icode   IN ($place)
            AND    opi.hn      IS NOT NULL AND opi.hn <> ''
            {$groupBy}
            {$orderBy}";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$start, $end], $icodes));
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('drugitems_fever_rows')) {
  /**
   * drugitems.php — หน้าแสดงคนไข้กลุ่มเสี่ยงกินยาอันตราย (icode 1483860) 20 รายล่าสุด
   * เป็น Source Query คนละตัวกับ drug_source_rows (ดึง ovst + opdscreen.hpi) จึงแยกฟังก์ชัน
   * คืนคีย์: hn, fullname, NAME (ชื่อยา), vstdate, hpi
   */
  function drugitems_fever_rows(): array {
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';
    if ($driver === 'pgsql') {
      $sql = "SELECT * FROM (
                SELECT DISTINCT ON (ovst.vn)
                  ovst.hn,
                  CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
                  d.name AS \"NAME\",
                  ovst.vstdate,
                  CAST(opd.hpi AS varchar(200)) AS hpi
                FROM ovst ovst
                  LEFT JOIN opdscreen  opd ON opd.vn   = ovst.vn
                  LEFT JOIN patient    pt  ON pt.hn     = ovst.hn
                  LEFT JOIN opitemrece op  ON op.hn     = ovst.hn
                  LEFT JOIN drugitems  d   ON d.icode   = op.icode
                WHERE ovst.vn IN (SELECT vn FROM opitemrece WHERE icode = '1483860')
                ORDER BY ovst.vn, ovst.vstdate DESC
              ) t ORDER BY vstdate DESC LIMIT 20";
    } else {
      $sql = "SELECT ovst.hn,
                CONCAT(pt.pname, pt.fname, ' ', pt.lname) AS fullname,
                d.NAME,
                ovst.vstdate,
                CAST(opd.hpi AS CHAR(200)) AS hpi
              FROM ovst ovst
                LEFT JOIN opdscreen  opd ON opd.vn   = ovst.vn
                LEFT JOIN patient    pt  ON pt.hn     = ovst.hn
                LEFT JOIN opitemrece op  ON op.hn     = ovst.hn
                LEFT JOIN drugitems  d   ON d.icode   = op.icode
              WHERE ovst.vn IN (SELECT vn FROM opitemrece WHERE icode = '1483860')
              GROUP BY ovst.vn
              ORDER BY vstdate DESC LIMIT 20";
    }
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }
}
