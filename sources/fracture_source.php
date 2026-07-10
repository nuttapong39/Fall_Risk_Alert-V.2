<?php
/**
 * Source Query: Fracture  (ดู docs/adr/0001 + CONTEXT.md "Source Query")
 *
 * อ่านผู้ป่วยหกล้ม/กระดูกหักจาก HOSxP ผ่าน hosxp_db() — รองรับทั้ง
 * MySQL (HOSxP V3) และ PostgreSQL (HOSxPXE4) โดยสลับเฉพาะส่วนที่ dialect ต่างกัน
 *
 * Condition (canonical): ทุก visit, อายุ >= minAge, ICD-10 W00–W19 หรือ S-codes กระดูกหัก
 * Row shape ที่คืน: visit_vn, hn, fullname, cid, hometel, age, sex,
 *                   address, pdx_code, pdx_name, vstdate, mainstation
 *
 * การกันซ้ำ (dedup) ทำที่ฝั่งเขียน MedAlert_DB (ON DUPLICATE KEY / existence check)
 * ไม่ทำใน SQL นี้ เพราะ fracture_queue อยู่คนละ datastore กับ HOSxP
 */

if (!function_exists('fracture_source_rows')) {
  function fracture_source_rows(string $start, string $end, int $minAge = 60): array {
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';

    // ── ส่วนที่ dialect ต่างกัน: การคำนวณอายุจากวันเกิด ──
    $ageExpr = ($driver === 'pgsql')
      ? "EXTRACT(YEAR FROM age(vs.vstdate, pt.birthday))"   // PostgreSQL (XE4)
      : "TIMESTAMPDIFF(YEAR, pt.birthday, vs.vstdate)";     // MySQL (V3)

    $sql = "SELECT
              vs.vn                                        AS visit_vn,
              vs.hn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              pt.cid,
              pt.hometel,
              {$ageExpr}                                   AS age,
              CASE WHEN pt.sex='1' THEN 'ชาย'
                   WHEN pt.sex='2' THEN 'หญิง' ELSE '' END AS sex,
              pt.informaddr                                AS address,
              vs.pdx                                       AS pdx_code,
              i.name                                       AS pdx_name,
              vs.vstdate,
              COALESCE(h.name,'')                          AS mainstation
            FROM   vn_stat vs
            LEFT JOIN patient  pt   ON pt.hn      = vs.hn
            LEFT JOIN icd101   i    ON i.code     = vs.pdx
            LEFT JOIN ovst     ovst ON ovst.vn    = vs.vn
            LEFT JOIN hospcode h    ON h.hospcode = ovst.hospsub
            WHERE  vs.vstdate BETWEEN ? AND ?
            AND    {$ageExpr} >= ?
            AND    (
              (UPPER(vs.pdx) BETWEEN 'W00' AND 'W19')
              OR UPPER(vs.pdx) LIKE 'S720%' OR UPPER(vs.pdx) LIKE 'S721%'
              OR UPPER(vs.pdx) LIKE 'S722%' OR UPPER(vs.pdx) LIKE 'S525%'
              OR UPPER(vs.pdx) LIKE 'S526%' OR UPPER(vs.pdx) LIKE 'S422%'
              OR UPPER(vs.pdx) LIKE 'S220%' OR UPPER(vs.pdx) LIKE 'S221%'
              OR UPPER(vs.pdx) LIKE 'S320%' OR UPPER(vs.pdx) LIKE 'S327%'
            )
            AND    vs.hn IS NOT NULL AND vs.hn <> ''
            ORDER  BY vs.vstdate DESC";

    $st = $db->prepare($sql);
    $st->execute([$start, $end, $minAge]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
