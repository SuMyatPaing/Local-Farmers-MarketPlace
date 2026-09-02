<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = realpath(__DIR__ . '/..');
$failures = 0;

function check_line($ok, $label)
{
    global $failures;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function db_has_column(PDO $pdo, $table, $column)
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name"
    );
    $statement->execute(array(
        'table_name' => $table,
        'column_name' => $column
    ));

    return (int) $statement->fetchColumn() > 0;
}

check_line(version_compare(PHP_VERSION, '7.1.0', '>='), 'PHP 7.1+');

$requiredFiles = array(
    'index.php',
    'signin.php',
    'auth.php',
    'security.php',
    'review_submit.php',
    'payment_slip.php',
    'lib/marketplace.php',
    'config/database.php',
    'config/database.sql',
    'config/migrate_existing_database.sql',
    'assets/css/app.css',
    'assets/css/theme.css',
    'assets/js/chart-lite.js',
    'assets/vendor/fontawesome/css/all.min.css',
    'uploads/payment_qr/kbzpay.png',
    'uploads/payment_qr/wave_money.png',
    'admin/dashboard.php',
    'vendor/dashboard.php',
    'docs/PROJECT_STRUCTURE.md',
    'docs/SECURITY.md',
    'docs/ORDER_WORKFLOW.md',
    'docs/FINAL_CHECKLIST.md'
);

foreach ($requiredFiles as $file) {
    check_line(is_file($root . '/' . $file), 'Required file: ' . $file);
}

$forbiddenFiles = array(
    'reset.php',
    'login.php',
    'admin/logout.php',
    'vendor/logout.php',
    'auth/signin.php',
    'admin/payments.php'
);

foreach ($forbiddenFiles as $file) {
    check_line(
        !file_exists($root . '/' . $file),
        'Removed stale/sensitive file: ' . $file
    );
}

foreach (array(
    'uploads/products',
    'uploads/markets',
    'uploads/profiles',
    'uploads/payment_slips',
    'uploads/payment_qr'
) as $directory) {
    check_line(
        is_dir($root . '/' . $directory),
        'Upload directory: ' . $directory
    );
}

try {
    $pdo = null;
    require $root . '/config/database.php';
    check_line(isset($pdo) && $pdo instanceof PDO, 'Database connection');

    $tables = array(
        'users', 'vendors', 'cities', 'markets', 'categories', 'products',
        'vendor_markets', 'permission', 'events', 'reviews',
        'purchase_process', 'vendor_orders', 'purchase_details',
        'payment_accounts', 'login_attempts'
    );

    foreach ($tables as $table) {
        $statement = $pdo->query(
            "SHOW TABLES LIKE " . $pdo->quote($table)
        );
        check_line(
            (bool) $statement->fetchColumn(),
            'Database table: ' . $table
        );
    }

    $columns = array(
        array('users', 'must_change_password'),
        array('products', 'unit'),
        array('purchase_process', 'fulfillment_type'),
        array('purchase_process', 'delivery_name'),
        array('purchase_process', 'delivery_phone'),
        array('purchase_process', 'delivery_address'),
        array('purchase_process', 'customer_note'),
        array('purchase_process', 'payment_status'),
        array('purchase_process', 'payment_slip'),
        array('purchase_details', 'vendor_order_id')
    );

    foreach ($columns as $item) {
        check_line(
            db_has_column($pdo, $item[0], $item[1]),
            'Database column: ' . $item[0] . '.' . $item[1]
        );
    }

    $typeStatement = $pdo->query(
        "SELECT COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'purchase_process'
           AND COLUMN_NAME = 'payment_method'"
    );
    $paymentType = strtolower((string) $typeStatement->fetchColumn());

    check_line(
        strpos($paymentType, 'cod') === false,
        'COD removed from payment_method enum'
    );
    check_line(
        strpos($paymentType, 'kbzpay') !== false &&
        strpos($paymentType, 'wave_money') !== false,
        'KBZPay/Wave Money payment enum'
    );

    $charsetStatement = $pdo->query(
        "SELECT CCSA.character_set_name
         FROM information_schema.TABLES T
         INNER JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
            ON CCSA.collation_name = T.table_collation
         WHERE T.table_schema = DATABASE()
           AND T.table_name = 'vendor_orders'"
    );

    check_line(
        strtolower((string) $charsetStatement->fetchColumn()) === 'utf8mb4',
        'vendor_orders uses utf8mb4'
    );
} catch (Exception $exception) {
    check_line(
        false,
        'Database check: ' . $exception->getMessage()
    );
}

echo PHP_EOL;
echo $failures === 0
    ? "Project check passed.\n"
    : "Project check failed: " . $failures . " issue(s).\n";

exit($failures > 0 ? 1 : 0);
