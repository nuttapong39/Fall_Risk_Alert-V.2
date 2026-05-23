@echo off
setlocal

:: ─────────────────────────────────────────────────────────────
::  run_drug.bat
::  Task Scheduler launcher — Drug Queue (ยาอันตราย)
::
::  วิธีใช้:
::    run_drug.bat              → ส่งรายการค้างในคิวเท่านั้น
::    run_drug.bat sync         → Sync จาก HOSxP แล้วส่ง (icode ค่า default)
::    run_drug.bat sync 1483860,2045001
::                              → Sync ระบุ icode แล้วส่ง
::    run_drug.bat dryrun       → ทดสอบโดยไม่ส่งจริง
::
::  ตั้ง Task Scheduler ให้รันทุก 15 นาที:
::    schtasks /Create /SC MINUTE /MO 15 /TN "DrugQueue_Send"
::             /TR "\"C:\xampp\htdocs\Fall_Risk_Alert-main\run_drug.bat\""
::             /RU SYSTEM /F
:: ─────────────────────────────────────────────────────────────

set "PHP_EXE=C:\xampp\php\php.exe"
set "APP_DIR=C:\xampp\htdocs\Fall_Risk_Alert-main"
set "SCRIPT=%APP_DIR%\drug_send.php"
set "LOGDIR=%APP_DIR%\logs"
if not exist "%LOGDIR%\" mkdir "%LOGDIR%"
set "RUNLOG=%LOGDIR%\drug_task_run.log"
set "PHPERR=%LOGDIR%\drug_php_errors.log"

:: ── ตัวแปรควบคุม ──────────────────────────────────────────────
::  แก้ DEFAULT_ICODES ให้ตรงกับรหัสยาอันตรายของโรงพยาบาล
::  (คั่นหลายรหัสด้วยเครื่องหมายคอมมา เช่น 1483860,2045001)
set "DEFAULT_ICODES=1483860"

::  จำนวนวันย้อนหลังสำหรับ Sync (เมื่อใช้ sync mode)
set "LOOKBACK_DAYS=30"

:: คำนวณวันที่ start (วันนี้ - LOOKBACK_DAYS) และ end (วันนี้)
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set "DT=%%I"
set "TODAY=%DT:~0,4%-%DT:~4,2%-%DT:~6,2%"

:: ─────────────────────────────────────────────────────────────
echo [%date% %time%] *** RUN_FROM=%USERNAME% ARGS=%* *** >>"%RUNLOG%"
echo [%date% %time%] start >>"%RUNLOG%"

cd /d "%APP_DIR%"

:: ── เลือก mode ตาม argument แรก ──────────────────────────────
if /i "%~1"=="dryrun" (
  echo [%date% %time%] mode=DRY-RUN >>"%RUNLOG%"
  "%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" -- --dry-run >>"%RUNLOG%" 2>&1

) else if /i "%~1"=="sync" (
  :: รับ icode จาก argument ที่ 2 หรือใช้ค่า default
  if not "%~2"=="" (
    set "USE_ICODES=%~2"
  ) else (
    set "USE_ICODES=%DEFAULT_ICODES%"
  )
  echo [%date% %time%] mode=SYNC+SEND icodes=%USE_ICODES% end=%TODAY% >>"%RUNLOG%"
  "%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" -- ^
    --with-sync ^
    --icodes="%USE_ICODES%" ^
    --end="%TODAY%" ^
    >>"%RUNLOG%" 2>&1

) else (
  :: ค่าเริ่มต้น: ส่งรายการค้างในคิวเท่านั้น (ไม่ sync)
  echo [%date% %time%] mode=SEND-ONLY >>"%RUNLOG%"
  "%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" >>"%RUNLOG%" 2>&1
)

echo [%date% %time%] done >>"%RUNLOG%"
endlocal
