<?php
declare(strict_types=1);

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
fm_require_role('admin');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Yangon');

require_once __DIR__ . '/../config/database.php';

if ((!isset($pdo) || !($pdo instanceof PDO)) && function_exists('getPDO')) {
    $pdo = getPDO();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit('Database configuration must create a PDO connection named $pdo.');
}


if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function redirectToVendors(): void
{
    header('Location: vendors.php');
    exit;
}

function setVendorsFlash(string $type, string $message): void
{
    $_SESSION['vendors_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function validateVendorsCsrfToken(): bool
{
    $submittedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $sessionToken = isset($_SESSION['csrf_token']) ? (string) $_SESSION['csrf_token'] : '';

    return $submittedToken !== ''
        && $sessionToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}

function vendorsQueryString(array $changes): string
{
    $current = [
        'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
        'status' => isset($_GET['status']) ? (string) $_GET['status'] : 'all',
        'account' => isset($_GET['account']) ? (string) $_GET['account'] : 'all',
        'market' => isset($_GET['market']) ? (int) $_GET['market'] : 0,
        'sort' => isset($_GET['sort']) ? (string) $_GET['sort'] : 'newest',
        'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    ];

    foreach ($changes as $key => $value) {
        $current[$key] = $value;
    }

    return http_build_query($current);
}

function vendorRecord(PDO $pdo, int $vendorId): array
{
    $statement = $pdo->prepare(
        'SELECT v.vendor_id, v.user_id, v.vendor_name, v.status, u.status AS user_status
         FROM vendors v
         INNER JOIN users u ON u.user_id = v.user_id
         WHERE v.vendor_id = :vendor_id
         LIMIT 1'
    );
    $statement->execute(['vendor_id' => $vendorId]);
    $vendor = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$vendor) {
        throw new RuntimeException('Vendor not found.');
    }

    return $vendor;
}

if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        $_SESSION['csrf_token'] = hash('sha256', uniqid((string) mt_rand(), true));
    }
}

