<?php

use PhpOffice\PhpSpreadsheet\IOFactory;

function product_catalog_required_headers(): array
{
    return [
        'ID товару/послуги',
        'Товар/Послуга',
        'Назва для документів',
        'Постачальник',
        'Виробник',
        'SKU',
        'Ціна',
        'Ціна зі знижкою',
        'Собівартість',
        'ID категорії',
        'Категорія',
        'Структура категорій',
        'Зображення',
    ];
}

function product_catalog_cell($row, array $columns, string $name)
{
    if (!isset($columns[$name])) return null;
    return $row[$columns[$name]] ?? null;
}

function product_catalog_text($value): ?string
{
    if ($value === null) return null;
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function product_catalog_money($value): ?float
{
    if ($value === null || $value === '') return null;
    if (is_string($value)) {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
    }
    if (!is_numeric($value)) return null;
    return round((float)$value, 2);
}

function import_products_catalog_from_xlsx(PDO $pdo, string $xlsxPath): array
{
    if (!is_file($xlsxPath)) {
        throw new RuntimeException('XLSX file not found: ' . $xlsxPath);
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (!class_exists(IOFactory::class)) {
        throw new RuntimeException('PhpSpreadsheet is not installed. Run composer install in /var/www/private.');
    }

    $reader = IOFactory::createReaderForFile($xlsxPath);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($xlsxPath);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);
    if (!$rows || count($rows) < 2) {
        throw new RuntimeException('XLSX file is empty.');
    }

    $header = array_shift($rows);
    $columns = [];
    foreach ($header as $col => $label) {
        $label = trim((string)$label);
        if ($label !== '') {
            $columns[$label] = $col;
        }
    }

    $missingHeaders = [];
    foreach (product_catalog_required_headers() as $headerName) {
        if (!isset($columns[$headerName])) {
            $missingHeaders[] = $headerName;
        }
    }
    if ($missingHeaders) {
        throw new RuntimeException('Missing XLSX columns: ' . implode(', ', $missingHeaders));
    }

    $hasProducts = false;
    foreach ($rows as $row) {
        if (product_catalog_text(product_catalog_cell($row, $columns, 'ID товару/послуги')) !== null) {
            $hasProducts = true;
            break;
        }
    }
    if (!$hasProducts) {
        throw new RuntimeException('XLSX file has no products with an ID. Existing catalog was not changed.');
    }

    $sql = "INSERT INTO products_catalog (
                product_id, sku, name, document_name, supplier, manufacturer,
                category_id, category_name, category_path,
                price, discount_price, cost_price, image_url,
                source, imported_at, updated_at
            ) VALUES (
                :product_id, :sku, :name, :document_name, :supplier, :manufacturer,
                :category_id, :category_name, :category_path,
                :price, :discount_price, :cost_price, :image_url,
                'salesdrive_xlsx', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
                sku = VALUES(sku),
                name = VALUES(name),
                document_name = VALUES(document_name),
                supplier = VALUES(supplier),
                manufacturer = VALUES(manufacturer),
                category_id = VALUES(category_id),
                category_name = VALUES(category_name),
                category_path = VALUES(category_path),
                price = VALUES(price),
                discount_price = VALUES(discount_price),
                cost_price = VALUES(cost_price),
                image_url = VALUES(image_url),
                source = VALUES(source),
                imported_at = VALUES(imported_at),
                updated_at = CURRENT_TIMESTAMP";
    $stmt = $pdo->prepare($sql);

    $processed = 0;
    $skipped = 0;
    $duplicateIds = 0;
    $seenIds = [];
    $pdo->beginTransaction();
    try {
        // DELETE is transactional for InnoDB: a failed import restores the old catalog.
        $pdo->exec('DELETE FROM products_catalog');
        foreach ($rows as $row) {
            $productId = product_catalog_text(product_catalog_cell($row, $columns, 'ID товару/послуги'));
            if ($productId === null) {
                $skipped++;
                continue;
            }

            $name = product_catalog_text(product_catalog_cell($row, $columns, 'Товар/Послуга'));
            if ($name === null) {
                $name = product_catalog_text(product_catalog_cell($row, $columns, 'Назва для документів')) ?? $productId;
            }

            $stmt->execute([
                ':product_id' => $productId,
                ':sku' => product_catalog_text(product_catalog_cell($row, $columns, 'SKU')),
                ':name' => $name,
                ':document_name' => product_catalog_text(product_catalog_cell($row, $columns, 'Назва для документів')),
                ':supplier' => product_catalog_text(product_catalog_cell($row, $columns, 'Постачальник')),
                ':manufacturer' => product_catalog_text(product_catalog_cell($row, $columns, 'Виробник')),
                ':category_id' => product_catalog_text(product_catalog_cell($row, $columns, 'ID категорії')),
                ':category_name' => product_catalog_text(product_catalog_cell($row, $columns, 'Категорія')),
                ':category_path' => product_catalog_text(product_catalog_cell($row, $columns, 'Структура категорій')),
                ':price' => product_catalog_money(product_catalog_cell($row, $columns, 'Ціна')),
                ':discount_price' => product_catalog_money(product_catalog_cell($row, $columns, 'Ціна зі знижкою')),
                ':cost_price' => product_catalog_money(product_catalog_cell($row, $columns, 'Собівартість')),
                ':image_url' => product_catalog_text(product_catalog_cell($row, $columns, 'Зображення')),
            ]);
            if (isset($seenIds[$productId])) {
                $duplicateIds++;
            } else {
                $seenIds[$productId] = true;
                $processed++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return [
        'processed' => $processed,
        'skipped' => $skipped,
        'duplicate_ids' => $duplicateIds,
    ];
}
