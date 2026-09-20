<?php
// /var/www/html/dashboard/cache_catalog.php
// Usage: php cache_catalog.php /var/www/private/export.xml /var/www/private/catalog_cache.json

if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only\n"; exit; }

$xmlPath = $argv[1] ?? '/var/www/private/export.xml';
$outPath = $argv[2] ?? '/var/www/private/catalog_cache.json';

if (!$xmlPath || !is_file($xmlPath)) {
  fwrite(STDERR, "XML file not found: $xmlPath\n");
  exit(1);
}

$reader = new XMLReader();
if (!$reader->open($xmlPath)) {
  fwrite(STDERR, "Cannot open XML\n");
  exit(1);
}

$categories = []; // id => ['parentId'=>..., 'name'=>...]
$offers     = []; // offerId => ['name'=>..., 'categoryId'=>..., 'vendor'=>..., 'sku'=>...]

// Хелпер для чтения текста узла
$readText = function(XMLReader $r){
  $txt = '';
  if ($r->isEmptyElement) return '';
  if ($r->read() && ($r->nodeType === XMLReader::TEXT || $r->nodeType === XMLReader::CDATA)) {
      $txt = $r->value;
  }
  return trim($txt);
};

while ($reader->read()) {
  if ($reader->nodeType !== XMLReader::ELEMENT) continue;

  // 1. Парсинг Категорий
  if ($reader->name === 'category') {
    $cid  = (string)$reader->getAttribute('id');
    $pid  = $reader->getAttribute('parentId');
    $name = $readText($reader);
    
    if ($cid !== '') {
      $categories[$cid] = [
        'parentId' => $pid !== null ? (string)$pid : null,
        'name'     => $name,
      ];
    }
  }

  // 2. Парсинг Товаров (Offer)
  if ($reader->name === 'offer') {
    $oid = (string)$reader->getAttribute('id');
    if ($oid === '') continue;

    $offerName = '';
    $catId = null;
    $vendor = '—';
    $sku = ''; // Переменная для артикула

    // Если пустой тег <offer />
    if ($reader->isEmptyElement) {
      $offers[$oid] = ['name'=>$offerName, 'categoryId'=>$catId, 'vendor'=>$vendor, 'sku'=>$sku];
      continue;
    }

    $depth = $reader->depth;
    while ($reader->read()) {
      // Выход, если закрылся тег </offer>
      if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) break;
      if ($reader->nodeType !== XMLReader::ELEMENT) continue;

      // Читаем вложенные теги
      if ($reader->name === 'name') {
        $offerName = $readText($reader);
      } elseif ($reader->name === 'categoryId') {
        $catId = $readText($reader);
      } elseif ($reader->name === 'vendor') {
        $v = $readText($reader);
        if ($v !== '') $vendor = $v;
      } elseif ($reader->name === 'vendorCode') {
        // !!! ВАЖНО: Читаем стандартный тег артикула !!!
        $s = $readText($reader);
        if ($s !== '') $sku = $s;
      } elseif ($reader->name === 'param') {
        // !!! ВАЖНО: Читаем артикул из параметров, если он там !!!
        $paramName = $reader->getAttribute('name');
        if (mb_stripos($paramName, 'Артикул') !== false) {
           $s = $readText($reader);
           if ($s !== '') $sku = $s;
        }
      }
    }

    // Если артикул не найден, пробуем использовать ID
    if ($sku === '') {
        $sku = $oid; 
    }

    $offers[$oid] = [
      'name'       => $offerName,
      'categoryId' => $catId,
      'vendor'     => $vendor,
      'sku'        => $sku  // Сохраняем найденный SKU
    ];
  }
}

$reader->close();

$payload = [
  'generated_at' => date('c'),
  'categories'   => $categories,
  'offers'       => $offers
];

// Сохраняем JSON
if (file_put_contents($outPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
  echo "Catalog cached successfully: " . count($offers) . " offers, " . count($categories) . " categories.\n";
} else {
  fwrite(STDERR, "Error writing to $outPath\n");
  exit(1);
}
