# Fall Risk Alert — ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยง

> ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยงผ่าน **MOPH ALERT (LINE)** สำหรับโรงพยาบาล  
> พัฒนาด้วย PHP + XAMPP | UI: Bootstrap 5 + HR-CENTER 4.0 Design System  
> รพ.เชียงกลาง จ.น่าน

---

## สารบัญ

1. [ความต้องการของระบบ (Requirements)](#requirements)
2. [การติดตั้ง XAMPP (Windows)](#install-xampp)
3. [การติดตั้งระบบ (Step by Step)](#install-system)
4. [การตั้งค่าฐานข้อมูล](#setup-database)
5. [การตั้งค่าสำหรับ PostgreSQL](#postgresql-setup)
6. [การตั้งค่า MOPH ALERT Keys](#setup-moph-keys)
7. [การตั้งค่า Task Scheduler (Cron)](#task-scheduler)
8. [โครงสร้างไฟล์](#file-structure)
9. [การใช้งานระบบ](#usage)
10. [Troubleshooting](#troubleshooting)

---

## Requirements

| รายการ | เวอร์ชัน | หมายเหตุ |
|--------|----------|----------|
| XAMPP  | 8.2+     | หรือ PHP 8.1+ ที่มี Apache |
| PHP    | 8.1+     | พร้อม PDO extension |
| MySQL / MariaDB | 10.4+ | สำหรับฐานข้อมูล HOSxP แบบ MySQL |
| PostgreSQL | 12+ | สำหรับ HIS ที่ใช้ PostgreSQL (ดูส่วน PostgreSQL Setup) |
| Internet | — | สำหรับส่ง MOPH ALERT API |

**PHP Extensions ที่ต้องการ:**
- `pdo_mysql` — เปิดมาแล้วโดย default ใน XAMPP
- `pdo_pgsql` — ต้องเปิดเพิ่มถ้าใช้ PostgreSQL
- `json`, `curl`, `mbstring` — เปิดมาแล้วใน XAMPP

---

## การติดตั้ง XAMPP (Windows) {#install-xampp}

1. ดาวน์โหลด XAMPP จาก [https://www.apachefriends.org](https://www.apachefriends.org)
2. ติดตั้งที่ `C:\xampp` (แนะนำ)
3. เปิด **XAMPP Control Panel** → Start **Apache** และ **MySQL**

---

## การติดตั้งระบบ (Step by Step) {#install-system}

### ขั้นที่ 1 — Clone หรือดาวน์โหลดโปรเจกต์

```bash
# วิธีที่ 1: Clone ผ่าน Git
cd C:\xampp\htdocs
git clone https://github.com/nuttapong39/Fall_Risk_Alert-V.2.git Fall_Risk_Alert-main

# วิธีที่ 2: ดาวน์โหลด ZIP จาก GitHub แล้วแตกไฟล์ไปที่
# C:\xampp\htdocs\Fall_Risk_Alert-main\
```

### ขั้นที่ 2 — สร้างโฟลเดอร์ secrets/

โฟลเดอร์ `secrets/` ถูก `.gitignore` ไว้ (ไม่อยู่ใน repository) ต้องสร้างด้วยตนเอง:

```
C:\xampp\htdocs\Fall_Risk_Alert-main\
└── secrets\          ← สร้างโฟลเดอร์นี้ (ว่างไว้ก่อน)
```

สร้างผ่าน Command Prompt:
```cmd
mkdir C:\xampp\htdocs\Fall_Risk_Alert-main\secrets
```

### ขั้นที่ 3 — Import ฐานข้อมูล Users (สำหรับ Login)

ระบบต้องการตาราง `users` สำหรับการ Login (**แยกจากฐานข้อมูล HIS**)

1. เปิด phpMyAdmin: [http://localhost/phpmyadmin](http://localhost/phpmyadmin)
2. สร้างฐานข้อมูลใหม่ชื่อ `fall_risk_alert` (charset: `utf8mb4`)
3. Import ไฟล์ `users.sql` จากโฟลเดอร์โปรเจกต์

```sql
-- หรือรัน SQL ตรงๆ:
CREATE DATABASE IF NOT EXISTS fall_risk_alert CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fall_risk_alert;
-- จากนั้น import users.sql
```

### ขั้นที่ 4 — เปิดหน้า Setup (First Run)

เปิดเบราว์เซอร์:
```
http://localhost/Fall_Risk_Alert-main/
```
ระบบจะ **redirect อัตโนมัติ** ไปหน้าตั้งค่าฐานข้อมูล เมื่อยังไม่มีไฟล์ `secrets/db_config.json`

---

## การตั้งค่าฐานข้อมูล {#setup-database}

### ขั้นที่ 1 — เลือกชนิดฐานข้อมูล

| Database | ใช้กับ | Default Port |
|----------|--------|--------------|
| **MySQL / MariaDB** | HOSxP, HOSxP PCU, iMed@Home | 3306 |
| **PostgreSQL** | HIS อื่นๆ ที่ใช้ PostgreSQL | 5432 |

### ขั้นที่ 2 — กรอกข้อมูลการเชื่อมต่อ

| ช่อง | คำอธิบาย | ตัวอย่าง |
|------|----------|---------|
| Host / IP | IP หรือ hostname ของ DB Server | `192.168.1.249` |
| Port | Port ของ Database | `3306` (MySQL) / `5432` (PostgreSQL) |
| Database | ชื่อฐานข้อมูล HIS | `hosxp` |
| Username | ชื่อผู้ใช้ DB | `root` |
| Password | รหัสผ่าน DB | `****` |

> 💡 **แนะนำ**: ใช้ **Slave DB (Read Replica)** เพื่อลดภาระ Production Server

### ขั้นที่ 3 — ทดสอบและบันทึก

1. กด **"ทดสอบการเชื่อมต่อ"** → รอผล (แสดง Server version ถ้าสำเร็จ)
2. กด **"บันทึกการตั้งค่า"** → ระบบสร้างไฟล์ `secrets/db_config.json`
3. ระบบพาไปหน้า Login โดยอัตโนมัติ

---

## การตั้งค่าสำหรับ PostgreSQL {#postgresql-setup}

> ⚠️ ต้องทำ **ก่อน** กรอกข้อมูลในหน้า Setup

### ขั้นที่ 1 — เปิด Extension pdo_pgsql ใน php.ini

1. เปิด XAMPP Control Panel → คลิก **Config** ถัดจาก Apache → เลือก **PHP (php.ini)**
2. ค้นหาบรรทัด (กด `Ctrl+F` แล้วพิมพ์ `pdo_pgsql`):

```ini
;extension=pdo_pgsql
;extension=pgsql
```

3. ลบ `;` ออกหน้าทั้งสองบรรทัด:

```ini
extension=pdo_pgsql
extension=pgsql
```

4. **Restart Apache** ใน XAMPP Control Panel

### ขั้นที่ 2 — ตรวจสอบ Extension

สร้างไฟล์ `test.php` ใน htdocs:
```php
<?php phpinfo(); ?>
```
เปิด [http://localhost/test.php](http://localhost/test.php) แล้วค้นหา **"pdo_pgsql"** ในหน้าผลลัพธ์

### ขั้นที่ 3 — ตั้งค่าใน db_config_admin.php

- เลือก Driver: **PostgreSQL**
- Port จะเปลี่ยนเป็น **5432** อัตโนมัติ
- กรอก Host, Database, User, Password ของ PostgreSQL Server
- กด **ทดสอบ** → **บันทึก**

> ✅ **ไม่ต้องแก้โค้ดใดเพิ่มเติม** — ระบบจัดการ DSN ให้อัตโนมัติผ่าน `config.php`

---

## การตั้งค่า MOPH ALERT Keys {#setup-moph-keys}

รับ API Key ได้จาก [https://morpromt2f.moph.go.th](https://morpromt2f.moph.go.th)

### ขั้นที่ 1 — เข้าหน้าตั้งค่า

```
http://localhost/Fall_Risk_Alert-main/moph_keys_admin.php
```

### ขั้นที่ 2 — กรอก Keys ตามโมดูล

ระบบรองรับ Keys แยกตามโมดูล พร้อม Fallback อัตโนมัติ:

| โมดูล | ใช้กับ | ถ้าว่าง |
|-------|--------|--------|
| **Default** | ค่ากลาง Fallback | — |
| **COVID-19** | หน้า covid.php | ใช้ Default |
| **Fracture** | พลัดตก/หกล้ม | ใช้ Default |
| **Accident** | พ.ร.บ./อุบัติเหตุ | ใช้ Default |
| **Pharm Lab** | เภสัชกรรม | ใช้ Default |

> กรอกแค่ **Default** → ทุกโมดูลใช้ Key เดียวกัน  
> กรอก **เฉพาะโมดูล** → ส่งไปคนละ LINE Group ได้

### ขั้นที่ 3 — บันทึก

กด **"บันทึก Keys ทั้งหมด"** → ระบบสร้างไฟล์ `secrets/moph_keys.json`

---

## การตั้งค่า Task Scheduler (Cron) {#task-scheduler}

ระบบส่งแจ้งเตือนอัตโนมัติผ่าน Windows Task Scheduler

### ไฟล์ Batch ที่มาพร้อมระบบ

| ไฟล์ | โมดูล |
|------|-------|
| `run_fracture.bat` | พลัดตก / หกล้ม |
| `run_accident.bat` | พ.ร.บ. / อุบัติเหตุ |
| `run_covid.bat` | COVID-19 |
| `run_pharm_lab.bat` | เภสัชกรรม / Lab |

### วิธีที่ 1 — Import XML (แนะนำ)

1. เปิด **Task Scheduler** (พิมพ์ใน Start Menu)
2. Action → **Import Task...**
3. เลือกไฟล์ XML จากโฟลเดอร์โปรเจกต์:
   - `Fall_Risk_Alert_Auto Sender.xml`
   - `HOSxLine Covid Auto Sender.xml`
   - `HOSxLine Fructure Auto Sender.xml`
4. ปรับ path ของ `.bat` ไฟล์ให้ตรงกับเครื่อง

### วิธีที่ 2 — สร้าง Task ด้วยตนเอง

1. Task Scheduler → **Create Basic Task...**
2. ตั้งชื่อ เช่น `FallRisk - Fracture Alert`
3. Trigger: **Daily** → เปิด Advanced → ติ๊ก Repeat every **5 minutes** for **1 day**
4. Action: **Start a program** → Browse → เลือกไฟล์ `.bat`
5. กด Finish

---

## โครงสร้างไฟล์ {#file-structure}

```
Fall_Risk_Alert-main/
│
├── partials/
│   ├── header.php              # HR-CENTER 4.0 — Sidebar + Topbar
│   └── footer.php              # Footer JS (clock, logout, sidebar toggle)
│
├── secrets/                    # ⚠️ อยู่ใน .gitignore — ไม่ commit ขึ้น Git
│   ├── db_config.json          # การเชื่อมต่อ DB (สร้างโดยระบบ)
│   └── moph_keys.json          # MOPH API Keys (สร้างโดยระบบ)
│
├── img/
│   └── Logo_CKHospital.png     # โลโก้โรงพยาบาล
│
├── config.php                  # Config หลัก (DB, PDO, first-run detection)
├── auth_guard.php              # Session / Login guard
├── moph_keys_loader.php        # โหลด MOPH Keys → define PHP constants
│
├── login.php                   # หน้า Login
├── logout.php                  # ออกจากระบบ
├── index.php                   # หน้าหลัก (Dashboard KPI)
│
├── db_config_admin.php         # ตั้งค่า DB (MySQL / PostgreSQL)
├── moph_keys_admin.php         # ตั้งค่า MOPH ALERT Keys
├── settings.php                # ตั้งค่า UI (ธีม / ฟอนต์ / สีไอคอน)
│
├── patient.php                 # กลุ่มเสี่ยงจิตเวช
├── drugitems01.php             # กลุ่มเสี่ยงยาอันตราย
├── sexual.php                  # ผู้ถูกข่มขืน / ทำร้าย
├── accident_queue_ui.php       # คนไข้ พ.ร.บ.
├── fracture_queue_ui.php       # พลัดตก / หกล้ม
├── pharm_lab_queue_ui.php      # เภสัชกรรม / Lab
│
├── covid.php                   # COVID-19
├── dengue.php                  # ไข้เลือดออก
├── Leptospira.php              # เลปโตสไปโรสิส
├── scrubtyphus.php             # สครับไทฟัส
│
├── fracture_dashboard.php      # Dashboard สถิติ
│
├── users.sql                   # SQL สร้างตาราง users
├── *.bat                       # Windows Batch สำหรับ Cron
└── *.xml                       # Task Scheduler Import Files
```

---

## การใช้งานระบบ {#usage}

### เข้าสู่ระบบ

```
http://localhost/Fall_Risk_Alert-main/
```

### เมนูหลัก

| เมนู | คำอธิบาย |
|------|----------|
| หน้าหลัก | Dashboard KPI รายโมดูล |
| กลุ่มเสี่ยงจิตเวช | Queue + ส่งแจ้งเตือน LINE |
| ยาอันตราย | รายการยากลุ่มเสี่ยง |
| พ.ร.บ. | Queue คนไข้ พ.ร.บ. |
| พลัดตก/หกล้ม | Queue + ส่งแจ้งเตือน |
| เภสัชกรรม | Queue เภสัช/Lab |
| ตั้งค่าฐานข้อมูล | เปลี่ยน DB Driver / Host / Port |
| ตั้งค่า MOPH Keys | อัปเดต API Keys |
| ตั้งค่าระบบ | ธีม / ขนาดฟอนต์ / สีไอคอน |

### การส่งแจ้งเตือน

**ส่งด้วยตนเอง:** กดปุ่ม "ส่งซ้ำ" หรือ "ส่งแจ้งเตือน" ในหน้า Queue  
**ส่งอัตโนมัติ:** ผ่าน Windows Task Scheduler ตามที่ตั้งค่าไว้

### การปรับแต่ง UI

ไปที่ **ตั้งค่าระบบ** (`settings.php`) สามารถปรับ:
- 🎨 **ธีม**: Light / Dark / Pastel / Classic
- 🔤 **ขนาดฟอนต์**: Small / Normal / Large / X-Large
- 🎨 **สีไอคอน**: Color picker + 16 Preset สี

---

## Troubleshooting {#troubleshooting}

### ❌ หน้าเว็บขึ้น "DB connect failed"
- ตรวจสอบว่า MySQL/PostgreSQL service เปิดอยู่
- ไปที่ `db_config_admin.php` → กด "ทดสอบการเชื่อมต่อ"

### ❌ "Call to undefined function pdo_pgsql" หรือ Driver not found (PostgreSQL)
- เปิด `php.ini` → ลบ `;` หน้า `extension=pdo_pgsql` → Restart Apache
- ดูขั้นตอนใน [การตั้งค่าสำหรับ PostgreSQL](#postgresql-setup)

### ❌ กดปุ่ม "ส่งซ้ำ" แล้วไม่มีอะไรเกิดขึ้น
- เปิด DevTools (F12) → Console → ดู Error
- ตรวจสอบว่าตั้งค่า MOPH Keys แล้ว
- ตรวจสอบ Internet ไปยัง `morpromt2f.moph.go.th`

### ❌ ไอคอนไม่แสดง (เป็นกล่องเปล่า)
- ตรวจสอบ Internet connection (Google Fonts CDN)
- ระบบใช้ **Material Symbols Outlined** — ต้องการ Internet

### ❌ หน้า Login redirect วนซ้ำ
- ตรวจสอบว่า Import `users.sql` แล้ว
- ลบ Session/Cookie แล้วลอง Login ใหม่

### ❌ ไม่พบโฟลเดอร์ secrets/ / บันทึกไม่สำเร็จ
- สร้างโฟลเดอร์ `secrets/` ด้วยตนเอง (ดูขั้นที่ 2)
- ตรวจสอบสิทธิ์ Write ของ Apache บนโฟลเดอร์นั้น

---

## License

MIT License — ใช้งาน แก้ไข และแจกจ่ายได้อย่างอิสระ  
พัฒนาโดย รพ.เชียงกลาง จ.น่าน

---

> **พบปัญหาหรือต้องการความช่วยเหลือ?** กรุณาเปิด Issue ที่ [GitHub Repository](https://github.com/nuttapong39/Fall_Risk_Alert-V.2)
