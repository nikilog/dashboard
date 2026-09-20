<?php
// /var/www/html/dashboard/cache_suppliers.php
// Usage: php cache_suppliers.php /var/www/private/export.xlsx /var/www/private/suppliers_cache.json
// Requires PhpSpreadsheet (composer require phpoffice/phpspreadsheet)

require_once '/var/www/private/vendor/autoload.php';


if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only\n"; exit; }

$inXlsx  = $argv[1] ?? '/var/www/private/export.xlsx';
$outJson = $argv[2] ?? '/var/www/private/suppliers_cache.json';

if (!is_file($inXlsx)) {
  fwrite(STDERR, "XLSX not found: {$inXlsx}\n");
  exit(1);
}

if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
  fwrite(STDERR, "PhpSpreadsheet not installed. Install: composer require phpoffice/phpspreadsheet\n");
  exit(1);
}

use PhpOffice\PhpSpreadsheet\IOFactory;

$ss = IOFactory::load($inXlsx);
$ws = $ss->getActiveSheet();
$rows = $ws->toArray(null, true, true, true);
if (!$rows || count($rows) < 2) {
  fwrite(STDERR, "XLSX seems empty\n");
  exit(1);
}

$header = array_shift($rows);
$colByName = [];
foreach ($header as $col => $name) {
  $name = trim((string)$name);
  if ($name !== '') $colByName[$name] = $col;
}

// твоя структура из старого файла
$need = ['ID товару/послуги', 'Назва для документів', 'Товар/Послуга', 'Постачальник', 'SKU', 'ID категорії'];
foreach ($need as $n) {
  if (!isset($colByName[$n])) {
    fwrite(STDERR, "Missing column in XLSX: {$n}\n");
    exit(1);
  }
}

$out = [
  'generated_at' => date('c'),
  'items' => [] // productId => [name, supplier, sku, categoryId]
];

foreach ($rows as $r) {
  $pid = trim((string)($r[$colByName['ID товару/послуги']] ?? ''));
  if ($pid === '') continue;

  $nameDoc = trim((string)($r[$colByName['Назва для документів']] ?? ''));
  $nameAny = trim((string)($r[$colByName['Товар/Послуга']] ?? ''));
  $name = $nameDoc !== '' ? $nameDoc : ($nameAny !== '' ? $nameAny : $pid);

  $supplier = trim((string)($r[$colByName['Постачальник']] ?? ''));
  if ($supplier === '') $supplier = '—';

  $sku = trim((string)($r[$colByName['SKU']] ?? ''));

  $catId = trim((string)($r[$colByName['ID категорії']] ?? ''));
  if ($catId === '') $catId = null;

  $out['items'][(string)$pid] = [
    'name' => $name,
    'supplier' => $supplier,
    'sku' => $sku,
    'categoryId' => $catId,
  ];
}

$tmp = $outJson . '.tmp';
file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_UNICODE));
rename($tmp, $outJson);

echo "OK: {$outJson}\n";
echo "Items: " . count($out['items']) . "\n";


