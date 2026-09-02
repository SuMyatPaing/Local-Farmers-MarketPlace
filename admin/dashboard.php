<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
fm_require_role('admin');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Yangon');

/*
|--------------------------------------------------------------------------
| Admin Dashboard - Database Driven
|--------------------------------------------------------------------------
| Save as:
| C:\xampp\htdocs\farmer_marketplace\admin\dashboard.php
|
| All statistics, charts, approvals, orders, reviews and market data below
| are loaded from the current MySQL database.
|
| PHP 7.1 compatible.
|--------------------------------------------------------------------------
*/

function ad_e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function ad_redirect($location)
{
    header('Location: ' . $location);
    exit;
}

function ad_money($amount)
{
    return number_format(
        (float) $amount,
        0
    ) . ' MMK';
}

function ad_date($value, $format)
{
    $timestamp = strtotime((string) $value);

    return $timestamp !== false
        ? date($format, $timestamp)
        : '—';
}

function ad_time_ago($date)
{
    $timestamp = strtotime((string) $date);

    if ($timestamp === false) {
        return 'Recently';
    }

    $seconds = time() - $timestamp;

    if ($seconds < 60) {
        return 'Just now';
    }

    if ($seconds < 3600) {
        return floor($seconds / 60) . 'm ago';
    }

    if ($seconds < 86400) {
        return floor($seconds / 3600) . 'h ago';
    }

    if ($seconds < 604800) {
        return floor($seconds / 86400) . 'd ago';
    }

    return date('M j, Y', $timestamp);
}

function ad_initials($name)
{
    $name = trim((string) $name);

    if ($name === '') {
        return 'A';
    }

    $parts = preg_split('/\s+/', $name);
    $letters = '';

    if (is_array($parts)) {
        $parts = array_slice($parts, 0, 2);

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (
                function_exists('mb_substr') &&
                function_exists('mb_strtoupper')
            ) {
                $letters .= mb_strtoupper(
                    mb_substr(
                        $part,
                        0,
                        1,
                        'UTF-8'
                    ),
                    'UTF-8'
                );
            } else {
                $letters .= strtoupper(
                    substr($part, 0, 1)
                );
            }
        }
    }

    return $letters !== ''
        ? $letters
        : 'A';
}

function ad_order_status($vendorCount, $pending, $confirmed, $rejected, $completed)
{
    $vendorCount = (int) $vendorCount;
    $pending = (int) $pending;
    $confirmed = (int) $confirmed;
    $rejected = (int) $rejected;
    $completed = (int) $completed;

    if (
        $vendorCount <= 0 ||
        $pending > 0
    ) {
        return array(
            'label' => 'Pending',
            'class' => 'bg-amber-50 text-amber-700 ring-amber-200'
        );
    }

    if (
        $vendorCount > 0 &&
        $rejected === $vendorCount
    ) {
        return array(
            'label' => 'Rejected',
            'class' => 'bg-red-50 text-red-700 ring-red-200'
        );
    }

    if (
        ($confirmed + $completed) > 0 &&
        $confirmed === 0 &&
        $pending === 0
    ) {
        return array(
            'label' => 'Completed',
            'class' => 'bg-blue-50 text-blue-700 ring-blue-200'
        );
    }

    if (($confirmed + $completed) > 0) {
        return array(
            'label' => $rejected > 0
                ? 'Partially Confirmed'
                : 'Confirmed',
            'class' => 'bg-green-50 text-green-700 ring-green-200'
        );
    }

    return array(
        'label' => 'Pending',
        'class' => 'bg-amber-50 text-amber-700 ring-amber-200'
    );
}

function ad_payment_label($status)
{
    $status = strtolower(
        trim((string) $status)
    );

    if ($status === 'paid') {
        return array(
            'label' => 'Successful',
            'class' => 'bg-green-50 text-green-700 ring-green-200'
        );
    }

    if ($status === 'pending_verification') {
        return array(
            'label' => 'Pending Verification',
            'class' => 'bg-blue-50 text-blue-700 ring-blue-200'
        );
    }

    if ($status === 'rejected') {
        return array(
            'label' => 'Payment Rejected',
            'class' => 'bg-red-50 text-red-700 ring-red-200'
        );
    }

    return array(
        'label' => 'Unpaid',
        'class' => 'bg-amber-50 text-amber-700 ring-amber-200'
    );
}

/*
|--------------------------------------------------------------------------
| Admin Login Guard
|--------------------------------------------------------------------------
*/

$isAdmin =
    isset($_SESSION['user_id']) &&
    (int) $_SESSION['user_id'] > 0 &&
    isset($_SESSION['role']) &&
    strtolower((string) $_SESSION['role']) === 'admin';

if (!$isAdmin) {
    ad_redirect('../signin.php?notice=login_required');
}

$adminUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$pdo = null;

$databaseFiles = array(
    __DIR__ . '/../config/database.php',
);

foreach ($databaseFiles as $databaseFile) {
    if (file_exists($databaseFile)) {
        require_once $databaseFile;
        break;
    }
}

if (
    !($pdo instanceof PDO) &&
    function_exists('getPDO')
) {
    $pdo = getPDO();
}

if (!($pdo instanceof PDO)) {
    exit(
        'Database connection is not available. ' .
        'Check config/database.php.'
    );
}

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

$pdo->setAttribute(
    PDO::ATTR_DEFAULT_FETCH_MODE,
    PDO::FETCH_ASSOC
);

/*
|--------------------------------------------------------------------------
| Current Admin
|--------------------------------------------------------------------------
*/

$adminStatement = $pdo->prepare(
    "SELECT
        user_id,
        user_name,
        email,
        status
     FROM users
     WHERE user_id = :user_id
       AND role = 'admin'
       AND status = 'active'
     LIMIT 1"
);

$adminStatement->execute(array(
    'user_id' => $adminUserId
));

$adminUser = $adminStatement->fetch();

if (!$adminUser) {
    $_SESSION = array();

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    ad_redirect('../signin.php');
}

$adminName = (string) $adminUser['user_name'];

/*
|--------------------------------------------------------------------------
| Main Statistics
|--------------------------------------------------------------------------
*/

$customerCountStatement = $pdo->query(
    "SELECT COUNT(*)
     FROM users
     WHERE role = 'user'"
);

$totalCustomers =
    (int) $customerCountStatement->fetchColumn();

$vendorCountStatement = $pdo->query(
    "SELECT COUNT(*)
     FROM vendors"
);

$totalVendors =
    (int) $vendorCountStatement->fetchColumn();

$marketCountStatement = $pdo->query(
    "SELECT COUNT(*)
     FROM markets"
);

$totalMarkets =
    (int) $marketCountStatement->fetchColumn();

$productCountStatement = $pdo->query(
    "SELECT COUNT(*)
     FROM products"
);

$totalProducts =
    (int) $productCountStatement->fetchColumn();

$orderCountStatement = $pdo->query(
    "SELECT COUNT(*)
     FROM purchase_process"
);

$totalOrders =
    (int) $orderCountStatement->fetchColumn();

/*
|--------------------------------------------------------------------------
| Verified Sales
|--------------------------------------------------------------------------
| Sum only accepted Vendor portions whose master payment was verified.
|--------------------------------------------------------------------------
*/