/*
|--------------------------------------------------------------------------
| Vendor actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateVendorsCsrfToken()) {
        setVendorsFlash('error', 'Security token expired. Please try again.');
        redirectToVendors();
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $vendorId = isset($_POST['vendor_id']) ? (int) $_POST['vendor_id'] : 0;

    try {
        if ($vendorId <= 0) {
            throw new RuntimeException('Invalid vendor selected.');
        }

        $vendor = vendorRecord($pdo, $vendorId);

        if ($action === 'accept') {
            if ((string) $vendor['status'] !== 'pending') {
                throw new RuntimeException('Only pending vendor applications can be accepted.');
            }

            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                "UPDATE vendors
                 SET status = 'accepted',
                     rejection_reason = NULL,
                     reviewed_at = NOW()
                 WHERE vendor_id = :vendor_id"
            );
            $statement->execute(['vendor_id' => $vendorId]);

            $statement = $pdo->prepare(
                "UPDATE users
                 SET role = 'vendor', status = 'active'
                 WHERE user_id = :user_id"
            );
            $statement->execute(['user_id' => (int) $vendor['user_id']]);

            $statement = $pdo->prepare(
                'INSERT INTO permission (vendor_id, upload_limit, can_delete)
                 VALUES (:vendor_id, 10, 1)
                 ON DUPLICATE KEY UPDATE vendor_id = VALUES(vendor_id)'
            );
            $statement->execute(['vendor_id' => $vendorId]);

            $pdo->commit();
            setVendorsFlash('success', 'Vendor application accepted successfully.');
            redirectToVendors();
        }

        if ($action === 'reject') {
            if ((string) $vendor['status'] !== 'pending') {
                throw new RuntimeException('Only pending vendor applications can be rejected.');
            }

            $reason = trim(isset($_POST['rejection_reason']) ? (string) $_POST['rejection_reason'] : '');

            if ($reason === '') {
                throw new RuntimeException('Please enter a rejection reason.');
            }

            if (mb_strlen($reason) > 2000) {
                throw new RuntimeException('Rejection reason cannot be longer than 2,000 characters.');
            }

            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                "UPDATE vendors
                 SET status = 'rejected',
                     rejection_reason = :rejection_reason,
                     reviewed_at = NOW()
                 WHERE vendor_id = :vendor_id"
            );
            $statement->execute([
                'rejection_reason' => $reason,
                'vendor_id' => $vendorId,
            ]);

            $statement = $pdo->prepare('DELETE FROM vendor_markets WHERE vendor_id = :vendor_id');
            $statement->execute(['vendor_id' => $vendorId]);

            $pdo->commit();
            setVendorsFlash('success', 'Vendor application rejected.');
            redirectToVendors();
        }

        if ($action === 'set_pending') {
            if ((string) $vendor['status'] !== 'rejected') {
                throw new RuntimeException('Only rejected applications can be returned to pending.');
            }

            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                "UPDATE vendors
                 SET status = 'pending',
                     rejection_reason = NULL,
                     reviewed_at = NULL
                 WHERE vendor_id = :vendor_id"
            );
            $statement->execute(['vendor_id' => $vendorId]);

            $statement = $pdo->prepare('DELETE FROM vendor_markets WHERE vendor_id = :vendor_id');
            $statement->execute(['vendor_id' => $vendorId]);

            $pdo->commit();
            setVendorsFlash('success', 'Vendor application moved back to pending.');
            redirectToVendors();
        }

        if ($action === 'assign_markets') {
            if ((string) $vendor['status'] !== 'accepted') {
                throw new RuntimeException('Only accepted vendors can be assigned to markets.');
            }

            if ((string) $vendor['user_status'] !== 'active') {
                throw new RuntimeException('Activate the vendor account before changing market assignments.');
            }

            $submittedMarketIds = isset($_POST['market_ids']) && is_array($_POST['market_ids'])
                ? $_POST['market_ids']
                : [];
            $marketIds = [];

            foreach ($submittedMarketIds as $marketIdValue) {
                $marketId = (int) $marketIdValue;
                if ($marketId > 0) {
                    $marketIds[$marketId] = $marketId;
                }
            }
            $marketIds = array_values($marketIds);

            if (count($marketIds) > 0) {
                $placeholders = [];
                $marketParameters = [];

                foreach ($marketIds as $index => $marketId) {
                    $key = ':market_' . $index;
                    $placeholders[] = $key;
                    $marketParameters[$key] = $marketId;
                }

                $statement = $pdo->prepare(
                    'SELECT COUNT(*) FROM markets WHERE market_id IN (' . implode(', ', $placeholders) . ')'
                );
                foreach ($marketParameters as $key => $value) {
                    $statement->bindValue($key, $value, PDO::PARAM_INT);
                }
                $statement->execute();

                if ((int) $statement->fetchColumn() !== count($marketIds)) {
                    throw new RuntimeException('One or more selected markets were not found.');
                }
            }

            $pdo->beginTransaction();

            $statement = $pdo->prepare('DELETE FROM vendor_markets WHERE vendor_id = :vendor_id');
            $statement->execute(['vendor_id' => $vendorId]);

            if (count($marketIds) > 0) {
                $insert = $pdo->prepare(
                    'INSERT INTO vendor_markets (vendor_id, market_id)
                     VALUES (:vendor_id, :market_id)'
                );

                foreach ($marketIds as $marketId) {
                    $insert->execute([
                        'vendor_id' => $vendorId,
                        'market_id' => $marketId,
                    ]);
                }
            }

            $pdo->commit();
            setVendorsFlash('success', 'Vendor market assignments updated.');
            redirectToVendors();
        }

        if ($action === 'update_permission') {
            if ((string) $vendor['status'] !== 'accepted') {
                throw new RuntimeException('Only accepted vendors can receive product permissions.');
            }

            if ((string) $vendor['user_status'] !== 'active') {
                throw new RuntimeException('Activate the vendor account before changing product permissions.');
            }

            $uploadLimitRaw = isset($_POST['upload_limit']) ? trim((string) $_POST['upload_limit']) : '';

            if ($uploadLimitRaw === '' || filter_var($uploadLimitRaw, FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException('Upload limit must be a whole number.');
            }

            $uploadLimit = (int) $uploadLimitRaw;
            $canDelete = isset($_POST['can_delete']) ? 1 : 0;

            if ($uploadLimit < 0 || $uploadLimit > 10000) {
                throw new RuntimeException('Upload limit must be between 0 and 10,000.');
            }

            $statement = $pdo->prepare(
                'INSERT INTO permission (vendor_id, upload_limit, can_delete)
                 VALUES (:vendor_id, :upload_limit, :can_delete)
                 ON DUPLICATE KEY UPDATE
                    upload_limit = VALUES(upload_limit),
                    can_delete = VALUES(can_delete)'
            );
            $statement->execute([
                'vendor_id' => $vendorId,
                'upload_limit' => $uploadLimit,
                'can_delete' => $canDelete,
            ]);

            setVendorsFlash('success', 'Vendor product permissions updated.');
            redirectToVendors();
        }

        if ($action === 'toggle_account') {
            if ((string) $vendor['status'] !== 'accepted') {
                throw new RuntimeException('Only accepted vendors can be suspended or activated.');
            }

            $newStatus = (string) $vendor['user_status'] === 'active' ? 'suspended' : 'active';

            $statement = $pdo->prepare('UPDATE users SET status = :status WHERE user_id = :user_id');
            $statement->execute([
                'status' => $newStatus,
                'user_id' => (int) $vendor['user_id'],
            ]);

            setVendorsFlash(
                'success',
                $newStatus === 'active'
                    ? 'Vendor account activated successfully.'
                    : 'Vendor account suspended successfully.'
            );
            redirectToVendors();
        }

        throw new RuntimeException('Unknown vendor action.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception instanceof PDOException) {
            error_log('Admin vendor database error: ' . $exception->getMessage());
            setVendorsFlash('error', 'The database could not complete the vendor action.');
        } else {
            setVendorsFlash('error', $exception->getMessage());
        }
        redirectToVendors();
    }
}

/*
|--------------------------------------------------------------------------
| Page data
|--------------------------------------------------------------------------
*/
$flash = isset($_SESSION['vendors_flash']) && is_array($_SESSION['vendors_flash'])
    ? $_SESSION['vendors_flash']
    : null;
unset($_SESSION['vendors_flash']);

$marketOptions = $pdo->query(
    'SELECT m.market_id, m.market_name, c.city_name, c.administrative_division
     FROM markets m
     INNER JOIN cities c ON c.city_id = m.city_id
     ORDER BY c.city_name ASC, m.market_name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$statsStatement = $pdo->query(
    "SELECT
        COUNT(*) AS total_vendors,
        COALESCE(SUM(status = 'pending'), 0) AS pending_vendors,
        COALESCE(SUM(status = 'accepted'), 0) AS accepted_vendors,
        COALESCE(SUM(status = 'rejected'), 0) AS rejected_vendors
     FROM vendors"
);
$stats = $statsStatement->fetch(PDO::FETCH_ASSOC);
$totalVendors = (int) $stats['total_vendors'];
$pendingVendors = (int) $stats['pending_vendors'];
$acceptedVendors = (int) $stats['accepted_vendors'];
$rejectedVendors = (int) $stats['rejected_vendors'];

$search = trim(
    isset($_GET['q'])
        ? (string) $_GET['q']
        : ''
);

$status = strtolower(
    trim(
        isset($_GET['status'])
            ? (string) $_GET['status']
            : 'all'
    )
);

$account = strtolower(
    trim(
        isset($_GET['account'])
            ? (string) $_GET['account']
            : 'all'
    )
);

$selectedMarket = isset($_GET['market'])
    ? max(0, (int) $_GET['market'])
    : 0;

$sort = strtolower(
    trim(
        isset($_GET['sort'])
            ? (string) $_GET['sort']
            : 'newest'
    )
);

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 10;

$allowedStatuses = ['all', 'pending', 'accepted', 'rejected'];
$allowedAccounts = ['all', 'active', 'suspended'];
$allowedSorts = ['newest', 'oldest', 'name_asc', 'name_desc', 'products_desc', 'markets_desc'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}
if (!in_array($account, $allowedAccounts, true)) {
    $account = 'all';
}
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

$where = ['1 = 1'];
$params = [];

if ($search !== '') {
    $where[] = '(v.vendor_name LIKE :search_vendor
                 OR u.user_name LIKE :search_user
                 OR u.email LIKE :search_email
                 OR u.phone_number LIKE :search_phone
                 OR v.address LIKE :search_address)';
    $searchValue = '%' . $search . '%';
    $params['search_vendor'] = $searchValue;
    $params['search_user'] = $searchValue;
    $params['search_email'] = $searchValue;
    $params['search_phone'] = $searchValue;
    $params['search_address'] = $searchValue;
}

