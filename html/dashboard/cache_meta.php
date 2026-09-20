<?php
/**
 * cache_meta.php - Скрипт для оновлення мета-даних (опцій полів) з SalesDrive API
 * 
 * Використання:
 *   CLI: php cache_meta.php
 *   Web: https://your-domain/dashboard/cache_meta.php?key=YOUR_SECRET_KEY
 * 
 * Cron (кожні 12 годин):
 *   0 0,12 * * * php /var/www/html/dashboard/cache_meta.php
 */

// Secret key для доступу через web
define('SECRET_KEY', 'meta_update_2024'); // Змініть на свій!

// Дозволити тільки з CLI або з правильним ключем
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    if ($key !== SECRET_KEY) {
        http_response_code(403);
        die('Access denied');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$configPath = '/var/www/private/config.php';
if (!file_exists($configPath)) {
    die('Config not found');
}
$config = require $configPath;

$apiUrl = $config['crm_api_base'] ?? '';
$apiKey = $config['crm_api_key'] ?? '';

if (!$apiUrl || !$apiKey) {
    die('API credentials not configured');
}

echo "=== SalesDrive Meta Data Update ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $baseUrl = salesdriveBaseUrl($apiUrl);
    $totalOptions = 0;

    $sources = [
        'payment_method' => '/api/payment-methods/',
        'shipping_method' => '/api/delivery-methods/',
        'statusId' => '/api/statuses/',
    ];

    foreach ($sources as $fieldName => $path) {
        $url = rtrim($baseUrl, '/') . $path;

        echo "Fetching {$fieldName} from {$path}...\n";
        $response = fetchWithRetry($url, $apiKey);
        if (!$response) {
            echo "Failed to fetch {$fieldName}\n";
            continue;
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            echo "Invalid JSON for {$fieldName}\n";
            continue;
        }

        $options = extractOptionList($json);
        $saved = saveOptions($pdo, $fieldName, $options);
        $totalOptions += $saved;

        echo "Field '{$fieldName}': {$saved} options saved\n";
    }
    
    echo "\n=== Update Complete ===\n";
    echo "Total options saved: {$totalOptions}\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}

/**
 * Fetch з retry логікою
 */
function salesdriveBaseUrl($apiUrl) {
    $parts = parse_url($apiUrl);
    if (!empty($parts['scheme']) && !empty($parts['host'])) {
        return $parts['scheme'] . '://' . $parts['host'];
    }
    return rtrim($apiUrl, '/');
}

function fetchWithRetry($url, $apiKey, $maxRetries = 3) {
    $separator = strpos($url, '?') === false ? '?' : '&';
    $urlWithKey = $url . $separator . http_build_query(['publicKey' => $apiKey]);

    for ($i = 0; $i < $maxRetries; $i++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlWithKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Form-Api-Key: ' . $apiKey,
            'X-API-Key: ' . $apiKey,
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response && $httpCode === 200) {
            return $response;
        }
        
        echo "Attempt " . ($i + 1) . " failed (HTTP {$httpCode}). Retrying...\n";
        sleep(2);
    }
    
    return null;
}

/**
 * Збереження опцій в БД
 */
function saveOptions($pdo, $fieldName, $options) {
    $insertSql = "INSERT INTO field_options (field_name, option_id, option_label, option_color) 
                  VALUES (:field_name, :option_id, :option_label, :option_color)
                  ON DUPLICATE KEY UPDATE 
                    option_label = VALUES(option_label),
                    option_color = VALUES(option_color),
                    updated_at = CURRENT_TIMESTAMP";
    
    $stmt = $pdo->prepare($insertSql);
    $totalOptions = 0;
    
    foreach ($options as $option) {
        if (!is_array($option)) continue;

        $optionId = $option['id'] ?? $option['value'] ?? $option['key'] ?? null;
        $optionLabel = $option['name'] ?? $option['label'] ?? $option['nameUa'] ?? $option['title'] ?? $option['text'] ?? '';
        $optionColor = $option['color'] ?? $option['backgroundColor'] ?? null;

        if ($optionId === null || $optionLabel === '') continue;

        $stmt->execute([
            ':field_name' => $fieldName,
            ':option_id' => (int)$optionId,
            ':option_label' => trim($optionLabel),
            ':option_color' => $optionColor
        ]);

        $totalOptions++;
    }
    
    return $totalOptions;
}

/**
 * Видобуває список опцій з відповіді SalesDrive.
 */
function extractOptionList($json) {
    if (!is_array($json)) return [];

    foreach (['data', 'items', 'paymentMethods', 'deliveryMethods', 'statuses', 'result'] as $key) {
        if (isset($json[$key]) && is_array($json[$key])) {
            return normalizeOptionList($json[$key]);
        }
    }

    return normalizeOptionList($json);
}

function normalizeOptionList($items) {
    if (!is_array($items)) return [];

    $options = [];
    foreach ($items as $key => $value) {
        if (is_array($value)) {
            if (!isset($value['id']) && !is_int($key) && ctype_digit((string)$key)) {
                $value['id'] = $key;
            }
            $options[] = $value;
        } elseif ($value !== null && $value !== '') {
            $options[] = [
                'id' => $key,
                'name' => (string)$value,
            ];
        }
    }

    return $options;
}
