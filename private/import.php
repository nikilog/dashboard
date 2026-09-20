<?php
// api/import.php
set_time_limit(0);
ini_set('display_errors', 1);
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/salesdrive_order_mapper.php';

echo "--- [SalesDrive Import] Start ---\n";
echo "Connecting to database ({$config['db_host']})...\n";

try {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    echo "DB Connected successfully.\n";
} catch (PDOException $e) {
    die("Error: Connection failed: " . $e->getMessage() . "\n");
}

$from = $argv[1] ?? date('Y-m-01');
$to = $argv[2] ?? date('Y-m-d');

echo "Period: $from - $to\n";

$limit = 50;
$page = 1;
$totalLoaded = 0;
$stmt = null;

function import_build_order_list_url($baseUrl, $from, $to, $page, $limit) {
    $params = [
        'page' => $page,
        'limit' => $limit,
        'filter[orderTime][from]' => $from,
        'filter[orderTime][to]' => $to,
    ];

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = $key . '=' . rawurlencode((string)$value);
    }

    return rtrim($baseUrl, '/') . '/?' . implode('&', $pairs);
}

do {
    $url = import_build_order_list_url($config['crm_api_base'], $from, $to, $page, $limit);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Form-Api-Key: ' . $config['crm_api_key'],
        'X-API-Key: ' . $config['crm_api_key'],
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "API Error [$httpCode]: " . mb_substr(trim((string)$res), 0, 1000) . "\n";
        echo "URL: {$url}\n";
        echo "Waiting 30 sec...\n";
        sleep(30);
        continue;
    }

    $json = json_decode($res, true);
    if (empty($json['data'])) {
        echo "No more data on page $page. Import finished.\n";
        break;
    }

    $pdo->beginTransaction();
    $countOnPage = 0;

    foreach ($json['data'] as $ord) {
        $mapped = salesdrive_map_order($ord);
        if ($stmt === null) {
            $sql = salesdrive_build_upsert_sql('orders', array_keys($mapped));
            $stmt = $pdo->prepare($sql);
        }

        try {
            $stmt->execute(salesdrive_bind_params($mapped));
            $countOnPage++;
            $totalLoaded++;
        } catch (Exception $e) {
            $orderId = $ord['id'] ?? 'unknown';
            echo "Warning: Order {$orderId} failed: " . $e->getMessage() . "\n";
        }
    }

    $pdo->commit();
    echo "Page $page loaded ($countOnPage items). Total: $totalLoaded\n";

    if ($page >= ($json['pagination']['pageCount'] ?? 1)) {
        echo "All pages processed.\n";
        break;
    }

    $page++;
    usleep(200000);
} while (true);

$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

echo "--- Done. Total imported: $totalLoaded ---\n";
