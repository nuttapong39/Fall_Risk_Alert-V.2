<?php
require_once 'db.php';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="covid_log.csv"');

$stmt = $conn->query("SELECT * FROM covid_notify_log ORDER BY sent_at DESC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$output = fopen("php://output", "w");
fputcsv($output, array_keys($rows[0]));
foreach ($rows as $row) {
    fputcsv($output, $row);
}
fclose($output);
exit;
