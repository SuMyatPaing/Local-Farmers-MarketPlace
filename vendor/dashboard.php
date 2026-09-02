<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
fm_require_role('vendor');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Yangon');

/*
|--------------------------------------------------------------------------
| Vendor Dashboard - Database Driven
|--------------------------------------------------------------------------
| Save as:
| C:\xampp\htdocs\farmer_marketplace\vendor\dashboard.php
|
| All dashboard numbers, orders, events, permissions, chart data and top
| products are loaded from the current database.
|
| PHP 7.1 compatible.
|--------------------------------------------------------------------------
*/

function vd_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function vd_redirect($location)
{
    header('Location: ' . $location);
    exit;
}

function vd_money($amount)
{
    return number_format((float) $amount, 0) . ' MMK';
}

function vd_date($value, $format)
{
    $time = strtotime((string) $value);

    return $time !== false
        ? date($format, $time)
        : '—';
}

function vd_order_status_class($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === 'confirmed') {
        return 'bg-green-100 text-green-700';
    }

    if ($status === 'completed') {
        return 'bg-blue-100 text-blue-700';
    }

    if ($status === 'rejected') {
        return 'bg-rose-100 text-rose-700';
    }

    return 'bg-amber-100 text-amber-700';
}

function vd_order_status_label($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === 'confirmed') {
        return 'Confirmed';
    }

    if ($status === 'completed') {
        return 'Completed';
    }

    if ($status === 'rejected') {
        return 'Rejected';
    }

    return 'Pending';
}

/*
|--------------------------------------------------------------------------
| Vendor Login Guard
|--------------------------------------------------------------------------
*/

$isLoggedIn =
    isset($_SESSION['user_id']) &&
    (int) $_SESSION['user_id'] > 0 &&
    isset($_SESSION['role']);

if (
    !$isLoggedIn ||
    strtolower((string) $_SESSION['role']) !== 'vendor'
) {
    vd_redirect('../signin.php?notice=login_required');
}

$userId = (int) $_SESSION['user_id'];

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

if (!($pdo instanceof PDO) && function_exists('getPDO')) {
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
| Current Vendor
|--------------------------------------------------------------------------
*/

$vendorStatement = $pdo->prepare(
    "SELECT
        v.vendor_id,
        v.user_id,
        v.vendor_name,
        v.address,
        v.status AS vendor_status,
        v.rejection_reason,
        v.reviewed_at,
        u.user_name,
        u.email,
        u.phone_number,
        u.status AS account_status
     FROM vendors v
     INNER JOIN users u
        ON u.user_id = v.user_id
     WHERE v.user_id = :user_id
       AND u.role = 'vendor'
     LIMIT 1"
);

$vendorStatement->execute(array(
    'user_id' => $userId
));

$vendor = $vendorStatement->fetch();

if (!$vendor) {
    exit('Vendor profile was not found.');
}

$vendorId = (int) $vendor['vendor_id'];
$vendorName = trim((string) $vendor['vendor_name']);
$vendorStatus = strtolower((string) $vendor['vendor_status']);
$accountStatus = strtolower((string) $vendor['account_status']);

$_SESSION['vendor_id'] = $vendorId;
$_SESSION['vendor_name'] = $vendorName;
$_SESSION['name'] = $vendorName;

/*
|--------------------------------------------------------------------------
| Vendor Approval / Account State
|--------------------------------------------------------------------------
*/

if (
    $vendorStatus !== 'accepted' ||
    $accountStatus !== 'active'
) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport"
              content="width=device-width, initial-scale=1.0">

        <title>Vendor Account Status</title>

        <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">

        <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
        <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>

    <body class="grid min-h-screen place-items-center bg-[#f6faf5] p-4">

        <main class="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-xl">

            <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl
                <?php echo $vendorStatus === 'rejected'
                    ? 'bg-red-100 text-red-600'
                    : 'bg-amber-100 text-amber-600'; ?>">

                <i class="fa-solid
                    <?php echo $vendorStatus === 'rejected'
                        ? 'fa-circle-xmark'
                        : 'fa-clock'; ?>
                    text-2xl"></i>
            </span>

            <h1 class="mt-5 text-2xl font-black text-slate-900">
                <?php echo vd_e($vendorName); ?>
            </h1>

            <p class="mt-3 text-sm leading-6 text-slate-500">
                <?php if ($accountStatus !== 'active'): ?>
                    Your account is currently suspended.
                <?php elseif ($vendorStatus === 'pending'): ?>
                    Your Vendor application is waiting for Admin approval.
                <?php elseif ($vendorStatus === 'rejected'): ?>
                    Your Vendor application was rejected.
                <?php else: ?>
                    Your Vendor account is not active.
                <?php endif; ?>
            </p>

            <?php if (
                $vendorStatus === 'rejected' &&
                trim((string) $vendor['rejection_reason']) !== ''
            ): ?>

                <div class="mt-5 rounded-2xl bg-red-50 p-4 text-left text-sm text-red-700">
                    <strong>Reason:</strong>
                    <?php echo vd_e($vendor['rejection_reason']); ?>
                </div>

            <?php endif; ?>

            <a href="../logout.php"
               class="mt-6 inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-black text-white hover:bg-green-700">

                <i class="fa-solid fa-right-from-bracket"></i>
                Sign Out
            </a>
        </main>
    </body>
    </html>
    <?php
    exit;
}

