<?php
require_once '/var/www/private/config.php';

$period  = $_GET['period'] ?? 'month';
$manager = $_GET['manager'] ?? '';
$city    = $_GET['city'] ?? '';

$where = [];

/* Период */
switch ($period) {
    case 'day':
        $where[] = "DATE(order_time) = CURDATE()";
        break;
    case 'week':
        $where[] = "YEARWEEK(order_time,1) = YEARWEEK(CURDATE(),1)";
        break;
    case 'year':
        $where[] = "YEAR(order_time) = YEAR(CURDATE())";
        break;
    default:
        $where[] = "MONTH(order_time)=MONTH(CURDATE()) AND YEAR(order_time)=YEAR(CURDATE())";
}

/* Только успешные статусы */
$success = implode(',', array_map('intval', $success_statuses));
$where[] = "status_id IN ($success)";

/* Менеджер */
if ($manager !== '') {
    $where[] = "manager_id = " . intval($manager);
}

/* Город */
if ($city !== '') {
    $where[] = "np_city_ref = '" . $db->real_escape_string($city) . "'";
}

$whereSql = implode(' AND ', $where);

/* Сводка */
$summary = $db->query("
    SELECT 
        COUNT(*) orders,
        SUM(amount) amount,
        SUM(profit_amount) profit
    FROM orders
    WHERE $whereSql
")->fetch_assoc();

/* Таблица */
$list = $db->query("
    SELECT id, order_time, manager_id, amount, np_city_ref
    FROM orders
    WHERE $whereSql
    ORDER BY order_time DESC
    LIMIT 100
")->fetch_all(MYSQLI_ASSOC);

/* Для фильтров */
$managers = $db->query("
    SELECT DISTINCT manager_id 
    FROM orders 
    WHERE manager_id IS NOT NULL
")->fetch_all(MYSQLI_ASSOC);

$cities = $db->query("
    SELECT DISTINCT np_city_ref 
    FROM orders 
    WHERE np_city_ref IS NOT NULL
")->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'summary' => $summary,
    'orders' => $list,
    'managers' => $managers,
    'cities' => $cities
]);

