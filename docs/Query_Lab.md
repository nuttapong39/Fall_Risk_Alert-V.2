# Query_Lab.md — SQL Reference สำหรับ Lab Alert Module

> ใช้อ้างอิงเมื่อสร้าง Alert Module ใหม่ที่เกี่ยวกับผลตรวจ Lab จาก HOSxP

---

## ตาราง HOSxP ที่เกี่ยวข้อง

| ตาราง | คำอธิบาย |
|---|---|
| `lab_head` | Header ของคำสั่ง Lab แต่ละรายการ (hn, order_date, report_time) |
| `lab_order` | รายการผลตรวจย่อย (lab_items_code, lab_order_result) |
| `patient` | ข้อมูลผู้ป่วย (pname, fname, lname, birthday) |
| `vn_stat` | ข้อมูล Visit (vn, dx_doctor) |
| `doctor` | ข้อมูลแพทย์ (code, name) |

---

## Query หลัก — ดึง Lab วิกฤตจาก HOSxP

### รูปแบบ Base Query (ใช้ซ้ำได้ทุก Module)

```sql
SELECT
    h.lab_order_number,
    h.hn,
    CONCAT(COALESCE(pt.pname,''), pt.fname, ' ', pt.lname) AS fullname,
    TIMESTAMPDIFF(YEAR, pt.birthday, h.order_date)         AS age,
    h.order_date    AS lab_date,
    h.report_time   AS lab_time,
    d.name          AS doctor,
    l.lab_items_code,
    l.lab_order_result AS result,
    'OPD'           AS patient_type
FROM   lab_head  h
INNER JOIN lab_order l  ON l.lab_order_number = h.lab_order_number
LEFT  JOIN vn_stat   vs ON vs.vn  = h.vn
LEFT  JOIN patient   pt ON pt.hn  = h.hn
LEFT  JOIN doctor    d  ON d.code = vs.dx_doctor
WHERE  h.order_date BETWEEN '{start}' AND '{end}'
AND    l.lab_order_result IS NOT NULL
AND    l.lab_order_result <> ''
-- เพิ่ม AND condition ของแต่ละ Module ด้านล่าง
ORDER  BY h.order_date DESC;
```

---

## lab_items_code และ Threshold วิกฤต

| Lab | lab_items_code | เกณฑ์วิกฤต | หมายเหตุ |
|---|---|---|---|
| INR | `539` | `>= 5` | ผล `"9.26 R"` → ใช้ `result + 0` แปลงอัตโนมัติ |
| INR (สูง ไม่วิกฤต) | `539` | `>= 3.5` | ใช้สำหรับ Monitor เพิ่มเติม |
| Depakin level | `2368` | `> 150` | ผล text (รูปภาพ) → แจ้งเตือนเสมอ |
| Lithium level | `697`, `2388` | `> 1.2` | ผล text → แจ้งเตือนเสมอ |
| Phenytoin level | `2370` | `> 20` | ผล text → แจ้งเตือนเสมอ |

---

## Query สำเร็จรูปแยกตาม Lab

### 1. INR เท่านั้น (วิกฤต ≥ 5)

```sql
SELECT
    h.lab_order_number, h.hn,
    CONCAT(COALESCE(pt.pname,''), pt.fname, ' ', pt.lname) AS fullname,
    TIMESTAMPDIFF(YEAR, pt.birthday, h.order_date)         AS age,
    h.order_date AS lab_date, h.report_time AS lab_time,
    d.name AS doctor, l.lab_order_result AS result
FROM   lab_head h
INNER JOIN lab_order l  ON l.lab_order_number = h.lab_order_number
LEFT  JOIN vn_stat   vs ON vs.vn  = h.vn
LEFT  JOIN patient   pt ON pt.hn  = h.hn
LEFT  JOIN doctor    d  ON d.code = vs.dx_doctor
WHERE  h.order_date BETWEEN '2025-01-01' AND CURDATE()
AND    l.lab_items_code = '539'
AND    l.lab_order_result IS NOT NULL
AND    l.lab_order_result <> ''
AND    l.lab_order_result + 0 >= 5
ORDER  BY h.order_date DESC;
```

### 2. INR สูง (≥ 3.5 แต่ < 5 — เฝ้าระวัง)

```sql
AND    l.lab_items_code = '539'
AND    l.lab_order_result + 0 >= 3.5
AND    l.lab_order_result + 0 < 5
```

### 3. ทุก Lab วิกฤต (INR + Depakin + Lithium + Phenytoin) — ใช้ใน Pharm Module

