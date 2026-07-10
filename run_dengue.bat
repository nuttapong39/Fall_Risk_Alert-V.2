@echo off
setlocal

:: ─────────────────────────────────────────────────────────────────────────────
::  run_dengue.bat
::  Task Scheduler launcher — Dengue Alert Worker (ไข้เลือดออก)
::
::  Usage (จาก Task Scheduler หรือ command line):
::    run_dengue.bat                          — ส่ง 7 วันล่าสุด (default)
::    run_dengue.bat dryrun                   — ทดสอบ ไม่ส่งจริง
::    run_dengue.bat start 2025-06-01         — backfill ตั้งแต่วันที่ระบุ
::    run_dengue.bat start 2025-06-01 end 2025-12-31
::
::  Task Scheduler (ทุกวัน เวลา 08:00):
::    Program : C:\xampp\htdocs\Fall_Risk_Alert-main\run_dengue.bat
::    Args    : (เว้นว่าง หรือใส่ dryrun เพื่อทดสอบ)
::    Start in: C:\xampp\htdocs\Fall_Risk_Alert-main
:: ─────────────────────────────────────────────────────────────────────────────

set "PHP_EXE=C:\xampp\php\php.exe"
set "APP_DIR=C:\xampp\htdocs\Fall_Risk_Alert-main"
set "SCRIPT=%APP_DIR%\dengue_ingest.php"
set "LOGDIR=%APP_DIR%\logs"
if not exist "%LOGDIR%\" mkdir "%LOGDIR%"
set "RUNLOG=%LOGDIR%\dengue_task_run.log"
set "PHPERR=%LOGDIR%\dengue_php_errors.log"

echo [%date% %time%] *** RUN_FROM=%USERNAME% *** >>"%RUNLOG%"
echo [%date% %time%] start args=%* >>"%RUNLOG%"
cd /d "%APP_DIR%"
"%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" -- %*  >>"%RUNLOG%" 2>&1
echo [%date% %time%] done >>"%RUNLOG%"

endlocal