$verifiedSalesStatement = $pdo->query(
    "SELECT
        COALESCE(
            SUM(vo.subtotal),
            0
        )
     FROM vendor_orders vo
     INNER JOIN purchase_process pp
        ON pp.purchase_id = vo.purchase_id
     WHERE pp.payment_status = 'paid'
       AND vo.order_status IN (
            'confirmed',
            'completed'
       )"
);

$totalVerifiedSales =
    (float) $verifiedSalesStatement->fetchColumn();

/*
|--------------------------------------------------------------------------
| Attention Counters
|--------------------------------------------------------------------------
*/

$pendingVendorStatement = $pdo->query(
    "SELECT COUNT(*)
     FROM vendors
     WHERE status = 'pending'"
);

$pendingVendorCount =
    (int) $pendingVendorStatement->fetchColumn();

$pendingPaymentStatement = $pdo->query(
    "SELECT COUNT(*)
     FROM purchase_process
     WHERE payment_status = 'pending_verification'"
);

$pendingPaymentCount =
    (int) $pendingPaymentStatement->fetchColumn();

/*
|--------------------------------------------------------------------------
| Monthly Growth
|--------------------------------------------------------------------------
*/

function ad_month_growth($pdo, $table, $whereSql)
{
    $sql =
        "SELECT
            SUM(
                CASE
                    WHEN created_at >=
                        DATE_FORMAT(
                            CURDATE(),
                            '%Y-%m-01'
                        )
                    THEN 1 ELSE 0
                END
            ) AS current_month,

            SUM(
                CASE
                    WHEN created_at >=
                        DATE_FORMAT(
                            CURDATE() - INTERVAL 1 MONTH,
                            '%Y-%m-01'
                        )
                     AND created_at <
                        DATE_FORMAT(
                            CURDATE(),
                            '%Y-%m-01'
                        )
                    THEN 1 ELSE 0
                END
            ) AS previous_month

         FROM " . $table .
         ($whereSql !== ''
            ? ' WHERE ' . $whereSql
            : '');

    try {
        $row = $pdo->query($sql)->fetch();

        $current = $row
            ? (int) $row['current_month']
            : 0;

        $previous = $row
            ? (int) $row['previous_month']
            : 0;

        if ($previous === 0) {
            return $current > 0
                ? 100
                : null;
        }

        return (
            ($current - $previous) /
            $previous
        ) * 100;
    } catch (PDOException $exception) {
        return null;
    }
}

$customerGrowth =
    ad_month_growth(
        $pdo,
        'users',
        "role = 'user'"
    );

$vendorGrowth =
    ad_month_growth(
        $pdo,
        'vendors',
        ''
    );

$marketGrowth =
    ad_month_growth(
        $pdo,
        'markets',
        ''
    );

$productGrowth =
    ad_month_growth(
        $pdo,
        'products',
        ''
    );

$orderGrowth =
    ad_month_growth(
        $pdo,
        'purchase_process',
        ''
    );

$statCards = array(
    array(
        'label' => 'Customers',
        'value' => number_format($totalCustomers),
        'icon' => 'fa-users',
        'icon_class' => 'bg-green-100 text-green-600',
        'growth' => $customerGrowth
    ),
    array(
        'label' => 'Vendors',
        'value' => number_format($totalVendors),
        'icon' => 'fa-store',
        'icon_class' => 'bg-emerald-100 text-emerald-600',
        'growth' => $vendorGrowth
    ),
    array(
        'label' => 'Markets',
        'value' => number_format($totalMarkets),
        'icon' => 'fa-location-dot',
        'icon_class' => 'bg-orange-100 text-orange-500',
        'growth' => $marketGrowth
    ),
    array(
        'label' => 'Products',
        'value' => number_format($totalProducts),
        'icon' => 'fa-basket-shopping',
        'icon_class' => 'bg-amber-100 text-amber-500',
        'growth' => $productGrowth
    ),
    array(
        'label' => 'Orders',
        'value' => number_format($totalOrders),
        'icon' => 'fa-clipboard-list',
        'icon_class' => 'bg-blue-100 text-blue-600',
        'growth' => $orderGrowth
    ),
    array(
        'label' => 'Verified Sales',
        'value' => ad_money($totalVerifiedSales),
        'icon' => 'fa-coins',
        'icon_class' => 'bg-green-100 text-green-600',
        'growth' => null
    )
);

/*
|--------------------------------------------------------------------------
| Verified Sales - Last 30 Days
|--------------------------------------------------------------------------
*/

$salesStatement = $pdo->query(
    "SELECT
        DATE(
            COALESCE(
                pp.paid_at,
                pp.verified_at,
                pp.updated_at,
                pp.created_at
            )
        ) AS sales_day,

        COALESCE(
            SUM(vo.subtotal),
            0
        ) AS sales_amount

     FROM purchase_process pp

     INNER JOIN vendor_orders vo
        ON vo.purchase_id = pp.purchase_id

     WHERE pp.payment_status = 'paid'
       AND vo.order_status IN (
            'confirmed',
            'completed'
       )
       AND DATE(
            COALESCE(
                pp.paid_at,
                pp.verified_at,
                pp.updated_at,
                pp.created_at
            )
       ) >= CURDATE() - INTERVAL 29 DAY

     GROUP BY
        DATE(
            COALESCE(
                pp.paid_at,
                pp.verified_at,
                pp.updated_at,
                pp.created_at
            )
        )

     ORDER BY sales_day ASC"
);

$salesRows = $salesStatement->fetchAll();

$salesByDate = array();

foreach ($salesRows as $salesRow) {
    $salesByDate[
        (string) $salesRow['sales_day']
    ] = (float) $salesRow['sales_amount'];
}

$salesLabels = array();
$salesValues = array();

for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
    $dateKey = date(
        'Y-m-d',
        strtotime('-' . $daysAgo . ' days')
    );

    $salesLabels[] = date(
        'M j',
        strtotime($dateKey)
    );

    $salesValues[] = isset(
        $salesByDate[$dateKey]
    )
        ? (float) $salesByDate[$dateKey]
        : 0;
}

/*
|--------------------------------------------------------------------------
| Orders This Week
|--------------------------------------------------------------------------
*/

$weekStart = date(
    'Y-m-d',
    strtotime('monday this week')
);

$weekEnd = date(
    'Y-m-d',
    strtotime($weekStart . ' +7 days')
);

$weekOrderStatement = $pdo->prepare(
    "SELECT
        DATE(created_at) AS order_day,
        COUNT(*) AS total
     FROM purchase_process
     WHERE created_at >= :week_start
       AND created_at < :week_end
     GROUP BY DATE(created_at)"
);

$weekOrderStatement->execute(array(
    'week_start' => $weekStart . ' 00:00:00',
    'week_end' => $weekEnd . ' 00:00:00'
));

$ordersByDate = array();

foreach ($weekOrderStatement->fetchAll() as $row) {
    $ordersByDate[
        (string) $row['order_day']
    ] = (int) $row['total'];
}

$weekLabels = array();
$weekValues = array();

for ($day = 0; $day < 7; $day++) {
    $dateKey = date(
        'Y-m-d',
        strtotime(
            $weekStart .
            ' +' .
            $day .
            ' days'
        )
    );

    $weekLabels[] = date(
        'D',
        strtotime($dateKey)
    );

    $weekValues[] = isset(
        $ordersByDate[$dateKey]
    )
        ? (int) $ordersByDate[$dateKey]
        : 0;
}

