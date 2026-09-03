@echo off
setlocal
:: -----------------------------------------------------------------------------
::  run_lab_hemato.bat
::  Task Scheduler launcher - Hematocrit Alert (คาความเขมขนเลอด)
::
::  Usage:
::    run_lab_hemato.bat                          - ingest + send 7 วนลาสด (default)
::    run_lab_hemato.bat dryrun                   - ดงจาก HOSxP อยางเดยว ไมเขยน/ไมสง
::    run_lab_hemato.bat send                     - สงเฉพาะรายการทคางในคว
::    run_lab_hemato.bat start 2026-01-01         - backfill ตงแตวนทระบ
::    run_lab_hemato.bat start 2026-01-01 end 2026-08-31
::
::  เงอนไขการดงขอมล (lab_items_code + คา) แกผานหนาเวบ lab_hemato_queue_ui.php
::  ไมตองแกไฟลน
:: -----------------------------------------------------------------------------

set "PHP_EXE=C:\xampp\php\php.exe"
set "APP_DIR=C:\xampp\htdocs\Fall_Risk_Alert-main"
set "SCRIPT=%APP_DIR%\lab_hemato.php"
set "LOGDIR=%APP_DIR%\logs"
if not exist "%LOGDIR%\" mkdir "%LOGDIR%"
set "RUNLOG=%LOGDIR%\lab_hemato_task_run.log"
set "PHPERR=%LOGDIR%\lab_hemato_php_errors.log"

echo [%date% %time%] *** RUN_FROM=%USERNAME% *** >>"%RUNLOG%"
echo [%date% %time%] start args=%* >>"%RUNLOG%"
cd /d "%APP_DIR%"
"%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" -- %*  >>"%RUNLOG%" 2>&1
echo [%date% %time%] done >>"%RUNLOG%"
endlocal