```sql
AND (
  (l.lab_items_code = '539'
    AND l.lab_order_result + 0 >= 5)

  OR (l.lab_items_code = '2368'
    AND (l.lab_order_result NOT REGEXP '^[0-9]'
         OR l.lab_order_result + 0 > 150))

  OR (l.lab_items_code IN ('697','2388')
    AND (l.lab_order_result NOT REGEXP '^[0-9]'
         OR l.lab_order_result + 0 > 1.2))

  OR (l.lab_items_code = '2370'
    AND (l.lab_order_result NOT REGEXP '^[0-9]'
         OR l.lab_order_result + 0 > 20))
)
```

---

## Query ตรวจสอบ (Recheck / Audit)

### เช็คข้อมูลทั้งหมดใน Queue (pharm_lab_queue)

```sql
SELECT
    id, hn, fullname, age,
    lab_date, lab_time, doctor,
    lab_name, result, patient_type,
    status,
    attempt, last_attempt_at, out_ref, last_error,
    created_at, sent_at
FROM pharm_lab_queue
WHERE lab_date BETWEEN '2025-01-01' AND CURDATE()
ORDER BY lab_date DESC;
```

### เช็คข้อมูลตกหล่น (อยู่ใน HOSxP แต่ไม่มีใน Queue)

```sql
SELECT
    h.lab_order_number, h.hn,
    CONCAT(COALESCE(pt.pname,''), pt.fname, ' ', pt.lname) AS fullname,
    h.order_date AS lab_date,
    l.lab_items_code,
    l.lab_order_result AS result
FROM   lab_head  h
INNER JOIN lab_order l  ON l.lab_order_number = h.lab_order_number
LEFT  JOIN patient   pt ON pt.hn = h.hn
WHERE  h.order_date BETWEEN '2025-01-01' AND CURDATE()
AND (
  (l.lab_items_code = '539'  AND l.lab_order_result + 0 >= 5)
  OR (l.lab_items_code = '2368' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 150))
  OR (l.lab_items_code IN ('697','2388') AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 1.2))
  OR (l.lab_items_code = '2370' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 20))
)
AND NOT EXISTS (
    SELECT 1 FROM pharm_lab_queue q
    WHERE q.hn = h.hn
    AND   q.lab_order_number = h.lab_order_number
)
ORDER BY h.order_date DESC;
```

### นับ record แยกตาม Lab (ใช้ verify กับ Navicat)

```sql
SELECT
    l.lab_items_code,
    CASE l.lab_items_code
        WHEN '539'  THEN 'INR'
        WHEN '2368' THEN 'Depakin level'
        WHEN '697'  THEN 'Lithium level'
        WHEN '2388' THEN 'Lithium level (alt)'
        WHEN '2370' THEN 'Phenytoin level'
        ELSE 'อื่น ๆ'
    END AS lab_name,
    COUNT(*) AS total_records
FROM   lab_head  h
INNER JOIN lab_order l ON l.lab_order_number = h.lab_order_number
WHERE  h.order_date BETWEEN '2025-01-01' AND CURDATE()
AND    l.lab_items_code IN ('539','2368','697','2388','2370')
AND    l.lab_order_result IS NOT NULL
AND    l.lab_order_result <> ''
AND (
  (l.lab_items_code = '539'  AND l.lab_order_result + 0 >= 5)
  OR (l.lab_items_code = '2368' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 150))
  OR (l.lab_items_code IN ('697','2388') AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 1.2))
  OR (l.lab_items_code = '2370' AND (l.lab_order_result NOT REGEXP '^[0-9]' OR l.lab_order_result + 0 > 20))
)
GROUP BY l.lab_items_code
ORDER BY total_records DESC;
```

---

## หมายเหตุสำคัญ

### การจัดการผลตรวจที่มี Suffix เช่น "9.26 R"

HOSxP บางครั้ง append ตัวอักษรหลังตัวเลข เช่น `"9.26 R"`, `"5.04 H"`
MySQL แก้ด้วย `result + 0` ซึ่งแปลง `"9.26 R"` → `9.26` อัตโนมัติ
PHP แก้ด้วย `preg_match('/^\d+(?:\.\d+)?/', trim($result), $m)`

### ผลตรวจที่เป็น Text (รูปภาพ)

ผลแบบ `"รายงานผลตามไฟล์รูปภาพ"` → `result NOT REGEXP '^[0-9]'` = true
สำหรับ Depakin / Lithium / Phenytoin → **แจ้งเตือนเสมอ** (มีผลทางคลินิก)
สำหรับ INR → **ไม่แจ้งเตือน** (ต้องการค่าตัวเลขเพื่อเปรียบเทียบ threshold)

### Template สำหรับ Module ใหม่

เมื่อเพิ่ม Lab ใหม่ ให้เพิ่ม condition ใน `AND (...)` block:
```sql
OR (l.lab_items_code = '{NEW_CODE}'
    AND l.lab_order_result + 0 > {THRESHOLD})
```
และเพิ่ม classify function ใน PHP:
```php
if ($code === '{NEW_CODE}') return ($v !== null && $v > THRESHOLD) ? 'Lab Name' : null;
```
