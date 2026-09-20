<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$configPath = '/var/www/private/config.php';
if (function_exists('opcache_invalidate')) {
  @opcache_invalidate($configPath, true);
}

$cfg   = require $configPath;
$rules = require __DIR__ . '/motivation_rules.php';

function fail($msg, $http = 500) {
  http_response_code($http);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function toDate($s){ $t = strtotime($s); return $t ? date('Y-m-d', $t) : null; }
function daysInclusive($from,$to){ return (int)round((strtotime($to)-strtotime($from))/86400)+1; }
function clamp($v,$min,$max){ return max($min, min($max, $v)); }



/* required keys */
$need = ['db_host','db_name','db_user','db_pass','success_statuses','hold_statuses'];
foreach ($need as $k) {
  if (!isset($cfg[$k])) fail("config.php: отсутствует ключ '$k'");
}
if (!is_array($cfg['success_statuses']) || !count($cfg['success_statuses'])) fail("config.php: success_statuses пустой/не массив");
if (!is_array($cfg['hold_statuses'])    || !count($cfg['hold_statuses']))    fail("config.php: hold_statuses пустой/не массив");

$success_statuses = $cfg['success_statuses'];
$hold_statuses    = $cfg['hold_statuses'];

/* DB - PDO connection (for field_options) */
try {
    $pdo = new PDO(
        "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
        $cfg['db_user'],
        $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fail("DB connection error: " . $e->getMessage());
}

// Завантажуємо опції полів з БД (field_options)
function loadFieldOptions($pdo, $fieldName) {
    static $cache = [];
    if (isset($cache[$fieldName])) return $cache[$fieldName];
    
    try {
        $stmt = $pdo->prepare("SELECT option_id, option_label, option_color FROM field_options WHERE field_name = ?");
        $stmt->execute([$fieldName]);
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[(int)$row['option_id']] = [
                'label' => $row['option_label'],
                'color' => $row['option_color']
            ];
        }
        $cache[$fieldName] = $result;
        return $result;
    } catch (Exception $e) {
        return [];
    }
}

// Завантажуємо мапи з БД, fallback на конфіг
$managers_map_db = loadFieldOptions($pdo, 'userId');
$managers_map = [];
foreach ($managers_map_db as $id => $data) {
    $managers_map[$id] = $data['label'];
}
// Fallback на конфіг для менеджерів, яких немає в БД
$status_map_db = loadFieldOptions($pdo, 'statusId');
$status_map = [];
foreach ($status_map_db as $id => $data) {
    $status_map[$id] = $data['label'];
}
$sites_map_db = loadFieldOptions($pdo, 'sajt');
$sites_map = [];
foreach ($sites_map_db as $id => $data) {
    $sites_map[$id] = $data['label'];
}
$managers_w = array_values(array_unique(array_map('intval', $cfg['managers_w'] ?? [])));
$managers_b = array_values(array_unique(array_map('intval', $cfg['managers_b'] ?? [])));
$reject_statuses = array_values(array_unique(array_map('intval', $cfg['reject_statuses'] ?? [6,7,16,17])));
$excluded_statuses = array_values(array_unique(array_map('intval', $cfg['excluded_statuses'] ?? [])));

/* DB - mysqli (for existing queries) */
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
if ($db->connect_errno) fail("Ошибка подключения к БД: ".$db->connect_error);
$db->set_charset('utf8mb4');

/* rules */
$globalPlan           = (float)($rules['global_plan_uah'] ?? 0);
$defaultMonthlySalary = (float)($rules['default']['monthly_salary_uah'] ?? 17000);
$managerRules         = $rules['managers'] ?? [];
$contentManagers      = array_map('intval', $rules['content_managers'] ?? []);
$salesManagers        = array_map('intval', $rules['sales_managers'] ?? []);

/* dates */
$from = toDate($_GET['from'] ?? '');
$to   = toDate($_GET['to'] ?? '');
if (!$from || !$to) { $from = date('Y-m-01'); $to = date('Y-m-t'); }
if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }

