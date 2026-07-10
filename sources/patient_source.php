<?php
/**
 * Source Query: Patient (กลุ่มเสี่ยงทำร้ายตัวเอง/ถูกทำร้าย — ICD T71, X60–X69, X70, X84)
 * ดู docs/adr/0001 + CONTEXT.md "Source Query"
 *
 * อ่านจาก HOSxP ผ่าน hosxp_db(). ใช้คอลัมน์ ov.age_y (สำเร็จรูป) → query portable
 * ทั้ง MySQL (V3) และ PostgreSQL (XE4) โดยไม่ต้องแยก dialect
 * Row shape: visit_vn, hn, fullname, cid, hometel, age, sex, address, pdx_code, pdx_name, vstdate, mainstation
 * กันซ้ำที่ฝั่งเขียน MedAlert_DB (ON DUPLICATE KEY)
 */

if (!function_exists('patient_source_rows')) {
  function patient_source_rows(string $start, string $end): array {
    $db = hosxp_db();

    // ICD-10: T71, X60–X69, X70, X84 — ตรวจทั้ง pdx และ dx0..dx3
    $codeConds = [];
    foreach (['ov.pdx','ov.dx0','ov.dx1','ov.dx2','ov.dx3'] as $col) {
      $codeConds[] = "UPPER($col) = 'T71'";
      foreach (['X60','X61','X62','X63','X64','X65','X66','X67','X68','X69','X70','X84'] as $pfx) {
        $codeConds[] = "UPPER($col) LIKE '{$pfx}%'";
      }
    }
    $codeWhere = '(' . implode(' OR ', $codeConds) . ')';

    $sql = "SELECT
              ov.vn AS visit_vn,
              pt.hn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              pt.cid,
              pt.hometel,
              ov.age_y AS age,
              se.name  AS sex,
              pt.informaddr AS address,
              ov.pdx   AS pdx_code,
              ic.name  AS pdx_name,
              ov.vstdate,
              COALESCE(h.name, '') AS mainstation
            FROM vn_stat ov
            LEFT JOIN patient  pt ON pt.hn      = ov.hn
            LEFT JOIN sex      se ON pt.sex     = se.code
            LEFT JOIN icd101   ic ON ov.pdx     = ic.code
            LEFT JOIN ovst   ovst ON ovst.vn    = ov.vn
            LEFT JOIN hospcode h  ON h.hospcode = ovst.hospsub
            WHERE ov.vstdate BETWEEN ? AND ?
              AND {$codeWhere}
            ORDER BY ov.vstdate DESC, ov.vn DESC";
    $st = $db->prepare($sql);
    $st->execute([$start, $end]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
