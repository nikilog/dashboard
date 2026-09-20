<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

$config = require '/var/www/private/config.php';

function out_json($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function text_value($value): string
{
    return trim((string)($value ?? ''));
}

function date_param(string $name, string $fallback): string
{
    $value = $_GET[$name] ?? $fallback;
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function strip_manufacturer_suffix(string $label, ?string $manufacturer): string
{
    $label = trim($label);
    $manufacturer = trim((string)$manufacturer);
    if ($label === '') return 'Невизначено';

    $suffixes = [];
    if ($manufacturer !== '') $suffixes[] = preg_quote($manufacturer, '/');

    $suffixes = array_merge($suffixes, [
        'ML', 'FM', 'VD', 'VS', 'MB', 'МБ', 'СД', 'IFL', 'IFT', 'FS', 'MF', 'MH',
        'LION\s*\(TOP\)', 'LION',
    ]);

    foreach ($suffixes as $suffix) {
        $next = preg_replace('/\s+' . $suffix . '$/iu', '', $label);
        if (is_string($next) && $next !== $label && trim($next) !== '') {
            return trim($next);
        }
    }

    return $label;
}

function category_parts(?string $path, ?string $name, ?string $manufacturer): array
{
    $parts = [];
    foreach (explode('>>', (string)$path) as $part) {
        $part = trim($part);
        if ($part !== '') $parts[] = $part;
    }

    // SalesDrive export often has shop/root as the first segment.
    if (count($parts) > 1) array_shift($parts);
    if (!$parts && $name) $parts[] = trim($name);

    $subcategory = null;
    if (count($parts) >= 2) {
        $category = $parts[0];
        $subcategory = $parts[1];
    } else {
        $subcategory = $parts[0] ?? null;
        $category = strip_manufacturer_suffix((string)($subcategory ?: $name ?: ''), $manufacturer);
    }

    if ($category === '' || $category === null) $category = 'Невизначено';
    if ($subcategory === null || $subcategory === '' || $subcategory === $category) {
        $subcategory = null;
    }

    return [$category, $subcategory];
}

function load_catalog(PDO $pdo): array
{
    $byId = [];
    $bySku = [];
    try {
        $stmt = $pdo->query("SELECT product_id, sku, name, document_name, supplier, manufacturer,
                                    category_name, category_path
                             FROM products_catalog");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $productId = text_value($row['product_id'] ?? '');
            if ($productId !== '') $byId[$productId] = $row;

            $sku = text_value($row['sku'] ?? '');
            if ($sku !== '' && !isset($bySku[$sku])) $bySku[$sku] = $row;
        }
    } catch (Throwable $e) {
        // Fallback to order data if catalog is temporarily unavailable.
    }
    return ['by_id' => $byId, 'by_sku' => $bySku];
}

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

    $successStatuses = array_map('intval', $config['success_statuses'] ?? [5, 11, 18]);
    $from = date_param('from', date('Y-m-01'));
    $to = date_param('to', date('Y-m-d'));
    $bind = ($_GET['bind'] ?? 'created') === 'sold' ? 'sold' : 'created';
    $dateColumn = $bind === 'sold' ? 'update_at' : 'order_time';

    $catalog = load_catalog($pdo);

    $where = "DATE(`{$dateColumn}`) BETWEEN :from_date AND :to_date
              AND full_json IS NOT NULL";
    $params = [
        ':from_date' => $from,
        ':to_date' => $to,
    ];

    if ($bind === 'sold') {
        if (!$successStatuses) out_json([]);
        $placeholders = [];
        foreach ($successStatuses as $i => $statusId) {
            $key = ':status_' . $i;
            $placeholders[] = $key;
            $params[$key] = $statusId;
        }
        $where .= " AND status_id IN (" . implode(',', $placeholders) . ")";
    }

    $stmt = $pdo->prepare("SELECT order_time, update_at, status_id, full_json
                           FROM orders
                           WHERE {$where}
                           ORDER BY `{$dateColumn}` DESC");
    $stmt->execute($params);

    $output = [];
    while ($row = $stmt->fetch()) {
        $json = json_decode((string)$row['full_json'], true);
        if (empty($json['products']) || !is_array($json['products'])) continue;

        $createdDate = substr((string)$row['order_time'], 0, 10);
        $soldDate = substr((string)($row['update_at'] ?: $row['order_time']), 0, 10);
        $statusId = (int)$row['status_id'];

        foreach ($json['products'] as $product) {
            $productId = text_value($product['productId'] ?? $product['id'] ?? '');
            $sku = text_value($product['sku'] ?? '');

            $catalogRow = null;
            if ($productId !== '' && isset($catalog['by_id'][$productId])) {
                $catalogRow = $catalog['by_id'][$productId];
            } elseif ($sku !== '' && isset($catalog['by_sku'][$sku])) {
                $catalogRow = $catalog['by_sku'][$sku];
            }

            $name = text_value($product['documentName'] ?? '')
                ?: text_value($product['text'] ?? '')
                ?: text_value($product['name'] ?? '');
            $supplier = 'Невизначено';
            [$category, $subcategory] = ['Невизначено', null];

            if ($catalogRow) {
                $catalogName = text_value($catalogRow['document_name'] ?? '') ?: text_value($catalogRow['name'] ?? '');
                if ($catalogName !== '') $name = $catalogName;
                $supplier = text_value($catalogRow['supplier'] ?? '') ?: 'Невизначено';
                [$category, $subcategory] = category_parts(
                    $catalogRow['category_path'] ?? null,
                    $catalogRow['category_name'] ?? null,
                    $catalogRow['manufacturer'] ?? null
                );
            }

            if ($name === '') $name = $productId !== '' ? $productId : ($sku !== '' ? $sku : 'Невідомий товар');

            $qty = (float)($product['amount'] ?? 0);
            if ($qty <= 0) $qty = 1;
            $price = (float)($product['price'] ?? 0);
            $cost = (float)($product['costPrice'] ?? 0);

            $output[] = [
                'd' => $createdDate,
                'sd' => $soldDate,
                'st' => $statusId,
                'pid' => $productId,
                'sku' => $sku,
                'n' => $name,
                'c' => $category,
                's' => $subcategory,
                'v' => $supplier,
                't' => $price * $qty,
                'p' => ($price - $cost) * $qty,
                'q' => $qty,
            ];
        }
    }

    out_json($output);
} catch (Throwable $e) {
    out_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