/*
|--------------------------------------------------------------------------
| Pending Vendor Applications
|--------------------------------------------------------------------------
| Dashboard shows the latest 4 pending vendor applications.
|--------------------------------------------------------------------------
*/

$pendingVendorsStatement = $pdo->query(
    "SELECT
        v.vendor_id,
        v.vendor_name,
        v.address,
        v.created_at,
        u.user_name,
        u.email
     FROM vendors v
     INNER JOIN users u
        ON u.user_id = v.user_id
     WHERE v.status = 'pending'
     ORDER BY
        v.created_at DESC,
        v.vendor_id DESC
     LIMIT 4"
);

$pendingVendors =
    $pendingVendorsStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Recent Orders
|--------------------------------------------------------------------------
| Dashboard shows the latest 5 orders.
|--------------------------------------------------------------------------
*/

$recentOrdersStatement = $pdo->query(
    "SELECT
        pp.purchase_id,
        pp.total_amount,
        pp.payment_status,
        pp.payment_method,
        pp.created_at,
        COALESCE(
            u.user_name,
            'Customer'
        ) AS customer_name,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_total
            WHERE vo_total.purchase_id =
                  pp.purchase_id
        ) AS vendor_count,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_pending
            WHERE vo_pending.purchase_id =
                  pp.purchase_id
              AND vo_pending.order_status =
                  'pending'
        ) AS pending_count,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_confirmed
            WHERE vo_confirmed.purchase_id =
                  pp.purchase_id
              AND vo_confirmed.order_status =
                  'confirmed'
        ) AS confirmed_count,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_rejected
            WHERE vo_rejected.purchase_id =
                  pp.purchase_id
              AND vo_rejected.order_status =
                  'rejected'
        ) AS rejected_count,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_completed
            WHERE vo_completed.purchase_id =
                  pp.purchase_id
              AND vo_completed.order_status =
                  'completed'
        ) AS completed_count,

        COALESCE(
            (
                SELECT SUM(vo_pay.subtotal)
                FROM vendor_orders vo_pay
                WHERE vo_pay.purchase_id =
                      pp.purchase_id
                  AND vo_pay.order_status IN (
                      'confirmed',
                      'completed'
                  )
            ),
            0
        ) AS payable_amount

     FROM purchase_process pp

     LEFT JOIN users u
        ON u.user_id = pp.user_id

     ORDER BY
        pp.created_at DESC,
        pp.purchase_id DESC

     LIMIT 5"
);

$recentOrders =
    $recentOrdersStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Recent Reviews
|--------------------------------------------------------------------------
| Dashboard shows the latest 4 reviews.
|--------------------------------------------------------------------------
*/

$recentReviewsStatement = $pdo->query(
    "SELECT
        r.review_id,
        r.rating,
        r.comment,
        r.created_at,
        COALESCE(
            u.user_name,
            'Customer'
        ) AS reviewer_name,
        COALESCE(
            p.product_name,
            'Product'
        ) AS product_name,
        COALESCE(
            v.vendor_name,
            'Vendor'
        ) AS vendor_name

     FROM reviews r

     LEFT JOIN users u
        ON u.user_id = r.user_id

     LEFT JOIN products p
        ON p.product_id = r.product_id

     LEFT JOIN vendors v
        ON v.vendor_id = p.vendor_id

     ORDER BY
        r.created_at DESC,
        r.review_id DESC

     LIMIT 4"
);

$recentReviews =
    $recentReviewsStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Market Overview by City
|--------------------------------------------------------------------------
*/

$marketOverviewStatement = $pdo->query(
    "SELECT
        COALESCE(
            c.city_name,
            'Other'
        ) AS city_name,
        COUNT(m.market_id) AS market_count

     FROM markets m

     LEFT JOIN cities c
        ON c.city_id = m.city_id

     GROUP BY
        c.city_id,
        c.city_name

     ORDER BY
        market_count DESC,
        city_name ASC"
);

$allMarketOverviewRows =
    $marketOverviewStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Dashboard Market Overview: Top 4 + Others
|--------------------------------------------------------------------------
| Keep the dashboard compact even when many cities/markets are added.
| The full market list is still available from the Markets page.
|--------------------------------------------------------------------------
*/

$marketOverviewRows = array();
$topMarketRows = array_slice(
    $allMarketOverviewRows,
    0,
    4
);

foreach ($topMarketRows as $marketRow) {
    $marketOverviewRows[] = array(
        'city_name' => (string) $marketRow['city_name'],
        'market_count' => (int) $marketRow['market_count']
    );
}

if (count($allMarketOverviewRows) > 4) {
    $otherMarketCount = 0;

    for (
        $marketIndex = 4;
        $marketIndex < count($allMarketOverviewRows);
        $marketIndex++
    ) {
        $otherMarketCount +=
            (int) $allMarketOverviewRows[$marketIndex]['market_count'];
    }

    if ($otherMarketCount > 0) {
        $marketOverviewRows[] = array(
            'city_name' => 'Others',
            'market_count' => $otherMarketCount
        );
    }
}

$marketLabels = array();
$marketValues = array();

foreach ($marketOverviewRows as $marketRow) {
    $marketLabels[] =
        (string) $marketRow['city_name'];

    $marketValues[] =
        (int) $marketRow['market_count'];
}

/*
|--------------------------------------------------------------------------
| JSON
|--------------------------------------------------------------------------
*/

$salesLabelsJson = json_encode($salesLabels);
$salesValuesJson = json_encode($salesValues);
$weekLabelsJson = json_encode($weekLabels);
$weekValuesJson = json_encode($weekValues);
$marketLabelsJson = json_encode($marketLabels);
$marketValuesJson = json_encode($marketValues);

if ($salesLabelsJson === false) {
    $salesLabelsJson = '[]';
}

if ($salesValuesJson === false) {
    $salesValuesJson = '[]';
}

if ($weekLabelsJson === false) {
    $weekLabelsJson = '[]';
}

if ($weekValuesJson === false) {
    $weekValuesJson = '[]';
}

if ($marketLabelsJson === false) {
    $marketLabelsJson = '[]';
}

