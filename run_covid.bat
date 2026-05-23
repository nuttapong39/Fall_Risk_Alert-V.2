@echo off
setlocal
set "PHP_EXE=C:\xampp\php\php.exe"
set "APP_DIR=C:\xampp\htdocs\Fall_Risk_Alert   "
set "SCRIPT=%APP_DIR%\covid.php"
set "LOGDIR=%APP_DIR%\logs"
if not exist "%LOGDIR%" mkdir "%LOGDIR%"
set "RUNLOG=%LOGDIR%\covid_task_run.log"
set "PHPERR=%LOGDIR%\covid_php_errors.log"

echo [%date% %time%] start>>"%RUNLOG%"
cd /d "%APP_DIR%"
"%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" >>"%RUNLOG%" 2>&1
echo [%date% %time%] done>>"%RUNLOG%"
endlocal