/*
|--------------------------------------------------------------------------
| Permission
|--------------------------------------------------------------------------
*/

$permissionStatement = $pdo->prepare(
    "SELECT
        upload_limit,
        can_delete
     FROM permission
     WHERE vendor_id = :vendor_id
     LIMIT 1"
);

$permissionStatement->execute(array(
    'vendor_id' => $vendorId
));

$permission = $permissionStatement->fetch();

$uploadLimit = $permission
    ? max(0, (int) $permission['upload_limit'])
    : 10;

$canDelete = $permission
    ? (int) $permission['can_delete'] === 1
    : true;

/*
|--------------------------------------------------------------------------
| Product Statistics
|--------------------------------------------------------------------------
*/

$productStatsStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_products,
        COALESCE(
            SUM(
                CASE
                    WHEN stock_quantity BETWEEN 1 AND 10
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS low_stock,
        COALESCE(
            SUM(price * stock_quantity),
            0
        ) AS inventory_value
     FROM products
     WHERE vendor_id = :vendor_id"
);

$productStatsStatement->execute(array(
    'vendor_id' => $vendorId
));

$productStats = $productStatsStatement->fetch();

$totalProducts = $productStats
    ? (int) $productStats['total_products']
    : 0;

$lowStock = $productStats
    ? (int) $productStats['low_stock']
    : 0;

$inventoryValue = $productStats
    ? (float) $productStats['inventory_value']
    : 0;

$remainingUploads = max(
    0,
    $uploadLimit - $totalProducts
);

$uploadPercent = $uploadLimit > 0
    ? min(
        100,
        max(
            0,
            (int) round(
                ($totalProducts / $uploadLimit) * 100
            )
        )
    )
    : 100;

/*
|--------------------------------------------------------------------------
| Vendor Order Statistics + Verified Revenue
|--------------------------------------------------------------------------
*/

$orderStatsStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_orders,

        COALESCE(
            SUM(
                CASE
                    WHEN vo.order_status = 'pending'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS pending_orders,

        COALESCE(
            SUM(
                CASE
                    WHEN pp.payment_status = 'paid'
                     AND vo.order_status IN (
                        'confirmed',
                        'completed'
                     )
                    THEN vo.subtotal
                    ELSE 0
                END
            ),
            0
        ) AS verified_revenue

     FROM vendor_orders vo

     INNER JOIN purchase_process pp
        ON pp.purchase_id = vo.purchase_id

     WHERE vo.vendor_id = :vendor_id"
);

$orderStatsStatement->execute(array(
    'vendor_id' => $vendorId
));

$orderStats = $orderStatsStatement->fetch();

$totalOrders = $orderStats
    ? (int) $orderStats['total_orders']
    : 0;

$pendingOrders = $orderStats
    ? (int) $orderStats['pending_orders']
    : 0;

$verifiedRevenue = $orderStats
    ? (float) $orderStats['verified_revenue']
    : 0;

/*
|--------------------------------------------------------------------------
| Current 30 Days vs Previous 30 Days
|--------------------------------------------------------------------------
*/

$trendStatement = $pdo->prepare(
    "SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN pp.payment_status = 'paid'
                     AND vo.order_status IN (
                        'confirmed',
                        'completed'
                     )
                     AND COALESCE(
                        pp.paid_at,
                        vo.updated_at,
                        vo.created_at
                     ) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    THEN vo.subtotal
                    ELSE 0
                END
            ),
            0
        ) AS current_period,

        COALESCE(
            SUM(
                CASE
                    WHEN pp.payment_status = 'paid'
                     AND vo.order_status IN (
                        'confirmed',
                        'completed'
                     )
                     AND COALESCE(
                        pp.paid_at,
                        vo.updated_at,
                        vo.created_at
                     ) >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                     AND COALESCE(
                        pp.paid_at,
                        vo.updated_at,
                        vo.created_at
                     ) < DATE_SUB(NOW(), INTERVAL 30 DAY)
                    THEN vo.subtotal
                    ELSE 0
                END
            ),
            0
        ) AS previous_period

     FROM vendor_orders vo

     INNER JOIN purchase_process pp
        ON pp.purchase_id = vo.purchase_id

     WHERE vo.vendor_id = :vendor_id"
);

