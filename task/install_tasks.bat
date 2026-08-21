@echo off
setlocal EnableDelayedExpansion
title MedAlert - Install Scheduled Tasks

:: ============================================================================
::  install_tasks.bat - Bulk-import ALL MedAlert scheduled tasks in one click.
::
::  HOW TO USE : double-click this file (it auto-elevates to Administrator).
::  REQUIRES   : project installed at  C:\xampp\htdocs\Fall_Risk_Alert-main
::               all .xml files must sit in the same folder as this .bat (task\)
::  Tasks run as SYSTEM (S-1-5-18). Thai usage guide: see README.txt
:: ============================================================================

set "TASKDIR=%~dp0"

:: --- Self-elevate: creating scheduled tasks requires Administrator ---
net session >nul 2>&1
if %errorlevel% neq 0 (
  echo Requesting Administrator rights ^(UAC^)...
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)

echo ============================================================
echo   MedAlert - Installing all Scheduled Tasks
echo   Folder: %TASKDIR%
echo ============================================================
echo.

set /a OK=0, FAIL=0
for %%F in ("%TASKDIR%*.xml") do (
  echo [.] Installing: %%~nF
  schtasks /Create /TN "%%~nF" /XML "%%~fF" /F >nul 2>&1
  if !errorlevel! equ 0 (
    echo     ^> OK
    set /a OK+=1
  ) else (
    echo     ^> FAILED ^(errorlevel !errorlevel!^)
    set /a FAIL+=1
  )
)

echo.
echo ============================================================
echo   Done: !OK! installed, !FAIL! failed
echo ============================================================
echo.
echo   Verify in Task Scheduler ^(taskschd.msc^)
echo.
pause
endlocal
