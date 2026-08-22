# โครงสร้างโปรเจกต์ MedAlert

> แผนที่จัดหมวดไฟล์ทั้งหมด — ให้เข้าใจว่าไฟล์ไหนทำหน้าที่อะไร โดย**ไม่ย้ายไฟล์ code**
> (โปรเจกต์เป็น PHP flat + XAMPP: ไฟล์ผูกกันด้วย `require __DIR__`, `href`, `run_*.bat`, URL —
> ย้าย .php = เสี่ยงพัง จึงจัดระบบด้วย "แผนที่" นี้แทน ส่วนไฟล์เอกสาร/legacy ที่ปลอดภัยได้ย้ายเข้าโฟลเดอร์แล้ว)

---

## 🧭 หลักการตั้งชื่อ (naming convention)

ทุก module ใช้ชื่อลงท้ายเหมือนกัน ทำให้เดาไฟล์ได้:

```
run_<mod>.bat   →   <worker>.php   →   <mod>_queue (ตาราง)   →   <mod>_queue_ui.php   →   flex_<mod>.php
   ตั้งเวลา            ดึง+ส่ง            เก็บคิว                    หน้าจอจัดการคิว           สร้าง LINE Flex
```

---

## 📇 แผนที่ต่อ module (10 โมดูล)

| module | หน้าจอ (URL) | worker (run_*.bat เรียก) | ตารางคิว | Flex builder |
|---|---|---|---|---|
| fracture (หกล้ม) | `fracture_queue_ui.php` | `fracture.php` | `fracture_queue` | `flex_fracture.php` |
| patient (จิตเวช) | `patient.php` | `patient_ingest.php` | `patient_queue` | `flex_patient.php` |
| drug (ยาอันตราย) | `drugitems01.php` | `drug_send.php` | `drug_queue` | `flex_drug.php` |
| accident (พ.ร.บ.) | `accident_queue_ui.php` | `accident_worker.php` | `accident_queue` | `flex_accident.php` |
| pharm_lab (Lab วิกฤต) | `pharm_lab_queue_ui.php` | `pharm_lab.php` | `pharm_lab_queue` | `flex_pharm.php` |
| covid | `covid_queue_ui.php` | `covid.php` | `covid_queue` | `flex_builders.php` (covid_buildMophPayload) |
| dengue | `dengue_queue_ui.php` | `dengue_ingest.php` | `dengue_queue` | `flex_disease.php` |
| lepto | `Leptospira.php` | `lepto_ingest.php` | `lepto_queue` | `flex_disease.php` |
| scrub | `scrubtyphus.php` | `scrub_ingest.php` | `scrub_queue` | `flex_disease.php` |
| sexual (STI) | `sexual.php` | `sexual_ingest.php` | `sexual_alert_queue` | `flex_sexual.php` |

> ไฟล์เสริมต่อ module: `<mod>_queue_action.php` / `<mod>_action.php` (ปุ่ม send/requeue/clear ในหน้าจอ) · `<mod>_queue.sql` (schema)

---

## 🗂️ หมวดไฟล์ (จัดตามหน้าที่)

### 1) แกนกลาง (Core / bootstrap) — root
- `config.php` — โหลดค่ากลาง, เชื่อม DB, ตรวจ first-run, require loaders
- `auth_guard.php` — ตรวจ login (session)
- `moph_keys_loader.php` — โหลด MOPH keys → PHP constants
- `site_config_loader.php` — โหลดชื่อ รพ. → `HOSPITAL_SHORT`/`HOSPITAL_FULL`
- `covid_lib.php` — utility (row_to_utf8, extract_moph_message_id ฯลฯ)
- `telegram_lib.php` — ส่ง Telegram mirror

### 2) หน้าเว็บ (Entry points — เปิดด้วย URL, **ต้องอยู่ root**)
- ระบบ: `login.php` `logout.php` `index.php` `dashboard.php`
- ตั้งค่า: `db_config_admin.php` `moph_keys_admin.php` `settings.php` `flex_editor.php`
- คิวราย module: ตามตาราง "แผนที่ต่อ module" ด้านบน
- Dashboard: `dashboard.php` + `dashboard_modules.php` (registry) + `dashboard_export.php` (CSV)

### 3) Worker / อัตโนมัติ — root + `task/`
- `run_<mod>.bat` (10) — ตัวตั้งเวลาเรียก worker
- worker `.php` — ตาม "แผนที่ต่อ module"
- `<mod>_ingest.php` / `<mod>_send.php` — ตัวดึง HOSxP + ส่ง MOPH
- `cron_covid_queue.php` — cron เสริม covid
- `task/` — **ตัวติดตั้ง Scheduled Task**: `install_tasks.bat`, `uninstall_tasks.bat`, `*.xml` (10), `README.txt`
- `enable_php_extensions.bat` — เปิด PHP extension อัตโนมัติ (ตอนติดตั้ง)

