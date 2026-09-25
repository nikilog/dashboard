<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$configPath = '/var/www/private/config.php';
$mapperPath = '/var/www/private/salesdrive_order_mapper.php';
$syncStatePath = '/var/www/private/sync_orders_state.json';

if (!file_exists($configPath) || !file_exists($mapperPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Config or mapper not found']);
    exit;
}

$cfg = require $configPath;
require_once $mapperPath;

$limit = isset($_GET['limit']) ? max(1, min(2000, (int)$_GET['limit'])) : 500;
$fieldOptionsMaxAgeHours = isset($_GET['field_options_max_age_hours'])
    ? max(1, (int)$_GET['field_options_max_age_hours'])
    : 24;
$syncMaxAgeHours = isset($_GET['sync_max_age_hours'])
    ? max(1, (int)$_GET['sync_max_age_hours'])
    : 2;

function addAlert(&$alerts, $type, $path, $message, $orderId = null, $value = null) {
    $key = $type . ':' . $path . ':' . $message;
    if (!isset($alerts[$key])) {
        $alerts[$key] = [
            'type' => $type,
            'path' => $path,
            'message' => $message,
            'count' => 0,
            'orders' => [],
            'sample' => null,
        ];
    }

    $alerts[$key]['count']++;
    if ($orderId !== null && count($alerts[$key]['orders']) < 5) {
        $alerts[$key]['orders'][] = (int)$orderId;
    }
    if ($alerts[$key]['sample'] === null && $value !== null && !is_array($value) && !is_object($value)) {
        $alerts[$key]['sample'] = mb_substr((string)$value, 0, 160);
    }
}

function checkUnknownKeys(&$alerts, $prefix, $data, $known, $orderId) {
    if (!is_array($data)) return;
    foreach ($data as $key => $value) {
        if (!isset($known[$key])) {
            addAlert(
                $alerts,
                'unknown_json_field',
                $prefix === '' ? (string)$key : $prefix . '.' . $key,
                'SalesDrive sent a field not listed in diagnostics; the raw JSON is still stored',
                $orderId,
                $value
            );
        }
    }
}

try {
    $pdo = new PDO(
        "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
        $cfg['db_user'],
        $cfg['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $alerts = [];
    $checked = 0;

    $syncState = [];
    if (is_file($syncStatePath)) {
        $decodedSyncState = json_decode(file_get_contents($syncStatePath), true);
        if (is_array($decodedSyncState)) $syncState = $decodedSyncState;
    }
    $lastSync = $syncState['last_successful_sync_at'] ?? null;
    $syncAgeHours = null;
    if (!$lastSync) {
        addAlert(
            $alerts,
            'sync_never_ran',
            'sync_orders_state',
            'UpdateAt safety sync has not completed yet'
        );
    } else {
        $syncAgeHours = (time() - strtotime($lastSync)) / 3600;
        if ($syncAgeHours > $syncMaxAgeHours) {
            addAlert(
                $alerts,
                'sync_stale',
                'sync_orders_state.last_successful_sync_at',
                'UpdateAt safety sync is older than ' . $syncMaxAgeHours . ' hours',
                null,
                $lastSync
            );
        }
    }

    $schemaStmt = $pdo->query("SHOW COLUMNS FROM `orders`");
    $dbColumns = [];
    while ($row = $schemaStmt->fetch()) {
        $dbColumns[$row['Field']] = true;
    }

    foreach (salesdrive_order_columns() as $column) {
        if (!isset($dbColumns[$column])) {
            addAlert(
                $alerts,
                'missing_db_column',
                $column,
                'Mapper writes this column, but orders table does not have it'
            );
        }
    }

    $freshStmt = $pdo->query("SELECT MAX(updated_at) AS last_update, COUNT(*) AS total FROM field_options");
    $fresh = $freshStmt->fetch() ?: ['last_update' => null, 'total' => 0];
    $lastOptionsUpdate = $fresh['last_update'] ?? null;
    $fieldOptionsAgeHours = null;

    if (empty($fresh['total'])) {
        addAlert($alerts, 'field_options_empty', 'field_options', 'field_options table is empty');
    } elseif ($lastOptionsUpdate) {
        $fieldOptionsAgeHours = (time() - strtotime($lastOptionsUpdate)) / 3600;
        if ($fieldOptionsAgeHours > $fieldOptionsMaxAgeHours) {
            addAlert(
                $alerts,
                'field_options_stale',
                'field_options.updated_at',
                'field_options are older than ' . $fieldOptionsMaxAgeHours . ' hours',
                null,
                $lastOptionsUpdate
            );
        }
    }

    $idChecks = [
        ['column' => 'status_id', 'field' => 'statusId'],
        ['column' => 'manager_id', 'field' => 'userId'],
        ['column' => 'site_id', 'field' => 'sajt'],
        ['column' => 'shipping_method_id', 'field' => 'shipping_method'],
        ['column' => 'payment_method_id', 'field' => 'payment_method'],
        ['column' => 'organization_id', 'field' => 'organizationId'],
        ['column' => 'supplier_id', 'field' => 'postacalnik'],
        ['column' => 'campaign_id', 'field' => 'campaignId'],
        ['column' => 'rejection_reason_id', 'field' => 'rejectionReason'],
        ['column' => 'stock_id', 'field' => 'stockId'],
        ['column' => 'mutual_settlement_id', 'field' => 'vzaemozalik'],
        ['column' => 'promo_code_id', 'field' => 'promokod'],
        ['column' => 'dispatch_term_id', 'field' => 'terminiVidpravlenna'],
    ];

    foreach ($idChecks as $check) {
        if (!isset($dbColumns[$check['column']])) continue;

        $sql = "SELECT o.`{$check['column']}` AS option_id, COUNT(*) AS cnt
                FROM orders o
                LEFT JOIN field_options fo
                  ON fo.field_name = :field_name
                 AND fo.option_id = o.`{$check['column']}`
                WHERE o.`{$check['column']}` IS NOT NULL
                  AND o.`{$check['column']}` <> 0
                  AND fo.option_id IS NULL
                GROUP BY o.`{$check['column']}`
                ORDER BY cnt DESC
                LIMIT 20";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':field_name' => $check['field']]);

        while ($row = $stmt->fetch()) {
            addAlert(
                $alerts,
                'missing_option_label',
                $check['field'] . ':' . $row['option_id'],
                'Orders contain this option ID, but field_options has no label for it',
                null,
                'orders: ' . $row['cnt']
            );
        }
    }

    $knownTop = array_fill_keys(salesdrive_known_top_level_keys(), true);
    $knownDelivery = array_fill_keys(salesdrive_known_delivery_keys(), true);
    $knownProduct = array_fill_keys(salesdrive_known_product_keys(), true);
    $knownContact = array_fill_keys(salesdrive_known_contact_keys(), true);

    $stmt = $pdo->query(
        "SELECT id, full_json
         FROM orders
         WHERE full_json IS NOT NULL
         ORDER BY COALESCE(update_at, order_time) DESC, id DESC
         LIMIT " . (int)$limit
    );

    while ($row = $stmt->fetch()) {
        $checked++;
        $json = json_decode($row['full_json'], true);
        if (!is_array($json)) continue;

        checkUnknownKeys($alerts, '', $json, $knownTop, $row['id']);

        if (!empty($json['ord_delivery_data']) && is_array($json['ord_delivery_data'])) {
            foreach ($json['ord_delivery_data'] as $deliveryRow) {
                checkUnknownKeys($alerts, 'ord_delivery_data[]', $deliveryRow, $knownDelivery, $row['id']);
            }
        }

        foreach (['ord_novaposhta', 'ord_ukrposhta', 'ord_meest', 'ord_justin', 'ord_rozetka_delivery', 'ord_delivery'] as $deliveryKey) {
            if (!empty($json[$deliveryKey]) && is_array($json[$deliveryKey])) {
                checkUnknownKeys($alerts, $deliveryKey, $json[$deliveryKey], $knownDelivery, $row['id']);
            }
        }

        if (!empty($json['products']) && is_array($json['products'])) {
            foreach ($json['products'] as $product) {
                checkUnknownKeys($alerts, 'products[]', $product, $knownProduct, $row['id']);
            }
        }

        if (!empty($json['primaryContact']) && is_array($json['primaryContact'])) {
            checkUnknownKeys($alerts, 'primaryContact', $json['primaryContact'], $knownContact, $row['id']);
        }

        if (!empty($json['contacts']) && is_array($json['contacts'])) {
            foreach ($json['contacts'] as $contact) {
                checkUnknownKeys($alerts, 'contacts[]', $contact, $knownContact, $row['id']);
            }
        }
    }

    $alerts = array_values($alerts);
    usort($alerts, function($a, $b) {
        if ($a['type'] !== $b['type']) return strcmp($a['type'], $b['type']);
        return $b['count'] <=> $a['count'];
    });

    echo json_encode([
        'ok' => true,
        'checked' => $checked,
        'field_options_last_update' => $lastOptionsUpdate,
        'field_options_age_hours' => $fieldOptionsAgeHours,
        'sync_last_successful_at' => $lastSync,
        'sync_age_hours' => $syncAgeHours,
        'alerts' => $alerts,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
