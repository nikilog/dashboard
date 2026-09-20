<?php
$path = $argv[1] ?? '';
$statusNeed = isset($argv[2]) ? (int)$argv[2] : 0;
$fromDate = $argv[3] ?? '2026-03-01';
$toDate = $argv[4] ?? '2026-03-31';
if ($path === '' || !is_file($path) || $statusNeed <= 0) {
    fwrite(STDERR, "Usage: php tools/inspect_orders_by_status.php orders.sql status [from] [to]\n");
    exit(1);
}

$sql = file_get_contents($path);
preg_match('/INSERT INTO `orders` \((.*?)\) VALUES\s*(.*);/s', $sql, $m) || die("INSERT not found\n");
$columns = array_map(fn($v) => trim($v, " `\r\n\t"), explode(',', $m[1]));
$idx = array_flip($columns);

function split_rows($s) {
    $rows = []; $len = strlen($s); $in = false; $esc = false; $depth = 0; $start = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($in) {
            if ($esc) $esc = false;
            elseif ($ch === '\\') $esc = true;
            elseif ($ch === "'") $in = false;
            continue;
        }
        if ($ch === "'") { $in = true; continue; }
        if ($ch === '(') { if ($depth === 0) $start = $i + 1; $depth++; }
        elseif ($ch === ')') {
            $depth--;
            if ($depth === 0 && $start !== null) { $rows[] = substr($s, $start, $i - $start); $start = null; }
        }
    }
    return $rows;
}

function split_values($row) {
    $out = []; $buf = ''; $len = strlen($row); $in = false; $esc = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $row[$i];
        if ($in) {
            $buf .= $ch;
            if ($esc) $esc = false;
            elseif ($ch === '\\') $esc = true;
            elseif ($ch === "'") $in = false;
            continue;
        }
        if ($ch === "'") { $in = true; $buf .= $ch; continue; }
        if ($ch === ',') { $out[] = parse_value($buf); $buf = ''; }
        else $buf .= $ch;
    }
    $out[] = parse_value($buf);
    return $out;
}

function parse_value($raw) {
    $raw = trim($raw);
    if (strcasecmp($raw, 'NULL') === 0) return null;
    if (strlen($raw) >= 2 && $raw[0] === "'" && substr($raw, -1) === "'") {
        return stripcslashes(substr($raw, 1, -1));
    }
    return $raw;
}

foreach (split_rows($m[2]) as $row) {
    $v = split_values($row);
    if (count($v) !== count($columns)) continue;
    $status = (int)$v[$idx['status_id']];
    $orderTime = (string)$v[$idx['order_time']];
    if ($status !== $statusNeed || $orderTime < $fromDate . ' 00:00:00' || $orderTime > $toDate . ' 23:59:59') continue;

    $json = json_decode((string)$v[$idx['full_json']], true);
    printf(
        "id=%d order=%s amount=%.2f expenses_col=%.2f profit_col=%.2f cost_col=%.2f commission_col=%.2f json_expenses=%.2f json_profit=%.2f json_commission=%.2f json_cost=%.2f np_cost=%.2f\n",
        (int)$v[$idx['id']],
        $v[$idx['order_number']],
        (float)$v[$idx['amount']],
        (float)$v[$idx['expenses_amount']],
        (float)$v[$idx['profit_amount']],
        (float)$v[$idx['cost_price_amount']],
        (float)$v[$idx['commission_amount']],
        (float)($json['expensesAmount'] ?? 0),
        (float)($json['profitAmount'] ?? 0),
        (float)($json['commissionAmount'] ?? 0),
        (float)($json['costPriceAmount'] ?? 0),
        (float)($json['ord_novaposhta']['cost'] ?? 0)
    );
}
