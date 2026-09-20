<?php
/**
 * alert_window_loader.php
 * ตัวโหลด "ช่วงเวลาแจ้งเตือน" (Alert Window) ราย module จาก secrets/alert_windows.json
 *
 * Alert Window = นโยบายของขั้น Send เท่านั้น — ไม่ใช่ Condition (เกณฑ์คัดกรองผู้ป่วยในขั้น
 * Ingest ซึ่งอยู่ใน module_filters.json) จงใจแยกไฟล์กันเพราะเป็นคนละแนวคิด และ schema ของ
 * filter modal จะได้ไม่ต้องมี special case สำหรับฟิลด์เวลา (ดู docs/adr/0003)
 *
 * default = enabled:false ทุก module = ส่งตลอด 24 ชม. (พฤติกรรมเดิมเป๊ะ) — สำคัญมาก เพราะ
 * รพ. ที่กดอัปเดตจะได้โค้ดใหม่แต่ยังไม่มีไฟล์นี้ ต้องไม่มีอะไรเปลี่ยนจนกว่าจะเปิดเอง
 * (module_filters_loader.php บันทึกอุบัติเหตุจริงจาก default ที่ไม่ behavior-preserving ไว้แล้ว)
 *
 * fail-open ทุกทาง: ไฟล์หาย / JSON เสีย / ค่าผิดรูปแบบ / start==end → แปลว่า "ส่ง 24 ชม."
 * ไม่ใช่ "เงียบ" — ระบบแจ้งเตือนทางการแพทย์ควรพลาดไปทาง "แจ้งเกิน" ไม่ใช่ "ไม่แจ้ง"
 */

if (!defined('ALERT_WINDOWS_FILE')) {
  define('ALERT_WINDOWS_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'alert_windows.json');
}

/* ── ตัวเลขที่ใช้คำนวณ "ส่งได้กี่รายการต่อวัน" — ต้องตรงกับของจริง 2 ที่ ─────────────
 *   AW_SEND_LIMIT = $limit ของ had_send_pending() ใน HAD.php
 *   AW_CRON_MIN   = <Repetition><Interval>PT5M</Interval> ใน task/HAD_Auto Sender.xml
 * ประกาศไว้ที่เดียวแล้วให้ทั้ง worker / info-line / JS ในโมดัลอ้างค่านี้ กันตัวเลขคนละตัว
 * (XML เป็น UTF-16 ฝั่ง PHP อ่านไม่ได้ ถ้าใครไปแก้ Interval ที่ รพ. ใด ตัวเลขในโมดัลจะเพี้ยน) */
if (!defined('AW_SEND_LIMIT')) define('AW_SEND_LIMIT', 50);
if (!defined('AW_CRON_MIN'))   define('AW_CRON_MIN',   5);

$GLOBALS['ALERT_WINDOW_DEFAULTS'] = [
  // start/end เป็นแค่ค่าตั้งต้นให้โมดัล (ห้องยาขอ 16:30-08:00) — ไม่มีผลใดๆ ตราบใดที่ enabled=false
  'had' => ['enabled' => false, 'start' => '16:30', 'end' => '08:00'],
];

/* ── validate / normalize ──────────────────────────────────────────────────── */
if (!function_exists('aw_valid_time')) {
  /** "HH:MM" 24 ชม. เท่านั้น — 08:00 ผ่าน · 8:00 / 24:00 / 08:60 / "8am" ไม่ผ่าน */
  function aw_valid_time($s): bool {
    return is_string($s) && (bool)preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $s);
  }
}
if (!function_exists('aw_norm_time')) {
  /** ตัดวินาทีทิ้งก่อน (บางเบราว์เซอร์/ไฟล์ที่แก้มือส่ง "16:30:00") แล้วค่อย validate เข้ม */
  function aw_norm_time($s): ?string {
    if (!is_string($s)) return null;
    $s = trim($s);
    if (strlen($s) === 8 && substr($s, 5, 1) === ':') $s = substr($s, 0, 5);
    return aw_valid_time($s) ? $s : null;
  }
}
if (!function_exists('aw_minutes')) {
  /** "16:30" → 990 (นาทีนับจากเที่ยงคืน) — เรียกได้เฉพาะค่าที่ผ่าน aw_valid_time มาแล้ว */
  function aw_minutes(string $hhmm): int {
    return ((int)substr($hhmm, 0, 2)) * 60 + ((int)substr($hhmm, 3, 2));
  }
}

/* ── โหลด store (รอบเดียวต่อ request) ──────────────────────────────────────── */
if (!function_exists('_alert_windows_stored')) {
  function _alert_windows_stored(): array {
    static $data = null;
    if ($data !== null) return $data;
    $data = [];
    if (is_readable(ALERT_WINDOWS_FILE)) {
      $j = json_decode(@file_get_contents(ALERT_WINDOWS_FILE), true);
      if (is_array($j)) $data = $j;
    }
    return $data;   // ไฟล์เสีย/อ่านไม่ได้ → [] → ใช้ default = ส่ง 24 ชม. (fail-open โดยตั้งใจ)
  }
}

