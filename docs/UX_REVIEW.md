# รายงานรีวิวโค้ดและ UX/UI — Fall Risk Alert
**โรงพยาบาลเชียงกลาง · ระบบแจ้งเตือนข้อมูลคนไข้**
วันที่รีวิว: 22 เมษายน 2026

---

## สรุปภาพรวม

ระบบใช้ PHP + Bootstrap 5 + FontAwesome 6 + SweetAlert2 + DataTables ร่วมกับ AdminLTE ผ่าน `index1.html` เป็น layout กลาง การออกแบบหน้า `index.php` (หน้าหลัก) ทำได้ค่อนข้างดี มีธีมสว่าง/มืด, กริดไทล์แบบไล่ระดับสี, ช่องค้นหา และฟอนต์ไทย Prompt/Kanit แต่พบปัญหาด้าน UX และบัคโครงสร้างในหน้าอื่นที่ต้องแก้

ระดับความเร่งด่วน: **P0** = บัคต้องแก้ด่วน, **P1** = UX สำคัญ, **P2** = ขัดเกลา

---

## P0 — ปัญหาโครงสร้างที่ควรแก้ทันที

### 1. `require_once('index1.html')` ทำให้เลย์เอาต์พัง
**ไฟล์ที่ได้รับผลกระทบ:** `fracture_queue_ui.php`, `fracture_dashboard.php` (อาจรวมหน้าอื่นที่ใช้รูปแบบเดียวกัน)

`index1.html` เป็นเอกสาร HTML สมบูรณ์ — มีทั้ง `<!DOCTYPE html>`, `<html>`, `<head>`, `<body>` และปิดด้วย `</body></html>` ครบ เมื่อหน้า queue เรียก `require_once('index1.html')` ก่อน แล้วตามด้วย `<!doctype html><html>...` ของตัวเอง ผลลัพธ์คือเอกสารสองชุดต่อกัน — sidebar AdminLTE จึงไม่ครอบเนื้อหา queue เลย

**วิธีแก้:** แยก `index1.html` เป็นสองไฟล์
- `partials/header.php` — มีตั้งแต่ `<!doctype html>` ถึง จุดเริ่ม content-wrapper (ยังไม่ปิด `</body></html>`)
- `partials/footer.php` — ปิด content-wrapper, script, `</body></html>`

แล้วเปลี่ยนหน้า queue/dashboard เป็น:
```php
require_once __DIR__ . '/partials/header.php';
// ...form, table, chart ของหน้านี้...
require_once __DIR__ . '/partials/footer.php';
```

### 2. `session_start()` ถูกเรียกซ้ำ
**ไฟล์:** `index.php` (บรรทัด 3) เรียก `session_start()` หลังจาก `require_once __DIR__ . '/auth_guard.php';` ซึ่งน่าจะเรียก `session_start()` อยู่แล้ว — ทำให้เกิด notice

**วิธีแก้:** ใช้ `if (session_status() === PHP_SESSION_NONE) session_start();` หรือย้ายเข้าไปใน `auth_guard.php` จุดเดียว

### 3. การกรอง ICD-10 ต่อสตริงลง SQL โดยตรง
**ไฟล์:** `fracture_queue_ui.php` บรรทัด 23-25
```php
foreach (['S720','S721',...] as $prefix) {
  $dx[] = "UPPER(pdx_code) LIKE '{$prefix}%'";
}
```
ค่าที่ต่อเป็น literal คงที่ จึงไม่มีความเสี่ยง SQL injection **ในปัจจุบัน** แต่ถ้าอนาคตให้ admin ปรับรายการโรคผ่านฟอร์ม/ฐานข้อมูล จะกลายเป็นช่องโหว่ทันที — ควรเปลี่ยนเป็น prepared statement พร้อมผูก `:px1, :px2, ...` ตั้งแต่ตอนนี้

---

## P1 — ปัญหา UX ที่กระทบผู้ใช้ทุกวัน

### 4. Toast ก่อนเปิดหน้า (index.php) ทำให้ทุกคลิกหน่วง 1.1 วินาที
**ไฟล์:** `index.php` บรรทัด 368-381

