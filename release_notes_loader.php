<?php
/**
 * release_notes_loader.php
 * อ่านรายการเวอร์ชันที่มี release note จาก docs/release-notes.html
 *
 * ใช้ทำ badge "มีของใหม่ยังไม่ได้อ่าน" บนเมนู "เกี่ยวกับระบบ" ใน partials/header.php
 * — เทียบกับ localStorage['ckh-seen-release'] ของเบราว์เซอร์นั้นๆ
 *
 * เจตนา: ให้ไฟล์ HTML เป็น single source of truth ที่เดียว เพิ่มรีลีสใหม่ = แก้ไฟล์เดียว
 * ไม่ต้องมานั่งรักษารายการเวอร์ชันซ้ำอีกที่ให้ลืมอัปเดตแล้ว badge เพี้ยน
 *
 * fail-safe เสมอ: ไฟล์หาย / อ่านไม่ได้ / ไม่เจอ section → คืน [] = ไม่มี badge
 * ห้าม throw เด็ดขาด เพราะ sidebar อยู่ทุกหน้าของระบบ
 */

if (!defined('RELEASE_NOTES_FILE')) {
  define('RELEASE_NOTES_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'release-notes.html');
}

if (!function_exists('release_notes_versions')) {
  /**
   * คืนรายการเวอร์ชันที่มี release note เรียงใหม่สุดก่อน เช่น ['2026.09.21.0018','2026.09.19.1344']
   *
   * รับเฉพาะรูปแบบ YYYY.MM.DD[.HHMM] เท่านั้น — ตั้งใจให้เข้มเพื่อให้ตัวอย่าง
   * data-version="YYYY.MM.DD.HHMM" ที่เขียนไว้ในคอมเมนต์สอนวิธีเพิ่มรีลีส ไม่ถูกนับเป็นของจริง
   */
  function release_notes_versions(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    if (!is_readable(RELEASE_NOTES_FILE)) return $cache;

    $html = @file_get_contents(RELEASE_NOTES_FILE);
    if ($html === false || $html === '') return $cache;

    if (!preg_match_all('/data-version="(\d{4}\.\d{2}\.\d{2}(?:\.\d{4})?)"/', $html, $m)) return $cache;

    // SORT_STRING บังคับเทียบแบบข้อความ — วิธีเดียวกับที่ system_update_action.php ใช้เทียบเวอร์ชัน
    // (รูปแบบ YYYY.MM.DD.HHMM zero-pad สม่ำเสมอ เรียงข้อความจึงได้ลำดับเวลาที่ถูกต้อง)
    $cache = array_values(array_unique($m[1]));
    rsort($cache, SORT_STRING);
    return $cache;
  }
}