/* daily rate = monthly_salary / days in the month of $from */
$daysInMonth = (int)date('t', strtotime($from));
if ($daysInMonth < 1) $daysInMonth = 30;
$defaultDailyRate = $defaultMonthlySalary / $daysInMonth;

$fromDT = $from.' 00:00:00';
$toDT   = $to.' 23:59:59';

$periodDays = daysInclusive($from, $to);
$today = date('Y-m-d');
if ($today < $from) $workedDays = 0;
elseif ($today > $to) $workedDays = $periodDays;
else $workedDays = daysInclusive($from, $today);

/* bind mode: created | sold (sold means update_at) */
$bind = $_GET['bind'] ?? 'created';
$bind = ($bind === 'sold') ? 'sold' : 'created';
$dateField = ($bind === 'sold') ? 'update_at' : 'order_time';
$onlyCompleted = isset($_GET['only_completed']) ? ($_GET['only_completed'] === '1') : true;

/* statuses lists */
$success = implode(',', array_map('intval', $success_statuses));
$hold    = implode(',', array_map('intval', $hold_statuses));
$reject  = implode(',', array_map('intval', $reject_statuses));
$excluded = implode(',', array_map('intval', $excluded_statuses));

/* In update-date mode, optionally keep only completed statuses from config.php. */
$extraWhere = $excluded !== '' ? " AND status_id NOT IN ($excluded) " : '';
if ($bind === 'sold' && $onlyCompleted) {
  $extraWhere .= " AND status_id IN ($success) ";
}

/* ---------- KPI ---------- */
$stmtKpi = $db->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN status_id IN ($success) THEN amount END), 0) AS success_amount,
    COALESCE(SUM(CASE WHEN status_id IN ($success) THEN profit_amount END), 0) AS success_profit,
    COALESCE(SUM(CASE WHEN status_id IN ($success) THEN 1 END), 0) AS success_orders,

    COALESCE(SUM(CASE WHEN status_id IN ($hold) THEN amount END), 0) AS hold_amount,
    COALESCE(SUM(CASE WHEN status_id IN ($hold) THEN profit_amount END), 0) AS hold_profit,
    COALESCE(SUM(CASE WHEN status_id IN ($hold) THEN 1 END), 0) AS hold_orders,

    COALESCE(SUM(CASE WHEN status_id IN ($reject) THEN 1 END), 0) AS reject_orders,
    COALESCE(COUNT(*), 0) AS total_orders
  FROM orders
  WHERE {$dateField} BETWEEN ? AND ? {$extraWhere}
");
if (!$stmtKpi) fail("SQL prepare KPI: ".$db->error);
$stmtKpi->bind_param('ss', $fromDT, $toDT);
if (!$stmtKpi->execute()) fail("SQL execute KPI: ".$stmtKpi->error);
$k = $stmtKpi->get_result()->fetch_assoc();
$stmtKpi->close();

$kpi = [
  'success_amount' => (float)($k['success_amount'] ?? 0),
  'success_profit' => (float)($k['success_profit'] ?? 0),
  'success_orders' => (int)($k['success_orders'] ?? 0),

  'hold_amount' => (float)($k['hold_amount'] ?? 0),
  'hold_profit' => (float)($k['hold_profit'] ?? 0),
  'hold_orders' => (int)($k['hold_orders'] ?? 0),

  'reject_orders' => (int)($k['reject_orders'] ?? 0),
  'total_orders'  => (int)($k['total_orders'] ?? 0),
];
$kpi['reject_pct'] = ($kpi['total_orders'] > 0) ? ($kpi['reject_orders'] / $kpi['total_orders']) * 100.0 : null;

/* fulfillment percent (for KPI display only) */
$fulfillmentPct = ($globalPlan > 0) ? ($kpi['success_profit'] / $globalPlan) * 100.0 : null;


