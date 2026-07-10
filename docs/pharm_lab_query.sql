-- ============================================================================
--  pharm_lab — Query เกณฑ์ Lab วิกฤต/เฝ้าระวังห้องยา (พร้อมรัน)
--  INR(539)≥5 · Depakin(2368)>150 · Lithium(697/2388)>1.2 · Phenytoin(2370)>20
--  + ผลที่เป็น text (ไม่ขึ้นต้นด้วยตัวเลข) ของ 2368/697/2388/2370 = แจ้งเสมอ
--  แก้ช่วงวันที่ที่ 'YYYY-MM-DD' ได้เลย
--  อ้างอิงโค้ดจริง: sources/pharm_lab_source.php
-- ============================================================================


-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  A) MySQL / MariaDB  (HOSxP V3)                                            ║
-- ╚══════════════════════════════════════════════════════════════════════════╝
SELECT
    h.lab_order_number,
    h.hn,
    CONCAT(COALESCE(pt.pname,''), pt.fname, ' ', pt.lname)        AS fullname,
    TIMESTAMPDIFF(YEAR, pt.birthday, h.order_date)               AS age,
    h.order_date                                                  AS lab_date,
    h.report_time                                                 AS lab_time,
    d.name                                                        AS doctor,
    l.lab_items_code,
    CASE l.lab_items_code
        WHEN '539'  THEN 'INR'
        WHEN '2368' THEN 'Depakin level'
        WHEN '697'  THEN 'Lithium level'
        WHEN '2388' THEN 'Lithium level'
        WHEN '2370' THEN 'Phenytoin level'
    END                                                           AS lab_name,
    l.lab_order_result                                            AS result
FROM   lab_head  h
INNER JOIN lab_order l  ON l.lab_order_number = h.lab_order_number
LEFT  JOIN vn_stat  vs  ON vs.vn  = h.vn
LEFT  JOIN patient  pt  ON pt.hn  = h.hn
LEFT  JOIN doctor   d   ON d.code = vs.dx_doctor
WHERE  h.order_date BETWEEN '2025-01-01' AND '2025-12-31'
AND    l.lab_order_result IS NOT NULL
AND    l.lab_order_result <> ''
AND (
    (l.lab_items_code = '539'  AND l.lab_order_result + 0 >= 5)
    OR (l.lab_items_code = '2368' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 150))
    OR (l.lab_items_code IN ('697','2388') AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 1.2))
    OR (l.lab_items_code = '2370' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 20))
)
ORDER BY h.order_date DESC;


-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  B) PostgreSQL  (HOSxPXE4)                                                 ║
-- ║     - TIMESTAMPDIFF → age() ; + 0 → ดึงเลขนำหน้าด้วย substring()::numeric  ║
-- ║     - NOT REGEXP '^[0-9]' → !~ '^[0-9]'                                    ║
-- ╚══════════════════════════════════════════════════════════════════════════╝
SELECT
    h.lab_order_number,
    h.hn,
    CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
    EXTRACT(YEAR FROM age(h.order_date, pt.birthday))            AS age,
    h.order_date                                                  AS lab_date,
    h.report_time                                                 AS lab_time,
    d.name                                                        AS doctor,
    l.lab_items_code,
    CASE l.lab_items_code
        WHEN '539'  THEN 'INR'
        WHEN '2368' THEN 'Depakin level'
        WHEN '697'  THEN 'Lithium level'
        WHEN '2388' THEN 'Lithium level'
        WHEN '2370' THEN 'Phenytoin level'
    END                                                           AS lab_name,
    l.lab_order_result                                            AS result
