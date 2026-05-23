<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/covid_lib.php';
date_default_timezone_set('Asia/Bangkok');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
if (!isset($_POST['token']) || $_POST['token'] !== UI_ACTION_TOKEN) { http_response_code(403); exit('Forbidden'); }

$action = $_POST['action'] ?? '';
$ids    = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
$ids    = array_values(array_filter($ids, fn($x)=>ctype_digit((string)$x)));
if (!$ids) { header('Location: queue_ui.php?msg=no_ids'); exit; }

try {
  if ($action === 'requeue') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("UPDATE covid_queue SET status=0, attempt=0, last_error=NULL, out_ref=NULL, line_message_id=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: queue_ui.php?msg=requeued&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'clear_error') {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $dbcon->prepare("UPDATE covid_queue SET last_error=NULL WHERE id IN ($place)");
    $stmt->execute($ids);
    header('Location: queue_ui.php?msg=cleared&affected='.$stmt->rowCount()); exit;

  } elseif ($action === 'send_now') {
    $ok=0; $fail=0;
    foreach ($ids as $id) {
      [$o,$r,$e] = send_one_by_id($dbcon, (int)$id);
      if ($o) $ok++; else $fail++;
    }
    header('Location: queue_ui.php?msg=sendnow&ok='.$ok.'&fail='.$fail); exit;

  } else {
    header('Location: queue_ui.php?msg=bad_action'); exit;
  }
} catch (Throwable $e) {
  header('Location: queue_ui.php?msg=err&detail='.urlencode($e->getMessage()));
}
