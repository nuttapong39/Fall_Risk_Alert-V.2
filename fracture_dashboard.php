<?php
/**
 * fracture_dashboard.php — ยุบรวมเข้า dashboard.php (ศูนย์รวมทุก module) แล้ว
 * คงไฟล์ไว้เป็น redirect เพื่อ bookmark/ลิงก์เดิมยังใช้ได้
 */
$month = (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) ? $_GET['month'] : date('Y-m');
header('Location: dashboard.php?module=fracture&month=' . $month, true, 302);
exit;
