# MedAlert

ระบบแจ้งเตือนทางการแพทย์สำหรับ รพ.เชียงกลาง ดึงข้อมูลผู้ป่วยจาก HOSxP แล้วยิง LINE Flex Message ผ่าน MOPH Alert API มี 10 โมดูลแจ้งเตือนตามกลุ่มโรค/สิทธิ์ผู้ป่วย

## Language

**MedAlert**:
ชื่อระบบแจ้งเตือนทางการแพทย์นี้โดยรวม ครอบคลุมทุกโมดูล เริ่มต้นจาก Fracture Alert Module เป็นโมดูลแรก
_Avoid_: Fall Risk Alert, HR-CENTER, HR-CENTER 4.0

**Alert Module**:
หน่วยแจ้งเตือนอิสระหนึ่งกลุ่มโรค/สิทธิ์ผู้ป่วย (เช่น Fracture, Accident, COVID) รับผิดชอบ Ingest + Send cycle ของตัวเอง
_Avoid_: alert, worker, โมดูล

**Ingest**:
ขั้นตอนแรกของ Alert Module — ดึงข้อมูลผู้ป่วยจาก HOSxP แล้วเก็บลง Queue
_Avoid_: import, sync, fetch

**Send**:
ขั้นตอนที่สองของ Alert Module — อ่านรายการจาก Queue แล้วยิง Flex Message ไป MOPH Alert
_Avoid_: deliver, notify, dispatch

**Queue**:
ตาราง database ที่พักข้อมูลผู้ป่วยระหว่าง Ingest กับ Send แต่ละ Alert Module มี Queue ของตัวเอง (เช่น fracture_queue, accident_queue)
_Avoid_: ตารางรอส่ง, buffer, staging table

**Worker**:
ไฟล์ PHP ที่รัน Ingest + Send ครบวงจรในไฟล์เดียว ถูกเรียกผ่าน .bat และ Task Scheduler โดยอัตโนมัติ
_Avoid_: script, runner, bot

**HOSxP**:
ระบบ HIS (Hospital Information System) ของ รพ.เชียงกลาง เป็นแหล่งข้อมูลผู้ป่วยต้นทางที่ MedAlert ดึงข้อมูลในขั้นตอน Ingest MedAlert มองเป็นแหล่งข้อมูลฝั่งอ่านอย่างเดียว ไม่เขียนกลับ
_Avoid_: HIS, ฐานข้อมูลโรงพยาบาล, database โรงพยาบาล

**MedAlert_DB**:
ฐานข้อมูลของระบบ MedAlert เอง แยกก้อนออกจาก HOSxP เก็บเฉพาะตารางที่ระบบสร้างและเขียนเอง ได้แก่ users และ Queue ทั้งหมด เป็น datastore เดียวที่ MedAlert มีสิทธิ์เขียน (อ่าน-เขียน) ส่วน HOSxP เป็นแหล่งข้อมูลอ่านอย่างเดียว
_Avoid_: System Store, App DB, ฐานข้อมูลระบบ, local DB

**MOPHAlert**:
บริการ API ของกระทรวงสาธารณสุขที่รับ Flex Message แล้วส่งต่อผ่าน LINE ไปยังกลุ่มเป้าหมาย เป็นช่องทาง Send เดียวของ MedAlert
_Avoid_: MOPH Alert, LINE Notify, MOPH API, LINE API

**Flex Message**:
รูปแบบข้อความ LINE ที่ MedAlert ใช้แจ้งเตือน สร้างจาก flex_*.php library ของแต่ละ Alert Module
_Avoid_: การ์ดแจ้งเตือน, LINE message, notification card

**Flex Theme**:
อัตลักษณ์ภาพประจำ Alert Module หนึ่งของ Flex Message — สี gradient หัวการ์ด, สี accent, ข้อความหัว/ป้ายความด่วน, footer และ icon watermark ที่เจ้าหน้าที่แก้เองได้ผ่านหน้า "ปรับแต่ง Flex Message" โดยไม่แตะโค้ด (เก็บใน secrets/flex_themes.json)
_Avoid_: สกิน, template, สีการ์ด

**Token Key**:
คู่ Client Key + Secret Key ที่ใช้ authenticate กับ MOPHAlert แต่ละ Alert Module มี Token Key แยกของตัวเองเก็บใน secrets/moph_keys.json หากโมดูลใดไม่มี Token Key กำหนดไว้จะ fallback ไปใช้ Default Key แทน
_Avoid_: API Key, credential, MOPH Key, Client Key, Secret Key

**Default Key**:
Token Key สำรองที่ใช้เมื่อ Alert Module ไม่มี Token Key เป็นของตัวเอง กำหนดไว้ใน secrets/moph_keys.json ใต้ key "default"
_Avoid_: fallback key, master key, global key

**Resend**:
การส่ง Flex Message ซ้ำสำหรับ Queue item ที่ยังไม่สำเร็จ มี Cooldown และจำนวนครั้งสูงสุดที่กำหนดต่อ Alert Module
_Avoid_: retry, re-notify, re-send

