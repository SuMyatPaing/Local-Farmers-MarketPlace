<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
fm_require_role('vendor');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('vendor_page_e')) {
    function vendor_page_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('vendor_page_redirect')) {
    function vendor_page_redirect($location)
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('vendor_page_photo_url')) {
    function vendor_page_photo_url($path)
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $path) || strpos($path, 'data:') === 0) {
            return $path;
        }

        return '../' . ltrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('vendor_page_date')) {
    function vendor_page_date($value, $format)
    {
        $timestamp = strtotime((string) $value);

        return $timestamp !== false ? date($format, $timestamp) : '—';
    }
}

if (!function_exists('vendor_page_initials')) {
    function vendor_page_initials($name)
    {
        $parts = preg_split('/\s+/', trim((string) $name));
        $initials = '';

        foreach ($parts as $part) {
            if ($part !== '') {
                $initials .= strtoupper(substr($part, 0, 1));
            }

            if (strlen($initials) >= 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : 'U';
    }
}

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

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$sessionVendorId = isset($_SESSION['vendor_id']) ? (int) $_SESSION['vendor_id'] : 0;
$sessionRole = isset($_SESSION['role']) ? strtolower((string) $_SESSION['role']) : '';

if ($sessionRole !== '' && $sessionRole !== 'vendor') {
    vendor_page_redirect('../signin.php');
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

    $vendorStatement->execute(array('vendor_id' => $sessionVendorId));
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

    $vendorStatement->execute(array('user_id' => $userId));
} else {
    vendor_page_redirect('../signin.php');
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
    vendor_page_redirect('dashboard.php');
}

$statusFilter = isset($_GET['status'])
    ? strtolower(trim((string) $_GET['status']))
    : 'all';

$search = isset($_GET['q'])
    ? trim((string) $_GET['q'])
    : '';

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 10;

$allowedStatuses = array(
    'all',
    'ongoing',
    'upcoming',
    'past'
);

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$statsStatement = $pdo->prepare(
    "SELECT
        COUNT(DISTINCT e.event_id) AS total_events,
        COUNT(DISTINCT CASE
            WHEN CURDATE() BETWEEN e.start_date AND e.end_date THEN e.event_id
            ELSE NULL
        END) AS ongoing_events,
        COUNT(DISTINCT CASE
            WHEN e.start_date > CURDATE() THEN e.event_id
            ELSE NULL
        END) AS upcoming_events,
        COUNT(DISTINCT CASE
            WHEN e.end_date < CURDATE() THEN e.event_id
            ELSE NULL
        END) AS past_events
     FROM events e"
);

$statsStatement->execute();
$stats = $statsStatement->fetch();

$whereParts = array('1 = 1');
$parameters = array();

if ($statusFilter === 'ongoing') {
    $whereParts[] =
        'CURDATE() BETWEEN e.start_date AND e.end_date';
} elseif ($statusFilter === 'upcoming') {
    $whereParts[] =
        'e.start_date > CURDATE()';
} elseif ($statusFilter === 'past') {
    $whereParts[] =
        'e.end_date < CURDATE()';
}

if ($search !== '') {
    $whereParts[] = "(
        e.event_name LIKE :search_event
        OR e.description LIKE :search_description
        OR m.market_name LIKE :search_market
        OR m.address LIKE :search_address
        OR ci.city_name LIKE :search_city
        OR ci.administrative_division LIKE :search_division
    )";

    $searchValue = '%' . $search . '%';

    $parameters['search_event'] = $searchValue;
    $parameters['search_description'] = $searchValue;
    $parameters['search_market'] = $searchValue;
    $parameters['search_address'] = $searchValue;
    $parameters['search_city'] = $searchValue;
    $parameters['search_division'] = $searchValue;
}

$whereSql = implode(' AND ', $whereParts);

$countStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM events e
     INNER JOIN markets m ON m.market_id = e.market_id
     INNER JOIN cities ci ON ci.city_id = m.city_id
     WHERE {$whereSql}"
);

