<?php
// Run: php /var/www/html/dashboard/analyze_orders.php

if (php_sapi_name() !== 'cli') {
    set_time_limit(600);
    ini_set('memory_limit', '1G');
}

$config = require '/var/www/private/config.php';
$outFile = '/var/www/private/analytics_ready.json';

function db_conn(array $config): PDO
{
    return new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function product_text($value): string
{
    return trim((string)($value ?? ''));
}

function category_pair(?string $categoryPath, ?string $categoryName): array
{
    $parts = [];
    foreach (explode('>>', (string)$categoryPath) as $part) {
        $part = trim($part);
        if ($part !== '') $parts[] = $part;
    }

    // SalesDrive export often stores the root/source as the first path segment.
    if (count($parts) > 1) {
        array_shift($parts);
    }

    if (!$parts && $categoryName) {
        $parts[] = trim($categoryName);
    }

    if (!$parts) {
        return ['Невизначено', 'Невизначено'];
    }

    return [$parts[0], $parts[1] ?? $parts[0]];
}

function load_products_catalog(PDO $pdo): array
{
    $byId = [];
    $bySku = [];

    try {
        $stmt = $pdo->query("SELECT product_id, sku, name, document_name, supplier, manufacturer,
                                    category_id, category_name, category_path, price, discount_price,
                                    cost_price, image_url
                             FROM products_catalog");
    } catch (Throwable $e) {
        echo "Products catalog unavailable: " . $e->getMessage() . "\n";
        return ['by_id' => [], 'by_sku' => []];
    }

    while ($row = $stmt->fetch()) {
        $productId = product_text($row['product_id'] ?? '');
        if ($productId !== '') {
            $byId[$productId] = $row;
        }

        $sku = product_text($row['sku'] ?? '');
        if ($sku !== '' && !isset($bySku[$sku])) {
            $bySku[$sku] = $row;
        }
    }

    return ['by_id' => $byId, 'by_sku' => $bySku];
}

echo "1. Loading products_catalog...\n";

try {
    $pdo = db_conn($config);
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage() . "\n");
}

$catalog = load_products_catalog($pdo);
echo "Products by ID: " . count($catalog['by_id']) . "\n";
echo "Products by SKU: " . count($catalog['by_sku']) . "\n";

echo "2. Processing orders...\n";

$sql = "SELECT order_time, update_at, full_json, status_id
        FROM orders
        WHERE order_time > '2024-01-01 00:00:00'
           OR update_at > '2024-01-01 00:00:00'";
$stmt = $pdo->query($sql);

$analyzedData = [];
$count = 0;
$matchedById = 0;
$matchedBySku = 0;
$notMatched = 0;

while ($row = $stmt->fetch()) {
    $json = json_decode((string)$row['full_json'], true);
    if (empty($json['products']) || !is_array($json['products'])) continue;

    $dateCreated = substr((string)$row['order_time'], 0, 10);
    $dateSold = substr((string)($row['update_at'] ?: $row['order_time']), 0, 10);
    $statusId = (int)$row['status_id'];

    foreach ($json['products'] as $p) {
        $productId = product_text($p['productId'] ?? $p['id'] ?? '');
        $sku = product_text($p['sku'] ?? '');

        $catalogRow = null;
        if ($productId !== '' && isset($catalog['by_id'][$productId])) {
            $catalogRow = $catalog['by_id'][$productId];
            $matchedById++;
        } elseif ($sku !== '' && isset($catalog['by_sku'][$sku])) {
            $catalogRow = $catalog['by_sku'][$sku];
            $matchedBySku++;
        } else {
            $notMatched++;
        }

        $name = product_text($p['documentName'] ?? '') ?: product_text($p['text'] ?? '') ?: product_text($p['name'] ?? '');
        $supplier = 'Невизначено';
        [$category, $subcategory] = ['Невизначено', 'Невизначено'];

        if ($catalogRow) {
            $catalogName = product_text($catalogRow['document_name'] ?? '') ?: product_text($catalogRow['name'] ?? '');
            if ($catalogName !== '') $name = $catalogName;
            $supplier = product_text($catalogRow['supplier'] ?? '') ?: 'Невизначено';
            [$category, $subcategory] = category_pair($catalogRow['category_path'] ?? null, $catalogRow['category_name'] ?? null);
        }

        if ($name === '') $name = $productId !== '' ? $productId : ($sku !== '' ? $sku : 'Невідомий товар');

        $qty = (float)($p['amount'] ?? 0);
        if ($qty <= 0) $qty = 1;

        // Financial facts come from the order, not from the current catalog.
        $price = (float)($p['price'] ?? 0);
        $cost = (float)($p['costPrice'] ?? 0);

        $analyzedData[] = [
            'd' => $dateCreated,
            'sd' => $dateSold,
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

    $count++;
    if ($count % 1000 === 0) echo "   Orders: $count\n";
}

file_put_contents($outFile, json_encode($analyzedData, JSON_UNESCAPED_UNICODE));
chmod($outFile, 0666);

echo "Done! Total items: " . count($analyzedData) . "\n";
echo "Matched by product_id: {$matchedById}\n";
echo "Matched by sku: {$matchedBySku}\n";
echo "Not matched: {$notMatched}\n";
