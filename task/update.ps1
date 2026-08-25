<#
  update.ps1
  ตัวทำงานจริงของระบบอัปเดตเวอร์ชัน MedAlert (เรียกผ่าน update.bat หรือจากปุ่มในเว็บ)

  ขั้นตอน:
    1) สำรองทั้งโฟลเดอร์ไป ..\_backup\ ก่อนแตะอะไร
    2) ถ้ามี .git (และมี git.exe ใช้ได้) -> git fetch + git reset --hard origin/master
       ถ้าไม่มี -> ดาวน์โหลด ZIP จาก GitHub มาทับ (robocopy, ไม่แตะ secrets/ กับ logs/)
    3) รัน db_migrate.php (deploy ตาราง queue ใหม่ถ้ามี)
    4) เขียนผลลง logs\update_status.json (ให้หน้าเว็บ poll) + logs\update_<timestamp>.log

  ปลอดภัยกับ secrets/*.json และ logs/ เสมอ (ไม่ถูกเขียนทับไม่ว่าทางไหน)
#>

$ErrorActionPreference = 'Stop'

$AppDir  = Split-Path -Parent $PSScriptRoot        # .../task/update.ps1 -> .../ (app root)
$LogDir  = Join-Path $AppDir 'logs'
if (!(Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
$Stamp      = Get-Date -Format 'yyyyMMdd_HHmmss'
$LogFile    = Join-Path $LogDir "update_$Stamp.log"
$StatusFile = Join-Path $LogDir 'update_status.json'

$script:LastStep = 0
function Set-Status([string]$status, [string]$message, [int]$step = 0) {
  if ($step -gt 0) { $script:LastStep = $step }
  $obj = [ordered]@{ status = $status; message = $message; step = $script:LastStep; totalSteps = 5; updatedAt = (Get-Date -Format 's') }
  ($obj | ConvertTo-Json -Compress) | Set-Content -LiteralPath $StatusFile -Encoding UTF8
  $line = "[$($obj.updatedAt)] [$status] $message"
  Add-Content -LiteralPath $LogFile -Value $line -Encoding UTF8
  Write-Host $line
}

try {
  Set-Status 'running' "เริ่มอัปเดต (โฟลเดอร์: $AppDir)" 1

  # ── 1) สำรองก่อนเสมอ ─────────────────────────────────────────────────────
  Set-Status 'running' 'กำลังสำรองข้อมูล...' 1
  $BackupRoot = Join-Path (Split-Path -Parent $AppDir) '_backup'
  $BackupDir  = Join-Path $BackupRoot "$(Split-Path -Leaf $AppDir)_$Stamp"
  if (!(Test-Path $BackupRoot)) { New-Item -ItemType Directory -Path $BackupRoot -Force | Out-Null }
  robocopy $AppDir $BackupDir /E /XD ".git" /NFL /NDL /NJH /NJS /NC /NS | Out-Null
  if ($LASTEXITCODE -ge 8) { throw "สำรองข้อมูลล้มเหลว (robocopy code $LASTEXITCODE)" }
  Set-Status 'running' "สำรองแล้วที่ $BackupDir" 2

  # ── 2) อัปเดตไฟล์โค้ด: git ถ้ามี, ไม่งั้นดาวน์โหลด ZIP ────────────────────
  $GitDir   = Join-Path $AppDir '.git'
  $GitTool  = Get-Command git -ErrorAction SilentlyContinue
  $UseGit   = (Test-Path $GitDir) -and $GitTool

  if ($UseGit) {
    Set-Status 'running' 'พบ .git -> git fetch + reset --hard origin/master' 2
    Push-Location $AppDir
    # git เขียน progress ปกติลง stderr เสมอ (ไม่ใช่ error) — ต้องผ่อน EAP ชั่วคราว
    # ตอนจับ 2>&1 ไม่งั้น PowerShell 5.1 จะโยน exception จาก progress line เอง
    $prevEap = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
      git fetch origin 2>&1 | ForEach-Object { Add-Content -LiteralPath $LogFile -Value $_ }
      $fetchExit = $LASTEXITCODE
      if ($fetchExit -ne 0) { throw "git fetch ล้มเหลว (code $fetchExit)" }
      git reset --hard origin/master 2>&1 | ForEach-Object { Add-Content -LiteralPath $LogFile -Value $_ }
      $resetExit = $LASTEXITCODE
      if ($resetExit -ne 0) { throw "git reset ล้มเหลว (code $resetExit)" }
    } finally { $ErrorActionPreference = $prevEap; Pop-Location }
  } else {
    Set-Status 'running' 'ไม่พบ .git -> ดาวน์โหลด ZIP จาก GitHub' 2
    $ZipUrl     = 'https://github.com/nuttapong39/Fall_Risk_Alert-V.2/archive/refs/heads/master.zip'
    $TmpZip     = Join-Path $env:TEMP "medalert_update_$Stamp.zip"
    $TmpExtract = Join-Path $env:TEMP "medalert_update_extract_$Stamp"
    Invoke-WebRequest -Uri $ZipUrl -OutFile $TmpZip -UseBasicParsing
    Expand-Archive -Path $TmpZip -DestinationPath $TmpExtract -Force
    $SrcRoot = Get-ChildItem -Path $TmpExtract -Directory | Select-Object -First 1
    if (!$SrcRoot) { throw 'แตกไฟล์ ZIP แล้วไม่พบโฟลเดอร์โค้ด' }

    Set-Status 'running' 'กำลังคัดลอกไฟล์ทับ (ยกเว้น secrets/ และ logs/)' 3
    robocopy $SrcRoot.FullName $AppDir /E /XD secrets logs .git /NFL /NDL /NJH /NJS /NC /NS | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "คัดลอกไฟล์ล้มเหลว (robocopy code $LASTEXITCODE)" }

    Remove-Item -LiteralPath $TmpZip -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $TmpExtract -Recurse -Force -ErrorAction SilentlyContinue
  }

  # ── 3) Migration: deploy ตาราง queue ที่อาจเพิ่มมาใหม่ ───────────────────
  Set-Status 'running' 'กำลังตรวจ/สร้างตาราง DB ที่ขาด (migration)...' 4
  $PhpExe = 'C:\xampp\php\php.exe'
  if (!(Test-Path $PhpExe)) {
    $PhpCmd = Get-Command php -ErrorAction SilentlyContinue
    $PhpExe = if ($PhpCmd) { $PhpCmd.Source } else { $null }
  }
  if ($PhpExe) {
    $migrateOut = & $PhpExe (Join-Path $AppDir 'db_migrate.php') 2>&1
    $migrateOut | ForEach-Object { Add-Content -LiteralPath $LogFile -Value $_ }
  } else {
    Add-Content -LiteralPath $LogFile -Value 'ไม่พบ php.exe — ข้าม migration (รัน db_migrate.php เองภายหลังได้)'
  }

  # ── 4) จบ ─────────────────────────────────────────────────────────────
  $NewVersion = 'ไม่ทราบ'
  $VerFile = Join-Path $AppDir 'VERSION'
  if (Test-Path $VerFile) { $NewVersion = (Get-Content -LiteralPath $VerFile -Raw).Trim() }

  # ── 5) แจ้งเตือน "อัปเดตสำเร็จ" ผ่าน LINE/Telegram — best-effort เสมอ ──────
  # (อัปเดตไฟล์เสร็จสมบูรณ์ไปแล้วตอนนี้ ถ้าแจ้งเตือนพลาดไม่ควรทำให้ดูเหมือนอัปเดตล้มเหลว)
  if ($PhpExe) {
    try {
      $notifyOut = & $PhpExe (Join-Path $AppDir 'send_update_notification.php') $NewVersion 2>&1
      $notifyOut | ForEach-Object { Add-Content -LiteralPath $LogFile -Value $_ }
    } catch {
      Add-Content -LiteralPath $LogFile -Value "แจ้งเตือนอัปเดตสำเร็จล้มเหลว (ไม่กระทบผลอัปเดต): $($_.Exception.Message)"
    }
  }

  Set-Status 'done' "อัปเดตสำเร็จ — เวอร์ชัน $NewVersion (สำรองไว้ที่ $BackupDir)" 5
}
catch {
  Set-Status 'error' "อัปเดตล้มเหลว: $($_.Exception.Message) — กู้คืนได้จาก $BackupDir"
  exit 1
}