if ($status !== 'all') {
    $where[] = 'v.status = :vendor_status';
    $params['vendor_status'] = $status;
}

if ($account !== 'all') {
    $where[] = 'u.status = :account_status';
    $params['account_status'] = $account;
}

if ($selectedMarket > 0) {
    $where[] = 'EXISTS (
        SELECT 1
        FROM vendor_markets vm_filter
        WHERE vm_filter.vendor_id = v.vendor_id
          AND vm_filter.market_id = :market_filter
    )';
    $params['market_filter'] = $selectedMarket;
}

$whereSql = implode(' AND ', $where);

$countStatement = $pdo->prepare(
    'SELECT COUNT(*)
     FROM vendors v
     INNER JOIN users u ON u.user_id = v.user_id
     WHERE ' . $whereSql
);
$countStatement->execute($params);
$filteredTotal = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$orderBy = 'v.created_at DESC, v.vendor_id DESC';
if ($sort === 'oldest') {
    $orderBy = 'v.created_at ASC, v.vendor_id ASC';
} elseif ($sort === 'name_asc') {
    $orderBy = 'v.vendor_name ASC, v.vendor_id ASC';
} elseif ($sort === 'name_desc') {
    $orderBy = 'v.vendor_name DESC, v.vendor_id DESC';
} elseif ($sort === 'products_desc') {
    $orderBy = 'product_count DESC, v.vendor_name ASC';
} elseif ($sort === 'markets_desc') {
    $orderBy = 'market_count DESC, v.vendor_name ASC';
}

