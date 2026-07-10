---
status: accepted
---

# แยก HOSxP (อ่านอย่างเดียว) ออกจาก MedAlert_DB (MySQL อ่าน-เขียน)

โรงพยาบาลย้าย HOSxP จาก V3 (MySQL) ไป HOSxPXE4 (PostgreSQL เท่านั้น) ซึ่งตรวจสอบแล้วว่าใช้ schema เดิม (ตาราง/คอลัมน์ตรงกัน) เดิมแอปใช้ PDO connection เดียว (`$dbcon`) ทั้งอ่าน HOSxP และเขียนตารางของระบบเอง (`users` + Queue) ทำให้ตารางของระบบไปฝากอยู่ในฐาน HOSxP และพังทันทีเมื่อ source เปลี่ยน engine

เราจึงแยกเป็น **สอง datastore**: **HOSxP** เป็นแหล่งข้อมูลต้นทาง **อ่านอย่างเดียว** เชื่อมต่อได้ทั้ง MySQL (V3) และ PostgreSQL (XE4) ผ่าน lazy accessor `hosxp_db()`; และ **MedAlert_DB** เป็นฐานข้อมูลของระบบเอง **ตรึงเป็น MySQL/MariaDB เสมอ** ถือ `users` + Queue ทั้งหมด เข้าถึงผ่าน `$dbcon` (ความหมายเดิมของ `$dbcon` เปลี่ยนจาก "ทุกอย่าง" เป็น "MedAlert_DB เท่านั้น")

## Considered Options

- **ตรึง MedAlert_DB เป็น MySQL (เลือก)** — write SQL เดิม (`ON DUPLICATE KEY`, `AUTO_INCREMENT`, `utf8mb4`) ไม่ต้องแปลง dialect งาน dialect เหลือเฉพาะฝั่งอ่าน HOSxP
- รองรับ MedAlert_DB เป็น PostgreSQL ด้วย — **ปฏิเสธ**: ต้องดูแล write SQL + schema สองชุด เป็น YAGNI เพราะ MedAlert_DB รันบน MySQL เสมอ
- เปลี่ยน source เป็น PostgreSQL อย่างเดียว ทิ้ง MySQL — **ปฏิเสธ**: ต้องการ 2-way ถาวรเผื่อ rollback ช่วงเปลี่ยนผ่านและไซต์อื่นที่ยังเป็น V3
- ตั้งชื่อ connection ใหม่ทั้งสองตัว (`$hosxp`+`$medalert`) — **ปฏิเสธ**: ต้องแก้ทั้ง 53 ไฟล์ที่ใช้ `$dbcon` การคง `$dbcon`=MedAlert_DB ทำให้ไฟล์ส่วนใหญ่ไม่ต้องแก้

## Consequences

- การอ่าน HOSxP ต้องรองรับสอง dialect → รวมศูนย์เป็น **Source Query provider 1 ตัวต่อ Alert Module** (ยุบโค้ดที่ซ้ำใน ~26 ไฟล์) ดู term "Source Query" ใน CONTEXT.md
- ต้องแปลง construct เฉพาะ MySQL เมื่อรันบน PostgreSQL: `TIMESTAMPDIFF`, `REGEXP`, `+ 0`, `SUBSTRING(TIME(...))` และที่สำคัญ `GROUP BY` แบบ MySQL (เลือกคอลัมน์นอก group ได้) ต้องเปลี่ยนเป็น `DISTINCT ON` หรือใส่ครบทุกคอลัมน์ในฝั่ง PG
- บังคับ read-only ฝั่ง HOSxP ด้วย `SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY` (PostgreSQL) ใน `hosxp_db()`
- HOSxPXE4 (PostgreSQL) ใช้ `server_encoding = WIN874` ไม่ใช่ UTF-8 → `hosxp_db()` ต้องสั่ง `SET client_encoding TO 'UTF8'` ให้ PG แปลงให้บน wire (เทียบเท่า `SET NAMES utf8mb4` ฝั่ง MySQL) มิฉะนั้นชื่อไทยจะเป็น mojibake
- **กับดัก `substring(... from pattern)` ของ PostgreSQL:** ถ้า pattern มีวงเล็บจับกลุ่ม `(...)` PG จะคืนเฉพาะกลุ่มแรก ไม่ใช่ทั้ง match. ตอนแปลง pharm_lab `lab_order_result + 0` (ดึงเลขนำหน้า) ต้องใช้ **non-capturing** `(?:\.[0-9]+)?` มิฉะนั้น `'6.54 R'` จะถูกตัดเหลือ `.54` → ค่า INR/Depakin/Lithium ที่เป็นตัวเลขจะไม่เข้าเกณฑ์วิกฤตทั้งหมด (ดู sources/pharm_lab_source.php)
- MedAlert_DB เป็นเจ้าของข้อมูล alert ที่สำคัญแต่ผู้เดียว → ต้องมี backup routine แยก (เดิมฝากบนเซิร์ฟเวอร์ HOSxP)
- การเชื่อมต่อทั้งสองเก็บแยกใน `secrets/db_config.json` แบบสองก้อน (`hosxp` + `medalert`)
