<#
  update.ps1
  ตัวทำงานจริงของระบบอัปเดตเวอร์ชัน MedAlert (เรียกผ่าน update.bat หรือจากปุ่มในเว็บ)

  ขั้นตอน:
    1) สำรองทั้งโฟลเดอร์ไป ..\_backup\ ก่อนแตะอะไร
    2) ถ้ามี .git (และมี git.exe ใช้ได้) -> git fetch + git reset --hard origin/master
       ถ้าไม่มี -> ดาวน์โหลด ZIP จาก GitHub มาทับ (robocopy, ไม่แตะ secrets/ กับ logs/)
    3) รัน db_migrate.php (deploy ตาราง queue ใหม่ถ้ามี) + รีเฟรช CA certificate bundle ที่
       PHP/cURL ใช้ตรวจ SSL (curl.cainfo/openssl.cafile) ถ้าตั้งค่าไว้ — best-effort เสมอ
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
  # ต้องเขียน UTF-8 แบบ "ไม่มี BOM" — Set-Content -Encoding UTF8 บน PowerShell 5.1 ใส่ BOM
  # ให้เสมอ ทำให้ json_decode ฝั่ง PHP (system_update_status.php) อ่านไฟล์นี้ไม่ผ่าน
  [System.IO.File]::WriteAllText($StatusFile, ($obj | ConvertTo-Json -Compress), (New-Object System.Text.UTF8Encoding($false)))
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
  # /R:2 /W:2 สำคัญมาก — robocopy default คือ retry 1,000,000 ครั้ง รอครั้งละ 30 วิ ถ้าไม่ระบุ
  # (เจอบั๊กจริง: ไฟล์ล็อกอยู่ 1 ไฟล์ทำให้ robocopy ค้างเงียบๆ ไม่ error ไม่ progress ต่อ ดูเหมือนแฮงตลอดกาล)
  robocopy $AppDir $BackupDir /E /XD ".git" /R:2 /W:2 /NFL /NDL /NJH /NJS /NC /NS | Out-Null
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

    # ดาวน์โหลด+แตกไฟล์ ZIP มี retry — เน็ตบางที่ (เช่น รพ. หลังพร็อกซี/ไฟร์วอลล์) ตัดคอนเนกชัน
    # ที่ค้างนานกลางทางไฟล์ใหญ่บ่อย (เจอจริง: หลุดตอนนาทีที่ 14 ด้วย "connection was closed
    # unexpectedly") — ใช้ retry ไม่ใช่เพิ่ม timeout เพราะสาเหตุคือ connection reset ไม่ใช่ timeout
    $maxAttempts = 4
    $lastError   = $null
    $downloadOk  = $false
    for ($attempt = 1; $attempt -le $maxAttempts; $attempt++) {
      try {
        if ($attempt -gt 1) {
          Set-Status 'running' "ดาวน์โหลด ZIP ไม่สำเร็จ กำลังลองใหม่ (ครั้งที่ $attempt/$maxAttempts)..." 2
        }
        Remove-Item -LiteralPath $TmpZip -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $TmpExtract -Recurse -Force -ErrorAction SilentlyContinue
        Invoke-WebRequest -Uri $ZipUrl -OutFile $TmpZip -UseBasicParsing
        Expand-Archive -Path $TmpZip -DestinationPath $TmpExtract -Force
        $downloadOk = $true
        break
      } catch {
        $lastError = $_.Exception.Message
        Add-Content -LiteralPath $LogFile -Value "[$(Get-Date -Format 's')] [warn] ดาวน์โหลด/แตกไฟล์ ZIP ล้มเหลว (ครั้งที่ $attempt/$maxAttempts): $lastError"
        if ($attempt -lt $maxAttempts) { Start-Sleep -Seconds (10 * $attempt) }
      }
    }
    if (!$downloadOk) {
      throw "ดาวน์โหลด ZIP จาก GitHub ล้มเหลวหลังลองแล้ว $maxAttempts ครั้ง ($lastError) — ตรวจการเชื่อมต่ออินเทอร์เน็ต/proxy ของเครื่องนี้ หรือติดตั้ง git แล้วให้ระบบใช้ git fetch แทน (ข้อมูลน้อยกว่ามาก)"
    }

    $SrcRoot = Get-ChildItem -Path $TmpExtract -Directory | Select-Object -First 1
    if (!$SrcRoot) { throw 'แตกไฟล์ ZIP แล้วไม่พบโฟลเดอร์โค้ด' }

    Set-Status 'running' 'กำลังคัดลอกไฟล์ทับ (ยกเว้น secrets/ และ logs/)' 3
    robocopy $SrcRoot.FullName $AppDir /E /XD secrets logs .git /R:2 /W:2 /NFL /NDL /NJH /NJS /NC /NS | Out-Null
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

  # ── 3.5) รีเฟรช CA certificate bundle ที่ PHP/cURL ใช้ตรวจ SSL (curl.cainfo/openssl.cafile) ──
  # เคสจริงที่เจอ: รพ.หนึ่งใช้ curl-ca-bundle.crt ที่ค้างมาตั้งแต่ปี 2022 ทำให้ HTTPS ทุกทาง
  # จาก PHP/cURL พังหมด (root CA ใหม่ๆ เช่น Sectigo Public Server Authentication Root R46
  # ไม่อยู่ใน bundle เก่า) — ขั้นตอนนี้ best-effort เสมอ พลาดแล้วต้อง "ข้าม" ห้ามทำให้อัปเดต
  # ทั้งก้อนดูเหมือนล้มเหลว (เหมือนขั้นแจ้งเตือนด้านล่าง) และไม่สั่ง restart Apache เอง เพราะ
  # PHP/cURL อ่านเนื้อหาไฟล์นี้ใหม่ทุกครั้งที่เชื่อมต่ออยู่แล้ว (พาธใน php.ini ไม่ได้เปลี่ยน)
  try {
    if (!$PhpExe) {
      Add-Content -LiteralPath $LogFile -Value "[$(Get-Date -Format 's')] [warn] ไม่พบ php.exe — ข้ามการอัปเดต CA bundle (ตรวจสอบ/อัปเดตเองภายหลังได้)"
    } else {
      $caRaw = (& $PhpExe -r "echo ini_get('curl.cainfo') ?: ini_get('openssl.cafile');" 2>$null | Out-String).Trim()

      if ([string]::IsNullOrWhiteSpace($caRaw)) {
        Add-Content -LiteralPath $LogFile -Value "[$(Get-Date -Format 's')] [info] PHP ไม่ได้ตั้งค่า curl.cainfo/openssl.cafile ไว้ — ข้ามขั้นตอนอัปเดต CA bundle (ไม่มีไฟล์ต้องรีเฟรช)"
      } elseif (!(Test-Path -LiteralPath $caRaw)) {
        Add-Content -LiteralPath $LogFile -Value "[$(Get-Date -Format 's')] [warn] curl.cainfo/openssl.cafile ชี้ไปที่ไฟล์ที่ไม่พบ ($caRaw) — ข้ามขั้นตอนอัปเดต CA bundle"
      } else {
        $CaBundlePath = $caRaw
        Set-Status 'running' 'กำลังตรวจสอบ/อัปเดตชุดใบรับรอง CA (CA bundle) สำหรับ HTTPS...' 4

        $CaUrl         = 'https://curl.se/ca/cacert.pem'
        $CaTmpFile     = Join-Path $env:TEMP "medalert_cacert_$Stamp.pem"
        $caMaxAttempts = 4
        $caLastError   = $null
        $caDownloadOk  = $false
        for ($attempt = 1; $attempt -le $caMaxAttempts; $attempt++) {
          try {
            if ($attempt -gt 1) {
              Set-Status 'running' "ดาวน์โหลด CA bundle ไม่สำเร็จ กำลังลองใหม่ (ครั้งที่ $attempt/$caMaxAttempts)..." 4
            }
            Remove-Item -LiteralPath $CaTmpFile -Force -ErrorAction SilentlyContinue
            Invoke-WebRequest -Uri $CaUrl -OutFile $CaTmpFile -UseBasicParsing -TimeoutSec 30
            $caDownloadOk = $true
            break
          } catch {
            $caLastError = $_.Exception.Message
            Add-Content -LiteralPath $LogFile -Value "[$(Get-Date -Format 's')] [warn] ดาวน์โหลด CA bundle ล้มเหลว (ครั้งที่ $attempt/$caMaxAttempts): $caLastError"
            if ($attempt -lt $caMaxAttempts) { Start-Sleep -Seconds (10 * $attempt) }
          }
        }

        if (!$caDownloadOk) {
          Add-Content -LiteralPath $LogFile -Value "[$(Get-Date -Format 's')] [warn] ดาวน์โหลด CA bundle จาก curl.se ล้มเหลวหลังลองแล้ว $caMaxAttempts ครั้ง ($caLastError) — ข้ามขั้นตอนนี้ ใช้ไฟล์เดิมต่อไป (ไม่กระทบผลอัปเดตหลัก)"
        } else {
          $validSize    = (Get-Item -LiteralPath $CaTmpFile).Length -ge 50KB
          $validContent = $validSize -and (Select-String -LiteralPath $CaTmpFile -Pattern '-----BEGIN CERTIFICATE-----' -SimpleMatch -Quiet)

          if (!$validSize -or !$validContent) {
            Add-Content -LiteralPath $LogFile -Value "[$(Get-Date -Format 's')] [warn] ไฟล์ CA bundle ที่ดาวน์โหลดมาไม่ผ่านการตรวจสอบ (ขนาด/รูปแบบไม่ถูกต้อง) — ไม่ทับไฟล์เดิมเพื่อความปลอดภัย"
          } else {
            $CaBackupDir = Join-Path $BackupDir '_ca_bundle_backup'
            if (!(Test-Path $CaBackupDir)) { New-Item -ItemType Directory -Path $CaBackupDir -Force | Out-Null }
            $CaBackupFile = Join-Path $CaBackupDir (Split-Path -Leaf $CaBundlePath)
            Copy-Item -LiteralPath $CaBundlePath -Destination $CaBackupFile -Force

            # copy+rename ในโฟลเดอร์เดียวกับไฟล์ปลายทางแทน copy ตรงๆ — rename ในไดรฟ์เดียวกัน
            # เป็น atomic ระดับ NTFS ลดโอกาส Apache อ่านไฟล์ครึ่งเดียวขณะกำลังเขียนทับ
            $CaSwapFile = "$CaBundlePath.new"
            Copy-Item -LiteralPath $CaTmpFile -Destination $CaSwapFile -Force
            Move-Item -LiteralPath $CaSwapFile -Destination $CaBundlePath -Force

            Set-Status 'running' "อัปเดต CA bundle สำเร็จ (สำรองไฟล์เดิมไว้ที่ $CaBackupFile)" 4
          }
        }
        Remove-Item -LiteralPath $CaTmpFile -Force -ErrorAction SilentlyContinue
      }
    }
  } catch {
    Add-Content -LiteralPath $LogFile -Value "[$(Get-Date -Format 's')] [warn] อัปเดต CA bundle ล้มเหลว (ไม่กระทบผลอัปเดตหลัก): $($_.Exception.Message)"
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
