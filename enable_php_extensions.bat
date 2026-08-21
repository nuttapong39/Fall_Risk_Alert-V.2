@echo off
setlocal EnableDelayedExpansion
title MedAlert - Enable PHP Extensions

:: ============================================================================
::  enable_php_extensions.bat
::  Auto-enable the PHP extensions MedAlert needs, in one double-click.
::  Uncomments in php.ini:  curl  mbstring  pdo_mysql  pdo_pgsql  pgsql
::  Steps: back up php.ini -> uncomment -> restart Apache (auto, with fallback).
::  Auto-elevates to Administrator (UAC). Assumes XAMPP at C:\xampp.
::  Thai guide: README.md / docs/install-guide.html
:: ============================================================================

set "PHPINI=C:\xampp\php\php.ini"
set "HTTPD=C:\xampp\apache\bin\httpd.exe"

:: --- Self-elevate: editing php.ini / restarting Apache may need Administrator ---
net session >nul 2>&1
if %errorlevel% neq 0 (
  echo Requesting Administrator rights ^(UAC^)...
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)

echo ============================================================
echo   MedAlert - Enable PHP Extensions
echo   File: %PHPINI%
echo ============================================================
echo.

if not exist "%PHPINI%" (
  echo [X] php.ini NOT found at %PHPINI%
  echo     Check that XAMPP is installed at C:\xampp
  echo.
  pause
  exit /b 1
)

:: --- Backup first (restore point) ---
copy /Y "%PHPINI%" "%PHPINI%.bak" >nul
echo [i] Backup saved: php.ini.bak

:: --- Uncomment the 5 extensions (literal replace ';extension=x' -> 'extension=x') ---
powershell -NoProfile -Command "$p='%PHPINI%'; $c=Get-Content -LiteralPath $p -Raw; foreach($e in 'curl','mbstring','pdo_mysql','pdo_pgsql','pgsql'){ $c=$c.Replace(';extension='+$e, 'extension='+$e) }; Set-Content -LiteralPath $p -Value $c -NoNewline -Encoding Default"

echo.
echo [i] Extension status after edit:
for %%E in (curl mbstring pdo_mysql pdo_pgsql pgsql) do (
  findstr /B /C:"extension=%%E" "%PHPINI%" >nul 2>&1
  if !errorlevel! equ 0 (echo     [ ON ] %%E) else (echo     [ -- ] %%E)
)

:: --- Restart Apache (auto attempt + fallback message) ---
echo.
echo [i] Restarting Apache...
if exist "%HTTPD%" (
  "%HTTPD%" -k restart >nul 2>&1
  if !errorlevel! equ 0 (
    echo     ^> Apache restarted
  ) else (
    echo     ^> Auto-restart failed - please Stop then Start Apache
    echo       in the XAMPP Control Panel manually.
  )
) else (
  echo     ^> httpd.exe not found - please Stop/Start Apache in XAMPP manually.
)

echo.
echo ============================================================
echo   Done. Extensions enabled.
echo ============================================================
echo.
pause
endlocal
