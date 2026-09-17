<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!auth_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

incident_check_throttled(60);

$period = pmg_validate_period($_GET['period'] ?? null);

try {
    $data = pmg_collect_metrics($period);
    $data['incidents_active'] = db_incident_count_active();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
