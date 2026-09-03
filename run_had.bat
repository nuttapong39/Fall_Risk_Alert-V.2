@echo off
setlocal
:: -----------------------------------------------------------------------------
::  run_had.bat
::  Task Scheduler launcher — HAD Alert (High Alert Drug)
::
::  วิธีใช้:
::    run_had.bat                          → ingest + send 7 วันล่าสุด (default)
::    run_had.bat dryrun                   → ดึงจาก HOSxP อย่างเดียว ไม่เขียน/ไม่ส่งจริง
::    run_had.bat send                     → ส่งเฉพาะรายการที่ค้างในคิว
::    run_had.bat start 2026-01-01         → backfill ตั้งแต่วันที่ระบุ
::    run_had.bat start 2026-01-01 end 2026-08-31
::
::  รหัสยา (icode) แก้ผ่านหน้าเว็บ had_queue_ui.php ไม่ต้องแก้ไฟล์นี้
:: -----------------------------------------------------------------------------

set "PHP_EXE=C:\xampp\php\php.exe"
set "APP_DIR=C:\xampp\htdocs\Fall_Risk_Alert-main"
set "SCRIPT=%APP_DIR%\HAD.php"
set "LOGDIR=%APP_DIR%\logs"
if not exist "%LOGDIR%\" mkdir "%LOGDIR%"
set "RUNLOG=%LOGDIR%\had_task_run.log"
set "PHPERR=%LOGDIR%\had_php_errors.log"

echo [%date% %time%] *** RUN_FROM=%USERNAME% *** >>"%RUNLOG%"
echo [%date% %time%] start args=%* >>"%RUNLOG%"
cd /d "%APP_DIR%"
"%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" -- %*  >>"%RUNLOG%" 2>&1
echo [%date% %time%] done >>"%RUNLOG%"
endlocal