$trendStatement->execute(array(
    'vendor_id' => $vendorId
));

$trend = $trendStatement->fetch();

$currentPeriodRevenue = $trend
    ? (float) $trend['current_period']
    : 0;

$previousPeriodRevenue = $trend
    ? (float) $trend['previous_period']
    : 0;

$revenueChange = null;

if ($previousPeriodRevenue > 0) {
    $revenueChange =
        (($currentPeriodRevenue - $previousPeriodRevenue) /
        $previousPeriodRevenue) * 100;
} elseif ($currentPeriodRevenue > 0) {
    $revenueChange = 100;
}

/*
|--------------------------------------------------------------------------
| Assigned Markets
|--------------------------------------------------------------------------
*/

$marketStatement = $pdo->prepare(
    "SELECT
        m.market_id,
        m.market_name,
        m.address,
        c.city_name
     FROM vendor_markets vm
     INNER JOIN markets m
        ON m.market_id = vm.market_id
     LEFT JOIN cities c
        ON c.city_id = m.city_id
     WHERE vm.vendor_id = :vendor_id
     ORDER BY m.market_name ASC"
);

$marketStatement->execute(array(
    'vendor_id' => $vendorId
));

$assignedMarkets = $marketStatement->fetchAll();
$assignedMarketCount = count($assignedMarkets);

/*
|--------------------------------------------------------------------------
| Recent Vendor Orders
|--------------------------------------------------------------------------
*/

$recentOrderStatement = $pdo->prepare(
    "SELECT
        vo.vendor_order_id,
        vo.purchase_id,
        vo.subtotal,
        vo.order_status,
        vo.created_at,
        pp.payment_status,
        COALESCE(
            u.user_name,
            'Customer'
        ) AS customer_name,

        COALESCE(
            (
                SELECT SUM(pd.quantity)
                FROM purchase_details pd
                WHERE pd.vendor_order_id =
                      vo.vendor_order_id
            ),
            0
        ) AS total_quantity

     FROM vendor_orders vo

     INNER JOIN purchase_process pp
        ON pp.purchase_id = vo.purchase_id

     LEFT JOIN users u
        ON u.user_id = pp.user_id

     WHERE vo.vendor_id = :vendor_id

     ORDER BY
        vo.created_at DESC,
        vo.vendor_order_id DESC

     LIMIT 5"
);

$recentOrderStatement->execute(array(
    'vendor_id' => $vendorId
));

$recentOrders = $recentOrderStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Upcoming Events at Assigned Markets
|--------------------------------------------------------------------------
*/

$eventStatement = $pdo->prepare(
    "SELECT DISTINCT
        e.event_id,
        e.event_name,
        e.start_date,
        e.end_date,
        e.description,
        m.market_name

     FROM vendor_markets vm

     INNER JOIN markets m
        ON m.market_id = vm.market_id

     INNER JOIN events e
        ON e.market_id = m.market_id

     WHERE vm.vendor_id = :vendor_id
       AND e.end_date >= :today

     ORDER BY
        e.start_date ASC,
        e.event_id ASC

     LIMIT 3"
);

$eventStatement->execute(array(
    'vendor_id' => $vendorId,
    'today' => date('Y-m-d')
));

$upcomingEvents = $eventStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Top Products by Verified Paid Sales
|--------------------------------------------------------------------------
*/

$topProductStatement = $pdo->prepare(
    "SELECT
        p.product_id,
        p.product_name,
        p.stock_quantity,

        COALESCE(
            SUM(
                CASE
                    WHEN vo.vendor_id = :sales_vendor_id
                     AND vo.order_status IN (
                        'confirmed',
                        'completed'
                     )
                     AND pp.payment_status = 'paid'
                    THEN pd.quantity
                    ELSE 0
                END
            ),
            0
        ) AS units_sold,

        COALESCE(
            SUM(
                CASE
                    WHEN vo.vendor_id = :amount_vendor_id
                     AND vo.order_status IN (
                        'confirmed',
                        'completed'
                     )
                     AND pp.payment_status = 'paid'
                    THEN pd.subtotal
                    ELSE 0
                END
            ),
            0
        ) AS sales_amount

     FROM products p

     LEFT JOIN purchase_details pd
        ON pd.product_id = p.product_id

     LEFT JOIN vendor_orders vo
        ON vo.vendor_order_id = pd.vendor_order_id

     LEFT JOIN purchase_process pp
        ON pp.purchase_id = vo.purchase_id

     WHERE p.vendor_id = :vendor_id

     GROUP BY
        p.product_id,
        p.product_name,
        p.stock_quantity

     ORDER BY
        sales_amount DESC,
        units_sold DESC,
        p.product_name ASC

     LIMIT 3"
);

