<?php
/**
 * telegram_lib.php — แจ้งเตือนผ่าน Telegram Bot API (mirror patient alerts)
 * อ้างอิง: https://core.telegram.org/bots/api  (เมธอด sendMessage)
 *
 * เก็บคอนฟิกใน secrets/moph_keys.json คีย์ "telegram" แนวเดียวกับ MOPH keys:
 *   {
 *     "telegram": {
 *       "enabled": true,
 *       "default":  { "token": "<bot token>", "chat_id": "<chat id>" },
 *       "fracture": { "chat_id": "-100..." },   // override เฉพาะ chat ราย feature
 *       ...
 *     }
 *   }
 * Bot token ใช้ร่วมจาก default (1 bot) ส่วน chat_id แยกตาม feature; ถ้า module ไม่ตั้ง → ใช้ default
 */

if (!function_exists('telegram_load_cfg')) {
  function telegram_load_cfg(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'moph_keys.json';
    $cfg = [];
    if (is_readable($file)) {
      $j = json_decode(@file_get_contents($file), true);
      if (is_array($j) && isset($j['telegram']) && is_array($j['telegram'])) $cfg = $j['telegram'];
    }
    return $cfg;
  }
}

if (!function_exists('telegram_enabled')) {
  function telegram_enabled(): bool {
    $cfg = telegram_load_cfg();
    return !empty($cfg['enabled']);
  }
}

if (!function_exists('telegram_cfg')) {
  /** คืน [token, chat_id] ของ module โดย fallback ไป default */
  function telegram_cfg(string $module): array {
    $cfg   = telegram_load_cfg();
    $def   = $cfg['default'] ?? [];
    $mod   = $cfg[$module]   ?? [];
    $token = ($mod['token']   ?? '') !== '' ? $mod['token']   : ($def['token']   ?? '');
    $chat  = ($mod['chat_id'] ?? '') !== '' ? $mod['chat_id'] : ($def['chat_id'] ?? '');
    return [trim((string)$token), trim((string)$chat)];
  }
}

if (!function_exists('telegram_send')) {
  /**
   * ส่งข้อความไป Telegram. ไม่ทำให้ flow หลักล้ม — คืน array ['ok'=>bool,'ref'=>?id,'error'=>?str]
   * @param $module ชื่อ feature (fracture/covid/...) ใช้เลือก chat_id
   * @param $text   ข้อความ (รองรับ HTML tag พื้นฐานของ Telegram เมื่อ parseMode=HTML)
   */
  function telegram_send(string $module, string $text, string $parseMode = 'HTML'): array {
    if (!telegram_enabled()) return ['ok'=>false, 'ref'=>null, 'error'=>'telegram disabled'];
    [$token, $chatId] = telegram_cfg($module);
    if ($token === '' || $chatId === '') {
      return ['ok'=>false, 'ref'=>null, 'error'=>'missing token/chat_id'];
    }
    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $post = [
      'chat_id'                  => $chatId,
      'text'                     => $text,
      'parse_mode'               => $parseMode,
      'disable_web_page_preview' => true,
    ];
    try {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
      ]);
      $res  = curl_exec($ch);
      $err  = curl_error($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($err)            return ['ok'=>false, 'ref'=>null, 'error'=>"curl: {$err}"];
      $data = json_decode((string)$res, true);
      if (($code >= 200 && $code < 300) && !empty($data['ok'])) {
        return ['ok'=>true, 'ref'=>$data['result']['message_id'] ?? null, 'error'=>null];
      }
      return ['ok'=>false, 'ref'=>null, 'error'=>$data['description'] ?? "http {$code}"];
    } catch (Throwable $e) {
      return ['ok'=>false, 'ref'=>null, 'error'=>$e->getMessage()];
    }
  }
}

if (!function_exists('telegram_mirror')) {
  /**
   * mirror หนึ่ง alert ของผู้ป่วยไป Telegram — สร้างข้อความมาตรฐานจาก field ที่มีใน row
   * เรียกหลังส่ง LINE/MOPH สำเร็จ; เงียบเสมอถ้าปิดใช้งาน/ตั้งค่าไม่ครบ (ไม่ทำให้ flow ล้ม)
   */
  function telegram_mirror(string $module, string $title, array $row): array {
    if (!telegram_enabled()) return ['ok'=>false, 'ref'=>null, 'error'=>'disabled'];
    $g = fn($k) => trim((string)($row[$k] ?? ''));
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $lines = ["🔔 <b>{$esc($title)}</b>"];
    if ($g('fullname') !== '') $lines[] = "👤 " . $esc($g('fullname'));
    if ($g('hn') !== '')       $lines[] = "HN: " . $esc($g('hn'));
    if ($g('age') !== '')      $lines[] = "อายุ: " . $esc($g('age')) . " ปี";
    // field เฉพาะโมดูล (เลือกที่มีจริงใน row)
    $labels = [
      'pdx_name'=>'วินิจฉัย', 'disease'=>'โรค', 'icd10'=>'ICD-10',
      'drug_name'=>'ยา', 'lab_name'=>'Lab', 'result'=>'ผล',
      'pttname'=>'สิทธิ', 'doctor'=>'แพทย์',
      'lab_date'=>'วันที่', 'vstdate'=>'วันที่', 'regdate'=>'วันที่',
    ];
    foreach ($labels as $k => $lab) {
      if ($g($k) !== '') $lines[] = "{$esc($lab)}: " . $esc($g($k));
    }
    return telegram_send($module, implode("\n", $lines));
  }
}