/* ---------- Per-manager totals ---------- */
$stmtM = $db->prepare("
  SELECT
    manager_id,
    COALESCE(SUM(CASE WHEN status_id IN ($success) THEN amount END),0) AS fact_amount,
    COALESCE(SUM(CASE WHEN status_id IN ($success) THEN profit_amount END),0) AS fact_profit,
    COALESCE(SUM(CASE WHEN status_id IN ($hold) THEN amount END),0) AS hold_amount,
    COALESCE(SUM(CASE WHEN status_id IN ($hold) THEN profit_amount END),0) AS hold_profit
  FROM orders
  WHERE {$dateField} BETWEEN ? AND ? {$extraWhere}
  GROUP BY manager_id
");
if (!$stmtM) fail("SQL prepare managers: ".$db->error);
$stmtM->bind_param('ss', $fromDT, $toDT);
if (!$stmtM->execute()) fail("SQL execute managers: ".$stmtM->error);

$byManager = [];
$res = $stmtM->get_result();
while ($r = $res->fetch_assoc()) {
  $mid = (int)($r['manager_id'] ?? 0);
  if ($mid <= 0) continue;
  $byManager[$mid] = [
    'fact_amount' => (float)$r['fact_amount'],
    'fact_profit' => (float)$r['fact_profit'],
    'hold_amount' => (float)$r['hold_amount'],
    'hold_profit' => (float)$r['hold_profit'],
  ];
}
$stmtM->close();

/* build rows */
$rowsAll = [];
$totals = ['amount'=>0.0,'profit'=>0.0,'salary'=>0.0,'bonus'=>0.0,'pay'=>0.0];

$allManagers = [];
foreach ($managers_map as $mid => $_name) $allManagers[(int)$mid] = true;
foreach ($byManager as $mid => $_d)       $allManagers[(int)$mid] = true;

foreach (array_keys($allManagers) as $mid) {
  $d = $byManager[$mid] ?? ['fact_amount'=>0,'fact_profit'=>0,'hold_amount'=>0,'hold_profit'=>0];

  // Місячна ставка: беремо з правил менеджера або дефолтну
  $monthlySalary = (float)($managerRules[$mid]['monthly_salary_uah'] ?? $defaultMonthlySalary);
  // Денна ставка = місячна / кількість днів у місяці початку періоду
  $daily  = $monthlySalary / $daysInMonth;
  $salary = $daily * $workedDays;

  // Бонус від особистого прибутку менеджера (fact_profit) — залежить від відділу
  $mid = (int)$mid;
  if (in_array($mid, $contentManagers, true)) {
    $bonusPct = 0.5;
  } elseif (in_array($mid, $salesManagers, true)) {
    $bonusPct = 1.0;
  } else {
    $bonusPct = 1.0; // за замовчуванням — відділ продажів
  }
  $bonusAmount = $d['fact_profit'] * ($bonusPct / 100.0);
  $pay = $salary + $bonusAmount;

  $totals['amount'] += $d['fact_amount'];
  $totals['profit'] += $d['fact_profit'];
  $totals['salary'] += $salary;
  $totals['bonus']  += $bonusAmount;
  $totals['pay']    += $pay;

  $rowsAll[] = [
    'manager_id'   => (int)$mid,
    'manager_name' => $managers_map[$mid] ?? ('ID '.$mid),

    'daily_rate'  => $daily,
    'worked_days' => $workedDays,
    'salary'      => $salary,

    // fact (success)
    'fact_amount' => $d['fact_amount'],
    'fact_profit' => $d['fact_profit'],

    // totals for "Загальне" mode in table (fact + hold)
    'total_amount' => $d['fact_amount'] + $d['hold_amount'],
    'total_profit' => $d['fact_profit'] + $d['hold_profit'],

    'bonus_pct'    => $bonusPct,
    'bonus_amount' => $bonusAmount,
    'total_pay'    => $pay,
  ];
}

/* filter rows for display (blacklist/whitelist affects only display) */
$rows = array_values(array_filter($rowsAll, function($r) use ($managers_b, $managers_w){
  $mid = (int)$r['manager_id'];
  if (in_array($mid, $managers_b, true)) return false;
  if (!empty($managers_w) && !in_array($mid, $managers_w, true)) return false;
  return true;
}));
usort($rows, fn($a,$b)=> ($b['total_pay'] <=> $a['total_pay']));

/* ---------- Daily series for chart ---------- */
$groupDate = "DATE($dateField)";
$stmtS = $db->prepare("
  SELECT
    {$groupDate} AS d,
    COALESCE(SUM(CASE WHEN status_id IN ($success) THEN amount END),0) AS s_amount,
    COALESCE(SUM(CASE WHEN status_id IN ($success) THEN profit_amount END),0) AS s_profit,
    COALESCE(SUM(CASE WHEN status_id IN ($success) THEN 1 END),0) AS s_orders,

    COALESCE(SUM(CASE WHEN status_id IN ($hold) THEN amount END),0) AS h_amount,
    COALESCE(SUM(CASE WHEN status_id IN ($hold) THEN profit_amount END),0) AS h_profit,
    COALESCE(SUM(CASE WHEN status_id IN ($hold) THEN 1 END),0) AS h_orders
  FROM orders
  WHERE {$dateField} BETWEEN ? AND ? {$extraWhere}
  GROUP BY {$groupDate}
  ORDER BY {$groupDate}
");
if (!$stmtS) fail("SQL prepare series: ".$db->error);
$stmtS->bind_param('ss', $fromDT, $toDT);
if (!$stmtS->execute()) fail("SQL execute series: ".$stmtS->error);

$labels = [];
$successSeries = ['turnover'=>[],'profit'=>[],'orders'=>[]];
$holdSeries    = ['turnover'=>[],'profit'=>[],'orders'=>[]];
$totalSeries   = ['turnover'=>[],'profit'=>[],'orders'=>[]];

$rs = $stmtS->get_result();
while($r = $rs->fetch_assoc()){
  $labels[] = $r['d'];

  $sa = (float)$r['s_amount']; $sp = (float)$r['s_profit']; $so = (int)$r['s_orders'];
  $ha = (float)$r['h_amount']; $hp = (float)$r['h_profit']; $ho = (int)$r['h_orders'];

  $successSeries['turnover'][] = $sa;
  $successSeries['profit'][]   = $sp;
  $successSeries['orders'][]   = $so;

  $holdSeries['turnover'][] = $ha;
  $holdSeries['profit'][]   = $hp;
  $holdSeries['orders'][]   = $ho;

  $totalSeries['turnover'][] = $sa + $ha;
  $totalSeries['profit'][]   = $sp + $hp;
  $totalSeries['orders'][]   = $so + $ho;
}
$stmtS->close();

$series = [
  'labels'  => $labels,
  'success' => $successSeries,
  'hold'    => $holdSeries,
  'total'   => $totalSeries,
];

/* ---------- helpers for site/source ---------- */
function extractSiteIdStrict($siteId, $fullJson) {
  $siteId = (int)$siteId;
  if ($siteId > 0) return $siteId;

  if (!empty($fullJson)) {
    $json = json_decode($fullJson, true);
    if (is_array($json)) {
      if (isset($json['sajt'])) return (int)$json['sajt'];
      if (isset($json['data']['sajt'])) return (int)$json['data']['sajt'];
      if (isset($json['site_id'])) return (int)$json['site_id'];
      if (isset($json['data']['site_id'])) return (int)$json['data']['site_id'];
    }
  }
  return 0;
}

/* ---------- Orders list — ALL orders in period (no status filter) ---------- */
$dateOrder = ($bind === 'sold') ? 'update_at' : 'order_time';
$stmtO = $db->prepare("
  SELECT id, site_id, status_id, manager_id, full_json
  FROM orders
  WHERE {$dateOrder} BETWEEN ? AND ? {$extraWhere}
  ORDER BY {$dateOrder} DESC
  LIMIT 250
");
if (!$stmtO) fail("SQL prepare orders: ".$db->error);
$stmtO->bind_param('ss', $fromDT, $toDT);
if (!$stmtO->execute()) fail("SQL execute orders: ".$stmtO->error);

$orders = [];
$ro = $stmtO->get_result();
while($r = $ro->fetch_assoc()){
  $id = (int)$r['id'];

  $sid = extractSiteIdStrict($r['site_id'] ?? 0, $r['full_json'] ?? '');
  $source = $sites_map[$sid] ?? 'Прямий дзвінок';

  $statusId = (int)($r['status_id'] ?? 0);
  $status = $status_map[$statusId] ?? ($statusId ? ('ID '.$statusId) : '-');
  $statusColor = $status_map_db[$statusId]['color'] ?? null;

  $mid = (int)($r['manager_id'] ?? 0);
  $manager = $managers_map[$mid] ?? ($mid ? ('ID '.$mid) : '-');

  $orders[] = [
    'id'      => $id,
    'source'  => $source,
    'status_id' => $statusId,
    'status_color' => $statusColor,
    'status'  => $status,
    'manager' => $manager,
  ];
}
$stmtO->close();

/* ---------- Sources pie (total vs success) ---------- */
$stmtSrc = $db->prepare("
  SELECT site_id, status_id, full_json
  FROM orders
  WHERE {$dateField} BETWEEN ? AND ? {$extraWhere}
");
if (!$stmtSrc) fail("SQL prepare sources: " . $db->error);

$stmtSrc->bind_param('ss', $fromDT, $toDT);
if (!$stmtSrc->execute()) fail("SQL execute sources: " . $stmtSrc->error);

$srcTotal = [];
$srcSuccess = [];

$rs = $stmtSrc->get_result();
while ($r = $rs->fetch_assoc()) {
  $sid = extractSiteIdStrict($r['site_id'] ?? 0, $r['full_json'] ?? '');
  $label = $sites_map[$sid] ?? 'Прямий дзвінок';

  $srcTotal[$label] = ($srcTotal[$label] ?? 0) + 1;

  $st = (int)($r['status_id'] ?? 0);
  if (in_array($st, $success_statuses, true)) {
    $srcSuccess[$label] = ($srcSuccess[$label] ?? 0) + 1;
  }
}
$stmtSrc->close();

arsort($srcTotal);
arsort($srcSuccess);

$packTop = function($arr, $limit = 10) {
  $out = [];
  $i = 0;
  $other = 0;
  foreach ($arr as $k => $v) {
    if ($i < $limit) $out[] = ['label' => $k, 'count' => $v];
    else $other += $v;
    $i++;
  }
  if ($other > 0) $out[] = ['label' => 'Інше', 'count' => $other];
  return $out;
};

$sources = [
  'total' => $packTop($srcTotal),
  'success' => $packTop($srcSuccess),
];

/* ---------- Meta options for frontend (dynamic colors, labels) ---------- */
$metaOptions = [];

// Status options with colors
$statusOpts = loadFieldOptions($pdo, 'statusId');
foreach ($statusOpts as $id => $data) {
    $metaOptions['statusId'][$id] = [
        'label' => $data['label'],
        'color' => $data['color'] ?? null
    ];
}

// Site/source options
$siteOpts = loadFieldOptions($pdo, 'sajt');
foreach ($siteOpts as $id => $data) {
    $metaOptions['sajt'][$id] = [
        'label' => $data['label'],
        'color' => $data['color'] ?? null
    ];
}

// Manager options
$mgrOpts = loadFieldOptions($pdo, 'userId');
foreach ($mgrOpts as $id => $data) {
    $metaOptions['userId'][$id] = [
        'label' => $data['label']
    ];
}

echo json_encode([
  'ok' => true,

  'from' => $from,
  'to' => $to,
  'bind' => $bind,
  'only_completed' => $onlyCompleted,

  'period_days' => $periodDays,
  'worked_days' => $workedDays,

  'global_plan'     => $globalPlan,
  'fulfillment_pct' => $fulfillmentPct,

  'kpi'    => $kpi,
  'series' => $series,

  'totals' => $totals,
  'rows'   => $rows,
  'orders' => $orders,
  'sources'=> $sources,
  'meta_options' => $metaOptions,
], JSON_UNESCAPED_UNICODE);
