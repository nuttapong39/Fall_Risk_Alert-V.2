@echo off
setlocal

:: ─────────────────────────────────────────────────────────────────────────────
::  run_patient.bat
::  Task Scheduler launcher — Patient Ingest Worker (จิตเวช / ทำร้ายตัวเอง)
::
::  วิธีใช้:
::    run_patient.bat              → Ingest + ส่งข้อมูล 7 วันล่าสุด (default)
::    run_patient.bat dryrun       → ทดสอบโดยไม่ส่งจริง
::    run_patient.bat start 2025-06-01
::                                → Backfill ตั้งแต่วันที่ระบุ ถึงวันนี้
::    run_patient.bat start 2025-06-01 end 2026-05-26
::                                → Backfill ช่วงวันที่กำหนด
::
::  ตั้ง Task Scheduler ให้รันทุก 15 นาที:
::    schtasks /Create /SC MINUTE /MO 15 /TN "PatientIngest_Send"
::             /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_patient.bat\""
::             /RU SYSTEM /F
::
::  ICD-10 ที่ดักจับ: T71, X60–X69, X70, X84 (ฆ่าตัวตาย/ทำร้ายตัวเอง)
:: ─────────────────────────────────────────────────────────────────────────────

set "PHP_EXE=C:\xampp\php\php.exe"
set "APP_DIR=C:\xampp\htdocs\Fall_Risk_Alert-main"
set "SCRIPT=%APP_DIR%\patient_ingest.php"
set "LOGDIR=%APP_DIR%\logs"
if not exist "%LOGDIR%\" mkdir "%LOGDIR%"
set "RUNLOG=%LOGDIR%\patient_task_run.log"
set "PHPERR=%LOGDIR%\patient_php_errors.log"

:: ── คำนวณวันที่วันนี้ ─────────────────────────────────────────────────────────
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set "DT=%%I"
set "TODAY=%DT:~0,4%-%DT:~4,2%-%DT:~6,2%"

:: ─────────────────────────────────────────────────────────────────────────────
echo [%date% %time%] *** RUN_FROM=%USERNAME% ARGS=%* *** >>"%RUNLOG%"
echo [%date% %time%] start >>"%RUNLOG%"

cd /d "%APP_DIR%"

:: ── เลือก mode ตาม arguments ─────────────────────────────────────────────────
if /i "%~1"=="dryrun" (
  echo [%date% %time%] mode=DRY-RUN >>"%RUNLOG%"
  "%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" -- --dry-run >>"%RUNLOG%" 2>&1

) else if /i "%~1"=="start" (
  :: run_patient.bat start YYYY-MM-DD [end YYYY-MM-DD]
  set "USE_START=%~2"
  if /i "%~3"=="end" (
    set "USE_END=%~4"
  ) else (
    set "USE_END=%TODAY%"
  )
  echo [%date% %time%] mode=BACKFILL start=%USE_START% end=%USE_END% >>"%RUNLOG%"
  "%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" -- ^
    --start="%USE_START%" ^
    --end="%USE_END%" ^
    >>"%RUNLOG%" 2>&1

) else (
  :: ค่าเริ่มต้น: Ingest + ส่ง 7 วันล่าสุด
  echo [%date% %time%] mode=DEFAULT (7 days lookback) >>"%RUNLOG%"
  "%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" >>"%RUNLOG%" 2>&1
)

echo [%date% %time%] done >>"%RUNLOG%"
endlocal