if ($marketValuesJson === false) {
    $marketValuesJson = '[]';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>
        Admin Dashboard | Local Farmers Marketplace
    </title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">

    <script src="../assets/js/chart-lite.js"></script>

    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Compact Admin Dashboard — target design at browser zoom 100%
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

        .admin-hidden-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .admin-hidden-scrollbar::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .admin-dashboard-main {
            min-width: 0;
        }

        .admin-stat-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.65rem;
        }

        .admin-stat-card {
            min-width: 0;
            min-height: 88px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .admin-stat-value {
            white-space: nowrap;
        }

        .admin-dashboard-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .admin-chart-card,
        .admin-approvals-panel,
        .admin-market-panel,
        .admin-recent-orders-panel,
        .admin-reviews-panel {
            min-width: 0;
        }

        .admin-chart-wrap {
            position: relative;
            height: 176px;
            min-width: 0;
            overflow: hidden;
        }

        .admin-chart-wrap canvas {
            display: block !important;
            width: 100% !important;
            height: 100% !important;
            max-width: 100% !important;
            max-height: 100% !important;
        }

        .admin-market-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.65rem;
            align-items: center;
        }

        .admin-market-legend {
            min-width: 0;
        }

        .admin-market-chart {
            position: relative;
            width: 96px;
            height: 96px;
            max-width: 100%;
            margin: 0 auto;
            flex-shrink: 0;
        }

        .admin-market-chart canvas {
            display: block !important;
            width: 100% !important;
            height: 100% !important;
            max-width: 100% !important;
            max-height: 100% !important;
        }

        .admin-orders-table {
            width: 100%;
            min-width: 760px;
        }

        .admin-orders-table th,
        .admin-orders-table td {
            vertical-align: middle;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            .admin-stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Desktop / laptop — keep target design from 1024px upward
        |--------------------------------------------------------------------------
        */
        @media (min-width: 1024px) {
            .admin-content-shell {
                margin-left: 176px !important;
            }

            .admin-dashboard-main {
                padding: 14px 18px 24px !important;
            }

            .admin-stat-grid {
                grid-template-columns:
                    repeat(5, minmax(0, 1fr))
                    minmax(190px, 1.15fr);
                gap: 0.65rem;
            }

            .admin-stat-card {
                min-height: 88px;
                padding: 12px !important;
                border-radius: 14px !important;
            }

            .admin-stat-card > div:first-child {
                gap: 10px !important;
            }

            .admin-stat-card > div:first-child > div:first-child {
                width: 36px !important;
                height: 36px !important;
            }

            .admin-stat-card > div:first-child > div:first-child i {
                font-size: 14px !important;
            }

            .admin-stat-card p.text-xs {
                font-size: 10px !important;
                line-height: 1.1 !important;
            }

            .admin-stat-value {
                margin-top: 3px !important;
                font-size: 18px !important;
                line-height: 1 !important;
            }

            .admin-stat-card > div:last-child {
                margin-top: 7px !important;
                gap: 5px !important;
                font-size: 9px !important;
            }

            .admin-dashboard-grid {
                grid-template-columns: repeat(12, minmax(0, 1fr));
                gap: 0.75rem;
            }

            .admin-sales-panel {
                grid-column: span 5 / span 5;
            }

            .admin-orders-week-panel {
                grid-column: span 4 / span 4;
            }

            .admin-approvals-panel {
                grid-column: span 3 / span 3;
            }

            .admin-market-panel {
                grid-column: span 3 / span 3;
            }

            .admin-recent-orders-panel {
                grid-column: span 6 / span 6;
            }

            .admin-reviews-panel {
                grid-column: span 3 / span 3;
            }

            .admin-chart-card,
            .admin-approvals-panel,
            .admin-market-panel,
            .admin-reviews-panel {
                border-radius: 14px !important;
                padding: 14px !important;
            }

            .admin-chart-card {
                min-height: 242px;
                overflow: hidden;
            }

            .admin-chart-card > div:first-child {
                margin-bottom: 5px !important;
            }

            .admin-approvals-panel > div:first-child,
            .admin-market-panel > div:first-child,
            .admin-reviews-panel > div:first-child {
                margin-bottom: 9px !important;
            }

            .admin-chart-card h2,
            .admin-approvals-panel h2,
            .admin-market-panel h2,
            .admin-recent-orders-panel h2,
            .admin-reviews-panel h2 {
                font-size: 12px !important;
                line-height: 1.2 !important;
            }

            .admin-chart-card p,
            .admin-recent-orders-panel p {
                font-size: 9px !important;
            }

            .admin-chart-card > div:first-child > span {
                padding: 6px 9px !important;
                font-size: 9px !important;
                border-radius: 7px !important;
            }

            .admin-chart-wrap {
                height: 176px !important;
            }

            .admin-approvals-panel .min-h-\[170px\] {
                min-height: 126px !important;
            }

            .admin-market-layout {
                grid-template-columns: minmax(0, 1fr) 92px;
                gap: 0.45rem;
            }

            .admin-market-chart {
                width: 92px;
                height: 92px;
            }

            .admin-market-legend {
                gap: 0 !important;
            }

            .admin-market-legend > div {
                margin-top: 8px !important;
            }

            .admin-market-legend p {
                font-size: 9px !important;
                line-height: 1.2 !important;
            }

            .admin-market-legend p + p {
                margin-top: 2px !important;
                font-size: 8px !important;
            }

            .admin-market-chart .text-xl {
                font-size: 15px !important;
            }

            .admin-market-chart .text-\[9px\] {
                font-size: 7px !important;
            }

            .admin-recent-orders-panel {
                border-radius: 14px !important;
            }

            .admin-recent-orders-panel > div:first-child {
                padding: 11px 14px !important;
            }

            .admin-orders-table {
                min-width: 0;
                table-layout: fixed;
            }

            .admin-orders-table thead {
                font-size: 8px !important;
            }

            .admin-orders-table th {
                padding-top: 8px !important;
                padding-bottom: 8px !important;
            }

            .admin-orders-table td {
                padding-top: 8px !important;
                padding-bottom: 8px !important;
                font-size: 9px !important;
            }

            .admin-orders-table td span.text-sm,
            .admin-orders-table td.text-sm {
                font-size: 9px !important;
            }

            .admin-orders-table td span.grid {
                width: 24px !important;
                height: 24px !important;
                font-size: 8px !important;
            }

            .admin-orders-table td span.inline-flex {
                padding: 3px 6px !important;
                font-size: 7px !important;
            }

            .admin-orders-table th:nth-child(1),
            .admin-orders-table td:nth-child(1) {
                width: 10%;
            }

            .admin-orders-table th:nth-child(2),
            .admin-orders-table td:nth-child(2) {
                width: 19%;
            }

            .admin-orders-table th:nth-child(3),
            .admin-orders-table td:nth-child(3) {
                width: 18%;
            }

            .admin-orders-table th:nth-child(4),
            .admin-orders-table td:nth-child(4) {
                width: 19%;
            }

            .admin-orders-table th:nth-child(5),
            .admin-orders-table td:nth-child(5) {
                width: 20%;
            }

            .admin-orders-table th:nth-child(6),
            .admin-orders-table td:nth-child(6) {
                width: 14%;
            }

            .admin-reviews-panel {
                padding: 14px !important;
            }

            .admin-reviews-panel .divide-y > div {
                padding-top: 7px !important;
                padding-bottom: 7px !important;
            }

            .admin-reviews-panel .h-9 {
                width: 28px !important;
                height: 28px !important;
            }

            .admin-reviews-panel .text-xs {
                font-size: 9px !important;
            }

            .admin-reviews-panel .text-\[11px\] {
                font-size: 8px !important;
                line-height: 1.35 !important;
            }

            .admin-reviews-panel .text-\[10px\] {
                font-size: 8px !important;
            }

            .admin-reviews-panel .text-\[9px\] {
                font-size: 7px !important;
            }
        }

        @media (max-width: 1023px) {
            .admin-orders-table {
                min-width: 850px;
            }
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">

    <style>
        /* ==============================================================
           ADMIN DASHBOARD — FINAL 100% DESKTOP REFERENCE LAYOUT
           Loads AFTER responsive.css so this page matches the supplied
           screenshot at normal desktop/browser zoom 100%.
           ============================================================== */

        html,
        body {
            max-width: 100%;
            overflow-x: hidden !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar,
        .admin-hidden-scrollbar::-webkit-scrollbar {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
        }

        .admin-hidden-scrollbar {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        /* Normal desktop / 100% layout */
        @media (min-width: 1150px) {

            /* Reference screenshot uses the compact 176px admin sidebar. */
            #adminSidebar {
                display: flex !important;
                width: 176px !important;
                transform: translateX(0) !important;
                visibility: visible !important;
            }

            #adminSidebarOverlay {
                display: none !important;
            }

            #adminHeaderMenuButton,
            #closeAdminSidebar {
                display: none !important;
            }

            .admin-dashboard-page-shell {
                margin-left: 176px !important;
                width: calc(100% - 176px) !important;
                max-width: calc(100% - 176px) !important;
                padding-left: 0 !important;
            }

            /* Keep the normal desktop admin header. */
            .admin-dashboard-page-shell header {
                min-height: 64px !important;
                height: 64px !important;
            }

            .admin-dashboard-page-shell header {
                padding-left: 16px !important;
                padding-right: 16px !important;
            }

            .admin-dashboard-main {
                width: 100% !important;
                max-width: 100% !important;
                padding: 14px 18px 24px !important;
            }

            /* ----------------------------------------------------------
               TOP SUMMARY CARDS — 6 cards in a single row
               ---------------------------------------------------------- */
            .admin-stat-grid {
                display: grid !important;
                grid-template-columns:
                    repeat(5, minmax(0, 1fr))
                    minmax(205px, 1.16fr) !important;
                gap: 12px !important;
                width: 100% !important;
            }

            .admin-stat-card {
                min-width: 0 !important;
                min-height: 90px !important;
                padding: 12px !important;
                border-radius: 14px !important;
            }

            .admin-stat-card > div:first-child {
                gap: 10px !important;
            }

            .admin-stat-card > div:first-child > div:first-child {
                width: 38px !important;
                height: 38px !important;
            }

            .admin-stat-card > div:first-child > div:first-child i {
                font-size: 14px !important;
            }

            .admin-stat-card p.text-xs {
                font-size: 10px !important;
            }

            .admin-stat-value {
                font-size: 18px !important;
                line-height: 1 !important;
                white-space: nowrap !important;
            }

            .admin-stat-card > div:last-child {
                margin-top: 8px !important;
                font-size: 9px !important;
            }

            /* ----------------------------------------------------------
               DASHBOARD GRID
               Row 1: Sales 5/12 | Orders 4/12 | Approvals 3/12
               Row 2: Market 3/12 | Recent Orders 6/12 | Reviews 3/12
               ---------------------------------------------------------- */
            .admin-dashboard-grid {
                display: grid !important;
                grid-template-columns: repeat(12, minmax(0, 1fr)) !important;
                gap: 12px !important;
                width: 100% !important;
            }

            .admin-sales-panel {
                grid-column: span 5 / span 5 !important;
            }

            .admin-orders-week-panel {
                grid-column: span 4 / span 4 !important;
            }

            .admin-approvals-panel {
                grid-column: span 3 / span 3 !important;
            }

            .admin-market-panel {
                grid-column: span 3 / span 3 !important;
            }

            .admin-recent-orders-panel {
                grid-column: span 6 / span 6 !important;
            }

            .admin-reviews-panel {
                grid-column: span 3 / span 3 !important;
            }

            .admin-chart-card,
            .admin-approvals-panel,
            .admin-market-panel,
            .admin-reviews-panel {
                min-width: 0 !important;
                border-radius: 14px !important;
                padding: 14px !important;
            }

            /* Match the chart card proportions from the reference. */
            .admin-chart-card,
            .admin-approvals-panel {
                height: 250px !important;
                min-height: 250px !important;
                overflow: hidden !important;
            }

            .admin-chart-wrap {
                height: 178px !important;
                min-height: 178px !important;
                overflow: hidden !important;
            }

            .admin-chart-wrap canvas {
                width: 100% !important;
                height: 100% !important;
            }

            .admin-chart-card h2,
            .admin-approvals-panel h2,
            .admin-market-panel h2,
            .admin-recent-orders-panel h2,
            .admin-reviews-panel h2 {
                font-size: 12px !important;
            }

            .admin-chart-card p,
            .admin-recent-orders-panel p {
                font-size: 9px !important;
            }

            .admin-chart-card > div:first-child > span {
                padding: 6px 9px !important;
                border-radius: 7px !important;
                font-size: 9px !important;
            }

            .admin-approvals-panel .min-h-\[170px\] {
                min-height: 160px !important;
            }

            /* ----------------------------------------------------------
               MARKET OVERVIEW
               ---------------------------------------------------------- */
            .admin-market-panel,
            .admin-recent-orders-panel,
            .admin-reviews-panel {
                min-height: 290px !important;
                height: 290px !important;
            }

            .admin-market-layout {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) 96px !important;
                gap: 8px !important;
                align-items: center !important;
            }

            .admin-market-chart {
                width: 96px !important;
                height: 96px !important;
                margin: 0 auto !important;
            }

            .admin-market-legend {
                min-width: 0 !important;
            }

            .admin-market-legend > div {
                margin-top: 9px !important;
            }

            .admin-market-legend p {
                font-size: 9px !important;
            }

            .admin-market-legend p + p {
                font-size: 8px !important;
            }

            /* ----------------------------------------------------------
               RECENT ORDERS
               ---------------------------------------------------------- */
            .admin-recent-orders-panel {
                overflow: hidden !important;
                padding: 0 !important;
            }

            .admin-recent-orders-panel > div:first-child {
                padding: 11px 14px !important;
            }

            .admin-recent-orders-panel .admin-hidden-scrollbar {
                overflow-x: hidden !important;
            }

            .admin-orders-table {
                width: 100% !important;
                min-width: 0 !important;
                table-layout: fixed !important;
            }

            .admin-orders-table th {
                padding: 8px 10px !important;
                font-size: 8px !important;
            }

            .admin-orders-table td {
                padding: 8px 10px !important;
                font-size: 9px !important;
            }

            .admin-orders-table td span.text-sm,
            .admin-orders-table td.text-sm {
                font-size: 9px !important;
            }

            .admin-orders-table td span.grid {
                width: 24px !important;
                height: 24px !important;
                font-size: 8px !important;
            }

            .admin-orders-table td span.inline-flex {
                padding: 3px 6px !important;
                font-size: 7px !important;
            }

            .admin-orders-table th:nth-child(1),
            .admin-orders-table td:nth-child(1) { width: 10% !important; }

            .admin-orders-table th:nth-child(2),
            .admin-orders-table td:nth-child(2) { width: 20% !important; }

            .admin-orders-table th:nth-child(3),
            .admin-orders-table td:nth-child(3) { width: 18% !important; }

            .admin-orders-table th:nth-child(4),
            .admin-orders-table td:nth-child(4) { width: 19% !important; }

            .admin-orders-table th:nth-child(5),
            .admin-orders-table td:nth-child(5) { width: 20% !important; }

            .admin-orders-table th:nth-child(6),
            .admin-orders-table td:nth-child(6) { width: 13% !important; }

            /* ----------------------------------------------------------
               RECENT REVIEWS
               ---------------------------------------------------------- */
            .admin-reviews-panel {
                overflow: hidden !important;
            }

            .admin-reviews-panel .divide-y > div {
                padding-top: 7px !important;
                padding-bottom: 7px !important;
            }

            .admin-reviews-panel .h-9 {
                width: 28px !important;
                height: 28px !important;
            }

            .admin-reviews-panel .text-xs {
                font-size: 9px !important;
            }

            .admin-reviews-panel .text-\[11px\] {
                font-size: 8px !important;
                line-height: 1.35 !important;
            }

            .admin-reviews-panel .text-\[10px\] {
                font-size: 8px !important;
            }

            .admin-reviews-panel .text-\[9px\] {
                font-size: 7px !important;
            }
        }

        /* Slightly tighter desktop widths (110–125% on many screens). */
        @media (min-width: 1150px) and (max-width: 1450px) {
            .admin-dashboard-main {
                padding-left: 14px !important;
                padding-right: 14px !important;
            }

            .admin-stat-grid,
            .admin-dashboard-grid {
                gap: 9px !important;
            }

            .admin-stat-card {
                padding: 10px !important;
            }

            .admin-stat-value {
                font-size: 16px !important;
            }

            .admin-stat-card p.text-xs {
                font-size: 9px !important;
            }

            .admin-chart-card,
            .admin-approvals-panel,
            .admin-market-panel,
            .admin-reviews-panel {
                padding: 12px !important;
            }
        }

        /* High zoom / compact mode continues to use the shared responsive layer. */
        @media (max-width: 1149.98px) {
            .admin-dashboard-page-shell {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .admin-orders-table {
                min-width: 850px !important;
            }
        }
    </style>

</head>

<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">

<div class="min-h-screen">

    <?php require_once './sidebar.php'; ?>

    <div class="admin-dashboard-page-shell admin-content-shell min-h-screen">

        <?php require_once './header.php'; ?>

        <main class="admin-dashboard-main px-4 pb-10 pt-5 sm:px-6 xl:px-7">

            <!-- Statistics -->
            <section class="admin-stat-grid">

                <?php foreach ($statCards as $card): ?>

                    <article class="admin-stat-card rounded-2xl border border-slate-200 bg-white p-4 shadow-card transition hover:-translate-y-0.5 hover:shadow-lg">

                        <div class="flex items-center gap-3">

                            <div class="grid h-12 w-12 shrink-0 place-items-center rounded-full <?php echo ad_e($card['icon_class']); ?>">

                                <i class="fa-solid <?php echo ad_e($card['icon']); ?> text-xl"></i>
                            </div>

                            <div class="min-w-0 flex-1">

                                <p class="whitespace-nowrap text-xs font-semibold text-slate-600">
                                    <?php echo ad_e($card['label']); ?>
                                </p>

                                <p class="admin-stat-value mt-1 text-2xl font-extrabold tracking-tight text-slate-950">
                                    <?php echo ad_e($card['value']); ?>
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 flex items-center gap-2 text-[11px]">

                            <?php if ($card['growth'] !== null): ?>

                                <?php
                                $growth =
                                    (float) $card['growth'];
                                ?>

                                <span class="font-bold <?php echo $growth >= 0 ? 'text-green-600' : 'text-red-600'; ?>">

                                    <i class="fa-solid <?php echo $growth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down'; ?> mr-1"></i>

                                    <?php echo number_format(abs($growth), 1); ?>%
                                </span>

                                <span class="text-slate-400">
                                    vs last month
                                </span>

                            <?php else: ?>

                                <span class="font-semibold text-green-600">

                                    <i class="fa-solid fa-database mr-1"></i>
                                    Live database
                                </span>

                            <?php endif; ?>
                        </div>
                    </article>

                <?php endforeach; ?>
            </section>

            <!-- Charts + Approvals -->
            <section class="admin-dashboard-grid mt-5">

                <article class="admin-chart-card admin-sales-panel rounded-2xl border border-slate-200 bg-white p-5 shadow-card">

                    <div class="mb-4 flex items-start justify-between gap-3">

                        <div>
                            <h2 class="font-bold text-slate-950">
                                Verified Sales
                            </h2>

                            <p class="mt-1 text-xs text-slate-500">
                                Paid accepted Vendor revenue over the last 30 days
                            </p>
                        </div>

                        <span class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600">
                            Last 30 Days
                        </span>
                    </div>

                    <div class="admin-chart-wrap">
                        <canvas id="salesChart"></canvas>
                    </div>
                </article>

                <article class="admin-chart-card admin-orders-week-panel rounded-2xl border border-slate-200 bg-white p-5 shadow-card">

                    <div class="mb-4 flex items-start justify-between gap-3">

                        <div>
                            <h2 class="font-bold text-slate-950">
                                Orders This Week
                            </h2>

                            <p class="mt-1 text-xs text-slate-500">
                                <?php echo ad_e(
                                    date(
                                        'M j',
                                        strtotime($weekStart)
                                    )
                                ); ?>
                                -
                                <?php echo ad_e(
                                    date(
                                        'M j, Y',
                                        strtotime(
                                            $weekStart .
                                            ' +6 days'
                                        )
                                    )
                                ); ?>
                            </p>
                        </div>

                        <span class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600">
                            This Week
                        </span>
                    </div>

                    <div class="admin-chart-wrap">
                        <canvas id="ordersChart"></canvas>
                    </div>
                </article>

                <article class="admin-approvals-panel rounded-2xl border border-slate-200 bg-white p-5 shadow-card">

                    <div class="mb-4 flex items-center justify-between gap-3">

                        <h2 class="font-bold text-slate-950">
                            Pending Vendor Approvals
                        </h2>

                        <a href="vendors.php?status=pending"
                           class="shrink-0 text-xs font-bold text-green-600 hover:text-green-700">
                            View all
                        </a>
                    </div>

                    <?php if (empty($pendingVendors)): ?>

                        <div class="flex min-h-[170px] flex-col items-center justify-center px-4 text-center">

                            <div class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-green-50 text-green-600">
                                <i class="fa-solid fa-check"></i>
                            </div>

                            <p class="mt-3 text-sm font-semibold text-slate-700">
                                No pending Vendors
                            </p>

                            <p class="mt-1 text-xs text-slate-400">
                                All applications are reviewed.
                            </p>
                        </div>

                    <?php else: ?>

                        <div class="divide-y divide-slate-100">

                            <?php foreach ($pendingVendors as $pendingVendor): ?>

                                <div class="py-3">

                                    <div class="flex items-center gap-3">

                                        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-gradient-to-br from-green-100 to-amber-100 text-xs font-extrabold text-green-700">

                                            <?php echo ad_e(
                                                ad_initials(
                                                    $pendingVendor['vendor_name']
                                                )
                                            ); ?>
                                        </div>

                                        <div class="min-w-0 flex-1">

                                            <p class="truncate text-sm font-bold text-slate-800">
                                                <?php echo ad_e($pendingVendor['vendor_name']); ?>
                                            </p>

                                            <p class="mt-0.5 truncate text-[11px] text-slate-500">

                                                <i class="fa-solid fa-location-dot mr-1"></i>

                                                <?php echo ad_e($pendingVendor['address']); ?>
                                            </p>
                                        </div>

                                        <span class="shrink-0 text-[11px] text-slate-400">
                                            <?php echo ad_e(
                                                ad_time_ago(
                                                    $pendingVendor['created_at']
                                                )
                                            ); ?>
                                        </span>
                                    </div>

                                    <div class="mt-3 flex justify-end">

                                        <a href="vendors.php?status=pending"
                                           class="rounded-lg border border-green-300 px-3 py-1.5 text-[11px] font-bold text-green-700 transition hover:bg-green-50">

                                            Review
                                        </a>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>
                </article>
            </section>

            <!-- Bottom -->
            <section class="admin-dashboard-grid mt-5">

                <!-- Markets -->
                <article class="admin-market-panel rounded-2xl border border-slate-200 bg-white p-5 shadow-card">

                    <div class="mb-4 flex items-center justify-between">

                        <h2 class="font-bold text-slate-950">
                            Market Overview
                        </h2>

                        <a href="markets.php"
                           class="text-xs font-bold text-green-600 hover:text-green-700">
                            View all
                        </a>
                    </div>

                    <div class="admin-market-layout">

                        <div class="admin-market-legend space-y-3">

                            <?php if (empty($marketOverviewRows)): ?>

                                <p class="text-sm text-slate-400">
                                    No market data available.
                                </p>

                            <?php else: ?>

                                <?php
                                $marketDotClasses = array(
                                    'bg-green-600',
                                    'bg-lime-500',
                                    'bg-orange-500',
                                    'bg-blue-500',
                                    'bg-slate-400'
                                );

                                $marketRowIndex = 0;
                                ?>

                                <?php foreach ($marketOverviewRows as $marketRow): ?>

                                    <?php
                                    $marketDotClass = isset(
                                        $marketDotClasses[$marketRowIndex]
                                    )
                                        ? $marketDotClasses[$marketRowIndex]
                                        : 'bg-slate-400';

                                    $marketRowIndex++;
                                    ?>

                                    <div class="flex items-start gap-2">

                                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full <?php echo ad_e($marketDotClass); ?>"></span>

                                        <div class="min-w-0">

                                            <p class="truncate text-xs font-semibold text-slate-700" title="<?php echo ad_e($marketRow['city_name']); ?>">
                                                <?php echo ad_e($marketRow['city_name']); ?>
                                            </p>

                                            <p class="text-[11px] text-slate-400">
                                                <?php echo number_format(
                                                    (int) $marketRow['market_count']
                                                ); ?>
                                                market(s)
                                            </p>
                                        </div>
                                    </div>

                                <?php endforeach; ?>

                            <?php endif; ?>
                        </div>

                        <div class="admin-market-chart relative mx-auto">

                            <canvas id="marketChart"></canvas>

                            <div class="pointer-events-none absolute inset-0 grid place-items-center overflow-hidden text-center">

                                <div>
                                    <p class="text-xl font-extrabold leading-none text-slate-900">
                                        <?php echo number_format($totalMarkets); ?>
                                    </p>

                                    <p class="mt-1 whitespace-nowrap text-[9px] font-medium text-slate-500">
                                        Total Markets
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>

                <!-- Recent Orders -->
                <article class="admin-recent-orders-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">

                        <div>
                            <h2 class="font-bold text-slate-950">
                                Recent Orders
                            </h2>

                            <p class="mt-1 text-[11px] text-slate-400">
                                Order and payment status shown separately
                            </p>
                        </div>

                        <a href="orders.php"
                           class="text-xs font-bold text-green-600 hover:text-green-700">
                            View all
                        </a>
                    </div>

                    <div class="admin-hidden-scrollbar overflow-x-auto">

                        <table class="admin-orders-table text-left">

                            <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-400">

                                <tr>
                                    <th class="px-5 py-3 font-semibold">Order</th>
                                    <th class="px-4 py-3 font-semibold">Customer</th>
                                    <th class="px-4 py-3 font-semibold">Amount</th>
                                    <th class="px-4 py-3 font-semibold">Order Status</th>
                                    <th class="px-4 py-3 font-semibold">Payment</th>
                                    <th class="px-5 py-3 font-semibold">Date</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100">

                                <?php if (empty($recentOrders)): ?>

                                    <tr>
                                        <td colspan="6"
                                            class="px-5 py-12 text-center text-sm text-slate-400">

                                            No orders found.
                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($recentOrders as $order): ?>
                                        <?php
                                        $displayOrderStatus =
                                            ad_order_status(
                                                $order['vendor_count'],
                                                $order['pending_count'],
                                                $order['confirmed_count'],
                                                $order['rejected_count'],
                                                $order['completed_count']
                                            );

                                        $displayPayment =
                                            ad_payment_label(
                                                $order['payment_status']
                                            );

                                        $displayAmount =
                                            (float) $order['payable_amount'] > 0
                                                ? (float) $order['payable_amount']
                                                : (float) $order['total_amount'];
                                        ?>

                                        <tr class="hover:bg-slate-50/80">

                                            <td class="px-5 py-4 text-sm font-bold text-green-700">
                                                #<?php echo number_format(
                                                    (int) $order['purchase_id']
                                                ); ?>
                                            </td>

                                            <td class="px-4 py-4">

                                                <div class="flex items-center gap-2">

                                                    <span class="grid h-8 w-8 place-items-center rounded-full bg-slate-100 text-[10px] font-extrabold text-slate-600">

                                                        <?php echo ad_e(
                                                            ad_initials(
                                                                $order['customer_name']
                                                            )
                                                        ); ?>
                                                    </span>

                                                    <span class="text-sm font-semibold text-slate-700">
                                                        <?php echo ad_e($order['customer_name']); ?>
                                                    </span>
                                                </div>
                                            </td>

                                            <td class="px-4 py-4 text-sm font-semibold text-slate-700">
                                                <?php echo ad_e(
                                                    ad_money($displayAmount)
                                                ); ?>
                                            </td>

                                            <td class="px-4 py-4">

                                                <span class="inline-flex rounded-md px-2 py-1 text-[10px] font-bold ring-1 ring-inset <?php echo ad_e($displayOrderStatus['class']); ?>">
                                                    <?php echo ad_e($displayOrderStatus['label']); ?>
                                                </span>
                                            </td>

                                            <td class="px-4 py-4">

                                                <span class="inline-flex rounded-md px-2 py-1 text-[10px] font-bold ring-1 ring-inset <?php echo ad_e($displayPayment['class']); ?>">
                                                    <?php echo ad_e($displayPayment['label']); ?>
                                                </span>
                                            </td>

                                            <td class="px-5 py-4 text-xs text-slate-500">
                                                <?php echo ad_e(
                                                    ad_date(
                                                        $order['created_at'],
                                                        'M j, Y'
                                                    )
                                                ); ?>
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <!-- Reviews -->
                <article class="admin-reviews-panel rounded-2xl border border-slate-200 bg-white p-5 shadow-card">

                    <div class="mb-4 flex items-center justify-between">

                        <h2 class="font-bold text-slate-950">
                            Recent Reviews
                        </h2>

                        <a href="reviews.php"
                           class="text-xs font-bold text-green-600 hover:text-green-700">
                            View all
                        </a>
                    </div>

                    <?php if (empty($recentReviews)): ?>

                        <div class="flex min-h-[170px] flex-col items-center justify-center px-4 text-center">

                            <div class="grid h-12 w-12 place-items-center rounded-full bg-green-50 text-green-600">
                                <i class="fa-regular fa-comment-dots text-lg"></i>
                            </div>

                            <p class="mt-3 text-sm font-bold text-slate-700">
                                No reviews yet
                            </p>

                            <p class="mt-1 max-w-[220px] text-[11px] leading-5 text-slate-400">
                                Customer feedback will appear here after eligible purchases are reviewed.
                            </p>
                        </div>

                    <?php else: ?>

                        <div class="divide-y divide-slate-100">

                            <?php foreach ($recentReviews as $review): ?>
                                <?php
                                $rating = max(
                                    0,
                                    min(
                                        5,
                                        (int) $review['rating']
                                    )
                                );
                                ?>

                                <div class="py-3 first:pt-0 last:pb-0">

                                    <div class="flex gap-3">

                                        <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-green-100 text-[11px] font-extrabold text-green-700">

                                            <?php echo ad_e(
                                                ad_initials(
                                                    $review['reviewer_name']
                                                )
                                            ); ?>
                                        </div>

                                        <div class="min-w-0 flex-1">

                                            <div class="flex items-center justify-between gap-2">

                                                <p class="truncate text-xs font-bold text-slate-800">
                                                    <?php echo ad_e($review['reviewer_name']); ?>
                                                </p>

                                                <span class="shrink-0 text-[10px] text-slate-400">
                                                    <?php echo ad_e(
                                                        ad_time_ago(
                                                            $review['created_at']
                                                        )
                                                    ); ?>
                                                </span>
                                            </div>

                                            <p class="mt-0.5 truncate text-[9px] font-semibold text-green-700">
                                                <?php echo ad_e($review['product_name']); ?>
                                                ·
                                                <?php echo ad_e($review['vendor_name']); ?>
                                            </p>

                                            <div class="mt-1 flex gap-0.5 text-[10px] text-amber-400">

                                                <?php for ($star = 1; $star <= 5; $star++): ?>

                                                    <i class="fa-solid fa-star <?php echo $star > $rating ? 'text-slate-200' : ''; ?>"></i>

                                                <?php endfor; ?>
                                            </div>

                                            <p class="mt-1 line-clamp-2 text-[11px] leading-5 text-slate-500">
                                                <?php echo ad_e($review['comment']); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>
