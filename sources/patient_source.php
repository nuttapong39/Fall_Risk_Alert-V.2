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
  function patient_source_rows(string $start, string $end, ?array $icdPatterns = null): array {
    $db = hosxp_db();

    // ICD-10 (default T71, X60–X69, X70, X84) จาก store (module_filter) — แก้ผ่าน modal ในหน้า queue_ui
    // ตรวจทั้ง pdx และ dx0..dx3 · exact/prefix แบบ portable (UPPER + LIKE) ไม่พึ่ง SIMILAR TO
    if ($icdPatterns === null) {
      $icdPatterns = function_exists('module_filter')
        ? (module_filter('patient')['icd'] ?? [])
        : array_merge(
            [['t'=>'exact','v'=>'T71']],
            array_map(fn($p) => ['t'=>'prefix','v'=>$p],
                      ['X60','X61','X62','X63','X64','X65','X66','X67','X68','X69','X70','X84'])
          );
    }
    $codeParams = [];
    $codeWhere  = mf_pdx_clause($codeParams, ['ov.pdx','ov.dx0','ov.dx1','ov.dx2','ov.dx3'], $icdPatterns, true);

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
    $st->execute(array_merge([$start, $end], $codeParams));
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
