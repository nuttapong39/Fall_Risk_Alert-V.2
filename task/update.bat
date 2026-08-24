@echo off
setlocal EnableDelayedExpansion
title MedAlert - Update

:: ============================================================================
::  update.bat - Update MedAlert to the latest version from GitHub.
::
::  HOW TO USE : double-click this file. No Administrator/UAC needed - it only
::               writes inside the app folder and a sibling backup folder,
::               same as the app itself already does for secrets\/logs\.
::  WHAT IT DOES: backs up the whole app folder, then either
::                git pull (if this install has a .git folder) or
::                downloads+applies the latest ZIP from GitHub.
::                secrets\ and logs\ are never touched. Runs db_migrate.php after.
::  This is the same script the "update now" button in settings.php launches
::  (via a background process - it must NOT require a UAC prompt to work).
::  Thai usage guide: see docs/install-guide.html
:: ============================================================================

set "TASKDIR=%~dp0"

echo ============================================================
echo   MedAlert - Update
echo ============================================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%TASKDIR%update.ps1"
set "RC=%errorlevel%"

echo.
echo ============================================================
if %RC% equ 0 (
  echo   Done. See logs\update_status.json for details.
) else (
  echo   FAILED - see logs\update_status.json / logs\update_*.log
  echo   A backup was made before any changes - restore from
  echo   the folder shown in the log if needed.
)
echo ============================================================
echo.
pause
endlocal