$topProductStatement->execute(array(
    'sales_vendor_id' => $vendorId,
    'amount_vendor_id' => $vendorId,
    'vendor_id' => $vendorId
));

$topProducts = $topProductStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Last 7 Days Verified Sales Chart
|--------------------------------------------------------------------------
*/

$chartStatement = $pdo->prepare(
    "SELECT
        DATE(
            COALESCE(
                pp.paid_at,
                vo.updated_at,
                vo.created_at
            )
        ) AS sale_date,

        COALESCE(
            SUM(vo.subtotal),
            0
        ) AS sales_amount

     FROM vendor_orders vo

     INNER JOIN purchase_process pp
        ON pp.purchase_id = vo.purchase_id

     WHERE vo.vendor_id = :vendor_id
       AND vo.order_status IN (
            'confirmed',
            'completed'
       )
       AND pp.payment_status = 'paid'
       AND DATE(
            COALESCE(
                pp.paid_at,
                vo.updated_at,
                vo.created_at
            )
       ) BETWEEN :start_date AND :end_date

     GROUP BY
        DATE(
            COALESCE(
                pp.paid_at,
                vo.updated_at,
                vo.created_at
            )
        )

     ORDER BY sale_date ASC"
);

$chartStartDate = date(
    'Y-m-d',
    strtotime('-6 days')
);

$chartEndDate = date('Y-m-d');

$chartStatement->execute(array(
    'vendor_id' => $vendorId,
    'start_date' => $chartStartDate,
    'end_date' => $chartEndDate
));

$chartRows = $chartStatement->fetchAll();

$chartSalesByDate = array();

foreach ($chartRows as $chartRow) {
    $chartSalesByDate[
        (string) $chartRow['sale_date']
    ] = (float) $chartRow['sales_amount'];
}

$chartLabels = array();
$chartValues = array();

for ($dayIndex = 6; $dayIndex >= 0; $dayIndex--) {
    $dateKey = date(
        'Y-m-d',
        strtotime('-' . $dayIndex . ' days')
    );

    $chartLabels[] = date(
        'D',
        strtotime($dateKey)
    );

    $chartValues[] = isset(
        $chartSalesByDate[$dateKey]
    )
        ? (float) $chartSalesByDate[$dateKey]
        : 0;
}

$chartLabelsJson = json_encode($chartLabels);
$chartValuesJson = json_encode($chartValues);

if ($chartLabelsJson === false) {
    $chartLabelsJson = '[]';
}

