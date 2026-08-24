<?php
/**
 * system_update_action.php — endpoint สำหรับการ์ด "เวอร์ชันระบบ" ในหน้า settings.php
 *   action=check : (read-only) ดึง VERSION ล่าสุดจาก GitHub เทียบกับ APP_VERSION ปัจจุบัน
 *   action=run   : สั่งรัน task\update.bat แบบ detached (ไม่รอ, ไม่ block request)
 *                  ต้องผ่าน UI_ACTION_TOKEN + พิมพ์ยืนยันคำว่า "UPDATE" เพิ่ม
 *                  (ปุ่มนี้เสี่ยงที่สุดในระบบ — overwrite โค้ดทั้งโฟลเดอร์ — token เดิมของระบบเป็นค่า static
 *                   เดียวกันทั้งแอป จึงเพิ่มชั้นยืนยันนี้เฉพาะจุด ไม่ได้แก้ auth ระบบโดยรวม)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$action = trim($_POST['action'] ?? '');

/* ══ action=check — read-only, ไม่ต้องมี token (ไม่เขียนอะไรเลย) ═══════════ */
if ($action === 'check') {
  $current = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
  $latest  = null;
  $err     = null;

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://raw.githubusercontent.com/nuttapong39/Fall_Risk_Alert-V.2/master/VERSION',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 8,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);

  if ($resp !== false && $code === 200) {
    $latest = trim($resp);
  } else {
    $err = 'เชื่อมต่อ GitHub ไม่สำเร็จ' . ($cerr ? " ({$cerr})" : " (HTTP {$code})");
  }

  echo json_encode([
    'ok'              => $latest !== null,
    'current'         => $current,
    'latest'          => $latest,
    // เทียบแบบ string (lexicographic) พอ เพราะ VERSION เป็น YYYY.MM.DD.HHMM ที่ zero-pad สม่ำเสมอ
    // เทียบ "ใหม่กว่า" จริง ไม่ใช่แค่ "ต่างกัน" — กัน false positive ตอน local ล้ำหน้า remote ชั่วคราว
    // (เช่น CDN ของ raw.githubusercontent.com cache ค่าเก่าค้างไว้สักพักหลัง push)
    'updateAvailable' => $latest !== null && $latest > $current,
    'msg'             => $err,
  ]);
  exit;
}

/* ══ action=run — ต้องผ่าน auth + token + พิมพ์ยืนยัน "UPDATE" ══════════════ */
if ($action === 'run') {
  if (($_POST['token'] ?? '') !== (defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : '')) {
    echo json_encode(['ok'=>false,'msg'=>'Token ไม่ถูกต้อง — refresh หน้าแล้วลองใหม่']); exit;
  }
  if (trim((string)($_POST['confirm'] ?? '')) !== 'UPDATE') {
    echo json_encode(['ok'=>false,'msg'=>'กรุณาพิมพ์ UPDATE ให้ตรงเพื่อยืนยัน']); exit;
  }

  $batPath = __DIR__ . '\\task\\update.bat';
  $vbsPath = __DIR__ . '\\task\\launch_detached.vbs';
  if (!is_file($batPath) || !is_file($vbsPath)) {
    echo json_encode(['ok'=>false,'msg'=>'ไม่พบ task\\update.bat หรือ task\\launch_detached.vbs']); exit;
  }

  $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
  $canExec  = function_exists('exec') && !in_array('exec', $disabled, true);

  if (!$canExec) {
    echo json_encode([
      'ok'     => false,
      'manual' => true,
      'msg'    => 'เซิร์ฟเวอร์นี้ปิดฟังก์ชันรันโปรแกรมภายนอกไว้ (exec ถูกปิด) — กรุณาไปที่เครื่อง server แล้วดับเบิลคลิก task\\update.bat เอง',
    ]);
    exit;
  }

  try {
    // เปิดแบบ detached จริง (ไม่รอให้จบ) ผ่าน wscript.exe + WScript.Shell.Run —
    // proc_open()+proc_close() บน Windows ผูก child process กับ Job Object ที่ถูกฆ่า
    // ทันทีที่ request จบ (แม้จะสั่ง "start /B" ก็ตาม) ทำให้ update.bat ไม่เคยรันจริง
    // ทดสอบแล้วว่า WScript.Shell.Run(cmd, 0, False) รอดจาก lifecycle ของ PHP request
    $cmd = 'wscript.exe //B ' . escapeshellarg($vbsPath) . ' ' . escapeshellarg($batPath);
    exec($cmd, $out, $rc);
    if ($rc === 0) {
      echo json_encode(['ok'=>true,'msg'=>'เริ่มอัปเดตแล้ว — ระบบกำลังทำงานอยู่เบื้องหลัง']);
    } else {
      echo json_encode(['ok'=>false,'manual'=>true,'msg'=>'สั่งรันสคริปต์ไม่สำเร็จ — กรุณาดับเบิลคลิก task\\update.bat เอง']);
    }
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'manual'=>true,'msg'=>'เกิดข้อผิดพลาด: '.$e->getMessage().' — กรุณาดับเบิลคลิก task\\update.bat เอง']);
  }
  exit;
}

echo json_encode(['ok'=>false,'msg'=>'action ไม่ถูกต้อง']);
