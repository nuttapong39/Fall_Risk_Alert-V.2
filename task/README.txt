======================================================================
 MedAlert — ชุดติดตั้ง Scheduled Tasks (สำหรับ รพ.ที่นำ project ไปใช้)
======================================================================

โฟลเดอร์ task\ นี้เก็บไฟล์ export ของ Windows Task Scheduler ทั้ง 10 module
พร้อมสคริปต์ติดตั้ง/ถอนแบบคลิกเดียว ไม่ต้อง import ทีละไฟล์

----------------------------------------------------------------------
เงื่อนไขก่อนติดตั้ง
----------------------------------------------------------------------
1. ติดตั้ง project ไว้ที่ path มาตรฐาน:
      C:\xampp\htdocs\Fall_Risk_Alert-main
   (ไฟล์ .xml อ้างอิง path นี้แบบตายตัว — หากวางที่อื่น task จะชี้ผิดที่)
2. ต้องเป็นเครื่อง Windows และมีสิทธิ์ Administrator

----------------------------------------------------------------------
วิธีติดตั้ง (import ทั้ง 10 task ในคลิกเดียว)
----------------------------------------------------------------------
  ดับเบิลคลิก  install_tasks.bat
  -> กด "Yes" ที่หน้าต่าง UAC (ขอสิทธิ์ Administrator อัตโนมัติ)
  -> สคริปต์จะ import ไฟล์ .xml ทุกตัวในโฟลเดอร์นี้ให้เอง
  -> ตรวจผลได้ที่ Task Scheduler (เปิดด้วยคำสั่ง taskschd.msc)

วิธีถอนติดตั้ง (ลบทั้ง 10 task)
  ดับเบิลคลิก  uninstall_tasks.bat  -> กด Yes ที่ UAC

----------------------------------------------------------------------
รายการ task (รันด้วยสิทธิ์ SYSTEM ทุก 5 นาที)
----------------------------------------------------------------------
  Accident_Auto Sender          -> run_accident.bat
  Covide_Auto Sender            -> run_covid.bat
  Dengue_Auto Sender            -> run_dengue.bat
  Drug_Sender                   -> run_drug.bat
  Fall_Risk_Alert_Auto Sender   -> run_fracture.bat
  Lepto_Auto Sender             -> run_lepto.bat
  MedAlert_PharmLab             -> run_pharm_lab.bat
  Patient_Alert_Auto Sender     -> run_patient.bat
  Scrub_Auto Sender             -> run_scrub.bat
  Sexual_Auto Sender            -> run_sexual.bat

----------------------------------------------------------------------
หมายเหตุสำคัญ
----------------------------------------------------------------------
- ตัว task เป็นแค่ "ตัวตั้งเวลา" ให้รัน worker — ระบบจะส่งแจ้งเตือนได้จริง
  ต่อเมื่อตั้งค่า MOPH client/secret key ของแต่ละ module แล้ว
  (ทำที่หน้า moph_keys_admin.php ในระบบ)
- ตั้ง database ที่หน้า db_config_admin.php ให้เรียบร้อยก่อน
- อยากปรับเวลา/ความถี่: แก้ได้ใน Task Scheduler หลัง import
