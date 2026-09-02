<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
fm_require_role('vendor');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Vendor Assigned Markets
|--------------------------------------------------------------------------
| Displays only markets assigned to the signed-in Vendor by the Admin.
|--------------------------------------------------------------------------
*/

if (!function_exists('vm_e')) {
    function vm_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('vm_redirect')) {
    function vm_redirect($location)
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('vm_photo_url')) {
    function vm_photo_url($path)
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '';
        }

        if (
            preg_match('/^https?:\/\//i', $path) ||
            strpos($path, 'data:') === 0
        ) {
            return $path;
        }

        return '../' . ltrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('vm_format_time')) {
    function vm_format_time($time)
    {
        $timestamp = strtotime((string) $time);

        return $timestamp !== false
            ? date('g:i A', $timestamp)
            : '—';
    }
}

if (!function_exists('vm_format_date')) {
    function vm_format_date($date)
    {
        $timestamp = strtotime((string) $date);

        return $timestamp !== false
            ? date('M j, Y', $timestamp)
            : '—';
    }
}

if (!function_exists('vm_query_url')) {
    function vm_query_url($overrides)
    {
        $parameters = $_GET;
        unset($parameters['view']);

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($parameters[$key]);
            } else {
                $parameters[$key] = $value;
            }
        }

        $query = http_build_query($parameters);

        $currentFile = basename(
            isset($_SERVER['PHP_SELF'])
                ? (string) $_SERVER['PHP_SELF']
                : 'assigned_markets.php'
        );

        return $currentFile .
            ($query !== '' ? '?' . $query : '');
    }
}

