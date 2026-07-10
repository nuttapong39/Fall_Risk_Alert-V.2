@echo off
setlocal

:: ─────────────────────────────────────────────────────────────────────────────
::  run_lepto.bat
::  Task Scheduler placeholder — Lepto Alert (เลปโตสไปโรซิส)
::
::  ⚠ หมายเหตุ: โมดูล Lepto ทำงานแบบ "ส่งตามรายเคส" ผ่าน lepto_action.php
::    ยังไม่มี worker สำหรับ Automation batch-send อัตโนมัติ
::    (lepto_action.php เป็น AJAX POST handler รับ vn ทีละรายการเท่านั้น)
::
::  ส่งแจ้งเตือนได้จากหน้าเว็บ:
::    http://localhost/Fall_Risk_Alert-main/leptospira.php
::    (หรือหน้าเว็บ Lepto UI ที่ใช้งาน)
::
::  เมื่อมี worker script (lepto_ingest.php) ให้แก้ SCRIPT ด้านล่างแล้ว
::  ลบบล็อก "goto :eof" ออก เพื่อเปิดใช้งาน
:: ─────────────────────────────────────────────────────────────────────────────

set "PHP_EXE=C:\xampp\php\php.exe"
set "APP_DIR=C:\xampp\htdocs\Fall_Risk_Alert-main"
set "LOGDIR=%APP_DIR%\logs"
if not exist "%LOGDIR%\" mkdir "%LOGDIR%"
set "RUNLOG=%LOGDIR%\lepto_task_run.log"
set "PHPERR=%LOGDIR%\lepto_php_errors.log"

echo [%date% %time%] *** RUN_FROM=%USERNAME% *** >>"%RUNLOG%"
echo [%date% %time%] INFO: Lepto module is web-UI only — automated batch not yet implemented >>"%RUNLOG%"
echo [%date% %time%] INFO: Use browser: http://localhost/Fall_Risk_Alert-main/leptospira.php >>"%RUNLOG%"
echo [%date% %time%] done (no-op) >>"%RUNLOG%"

:: ── ลบ 2 บรรทัดด้านล่างนี้เมื่อมี worker script พร้อมใช้งาน ─────────────────
goto :eof

:: ── Future worker (แก้ SCRIPT ให้ตรง) ───────────────────────────────────────
:: set "SCRIPT=%APP_DIR%\lepto_ingest.php"
:: cd /d "%APP_DIR%"
:: echo [%date% %time%] start >>"%RUNLOG%"
:: "%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" >>"%RUNLOG%" 2>&1
:: echo [%date% %time%] done >>"%RUNLOG%"

:eof
endlocal