**Alert Group**:
กลุ่ม LINE ที่สร้างขึ้นสำหรับรับการแจ้งเตือนของ Alert Module หนึ่ง ๆ โดยเฉพาะ แต่ละ Alert Group ผูกกับ Token Key ของตัวเอง
_Avoid_: ผู้รับ, recipient, LINE channel, subscriber

**Cron**:
กลไกรัน Worker อัตโนมัติตามเวลาที่กำหนด ใช้งานผ่าน Windows Task Scheduler + .bat file
_Avoid_: Task Scheduler, scheduler, cronjob, automation

**Pending**:
สถานะ Queue item ที่ยังไม่ได้ส่ง (status=0) รอ Worker มาประมวลผลในรอบถัดไป
_Avoid_: รอส่ง, ใหม่, queued, unprocessed

**Sent**:
สถานะ Queue item ที่ส่ง Flex Message ไป MOPHAlert สำเร็จแล้ว (status=1)
_Avoid_: ส่งแล้ว, done, completed, delivered

**Condition**:
เกณฑ์คัดกรองผู้ป่วยในขั้นตอน Ingest ของแต่ละ Alert Module เช่น ICD-10 code, ประเภทสิทธิ์ผู้ป่วย (pttype), หรือ lab item code
_Avoid_: criteria, filter, เงื่อนไขคัดกรอง, rule

**Source Query**:
คำสั่ง SQL SELECT ที่ implement การอ่านข้อมูลจาก HOSxP ในขั้น Ingest ของ Alert Module หนึ่ง เป็นตัวแทนของ Condition ในรูป SQL มี provider เดียวต่อ module (รวมศูนย์ ไม่ copy ซ้ำหลายไฟล์) และเลือกภาษา SQL ตาม engine ของ HOSxP ที่เชื่อมอยู่ (MySQL V3 หรือ PostgreSQL XE4)
_Avoid_: ingest query, select query, HOSxP query, source select

**Test**:
Mode รัน Worker โดยแสดงผลเท่านั้น ไม่บันทึก Queue และไม่ส่ง Flex Message จริง (--dry-run flag)
_Avoid_: dry run, preview, simulate, ทดสอบ

**Queue UI**:
หน้าเว็บสำหรับดูและจัดการ Queue ของแต่ละ Alert Module ประกอบด้วย *_queue_ui.php (แสดงผล) และ *_queue_action.php (รับ action จากผู้ใช้)
_Avoid_: Dashboard, หน้าจัดการ, admin panel

**Date Range**:
ช่วงวันที่ที่ใช้ดึงข้อมูลผู้ป่วยจาก HOSxP ในขั้นตอน Ingest กำหนดเป็น start/end date หรือจำนวนวันย้อนหลัง
_Avoid_: lookback, lookback period, ช่วงเวลาย้อนหลัง

**Cooldown**:
ระยะเวลาขั้นต่ำที่ต้องรอระหว่าง Resend แต่ละครั้งของ Queue item เดียวกัน กำหนดเป็นนาที
_Avoid_: interval, delay, wait time, throttle

**Fracture**:
Alert Module แรกของ MedAlert แจ้งเตือนผู้ป่วยอายุ ≥60 ที่มี ICD-10 W-codes หรือ S-codes (หกล้มกระดูกหัก) คำนี้ใช้แทน "Fall Risk" ได้เลย
_Avoid_: Fall Risk, Fall Risk Alert

## Alert Modules

10 โมดูลแจ้งเตือนของ MedAlert แต่ละโมดูลมีชื่อในโค้ดและชื่อที่ใช้เรียกในทีม:

| ชื่อในโค้ด | ชื่อที่ใช้เรียก | กลุ่มผู้ป่วย |
|---|---|---|
| `fracture` | Fracture | ผู้ป่วยอายุ ≥60 หกล้ม/กระดูกหัก (W-codes, S-codes) |
| `accident` | พ.ร.บ. | ผู้ป่วยสิทธิ์ พ.ร.บ./ประกันสังคมต่างจังหวัด (pttype 33/35/36/39) |
| `covid` | COVID | ผู้ป่วย COVID-19 |
| `drug` | เสี่ยงกินยาอันตราย | ผู้ป่วยที่ได้รับยากลุ่มเสี่ยง |
| `pharm_lab` | LabAlert | ผลแล็บที่ต้องแจ้งเตือนเภสัชกร |
| `dengue` | ไข้เลือดออก | ผู้ป่วยไข้เลือดออก |
| `lepto` | Lepto | ผู้ป่วย Leptospirosis |
| `scrub` | Scrub | ผู้ป่วย Scrub Typhus |
| `sexual` | ถูกล่วงละเมิด | ผู้ป่วยถูกล่วงละเมิดทางเพศ |
| `patient` | จิตเวช | ผู้ป่วยจิตเวชและกลุ่มอื่น ๆ |
