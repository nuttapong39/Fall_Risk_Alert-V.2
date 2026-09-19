@echo off
setlocal
:: -----------------------------------------------------------------------------
::  run_drugs_alert.bat
::  Task Scheduler launcher - Drug Alert (ยาเฝ้าระวังที่เภสัชเลือกเอง)
::
::  Usage:
::    run_drugs_alert.bat                          - ingest + send เมื่อวาน+วันนี้ (default)
::    run_drugs_alert.bat dryrun                    - ดึงจาก HOSxP อย่างเดียว ไม่เขียน/ไม่ส่ง
::    run_drugs_alert.bat send                      - ส่งเฉพาะรายการที่ค้างในคิว
::    run_drugs_alert.bat mode=backfill start=2026-01-01 end=2026-08-31
::                                                    - backfill ประวัติ (เก็บเป็น Sent ไม่ยิง LINE)
::
::  รายการยาที่เฝ้าระวัง (icode) แก้ผ่านหน้าเว็บ drugs_alert.php การ์ด
::  "รายการยาที่เฝ้าระวัง" หรือปุ่ม แก้ไขเงื่อนไขดึงข้อมูล — ไม่ต้องแก้ไฟล์นี้
:: -----------------------------------------------------------------------------

set "PHP_EXE=C:\xampp\php\php.exe"
set "APP_DIR=C:\xampp\htdocs\Fall_Risk_Alert-main"
set "SCRIPT=%APP_DIR%\drugs_alert_worker.php"
set "LOGDIR=%APP_DIR%\logs"
if not exist "%LOGDIR%\" mkdir "%LOGDIR%"
set "RUNLOG=%LOGDIR%\drugs_alert_task_run.log"
set "PHPERR=%LOGDIR%\drugs_alert_php_errors.log"

echo [%date% %time%] *** RUN_FROM=%USERNAME% *** >>"%RUNLOG%"
echo [%date% %time%] start args=%* >>"%RUNLOG%"
cd /d "%APP_DIR%"
"%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" -- %*  >>"%RUNLOG%" 2>&1
echo [%date% %time%] done >>"%RUNLOG%"
endlocal
