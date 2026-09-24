<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/product_catalog_importer.php';

$xlsxPath = $argv[1] ?? '/var/www/private/export-2026-06-22_21-26-36.xlsx';

$pdo = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

echo "=== SalesDrive Products Import ===\n";
echo "File: {$xlsxPath}\n";

$result = import_products_catalog_from_xlsx($pdo, $xlsxPath);

echo "Done.\n";
echo "Processed: {$result['processed']}\n";
echo "Skipped: {$result['skipped']}\n";
echo "Duplicate IDs in XLSX: {$result['duplicate_ids']}\n";