foreach ($parameters as $name => $value) {
    $countStatement->bindValue(
        ':' . $name,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}

$countStatement->execute();
$totalFilteredEvents = (int) $countStatement->fetchColumn();

$totalPages = max(1, (int) ceil($totalFilteredEvents / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$listStatement = $pdo->prepare(
    "SELECT
        e.event_id,
        e.event_name,
        e.start_date,
        e.end_date,
        e.description,
        e.created_at,
        m.market_id,
        m.market_name,
        m.address,
        m.opening_hour,
        m.closing_hour,
        ci.city_name,
        ci.administrative_division,
        CASE
            WHEN CURDATE() BETWEEN e.start_date AND e.end_date THEN 'ongoing'
            WHEN e.start_date > CURDATE() THEN 'upcoming'
            ELSE 'past'
        END AS event_status,
        (
            SELECT mp.photo_path
            FROM market_photo mp
            WHERE mp.market_id = m.market_id
            ORDER BY mp.photo_id ASC
            LIMIT 1
        ) AS market_photo
     FROM events e
     INNER JOIN markets m ON m.market_id = e.market_id
     INNER JOIN cities ci ON ci.city_id = m.city_id
     WHERE {$whereSql}
     ORDER BY
        CASE
            WHEN CURDATE() BETWEEN e.start_date AND e.end_date THEN 1
            WHEN e.start_date > CURDATE() THEN 2
            ELSE 3
        END,
        e.start_date ASC
     LIMIT :limit_value OFFSET :offset_value"
);

foreach ($parameters as $name => $value) {
    $listStatement->bindValue(
        ':' . $name,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}

$listStatement->bindValue(':limit_value', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset_value', $offset, PDO::PARAM_INT);
$listStatement->execute();

$events = $listStatement->fetchAll();

$selectedEvent = null;
$viewId = isset($_GET['view']) ? (int) $_GET['view'] : 0;

if ($viewId > 0) {
    $detailStatement = $pdo->prepare(
        "SELECT
            e.event_id,
            e.event_name,
            e.start_date,
            e.end_date,
            e.description,
            e.created_at,
            m.market_id,
            m.market_name,
            m.address,
            m.opening_hour,
            m.closing_hour,
            m.description AS market_description,
            ci.city_name,
            ci.administrative_division,
            CASE
                WHEN CURDATE() BETWEEN e.start_date AND e.end_date THEN 'ongoing'
                WHEN e.start_date > CURDATE() THEN 'upcoming'
                ELSE 'past'
            END AS event_status,
            (
                SELECT mp.photo_path
                FROM market_photo mp
                WHERE mp.market_id = m.market_id
                ORDER BY mp.photo_id ASC
                LIMIT 1
            ) AS market_photo
         FROM events e
         INNER JOIN markets m ON m.market_id = e.market_id
         INNER JOIN cities ci ON ci.city_id = m.city_id
         WHERE e.event_id = :event_id
         LIMIT 1"
    );

    $detailStatement->execute(array(
        'event_id' => $viewId
    ));

    $selectedEvent = $detailStatement->fetch();
}

function vendor_event_url($overrides)
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

    return 'events.php' . ($query !== '' ? '?' . $query : '');
}

$showingFrom = $totalFilteredEvents > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalFilteredEvents);

$statusClasses = array(
    'ongoing' => 'bg-green-50 text-green-700 ring-green-200',
    'upcoming' => 'bg-blue-50 text-blue-700 ring-blue-200',
    'past' => 'bg-slate-100 text-slate-600 ring-slate-200'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events | Farmers Market Vendor</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">

    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    


    <style>
        /*
        |--------------------------------------------------------------------------
        | Vendor Events Responsive Layout
        |--------------------------------------------------------------------------
        | Desktop: Event / Market / Schedule / Status stay on one row.
        | Scrollbars are visually hidden while scrolling still works.
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

        .vendor-events-scroll {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .vendor-events-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .vendor-events-table {
            width: 100%;
            min-width: 820px;
        }

        .vendor-events-table th,
        .vendor-events-table td {
            vertical-align: middle;
        }

        @media (min-width: 1180px) {
            .vendor-events-table {
                min-width: 0;
                table-layout: fixed;
            }

            .vendor-events-table th:nth-child(1),
            .vendor-events-table td:nth-child(1) {
                width: 32%;
            }

            .vendor-events-table th:nth-child(2),
            .vendor-events-table td:nth-child(2) {
                width: 27%;
            }

            .vendor-events-table th:nth-child(3),
            .vendor-events-table td:nth-child(3) {
                width: 29%;
            }

            .vendor-events-table th:nth-child(4),
            .vendor-events-table td:nth-child(4) {
                width: 12%;
            }

            .vendor-event-market,
            .vendor-event-location {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">

    <style>
        /* ==============================================================
           Vendor Events - final desktop reference layout
           Target: browser zoom / screen size 100%
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
        .vendor-events-scroll::-webkit-scrollbar {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
        }

        .vendor-events-scroll {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        @media (min-width: 1150px) {
            /* Sidebar + desktop header */
            #vendorSidebar {
                display: flex !important;
                width: 16rem !important;
                transform: translateX(0) !important;
                visibility: visible !important;
            }

            #vendorSidebarOverlay {
                display: none !important;
            }

            #vendorHeaderMenuButton,
            #closeVendorSidebar {
                display: none !important;
            }

            .vendor-events-page-shell {
                margin-left: 16rem !important;
                width: calc(100% - 16rem) !important;
                max-width: calc(100% - 16rem) !important;
                padding-left: 0 !important;
            }

            .vendor-events-page-shell header {
                min-height: 4rem !important;
                height: 4rem !important;
            }

            .vendor-events-page-shell header > div {
                min-height: 4rem !important;
                padding-left: 1.5rem !important;
                padding-right: 1.5rem !important;
            }

            .vendor-events-page-shell header form[method="get"] {
                display: block !important;
            }

            .vendor-events-page-shell header a[title="Open marketplace"] {
                display: grid !important;
            }

            #vendorProfileButton > span:nth-child(2),
            #vendorProfileChevron {
                display: block !important;
            }

            /* Main content */
            .vendor-events-page-shell main {
                width: 100% !important;
                max-width: 100% !important;
                padding: 1.25rem 1.5rem 2.5rem !important;
            }

            /* Top stats = exactly 3 equal cards */
            .vendor-events-stats {
                display: grid !important;
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
                gap: 1rem !important;
            }

            .vendor-events-stats > article {
                min-width: 0 !important;
                min-height: 104px !important;
                padding: 1.1rem 1.25rem !important;
                border-radius: 16px !important;
            }

            .vendor-events-stats > article .h-12 {
                width: 3rem !important;
                height: 3rem !important;
            }

            .vendor-events-stats > article p.text-2xl {
                font-size: 1.35rem !important;
                line-height: 1 !important;
            }

            /* Filter bar like the screenshot:
               status select + Filter + Clear, left aligned */
            .vendor-events-filter-card {
                padding: 1rem !important;
                border-radius: 16px !important;
            }

            .vendor-events-filter-form {
                display: flex !important;
                flex-direction: row !important;
                align-items: center !important;
                gap: .75rem !important;
                width: 100% !important;
                flex-wrap: nowrap !important;
            }

            .vendor-events-status-select {
                flex: 0 1 390px !important;
                width: 390px !important;
                max-width: 390px !important;
                min-width: 260px !important;
                height: 48px !important;
                border-radius: 12px !important;
                padding-left: 1rem !important;
                padding-right: 1rem !important;
                font-size: 1rem !important;
            }

            .vendor-events-filter-actions {
                display: inline-flex !important;
                flex: 0 0 auto !important;
                gap: .6rem !important;
                align-items: center !important;
            }

            .vendor-events-filter-actions > button,
            .vendor-events-filter-actions > a {
                height: 48px !important;
                border-radius: 12px !important;
                font-size: .95rem !important;
                flex: 0 0 auto !important;
            }

            .vendor-events-filter-actions > button {
                min-width: 116px !important;
                padding-left: 1.1rem !important;
                padding-right: 1.1rem !important;
            }

            .vendor-events-filter-actions > a {
                min-width: 108px !important;
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }

            /* Event list */
            .vendor-events-list-card {
                border-radius: 16px !important;
            }

            .vendor-events-scroll {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: hidden !important;
            }

            .vendor-events-table {
                width: 100% !important;
                min-width: 0 !important;
                table-layout: fixed !important;
            }

            .vendor-events-table th:nth-child(1),
            .vendor-events-table td:nth-child(1) {
                width: 32% !important;
            }

            .vendor-events-table th:nth-child(2),
            .vendor-events-table td:nth-child(2) {
                width: 27% !important;
            }

            .vendor-events-table th:nth-child(3),
            .vendor-events-table td:nth-child(3) {
                width: 29% !important;
            }

            .vendor-events-table th:nth-child(4),
            .vendor-events-table td:nth-child(4) {
                width: 12% !important;
            }

            .vendor-events-table th {
                padding-top: .9rem !important;
                padding-bottom: .9rem !important;
            }

            .vendor-events-table td {
                padding-top: 1rem !important;
                padding-bottom: 1rem !important;
            }

            .vendor-event-market,
            .vendor-event-location {
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                white-space: nowrap !important;
            }
        }

        /* Keep normal responsive behavior only when compact/high zoom. */
        @media (max-width: 1149.98px) {
            .vendor-events-page-shell {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .vendor-events-filter-form {
                flex-wrap: wrap !important;
            }

            .vendor-events-scroll {
                overflow-x: auto !important;
            }

            .vendor-events-table {
                min-width: 820px !important;
            }
        }
    </style>

</head>

<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">

<div class="min-h-screen">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="vendor-events-page-shell min-h-screen">
        <?php require __DIR__ . '/header.php'; ?>

        <main class="px-4 pb-10 pt-5 sm:px-6 xl:px-7">

            <section class="vendor-events-stats grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <?php
                $eventStats = array(
                    array(
                        'label' => 'Total Events',
                        'value' => (int) $stats['total_events'],
                        'icon' => 'fa-calendar',
                        'class' => 'bg-green-100 text-green-600'
                    ),
                    array(
                        'label' => 'Ongoing',
                        'value' => (int) $stats['ongoing_events'],
                        'icon' => 'fa-circle-play',
                        'class' => 'bg-emerald-100 text-emerald-600'
                    ),
                    array(
                        'label' => 'Upcoming',
                        'value' => (int) $stats['upcoming_events'],
                        'icon' => 'fa-clock',
                        'class' => 'bg-blue-100 text-blue-600'
                    )
                );
                ?>

                <?php foreach ($eventStats as $item): ?>
                    <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                        <div class="flex items-center gap-3">
                            <span class="grid h-12 w-12 place-items-center rounded-full <?php echo vendor_page_e($item['class']); ?>">
                                <i class="fa-solid <?php echo vendor_page_e($item['icon']); ?>"></i>
                            </span>

                            <div>
                                <p class="text-xs font-semibold text-slate-500">
                                    <?php echo vendor_page_e($item['label']); ?>
                                </p>

                                <p class="mt-1 text-2xl font-extrabold text-slate-950">
                                    <?php echo number_format($item['value']); ?>
                                </p>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="vendor-events-filter-card mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-card">

                <form method="get"
                      action="events.php"
                      class="vendor-events-filter-form flex flex-col gap-3 sm:flex-row sm:items-center">

                    <?php if ($search !== ''): ?>
                        <input type="hidden"
                               name="q"
                               value="<?php echo vendor_page_e($search); ?>">
                    <?php endif; ?>

                    <select name="status"
                            class="vendor-events-status-select w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100 sm:max-w-xs">

                        <option value="all"
                            <?php echo $statusFilter === 'all'
                                ? 'selected'
                                : ''; ?>>
                            All event statuses
                        </option>

                        <option value="ongoing"
                            <?php echo $statusFilter === 'ongoing'
                                ? 'selected'
                                : ''; ?>>
                            Ongoing
                        </option>

                        <option value="upcoming"
                            <?php echo $statusFilter === 'upcoming'
                                ? 'selected'
                                : ''; ?>>
                            Upcoming
                        </option>

                        <option value="past"
                            <?php echo $statusFilter === 'past'
                                ? 'selected'
                                : ''; ?>>
                            Past
                        </option>
                    </select>

                    <div class="vendor-events-filter-actions flex gap-2">

                        <button type="submit"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-green-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-green-700">

                            <i class="fa-solid fa-filter text-xs"></i>
                            Filter
                        </button>

                        <a href="events.php"
                           title="Clear filters"
                           class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-500 hover:bg-slate-50 hover:text-slate-800">

                            <i class="fa-solid fa-rotate-left text-xs"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </section>

            <!-- Event List Table -->
            <section class="vendor-events-list-card mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h2 class="font-bold text-slate-950">
                            Event List
                        </h2>

                        <p class="vendor-event-location mt-1 truncate text-xs text-slate-400">
                            <?php echo number_format($totalFilteredEvents); ?>
                            <?php echo $search !== ''
                                ? 'search result(s)'
                                : 'matching event(s)'; ?>
                        </p>
                    </div>

                    <span class="inline-flex w-fit rounded-full bg-green-50 px-3 py-1.5 text-[10px] font-bold capitalize text-green-700">

                        <?php echo vendor_page_e(
                            $statusFilter === 'all'
                                ? 'All'
                                : $statusFilter
                        ); ?>
                    </span>
                </div>

                <?php if (empty($events)): ?>

                    <div class="px-5 py-16 text-center">

                        <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-green-50 text-green-600">
                            <i class="fa-regular fa-calendar-xmark text-xl"></i>
                        </div>

                        <p class="mt-4 text-sm font-bold text-slate-700">
                            <?php echo $search !== ''
                                ? 'No events match your search'
                                : 'No events found'; ?>
                        </p>

                        <p class="mt-1 text-xs text-slate-400">
                            <?php if ($search !== ''): ?>
                                Try another event name, market, city or location.
                            <?php else: ?>
                                No administrator-created events match the current filter.
                            <?php endif; ?>
                        </p>

                        <?php if ($search !== ''): ?>
                            <a href="events.php"
                               class="mt-4 inline-flex items-center gap-2 rounded-xl border border-green-200 bg-white px-4 py-2 text-xs font-bold text-green-700 transition hover:bg-green-50">
                                <i class="fa-solid fa-rotate-left text-[10px]"></i>
                                Clear Search
                            </a>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <div class="vendor-events-scroll overflow-x-auto">

                        <table class="vendor-events-table divide-y divide-slate-200">

                            <thead class="bg-slate-50">

                                <tr>
                                    <th scope="col"
                                        class="whitespace-nowrap px-5 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                        Event
                                    </th>

                                    <th scope="col"
                                        class="whitespace-nowrap px-5 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                        Market
                                    </th>

                                    <th scope="col"
                                        class="whitespace-nowrap px-5 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                        Schedule
                                    </th>

                                    <th scope="col"
                                        class="whitespace-nowrap px-5 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                        Status
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100 bg-white">

                                <?php foreach ($events as $event): ?>
                                    <?php
                                    $eventStatus = strtolower(
                                        (string) $event['event_status']
                                    );

                                    $statusClass = isset(
                                        $statusClasses[$eventStatus]
                                    )
                                        ? $statusClasses[$eventStatus]
                                        : 'bg-slate-100 text-slate-600 ring-slate-200';

                                    $startTimestamp = strtotime(
                                        (string) $event['start_date']
                                    );

                                    $endTimestamp = strtotime(
                                        (string) $event['end_date']
                                    );

                                    $eventDays = 0;

                                    if (
                                        $startTimestamp !== false &&
                                        $endTimestamp !== false &&
                                        $endTimestamp >= $startTimestamp
                                    ) {
                                        $eventDays = (int) floor(
                                            ($endTimestamp - $startTimestamp) / 86400
                                        ) + 1;
                                    }
                                    ?>

                                    <tr class="transition hover:bg-green-50/40">

                                        <!-- Event -->
                                        <td class="px-5 py-4">

                                            <div class="flex min-w-0 items-start gap-3">

                                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600">

                                                    <i class="fa-solid fa-calendar-day"></i>
                                                </span>

                                                <div class="min-w-0">

                                                    <a href="<?php echo vendor_page_e(
                                                        vendor_event_url(array(
                                                            'view' => (int) $event['event_id']
                                                        ))
                                                    ); ?>"
                                                       class="block max-w-[250px] truncate text-sm font-extrabold text-slate-900 hover:text-green-700">

                                                        <?php echo vendor_page_e(
                                                            $event['event_name']
                                                        ); ?>
                                                    </a>

                                                    <p class="mt-1 max-w-[250px] truncate text-xs text-slate-500">

                                                        <?php echo vendor_page_e(
                                                            $event['description']
                                                        ); ?>
                                                    </p>

                                                    <p class="mt-2 text-[10px] font-semibold text-slate-400">

                                                        ID #
                                                        <?php echo (int) $event['event_id']; ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Market -->
                                        <td class="px-5 py-4">

                                            <div class="min-w-0">

                                                <p class="vendor-event-market truncate text-sm font-bold text-slate-800">

                                                    <?php echo vendor_page_e(
                                                        $event['market_name']
                                                    ); ?>
                                                </p>

                                                <p class="mt-1 text-xs text-slate-400">

                                                    <i class="fa-solid fa-location-dot mr-1"></i>

                                                    <?php echo vendor_page_e(
                                                        $event['city_name'] .
                                                        (
                                                            trim(
                                                                (string) $event['administrative_division']
                                                            ) !== ''
                                                                ? ', ' . $event['administrative_division']
                                                                : ''
                                                        )
                                                    ); ?>
                                                </p>
                                            </div>
                                        </td>

                                        <!-- Schedule -->
                                        <td class="px-5 py-4">

                                            <div class="min-w-0">

                                                <p class="whitespace-nowrap text-sm font-bold text-slate-700">

                                                    <?php echo vendor_page_e(
                                                        vendor_page_date(
                                                            $event['start_date'],
                                                            'M d, Y'
                                                        )
                                                    ); ?>

                                                    <span class="mx-1 text-slate-300">
                                                        →
                                                    </span>

                                                    <?php echo vendor_page_e(
                                                        vendor_page_date(
                                                            $event['end_date'],
                                                            'M d, Y'
                                                        )
                                                    ); ?>
                                                </p>

                                                <p class="mt-1 text-xs text-slate-400">

                                                    <?php echo number_format($eventDays); ?>

                                                    <?php echo $eventDays === 1
                                                        ? 'day'
                                                        : 'days'; ?>
                                                </p>
                                            </div>
                                        </td>

                                        <!-- Status -->
                                        <td class="px-5 py-4">

                                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold capitalize ring-1 ring-inset <?php echo vendor_page_e($statusClass); ?>">

                                                <?php if ($eventStatus === 'ongoing'): ?>

                                                    <i class="fa-solid fa-circle-play text-[9px]"></i>

                                                <?php elseif ($eventStatus === 'upcoming'): ?>

                                                    <i class="fa-regular fa-clock text-[9px]"></i>

                                                <?php else: ?>

                                                    <i class="fa-solid fa-clock-rotate-left text-[9px]"></i>

                                                <?php endif; ?>

                                                <?php echo vendor_page_e($eventStatus); ?>
                                            </span>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                        <p class="text-xs text-slate-400">

                            Showing
                            <?php echo number_format($showingFrom); ?>
                            to
                            <?php echo number_format($showingTo); ?>
                            of
                            <?php echo number_format($totalFilteredEvents); ?>
                            events
                        </p>

                        <?php if ($totalPages > 1): ?>

                            <div class="flex items-center gap-1">

                                <a href="<?php echo vendor_page_e(
                                    vendor_event_url(array(
                                        'page' => max(1, $page - 1)
                                    ))
                                ); ?>"
                                   class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50 <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : ''; ?>">

                                    <i class="fa-solid fa-chevron-left text-[9px]"></i>

                                    Previous
                                </a>

                                <?php for (
                                    $pageNumber = max(1, $page - 2);
                                    $pageNumber <= min($totalPages, $page + 2);
                                    $pageNumber++
                                ): ?>

                                    <a href="<?php echo vendor_page_e(
                                        vendor_event_url(array(
                                            'page' => $pageNumber
                                        ))
                                    ); ?>"
                                       class="grid h-9 w-9 place-items-center rounded-lg text-xs font-bold <?php echo $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50'; ?>">

                                        <?php echo $pageNumber; ?>
                                    </a>

                                <?php endfor; ?>

                                <a href="<?php echo vendor_page_e(
                                    vendor_event_url(array(
                                        'page' => min(
                                            $totalPages,
                                            $page + 1
                                        )
                                    ))
                                ); ?>"
                                   class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50 <?php echo $page >= $totalPages ? 'pointer-events-none opacity-40' : ''; ?>">

                                    Next

                                    <i class="fa-solid fa-chevron-right text-[9px]"></i>
                                </a>
                            </div>

                        <?php endif; ?>
                    </div>

                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<?php if ($selectedEvent): ?>
    <?php
    $selectedStatus = strtolower((string) $selectedEvent['event_status']);
    $selectedStatusClass = isset($statusClasses[$selectedStatus])
        ? $statusClasses[$selectedStatus]
        : 'bg-slate-100 text-slate-600 ring-slate-200';
    $selectedPhoto = vendor_page_photo_url($selectedEvent['market_photo']);
    ?>

    <div class="vendor-events-scroll fixed inset-0 z-[70] overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm">
        <div class="mx-auto flex min-h-full max-w-3xl items-center justify-center">
            <article class="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div class="relative h-56 overflow-hidden bg-gradient-to-br from-green-50 to-slate-100">
                    <?php if ($selectedPhoto !== ''): ?>
                        <img src="<?php echo vendor_page_e($selectedPhoto); ?>"
                             alt="<?php echo vendor_page_e($selectedEvent['market_name']); ?>"
                             class="h-full w-full object-cover">
                    <?php else: ?>
                        <div class="grid h-full place-items-center text-green-300">
                            <i class="fa-solid fa-calendar-days text-6xl"></i>
                        </div>
                    <?php endif; ?>

                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-transparent"></div>

                    <a href="<?php echo vendor_page_e(vendor_event_url(array())); ?>"
                       class="absolute right-4 top-4 grid h-10 w-10 place-items-center rounded-xl bg-white/90 text-slate-600 shadow-lg hover:bg-white">
                        <i class="fa-solid fa-xmark"></i>
                    </a>

                    <div class="absolute inset-x-0 bottom-0 p-5 text-white">
                        <span class="inline-flex rounded-md px-2 py-1 text-[10px] font-bold capitalize ring-1 ring-inset <?php echo vendor_page_e($selectedStatusClass); ?>">
                            <?php echo vendor_page_e($selectedStatus); ?>
                        </span>

                        <h2 class="mt-3 text-2xl font-extrabold">
                            <?php echo vendor_page_e($selectedEvent['event_name']); ?>
                        </h2>

                        <p class="mt-1 text-sm text-white/80">
                            <?php echo vendor_page_e($selectedEvent['market_name']); ?>
                        </p>
                    </div>
                </div>

                <div class="p-5">
                    <p class="text-sm leading-7 text-slate-600">
                        <?php echo vendor_page_e($selectedEvent['description']); ?>
                    </p>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl bg-slate-50 p-4">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Event Dates</p>
                            <p class="mt-2 text-sm font-bold text-slate-700">
                                <?php echo vendor_page_e(vendor_page_date($selectedEvent['start_date'], 'M j, Y')); ?>
                                –
                                <?php echo vendor_page_e(vendor_page_date($selectedEvent['end_date'], 'M j, Y')); ?>
                            </p>
                        </div>

                        <div class="rounded-xl bg-slate-50 p-4">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Market Hours</p>
                            <p class="mt-2 text-sm font-bold text-slate-700">
                                <?php echo vendor_page_e(vendor_page_date($selectedEvent['opening_hour'], 'g:i A')); ?>
                                –
                                <?php echo vendor_page_e(vendor_page_date($selectedEvent['closing_hour'], 'g:i A')); ?>
                            </p>
                        </div>

                        <div class="rounded-xl bg-slate-50 p-4 sm:col-span-2">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Location</p>
                            <p class="mt-2 text-sm font-bold text-slate-700">
                                <?php echo vendor_page_e($selectedEvent['address']); ?>
                            </p>
                            <p class="mt-1 text-xs text-slate-400">
                                <?php echo vendor_page_e($selectedEvent['city_name'] . ', ' . $selectedEvent['administrative_division']); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </div>
<?php endif; ?>

</body>
</html>