</div>

<script>
    var salesLabels =
        <?php echo $salesLabelsJson; ?>;

    var salesValues =
        <?php echo $salesValuesJson; ?>;

    var weekLabels =
        <?php echo $weekLabelsJson; ?>;

    var weekValues =
        <?php echo $weekValuesJson; ?>;

    var marketLabels =
        <?php echo $marketLabelsJson; ?>;

    var marketValues =
        <?php echo $marketValuesJson; ?>;

    function moneyTick(value) {
        var numericValue =
            Number(value || 0);

        if (numericValue >= 1000000) {
            return (
                numericValue /
                1000000
            ).toFixed(1) + 'M';
        }

        if (numericValue >= 1000) {
            return (
                numericValue /
                1000
            ).toFixed(0) + 'K';
        }

        return numericValue;
    }

    Chart.defaults.font.family =
        'Inter, sans-serif';

    Chart.defaults.color =
        '#64748b';

    var salesCanvas =
        document.getElementById(
            'salesChart'
        );

    if (salesCanvas) {
        var salesContext =
            salesCanvas.getContext('2d');

        var salesGradient =
            salesContext.createLinearGradient(
                0,
                0,
                0,
                220
            );

        salesGradient.addColorStop(
            0,
            'rgba(34, 197, 94, .32)'
        );

        salesGradient.addColorStop(
            1,
            'rgba(34, 197, 94, 0)'
        );

        new Chart(
            salesContext,
            {
                type: 'line',

                data: {
                    labels: salesLabels,

                    datasets: [
                        {
                            data: salesValues,
                            borderColor: '#16a34a',
                            backgroundColor:
                                salesGradient,
                            borderWidth: 2.5,
                            pointRadius: 2.5,
                            pointHoverRadius: 5,
                            pointBackgroundColor:
                                '#16a34a',
                            fill: true,
                            tension: .35
                        }
                    ]
                },

                options: {
                    maintainAspectRatio: false,

                    layout: {
                        padding: {
                            top: 4,
                            right: 4,
                            bottom: 8,
                            left: 4
                        }
                    },

                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },

                    plugins: {
                        legend: {
                            display: false
                        },

                        tooltip: {
                            displayColors: false,

                            callbacks: {
                                label: function (
                                    context
                                ) {
                                    return Number(
                                        context.raw ||
                                        0
                                    ).toLocaleString() +
                                    ' MMK';
                                }
                            }
                        }
                    },

                    scales: {
                        x: {
                            grid: {
                                display: false
                            },

                            border: {
                                display: false
                            },

                            ticks: {
                                maxTicksLimit: 7,
                                padding: 4,
                                font: {
                                    size: 9
                                }
                            }
                        },

                        y: {
                            beginAtZero: true,

                            border: {
                                display: false
                            },

                            grid: {
                                color: 'rgba(226, 232, 240, .75)'
                            },

                            ticks: {
                                callback: moneyTick,
                                font: {
                                    size: 10
                                }
                            }
                        }
                    }
                }
            }
        );
    }

    var ordersCanvas =
        document.getElementById(
            'ordersChart'
        );

    if (ordersCanvas) {
        new Chart(
            ordersCanvas,
            {
                type: 'bar',

                data: {
                    labels: weekLabels,

                    datasets: [
                        {
                            data: weekValues,
                            backgroundColor:
                                '#9bd68a',
                            borderRadius: 7,
                            borderSkipped: false,
                            maxBarThickness: 34
                        }
                    ]
                },

                options: {
                    maintainAspectRatio: false,

                    layout: {
                        padding: {
                            top: 4,
                            right: 4,
                            bottom: 8,
                            left: 4
                        }
                    },

                    plugins: {
                        legend: {
                            display: false
                        }
                    },

                    scales: {
                        x: {
                            grid: {
                                display: false
                            },

                            border: {
                                display: false
                            },

                            ticks: {
                                padding: 4,
                                font: {
                                    size: 9
                                }
                            }
                        },

                        y: {
                            beginAtZero: true,

                            border: {
                                display: false
                            },

                            grid: {
                                color: 'rgba(226, 232, 240, .75)'
                            },

                            ticks: {
                                precision: 0,
                                font: {
                                    size: 10
                                }
                            }
                        }
                    }
                }
            }
        );
    }

    var marketCanvas =
        document.getElementById(
            'marketChart'
        );

    if (marketCanvas) {
        new Chart(
            marketCanvas,
            {
                type: 'doughnut',

                data: {
                    labels: marketLabels,

                    datasets: [
                        {
                            data: marketValues,

                            backgroundColor: [
                                '#2f9e44',
                                '#8bc34a',
                                '#ff9800',
                                '#42a5f5',
                                '#b0b7c3'
                            ],

                            borderColor:
                                '#ffffff',

                            borderWidth: 3,
                            hoverOffset: 4
                        }
                    ]
                },

                options: {
                    maintainAspectRatio: false,
                    cutout: '68%',

                    plugins: {
                        legend: {
                            display: false
                        },

                        tooltip: {
                            callbacks: {
                                label: function (
                                    context
                                ) {
                                    return (
                                        context.label +
                                        ': ' +
                                        context.raw +
                                        ' market(s)'
                                    );
                                }
                            }
                        }
                    }
                }
            }
        );
    }
</script>
</body>
</html>