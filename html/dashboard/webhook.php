<?php
// webhook.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/webhook_error.log');

$configPath = '/var/www/private/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    die('Config not found');
}
$config = require $configPath;

$mapperPath = '/var/www/private/salesdrive_order_mapper.php';
if (!file_exists($mapperPath)) {
    http_response_code(500);
    die('Mapper not found');
}
require_once $mapperPath;

$input = file_get_contents('php://input');
$json = json_decode($input, true);

file_put_contents(
    __DIR__ . '/webhook_requests.log',
    "[" . date('Y-m-d H:i:s') . "] Input: " . substr($input, 0, 150) . "...\n",
    FILE_APPEND
);

if (!isset($json['data']['id']) || !is_array($json['data'])) {
    http_response_code(400);
    die('Bad Request: No ID found');
}

$ord = $json['data'];

try {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $oldJson = [];
    $oldStmt = $pdo->prepare("SELECT full_json FROM orders WHERE id = ? LIMIT 1");
    $oldStmt->execute([$ord['id']]);
    $oldRow = $oldStmt->fetch();
    if (!empty($oldRow['full_json'])) {
        $decoded = json_decode($oldRow['full_json'], true);
        if (is_array($decoded)) {
            $oldJson = $decoded;
        }
    }

    $mergedOrder = array_replace_recursive($oldJson, $ord);
    foreach (['products', 'contacts', 'ord_delivery_data'] as $listKey) {
        if (array_key_exists($listKey, $ord)) {
            $mergedOrder[$listKey] = $ord[$listKey];
        }
    }

    $mapped = salesdrive_map_order($mergedOrder);
    $stmt = $pdo->prepare(salesdrive_build_upsert_sql('orders', array_keys($mapped)));
    $stmt->execute(salesdrive_bind_params($mapped));

    if (!empty($json['meta']['fields'])) {
        updateFieldOptions($pdo, $json['meta']['fields']);
    }

    echo "OK. Order {$ord['id']} updated.";
} catch (Exception $e) {
    http_response_code(500);
    file_put_contents(
        __DIR__ . '/webhook_error.log',
        "[" . date('Y-m-d H:i:s') . "] DB Error: " . $e->getMessage() . "\n",
        FILE_APPEND
    );
    echo "Error";
}

function updateFieldOptions($pdo, $fields) {
    $insertSql = "INSERT INTO field_options (field_name, option_id, option_label, option_color)
                  VALUES (:field_name, :option_id, :option_label, :option_color)
                  ON DUPLICATE KEY UPDATE
                    option_label = VALUES(option_label),
                    option_color = VALUES(option_color)";

    $stmt = $pdo->prepare($insertSql);

    foreach ($fields as $fieldName => $fieldData) {
        if (!is_array($fieldData)) continue;

        $options = [];
        if (isset($fieldData['options']) && is_array($fieldData['options'])) {
            $options = $fieldData['options'];
        } elseif (isset($fieldData['id']) && isset($fieldData['name'])) {
            $options = [$fieldData];
        } elseif (isset($fieldData['fields'])) {
            updateFieldOptions($pdo, $fieldData['fields']);
            continue;
        } else {
            $keys = array_keys($fieldData);
            if (!empty($keys) && is_numeric($keys[0])) {
                $options = $fieldData;
            } else {
                continue;
            }
        }

        foreach ($options as $option) {
            if (!is_array($option)) continue;

            $optionId = $option['id'] ?? $option['value'] ?? null;
            $optionLabel = $option['name'] ?? $option['label'] ?? $option['nameUa'] ?? '';
            $optionColor = $option['color'] ?? null;

            if ($optionId === null || $optionLabel === '') continue;

            $stmt->execute([
                ':field_name' => $fieldName,
                ':option_id' => (int)$optionId,
                ':option_label' => $optionLabel,
                ':option_color' => $optionColor,
            ]);
        }
    }
}