$listSql =
    'SELECT
        v.vendor_id,
        v.user_id,
        v.vendor_name,
        v.address,
        v.status,
        v.rejection_reason,
        v.reviewed_at,
        v.created_at,
        v.updated_at,
        u.user_name,
        u.phone_number,
        u.email,
        u.status AS user_status,
        u.last_login,
        COALESCE(pm.upload_limit, 10) AS upload_limit,
        COALESCE(pm.can_delete, 1) AS can_delete,
        (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.vendor_id) AS product_count,
        (SELECT COUNT(*) FROM vendor_markets vm_count WHERE vm_count.vendor_id = v.vendor_id) AS market_count,
        (SELECT GROUP_CONCAT(vm_ids.market_id ORDER BY vm_ids.market_id SEPARATOR \',\')
         FROM vendor_markets vm_ids
         WHERE vm_ids.vendor_id = v.vendor_id) AS assigned_market_ids,
        (SELECT GROUP_CONCAT(
                    CONCAT(m2.market_name, \' — \', c2.city_name)
                    ORDER BY c2.city_name, m2.market_name
                    SEPARATOR \'||\'
                )
         FROM vendor_markets vm_names
         INNER JOIN markets m2 ON m2.market_id = vm_names.market_id
         INNER JOIN cities c2 ON c2.city_id = m2.city_id
         WHERE vm_names.vendor_id = v.vendor_id) AS assigned_market_names
     FROM vendors v
     INNER JOIN users u ON u.user_id = v.user_id
     LEFT JOIN permission pm ON pm.vendor_id = v.vendor_id
     WHERE ' . $whereSql . '
     ORDER BY ' . $orderBy . '
     LIMIT :limit OFFSET :offset';

$listStatement = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStatement->bindValue(
        ':' . $key,
        $value,
        is_int($value)
            ? PDO::PARAM_INT
            : PDO::PARAM_STR
    );
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$vendors = $listStatement->fetchAll(PDO::FETCH_ASSOC);

$fromRecord = $filteredTotal > 0 ? $offset + 1 : 0;
$toRecord = min($offset + $perPage, $filteredTotal);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendors | Local Farmers Marketplace</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Vendors Page - Compact Production Admin UI at Browser Zoom 100%
        |--------------------------------------------------------------------------
        | - aligns with compact 176px admin sidebar
        | - keeps desktop controls/table dense and readable
        | - hides visual scrollbars while preserving scrolling
        |--------------------------------------------------------------------------
        */

        html,
        body {
            overflow-x: hidden;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .vendors-scroll-hidden,
        .vendors-modal-scroll {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .vendors-scroll-hidden::-webkit-scrollbar,
        .vendors-modal-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .vendors-main {
            min-width: 0;
        }

        .vendors-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
        }

        .vendors-stat-card,
        .vendors-filter-panel,
        .vendors-list-panel {
            min-width: 0;
        }

        .vendors-table {
            width: 100%;
            min-width: 1120px;
        }

        .vendors-table th,
        .vendors-table td {
            vertical-align: top;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            .vendors-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .vendors-shell {
                margin-left: 176px !important;
            }

            .vendors-main {
                padding: 14px 18px 24px !important;
            }

            .vendors-stats {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: .75rem;
                margin-bottom: .85rem !important;
            }

            .vendors-stat-card {
                min-height: 84px;
                border-radius: 14px !important;
                padding: 12px 14px !important;
            }

            .vendors-stat-card p:first-child {
                font-size: 9px !important;
                letter-spacing: .04em !important;
            }

            .vendors-stat-card p.text-3xl {
                margin-top: 5px !important;
                font-size: 20px !important;
                line-height: 1 !important;
            }

            .vendors-stat-card .h-12 {
                width: 38px !important;
                height: 38px !important;
                border-radius: 10px !important;
            }

            .vendors-stat-card .text-xl {
                font-size: 15px !important;
            }

            .vendors-filter-panel,
            .vendors-list-panel {
                border-radius: 14px !important;
            }

            .vendors-filter-panel {
                padding: 12px 14px !important;
                margin-bottom: .85rem !important;
            }

            .vendors-filter-grid {
                display: grid !important;
                grid-template-columns:
                    minmax(220px, 1.45fr)
                    minmax(120px, .72fr)
                    minmax(120px, .72fr)
                    minmax(140px, .9fr)
                    minmax(145px, .92fr)
                    auto !important;
                gap: 8px !important;
                align-items: center;
            }

            .vendors-filter-control,
            .vendors-filter-button,
            .vendors-clear-button {
                height: 36px !important;
                border-radius: 9px !important;
                font-size: 10px !important;
            }

            .vendors-filter-control {
                padding-left: 10px !important;
                padding-right: 10px !important;
            }

            .vendors-search {
                padding-left: 34px !important;
            }

            .vendors-search-icon {
                left: 12px !important;
                font-size: 10px !important;
            }

            .vendors-filter-actions {
                gap: 6px !important;
                justify-content: flex-end !important;
            }

            .vendors-filter-button {
                padding-left: 13px !important;
                padding-right: 13px !important;
            }

            .vendors-clear-button {
                padding-left: 11px !important;
                padding-right: 11px !important;
            }

            .vendors-list-head {
                padding: 11px 14px !important;
            }

            .vendors-list-head h2 {
                font-size: 14px !important;
            }

            .vendors-list-head p,
            .vendors-list-head > span {
                font-size: 9px !important;
            }

            .vendors-list-head > span {
                padding: 4px 8px !important;
            }

            .vendors-table {
                min-width: 0 !important;
                table-layout: fixed;
            }

            .vendors-table thead {
                font-size: 8px !important;
            }

            .vendors-table th {
                padding: 8px 9px !important;
            }

            .vendors-table td {
                padding: 9px !important;
                font-size: 9px !important;
            }

            .vendors-table th:nth-child(1),
            .vendors-table td:nth-child(1) { width: 16%; }

            .vendors-table th:nth-child(2),
            .vendors-table td:nth-child(2) { width: 13%; }

            .vendors-table th:nth-child(3),
            .vendors-table td:nth-child(3) { width: 11%; }

            .vendors-table th:nth-child(4),
            .vendors-table td:nth-child(4) { width: 18%; }

            .vendors-table th:nth-child(5),
            .vendors-table td:nth-child(5) { width: 11%; }

            .vendors-table th:nth-child(6),
            .vendors-table td:nth-child(6) { width: 7%; }

            .vendors-table th:nth-child(7),
            .vendors-table td:nth-child(7) { width: 9%; }

            .vendors-table th:nth-child(8),
            .vendors-table td:nth-child(8) { width: 15%; }

            .vendors-avatar {
                width: 34px !important;
                height: 34px !important;
                border-radius: 9px !important;
                font-size: 10px !important;
            }

            .vendors-name {
                font-size: 10px !important;
            }

            .vendors-owner,
            .vendors-contact-main {
                font-size: 9px !important;
            }

            .vendors-meta,
            .vendors-contact-meta {
                font-size: 8px !important;
                line-height: 1.35 !important;
            }

            .vendors-status-badge,
            .vendors-account-badge,
            .vendors-market-badge,
            .vendors-more-badge {
                font-size: 7px !important;
                line-height: 1.2 !important;
                padding: 3px 6px !important;
            }

            .vendors-permission-main {
                font-size: 9px !important;
            }

            .vendors-permission-sub {
                margin-top: 3px !important;
                font-size: 8px !important;
                line-height: 1.35 !important;
            }

            .vendors-product-count {
                min-width: 34px !important;
                border-radius: 8px !important;
                padding: 6px 8px !important;
                font-size: 9px !important;
            }

            .vendors-action-wrap {
                display: inline-flex !important;
                flex-wrap: nowrap !important;
                white-space: nowrap !important;
                gap: 5px !important;
                max-width: none !important;
            }

            .vendors-action-button {
                width: 28px !important;
                height: 28px !important;
                min-width: 28px !important;
                flex: 0 0 28px !important;
                border-radius: 7px !important;
            }

            .vendors-action-button i {
                font-size: 8px !important;
            }

            .vendors-pagination {
                padding: 10px 14px !important;
            }

            .vendors-pagination p {
                font-size: 9px !important;
            }

            .vendors-pagination a {
                width: 30px !important;
                min-width: 30px !important;
                height: 30px !important;
                border-radius: 7px !important;
                font-size: 9px !important;
            }
        }

        @media (max-width: 1023px) {
            .vendors-table {
                min-width: 1120px;
            }
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>
<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">
<div class="min-h-screen">
    <?php require_once './sidebar.php'; ?>

    <div class="vendors-shell min-h-screen lg:ml-64">
        <?php require_once './header.php'; ?>

        <main class="vendors-main px-4 pb-10 pt-5 sm:px-6 xl:px-7">
            <?php if ($flash): ?>
                <?php
                $isSuccess = isset($flash['type']) && $flash['type'] === 'success';
                $flashClasses = $isSuccess
                    ? 'border-green-200 bg-green-50 text-green-800'
                    : 'border-red-200 bg-red-50 text-red-800';
                $flashIcon = $isSuccess ? 'fa-circle-check' : 'fa-circle-exclamation';
                ?>
                <div id="flashMessage" class="mb-5 flex items-center gap-3 rounded-xl border px-4 py-3 text-sm font-semibold <?= e($flashClasses) ?>">
                    <i class="fa-solid <?= e($flashIcon) ?>"></i>
                    <span class="flex-1"><?= e(isset($flash['message']) ? $flash['message'] : '') ?></span>
                    <button type="button" onclick="document.getElementById('flashMessage').remove()" class="grid h-7 w-7 place-items-center rounded-lg hover:bg-black/5">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            <?php endif; ?>

            <section class="vendors-stats mb-5">
                <article class="vendors-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total Vendors</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalVendors)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-slate-100 text-slate-600">
                            <i class="fa-solid fa-store text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="vendors-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pending</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($pendingVendors)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-amber-50 text-amber-600">
                            <i class="fa-solid fa-hourglass-half text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="vendors-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Accepted</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($acceptedVendors)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-circle-check text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="vendors-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Rejected</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($rejectedVendors)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-red-50 text-red-600">
                            <i class="fa-solid fa-circle-xmark text-xl"></i>
                        </div>
                    </div>
                </article>
            </section>

            <?php if (count($marketOptions) === 0): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                    <div>
                        <p class="font-extrabold">No markets are available</p>
                        <p class="mt-1 text-xs leading-5 text-amber-800">You can accept vendors now, but you must create a market before assigning them.</p>
                    </div>
                    <a href="markets.php" class="ml-auto shrink-0 rounded-xl bg-amber-600 px-3 py-2 text-xs font-bold text-white hover:bg-amber-700">Add Market</a>
                </div>
            <?php endif; ?>

            <!-- Search / Filters -->
            <section class="vendors-filter-panel mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-card sm:p-5">

                <form method="get"
                      action="vendors.php"
                      class="vendors-filter-grid grid grid-cols-1 gap-3 sm:grid-cols-2">

                    <label class="relative block">
                        <span class="sr-only">Search vendors</span>
                        <i class="vendors-search-icon fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                        <input
                            type="search"
                            name="q"
                            value="<?= e($search) ?>"
                            placeholder="Search vendor, owner, email or phone..."
                            class="vendors-filter-control vendors-search h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100"
                        >
                    </label>

                    <select name="status"
                            class="vendors-filter-control h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-100">

                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                            All Application Statuses
                        </option>

                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>
                            Pending
                        </option>

                        <option value="accepted" <?= $status === 'accepted' ? 'selected' : '' ?>>
                            Accepted
                        </option>

                        <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>
                            Rejected
                        </option>
                    </select>

                    <select name="account"
                            class="vendors-filter-control h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-100">

                        <option value="all" <?= $account === 'all' ? 'selected' : '' ?>>
                            All Account Statuses
                        </option>

                        <option value="active" <?= $account === 'active' ? 'selected' : '' ?>>
                            Active Accounts
                        </option>

                        <option value="suspended" <?= $account === 'suspended' ? 'selected' : '' ?>>
                            Suspended Accounts
                        </option>
                    </select>

                    <select name="market"
                            class="vendors-filter-control h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-100">

                        <option value="0">All Markets</option>

                        <?php foreach ($marketOptions as $market): ?>
                            <option value="<?= e($market['market_id']) ?>"
                                <?= $selectedMarket === (int) $market['market_id'] ? 'selected' : '' ?>>

                                <?= e($market['market_name'] . ' — ' . $market['city_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="sort"
                            class="vendors-filter-control h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-100">

                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>
                            Newest Applications
                        </option>

                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>
                            Oldest Applications
                        </option>

                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>
                            Vendor Name A–Z
                        </option>

                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>
                            Vendor Name Z–A
                        </option>

                        <option value="products_desc" <?= $sort === 'products_desc' ? 'selected' : '' ?>>
                            Most Products
                        </option>

                        <option value="markets_desc" <?= $sort === 'markets_desc' ? 'selected' : '' ?>>
                            Most Markets
                        </option>
                    </select>

                    <div class="vendors-filter-actions flex gap-2">

                        <button type="submit"
                                class="vendors-filter-button inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-bold text-white transition hover:bg-green-700 sm:flex-none">

                            <i class="fa-solid fa-filter text-xs"></i>
                            Filter
                        </button>

                        <a href="vendors.php"
                           class="vendors-clear-button inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 sm:flex-none">

                            <i class="fa-solid fa-rotate-left text-xs"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </section>

            <section class="vendors-list-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
                <div class="vendors-list-head flex flex-col gap-3 border-b border-slate-100 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">

                    <div>
                        <h2 class="text-lg font-extrabold text-slate-950">
                            Vendor List
                        </h2>

                        <p class="mt-1 text-xs text-slate-400">
                            Showing
                            <?= e(number_format($fromRecord)) ?>
                            to
                            <?= e(number_format($toRecord)) ?>
                            of
                            <?= e(number_format($filteredTotal)) ?>
                            vendor(s)
                        </p>
                    </div>

                    <span class="inline-flex w-fit rounded-full bg-green-50 px-3 py-1.5 text-xs font-bold text-green-700">
                        <?= e($status === 'all' ? 'All Statuses' : ucfirst($status)) ?>
                    </span>
                </div>

                <div class="vendors-scroll-hidden overflow-x-auto overscroll-x-contain">
                    <table class="vendors-table w-full min-w-[980px] xl:min-w-[1180px] text-left">
                        <thead class="bg-slate-50/80 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-5 py-3.5">Vendor</th>
                            <th class="px-5 py-3.5">Contact</th>
                            <th class="px-5 py-3.5">Application</th>
                            <th class="px-5 py-3.5">Assigned Markets</th>
                            <th class="hidden px-5 py-3.5 xl:table-cell">Product Permission</th>
                            <th class="px-5 py-3.5 text-center">Products</th>
                            <th class="px-5 py-3.5">Account</th>
                            <th class="px-5 py-3.5 text-right">Actions</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php if (count($vendors) === 0): ?>
                            <tr>
                                <td colspan="8" class="px-5 py-16 text-center">
                                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-100 text-slate-400">
                                        <i class="fa-solid fa-user-slash text-xl"></i>
                                    </div>
                                    <p class="mt-4 text-sm font-extrabold text-slate-700">No vendors found</p>
                                    <p class="mt-1 text-xs text-slate-400">Change your filters or wait for a new vendor registration.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($vendors as $vendorRow): ?>
                                <?php
                                $vendorStatus = (string) $vendorRow['status'];
                                if ($vendorStatus === 'accepted') {
                                    $statusClasses = 'bg-green-50 text-green-700 ring-green-600/10';
                                    $statusIcon = 'fa-circle-check';
                                } elseif ($vendorStatus === 'rejected') {
                                    $statusClasses = 'bg-red-50 text-red-700 ring-red-600/10';
                                    $statusIcon = 'fa-circle-xmark';
                                } else {
                                    $statusClasses = 'bg-amber-50 text-amber-700 ring-amber-600/10';
                                    $statusIcon = 'fa-hourglass-half';
                                }

                                $marketIds = [];
                                if (!empty($vendorRow['assigned_market_ids'])) {
                                    foreach (explode(',', (string) $vendorRow['assigned_market_ids']) as $marketIdValue) {
                                        $marketIdValue = (int) $marketIdValue;
                                        if ($marketIdValue > 0) {
                                            $marketIds[] = $marketIdValue;
                                        }
                                    }
                                }

                                $marketNames = !empty($vendorRow['assigned_market_names'])
                                    ? explode('||', (string) $vendorRow['assigned_market_names'])
                                    : [];

                                $marketsData = [
                                    'vendor_id' => (int) $vendorRow['vendor_id'],
                                    'vendor_name' => (string) $vendorRow['vendor_name'],
                                    'market_ids' => $marketIds,
                                ];
                                $permissionData = [
                                    'vendor_id' => (int) $vendorRow['vendor_id'],
                                    'vendor_name' => (string) $vendorRow['vendor_name'],
                                    'upload_limit' => (int) $vendorRow['upload_limit'],
                                    'can_delete' => (int) $vendorRow['can_delete'] === 1,
                                ];
                                $rejectData = [
                                    'vendor_id' => (int) $vendorRow['vendor_id'],
                                    'vendor_name' => (string) $vendorRow['vendor_name'],
                                    'rejection_reason' => (string) ($vendorRow['rejection_reason'] !== null ? $vendorRow['rejection_reason'] : ''),
                                ];

                                $vendorInitial = mb_substr(trim((string) $vendorRow['vendor_name']), 0, 1);
                                if ($vendorInitial === '') {
                                    $vendorInitial = 'V';
                                }
                                ?>
                                <tr class="transition hover:bg-slate-50/70">
                                    <td class="px-5 py-4 align-top">
                                        <div class="flex items-start gap-3">
                                            <div class="vendors-avatar grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-green-600 text-sm font-extrabold uppercase text-white shadow-sm">
                                                <?= e($vendorInitial) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="vendors-name max-w-[250px] truncate text-sm font-extrabold text-slate-900"><?= e($vendorRow['vendor_name']) ?></p>
                                                <p class="vendors-owner mt-0.5 max-w-[250px] truncate text-xs font-medium text-slate-500"><?= e($vendorRow['user_name']) ?></p>
                                                <p class="vendors-meta mt-1 max-w-[270px] truncate text-[11px] text-slate-400" title="<?= e($vendorRow['address']) ?>">
                                                    <i class="fa-solid fa-location-dot mr-1"></i><?= e($vendorRow['address']) ?>
                                                </p>
                                                <p class="vendors-meta mt-1 text-[10px] font-semibold text-slate-400">Vendor #<?= e($vendorRow['vendor_id']) ?> · <?= e(date('M d, Y', strtotime((string) $vendorRow['created_at']))) ?></p>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <p class="vendors-contact-main text-sm font-semibold text-slate-700"><?= e($vendorRow['email']) ?></p>
                                        <p class="vendors-contact-meta mt-1 text-xs text-slate-400"><i class="fa-solid fa-phone mr-1.5"></i><?= e($vendorRow['phone_number']) ?></p>
                                        <p class="vendors-contact-meta mt-1 hidden text-[11px] text-slate-400 lg:block">
                                            Last login: <?= !empty($vendorRow['last_login']) ? e(date('M d, Y', strtotime((string) $vendorRow['last_login']))) : 'Never' ?>
                                        </p>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <span class="vendors-status-badge inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-extrabold ring-1 ring-inset <?= e($statusClasses) ?>">
                                            <i class="fa-solid <?= e($statusIcon) ?> text-[10px]"></i>
                                            <?= e(ucfirst($vendorStatus)) ?>
                                        </span>
                                        <?php if ($vendorStatus === 'rejected' && !empty($vendorRow['rejection_reason'])): ?>
                                            <p class="mt-2 max-w-[220px] truncate text-[11px] leading-5 text-red-500" title="<?= e($vendorRow['rejection_reason']) ?>">
                                                <?= e($vendorRow['rejection_reason']) ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($vendorRow['reviewed_at'])): ?>
                                            <p class="mt-1 text-[10px] text-slate-400">Reviewed <?= e(date('M d, Y', strtotime((string) $vendorRow['reviewed_at']))) ?></p>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <?php if (count($marketNames) === 0): ?>
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500">
                                                <i class="fa-solid fa-store-slash text-[10px]"></i>No market
                                            </span>
                                        <?php else: ?>
                                            <div class="flex max-w-[270px] flex-wrap gap-1.5">
                                                <?php foreach (array_slice($marketNames, 0, 2) as $marketName): ?>
                                                    <span class="vendors-market-badge max-w-[250px] truncate rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-bold text-blue-700" title="<?= e($marketName) ?>">
                                                        <?= e($marketName) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php if (count($marketNames) > 2): ?>
                                                    <span class="vendors-more-badge rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600">+<?= e(count($marketNames) - 2) ?> more</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="hidden px-5 py-4 align-top xl:table-cell">
                                        <p class="vendors-permission-main text-sm font-extrabold text-slate-700">
                                            <?= e(number_format((int) $vendorRow['upload_limit'])) ?> uploads
                                        </p>
                                        <p class="vendors-permission-sub mt-1 text-xs <?= (int) $vendorRow['can_delete'] === 1 ? 'text-green-600' : 'text-red-500' ?>">
                                            <i class="fa-solid <?= (int) $vendorRow['can_delete'] === 1 ? 'fa-check' : 'fa-xmark' ?> mr-1"></i>
                                            Delete <?= (int) $vendorRow['can_delete'] === 1 ? 'allowed' : 'blocked' ?>
                                        </p>
                                    </td>

                                    <td class="px-5 py-4 text-center align-top">
                                        <a href="products.php?vendor=<?= e($vendorRow['vendor_id']) ?>" class="vendors-product-count inline-flex min-w-12 items-center justify-center rounded-xl bg-slate-100 px-3 py-2 text-sm font-extrabold text-slate-700 transition hover:bg-green-50 hover:text-green-700">
                                            <?= e(number_format((int) $vendorRow['product_count'])) ?>
                                        </a>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <?php if ((string) $vendorRow['user_status'] === 'active'): ?>
                                            <span class="vendors-account-badge inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-extrabold text-green-700">
                                                <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>Active
                                            </span>
                                        <?php else: ?>
                                            <span class="vendors-account-badge inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-extrabold text-red-700">
                                                <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>Suspended
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-5 py-4 align-top text-right">
                                        <div class="vendors-action-wrap inline-flex flex-nowrap items-center justify-end gap-2 whitespace-nowrap">
                                            <?php if ($vendorStatus === 'pending'): ?>
                                                <form method="post" class="inline" onsubmit="return confirm('Accept this vendor application?');">
                                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="accept">
                                                    <input type="hidden" name="vendor_id" value="<?= e($vendorRow['vendor_id']) ?>">
                                                    <button type="submit" title="Accept application" class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl border border-green-200 bg-green-50 text-green-600 transition hover:bg-green-100">
                                                        <i class="fa-solid fa-check text-xs"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($vendorStatus === 'accepted' && (string) $vendorRow['user_status'] === 'active'): ?>
                                                <button type="button"
                                                        data-vendor-markets="<?= e(json_encode($marketsData, JSON_UNESCAPED_UNICODE)) ?>"
                                                        onclick="openMarketsModal(this)"
                                                        title="Assign markets"
                                                        class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-600">
                                                    <i class="fa-solid fa-store text-xs"></i>
                                                </button>

                                                <button type="button"
                                                        data-vendor-permission="<?= e(json_encode($permissionData, JSON_UNESCAPED_UNICODE)) ?>"
                                                        onclick="openPermissionModal(this)"
                                                        title="Product permissions"
                                                        class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-violet-200 hover:bg-violet-50 hover:text-violet-600">
                                                    <i class="fa-solid fa-sliders text-xs"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($vendorStatus === 'pending'): ?>
                                                <button type="button"
                                                        data-vendor-reject="<?= e(json_encode($rejectData, JSON_UNESCAPED_UNICODE)) ?>"
                                                        onclick="openRejectModal(this)"
                                                        title="Reject application"
                                                        class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600">
                                                    <i class="fa-solid fa-ban text-xs"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($vendorStatus === 'rejected'): ?>
                                                <form method="post" class="inline" onsubmit="return confirm('Return this rejected application to pending for reconsideration?');">
                                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="set_pending">
                                                    <input type="hidden" name="vendor_id" value="<?= e($vendorRow['vendor_id']) ?>">
                                                    <button type="submit" title="Reconsider application" class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-amber-200 hover:bg-amber-50 hover:text-amber-600">
                                                        <i class="fa-solid fa-rotate-left text-xs"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($vendorStatus === 'accepted'): ?>
                                                <form method="post" class="inline" onsubmit="return confirm('<?= (string) $vendorRow['user_status'] === 'active' ? 'Suspend' : 'Activate' ?> this vendor account?');">
                                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="toggle_account">
                                                    <input type="hidden" name="vendor_id" value="<?= e($vendorRow['vendor_id']) ?>">
                                                    <button type="submit" title="<?= (string) $vendorRow['user_status'] === 'active' ? 'Suspend account' : 'Activate account' ?>"
                                                            class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition <?= (string) $vendorRow['user_status'] === 'active' ? 'hover:border-red-200 hover:bg-red-50 hover:text-red-600' : 'border-green-200 bg-green-50 text-green-600 hover:bg-green-100' ?>">
                                                        <i class="fa-solid <?= (string) $vendorRow['user_status'] === 'active' ? 'fa-user-lock' : 'fa-user-check' ?> text-xs"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="vendors-pagination flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs font-medium text-slate-400">
                        Showing <span class="font-bold text-slate-600"><?= e(number_format($fromRecord)) ?></span>
                        to <span class="font-bold text-slate-600"><?= e(number_format($toRecord)) ?></span>
                        of <span class="font-bold text-slate-600"><?= e(number_format($filteredTotal)) ?></span> vendors
                    </p>

                    <?php if ($totalPages > 1): ?>
                        <nav class="flex flex-wrap items-center gap-1.5">
                            <a href="?<?= e(vendorsQueryString(['page' => max(1, $page - 1)])) ?>"
                               class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-sm text-slate-500 transition hover:bg-slate-50 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </a>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            ?>
                            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                                <a href="?<?= e(vendorsQueryString(['page' => $pageNumber])) ?>"
                                   class="grid h-9 min-w-9 place-items-center rounded-xl px-2 text-xs font-extrabold transition <?= $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50' ?>">
                                    <?= e($pageNumber) ?>
                                </a>
                            <?php endfor; ?>

                            <a href="?<?= e(vendorsQueryString(['page' => min($totalPages, $page + 1)])) ?>"
                               class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-sm text-slate-500 transition hover:bg-slate-50 <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>">
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </a>
                        </nav>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- Reject vendor modal -->
<div id="rejectModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="font-extrabold text-slate-950">Reject Vendor Application</h2>
                <p id="rejectVendorLabel" class="mt-0.5 text-xs text-slate-400"></p>
            </div>
            <button type="button" onclick="closeModal('rejectModal')" class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" class="p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="reject">
            <input id="reject_vendor_id" type="hidden" name="vendor_id">

            <div class="rounded-xl border border-red-100 bg-red-50 p-3 text-xs leading-5 text-red-700">
                <i class="fa-solid fa-circle-info mr-1"></i>
                Rejecting a pending application records the rejection reason. You can return the application to pending later for reconsideration.
            </div>

            <label class="mt-4 block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">Rejection Reason *</span>
                <textarea id="rejection_reason" name="rejection_reason" required maxlength="2000" rows="5" placeholder="Explain why the application was rejected..."
                          class="w-full resize-none rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-red-500 focus:ring-2 focus:ring-red-100"></textarea>
            </label>

            <div class="mt-5 flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('rejectModal')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-red-700">Reject Application</button>
            </div>
        </form>
    </div>
</div>

<!-- Assign markets modal -->
<div id="marketsModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="font-extrabold text-slate-950">Assign Markets</h2>
                <p id="marketsVendorLabel" class="mt-0.5 text-xs text-slate-400"></p>
            </div>
            <button type="button" onclick="closeModal('marketsModal')" class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" class="p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="assign_markets">
            <input id="markets_vendor_id" type="hidden" name="vendor_id">

            <?php if (count($marketOptions) === 0): ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    No market exists yet. Create a market before assigning vendors.
                </div>
            <?php else: ?>
                <div class="mb-3 flex items-center justify-between">
                    <p class="text-xs font-semibold text-slate-500">Select every market this vendor may sell in.</p>
                    <div class="flex gap-2">
                        <button type="button" onclick="setAllMarkets(true)" class="text-xs font-bold text-green-600 hover:text-green-700">Select all</button>
                        <span class="text-slate-300">|</span>
                        <button type="button" onclick="setAllMarkets(false)" class="text-xs font-bold text-slate-500 hover:text-slate-700">Clear</button>
                    </div>
                </div>

                <div class="vendors-modal-scroll max-h-[380px] space-y-2 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <?php foreach ($marketOptions as $market): ?>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-transparent bg-white p-3 transition hover:border-green-200 hover:bg-green-50/50">
                            <input type="checkbox" name="market_ids[]" value="<?= e($market['market_id']) ?>" class="market-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-green-600 focus:ring-green-500">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-extrabold text-slate-800"><?= e($market['market_name']) ?></span>
                                <span class="mt-0.5 block text-xs text-slate-400"><?= e($market['city_name'] . ', ' . $market['administrative_division']) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="mt-5 flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('marketsModal')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" <?= count($marketOptions) === 0 ? 'disabled' : '' ?> class="rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50">Save Assignments</button>
            </div>
        </form>
    </div>
</div>

<!-- Product permission modal -->
<div id="permissionModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="font-extrabold text-slate-950">Product Permissions</h2>
                <p id="permissionVendorLabel" class="mt-0.5 text-xs text-slate-400"></p>
            </div>
            <button type="button" onclick="closeModal('permissionModal')" class="vendors-action-button grid h-9 w-9 place-items-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" class="p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_permission">
            <input id="permission_vendor_id" type="hidden" name="vendor_id">

            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">Maximum Product Uploads *</span>
                <input id="upload_limit" type="number" name="upload_limit" min="0" max="10000" required
                       class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                <span class="mt-1.5 block text-[11px] leading-5 text-slate-400">Set 0 to prevent new product uploads. Existing products remain visible.</span>
            </label>

            <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input id="can_delete" type="checkbox" name="can_delete" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-green-600 focus:ring-green-500">
                <span>
                    <span class="block text-sm font-extrabold text-slate-800">Allow product deletion</span>
                    <span class="mt-1 block text-xs leading-5 text-slate-400">The vendor may permanently delete products they created.</span>
                </span>
            </label>

            <div class="mt-5 flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('permissionModal')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">Save Permissions</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showModal(id) {
        var modal = document.getElementById(id);
        if (!modal) {
            return;
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
    }

    function closeModal(id) {
        var modal = document.getElementById(id);
        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    function parseButtonData(button, attributeName) {
        if (!button) {
            return null;
        }

        var raw = button.getAttribute(attributeName);
        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            alert('Unable to load vendor information.');
            return null;
        }
    }

    function openRejectModal(button) {
        var data = parseButtonData(button, 'data-vendor-reject');
        if (!data) {
            return;
        }

        document.getElementById('reject_vendor_id').value = data.vendor_id || '';
        document.getElementById('rejectVendorLabel').textContent = data.vendor_name || 'Selected vendor';
        document.getElementById('rejection_reason').value = data.rejection_reason || '';
        showModal('rejectModal');
    }

    function openMarketsModal(button) {
        var data = parseButtonData(button, 'data-vendor-markets');
        if (!data) {
            return;
        }

        document.getElementById('markets_vendor_id').value = data.vendor_id || '';
        document.getElementById('marketsVendorLabel').textContent = data.vendor_name || 'Selected vendor';

        var selectedIds = Array.isArray(data.market_ids)
            ? data.market_ids.map(function (value) { return String(value); })
            : [];

        var checkboxes = document.querySelectorAll('.market-checkbox');
        checkboxes.forEach(function (checkbox) {
            checkbox.checked = selectedIds.indexOf(String(checkbox.value)) !== -1;
        });

        showModal('marketsModal');
    }

    function setAllMarkets(checked) {
        var checkboxes = document.querySelectorAll('.market-checkbox');
        checkboxes.forEach(function (checkbox) {
            checkbox.checked = checked;
        });
    }

    function openPermissionModal(button) {
        var data = parseButtonData(button, 'data-vendor-permission');
        if (!data) {
            return;
        }

        document.getElementById('permission_vendor_id').value = data.vendor_id || '';
        document.getElementById('permissionVendorLabel').textContent = data.vendor_name || 'Selected vendor';
        document.getElementById('upload_limit').value = typeof data.upload_limit !== 'undefined' ? data.upload_limit : 10;
        document.getElementById('can_delete').checked = Boolean(data.can_delete);
        showModal('permissionModal');
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal('rejectModal');
            closeModal('marketsModal');
            closeModal('permissionModal');
        }
    });

    ['rejectModal', 'marketsModal', 'permissionModal'].forEach(function (id) {
        var modal = document.getElementById(id);
        if (!modal) {
            return;
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal(id);
            }
        });
    });
</script>
</body>
</html>