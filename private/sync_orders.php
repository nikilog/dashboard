<?php
// Safety sync for orders changed while webhook/server was unavailable.
// Usage:
//   php sync_orders.php
//   php sync_orders.php 2026-08-01T00:00:00
// The date argument starts or resumes a catch-up window from that date.

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
$limit = 100;
$maxPagesPerRun = 10;
$minRequestIntervalSeconds = 120;
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
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
        throw new RuntimeException('Cannot save sync state');
    }
}

function sync_build_order_list_url($baseUrl, $from, $to, $page, $limit) {
    $params = [
        'page' => $page,
        'limit' => $limit,
        'filter[updateAt][from]' => $from,
        'filter[updateAt][to]' => $to,
        'filter[statusId]' => '__ALL__',
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

$requestedFrom = $argv[1] ?? null;
$requestedTo = $argv[2] ?? null;
$state = sync_load_state($stateFile);
$now = time();

if ($requestedFrom !== null) {
    $forcedFrom = sync_date($requestedFrom);
    if (!$forcedFrom) {
        fwrite(STDERR, "Bad from date: {$requestedFrom}\n");
        exit(1);
    }
    $forcedTo = $requestedTo !== null ? sync_date($requestedTo) : date('Y-m-d H:i:s', $now);
    if (!$forcedTo || $forcedFrom > $forcedTo) {
        fwrite(STDERR, "Bad or reversed to date\n");
        exit(1);
    }

    // Repeating the same command resumes its saved window. An earlier date
    // deliberately rewinds the window; a different later date cannot discard it.
    if (!empty($state['pending']) && is_array($state['pending'])) {
        $pendingFrom = $state['pending']['from'] ?? null;
        if ($forcedFrom > $pendingFrom) {
            fwrite(STDERR, "An earlier sync window is pending; run without dates to resume it\n");
            exit(1);
        }
        if ($forcedFrom < $pendingFrom || ($requestedTo !== null && $forcedTo !== ($state['pending']['to'] ?? null))) {
            $state['pending'] = ['from' => $forcedFrom, 'to' => $forcedTo, 'next_page' => 1];
            sync_save_state($stateFile, $state);
        }
    } else {
        $state['pending'] = ['from' => $forcedFrom, 'to' => $forcedTo, 'next_page' => 1];
        sync_save_state($stateFile, $state);
    }
} elseif (empty($state['pending']) || !is_array($state['pending'])) {
    $from = !empty($state['last_successful_sync_at'])
        ? date('Y-m-d H:i:s', strtotime($state['last_successful_sync_at']) - $overlapSeconds)
        : date('Y-m-d H:i:s', $now - $defaultLookbackSeconds);
    $state['pending'] = [
        'from' => $from,
        'to' => date('Y-m-d H:i:s', $now),
        'next_page' => 1,
    ];
    sync_save_state($stateFile, $state);
}

$from = $state['pending']['from'] ?? null;
$to = $state['pending']['to'] ?? null;
$page = (int)($state['pending']['next_page'] ?? 0);
if (!$from || !$to || $from > $to || $page < 1) {
    fwrite(STDERR, "Invalid pending sync window in state file\n");
    exit(1);
}

echo "--- [SalesDrive UpdateAt Sync] Start ---\n";
echo "Period: {$from} - {$to}; page {$page}\n";

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
$totalLoaded = 0;
$failed = false;
$completed = false;
$pagesProcessed = 0;

try {
    do {
        $lastRequestAt = (int)($state['last_order_list_request_at'] ?? 0);
        $waitSeconds = $minRequestIntervalSeconds - (time() - $lastRequestAt);
        if ($waitSeconds > 0) {
            echo "Waiting {$waitSeconds}s for SalesDrive request limit.\n";
            sleep($waitSeconds);
        }
        $state['last_order_list_request_at'] = time();
        sync_save_state($stateFile, $state);
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
        $pagesProcessed++;
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
        $state['pending']['next_page'] = $page;
        sync_save_state($stateFile, $state);
        if ($pagesProcessed >= $maxPagesPerRun) {
            echo "Page batch finished. Next run resumes at page {$page}.\n";
            break;
        }
    } while (true);
} catch (Throwable $e) {
    $failed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Sync failed: " . $e->getMessage() . "\n");
}

if (!$failed && $completed) {
    unset($state['pending']);
    $state['last_successful_sync_at'] = $to;
    $state['last_started_at'] = date('Y-m-d H:i:s', $now);
    $state['last_finished_at'] = date('Y-m-d H:i:s');
    $state['last_window_from'] = $from;
    $state['last_window_to'] = $to;
    $state['last_total_loaded'] = $totalLoaded;
    sync_save_state($stateFile, $state);
}

echo $completed
    ? "--- Complete. Synced this run: {$totalLoaded} ---\n"
    : "--- Paused. Synced this run: {$totalLoaded} ---\n";
exit($failed ? 1 : 0);

