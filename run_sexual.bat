@echo off
setlocal

:: ─────────────────────────────────────────────────────────────────────────────
::  run_sexual.bat
::  Task Scheduler Worker — Sexual Assault Alert Queue
::  (ผู้ถูกกระทำความรุนแรง / ข่มขืน)
::
::  Usage:
::    run_sexual.bat                           — ดึงย้อนหลัง 30 วัน (default)
::    run_sexual.bat dryrun                    — แสดงผลเฉพาะ ไม่ upsert จริง
::    run_sexual.bat send                      — ดึง + ส่ง pending queue ทันที
::    run_sexual.bat start 2025-01-01          — backfill ตั้งแต่วันที่ระบุ
::    run_sexual.bat start 2025-01-01 end 2025-12-31
::    run_sexual.bat send start 2025-06-01     — backfill + ส่งด้วย
::
::  ติดตั้ง Task Scheduler (ทุก 15 นาที, ส่งอัตโนมัติ):
::    schtasks /Create /SC MINUTE /MO 15 /TN "SexualIngest_Send" ^
::      /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_sexual.bat\" send" ^
::      /RU SYSTEM /RL HIGHEST /F
::
::  ดึงอย่างเดียว (ไม่ส่ง) ทุก 30 นาที:
::    schtasks /Create /SC MINUTE /MO 30 /TN "SexualIngest_Sync" ^
::      /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_sexual.bat\"" ^
::      /RU SYSTEM /RL HIGHEST /F
::
::  Log files:
::    logs\sexual_task_run.log   — output + ingest summary
::    logs\sexual_php_errors.log — PHP error_log
:: ─────────────────────────────────────────────────────────────────────────────

set "PHP_EXE=C:\xampp\php\php.exe"
set "APP_DIR=C:\xampp\htdocs\Fall_Risk_Alert-main"
set "SCRIPT=%APP_DIR%\sexual_ingest.php"
set "LOGDIR=%APP_DIR%\logs"
if not exist "%LOGDIR%\" mkdir "%LOGDIR%"
set "RUNLOG=%LOGDIR%\sexual_task_run.log"
set "PHPERR=%LOGDIR%\sexual_php_errors.log"

echo [%date% %time%] *** START run_sexual.bat  RUN_FROM=%USERNAME%  ARGS=%* *** >>"%RUNLOG%"

:: ── Validate PHP ────────────────────────────────────────────────────────────
if not exist "%PHP_EXE%" (
  echo [%date% %time%] ERROR: PHP not found: %PHP_EXE% >>"%RUNLOG%"
  echo ERROR: PHP not found: %PHP_EXE%
  exit /b 1
)

:: ── Validate worker script ──────────────────────────────────────────────────
if not exist "%SCRIPT%" (
  echo [%date% %time%] ERROR: Script not found: %SCRIPT% >>"%RUNLOG%"
  echo ERROR: Script not found: %SCRIPT%
  exit /b 1
)

:: ── Run PHP worker ──────────────────────────────────────────────────────────
cd /d "%APP_DIR%"
echo [%date% %time%] Running: php sexual_ingest.php %* >>"%RUNLOG%"
"%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" -- %* >>"%RUNLOG%" 2>&1
set EXIT_CODE=%ERRORLEVEL%

echo [%date% %time%] exit_code=%EXIT_CODE% >>"%RUNLOG%"
echo [%date% %time%] *** DONE run_sexual.bat *** >>"%RUNLOG%"

exit /b %EXIT_CODE%