FROM   lab_head  h
INNER JOIN lab_order l  ON l.lab_order_number = h.lab_order_number
LEFT  JOIN vn_stat  vs  ON vs.vn  = h.vn
LEFT  JOIN patient  pt  ON pt.hn  = h.hn
LEFT  JOIN doctor   d   ON d.code = vs.dx_doctor
WHERE  h.order_date BETWEEN date_trunc('year', CURRENT_DATE)::date AND CURRENT_DATE  -- ปีนี้ถึงวันนี้ (ปัจจุบันอัตโนมัติ)
AND    l.lab_order_result IS NOT NULL
AND    l.lab_order_result <> ''
AND (
    (l.lab_items_code = '539'
        AND COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric, 0) >= 5)
    OR (l.lab_items_code = '2368'
        AND (l.lab_order_result !~ '^[0-9]'
             OR COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric, 0) > 150))
    OR (l.lab_items_code IN ('697','2388')
        AND (l.lab_order_result !~ '^[0-9]'
             OR COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric, 0) > 1.2))
    OR (l.lab_items_code = '2370'
        AND (l.lab_order_result !~ '^[0-9]'
             OR COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric, 0) > 20))
)
ORDER BY h.order_date DESC;


-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  C) AUDIT — นับแยกตาม lab code (verify จำนวน)                              ║
-- ╚══════════════════════════════════════════════════════════════════════════╝
-- MySQL
SELECT l.lab_items_code,
       CASE l.lab_items_code
           WHEN '539' THEN 'INR' WHEN '2368' THEN 'Depakin'
           WHEN '697' THEN 'Lithium' WHEN '2388' THEN 'Lithium(alt)'
           WHEN '2370' THEN 'Phenytoin' END AS lab_name,
       COUNT(*) AS total
FROM   lab_head h
INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number
WHERE  h.order_date BETWEEN '2025-01-01' AND '2025-12-31'
AND    l.lab_order_result IS NOT NULL AND l.lab_order_result <> ''
AND (
    (l.lab_items_code = '539'  AND l.lab_order_result + 0 >= 5)
    OR (l.lab_items_code = '2368' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 150))
    OR (l.lab_items_code IN ('697','2388') AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 1.2))
    OR (l.lab_items_code = '2370' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 20))
)
GROUP BY l.lab_items_code
ORDER BY total DESC;

-- PostgreSQL (เปลี่ยนเฉพาะ block AND (...) เป็นแบบ !~ / substring()::numeric เหมือน B)
SELECT l.lab_items_code, COUNT(*) AS total
FROM   lab_head h
INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number
WHERE  h.order_date BETWEEN date_trunc('year', CURRENT_DATE)::date AND CURRENT_DATE  -- ปีนี้ถึงวันนี้ (ปัจจุบันอัตโนมัติ)
AND    l.lab_order_result IS NOT NULL AND l.lab_order_result <> ''
AND (
    (l.lab_items_code = '539'
        AND COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric, 0) >= 5)
    OR (l.lab_items_code = '2368'
        AND (l.lab_order_result !~ '^[0-9]'
             OR COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric, 0) > 150))
    OR (l.lab_items_code IN ('697','2388')
        AND (l.lab_order_result !~ '^[0-9]'
             OR COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric, 0) > 1.2))
    OR (l.lab_items_code = '2370'
        AND (l.lab_order_result !~ '^[0-9]'
             OR COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric, 0) > 20))
)
GROUP BY l.lab_items_code
ORDER BY total DESC;


-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  D) แสดง "ทุกผล" ของ INR/Depakin/Lithium/Phenytoin + คอลัมน์ระดับเสี่ยง    ║
-- ║     ใช้เมื่ออยากเห็นคนไข้ทุกคน (ไม่ใช่เฉพาะวิกฤต) เช่น INR คนไข้เยอะ        ║
-- ║     กรองแค่ lab_items_code ไม่กรอง threshold — แล้วคำนวณ risk เป็นคอลัมน์   ║
-- ╚══════════════════════════════════════════════════════════════════════════╝

