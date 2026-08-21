@echo off
setlocal EnableDelayedExpansion
title MedAlert - Remove Scheduled Tasks

:: ============================================================================
::  uninstall_tasks.bat - Bulk-remove ALL MedAlert scheduled tasks in one click.
::  HOW TO USE : double-click (it auto-elevates to Administrator).
::  Deletes tasks named after each .xml file in this folder. See README.txt.
:: ============================================================================

set "TASKDIR=%~dp0"

net session >nul 2>&1
if %errorlevel% neq 0 (
  echo Requesting Administrator rights ^(UAC^)...
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)

echo ============================================================
echo   MedAlert - Removing all Scheduled Tasks
echo ============================================================
echo.

set /a OK=0, MISS=0
for %%F in ("%TASKDIR%*.xml") do (
  echo [.] Removing: %%~nF
  schtasks /Delete /TN "%%~nF" /F >nul 2>&1
  if !errorlevel! equ 0 (
    echo     ^> removed
    set /a OK+=1
  ) else (
    echo     ^> not found / skipped
    set /a MISS+=1
  )
)

echo.
echo ============================================================
echo   Done: !OK! removed, !MISS! skipped
echo ============================================================
echo.
pause
endlocal
