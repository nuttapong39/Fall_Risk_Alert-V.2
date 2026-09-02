@echo off
setlocal EnableDelayedExpansion
title MedAlert - Update

:: ============================================================================
::  update.bat - Update MedAlert to the latest version from GitHub.
::
::  HOW TO USE : right-click -> "Run as administrator". (Double-clicking works
::               too: the script only writes inside the app folder and a sibling
::               backup folder, same as the app already does for secrets\/logs\,
::               so it does not strictly require elevation.)
::  WHAT IT DOES: backs up the whole app folder, then either
::                git pull (if this install has a .git folder) or
::                downloads+applies the latest ZIP from GitHub.
::                secrets\ and logs\ are never touched. Runs db_migrate.php after.
::  This is the same script the "update now" button in settings.php launches.
::  That button opens this window ELEVATED and VISIBLE (task\launch_detached.vbs
::  uses ShellExecute with the "runas" verb) so the operator can watch the whole
::  update run and read the result - the web page just waits for it to finish.
::  Thai usage guide: see docs/install-guide.html
:: ============================================================================

set "TASKDIR=%~dp0"

echo ============================================================
echo   MedAlert - Update
echo ============================================================
echo.

:: Some Git clients/checkouts on Windows can strip the UTF-8 BOM from update.ps1
:: during transfer, which makes PowerShell fall back to the system's ANSI code
:: page (e.g. Thai 874) to read the file -- corrupting every Thai string and
:: causing confusing parser errors ("Missing closing paren/brace/quote").
:: Self-heal by re-writing the file with a guaranteed-correct UTF-8 BOM first
:: (safe/idempotent -- a no-op if the BOM was already fine).
powershell -NoProfile -ExecutionPolicy Bypass -Command "$p='%TASKDIR%update.ps1'; [System.IO.File]::WriteAllText($p, [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8), (New-Object System.Text.UTF8Encoding($true)))"

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
