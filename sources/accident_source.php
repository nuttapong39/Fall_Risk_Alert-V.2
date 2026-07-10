<?php
/**
 * Source Query: Accident  (ดู docs/adr/0001 + CONTEXT.md "Source Query")
 *
 * อ่านจาก HOSxP ผ่าน hosxp_db() — รองรับ MySQL (V3) และ PostgreSQL (XE4)
 *
 * หมายเหตุ: accident มี Source Query ที่ "ต่างกันจริง" 2 แบบ (ไม่ใช่สำเนาซ้ำ) จึงเก็บไว้ทั้งคู่:
 *   - ipt  : ผู้ป่วยใน (admission) pttype 33/35/36/39 — ใช้โดย accident_worker.php (cron)
 *   - ovst : ผู้ป่วยนอก (visit)  pttype จากผู้ใช้      — ใช้โดย accident_queue_action.php (ปุ่ม import)
 * ทั้งสองคืน row shape เดียวกัน: an, hn, fullname, regdate, regtime, pttype, pttname
 * การกันซ้ำทำที่ฝั่งเขียน MedAlert_DB (ON DUPLICATE KEY / existence check)
 */

if (!function_exists('accident_source_ipt_rows')) {
  /** Worker: ผู้ป่วยในอุบัติเหตุ (default pttype 33/35/36/39) — query portable ทั้งสอง dialect */
  function accident_source_ipt_rows(string $start, string $end, array $pttypes = ['33','35','36','39']): array {
    $pttypes = array_values(array_filter($pttypes, fn($x) => $x !== ''));
    if (!$pttypes) return [];
    $db    = hosxp_db();
    $place = implode(',', array_fill(0, count($pttypes), '?'));
    $sql = "SELECT
              ipt.an,
              pt.hn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              ipt.regdate,
              ipt.regtime,
              ipt.pttype,
              ptt.name AS pttname
            FROM ipt
              LEFT JOIN patient pt  ON pt.hn      = ipt.hn
              LEFT JOIN pttype  ptt ON ptt.pttype = ipt.pttype
            WHERE ipt.regdate BETWEEN ? AND ?
              AND ipt.pttype IN ($place)
            ORDER BY ipt.regdate DESC, ipt.regtime DESC, ipt.an DESC";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$start, $end], $pttypes));
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('accident_source_ovst_rows')) {
  /** UI import: ผู้ป่วยนอก ตาม pttype ที่ผู้ใช้ระบุ */
  function accident_source_ovst_rows(string $start, string $end, array $pttypes): array {
    $pttypes = array_values(array_filter($pttypes, fn($x) => $x !== ''));
    if (!$pttypes) return [];
    $db     = hosxp_db();
    $driver = $GLOBALS['DB_HOSXP']['driver'] ?? 'mysql';

    // ── dialect: ดึงวันที่/เวลา จาก vstdate ──
    if ($driver === 'pgsql') {
      $dateExpr = "ov.vstdate::date";
      $timeExpr = "to_char(ov.vstdate::timestamp,'HH24:MI')";
    } else {
      $dateExpr = "DATE(ov.vstdate)";
      $timeExpr = "SUBSTRING(TIME(ov.vstdate),1,5)";
    }
    $place = implode(',', array_fill(0, count($pttypes), '?'));

    $sql = "SELECT
              ov.vn                                                      AS an,
              ov.hn,
              CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
              {$dateExpr}                                                AS regdate,
              {$timeExpr}                                                AS regtime,
              ov.pttype,
              ptt.name                                                   AS pttname
            FROM   ovst ov
            LEFT JOIN patient pt  ON pt.hn      = ov.hn
            LEFT JOIN pttype  ptt ON ptt.pttype = ov.pttype
            WHERE  {$dateExpr} BETWEEN ? AND ?
            AND    ov.pttype IN ($place)
            AND    ov.hn IS NOT NULL AND ov.hn <> ''
            ORDER  BY ov.vstdate DESC";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$start, $end], $pttypes));
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
