<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
try {
    db()->query('SELECT 1');
    ensure_session_table();
    ensure_v060_schema();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'version'=>app_version(),'time'=>time()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false]);
}
