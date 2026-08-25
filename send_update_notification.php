<?php
/**
 * send_update_notification.php
 * แจ้งเตือน "อัปเดตระบบสำเร็จ" ผ่าน LINE (MOPH Alert) + Telegram
 * เรียกจาก task/update.ps1 เป็นขั้นตอนสุดท้าย หลังอัปเดตโค้ด + migrate DB เสร็จ
 *   php send_update_notification.php [เวอร์ชันใหม่]   (ถ้าไม่ระบุ อ่านจากไฟล์ VERSION)
 *
 * best-effort เสมอ: ถ้าไม่ได้ตั้งค่าคีย์ / ส่งไม่สำเร็จ ก็แค่ log ไว้ ไม่ทำให้ exit code ผิดพลาด
 * (ตอนที่สคริปต์นี้รัน แปลว่าอัปเดตไฟล์เสร็จสมบูรณ์แล้ว — แค่แจ้งเตือนไม่สำเร็จไม่ควรทำให้ดูเหมือนอัปเดตพัง)
 */
define('CONFIG_SKIP_DB', true);   // ไม่ต้องต่อ MedAlert_DB สำหรับแค่ส่งแจ้งเตือน
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram_lib.php';

$logFile = __DIR__ . '/logs/update_notify.log';
function un_log(string $msg): void {
  global $logFile;
  @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

$version = trim((string)($argv[1] ?? ''));
if ($version === '') {
  $verFile = __DIR__ . '/VERSION';
  $version = is_readable($verFile) ? trim((string)@file_get_contents($verFile)) : 'ไม่ทราบเวอร์ชัน';
}
$hospital = defined('HOSPITAL_SHORT') ? HOSPITAL_SHORT : 'รพ.';
$now      = date('d/m/Y H:i');

/* ---------------- LINE (MOPH Alert) ---------------- */
if (!defined('SYSTEM_UPDATE_CLIENT_KEY')) define('SYSTEM_UPDATE_CLIENT_KEY', defined('MOPH_CLIENT_KEY') ? MOPH_CLIENT_KEY : '');
if (!defined('SYSTEM_UPDATE_SECRET_KEY')) define('SYSTEM_UPDATE_SECRET_KEY', defined('MOPH_SECRET_KEY') ? MOPH_SECRET_KEY : '');

if (SYSTEM_UPDATE_CLIENT_KEY !== '' && SYSTEM_UPDATE_SECRET_KEY !== '') {
  $bubble = [
    'type' => 'bubble', 'size' => 'kilo',
    'body' => [
      'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '16px', 'spacing' => 'sm',
      'contents' => [
        ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '12px', 'cornerRadius' => '12px',
         'backgroundColor' => '#4338CA', 'contents' => [
           ['type' => 'text', 'text' => '✅ อัปเดตระบบสำเร็จ', 'size' => 'md', 'weight' => 'bold', 'color' => '#FFFFFF'],
           ['type' => 'text', 'text' => 'MedAlert', 'size' => 'xs', 'color' => '#FFFFFFCC', 'margin' => 'xs'],
         ]],
        ['type' => 'box', 'layout' => 'baseline', 'margin' => 'lg', 'contents' => [
           ['type' => 'text', 'text' => 'เวอร์ชันใหม่', 'size' => 'sm', 'color' => '#6B7280', 'flex' => 4],
           ['type' => 'text', 'text' => $version, 'size' => 'sm', 'weight' => 'bold', 'color' => '#111827', 'align' => 'end', 'flex' => 6, 'wrap' => true],
         ]],
        ['type' => 'box', 'layout' => 'baseline', 'margin' => 'md', 'contents' => [
           ['type' => 'text', 'text' => 'เวลาอัปเดต', 'size' => 'sm', 'color' => '#6B7280', 'flex' => 4],
           ['type' => 'text', 'text' => $now, 'size' => 'sm', 'weight' => 'bold', 'color' => '#111827', 'align' => 'end', 'flex' => 6],
         ]],
        ['type' => 'text', 'text' => $hospital, 'size' => 'xxs', 'color' => '#9CA3AF', 'margin' => 'lg', 'align' => 'end'],
      ],
    ],
  ];
  $payload = ['messages' => [['type' => 'flex', 'altText' => "✅ อัปเดตระบบสำเร็จ — เวอร์ชัน {$version}", 'contents' => $bubble]]];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => defined('MOPH_API_URL') ? MOPH_API_URL : 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => defined('MOPH_TIMEOUT') ? MOPH_TIMEOUT : 30,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => [
      'client-key: ' . SYSTEM_UPDATE_CLIENT_KEY,
      'secret-key: ' . SYSTEM_UPDATE_SECRET_KEY,
      'Content-Type: application/json; charset=UTF-8',
      'Accept: application/json',
    ],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  un_log($err ? "LINE ส่งไม่สำเร็จ: {$err}" : "LINE ส่งแล้ว HTTP {$code}: " . substr((string)$resp, 0, 300));
} else {
  un_log('LINE ข้าม — ไม่ได้ตั้งค่า client/secret key (ทั้งเฉพาะ system_update และ default)');
}

/* ---------------- Telegram ---------------- */
try {
  $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  $text = "✅ <b>อัปเดตระบบสำเร็จ</b>\nเวอร์ชันใหม่: " . $esc($version) . "\nเวลา: " . $esc($now) . "\n" . $esc($hospital);
  $tgResult = telegram_send('system_update', $text);
  un_log('Telegram: ' . json_encode($tgResult, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
  un_log('Telegram exception: ' . $e->getMessage());
}

exit(0);
