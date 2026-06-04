# คู่มือการติดตั้ง MedAlert — ระบบแจ้งเตือนผู้ป่วยกลุ่มเสี่ยง
> สำหรับ IT โรงพยาบาลที่นำระบบไป implement

---

## สารบัญ

1. [ข้อกำหนดระบบ](#1-ข้อกำหนดระบบ)
2. [โครงสร้างโมดูลแจ้งเตือน](#2-โครงสร้างโมดูลแจ้งเตือน)
3. [ขั้นตอนที่ 1 — ติดตั้ง XAMPP](#3-ขั้นตอนที่-1--ติดตั้ง-xampp)
4. [ขั้นตอนที่ 2 — Deploy ไฟล์ระบบ](#4-ขั้นตอนที่-2--deploy-ไฟล์ระบบ)
5. [ขั้นตอนที่ 3 — สร้างฐานข้อมูล](#5-ขั้นตอนที่-3--สร้างฐานข้อมูล)
6. [ขั้นตอนที่ 4 — ตั้งค่า secrets](#6-ขั้นตอนที่-4--ตั้งค่า-secrets)
7. [ขั้นตอนที่ 5 — ตั้งค่า MOPH Alert API Keys](#7-ขั้นตอนที่-5--ตั้งค่า-moph-alert-api-keys)
8. [ขั้นตอนที่ 6 — สร้าง User แรก (Admin)](#8-ขั้นตอนที่-6--สร้าง-user-แรก-admin)
9. [ขั้นตอนที่ 7 — ทดสอบระบบ](#9-ขั้นตอนที่-7--ทดสอบระบบ)
10. [ขั้นตอนที่ 8 — ตั้ง Task Scheduler](#10-ขั้นตอนที่-8--ตั้ง-task-scheduler)
11. [ปรับค่าเฉพาะโรงพยาบาล](#11-ปรับค่าเฉพาะโรงพยาบาล)
12. [การแก้ปัญหาเบื้องต้น](#12-การแก้ปัญหาเบื้องต้น)

---

## 1. ข้อกำหนดระบบ

| รายการ | ข้อกำหนด |
|---|---|
| **OS** | Windows 10 / Windows Server 2016 ขึ้นไป |
| **XAMPP** | 8.2.x (PHP 8.2, Apache 2.4, MySQL 5.7+) |
| **HOSxP** | HOSxP PCU หรือ HOSxP XE (เชื่อมต่อ MySQL ได้) |
| **เครือข่าย** | เชื่อมต่อ Internet ได้ (ยิง MOPH Alert API) |
| **LINE** | กลุ่ม LINE ที่ได้ขอ client-key / secret-key จาก MOPH Alert แล้ว |

> **หมายเหตุ:** ระบบนี้ติดตั้งบน **เครื่อง Server HOSxP** หรือเครื่องที่เชื่อมต่อ DB HOSxP ได้โดยตรง

---

## 2. โครงสร้างโมดูลแจ้งเตือน

ระบบมี 10 โมดูล แต่ละโมดูลใช้ LINE channel (client-key) แยกกัน

| โมดูล | ชื่อโมดูล | Queue Table | Worker .bat |
|---|---|---|---|
| 🦴 Fall Risk | พลัดตก / หกล้ม | `fracture_queue` | `run_fracture.bat` |
| 🚑 Accident | อุบัติเหตุ พ.ร.บ. | `accident_queue` | `run_accident.bat` |
| 🦠 COVID-19 | ผล COVID Positive | `covid_queue` | `run_covid.bat` |
| 💊 Pharm Lab | Lab วิกฤต ห้องยา | `pharm_lab_queue` | `run_pharm_lab.bat` |
| ⚠️ Drug | ยาอันตราย | `drug_queue` | `run_drug.bat` |
| 🧠 Patient | จิตเวช / ทำร้ายตัวเอง | `patient_queue` | `run_patient.bat` |
| 🦟 Dengue | ไข้เลือดออก | `dengue_queue` | `run_dengue.bat` |
| 🔬 Lepto | เลปโตสไปโรซิส | — (web-only) | `run_lepto.bat` |
| 🌿 Scrub | สครับไทฟัส | — (web-only) | `run_scrub.bat` |
| 🚨 Sexual | ผู้ถูกกระทำความรุนแรง | `sexual_alert_queue` | `run_sexual.bat` |

---

## 3. ขั้นตอนที่ 1 — ติดตั้ง XAMPP

### 3.1 ดาวน์โหลดและติดตั้ง

1. ดาวน์โหลด XAMPP 8.2.x จาก https://www.apachefriends.org
2. ติดตั้งที่ `C:\xampp` (ใช้ path นี้เท่านั้น)
3. เปิด **XAMPP Control Panel** → Start **Apache** และ **MySQL**
4. ทดสอบเปิด http://localhost → ควรเห็นหน้า XAMPP Dashboard

### 3.2 ตั้ง Apache ให้ Start อัตโนมัติ

เปิด XAMPP Control Panel → คลิก **Config** → ติ๊ก ☑ Apache → ☑ MySQL → OK

### 3.3 ตั้งค่า PHP

เปิดไฟล์ `C:\xampp\php\php.ini` แก้ค่าต่อไปนี้:

```ini
max_execution_time = 300
memory_limit = 256M
extension=curl
extension=pdo_mysql
extension=mbstring
```

> ตรวจสอบว่า Extension เหล่านี้ไม่มีเครื่องหมาย `;` นำหน้า (ถ้ามีให้ลบออก)

---

## 4. ขั้นตอนที่ 2 — Deploy ไฟล์ระบบ

### 4.1 Clone หรือ Copy ไฟล์

**วิธีที่ 1 — Git Clone (แนะนำ)**
```bat
cd C:\xampp\htdocs
git clone https://github.com/nuttapong39/Fall_Risk_Alert-V.2.git Fall_Risk_Alert-main
```

**วิธีที่ 2 — Copy ไฟล์**
- แตกไฟล์ zip ไปที่ `C:\xampp\htdocs\Fall_Risk_Alert-main\`

### 4.2 ตรวจสอบโครงสร้างไฟล์

หลัง deploy ควรมีโครงสร้างดังนี้:
```
C:\xampp\htdocs\Fall_Risk_Alert-main\
├── config.php
├── covid.php
├── fracture.php
├── run_covid.bat
├── run_fracture.bat
├── ... (ไฟล์อื่น ๆ)
├── secrets\          ← โฟลเดอร์นี้อาจยังไม่มี ต้องสร้างเอง
└── logs\             ← สร้างอัตโนมัติเมื่อรัน
```

### 4.3 สร้างโฟลเดอร์ secrets (ถ้ายังไม่มี)

```bat
mkdir C:\xampp\htdocs\Fall_Risk_Alert-main\secrets
```

---

## 5. ขั้นตอนที่ 3 — สร้างฐานข้อมูล

> ระบบนี้ใช้ **ฐานข้อมูลเดียวกับ HOSxP** (`hosxp`) เพื่อความสะดวก  
> สามารถใช้ฐานข้อมูลแยกได้ แต่ต้องแก้ config.php ตามนั้น

### 5.1 เปิด phpMyAdmin

http://localhost/phpmyadmin → เลือก database `hosxp`

### 5.2 Import ตาราง Queue ทั้งหมด

ไปที่ tab **SQL** แล้ว import ทีละไฟล์ตามลำดับ:

```sql
-- ① ตารางผู้ใช้ระบบ (สำคัญ — ต้องทำก่อน)
source C:\xampp\htdocs\Fall_Risk_Alert-main\users.sql

-- ② Queue Tables (import ตามโมดูลที่ต้องการใช้)
source C:\xampp\htdocs\Fall_Risk_Alert-main\fracture_queue.sql
source C:\xampp\htdocs\Fall_Risk_Alert-main\accident_queue.sql
source C:\xampp\htdocs\Fall_Risk_Alert-main\pharm_lab_queue.sql
source C:\xampp\htdocs\Fall_Risk_Alert-main\drug_queue.sql
source C:\xampp\htdocs\Fall_Risk_Alert-main\patient_queue.sql
source C:\xampp\htdocs\Fall_Risk_Alert-main\dengue_queue.sql
source C:\xampp\htdocs\Fall_Risk_Alert-main\sexual_alert_queue.sql
```

**หรือ** ใช้ phpMyAdmin → Import → เลือกไฟล์ .sql ทีละไฟล์

### 5.3 สร้างตาราง covid_queue (ถ้ายังไม่มี)

เปิด phpMyAdmin → SQL แล้วรัน:

```sql
CREATE TABLE IF NOT EXISTS `covid_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lab_order_number` varchar(50) NOT NULL,
  `hn` varchar(15) NOT NULL,
  `fullname` varchar(255) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `cid` varchar(20) DEFAULT NULL,
  `informaddr` text DEFAULT NULL,
  `hometel` varchar(50) DEFAULT NULL,
  `vstdate` date DEFAULT NULL,
  `doctor` varchar(255) DEFAULT NULL,
  `pdx` varchar(20) DEFAULT NULL,
  `lab_order_result` varchar(100) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `attempt` int(11) NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `last_attempt_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `out_ref` varchar(255) DEFAULT NULL,
  `line_message_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lab_order` (`lab_order_number`),
  KEY `idx_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 6. ขั้นตอนที่ 4 — ตั้งค่า secrets

### 6.1 สร้างไฟล์ db_config.json

สร้างไฟล์ `C:\xampp\htdocs\Fall_Risk_Alert-main\secrets\db_config.json`

```json
{
    "driver": "mysql",
    "host": "localhost",
    "port": 3306,
    "name": "hosxp",
    "user": "root",
    "pass": "รหัสผ่าน MySQL ของโรงพยาบาล"
}
```

> **ค่าที่ต้องแก้:**
> - `host` → IP ของ MySQL HOSxP (ถ้าอยู่เครื่องเดียวกัน ใช้ `localhost`)
> - `user` / `pass` → user/password MySQL ของโรงพยาบาล (ปกติ `root`)
> - `name` → ชื่อ database HOSxP (ปกติ `hosxp`)

### 6.2 สร้างไฟล์ moph_keys.json (เปล่าก่อน)

สร้างไฟล์ `C:\xampp\htdocs\Fall_Risk_Alert-main\secrets\moph_keys.json`

```json
{
    "default": {
        "client": "",
        "secret": ""
    },
    "covid": {
        "client": "",
        "secret": ""
    },
    "fracture": {
        "client": "",
        "secret": ""
    },
    "accident": {
        "client": "",
        "secret": ""
    },
    "pharm_lab": {
        "client": "",
        "secret": ""
    },
    "drug": {
        "client": "",
        "secret": ""
    },
    "dengue": {
        "client": "",
        "secret": ""
    },
    "patient": {
        "client": "",
        "secret": ""
    },
    "lepto": {
        "client": "",
        "secret": ""
    },
    "scrub": {
        "client": "",
        "secret": ""
    },
    "sexual": {
        "client": "",
        "secret": ""
    }
}
```

> กุญแจจะกรอกในขั้นตอนที่ 5

---

## 7. ขั้นตอนที่ 5 — ตั้งค่า MOPH Alert API Keys

### 7.1 ขอ Keys จาก MOPH Alert

1. เข้าระบบ MOPH Alert: https://morpromt2f.moph.go.th
2. สมัคร/ขอ **client-key** และ **secret-key** สำหรับแต่ละโมดูลที่ต้องการใช้งาน
3. แต่ละโมดูลจะได้ key คู่หนึ่ง ผูกกับกลุ่ม LINE ที่กำหนด

### 7.2 กรอก Keys ผ่านหน้าเว็บ (แนะนำ)

1. เปิด http://localhost/Fall_Risk_Alert-main/login.php
2. Login เข้าระบบ
3. ไปที่ **ตั้งค่า → MOPH Keys**
4. กรอก client-key และ secret-key สำหรับแต่ละโมดูล

**หรือ** แก้ไฟล์ `secrets/moph_keys.json` โดยตรง:

```json
{
    "default": {
        "client": "ใส่ default client key ตรงนี้",
        "secret": "ใส่ default secret key ตรงนี้"
    },
    "fracture": {
        "client": "client key สำหรับกลุ่ม LINE พลัดตก/หกล้ม",
        "secret": "secret key สำหรับกลุ่ม LINE พลัดตก/หกล้ม"
    }
}
```

> **หมายเหตุ:** โมดูลที่ไม่ได้กรอก key จะใช้ `default` key แทนโดยอัตโนมัติ  
> ถ้าต้องการให้ทุกโมดูลแจ้งเตือนกลุ่ม LINE เดียวกัน กรอกแค่ `default` key เพียงชุดเดียวก็พอ

---

## 8. ขั้นตอนที่ 6 — สร้าง User แรก (Admin)

### 8.1 สร้าง User ด้วย SQL

เปิด phpMyAdmin → database `hosxp` → tab SQL:

```sql
INSERT INTO users (username, password_hash, first_name, last_name, position_name, is_active)
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'ผู้ดูแล',
    'ระบบ',
    'IT',
    1
);
```

> password เริ่มต้น: **`password`** (เปลี่ยนทันทีหลัง login ครั้งแรก)

### 8.2 Login ครั้งแรก

1. เปิด http://localhost/Fall_Risk_Alert-main/login.php
2. Username: `admin` / Password: `password`
3. เปลี่ยนรหัสผ่านทันทีที่ **ตั้งค่า → บัญชีผู้ใช้**

---

## 9. ขั้นตอนที่ 7 — ทดสอบระบบ

### 9.1 ทดสอบการเชื่อมต่อ Database

เปิด http://localhost/Fall_Risk_Alert-main/  
→ ถ้าเข้าหน้า Dashboard ได้ = DB เชื่อมต่อสำเร็จ  
→ ถ้าขึ้น "DB connect failed" = ตรวจสอบ `secrets/db_config.json` อีกครั้ง

### 9.2 ทดสอบส่ง LINE (ไม่รอ Task Scheduler)

1. เปิด http://localhost/Fall_Risk_Alert-main/fracture_queue_ui.php
2. กด **Import HOSxP** เพื่อดึงข้อมูลเข้าคิว
3. เลือก checkbox แถวใดแถวหนึ่ง → กด **Send Now**
4. ตรวจสอบกลุ่ม LINE ว่าได้รับแจ้งเตือนหรือไม่

### 9.3 ทดสอบ Worker ผ่าน Command Line

เปิด Command Prompt แล้วรัน:

```bat
cd C:\xampp\htdocs\Fall_Risk_Alert-main

:: ทดสอบ dryrun (ไม่ส่งจริง)
run_fracture.bat

:: ดู log
type logs\fracture_task_run.log
```

ใน log ควรเห็น:
```
[2026-06-04 08:00:01] start
[2026-06-04 08:00:02] Ingest: found X new rows.
[2026-06-04 08:00:02] Send: to process X rows.
[2026-06-04 08:00:03] done
```

> ⚠️ ถ้า log แสดงแค่ `start` → `done` ใน 0.1 วินาที แสดงว่า auth_guard block อยู่ ดูหัวข้อ [แก้ปัญหาเบื้องต้น](#12-การแก้ปัญหาเบื้องต้น)

---

## 10. ขั้นตอนที่ 8 — ตั้ง Task Scheduler

### 10.1 เปิด Task Scheduler

```
Start Menu → ค้นหา "Task Scheduler" → เปิด
```

### 10.2 สร้าง Task แต่ละโมดูล

คลิก **Create Basic Task** หรือใช้คำสั่งด้านล่าง (เลือกเฉพาะโมดูลที่ใช้งาน)

---

#### 🦴 Fall Risk (พลัดตก/หกล้ม) — ทุก 15 นาที
```bat
schtasks /Create /SC MINUTE /MO 15 /TN "MedAlert_FallRisk" ^
  /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_fracture.bat\"" ^
  /RU SYSTEM /F
```

#### 🚑 Accident (อุบัติเหตุ) — ทุก 15 นาที
```bat
schtasks /Create /SC MINUTE /MO 15 /TN "MedAlert_Accident" ^
  /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_accident.bat\"" ^
  /RU SYSTEM /F
```

#### 🦠 COVID-19 — ทุก 15 นาที
```bat
schtasks /Create /SC MINUTE /MO 15 /TN "MedAlert_Covid" ^
  /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_covid.bat\"" ^
  /RU SYSTEM /F
```

#### 💊 Pharm Lab (Lab วิกฤต) — ทุก 15 นาที
```bat
schtasks /Create /SC MINUTE /MO 15 /TN "MedAlert_PharmLab" ^
  /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_pharm_lab.bat\"" ^
  /RU SYSTEM /F
```

#### ⚠️ Drug (ยาอันตราย) — ทุก 15 นาที
```bat
schtasks /Create /SC MINUTE /MO 15 /TN "MedAlert_Drug" ^
  /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_drug.bat\" sync" ^
  /RU SYSTEM /F
```

#### 🧠 Patient (จิตเวช) — ทุก 15 นาที
```bat
schtasks /Create /SC MINUTE /MO 15 /TN "MedAlert_Patient" ^
  /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_patient.bat\"" ^
  /RU SYSTEM /RL HIGHEST /F
```

#### 🦟 Dengue (ไข้เลือดออก) — ทุกวัน 08:00
```bat
schtasks /Create /SC DAILY /ST 08:00 /TN "MedAlert_Dengue" ^
  /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_dengue.bat\"" ^
  /RU SYSTEM /F
```

#### 🚨 Sexual Assault — ทุก 15 นาที
```bat
schtasks /Create /SC MINUTE /MO 15 /TN "MedAlert_Sexual" ^
  /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_sexual.bat\" send" ^
  /RU SYSTEM /RL HIGHEST /F
```

### 10.3 ตั้งค่า Task เพิ่มเติม (สำคัญ)

หลังสร้าง Task แต่ละตัว ให้ทำตามนี้:
1. เปิด Task Scheduler → ค้นหา Task ที่สร้าง → คลิกขวา **Properties**
2. Tab **General** → ติ๊ก ☑ "Run whether user is logged on or not"
3. Tab **Actions** → ตรวจสอบ **Start in** = `C:\xampp\htdocs\Fall_Risk_Alert-main`
4. Tab **Settings** → ติ๊ก ☑ "If the task is already running, do not start a new instance"

### 10.4 ทดสอบ Task

คลิกขวา Task → **Run** → ดู log:

```bat
type C:\xampp\htdocs\Fall_Risk_Alert-main\logs\fracture_task_run.log
```

---

## 11. ปรับค่าเฉพาะโรงพยาบาล

### 11.1 แก้ชื่อโรงพยาบาลใน Flex Message

แก้ไฟล์ `config.php` บรรทัด:
```php
define('LINE_TITLE', 'ผู้ป่วย Covid-19 รพ.ชื่อโรงพยาบาล');
```

แก้ไฟล์ `flex_fracture.php`:
```php
define('FALL_SUBTITLE', 'Fall Risk Alert สำหรับเจ้าหน้าที่ รพ.สต. เครือข่าย รพ.ชื่อโรงพยาบาล');
```

> แก้ชื่อโรงพยาบาลใน `flex_*.php` ทุกไฟล์ตามโมดูลที่ใช้งาน

### 11.2 แก้รหัส Lab COVID

เปิดไฟล์ `covid.php` บรรทัด 51:
```php
$where[] = "l.lab_items_code IN ('3066','3082','3084','3088')";
```
แก้รหัสให้ตรงกับ `lab_items_code` ใน HOSxP ของโรงพยาบาล  
ตรวจสอบได้ที่ phpMyAdmin: `SELECT lab_items_code, lab_items_name FROM lab_items WHERE lab_items_name LIKE '%covid%'`

### 11.3 แก้รหัสยาอันตราย

เปิดไฟล์ `run_drug.bat` บรรทัด:
```bat
set "DEFAULT_ICODES=1483860"
```
แก้เป็น icode ของยาอันตรายในโรงพยาบาล (คั่นหลายรหัสด้วย `,`)

### 11.4 แก้รหัส Lab Sexual Assault

เปิดไฟล์ `flex_sexual.php`:
```php
define('LAB_CODE_SEXUAL', '2811');
```
แก้เป็น lab_items_code ที่ใช้ตรวจ sexual assault ใน HOSxP

### 11.5 แก้รูปแบนเนอร์ (ไม่บังคับ)

แต่ละ `flex_*.php` มีค่า `*_HEADER_URL` ที่ชี้ไปรูปโลโก้ รพ.เชียงกลาง  
สามารถเปลี่ยนเป็น URL รูปของโรงพยาบาลตัวเองได้:
```php
define('FALL_HEADER_URL', 'https://your-hospital.go.th/path/to/banner.jpg');
```

---

## 12. การแก้ปัญหาเบื้องต้น

### ปัญหา: เข้าเว็บแล้วขึ้น "DB connect failed"
✅ ตรวจสอบ `secrets/db_config.json` ว่า host, user, pass ถูกต้อง  
✅ ทดสอบ MySQL ที่ phpMyAdmin ว่า login ได้  
✅ ตรวจสอบว่า MySQL service กำลัง Start อยู่

### ปัญหา: Task Scheduler รันแล้ว log แสดงแค่ start → done ใน 0.1 วินาที
✅ Worker script มี `auth_guard.php` ที่ไม่มีการตรวจ CLI — ดูไฟล์ .php ที่ .bat เรียก แล้วเปลี่ยนบรรทัด:
```php
require_once __DIR__ . '/auth_guard.php';
// แก้เป็น:
if (PHP_SAPI !== 'cli') require_once __DIR__ . '/auth_guard.php';
```

### ปัญหา: API ตอบ HTTP 200 แต่ LINE ไม่ได้รับ
✅ ตรวจสอบ Flex Message ว่าไม่มี `rgba()` — LINE รองรับเฉพาะ `#RRGGBB` หรือ `#RRGGBBAA`  
✅ ตรวจสอบว่าไม่มี `fontFamily` ที่ไม่ใช่ค่ามาตรฐาน LINE  
✅ ตรวจสอบ client-key / secret-key ว่าถูกต้องและยังไม่หมดอายุ

### ปัญหา: Log แสดง CURL error หรือ timeout
✅ ตรวจสอบว่าเครื่อง Server เชื่อมต่อ Internet ได้  
✅ ตรวจสอบ Firewall ว่าอนุญาต outbound HTTPS (port 443)  
✅ ทดสอบ: `curl https://morpromt2f.moph.go.th` จาก cmd

### ปัญหา: ไม่พบข้อมูลผู้ป่วยหลัง Import
✅ ตรวจสอบ lab_items_code ให้ตรงกับ HOSxP ของโรงพยาบาล  
✅ ตรวจสอบช่วงวันที่ (default ดึงย้อนหลัง 7 วัน)  
✅ ตรวจสอบ user/password MySQL ว่ามีสิทธิ์ SELECT ตาราง HOSxP  

### ดู Log ทั้งหมด

```bat
:: Log การรัน Task
type C:\xampp\htdocs\Fall_Risk_Alert-main\logs\fracture_task_run.log

:: Log PHP error
type C:\xampp\htdocs\Fall_Risk_Alert-main\logs\fracture_php_errors.log

:: Log การส่ง MOPH API (ทุกโมดูล)
type C:\xampp\htdocs\Fall_Risk_Alert-main\logs\moph_alert.log
```

---

## ✅ Checklist สรุปขั้นตอน

```
[ ] ติดตั้ง XAMPP + Start Apache/MySQL
[ ] Clone/Copy ไฟล์ระบบไปที่ C:\xampp\htdocs\Fall_Risk_Alert-main\
[ ] สร้างโฟลเดอร์ secrets\
[ ] Import SQL ทุกไฟล์เข้า database hosxp
[ ] สร้างไฟล์ secrets\db_config.json (แก้ host/user/pass)
[ ] สร้างไฟล์ secrets\moph_keys.json (กรอก client/secret key)
[ ] สร้าง user admin ใน phpMyAdmin
[ ] Login ทดสอบที่ http://localhost/Fall_Risk_Alert-main/login.php
[ ] ทดสอบ Send Now จากหน้า queue UI
[ ] ยืนยันได้รับ LINE notification
[ ] ปรับค่าเฉพาะโรงพยาบาล (ชื่อ รพ., lab_items_code, icode ยา)
[ ] ตั้ง Task Scheduler ทุกโมดูลที่ใช้งาน
[ ] ทดสอบ Task Scheduler ทำงานได้ผ่าน log
```

---

> **ติดต่อสอบถาม:** หากติดปัญหาในการ implement กรุณาตรวจสอบ log ก่อนเสมอ  
> log ทั้งหมดอยู่ที่ `C:\xampp\htdocs\Fall_Risk_Alert-main\logs\`