ทุกไทล์จะแสดง `Swal.fire('ส่งคำขอเรียบร้อย')` แล้วรอ 1.1 วิ ก่อน redirect — ผู้ใช้กดหลายครั้งต่อวันจะรู้สึกช้ามาก และ toast นี้ให้ข้อมูลผิด (ยังไม่ได้ส่งคำขอ แค่เปิดหน้า)

**วิธีแก้:** ตัด block นี้ทิ้ง — ให้ `<a href="...">` navigate ตามปกติ ถ้าต้องการ feedback ใช้ `:active` state หรือ skeleton loading แทน

### 5. สถานะแสดงเป็น "0" / "1" ในตาราง
**ไฟล์:** `fracture_queue_ui.php` บรรทัด 149

`<span class="badge badge-ok">1</span>` — พยาบาล/เจ้าหน้าที่ต้องจำเองว่า 0 กับ 1 แปลว่าอะไร ทั้งที่ตัวเลือก filter มีคำอธิบาย "ค้างส่ง" / "ส่งแล้ว" อยู่แล้ว

**วิธีแก้:** แสดงข้อความพร้อมไอคอน เช่น
```html
<span class="badge bg-warning-subtle text-warning-emphasis">
  <i class="fa-solid fa-clock"></i> ค้างส่ง
</span>
<span class="badge bg-success-subtle text-success-emphasis">
  <i class="fa-solid fa-check"></i> ส่งแล้ว
</span>
```

### 6. Focus ring บนไทล์มีขนาด 0px (มองไม่เห็น)
**ไฟล์:** `index.php` บรรทัด 148
```css
.tile:focus-visible{outline: none; box-shadow: 0 0 0 0 var(--ring); }
```
`box-shadow: 0 0 0 0` หมายถึงเงาที่ไม่มีขนาด — ผู้ใช้คีย์บอร์ด/สกรีนรีดเดอร์จะมองไม่ออกว่าโฟกัสอยู่ที่ไทล์ไหน

**วิธีแก้:** `box-shadow: 0 0 0 4px rgba(255,255,255,.9), 0 0 0 6px #0d6efd;`

### 7. ปุ่มยืนยันใน queue ใช้ `confirm()` แบบเบราว์เซอร์
**ไฟล์:** `fracture_queue_ui.php` บรรทัด 112

`onsubmit="return confirm('ยืนยันดำเนินการ...')"` — รูปแบบนี้ขัดกับที่เหลือของระบบที่ใช้ SweetAlert2 และ confirm() native ในบางเบราว์เซอร์/OS ดูไม่เป็นมืออาชีพ

**วิธีแก้:** ดัก `submit` ด้วย JS → `Swal.fire` แบบ confirm พร้อมแสดงจำนวนรายการที่เลือก ("ส่งซ้ำ 12 รายการ?")

### 8. หน้า login ไม่เข้ากับดีไซน์ส่วนอื่น
**ไฟล์:** `login.php`

- ใช้ system font ล้วน ไม่ได้โหลด Prompt/Kanit
- ไม่ใช้ Bootstrap (เขียน CSS เองทั้งหมด)
- ไม่มี FontAwesome
- ไม่มีโลโก้โรงพยาบาลจริง (มีแต่ตัวหนังสือ "โรงพยาบาลเชียงกลาง")
- ไม่มีปุ่ม show/hide password
- ไม่มี `autofocus` ที่ช่อง username
- แสดง error สองที่ (inline HTML + SweetAlert) ซ้อนกัน
- `autocomplete="off"` บล็อก password manager

**วิธีแก้:** ดูในไฟล์โมเดิร์นไนซ์ที่ส่งมาในรอบนี้

### 9. ไม่มีสรุป KPI ในหน้า queue
**ไฟล์:** `fracture_queue_ui.php`

ผู้ใช้ต้องเลื่อนดูตารางเอง ไม่มีตัวเลข "ค้างส่ง 12 / ส่งสำเร็จ 189 / ล้มเหลว 2 / วันนี้ 5" อยู่ด้านบน

**วิธีแก้:** ใส่แถว KPI card 4-5 ใบ ก่อนตาราง ใช้สีเดียวกับธีมไทล์ (ค้างส่ง=ส้ม, ส่งสำเร็จ=เขียว, ล้มเหลว=แดง)

### 10. หน้า dashboard ขาด KPI summary เช่นกัน
**ไฟล์:** `fracture_dashboard.php`