-- D-1) MySQL / MariaDB
SELECT
    h.lab_order_number, h.hn,
    CONCAT(COALESCE(pt.pname,''), pt.fname, ' ', pt.lname) AS fullname,
    TIMESTAMPDIFF(YEAR, pt.birthday, h.order_date) AS age,
    h.order_date AS lab_date, h.report_time AS lab_time, d.name AS doctor,
    CASE l.lab_items_code
        WHEN '539' THEN 'INR' WHEN '2368' THEN 'Depakin level'
        WHEN '697' THEN 'Lithium level' WHEN '2388' THEN 'Lithium level'
        WHEN '2370' THEN 'Phenytoin level' END AS lab_name,
    l.lab_order_result AS result,
    CASE
        WHEN l.lab_items_code='539'  AND l.lab_order_result + 0 >= 5   THEN 'วิกฤต'
        WHEN l.lab_items_code='539'  AND l.lab_order_result + 0 >= 3.5 THEN 'เฝ้าระวัง'
        WHEN l.lab_items_code='2368' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 150) THEN 'วิกฤต'
        WHEN l.lab_items_code IN ('697','2388') AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 1.2) THEN 'วิกฤต'
        WHEN l.lab_items_code='2370' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 20) THEN 'วิกฤต'
        ELSE 'ปกติ'
    END AS risk
FROM   lab_head  h
INNER JOIN lab_order l  ON l.lab_order_number = h.lab_order_number
LEFT  JOIN vn_stat  vs  ON vs.vn  = h.vn
LEFT  JOIN patient  pt  ON pt.hn  = h.hn
LEFT  JOIN doctor   d   ON d.code = vs.dx_doctor
WHERE  h.order_date BETWEEN '2025-01-01' AND '2025-12-31'
AND    l.lab_items_code IN ('539','2368','697','2388','2370')
AND    l.lab_order_result IS NOT NULL AND l.lab_order_result <> ''
ORDER BY h.order_date DESC;

-- D-2) PostgreSQL (XE4)
SELECT
    h.lab_order_number, h.hn,
    CONCAT(COALESCE(pt.pname,''), COALESCE(pt.fname,''), ' ', COALESCE(pt.lname,'')) AS fullname,
    EXTRACT(YEAR FROM age(h.order_date, pt.birthday)) AS age,
    h.order_date AS lab_date, h.report_time AS lab_time, d.name AS doctor,
    CASE l.lab_items_code
        WHEN '539' THEN 'INR' WHEN '2368' THEN 'Depakin level'
        WHEN '697' THEN 'Lithium level' WHEN '2388' THEN 'Lithium level'
        WHEN '2370' THEN 'Phenytoin level' END AS lab_name,
    l.lab_order_result AS result,
    CASE
        WHEN l.lab_items_code='539'  AND COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric,0) >= 5   THEN 'วิกฤต'
        WHEN l.lab_items_code='539'  AND COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric,0) >= 3.5 THEN 'เฝ้าระวัง'
        WHEN l.lab_items_code='2368' AND (l.lab_order_result !~ '^[0-9]' OR COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric,0) > 150) THEN 'วิกฤต'
        WHEN l.lab_items_code IN ('697','2388') AND (l.lab_order_result !~ '^[0-9]' OR COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric,0) > 1.2) THEN 'วิกฤต'
        WHEN l.lab_items_code='2370' AND (l.lab_order_result !~ '^[0-9]' OR COALESCE(NULLIF(substring(l.lab_order_result from '^[0-9]+(?:\.[0-9]+)?'),'')::numeric,0) > 20) THEN 'วิกฤต'
        ELSE 'ปกติ'
    END AS risk
FROM   lab_head  h
INNER JOIN lab_order l  ON l.lab_order_number = h.lab_order_number
LEFT  JOIN vn_stat  vs  ON vs.vn  = h.vn
LEFT  JOIN patient  pt  ON pt.hn  = h.hn
LEFT  JOIN doctor   d   ON d.code = vs.dx_doctor
WHERE  h.order_date BETWEEN date_trunc('year', CURRENT_DATE)::date AND CURRENT_DATE  -- ปีนี้ถึงวันนี้ (ปัจจุบันอัตโนมัติ)
AND    l.lab_items_code IN ('539','2368','697','2388','2370')
AND    l.lab_order_result IS NOT NULL AND l.lab_order_result <> ''
ORDER BY h.order_date DESC;