/* Database connection */
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
    exit('Database connection is not available. Check config/database.php.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* Resolve Vendor */
$userId = isset($_SESSION['user_id'])
    ? (int) $_SESSION['user_id']
    : 0;

$sessionVendorId = isset($_SESSION['vendor_id'])
    ? (int) $_SESSION['vendor_id']
    : 0;

$sessionRole = isset($_SESSION['role'])
    ? strtolower((string) $_SESSION['role'])
    : '';

if ($sessionRole !== '' && $sessionRole !== 'vendor') {
    vm_redirect('../signin.php');
}

if ($sessionVendorId > 0) {
    $vendorStatement = $pdo->prepare(
        "SELECT
            v.vendor_id,
            v.user_id,
            v.vendor_name,
            v.address,
            v.status,
            v.rejection_reason,
            u.user_name,
            u.email,
            u.status AS account_status
         FROM vendors v
         INNER JOIN users u ON u.user_id = v.user_id
         WHERE v.vendor_id = :vendor_id
         LIMIT 1"
    );

    $vendorStatement->execute(array(
        'vendor_id' => $sessionVendorId
    ));
} elseif ($userId > 0) {
    $vendorStatement = $pdo->prepare(
        "SELECT
            v.vendor_id,
            v.user_id,
            v.vendor_name,
            v.address,
            v.status,
            v.rejection_reason,
            u.user_name,
            u.email,
            u.status AS account_status
         FROM vendors v
         INNER JOIN users u ON u.user_id = v.user_id
         WHERE v.user_id = :user_id
         LIMIT 1"
    );

    $vendorStatement->execute(array(
        'user_id' => $userId
    ));
} else {
    vm_redirect('../signin.php');
}

$vendor = $vendorStatement->fetch();

if (!$vendor) {
    exit('Vendor profile was not found.');
}

$vendorId = (int) $vendor['vendor_id'];

$_SESSION['vendor_id'] = $vendorId;
$_SESSION['vendor_name'] = (string) $vendor['vendor_name'];

if (
    strtolower((string) $vendor['status']) !== 'accepted' ||
    strtolower((string) $vendor['account_status']) !== 'active'
) {
    vm_redirect('dashboard.php');
}

/* Pagination */
$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 10;

/* Header search */
$search = isset($_GET['q'])
    ? trim((string) $_GET['q'])
    : '';

/* Query conditions */
$whereSql = 'vm.vendor_id = :vendor_id';

$countParameters = array(
    'vendor_id' => $vendorId
);

$listParameters = array(
    'vendor_id' => $vendorId,
    'product_vendor_id' => $vendorId
);

if ($search !== '') {
    $whereSql .= "
        AND (
            m.market_name LIKE :search_market
            OR ci.city_name LIKE :search_city
            OR ci.administrative_division LIKE :search_division
            OR m.address LIKE :search_address
        )
    ";

    $searchValue = '%' . $search . '%';

    $countParameters['search_market'] = $searchValue;
    $countParameters['search_city'] = $searchValue;
    $countParameters['search_division'] = $searchValue;
    $countParameters['search_address'] = $searchValue;

    $listParameters['search_market'] = $searchValue;
    $listParameters['search_city'] = $searchValue;
    $listParameters['search_division'] = $searchValue;
    $listParameters['search_address'] = $searchValue;
}

/* Total filtered rows */
$countSql = "
    SELECT COUNT(*)
    FROM vendor_markets vm
    INNER JOIN markets m ON m.market_id = vm.market_id
    INNER JOIN cities ci ON ci.city_id = m.city_id
    WHERE {$whereSql}
";

$countStatement = $pdo->prepare($countSql);

foreach ($countParameters as $parameterName => $parameterValue) {
    $countStatement->bindValue(
        ':' . $parameterName,
        $parameterValue,
        is_int($parameterValue) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}

$countStatement->execute();

$totalFilteredMarkets = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalFilteredMarkets / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/* Assigned market list */
$listSql = "
    SELECT
        vm.vendor_market_id,
        vm.assigned_at,
        m.market_id,
        m.market_name,
        m.address,
        m.opening_hour,
        m.closing_hour,
        m.description,
        m.created_at,
        ci.city_id,
        ci.city_name,
        ci.administrative_division,
        (
            SELECT mp.photo_path
            FROM market_photo mp
            WHERE mp.market_id = m.market_id
            ORDER BY mp.photo_id ASC
            LIMIT 1
        ) AS primary_photo,
        (
            SELECT COUNT(*)
            FROM market_photo mpc
            WHERE mpc.market_id = m.market_id
        ) AS photo_count,
        (
            SELECT COUNT(*)
            FROM categories cc
            WHERE cc.market_id = m.market_id
        ) AS category_count,
        (
            SELECT COUNT(*)
            FROM products pp
            INNER JOIN categories pc
                ON pc.category_id = pp.category_id
            WHERE pp.vendor_id = :product_vendor_id
              AND pc.market_id = m.market_id
        ) AS vendor_product_count,
        (
            SELECT COUNT(*)
            FROM events ee
            WHERE ee.market_id = m.market_id
              AND ee.end_date >= CURDATE()
        ) AS upcoming_event_count
    FROM vendor_markets vm
    INNER JOIN markets m ON m.market_id = vm.market_id
    INNER JOIN cities ci ON ci.city_id = m.city_id
    WHERE {$whereSql}
    ORDER BY vm.assigned_at DESC, m.market_name ASC
    LIMIT :limit_value OFFSET :offset_value
";

$listStatement = $pdo->prepare($listSql);

foreach ($listParameters as $parameterName => $parameterValue) {
    $listStatement->bindValue(
        ':' . $parameterName,
        $parameterValue,
        is_int($parameterValue) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}

$listStatement->bindValue(
    ':limit_value',
    $perPage,
    PDO::PARAM_INT
);

$listStatement->bindValue(
    ':offset_value',
    $offset,
    PDO::PARAM_INT
);

$listStatement->execute();
$markets = $listStatement->fetchAll();

/* Optional market details */
$selectedMarket = null;
$selectedPhotos = array();
$selectedCategories = array();
$selectedEvents = array();

$viewMarketId = isset($_GET['view'])
    ? (int) $_GET['view']
    : 0;

if ($viewMarketId > 0) {
    $detailStatement = $pdo->prepare(
        "SELECT
            vm.assigned_at,
            m.market_id,
            m.market_name,
            m.address,
            m.opening_hour,
            m.closing_hour,
            m.description,
            ci.city_id,
            ci.city_name,
            ci.administrative_division,
            (
                SELECT COUNT(*)
                FROM products p
                INNER JOIN categories c
                    ON c.category_id = p.category_id
                WHERE p.vendor_id = :product_vendor_id
                  AND c.market_id = m.market_id
            ) AS vendor_product_count
         FROM vendor_markets vm
         INNER JOIN markets m ON m.market_id = vm.market_id
         INNER JOIN cities ci ON ci.city_id = m.city_id
         WHERE vm.vendor_id = :assignment_vendor_id
           AND m.market_id = :market_id
         LIMIT 1"
    );

    $detailStatement->execute(array(
        'product_vendor_id' => $vendorId,
        'assignment_vendor_id' => $vendorId,
        'market_id' => $viewMarketId
    ));

    $selectedMarket = $detailStatement->fetch();

    if ($selectedMarket) {
        $photoStatement = $pdo->prepare(
            "SELECT
                photo_id,
                photo_name,
                photo_path,
                description
             FROM market_photo
             WHERE market_id = :market_id
             ORDER BY photo_id ASC"
        );

        $photoStatement->execute(array(
            'market_id' => $viewMarketId
        ));

        $selectedPhotos = $photoStatement->fetchAll();

        $categoryStatement = $pdo->prepare(
            "SELECT
                c.category_id,
                c.category_name,
                c.description,
                COUNT(p.product_id) AS vendor_product_count
             FROM categories c
             LEFT JOIN products p
                ON p.category_id = c.category_id
               AND p.vendor_id = :vendor_id
             WHERE c.market_id = :market_id
             GROUP BY
                c.category_id,
                c.category_name,
                c.description
             ORDER BY c.category_name ASC"
        );

        $categoryStatement->execute(array(
            'vendor_id' => $vendorId,
            'market_id' => $viewMarketId
        ));

        $selectedCategories = $categoryStatement->fetchAll();

        $eventStatement = $pdo->prepare(
            "SELECT
                event_id,
                event_name,
                start_date,
                end_date,
                description
             FROM events
             WHERE market_id = :market_id
               AND end_date >= CURDATE()
             ORDER BY start_date ASC
             LIMIT 6"
        );

        $eventStatement->execute(array(
            'market_id' => $viewMarketId
        ));

        $selectedEvents = $eventStatement->fetchAll();
    }
}

$showingFrom = $totalFilteredMarkets > 0
    ? $offset + 1
    : 0;

$showingTo = min(
    $offset + $perPage,
    $totalFilteredMarkets
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Assigned Markets | Farmers Market Vendor</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">

    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Vendor Assigned Markets Responsive Layout
        |--------------------------------------------------------------------------
        | Desktop: keep the full market table visible at normal 100% zoom.
        | Smaller screens: allow scrolling, but hide the visual scrollbar.
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

        .vendor-markets-scroll {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .vendor-markets-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .vendor-markets-table {
            width: 100%;
            min-width: 1080px;
        }

        .vendor-markets-table th,
        .vendor-markets-table td {
            vertical-align: middle;
        }

        @media (min-width: 1180px) {
            .vendor-markets-table {
                min-width: 0;
                table-layout: fixed;
            }

            .vendor-markets-table th:nth-child(1),
            .vendor-markets-table td:nth-child(1) {
                width: 22%;
            }

            .vendor-markets-table th:nth-child(2),
            .vendor-markets-table td:nth-child(2) {
                width: 10%;
            }

            .vendor-markets-table th:nth-child(3),
            .vendor-markets-table td:nth-child(3) {
                width: 12%;
            }

            .vendor-markets-table th:nth-child(4),
            .vendor-markets-table td:nth-child(4) {
                width: 12%;
            }

            .vendor-markets-table th:nth-child(5),
            .vendor-markets-table td:nth-child(5) {
                width: 8%;
            }

            .vendor-markets-table th:nth-child(6),
            .vendor-markets-table td:nth-child(6) {
                width: 9%;
            }

            .vendor-markets-table th:nth-child(7),
            .vendor-markets-table td:nth-child(7) {
                width: 7%;
            }

            .vendor-markets-table th:nth-child(8),
            .vendor-markets-table td:nth-child(8) {
                width: 10%;
            }

            .vendor-markets-table th:nth-child(9),
            .vendor-markets-table td:nth-child(9) {
                width: 10%;
            }

            .vendor-market-address {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>

<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">

<div class="min-h-screen">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="min-h-screen lg:ml-64">

        <?php require __DIR__ . '/header.php'; ?>

        <main class="px-4 pb-10 pt-5 sm:px-6 xl:px-7">

            <!-- Market List -->
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h1 class="text-lg font-extrabold text-slate-950">
                            Assigned Markets
                        </h1>

                        <p class="mt-1 text-xs text-slate-400">
                            Showing
                            <?php echo number_format($showingFrom); ?>
                            –
                            <?php echo number_format($showingTo); ?>
                            of
                            <?php echo number_format($totalFilteredMarkets); ?>
                            <?php echo $search !== ''
                                ? 'matching markets'
                                : 'assigned markets'; ?>
                        </p>
                    </div>

                    <span class="inline-flex w-fit items-center gap-2 rounded-xl bg-green-50 px-3 py-2 text-xs font-bold text-green-700 ring-1 ring-green-100">
                        <i class="fa-solid fa-shield-halved"></i>
                        Read-only access
                    </span>
                </div>

                <?php if (empty($markets)): ?>

                    <div class="px-5 py-16 text-center">

                        <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-green-50 text-green-600">
                            <i class="fa-solid fa-store-slash text-xl"></i>
                        </div>

                        <p class="mt-4 text-sm font-bold text-slate-700">
                            <?php echo $search !== ''
                                ? 'No markets match your search'
                                : 'No assigned markets found'; ?>
                        </p>

                        <p class="mx-auto mt-2 max-w-md text-xs leading-6 text-slate-400">
                            <?php if ($search !== ''): ?>
                                Try another market name, city, division or address.
                            <?php else: ?>
                                The administrator has not assigned a market to this Vendor yet.
                            <?php endif; ?>
                        </p>

                        <?php if ($search !== ''): ?>
                            <a href="markets.php"
                               class="mt-4 inline-flex items-center gap-2 rounded-xl border border-green-200 bg-white px-4 py-2 text-xs font-bold text-green-700 transition hover:bg-green-50">
                                <i class="fa-solid fa-rotate-left text-[10px]"></i>
                                Clear Search
                            </a>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <div class="vendor-markets-scroll overflow-x-auto">

                        <table class="vendor-markets-table divide-y divide-slate-200">

                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="whitespace-nowrap px-5 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Market
                                    </th>

                                    <th class="whitespace-nowrap px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        City
                                    </th>

                                    <th class="whitespace-nowrap px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Address
                                    </th>

                                    <th class="whitespace-nowrap px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Opening Hours
                                    </th>

                                    <th class="whitespace-nowrap px-4 py-3 text-center text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Categories
                                    </th>

                                    <th class="whitespace-nowrap px-4 py-3 text-center text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        My Products
                                    </th>

                                    <th class="whitespace-nowrap px-4 py-3 text-center text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Events
                                    </th>

                                    <th class="whitespace-nowrap px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Assigned
                                    </th>

                                    <th class="whitespace-nowrap px-5 py-3 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Action
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100 bg-white">

                                <?php foreach ($markets as $market): ?>
                                    <?php
                                    $photoUrl = vm_photo_url(
                                        $market['primary_photo']
                                    );
                                    ?>

                                    <tr class="transition hover:bg-green-50/40">

                                        <td class="px-5 py-4">
                                            <div class="flex min-w-0 items-center gap-3">

                                                <div class="h-12 w-12 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-green-50">

                                                    <?php if ($photoUrl !== ''): ?>

                                                        <img src="<?php echo vm_e($photoUrl); ?>"
                                                             alt="<?php echo vm_e($market['market_name']); ?>"
                                                             class="h-full w-full object-cover">

                                                    <?php else: ?>

                                                        <div class="grid h-full place-items-center text-green-300">
                                                            <i class="fa-solid fa-store text-lg"></i>
                                                        </div>

                                                    <?php endif; ?>
                                                </div>

                                                <div class="min-w-0">
                                                    <p class="max-w-[210px] truncate text-sm font-extrabold text-slate-900">
                                                        <?php echo vm_e($market['market_name']); ?>
                                                    </p>

                                                    <p class="mt-1 text-[10px] text-slate-400">
                                                        <?php echo number_format((int) $market['photo_count']); ?>
                                                        photo(s)
                                                    </p>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="whitespace-nowrap px-4 py-4">
                                            <p class="text-xs font-bold text-slate-700">
                                                <?php echo vm_e($market['city_name']); ?>
                                            </p>

                                            <?php if (
                                                trim((string) $market['administrative_division']) !== ''
                                            ): ?>
                                                <p class="mt-1 text-[10px] text-slate-400">
                                                    <?php echo vm_e($market['administrative_division']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </td>

                                        <td class="px-4 py-4">
                                            <p class="vendor-market-address max-w-[220px] line-clamp-2 text-xs leading-5 text-slate-500">
                                                <?php echo vm_e($market['address']); ?>
                                            </p>
                                        </td>

                                        <td class="whitespace-nowrap px-4 py-4 text-xs font-semibold text-slate-600">
                                            <?php echo vm_e(
                                                vm_format_time($market['opening_hour'])
                                            ); ?>
                                            –
                                            <?php echo vm_e(
                                                vm_format_time($market['closing_hour'])
                                            ); ?>
                                        </td>

                                        <td class="whitespace-nowrap px-4 py-4 text-center">
                                            <span class="inline-flex min-w-9 justify-center rounded-lg bg-green-50 px-2.5 py-1.5 text-xs font-bold text-green-700">
                                                <?php echo number_format((int) $market['category_count']); ?>
                                            </span>
                                        </td>

                                        <td class="whitespace-nowrap px-4 py-4 text-center">
                                            <span class="inline-flex min-w-9 justify-center rounded-lg bg-blue-50 px-2.5 py-1.5 text-xs font-bold text-blue-700">
                                                <?php echo number_format((int) $market['vendor_product_count']); ?>
                                            </span>
                                        </td>

                                        <td class="whitespace-nowrap px-4 py-4 text-center">
                                            <span class="inline-flex min-w-9 justify-center rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-700">
                                                <?php echo number_format((int) $market['upcoming_event_count']); ?>
                                            </span>
                                        </td>

                                        <td class="whitespace-nowrap px-4 py-4">
                                            <p class="text-xs font-semibold text-slate-600">
                                                <?php echo vm_e(
                                                    vm_format_date($market['assigned_at'])
                                                ); ?>
                                            </p>
                                        </td>

                                        <td class="whitespace-nowrap px-5 py-4 text-right">
                                            <a href="<?php echo vm_e(
                                                vm_query_url(array(
                                                    'view' => (int) $market['market_id']
                                                ))
                                            ); ?>"
                                               class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-green-700">

                                                <i class="fa-regular fa-eye text-[10px]"></i>
                                                View
                                            </a>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>

                <?php if ($totalPages > 1): ?>

                    <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                        <p class="text-xs text-slate-400">
                            Page
                            <?php echo number_format($page); ?>
                            of
                            <?php echo number_format($totalPages); ?>
                        </p>

                        <div class="flex flex-wrap items-center gap-1">

                            <a href="<?php echo vm_e(
                                vm_query_url(array(
                                    'page' => max(1, $page - 1)
                                ))
                            ); ?>"
                               class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50
                               <?php echo $page <= 1
                                   ? 'pointer-events-none opacity-40'
                                   : ''; ?>">

                                <i class="fa-solid fa-chevron-left text-[9px]"></i>
                                Previous
                            </a>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min(
                                $totalPages,
                                $page + 2
                            );
                            ?>

                            <?php for (
                                $pageNumber = $startPage;
                                $pageNumber <= $endPage;
                                $pageNumber++
                            ): ?>

                                <a href="<?php echo vm_e(
                                    vm_query_url(array(
                                        'page' => $pageNumber
                                    ))
                                ); ?>"
                                   class="grid h-9 w-9 place-items-center rounded-lg text-xs font-bold
                                   <?php echo $pageNumber === $page
                                       ? 'bg-green-600 text-white'
                                       : 'border border-slate-200 text-slate-500 hover:bg-slate-50'; ?>">

                                    <?php echo $pageNumber; ?>
                                </a>

                            <?php endfor; ?>

                            <a href="<?php echo vm_e(
                                vm_query_url(array(
                                    'page' => min(
                                        $totalPages,
                                        $page + 1
                                    )
                                ))
                            ); ?>"
                               class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50
                               <?php echo $page >= $totalPages
                                   ? 'pointer-events-none opacity-40'
                                   : ''; ?>">

                                Next
                                <i class="fa-solid fa-chevron-right text-[9px]"></i>
                            </a>
                        </div>
                    </div>

                <?php endif; ?>
            </section>

        </main>
    </div>
</div>

<!-- Market Detail Modal -->
<?php if ($selectedMarket): ?>
    <div class="fixed inset-0 z-[70] overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm">

        <div class="mx-auto flex min-h-full max-w-5xl items-center justify-center">

            <div class="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">

                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">

                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-green-600">
                            Assigned Market
                        </p>

                        <h2 class="mt-1 text-xl font-extrabold text-slate-950">
                            <?php echo vm_e($selectedMarket['market_name']); ?>
                        </h2>
                    </div>

                    <a href="<?php echo vm_e(vm_query_url(array())); ?>"
                       title="Close"
                       class="grid h-10 w-10 place-items-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700">

                        <i class="fa-solid fa-xmark"></i>
                    </a>
                </div>

                <div class="vendor-markets-scroll max-h-[80vh] overflow-y-auto p-5">

                    <?php if (!empty($selectedPhotos)): ?>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">

                            <?php foreach ($selectedPhotos as $photo): ?>
                                <figure class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50">

                                    <img src="<?php echo vm_e(vm_photo_url($photo['photo_path'])); ?>"
                                         alt="<?php echo vm_e($photo['photo_name']); ?>"
                                         class="h-44 w-full object-cover">

                                    <figcaption class="p-3">
                                        <p class="truncate text-xs font-bold text-slate-700">
                                            <?php echo vm_e($photo['photo_name']); ?>
                                        </p>

                                        <?php if (trim((string) $photo['description']) !== ''): ?>
                                            <p class="mt-1 line-clamp-2 text-[10px] leading-4 text-slate-400">
                                                <?php echo vm_e($photo['description']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="grid h-52 place-items-center rounded-xl bg-gradient-to-br from-green-50 to-slate-100 text-green-300">
                            <i class="fa-solid fa-store text-6xl"></i>
                        </div>
                    <?php endif; ?>

                    <div class="mt-5 grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">

                        <div class="space-y-5">

                            <section class="rounded-xl border border-slate-200 p-4">

                                <h3 class="text-sm font-bold text-slate-900">
                                    Market Information
                                </h3>

                                <p class="mt-3 text-xs leading-6 text-slate-500">
                                    <?php echo vm_e($selectedMarket['description']); ?>
                                </p>

                                <div class="mt-4 grid gap-3 sm:grid-cols-2">

                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">
                                            Location
                                        </p>

                                        <p class="mt-1 text-xs font-semibold leading-5 text-slate-700">
                                            <?php echo vm_e($selectedMarket['address']); ?>
                                        </p>

                                        <p class="mt-1 text-[10px] text-slate-400">
                                            <?php echo vm_e(
                                                $selectedMarket['city_name'] .
                                                ', ' .
                                                $selectedMarket['administrative_division']
                                            ); ?>
                                        </p>
                                    </div>

                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">
                                            Opening Hours
                                        </p>

                                        <p class="mt-1 text-xs font-semibold text-slate-700">
                                            <?php echo vm_e(vm_format_time($selectedMarket['opening_hour'])); ?>
                                            –
                                            <?php echo vm_e(vm_format_time($selectedMarket['closing_hour'])); ?>
                                        </p>

                                        <p class="mt-1 text-[10px] text-slate-400">
                                            Assigned
                                            <?php echo vm_e(vm_format_date($selectedMarket['assigned_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-xl border border-slate-200 p-4">

                                <div class="flex items-center justify-between">

                                    <div>
                                        <h3 class="text-sm font-bold text-slate-900">
                                            Available Categories
                                        </h3>

                                        <p class="mt-1 text-[11px] text-slate-400">
                                            Products can only be added under these categories.
                                        </p>
                                    </div>

                                    <a href="products.php?action=create"
                                       class="text-xs font-bold text-green-600 hover:text-green-700">
                                        Add Product
                                    </a>
                                </div>

                                <div class="mt-4 grid gap-3 sm:grid-cols-2">

                                    <?php if (empty($selectedCategories)): ?>
                                        <p class="text-xs text-slate-400">
                                            No categories are available for this market.
                                        </p>
                                    <?php else: ?>
                                        <?php foreach ($selectedCategories as $category): ?>

                                            <a href="products.php?category_id=<?php echo (int) $category['category_id']; ?>"
                                               class="rounded-xl border border-slate-200 p-3 transition hover:border-green-200 hover:bg-green-50">

                                                <div class="flex items-center justify-between gap-3">

                                                    <p class="truncate text-xs font-bold text-slate-700">
                                                        <?php echo vm_e($category['category_name']); ?>
                                                    </p>

                                                    <span class="rounded-md bg-green-50 px-2 py-1 text-[9px] font-bold text-green-700 ring-1 ring-inset ring-green-200">
                                                        <?php echo number_format((int) $category['vendor_product_count']); ?>
                                                        products
                                                    </span>
                                                </div>

                                                <p class="mt-2 line-clamp-2 text-[10px] leading-4 text-slate-400">
                                                    <?php echo vm_e($category['description']); ?>
                                                </p>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </section>
                        </div>

                        <section class="rounded-xl border border-slate-200 p-4">

                            <div class="flex items-center justify-between">

                                <div>
                                    <h3 class="text-sm font-bold text-slate-900">
                                        Upcoming Events
                                    </h3>

                                    <p class="mt-1 text-[11px] text-slate-400">
                                        Events created by the administrator.
                                    </p>
                                </div>

                                <a href="events.php"
                                   class="text-xs font-bold text-green-600 hover:text-green-700">
                                    View all
                                </a>
                            </div>

                            <div class="mt-4 space-y-3">

                                <?php if (empty($selectedEvents)): ?>
                                    <div class="py-10 text-center">

                                        <div class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-green-50 text-green-600">
                                            <i class="fa-regular fa-calendar"></i>
                                        </div>

                                        <p class="mt-3 text-xs font-semibold text-slate-600">
                                            No upcoming events
                                        </p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($selectedEvents as $event): ?>
                                        <?php
                                        $eventTimestamp = strtotime($event['start_date']);
                                        ?>

                                        <div class="flex gap-3 rounded-xl bg-slate-50 p-3">

                                            <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-green-600 text-center text-white">

                                                <span>
                                                    <span class="block text-sm font-extrabold leading-none">
                                                        <?php echo $eventTimestamp !== false
                                                            ? date('d', $eventTimestamp)
                                                            : '--'; ?>
                                                    </span>

                                                    <span class="mt-1 block text-[9px] font-bold uppercase">
                                                        <?php echo $eventTimestamp !== false
                                                            ? date('M', $eventTimestamp)
                                                            : '---'; ?>
                                                    </span>
                                                </span>
                                            </div>

                                            <div class="min-w-0 flex-1">

                                                <p class="truncate text-xs font-bold text-slate-700">
                                                    <?php echo vm_e($event['event_name']); ?>
                                                </p>

                                                <p class="mt-1 text-[10px] text-slate-400">
                                                    <?php echo vm_e(vm_format_date($event['start_date'])); ?>
                                                    –
                                                    <?php echo vm_e(vm_format_date($event['end_date'])); ?>
                                                </p>

                                                <p class="mt-1 line-clamp-2 text-[10px] leading-4 text-slate-500">
                                                    <?php echo vm_e($event['description']); ?>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-slate-100 px-5 py-4 sm:flex-row sm:justify-end">

                    <a href="<?php echo vm_e(vm_query_url(array())); ?>"
                       class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">

                        Close
                    </a>

                    <a href="products.php?action=create"
                       class="inline-flex items-center justify-center gap-2 rounded-xl bg-green-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-green-700">

                        <i class="fa-solid fa-plus"></i>
                        Add Product
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

</body>
</html>