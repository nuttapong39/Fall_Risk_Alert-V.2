<?php
/**
 * dashboard_export.php — Export CSV (UTF-8 BOM) ของ 1 module ตามเดือน
 *   ?module=<key>&month=YYYY-MM   → ดาวน์โหลด {module}_{month}.csv
 * คอลัมน์ = registry columns (เหมือนตารางหน้า dashboard/queue_ui) · read-only
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/covid_lib.php';        // row_to_utf8
require_once __DIR__ . '/dashboard_modules.php';
date_default_timezone_set('Asia/Bangkok');

$moduleKey = (string)($_GET['module'] ?? '');
$mod       = dash_module($moduleKey);           // validate กับ registry (กัน SQL injection ชื่อ table)
$month     = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
if (!$mod) { http_response_code(404); exit('unknown module'); }

$span = (string)($_GET['span'] ?? 'month');
if (!in_array($span, ['month','3m','6m','9m','q'], true)) $span = 'month';
$range = dash_span_range($span, $month);        // [span, start, end, label]

$expr = dash_month_expr($mod);
$st = $dbcon->prepare("SELECT * FROM {$mod['table']} WHERE $expr BETWEEN :a AND :b
                       ORDER BY COALESCE({$mod['date']}, created_at) DESC");
$st->execute([':a' => $range['start'], ':b' => $range['end']]);
$rows = array_map('row_to_utf8', $st->fetchAll(PDO::FETCH_ASSOC));

$fname = ($range['start'] === $range['end'])
  ? $moduleKey . '_' . $range['start']
  : $moduleKey . '_' . $range['start'] . '_' . $range['end'];
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");                    // UTF-8 BOM — กันภาษาไทยเพี้ยนใน Excel

fputcsv($out, array_values($mod['columns']));    // แถวหัวตาราง (label)
foreach ($rows as $r) {
  $line = [];
  foreach ($mod['columns'] as $col => $lbl) {
    if ($col === 'status') { [$sl, ] = dash_row_status($r); $line[] = $sl; }
    else                   { $line[] = (string)($r[$col] ?? ''); }
  }
  fputcsv($out, $line);
}
fclose($out);
exit;