### 4) Flex Message system (config-driven)
- `flex_theme.php` — โหลดธีมจาก `secrets/flex_themes.json` (มี default ฝังในตัว)
- `flex_card.php` — layout กลาง (flex_render_card)
- `flex_builders.php` — builder ราย module (thin) + covid
- `flex_disease.php` — builder รวม dengue/lepto/scrub
- `flex_<mod>.php` — builder/legacy ราย module
- `flex_editor.php` — หน้าแก้ธีม (UI)
- ไอคอน watermark: `assets/flex_icons/*.png`

### 5) แหล่งข้อมูล (Data sources) — `sources/`
- `sources/<mod>_source.php` — query HOSxP (รองรับ MySQL/PostgreSQL); อ่านเงื่อนไข (lab code/pttype/icode/pdx) จาก `module_filter($mod)` เมื่อไม่ส่ง arg มาตรงๆ (default = ค่าเดิม)

### 5b) เงื่อนไขดึงข้อมูลที่แก้ได้ (Editable source filters)
- `module_filters_loader.php` — default (ฝังในตัว, = ค่าปัจจุบันของแต่ละ module) + `module_filter($mod)`/`module_filter_schema($mod)` + helper สร้าง SQL ปลอดภัย (`mf_codes`/`mf_in`/`mf_pdx_clause`/...)
- `module_filter_action.php` — บันทึก/รีเซ็ตเงื่อนไข (POST จาก modal, CSRF + validate)
- `module_filter_preview.php` — นับผลลัพธ์จากเงื่อนไขที่กำลังกรอก (ก่อนบันทึก, ย้อนหลัง 30 วัน, read-only)
- `partials/filter_modal.php` — ปุ่ม "แก้ไขเงื่อนไขดึงข้อมูล" + modal (schema-driven) ที่ฝังในทุกหน้า `*_queue_ui.php`/`patient.php`/`sexual.php`/`Leptospira.php`/`scrubtyphus.php`/`drugitems01.php`
- แก้ได้ผ่านหน้าเว็บ ไม่ต้องแตะโค้ด — มีผลทั้ง worker อัตโนมัติและปุ่ม Import (ใช้ store เดียวกัน)

### 6) Schema — root (`*.sql`)
- `users.sql` + `<mod>_queue.sql` (10) — ตัวช่วย `db_config_admin.php` deploy ให้อัตโนมัติ

### 7) Config / secrets — `secrets/` (🔒 gitignore)
- `db_config.json` · `moph_keys.json` · `site_config.json` · `flex_themes.json` · `module_filters.json` (ตัวจริง — per-install)
- `*.example.json` — template (อยู่ใน git)

### 8) Layout / assets
- `partials/header.php` `partials/footer.php` — sidebar/topbar/theme (HR-CENTER 4.0)
- `img/` · `assets/` · `plugins/` · `css/` · `js/` — รูป/ไลบรารีหน้าเว็บ

### 9) เอกสาร — root + `docs/`
- root (มาตรฐาน คงไว้): `README.md` `INSTALL.md` `CLAUDE.md` `CONTEXT.md` `composer.json`
- `docs/`: `install-guide.html` (คู่มือภาพ) · `PROJECT-STRUCTURE.md` (ไฟล์นี้) · `adr/` · `agents/` · `ui-ux.md` · `UX_REVIEW.md` · `techstack.txt` · `lab_alert_new.txt`

### 10) Archive (ของเก่า เก็บอ้างอิง) — `archive/`
- `preview_*.html` (mockup เก่า) · `*Auto Sender.xml` (Task export รุ่นเก่า — ชุดจริงอยู่ใน `task/`)

---

## ⚠️ Legacy ที่ยังคง root (อย่าย้าย/ลบโดยไม่ตรวจ)
- `index1.html` — layout เก่า ยังถูก `require` โดย 6 หน้า: `drugitems.php` `report_daily.php` `meeting.php` `queue_ui.php` `sum_dashboard.php` `fracture_report_daily.php`
- หน้าเหล่านี้เป็น**ของเก่า**ที่แทนที่ด้วยระบบ queue ปัจจุบันแล้ว แต่ยังไม่ถูกถอด — หากจะรื้อ ต้องตรวจว่าไม่มีลิงก์/บุ๊กมาร์กใช้งานก่อน

---

## 🚫 ทำไมไม่ย้าย .php เข้าโฟลเดอร์
ไฟล์ .php 80 ตัวผูกกันแน่น: **68 จุด** `require __DIR__` · **80 จุด** `href="*.php"` · **10 จุด** `run_*.bat` · task XML · URL/bookmark
→ ย้ายแล้วต้องแก้ทุกจุดให้ครบ **และ URL ยังเปลี่ยน** (bookmark/Task Scheduler พัง)
→ จึงเลือก "แผนที่ + ตั้งชื่อเป็นระบบ" แทนการย้ายจริง เพื่อ **ไม่กระทบ workflow เลย**
