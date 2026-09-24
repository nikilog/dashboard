<?php
// Safety sync for orders changed while webhook/server was unavailable.
// Usage:
//   php sync_orders.php
//   php sync_orders.php 2026-07-01T00:00:00 2026-07-01T23:59:59

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '1G');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/salesdrive_order_mapper.php';

$stateFile = __DIR__ . '/sync_orders_state.json';
$limit = 41;
$overlapSeconds = 3600;
$defaultLookbackSeconds = 2 * 86400;

// A long catch-up run must not overlap with the next cron invocation.
$lockHandle = fopen(__DIR__ . '/sync_orders.lock', 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "Cannot open sync lock file\n");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Sync is already running.\n";
    exit(0);
}

function sync_date($value) {
    $ts = strtotime((string)$value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function sync_load_state($path) {
    if (!is_file($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function sync_save_state($path, array $state) {
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, $path);
}

function sync_build_order_list_url($baseUrl, $from, $to, $page, $limit) {
    $params = [
        'page' => $page,
        'limit' => $limit,
        'filter[updateAt][from]' => $from,
        'filter[updateAt][to]' => $to,
    ];

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = $key . '=' . rawurlencode((string)$value);
    }

    return rtrim($baseUrl, '/') . '/?' . implode('&', $pairs);
}

function sync_fetch_page($config, $from, $to, $page, $limit) {
    $url = sync_build_order_list_url($config['crm_api_base'], $from, $to, $page, $limit);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Form-Api-Key: ' . $config['crm_api_key'],
        'X-API-Key: ' . $config['crm_api_key'],
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $httpCode !== 200) {
        $bodyPreview = is_string($body) ? mb_substr(trim($body), 0, 1000) : '';
        throw new RuntimeException("SalesDrive API error HTTP {$httpCode}: {$error}; URL: {$url}; Body: {$bodyPreview}");
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('SalesDrive API returned invalid JSON');
    }

    return $json;
}

function sync_merge_with_existing(PDO $pdo, array $ord) {
    if (empty($ord['id'])) return $ord;

    $stmt = $pdo->prepare("SELECT full_json FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$ord['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $oldJson = [];
    if (!empty($row['full_json'])) {
        $decoded = json_decode($row['full_json'], true);
        if (is_array($decoded)) $oldJson = $decoded;
    }

    $merged = array_replace_recursive($oldJson, $ord);
    foreach (['products', 'contacts', 'ord_delivery_data'] as $listKey) {
        if (array_key_exists($listKey, $ord)) {
            $merged[$listKey] = $ord[$listKey];
        }
    }

    return $merged;
}

$manualFrom = $argv[1] ?? null;
$manualTo = $argv[2] ?? null;

$state = sync_load_state($stateFile);
$now = time();

if ($manualFrom) {
    $from = sync_date($manualFrom);
    if (!$from) die("Bad from date: {$manualFrom}\n");
} elseif (!empty($state['last_successful_sync_at'])) {
    $from = date('Y-m-d H:i:s', strtotime($state['last_successful_sync_at']) - $overlapSeconds);
} else {
    $from = date('Y-m-d H:i:s', $now - $defaultLookbackSeconds);
}

if ($manualTo) {
    $to = sync_date($manualTo);
    if (!$to) die("Bad to date: {$manualTo}\n");
} else {
    $to = date('Y-m-d H:i:s', $now);
}

if ($from > $to) {
    die("From date is after to date\n");
}

echo "--- [SalesDrive UpdateAt Sync] Start ---\n";
echo "Period: {$from} - {$to}\n";

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

$stmt = null;
$page = 1;
$totalLoaded = 0;
$failed = false;
$completed = false;

try {
    do {
        $json = sync_fetch_page($config, $from, $to, $page, $limit);

        if (!isset($json['data']) || !is_array($json['data'])) {
            throw new RuntimeException("SalesDrive API response has no data array on page {$page}");
        }

        if (empty($json['data'])) {
            $pageCount = $json['pagination']['pageCount'] ?? null;
            if ($pageCount !== null && $page < (int)$pageCount) {
                throw new RuntimeException("SalesDrive API returned an empty page {$page} before page {$pageCount}");
            }
            echo "No data on page {$page}.\n";
            $completed = true;
            break;
        }

        $pdo->beginTransaction();
        $countOnPage = 0;

        foreach ($json['data'] as $ord) {
            if (!is_array($ord) || empty($ord['id'])) continue;

            $merged = sync_merge_with_existing($pdo, $ord);
            $mapped = salesdrive_map_order($merged);

            if ($stmt === null) {
                $stmt = $pdo->prepare(salesdrive_build_upsert_sql('orders', array_keys($mapped)));
            }

            $stmt->execute(salesdrive_bind_params($mapped));
            $countOnPage++;
            $totalLoaded++;
        }

        $pdo->commit();
        echo "Page {$page} synced ({$countOnPage} items). Total: {$totalLoaded}\n";

        $pageCount = $json['pagination']['pageCount'] ?? null;
        if (($pageCount !== null && $page >= (int)$pageCount)
            || ($pageCount === null && count($json['data']) < $limit)) {
            $completed = true;
            break;
        }

        $page++;
        if ($page > 10000) {
            throw new RuntimeException('SalesDrive API pagination did not finish after 10000 pages');
        }
        usleep(200000);
    } while (true);
} catch (Throwable $e) {
    $failed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Sync failed: " . $e->getMessage() . "\n");
}

if (!$failed && $completed && !$manualFrom) {
    sync_save_state($stateFile, [
        'last_successful_sync_at' => $to,
        'last_started_at' => date('Y-m-d H:i:s', $now),
        'last_finished_at' => date('Y-m-d H:i:s'),
        'last_window_from' => $from,
        'last_window_to' => $to,
        'last_total_loaded' => $totalLoaded,
    ]);
}

echo "--- Done. Synced: {$totalLoaded} ---\n";
exit($failed ? 1 : 0);