if ($chartValuesJson === false) {
    $chartValuesJson = '[]';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Vendor Dashboard</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">

    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    <script src="../assets/js/chart-lite.js"></script>

    <style>
        /*
        |--------------------------------------------------------------------------
        | Vendor Dashboard Responsive Layout
        |--------------------------------------------------------------------------
        | Goal:
        | - Keep the 100% desktop view compact and aligned.
        | - Keep KPI cards in one row on desktop.
        | - Prevent Orders / Events cards from stretching to equal heights.
        | - Hide visual scrollbars while keeping wheel/touch scrolling enabled.
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

        .vendor-dashboard-scroll {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .vendor-dashboard-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .vendor-dashboard-kpis {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1rem;
        }

        .vendor-dashboard-main-grid,
        .vendor-dashboard-secondary-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1.25rem;
            align-items: start;
        }

        .vendor-dashboard-chart-wrap {
            position: relative;
            width: 100%;
            min-height: 260px;
            height: 300px;
        }

        .vendor-dashboard-chart-wrap canvas {
            width: 100% !important;
            height: 100% !important;
        }

        .vendor-dashboard-permission {
            align-self: start;
        }

        .vendor-dashboard-orders,
        .vendor-dashboard-events {
            align-self: start;
        }

        .vendor-top-products {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .vendor-top-products {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 640px) {
            .vendor-dashboard-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        /*
        | Desktop / normal browser zoom.
        | This breakpoint is deliberately slightly below xl so the layout
        | remains desktop-like after the fixed Vendor sidebar takes space.
        */
        @media (min-width: 1180px) {
            .vendor-dashboard-kpis {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .vendor-dashboard-main-grid {
                grid-template-columns:
                    minmax(0, 1.85fr)
                    minmax(320px, 0.95fr);
            }

            .vendor-dashboard-secondary-grid {
                grid-template-columns:
                    minmax(0, 1.55fr)
                    minmax(320px, 0.85fr);
            }

            .vendor-dashboard-chart-wrap {
                height: 340px;
            }

            .vendor-top-products {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
    </style>

</head>

<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800">

    <?php require_once './sidebar.php'; ?>

    <div class="min-h-screen lg:ml-64">

        <?php require_once './header.php'; ?>

        <main class="px-4 py-6 sm:px-6 xl:px-8 xl:py-8">

            <!-- Statistics -->
            <section class="vendor-dashboard-kpis mt-6">

                <article class="rounded-[1.5rem] border border-slate-200/80 bg-white p-5 shadow-card">

                    <div class="flex items-start justify-between">

                        <div>
                            <p class="text-sm font-semibold text-slate-500">
                                Total Products
                            </p>

                            <p class="mt-2 text-3xl font-black tracking-tight text-slate-950">
                                <?php echo number_format($totalProducts); ?>
                            </p>
                        </div>

                        <span class="grid h-12 w-12 place-items-center rounded-2xl bg-green-100 text-green-600">
                            <i class="fa-solid fa-box-open text-lg"></i>
                        </span>
                    </div>

                    <div class="mt-5">

                        <div class="mb-2 flex items-center justify-between text-xs font-semibold">

                            <span class="text-slate-400">
                                Upload limit
                            </span>

                            <span class="text-slate-600">
                                <?php echo number_format($totalProducts); ?>
                                /
                                <?php echo number_format($uploadLimit); ?>
                            </span>
                        </div>

                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">

                            <div class="h-full rounded-full bg-green-500"
                                 style="width: <?php echo (int) $uploadPercent; ?>%;">
                            </div>
                        </div>
                    </div>
                </article>

                <article class="rounded-[1.5rem] border border-slate-200/80 bg-white p-5 shadow-card">

                    <div class="flex items-start justify-between">

                        <div>
                            <p class="text-sm font-semibold text-slate-500">
                                Total Orders
                            </p>

                            <p class="mt-2 text-3xl font-black tracking-tight text-slate-950">
                                <?php echo number_format($totalOrders); ?>
                            </p>
                        </div>

                        <span class="grid h-12 w-12 place-items-center rounded-2xl bg-sky-100 text-sky-700">
                            <i class="fa-solid fa-bag-shopping text-lg"></i>
                        </span>
                    </div>

                    <p class="mt-5 text-sm font-semibold text-amber-600">
                        <i class="fa-regular fa-clock mr-1"></i>
                        <?php echo number_format($pendingOrders); ?>
                        pending order(s)
                    </p>
                </article>

                <article class="rounded-[1.5rem] border border-slate-200/80 bg-white p-5 shadow-card">

                    <div class="flex items-start justify-between">

                        <div>

                            <p class="text-sm font-semibold text-slate-500">
                                Verified Revenue
                            </p>

                            <p class="mt-2 text-3xl font-black tracking-tight text-slate-950">
                                <?php echo number_format($verifiedRevenue); ?>
                                <span class="text-sm font-bold text-slate-400">
                                    MMK
                                </span>
                            </p>
                        </div>

                        <span class="grid h-12 w-12 place-items-center rounded-2xl bg-green-100 text-green-600">
                            <i class="fa-solid fa-coins text-lg"></i>
                        </span>
                    </div>

                    <?php if ($revenueChange === null): ?>

                        <p class="mt-5 text-sm font-semibold text-slate-400">
                            <i class="fa-solid fa-minus mr-1"></i>
                            No previous 30-day sales data
                        </p>

                    <?php elseif ($revenueChange >= 0): ?>

                        <p class="mt-5 text-sm font-semibold text-green-600">
                            <i class="fa-solid fa-arrow-trend-up mr-1"></i>
                            <?php echo number_format(abs($revenueChange), 1); ?>%
                            vs previous 30 days
                        </p>

                    <?php else: ?>

                        <p class="mt-5 text-sm font-semibold text-rose-600">
                            <i class="fa-solid fa-arrow-trend-down mr-1"></i>
                            <?php echo number_format(abs($revenueChange), 1); ?>%
                            vs previous 30 days
                        </p>

                    <?php endif; ?>
                </article>

                <article class="rounded-[1.5rem] border border-slate-200/80 bg-white p-5 shadow-card">

                    <div class="flex items-start justify-between">

                        <div>
                            <p class="text-sm font-semibold text-slate-500">
                                Low Stock
                            </p>

                            <p class="mt-2 text-3xl font-black tracking-tight text-slate-950">
                                <?php echo number_format($lowStock); ?>
                            </p>
                        </div>

                        <span class="grid h-12 w-12 place-items-center rounded-2xl bg-rose-100 text-rose-700">
                            <i class="fa-solid fa-triangle-exclamation text-lg"></i>
                        </span>
                    </div>

                    <a href="products.php?stock=low"
                       class="mt-5 inline-flex items-center gap-2 text-sm font-bold text-rose-600 hover:text-rose-700">

                        Stock 1–10
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </article>
            </section>

            <!-- Sales Chart and Permissions -->
            <section class="vendor-dashboard-main-grid mt-6">

                <article class="rounded-[1.75rem] border border-slate-200/80 bg-white p-5 shadow-card sm:p-6">

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                        <div>
                            <h3 class="text-lg font-black text-slate-950">
                                Sales Overview
                            </h3>

                            <p class="mt-1 text-sm text-slate-500">
                                Verified paid Vendor Order revenue during the last seven days.
                            </p>
                        </div>

                        <span class="inline-flex w-fit items-center gap-2 rounded-xl bg-green-50 px-3 py-2 text-xs font-bold text-green-700">

                            <i class="fa-regular fa-calendar"></i>
                            Last 7 days
                        </span>
                    </div>

                    <div class="vendor-dashboard-chart-wrap mt-6">
                        <canvas id="vendorSalesChart"></canvas>
                    </div>
                </article>

                <article class="vendor-dashboard-permission rounded-[1.75rem] border border-slate-200/80 bg-white p-5 shadow-card sm:p-6">

                    <div class="flex items-center justify-between">

                        <div>
                            <h3 class="text-lg font-black text-slate-950">
                                Vendor Permission
                            </h3>

                            <p class="mt-1 text-sm text-slate-500">
                                Current limits assigned by Admin.
                            </p>
                        </div>

                        <span class="grid h-11 w-11 place-items-center rounded-2xl bg-green-100 text-green-600">
                            <i class="fa-solid fa-shield-halved"></i>
                        </span>
                    </div>

                    <div class="mt-6 space-y-4">

                        <div class="rounded-2xl bg-slate-50 p-4">

                            <div class="flex items-center justify-between">

                                <span class="text-sm font-bold text-slate-700">
                                    Product uploads
                                </span>

                                <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-black text-green-700">
                                    <?php echo number_format($uploadLimit); ?>
                                    allowed
                                </span>
                            </div>

                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">

                                <div class="h-full rounded-full bg-green-500"
                                     style="width: <?php echo (int) $uploadPercent; ?>%;">
                                </div>
                            </div>

                            <p class="mt-2 text-xs text-slate-500">
                                <?php echo number_format($remainingUploads); ?>
                                upload slot(s) remaining.
                            </p>
                        </div>

                      
                        <div class="rounded-2xl border border-slate-200 p-4">

                            <p class="text-sm font-bold text-slate-700">
                                Assigned Markets
                            </p>

                            <?php if (empty($assignedMarkets)): ?>

                                <p class="mt-2 text-xs text-slate-400">
                                    No market is assigned yet.
                                </p>

                            <?php else: ?>

                                <div class="mt-3 space-y-2">

                                    <?php foreach ($assignedMarkets as $assignedMarket): ?>

                                        <div class="flex items-start gap-2 text-xs">

                                            <i class="fa-solid fa-store mt-0.5 text-green-600"></i>

                                            <span class="text-slate-600">
                                                <strong class="text-slate-800">
                                                    <?php echo vd_e($assignedMarket['market_name']); ?>
                                                </strong>

                                                <?php if (
                                                    trim((string) $assignedMarket['city_name']) !== ''
                                                ): ?>
                                                    ·
                                                    <?php echo vd_e($assignedMarket['city_name']); ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>

                                    <?php endforeach; ?>
                                </div>

                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            </section>

            <!-- Orders and Events -->
            <section class="vendor-dashboard-secondary-grid mt-6">

                <article class="vendor-dashboard-orders overflow-hidden rounded-[1.75rem] border border-slate-200/80 bg-white shadow-card">

                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-5 sm:px-6">

                        <div>
                            <h3 class="text-lg font-black text-slate-950">
                                Recent Orders
                            </h3>

                            <p class="mt-1 text-sm text-slate-500">
                                Latest Vendor Orders containing your products.
                            </p>
                        </div>

                        <a href="orders.php"
                           class="text-sm font-bold text-green-700 hover:text-green-800">
                            View all
                        </a>
                    </div>

                    <?php if (empty($recentOrders)): ?>

                        <div class="px-6 py-12 text-center">

                            <i class="fa-solid fa-bag-shopping text-3xl text-slate-200"></i>

                            <p class="mt-3 text-sm font-bold text-slate-500">
                                No Vendor Orders yet.
                            </p>
                        </div>

                    <?php else: ?>

                        <div class="vendor-dashboard-scroll overflow-x-auto">

                            <table class="min-w-full">

                                <thead class="bg-slate-50 text-left text-[11px] font-black uppercase tracking-wider text-slate-400">

                                    <tr>
                                        <th class="px-5 py-3 sm:px-6">Order</th>
                                        <th class="px-5 py-3">Customer</th>
                                        <th class="px-5 py-3">Items</th>
                                        <th class="px-5 py-3">Amount</th>
                                        <th class="px-5 py-3">Status</th>
                                        <th class="px-5 py-3 sm:px-6"></th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-slate-100 text-sm">

                                    <?php foreach ($recentOrders as $recentOrder): ?>

                                        <tr class="hover:bg-slate-50/70">

                                            <td class="whitespace-nowrap px-5 py-4 font-black text-slate-800 sm:px-6">

                                                #<?php echo number_format(
                                                    (int) $recentOrder['vendor_order_id']
                                                ); ?>

                                                <p class="mt-1 text-[9px] font-semibold text-slate-400">
                                                    Purchase #
                                                    <?php echo number_format(
                                                        (int) $recentOrder['purchase_id']
                                                    ); ?>
                                                </p>
                                            </td>

                                            <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-700">
                                                <?php echo vd_e($recentOrder['customer_name']); ?>
                                            </td>

                                            <td class="whitespace-nowrap px-5 py-4 text-slate-500">
                                                <?php echo number_format(
                                                    (int) $recentOrder['total_quantity']
                                                ); ?>
                                            </td>

                                            <td class="whitespace-nowrap px-5 py-4 font-bold text-slate-800">
                                                <?php echo vd_e(
                                                    vd_money(
                                                        $recentOrder['subtotal']
                                                    )
                                                ); ?>
                                            </td>

                                            <td class="whitespace-nowrap px-5 py-4">

                                                <span class="rounded-full px-2.5 py-1 text-xs font-black <?php echo vd_e(
                                                    vd_order_status_class(
                                                        $recentOrder['order_status']
                                                    )
                                                ); ?>">

                                                    <?php echo vd_e(
                                                        vd_order_status_label(
                                                            $recentOrder['order_status']
                                                        )
                                                    ); ?>
                                                </span>
                                            </td>

                                            <td class="whitespace-nowrap px-5 py-4 text-right sm:px-6">

                                                <a href="orders.php?view=<?php echo (int) $recentOrder['vendor_order_id']; ?>"
                                                   class="grid h-9 w-9 place-items-center rounded-xl text-slate-400 transition hover:bg-green-50 hover:text-green-700">

                                                    <i class="fa-solid fa-arrow-right"></i>
                                                </a>
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php endif; ?>
                </article>

                <article class="vendor-dashboard-events rounded-[1.75rem] border border-slate-200/80 bg-white p-5 shadow-card sm:p-6">

                    <div class="flex items-center justify-between">

                        <div>
                            <h3 class="text-lg font-black text-slate-950">
                                Upcoming Events
                            </h3>

                            <p class="mt-1 text-sm text-slate-500">
                                Events at your assigned markets.
                            </p>
                        </div>

                        <a href="events.php"
                           class="text-sm font-bold text-green-700">
                            View all
                        </a>
                    </div>

                    <?php if (empty($upcomingEvents)): ?>

                        <div class="mt-5 rounded-2xl bg-slate-50 p-6 text-center">

                            <i class="fa-regular fa-calendar text-2xl text-slate-300"></i>

                            <p class="mt-2 text-xs font-bold text-slate-400">
                                No upcoming events.
                            </p>
                        </div>

                    <?php else: ?>

                        <div class="mt-5 space-y-3">

                            <?php foreach ($upcomingEvents as $event): ?>

                                <div class="flex gap-4 rounded-2xl border border-slate-200 p-4 transition hover:border-green-200 hover:bg-green-50/50">

                                    <div class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-[#0f2414] text-center text-white">

                                        <span>
                                            <span class="block text-lg font-black leading-none">
                                                <?php echo vd_e(
                                                    vd_date(
                                                        $event['start_date'],
                                                        'd'
                                                    )
                                                ); ?>
                                            </span>

                                            <span class="mt-1 block text-[10px] font-bold uppercase tracking-wider text-green-200">
                                                <?php echo vd_e(
                                                    vd_date(
                                                        $event['start_date'],
                                                        'M'
                                                    )
                                                ); ?>
                                            </span>
                                        </span>
                                    </div>

                                    <div class="min-w-0">

                                        <p class="truncate font-bold text-slate-800">
                                            <?php echo vd_e($event['event_name']); ?>
                                        </p>

                                        <p class="mt-1 truncate text-xs text-slate-500">
                                            <i class="fa-solid fa-location-dot mr-1 text-green-600"></i>
                                            <?php echo vd_e($event['market_name']); ?>
                                        </p>

                                        <p class="mt-2 text-[10px] font-semibold text-slate-400">
                                            Until
                                            <?php echo vd_e(
                                                vd_date(
                                                    $event['end_date'],
                                                    'M j, Y'
                                                )
                                            ); ?>
                                        </p>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>
                </article>
            </section>

            <!-- Top Products -->
            <section class="mt-6 rounded-[1.75rem] border border-slate-200/80 bg-white p-5 shadow-card sm:p-6">

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h3 class="text-lg font-black text-slate-950">
                            Top Products
                        </h3>

                        <p class="mt-1 text-sm text-slate-500">
                            Top 3 products ranked by verified paid sales.
                        </p>
                    </div>

                    <a href="products.php"
                       class="inline-flex w-fit items-center gap-2 rounded-xl bg-slate-100 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-green-100 hover:text-green-700">

                        Manage products
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </div>

                <?php if (empty($topProducts)): ?>

                    <div class="mt-5 rounded-2xl bg-slate-50 p-8 text-center">

                        <i class="fa-solid fa-chart-column text-2xl text-slate-300"></i>

                        <p class="mt-2 text-xs font-bold text-slate-400">
                            No products are available yet.
                        </p>
                    </div>

                <?php else: ?>

                    <div class="vendor-top-products mt-5">

                        <?php foreach ($topProducts as $rank => $product): ?>

                            <div class="rounded-2xl border border-slate-200 p-4 transition hover:-translate-y-1 hover:border-green-200 hover:shadow-lg">

                                <div class="flex items-center justify-between">

                                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-green-100 font-black text-green-700">
                                        <?php echo number_format($rank + 1); ?>
                                    </span>

                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-500">
                                        Stock
                                        <?php echo number_format(
                                            (int) $product['stock_quantity']
                                        ); ?>
                                    </span>
                                </div>

                                <p class="mt-4 truncate font-black text-slate-800">
                                    <?php echo vd_e($product['product_name']); ?>
                                </p>

                                <p class="mt-1 text-xs text-slate-400">
                                    <?php echo number_format(
                                        (int) $product['units_sold']
                                    ); ?>
                                    verified unit(s) sold
                                </p>

                                <p class="mt-3 text-sm font-black text-green-700">
                                    <?php echo vd_e(
                                        vd_money(
                                            $product['sales_amount']
                                        )
                                    ); ?>
                                </p>
                            </div>

                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </section>

        </main>
    </div>

    <script>
        var chartCanvas =
            document.getElementById(
                'vendorSalesChart'
            );

        if (
            chartCanvas &&
            typeof Chart !== 'undefined'
        ) {
            var context =
                chartCanvas.getContext('2d');

            var gradient =
                context.createLinearGradient(
                    0,
                    0,
                    0,
                    300
                );

            gradient.addColorStop(
                0,
                'rgba(34, 197, 94, 0.28)'
            );

            gradient.addColorStop(
                1,
                'rgba(34, 197, 94, 0)'
            );

            new Chart(
                context,
                {
                    type: 'line',

                    data: {
                        labels:
                            <?php echo $chartLabelsJson; ?>,

                        datasets: [
                            {
                                label: 'Verified Sales',

                                data:
                                    <?php echo $chartValuesJson; ?>,

                                borderColor: '#16a34a',
                                backgroundColor: gradient,
                                borderWidth: 3,
                                fill: true,
                                spanGaps: true,
                                tension: 0.38,
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                pointBackgroundColor:
                                    '#ffffff',
                                pointBorderColor:
                                    '#16a34a',
                                pointBorderWidth: 3
                            }
                        ]
                    },

                    options: {
                        responsive: true,
                        maintainAspectRatio: false,

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
                                            context.raw
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
                                    color: '#94a3b8',

                                    font: {
                                        weight: '600'
                                    }
                                }
                            },

                            y: {
                                beginAtZero: true,

                                border: {
                                    display: false
                                },

                                grid: {
                                    color: '#eef2f7'
                                },

                                ticks: {
                                    color: '#94a3b8',

                                    callback: function (
                                        value
                                    ) {
                                        if (
                                            value >=
                                            1000000
                                        ) {
                                            return (
                                                value /
                                                1000000
                                            ) + 'M';
                                        }

                                        if (
                                            value >=
                                            1000
                                        ) {
                                            return (
                                                value /
                                                1000
                                            ) + 'K';
                                        }

                                        return value;
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