if (!function_exists('alert_window')) {
  /**
   * คืน ['enabled'=>bool,'start'=>'HH:MM','end'=>'HH:MM'] ที่ normalize แล้วเสมอ
   *
   * ถ้าเวลาใดเวลาหนึ่งในไฟล์ใช้ไม่ได้ → คืน default ทั้งก้อน (= ปิด = ส่ง 24 ชม.)
   * ห้าม fallback เฉพาะฟิลด์เวลาแล้วคง enabled=true ไว้เด็ดขาด เพราะจะได้หน้าต่างที่
   * "เปิดใช้อยู่" ด้วยเวลาที่ผู้ใช้ไม่เคยตั้ง แล้วไป "ปิดกั้น" การส่งผิดช่วงแบบเงียบๆ
   * (fail-closed) ซึ่งตรงข้ามกับที่ระบบนี้ต้องการ — ยา High Alert ต้องพลาดไปทาง
   * "แจ้งเกิน" ไม่ใช่ "ไม่แจ้ง" · เคสนี้เกิดได้เฉพาะตอนแก้ JSON ด้วยมือ (UI validate แล้ว)
   */
  function alert_window(string $mod): array {
    $def = $GLOBALS['ALERT_WINDOW_DEFAULTS'][$mod] ?? ['enabled'=>false,'start'=>'00:00','end'=>'00:00'];
    $st  = _alert_windows_stored()[$mod] ?? [];
    if (!is_array($st)) $st = [];

    $start = aw_norm_time($st['start'] ?? null);
    $end   = aw_norm_time($st['end']   ?? null);
    if ($start === null || $end === null) return $def;   // ไม่ได้ตั้ง/ตั้งเสีย = ไม่มีหน้าต่าง

    // รับได้ทั้ง bool จริงและค่าที่แก้มือมาเป็น string — นอกรายการนี้ = ปิด (พฤติกรรมเดิม)
    $en = $st['enabled'] ?? $def['enabled'];
    return [
      'enabled' => in_array($en, [true, 1, '1', 'true', 'yes', 'on'], true),
      'start'   => $start,
      'end'     => $end,
    ];
  }
}

if (!function_exists('alert_window_is_open')) {
  /**
   * หน้าต่างเปิดอยู่ไหม ณ เวลา $now (unix timestamp — ไม่ส่ง = ตอนนี้)
   * $now มีไว้ให้ตรวจสอบตรรกะข้ามเที่ยงคืนได้โดยไม่ต้องรอเวลาจริง/ไปยุ่งกับนาฬิกาเครื่อง
   * (เช่น `php -r` ยิงเช็คทีละชั่วโมง) โค้ดที่ใช้งานจริงทุกที่เรียกแบบไม่ส่ง argument
   *   ช่วงเป็น [start, end) — เริ่มรวม ปลายไม่รวม
   *   start <  end → ช่วงในวันเดียวกัน : now >= start AND now < end
   *   start >  end → ข้ามเที่ยงคืน     : now >= start OR  now < end
   *   start == end → เปิดตลอด 24 ชม. (fail-open — ดูหัวไฟล์)
   *   enabled=false → เปิดเสมอ (สวิตช์ปิด = พฤติกรรมเดิม 24/7)
   */
  function alert_window_is_open(string $mod, ?int $now = null): bool {
    $w = alert_window($mod);
    if (!$w['enabled']) return true;
    $s = aw_minutes($w['start']);
    $e = aw_minutes($w['end']);
    if ($s === $e) return true;
    $t = $now ?? time();
    $n = ((int)date('H', $t)) * 60 + ((int)date('i', $t));
    return ($s < $e) ? ($n >= $s && $n < $e) : ($n >= $s || $n < $e);
  }
}

if (!function_exists('aw_window_minutes')) {
  /** ความยาวหน้าต่างเป็นนาที (start==end → 1440) — รับ array จาก alert_window() */
  function aw_window_minutes(array $w): int {
    $s = aw_minutes($w['start']);
    $e = aw_minutes($w['end']);
    if ($s === $e) return 1440;
    return ($e > $s) ? ($e - $s) : (1440 - $s + $e);
  }
}

if (!function_exists('alert_window_summary')) {
  /** ข้อความสั้นภาษาไทย ใช้ทั้งบน UI และใน logs/had_task_run.log */
  function alert_window_summary(string $mod): string {
    $w = alert_window($mod);
    if (!$w['enabled']) return 'ปิดอยู่ (ส่งตลอด 24 ชม.)';
    $s = aw_minutes($w['start']);
    $e = aw_minutes($w['end']);
    if ($s === $e) return "{$w['start']}-{$w['end']} น. (= เปิดตลอด 24 ชม.)";
    $hrs = rtrim(rtrim(number_format(aw_window_minutes($w) / 60, 1), '0'), '.');
    return "{$w['start']}-{$w['end']} น. (" . ($s > $e ? 'ข้ามเที่ยงคืน ' : '') . "{$hrs} ชม.)";
  }
}

if (!function_exists('alert_window_next_open')) {
  /** เวลาที่หน้าต่างจะเปิดรอบถัดไป ("16:30") — คืน null ถ้าเปิดอยู่แล้ว */
  function alert_window_next_open(string $mod): ?string {
    if (alert_window_is_open($mod)) return null;
    return alert_window($mod)['start'];
  }
}
