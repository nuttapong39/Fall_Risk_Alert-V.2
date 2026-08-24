# Changelog

รูปแบบเวอร์ชัน: `YYYY.MM.DD` (วันที่ปล่อยรีลีส)

## 2026.08.24
- เพิ่มระบบอัปเดตเวอร์ชัน: ปุ่ม "ตรวจสอบ/อัปเดต" ในหน้า `settings.php`, สคริปต์ `task/update.bat` (รองรับทั้ง git และ ZIP), สำรองข้อมูลอัตโนมัติก่อนอัปเดตทุกครั้ง
- แก้ปุ่ม "Setup MedAlert_DB" ให้ deploy schema แบบ glob (`*_queue.sql`) แทน list ตายตัว — ทำให้ `lepto_queue`/`scrub_queue` ที่เคยตกหล่นถูกสร้างอัตโนมัติด้วย
- ระบบ "แก้ไขเงื่อนไขดึงข้อมูล" ครบทั้ง 10 module (covid, accident, drug, sexual, lepto, scrub, dengue, patient, fracture, pharm_lab) — แก้รหัส lab/pttype/icode/pdx/เกณฑ์ผ่านหน้าเว็บได้เอง ไม่ต้องแก้โค้ด