มีกราฟ + top list แต่ไม่มีตัวเลขรวมด้านบน — ต้องอ่านกราฟเอง

---

## P2 — ขัดเกลา

### 11. ไม่มี keyboard shortcut สำหรับช่องค้นหา
หน้า `index.php` มีช่องค้นหาแต่กดตรงไหนก็ไม่โฟกัส เพิ่ม `/` หรือ `Ctrl+K` จะช่วยผู้ใช้ที่คุ้นเคย

### 12. ไม่มีการจัดหมวดไทล์
10 ไทล์อยู่รวมกันในกริดเดียว — แยกเป็น "งานฉุกเฉิน/ความเสี่ยง", "งานเฝ้าระวังโรคติดต่อ", "งานสนับสนุน" จะช่วยให้สแกนเร็วขึ้น

### 13. ไม่มีทักทาย/ปุ่ม logout บนหน้าหลัก
ผู้ใช้ login แล้วไม่เห็นชื่อตัวเอง และถ้าจะ logout ต้องเข้าหน้าที่มี sidebar ก่อน — เพิ่ม chip มุมขวาบนของ header จะสะดวกกว่า

### 14. ตาราง 18 คอลัมน์ไม่มีปุ่มซ่อน/แสดงคอลัมน์
DataTables รองรับ ColVis plugin เพิ่มปุ่ม "เลือกคอลัมน์" ได้เลย

### 15. ไม่มี Skip-to-content, landmarks ไม่ครบ
ผู้ใช้สกรีนรีดเดอร์/คีย์บอร์ดจะต้องกด Tab ผ่านทุกเมนู ควรใส่ `<a href="#main" class="visually-hidden-focusable">ข้ามไปยังเนื้อหา</a>`

### 16. Fonts โหลดทั้ง Prompt และ Kanit ทุกน้ำหนัก
`index.php` โหลด `Prompt:wght@300;400;600 & Kanit:wght@300;400;600` — แต่หน้า queue ไม่ได้ใช้เลย เลือกใช้แค่ Prompt 400/600 จะเบาลง

### 17. กริด Bootstrap ของ filter ใน dashboard ใช้ `col-auto` + `row` อาจแตกบรรทัดสวยไม่พอ
ควรใช้ `row g-2 row-cols-md-4 row-cols-lg-6` หรือ flex-wrap พร้อม min-width ของแต่ละช่อง

---

## ไฟล์ที่ปรับปรุงให้แล้วในรอบนี้

1. `index.php` — หน้าหลัก (ตัด toast, เพิ่มทักทาย/logout, จัดหมวดไทล์, focus ring ที่มองเห็น, keyboard shortcut, fix session)
2. `login.php` — เข้าสู่ระบบ (ใช้ฟอนต์/ไอคอน/Bootstrap เหมือนส่วนอื่น, show/hide password, autofocus, error channel เดียว)
3. `fracture_queue_ui.php` — คิวพลัดตก/หกล้ม (KPI cards, สถานะเป็นข้อความ, SweetAlert confirm, ปุ่มเลือกคอลัมน์, ตัด require index1.html)
4. `partials/header.php` + `partials/footer.php` — layout กลางที่ใช้แทน `index1.html` ได้ถูกต้อง

ไฟล์เดิมถูกสำรองไว้ที่ `*.backup` เผื่อย้อนคืน

---

## ข้อเสนอถัดไป

หน้าโรคอื่นๆ (`accident_queue_ui.php`, `covid_notify_dashboard.php`, `dengue.php`, `Leptospira.php`, `scrubtyphus.php`, `pharm_lab_queue_ui.php`) ใช้รูปแบบเดียวกับ fracture queue — ถ้าอนุมัติทิศทางนี้ ผมจะปรับให้ครบด้วยแพตเทิร์นเดียวกัน (KPI cards + text status + SweetAlert confirm + ปุ่มคอลัมน์)

หลังจากนั้นอาจพิจารณา:
- ย้าย shared CSS ไปไฟล์เดียว (`css/app.css`) แทนการเขียน `<style>` ซ้ำในทุกหน้า
- สร้าง component ของ KPI card / status badge เป็น PHP function reusable
- เพิ่ม PWA manifest + service worker เผื่อใช้บน tablet ที่หอผู้ป่วย
