<?php
$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php tools/analyze_orders_dump.php path/to/orders.sql [from YYYY-MM-DD] [to YYYY-MM-DD]\n");
    exit(1);
}
$fromDate = $argv[2] ?? '2026-06-01';
$toDate = $argv[3] ?? '2026-06-30';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    fwrite(STDERR, "Bad date format. Use YYYY-MM-DD.\n");
    exit(1);
}

$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, "Cannot read dump\n");
    exit(1);
}

if (!preg_match('/INSERT INTO `orders` \((.*?)\) VALUES\s*(.*);/s', $sql, $m)) {
    fwrite(STDERR, "INSERT not found\n");
    exit(1);
}

$columns = array_map(
    fn($v) => trim($v, " `\r\n\t"),
    explode(',', $m[1])
);
$idx = array_flip($columns);
$valuesSql = $m[2];

function split_rows($s) {
    $rows = [];
    $len = strlen($s);
    $in = false;
    $esc = false;
    $depth = 0;
    $start = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($in) {
            if ($esc) {
                $esc = false;
            } elseif ($ch === '\\') {
                $esc = true;
            } elseif ($ch === "'") {
                $in = false;
            }
            continue;
        }
        if ($ch === "'") {
            $in = true;
            continue;
        }
        if ($ch === '(') {
            if ($depth === 0) $start = $i + 1;
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
            if ($depth === 0 && $start !== null) {
                $rows[] = substr($s, $start, $i - $start);
                $start = null;
            }
        }
    }
    return $rows;
}

function split_values($row) {
    $out = [];
    $buf = '';
    $len = strlen($row);
    $in = false;
    $esc = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $row[$i];
        if ($in) {
            $buf .= $ch;
            if ($esc) {
                $esc = false;
            } elseif ($ch === '\\') {
                $esc = true;
            } elseif ($ch === "'") {
                $in = false;
            }
            continue;
        }
        if ($ch === "'") {
            $in = true;
            $buf .= $ch;
            continue;
        }
        if ($ch === ',') {
            $out[] = parse_value($buf);
            $buf = '';
        } else {
            $buf .= $ch;
        }
    }
    $out[] = parse_value($buf);
    return $out;
}

function parse_value($raw) {
    $raw = trim($raw);
    if (strcasecmp($raw, 'NULL') === 0) return null;
    if (strlen($raw) >= 2 && $raw[0] === "'" && substr($raw, -1) === "'") {
        $raw = substr($raw, 1, -1);
        return stripcslashes($raw);
    }
    return $raw;
}

function in_period_datetime($date) {
    global $fromDate, $toDate;
    return is_string($date) && $date >= $fromDate . ' 00:00:00' && $date <= $toDate . ' 23:59:59';
}

function in_period_day($date) {
    global $fromDate, $toDate;
    if (!is_string($date) || $date === '') return false;
    $d = substr($date, 0, 10);
    return $d >= $fromDate && $d <= $toDate;
}

function add_row(&$bucket, $v, $idx) {
    $bucket['count']++;
    $bucket['amount'] += (float)$v[$idx['amount']];
    $bucket['expenses'] += (float)$v[$idx['expenses_amount']];
    $bucket['profit'] += (float)$v[$idx['profit_amount']];
}

$success = [5, 11, 18];
$hold = [2, 3, 4, 13, 14];
$buckets = [];
foreach ([
    'order_time_all', 'order_time_success',
    'update_at_all', 'update_at_success',
    'payment_date_all', 'payment_date_success',
    'salesdrive_like_success_payment_date_positive',
    'order_time_without_excluded_8',
] as $name) {
    $buckets[$name] = ['count' => 0, 'amount' => 0.0, 'expenses' => 0.0, 'profit' => 0.0];
}

$statusCounts = [];
$byStatusOrderTime = [];
$rows = split_rows($valuesSql);
foreach ($rows as $row) {
    $v = split_values($row);
    if (count($v) !== count($columns)) continue;
    $status = (int)$v[$idx['status_id']];
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    if (!isset($byStatusOrderTime[$status])) {
        $byStatusOrderTime[$status] = ['count' => 0, 'amount' => 0.0, 'expenses' => 0.0, 'profit' => 0.0];
    }

    if (in_period_datetime($v[$idx['order_time']])) add_row($buckets['order_time_all'], $v, $idx);
    if (in_period_datetime($v[$idx['order_time']])) add_row($byStatusOrderTime[$status], $v, $idx);
    if (in_period_datetime($v[$idx['order_time']]) && $status !== 8) add_row($buckets['order_time_without_excluded_8'], $v, $idx);
    if (in_period_datetime($v[$idx['order_time']]) && in_array($status, $success, true)) add_row($buckets['order_time_success'], $v, $idx);
    if (in_period_datetime($v[$idx['update_at']])) add_row($buckets['update_at_all'], $v, $idx);
    if (in_period_datetime($v[$idx['update_at']]) && in_array($status, $success, true)) add_row($buckets['update_at_success'], $v, $idx);
    if (in_period_day($v[$idx['payment_date']])) add_row($buckets['payment_date_all'], $v, $idx);
    if (in_period_day($v[$idx['payment_date']]) && in_array($status, $success, true)) add_row($buckets['payment_date_success'], $v, $idx);
    if (in_period_day($v[$idx['payment_date']]) && in_array($status, $success, true) && (float)$v[$idx['amount']] > 0) {
        add_row($buckets['salesdrive_like_success_payment_date_positive'], $v, $idx);
    }
}

echo "Rows: " . count($rows) . PHP_EOL;
echo "Period: $fromDate - $toDate" . PHP_EOL;
ksort($statusCounts);
echo "Status counts: " . json_encode($statusCounts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
foreach ($buckets as $name => $b) {
    printf(
        "%s: count=%d amount=%.2f expenses=%.2f profit=%.2f\n",
        $name,
        $b['count'],
        $b['amount'],
        $b['expenses'],
        $b['profit']
    );
}

ksort($byStatusOrderTime);
echo "by_status_order_time:" . PHP_EOL;
foreach ($byStatusOrderTime as $status => $b) {
    if ($b['count'] === 0) continue;
    printf(
        "  status=%d count=%d amount=%.2f expenses=%.2f profit=%.2f\n",
        $status,
        $b['count'],
        $b['amount'],
        $b['expenses'],
        $b['profit']
    );
}
