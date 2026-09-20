<?php
// Backfills newly added order columns from orders.full_json.
// Usage: php backfill_orders_from_json.php [batch_size]

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '1G');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/salesdrive_order_mapper.php';

$batchSize = isset($argv[1]) ? max(1, (int)$argv[1]) : 500;

$columns = [
    'form_id',
    'version',
    'order_number',
    'payment_date',
    'cost_price_amount',
    'debt_amount',
    'stock_id',
    'mutual_settlement_id',
    'promo_code_id',
    'dispatch_term_id',
    'utm_page',
    'utm_source_full',
    'utm_content',
    'utm_term',
    'external_id',
    'delivery_provider',
    'delivery_tracking_number',
    'delivery_status_code',
    'delivery_delivered_at',
    'delivery_sender_id',
    'delivery_area_name',
    'delivery_region_name',
    'delivery_city_name',
    'delivery_city_ref',
    'delivery_settlement_ref',
    'delivery_branch_ref',
    'delivery_branch_number',
    'delivery_address',
    'delivery_payer',
    'delivery_has_postpay',
    'delivery_postpay_sum',
    'delivery_payment_method',
    'delivery_cargo_type',
];

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("DB connection error: " . $e->getMessage() . "\n");
}

$setSql = implode(', ', array_map(fn($c) => "`$c` = :$c", $columns));
$update = $pdo->prepare("UPDATE `orders` SET $setSql WHERE `id` = :id");
$select = $pdo->prepare("SELECT `id`, `full_json` FROM `orders` WHERE `id` > :last_id AND `full_json` IS NOT NULL ORDER BY `id` ASC LIMIT $batchSize");

$lastId = 0;
$seen = 0;
$updated = 0;
$skipped = 0;

echo "--- [SalesDrive Backfill] Start ---\n";

while (true) {
    $select->execute([':last_id' => $lastId]);
    $rows = $select->fetchAll();
    if (!$rows) break;

    $pdo->beginTransaction();

    foreach ($rows as $row) {
        $lastId = (int)$row['id'];
        $seen++;

        $ord = json_decode($row['full_json'], true);
        if (!is_array($ord)) {
            $skipped++;
            continue;
        }

        $mapped = salesdrive_map_order($ord);
        $params = [':id' => $lastId];
        foreach ($columns as $column) {
            $params[":$column"] = $mapped[$column] ?? null;
        }

        $update->execute($params);
        $updated++;
    }

    $pdo->commit();
    echo "Processed: $seen, updated: $updated, skipped: $skipped, last_id: $lastId\n";
}

echo "--- Done. Updated: $updated, skipped: $skipped ---\n";
