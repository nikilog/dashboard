<?php
// cities_report.php
header('Content-Type: application/json; charset=utf-8');

try {
  $cfg = require '/var/www/private/config.php';

  $from = $_GET['from'] ?? null; // YYYY-MM-DD
  $to   = $_GET['to'] ?? null;   // YYYY-MM-DD
  $bind = $_GET['bind'] ?? 'created'; // created|sold

  if (!$from || !$to) throw new Exception('from/to required');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    throw new Exception('bad date format');
  }
  if (!in_array($bind, ['created','sold'], true)) $bind = 'created';

  $successStatuses = $cfg['success_statuses'] ?? [];
  $holdStatuses    = $cfg['hold_statuses'] ?? [];
  $rejectStatuses  = $cfg['reject_statuses'] ?? [6,7,16,17];
  $excludedStatuses = $cfg['excluded_statuses'] ?? [];

  // ✅ План берём как на основной странице: motivation_rules.php -> global_plan_uah
  $globalPlan = 0.0;
  $rulesFile = __DIR__ . '/motivation_rules.php';
  if (is_file($rulesFile)) {
    $rules = require $rulesFile;
    if (is_array($rules)) {
      if (isset($rules['global_plan_uah'])) $globalPlan = (float)$rules['global_plan_uah'];
      else if (isset($rules['global_plan'])) $globalPlan = (float)$rules['global_plan']; // запасной вариант
    }
  }

  $pdo = new PDO(
    "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
    $cfg['db_user'],
    $cfg['db_pass'],
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );

  $loadOptionMap = function($fieldName) use ($pdo) {
    $stmt = $pdo->prepare("SELECT option_id, option_label, option_color FROM field_options WHERE field_name = ?");
    $stmt->execute([$fieldName]);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $out[(int)$row['option_id']] = [
        'label' => $row['option_label'],
        'color' => $row['option_color'],
      ];
    }
    return $out;
  };

  $statusMap = $loadOptionMap('statusId');
  $sitesMap = $loadOptionMap('sajt');
  $managersMap = $loadOptionMap('userId');

  // bind mode:
  // created: фильтр по order_time (все статусы)
  // sold: фильтр по update_at + только success statuses
  $dateCol = ($bind === 'sold') ? 'update_at' : 'order_time';

  $sql = "SELECT
            id, order_time, update_at,
            status_id, manager_id,
            amount, profit_amount,
            site_id, shipping_method_id, shipping_address,
            delivery_area_name, delivery_region_name, delivery_city_name,
            full_json
          FROM orders
          WHERE $dateCol >= :fromd AND $dateCol <= :tod2";

  if (!empty($excludedStatuses)) {
    $in = implode(',', array_map('intval', $excludedStatuses));
    $sql .= " AND status_id NOT IN ($in)";
  }

  if ($bind === 'sold') {
    $in = implode(',', array_map('intval', $successStatuses ?: [0]));
    $sql .= " AND status_id IN ($in)";
  }

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':fromd' => $from . ' 00:00:00',
    ':tod2'  => $to   . ' 23:59:59',
  ]);

  $rows = $stmt->fetchAll();

  /* ---------------- helpers ---------------- */

  function normStr($s) {
    if ($s === null) return null;
    if (!is_string($s)) return null;
    $s = trim($s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s === '' ? null : $s;
  }

  function pick($arr, $keys) {
    if (!is_array($arr)) return null;
    foreach ($keys as $k) {
      if (!array_key_exists($k, $arr)) continue;
      $v = $arr[$k];
      if (is_string($v)) {
        $v = normStr($v);
        if ($v !== null) return $v;
      } else if (is_array($v)) {
        // иногда значения вложены
        foreach (['name','title','value','text','label','city','region','oblast','area'] as $nk) {
          if (isset($v[$nk]) && is_string($v[$nk])) {
            $vv = normStr($v[$nk]);
            if ($vv !== null) return $vv;
          }
        }
      }
    }
    return null;
  }

  function parseFromAddressFallback($addr) {
    $addr = normStr($addr);
    if (!$addr) return [null, null];

    // Город: первая часть до запятой
    $parts = preg_split('/\s*,\s*/u', $addr);
    $city = null;
    if (is_array($parts) && count($parts) > 0) {
      $first = trim((string)$parts[0]);
      if ($first !== '' && mb_strlen($first) <= 50) $city = $first;
    }

    // Область: пробуем найти слово "обл" или "область"
    $region = null;
    foreach ($parts as $p) {
      if (preg_match('/\bобл\.?\b|\bобласть\b/iu', $p)) {
        $region = trim($p);
        break;
      }
    }

    return [normStr($region), normStr($city)];
  }

  function extractShipMethodId($row, $j) {
    // приоритет: колонка БД (она надёжная)
    $id = isset($row['shipping_method_id']) ? (int)$row['shipping_method_id'] : 0;
    if ($id > 0) return $id;

    // fallback: если в full_json есть shipping_method
    if (is_array($j) && isset($j['shipping_method'])) {
      $sm = $j['shipping_method'];
      if (is_numeric($sm)) return (int)$sm;
      if (is_array($sm) && isset($sm['id']) && is_numeric($sm['id'])) return (int)$sm['id'];
    }

    return 0;
  }

  function extractRegionCityByDelivery($row, $j) {
    $directRegion = normStr($row['delivery_area_name'] ?? null);
    $directCity = normStr($row['delivery_city_name'] ?? null);
    if ($directRegion || $directCity) {
      return [$directRegion, $directCity];
    }

    $shipId = extractShipMethodId($row, $j);

    // адрес (fallback)
    $addr = null;
    if (is_array($j)) {
      $addr = $j['adresaDostavki'] ?? $j['shipping_address'] ?? null;
    }
    if (!$addr) $addr = $row['shipping_address'] ?? null;

    // ---- 16: УКРПОШТА ----
    // data[ord_ukrposhta][regionName], data[ord_ukrposhta][cityName]
    if ($shipId === 16) {
      $up = is_array($j) ? ($j['ord_ukrposhta'] ?? null) : null;
      $region = pick($up, ['regionName', 'region', 'oblastName', 'areaName']);
      $city   = pick($up, ['cityName', 'city', 'settlementName', 'townName']);
      if ($region || $city) return [$region, $city];
    }

    // ---- 9: НОВА ПОШТА ----
    // region: ord_novaposhta.area
    if ($shipId === 9) {
      $np = is_array($j) ? ($j['ord_novaposhta'] ?? null) : null;
      $region = pick($np, ['area', 'areaName', 'regionName', 'oblast', 'oblastName']);
      $city   = pick($np, ['cityName', 'settlementName', 'city', 'settlement', 'townName']);
      if ($region || $city) return [$region, $city];
    }

    // ---- 103: MEEST ----
    if ($shipId === 103) {
      $m = is_array($j) ? ($j['ord_meest'] ?? null) : null;
      $region = pick($m, ['regionName','areaName','oblastName','area','oblast']);
      $city   = pick($m, ['cityName','city','settlementName','townName']);
      if ($region || $city) return [$region, $city];
    }

    // ---- 17: JUSTIN ----
    if ($shipId === 17) {
      $ju = is_array($j) ? ($j['ord_justin'] ?? null) : null;
      $region = pick($ju, ['regionName','areaName','oblastName','area','oblast']);
      $city   = pick($ju, ['cityName','city','settlementName','townName']);
      if ($region || $city) return [$region, $city];
    }

    // ---- 20: Курьер / 10: Самовывоз / 0: неизвестно ----
    // обычно там нет ord_* с нормализованными полями — fallback
    return parseFromAddressFallback($addr);
  }

  /* ---------------- KPIs + geo accumulators ---------------- */

  $kpi = [
    'success_amount' => 0.0,
    'hold_amount' => 0.0,
    'success_profit' => 0.0,
    'hold_profit' => 0.0,
    'success_orders' => 0,
    'total_orders' => 0,
    'reject_pct' => 0.0,
  ];

  $rejectCount = 0;

  $geoRegions = []; // region => aggRow
  $geoCities  = []; // region => [city => aggRow]

  $ensureGeoRow = function($label){
    return [
      'label' => $label,
      'success' => ['turnover'=>0.0,'profit'=>0.0,'orders'=>0],
      'hold'    => ['turnover'=>0.0,'profit'=>0.0,'orders'=>0],
      'total'   => ['turnover'=>0.0,'profit'=>0.0,'orders'=>0],
    ];
  };

  $pushGeo = function(&$bucketRow, $amount, $profit, $orders){
    $bucketRow['turnover'] += $amount;
    $bucketRow['profit']   += $profit;
    $bucketRow['orders']   += $orders;
  };

  $ordersOut = [];

  foreach ($rows as $r) {
    $statusId = (int)($r['status_id'] ?? 0);
    $amount   = (float)($r['amount'] ?? 0);
    $profit   = (float)($r['profit_amount'] ?? 0);

    $kpi['total_orders']++;

    $isSuccess = in_array($statusId, $successStatuses, true);
    $isHold    = in_array($statusId, $holdStatuses, true);

    if (in_array($statusId, $rejectStatuses, true)) $rejectCount++;

    if ($isSuccess) {
      $kpi['success_amount'] += $amount;
      $kpi['success_profit'] += $profit;
      $kpi['success_orders']++;
    } else if ($isHold) {
      $kpi['hold_amount'] += $amount;
      $kpi['hold_profit'] += $profit;
    }

    $statusName  = $statusMap[$statusId]['label'] ?? (string)$statusId;
    $statusColor = $statusMap[$statusId]['color'] ?? null;
    $managerName = $managersMap[(int)($r['manager_id'] ?? 0)]['label'] ?? '—';

    $siteId = (int)($r['site_id'] ?? 0);
    $source = $sitesMap[$siteId]['label'] ?? 'Прямий дзвінок';

    $j = null;
    if (!empty($r['full_json'])) {
      $tmp = json_decode($r['full_json'], true);
      if (is_array($tmp)) $j = $tmp;
    }

    [$region, $city] = extractRegionCityByDelivery($r, $j);

    if(!$region || !$city) {
      continue;
    }
    $region = trim($region);
    $city = trim($city);

    if ($region === '' || $city === '' || $region === '-' || $city === '-'){
      continue;
    }

    if (!isset($geoRegions[$region])) $geoRegions[$region] = $ensureGeoRow($region);
    if (!isset($geoCities[$region]))  $geoCities[$region]  = [];

    // total
    $pushGeo($geoRegions[$region]['total'], $amount, $profit, 1);
    if (!isset($geoCities[$region][$city])) $geoCities[$region][$city] = $ensureGeoRow($city);
    $pushGeo($geoCities[$region][$city]['total'], $amount, $profit, 1);

    // success/hold
    if ($isSuccess) {
      $pushGeo($geoRegions[$region]['success'], $amount, $profit, 1);
      $pushGeo($geoCities[$region][$city]['success'], $amount, $profit, 1);
    }
    if ($isHold) {
      $pushGeo($geoRegions[$region]['hold'], $amount, $profit, 1);
      $pushGeo($geoCities[$region][$city]['hold'], $amount, $profit, 1);
    }

    $ordersOut[] = [
      'id' => (int)$r['id'],
      'source' => $source,
      'status_id' => $statusId,
      'status_color' => $statusColor,
      'status' => $statusName,
      'manager' => $managerName,
      'region' => $region,
      'city' => $city,
    ];
  }

  $kpi['reject_pct'] = $kpi['total_orders'] > 0 ? ($rejectCount / $kpi['total_orders']) * 100.0 : 0.0;

  // normalize geo arrays
  $regionsArr = array_values($geoRegions);
  usort($regionsArr, function($a,$b){
    return ($b['total']['turnover'] <=> $a['total']['turnover']);
  });

  $citiesOut = [];
  foreach ($geoCities as $regionLabel => $citiesMap) {
    $arr = array_values($citiesMap);
    usort($arr, function($a,$b){
      return ($b['total']['turnover'] <=> $a['total']['turnover']);
    });
    $citiesOut[$regionLabel] = $arr;
  }

  echo json_encode([
    'ok' => true,
    'global_plan' => $globalPlan,
    'kpi' => $kpi,
    'geo' => [
      'regions' => $regionsArr,
      'cities' => $citiesOut,
    ],
    'orders' => $ordersOut,
    'bind' => $bind,
    'from' => $from,
    'to' => $to,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}

