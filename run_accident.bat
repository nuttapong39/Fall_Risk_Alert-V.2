@echo off
setlocal
set "PHP_EXE=C:\xampp\php\php.exe"
set "APP_DIR=C:\xampp\htdocs\HOSxLine"
set "SCRIPT=%APP_DIR%\accident.php"
set "LOGDIR=%APP_DIR%\logs"
if not exist "%LOGDIR%" mkdir "%LOGDIR%"
set "RUNLOG=%LOGDIR%\accident_task_run.log"
set "PHPERR=%LOGDIR%\accident_php_errors.log"

echo [%date% %time%] *** RUN_FROM=%USERNAME% *** >>"%RUNLOG%"
echo [%date% %time%] start>>"%RUNLOG%"
cd /d "%APP_DIR%"
"%PHP_EXE%" -d log_errors=On -d error_log="%PHPERR%" -f "%SCRIPT%" >>"%RUNLOG%" 2>&1
echo [%date% %time%] done>>"%RUNLOG%"
endlocal
