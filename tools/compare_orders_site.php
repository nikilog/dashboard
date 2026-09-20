<?php
$sqlPath = $argv[1] ?? '';
$sitePath = $argv[2] ?? '';
if ($sqlPath === '' || $sitePath === '' || !is_file($sqlPath) || !is_file($sitePath)) {
    fwrite(STDERR, "Usage: php tools/compare_orders_site.php orders.sql \"orders from cite.txt\"\n");
    exit(1);
}

$sql = file_get_contents($sqlPath);
$site = file_get_contents($sitePath);
if ($sql === false || $site === false) {
    fwrite(STDERR, "Cannot read input files\n");
    exit(1);
}

if (!preg_match('/INSERT INTO `orders` \((.*?)\) VALUES\s*(.*);/s', $sql, $m)) {
    fwrite(STDERR, "orders INSERT not found\n");
    exit(1);
}

$columns = array_map(fn($v) => trim($v, " `\r\n\t"), explode(',', $m[1]));
$idx = array_flip($columns);

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
            if ($esc) $esc = false;
            elseif ($ch === '\\') $esc = true;
            elseif ($ch === "'") $in = false;
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
            if ($esc) $esc = false;
            elseif ($ch === '\\') $esc = true;
            elseif ($ch === "'") $in = false;
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
        return stripcslashes(substr($raw, 1, -1));
    }
    return $raw;
}

function in_june_order_time($date) {
    return is_string($date) && $date >= '2026-06-01 00:00:00' && $date <= '2026-06-30 23:59:59';
}

$orders = [];
foreach (split_rows($m[2]) as $row) {
    $v = split_values($row);
    if (count($v) !== count($GLOBALS['columns'])) continue;
    $id = (int)$v[$GLOBALS['idx']['id']];
    $orders[$id] = [
        'id' => $id,
        'order_time' => $v[$GLOBALS['idx']['order_time']],
        'update_at' => $v[$GLOBALS['idx']['update_at']],
        'status_id' => (int)$v[$GLOBALS['idx']['status_id']],
        'manager_id' => (int)$v[$GLOBALS['idx']['manager_id']],
        'site_id' => (int)$v[$GLOBALS['idx']['site_id']],
        'amount' => (float)$v[$GLOBALS['idx']['amount']],
        'profit' => (float)$v[$GLOBALS['idx']['profit_amount']],
    ];
}

$dbJune = array_values(array_filter($orders, fn($o) => in_june_order_time($o['order_time'])));
usort($dbJune, function($a, $b) {
    $cmp = strcmp((string)$b['order_time'], (string)$a['order_time']);
    return $cmp !== 0 ? $cmp : ($b['id'] <=> $a['id']);
});
$dbTop250 = array_slice($dbJune, 0, 250);
$dbTop250Ids = array_map(fn($o) => $o['id'], $dbTop250);

preg_match_all('/^\s*(\d{4,})\b/mu', $site, $mm);
$siteIds = array_map('intval', $mm[1] ?? []);
$siteUnique = array_values(array_unique($siteIds));

$dbTopSet = array_fill_keys($dbTop250Ids, true);
$dbJuneSet = array_fill_keys(array_map(fn($o) => $o['id'], $dbJune), true);
$siteSet = array_fill_keys($siteUnique, true);

$siteNotInDb = array_values(array_filter($siteUnique, fn($id) => !isset($orders[$id])));
$siteNotInJune = array_values(array_filter($siteUnique, fn($id) => !isset($dbJuneSet[$id])));
$siteNotInTop250 = array_values(array_filter($siteUnique, fn($id) => !isset($dbTopSet[$id])));
$top250NotOnSite = array_values(array_filter($dbTop250Ids, fn($id) => !isset($siteSet[$id])));

$orderMismatches = [];
$n = min(count($siteUnique), count($dbTop250Ids));
for ($i = 0; $i < $n; $i++) {
    if ($siteUnique[$i] !== $dbTop250Ids[$i]) {
        $orderMismatches[] = [
            'pos' => $i + 1,
            'site' => $siteUnique[$i],
            'db' => $dbTop250Ids[$i],
        ];
        if (count($orderMismatches) >= 20) break;
    }
}

function sample_ids($ids, $limit = 30) {
    return implode(', ', array_slice($ids, 0, $limit)) . (count($ids) > $limit ? ' ...' : '');
}

echo "DB total rows: " . count($orders) . PHP_EOL;
echo "DB June by order_time rows: " . count($dbJune) . PHP_EOL;
echo "DB top 250 by order_time DESC: " . count($dbTop250Ids) . PHP_EOL;
echo "Site copied IDs: " . count($siteIds) . PHP_EOL;
echo "Site unique IDs: " . count($siteUnique) . PHP_EOL;
echo "Site duplicate IDs: " . (count($siteIds) - count($siteUnique)) . PHP_EOL;
echo PHP_EOL;

echo "Site IDs not present in orders.sql: " . count($siteNotInDb) . PHP_EOL;
if ($siteNotInDb) echo "  " . sample_ids($siteNotInDb) . PHP_EOL;
echo "Site IDs present in DB, but not in June by order_time: " . count($siteNotInJune) . PHP_EOL;
if ($siteNotInJune) echo "  " . sample_ids($siteNotInJune) . PHP_EOL;
echo "Site IDs not in expected top 250: " . count($siteNotInTop250) . PHP_EOL;
if ($siteNotInTop250) echo "  " . sample_ids($siteNotInTop250) . PHP_EOL;
echo "Expected top 250 IDs missing from site copy: " . count($top250NotOnSite) . PHP_EOL;
if ($top250NotOnSite) echo "  " . sample_ids($top250NotOnSite) . PHP_EOL;
echo PHP_EOL;

echo "First 20 order-position mismatches against DB top 250: " . count($orderMismatches) . PHP_EOL;
foreach ($orderMismatches as $mismatch) {
    echo "  #{$mismatch['pos']}: site={$mismatch['site']} db={$mismatch['db']}" . PHP_EOL;
}

if (!$siteNotInDb && !$siteNotInJune && !$siteNotInTop250 && !$top250NotOnSite && !$orderMismatches) {
    echo "MATCH: site copy equals DB top 250 for June order_time mode." . PHP_EOL;
}
