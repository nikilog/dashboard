<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$configPath = dirname(__DIR__, 2) . '/private/config.php';
$rulesPath = __DIR__ . '/motivation_rules.php';
$productsImporterPath = dirname(__DIR__, 2) . '/private/product_catalog_importer.php';
$responseSent = false;

register_shutdown_function(function () use (&$responseSent) {
    if ($responseSent) return;

    $error = error_get_last();
    if (!$error) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) return;

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }

    echo json_encode([
        'ok' => false,
        'error' => 'PHP fatal error: ' . $error['message'],
        'file' => $error['file'] ?? null,
        'line' => $error['line'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
});

function out($data, $code = 200) {
    global $responseSent;
    $responseSent = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail_json($message, $code = 400) {
    out(['ok' => false, 'error' => $message], $code);
}

function load_config($path) {
    if (!is_file($path)) fail_json('Config not found', 500);
    $config = require $path;
    if (!is_array($config)) fail_json('Config is invalid', 500);
    return $config;
}

function load_rules($path) {
    if (!is_file($path)) return [];
    $rules = require $path;
    return is_array($rules) ? $rules : [];
}

function pdo_conn($config) {
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

function clean_field_name($value) {
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        fail_json('Bad field name');
    }
    return $value;
}

function int_list($value) {
    if (is_array($value)) $value = implode(',', $value);
    preg_match_all('/-?\d+/', (string)$value, $matches);
    $out = array_map('intval', $matches[0] ?? []);
    $out = array_values(array_unique(array_filter($out, fn($id) => $id > 0)));
    return $out;
}

function money_value($value) {
    $value = str_replace(',', '.', (string)$value);
    return round((float)$value, 2);
}

function export_array($value) {
    return var_export($value, true);
}

function describe_path_permissions($path) {
    $dir = dirname($path);
    $parts = ['path=' . $path];

    if (is_file($path)) {
        $perms = substr(sprintf('%o', fileperms($path)), -4);
        $parts[] = 'file_perms=' . $perms;
        $parts[] = 'file_writable=' . (is_writable($path) ? 'yes' : 'no');
    } else {
        $parts[] = 'file_exists=no';
    }

    $parts[] = 'dir_writable=' . (is_writable($dir) ? 'yes' : 'no');
    return implode('; ', $parts);
}

function write_config($path, $config) {
    $text = "<?php\n\nreturn [\n";
    $text .= "    'db_host' => " . export_array($config['db_host'] ?? '127.0.0.1') . ",\n";
    $text .= "    'db_name' => " . export_array($config['db_name'] ?? '') . ",\n";
    $text .= "    'db_user' => " . export_array($config['db_user'] ?? '') . ",\n";
    $text .= "    'db_pass' => " . export_array($config['db_pass'] ?? '') . ",\n\n";
    $text .= "    'crm_api_base' => " . export_array($config['crm_api_base'] ?? '') . ",\n";
    $text .= "    'crm_api_key'  => trim(file_get_contents(__DIR__ . '/APIKEY.txt')),\n\n";
    $text .= "    'success_statuses' => " . export_array(array_values($config['success_statuses'] ?? [])) . ",\n";
    $text .= "    'hold_statuses'    => " . export_array(array_values($config['hold_statuses'] ?? [])) . ",\n\n";
    $text .= "    'reject_statuses'  => " . export_array(array_values($config['reject_statuses'] ?? [6, 7, 16, 17])) . ",\n";
    $text .= "    'excluded_statuses' => " . export_array(array_values($config['excluded_statuses'] ?? [])) . ",\n\n";
    $text .= "    'managers_w' => " . export_array(array_values($config['managers_w'] ?? [])) . ",\n";
    $text .= "    'managers_b' => " . export_array(array_values($config['managers_b'] ?? [])) . ",\n";
    $text .= "];\n";

    if (!is_file($path)) {
        fail_json('Could not write config.php: file not found; ' . describe_path_permissions($path), 500);
    }
    if (!is_writable($path)) {
        fail_json('Could not write config.php: permission denied; ' . describe_path_permissions($path), 500);
    }
    if (file_put_contents($path, $text, LOCK_EX) === false) {
        fail_json('Could not write config.php: write failed; ' . describe_path_permissions($path), 500);
    }
}

function write_rules($path, $rules) {
    $text = "<?php\nreturn " . var_export($rules, true) . ";\n";
    if (file_put_contents($path, $text, LOCK_EX) === false) {
        fail_json('Could not write motivation_rules.php', 500);
    }
}

function salary_map($text) {
    $out = [];
    foreach (preg_split('/\R+/', (string)$text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (!preg_match('/^(\d+)\s*[=:]\s*([0-9]+(?:[,.][0-9]+)?)$/', $line, $m)) {
            fail_json('Bad salary line: ' . $line);
        }
        $out[(int)$m[1]] = ['monthly_salary_uah' => money_value($m[2])];
    }
    return $out;
}

function products_catalog_stats(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) AS total, MAX(imported_at) AS last_import, MAX(updated_at) AS last_update FROM products_catalog");
        $row = $stmt->fetch();
        return [
            'available' => true,
            'total' => (int)($row['total'] ?? 0),
            'last_import' => $row['last_import'] ?? null,
            'last_update' => $row['last_update'] ?? null,
        ];
    } catch (Throwable $e) {
        return [
            'available' => false,
            'total' => 0,
            'last_import' => null,
            'last_update' => null,
            'error' => $e->getMessage(),
        ];
    }
}

try {
    $config = load_config($configPath);
    $pdo = pdo_conn($config);
    $action = $_POST['action'] ?? $_GET['action'] ?? 'state';

    if ($action === 'state') {
        $defaultFields = [
            'userId', 'sajt', 'organizationId', 'postacalnik',
            'statusId', 'payment_method', 'shipping_method',
            'campaignId', 'rejectionReason', 'stockId',
            'vzaemozalik', 'promokod', 'terminiVidpravlenna',
        ];

        $fields = $defaultFields;
        $stmt = $pdo->query("SELECT DISTINCT field_name FROM field_options ORDER BY field_name");
        while ($row = $stmt->fetch()) $fields[] = $row['field_name'];
        $fields = array_values(array_unique($fields));
        sort($fields);

        $field = $_GET['field'] ?? 'userId';
        if (!in_array($field, $fields, true)) $field = $fields[0] ?? 'userId';

        $stmt = $pdo->prepare("SELECT field_name, option_id, option_label, option_color, updated_at
                               FROM field_options
                               WHERE field_name = ?
                               ORDER BY option_id");
        $stmt->execute([$field]);
        $options = $stmt->fetchAll();

        $checks = [
            ['column' => 'manager_id', 'field' => 'userId'],
            ['column' => 'site_id', 'field' => 'sajt'],
            ['column' => 'organization_id', 'field' => 'organizationId'],
            ['column' => 'supplier_id', 'field' => 'postacalnik'],
            ['column' => 'status_id', 'field' => 'statusId'],
            ['column' => 'payment_method_id', 'field' => 'payment_method'],
            ['column' => 'shipping_method_id', 'field' => 'shipping_method'],
            ['column' => 'campaign_id', 'field' => 'campaignId'],
            ['column' => 'rejection_reason_id', 'field' => 'rejectionReason'],
            ['column' => 'stock_id', 'field' => 'stockId'],
            ['column' => 'mutual_settlement_id', 'field' => 'vzaemozalik'],
            ['column' => 'promo_code_id', 'field' => 'promokod'],
            ['column' => 'dispatch_term_id', 'field' => 'terminiVidpravlenna'],
        ];

        $columns = [];
        $colStmt = $pdo->query("SHOW COLUMNS FROM orders");
        while ($row = $colStmt->fetch()) $columns[$row['Field']] = true;

        $missing = [];
        foreach ($checks as $check) {
            if (!isset($columns[$check['column']])) continue;
            $sql = "SELECT o.`{$check['column']}` AS option_id, COUNT(*) AS cnt
                    FROM orders o
                    LEFT JOIN field_options fo
                      ON fo.field_name = :field_name
                     AND fo.option_id = o.`{$check['column']}`
                    WHERE o.`{$check['column']}` IS NOT NULL
                      AND o.`{$check['column']}` <> 0
                      AND fo.option_id IS NULL
                    GROUP BY o.`{$check['column']}`
                    ORDER BY cnt DESC
                    LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':field_name' => $check['field']]);
            while ($row = $stmt->fetch()) {
                $missing[] = [
                    'field_name' => $check['field'],
                    'option_id' => (int)$row['option_id'],
                    'orders' => (int)$row['cnt'],
                ];
            }
        }

        $rules = load_rules($rulesPath);
        out([
            'ok' => true,
            'fields' => $fields,
            'selected_field' => $field,
            'options' => $options,
            'missing' => $missing,
            'groups' => [
                'success_statuses' => array_values($config['success_statuses'] ?? []),
                'hold_statuses' => array_values($config['hold_statuses'] ?? []),
                'managers_w' => array_values($config['managers_w'] ?? []),
                'managers_b' => array_values($config['managers_b'] ?? []),
            ],
            'rules' => [
                'global_plan_uah' => $rules['global_plan_uah'] ?? 0,
                'default_monthly_salary_uah' => $rules['default']['monthly_salary_uah'] ?? 17000,
                'managers' => $rules['managers'] ?? [],
                'content_managers' => array_values($rules['content_managers'] ?? []),
                'sales_managers' => array_values($rules['sales_managers'] ?? []),
            ],
            'products_catalog' => products_catalog_stats($pdo),
        ]);
    }

    if ($action === 'save_option') {
        $fieldName = clean_field_name($_POST['field_name'] ?? '');
        $optionId = (int)($_POST['option_id'] ?? 0);
        $label = trim((string)($_POST['option_label'] ?? ''));
        $color = trim((string)($_POST['option_color'] ?? ''));
        if ($optionId <= 0 || $label === '') fail_json('ID and label are required');

        $stmt = $pdo->prepare("INSERT INTO field_options (field_name, option_id, option_label, option_color)
                               VALUES (:field_name, :option_id, :option_label, :option_color)
                               ON DUPLICATE KEY UPDATE
                                 option_label = VALUES(option_label),
                                 option_color = VALUES(option_color),
                                 updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([
            ':field_name' => $fieldName,
            ':option_id' => $optionId,
            ':option_label' => $label,
            ':option_color' => $color === '' ? null : $color,
        ]);
        out(['ok' => true]);
    }

    if ($action === 'delete_option') {
        $fieldName = clean_field_name($_POST['field_name'] ?? '');
        $optionId = (int)($_POST['option_id'] ?? 0);
        if ($optionId <= 0) fail_json('Bad option ID');
        $stmt = $pdo->prepare("DELETE FROM field_options WHERE field_name = ? AND option_id = ?");
        $stmt->execute([$fieldName, $optionId]);
        out(['ok' => true]);
    }

    if ($action === 'save_groups') {
        $config['success_statuses'] = int_list($_POST['success_statuses'] ?? '');
        $config['hold_statuses'] = int_list($_POST['hold_statuses'] ?? '');
        $config['managers_w'] = int_list($_POST['managers_w'] ?? '');
        $config['managers_b'] = int_list($_POST['managers_b'] ?? '');
        write_config($configPath, $config);
        out(['ok' => true]);
    }

    if ($action === 'save_motivation') {
        $rules = load_rules($rulesPath);
        $rules['global_plan_uah'] = money_value($_POST['global_plan_uah'] ?? 0);
        $rules['default'] = [
            'monthly_salary_uah' => money_value($_POST['default_monthly_salary_uah'] ?? 17000),
        ];
        $rules['managers'] = salary_map($_POST['manager_salaries'] ?? '');
        $rules['content_managers'] = int_list($_POST['content_managers'] ?? '');
        $rules['sales_managers'] = int_list($_POST['sales_managers'] ?? '');
        write_rules($rulesPath, $rules);
        out(['ok' => true]);
    }

    if ($action === 'upload_products_xlsx') {
        set_time_limit(900);
        ini_set('memory_limit', '1024M');

        if (!is_file($productsImporterPath)) {
            fail_json('Products importer not found', 500);
        }
        require_once $productsImporterPath;

        if (empty($_FILES['products_xlsx']) || !is_array($_FILES['products_xlsx'])) {
            fail_json('XLSX file is required');
        }
        $file = $_FILES['products_xlsx'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            fail_json('Upload failed. Code: ' . (int)$file['error']);
        }

        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            fail_json('Only XLSX/XLS files are allowed');
        }

        $uploadedPath = (string)($file['tmp_name'] ?? '');
        if ($uploadedPath === '' || !is_uploaded_file($uploadedPath) || !is_readable($uploadedPath)) {
            fail_json('Uploaded XLSX is not readable', 500);
        }

        $result = import_products_catalog_from_xlsx($pdo, $uploadedPath);

        out([
            'ok' => true,
            'import' => $result,
            'products_catalog' => products_catalog_stats($pdo),
        ]);
    }

    if ($action === 'rebuild_products_analytics') {
        $script = __DIR__ . '/analyze_orders.php';
        if (!is_file($script)) {
            fail_json('analyze_orders.php not found', 500);
        }

        set_time_limit(900);
        ini_set('memory_limit', '1G');

        ob_start();
        try {
            include $script;
            $log = ob_get_clean();
        } catch (Throwable $e) {
            $log = ob_get_clean();
            fail_json('Analytics rebuild failed: ' . $e->getMessage() . ($log ? "\n" . $log : ''), 500);
        }

        out([
            'ok' => true,
            'log' => $log,
        ]);
    }

    fail_json('Unknown action');
} catch (Throwable $e) {
    fail_json($e->getMessage(), 500);
}
