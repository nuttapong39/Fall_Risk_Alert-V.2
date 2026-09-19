<?php
/**
 * search_drug.php — endpoint ค้นหา/เติมชื่อยา ใช้โดย tag-input picker (partials/filter_drugpicker.php)
 * และการ์ดเด่น "รายการยาที่เฝ้าระวัง" ในหน้า drugs_alert.php
 *
 * GET action=search  &q=...            &inactive=0|1   → ค้นจาก icode/name (ขั้นต่ำ 2 ตัวอักษร)
 * GET action=lookup  &icodes=a,b,c                      → เติมชื่อยาให้ tag ที่มีอยู่แล้ว
 * GET action=count   &icodes=a,b,c &start=.. &end=..     → นับจำนวนจ่ายยาต่อ icode ในช่วงวันที่
 *
 * อ่านอย่างเดียวต่อ HOSxP ทั้งหมด (ADR-0001) — ไม่เขียน ไม่แก้ข้อมูลใดๆ
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/covid_lib.php';          // row_to_utf8()
require_once __DIR__ . '/sources/drug_search.php';

header('Content-Type: application/json; charset=utf-8');

$action = (string)($_GET['action'] ?? 'search');

try {
  if ($action === 'lookup') {
    $icodes = array_filter(array_map('trim', explode(',', (string)($_GET['icodes'] ?? ''))));
    $items  = array_map('row_to_utf8', drug_lookup($icodes));
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);

  } elseif ($action === 'count') {
    $icodes = array_filter(array_map('trim', explode(',', (string)($_GET['icodes'] ?? ''))));
    $start  = (string)($_GET['start'] ?? date('Y-m-d', strtotime('-1 day')));
    $end    = (string)($_GET['end']   ?? date('Y-m-d'));
    $counts = $icodes ? drug_dispense_counts($icodes, $start, $end) : [];
    echo json_encode(['ok' => true, 'counts' => $counts], JSON_UNESCAPED_UNICODE);

  } else {
    $q         = (string)($_GET['q'] ?? '');
    $inactive  = !empty($_GET['inactive']);
    $items     = array_map('row_to_utf8', drug_search($q, 20, $inactive));
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
  }
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'msg' => 'เชื่อมต่อ HOSxP ไม่สำเร็จ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
