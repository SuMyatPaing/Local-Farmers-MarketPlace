<?php
require_once __DIR__ . '/security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Home Events Page
|--------------------------------------------------------------------------
| Save as:
| C:\xampp\htdocs\farmer_marketplace\events.php
|--------------------------------------------------------------------------
*/

if (!function_exists('event_page_e')) {
    function event_page_e($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('event_page_redirect')) {
    function event_page_redirect($location)
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('event_page_photo_url')) {
    function event_page_photo_url($path)
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

        return ltrim(
            str_replace('\\', '/', $path),
            '/'
        );
    }
}

if (!function_exists('event_page_query_url')) {
    function event_page_query_url($overrides)
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

        return 'events.php' .
            ($query !== '' ? '?' . $query : '');
    }
}

if (!function_exists('event_page_status')) {
    function event_page_status($startDate, $endDate)
    {
        $today = date('Y-m-d');

        if ($startDate > $today) {
            return 'upcoming';
        }

        if ($endDate < $today) {
            return 'past';
        }

        return 'ongoing';
    }
}

if (!function_exists('event_page_duration')) {
    function event_page_duration($startDate, $endDate)
    {
        $startTimestamp = strtotime((string) $startDate);
        $endTimestamp = strtotime((string) $endDate);

        if (
            $startTimestamp === false ||
            $endTimestamp === false ||
            $endTimestamp < $startTimestamp
        ) {
            return 0;
        }

        return (int) floor(
            ($endTimestamp - $startTimestamp) / 86400
        ) + 1;
    }
}

/*
|--------------------------------------------------------------------------
| Public Browsing
|--------------------------------------------------------------------------
| Guests may browse events and open Event Details without signing in.
|--------------------------------------------------------------------------
*/

$isLoggedIn = isset($_SESSION['user_id']) &&
    (int) $_SESSION['user_id'] > 0 &&
    isset($_SESSION['role']);

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

$pdo = null;

$databaseFiles = array(
    __DIR__ . '/config/database.php',
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
| Filters
|--------------------------------------------------------------------------
*/

$search = isset($_GET['q'])
    ? trim((string) $_GET['q'])
    : '';

$statusFilter = isset($_GET['status'])
    ? strtolower(trim((string) $_GET['status']))
    : 'all';

$marketId = isset($_GET['market_id'])
    ? max(0, (int) $_GET['market_id'])
    : 0;

$cityId = isset($_GET['city_id'])
    ? max(0, (int) $_GET['city_id'])
    : 0;

$sort = isset($_GET['sort'])
    ? strtolower(trim((string) $_GET['sort']))
    : 'soonest';

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$allowedStatuses = array(
    'all',
    'upcoming',
    'ongoing',
    'past'
);

if (!in_array(
    $statusFilter,
    $allowedStatuses,
    true
)) {
    $statusFilter = 'all';
}

$sortOptions = array(
    'soonest' => 'e.start_date ASC, e.event_id ASC',
    'latest' => 'e.start_date DESC, e.event_id DESC',
    'name' => 'e.event_name ASC'
);

if (!isset($sortOptions[$sort])) {
    $sort = 'soonest';
}

$orderSql = $sortOptions[$sort];
$perPage = 9;

$whereParts = array(
    "creator.role = 'admin'",
    "creator.status = 'active'"
);

$queryParameters = array();

if ($search !== '') {
    $whereParts[] = '(
        e.event_name LIKE :search_event OR
        e.description LIKE :search_description OR
        m.market_name LIKE :search_market OR
        m.address LIKE :search_address OR
        ci.city_name LIKE :search_city OR
        ci.administrative_division LIKE :search_division
    )';

    $searchValue = '%' . $search . '%';

    $queryParameters['search_event'] =
        $searchValue;

    $queryParameters['search_description'] =
        $searchValue;

    $queryParameters['search_market'] =
        $searchValue;

    $queryParameters['search_address'] =
        $searchValue;

    $queryParameters['search_city'] =
        $searchValue;

    $queryParameters['search_division'] =
        $searchValue;
}

if ($marketId > 0) {
    $whereParts[] = 'm.market_id = :market_id';
    $queryParameters['market_id'] = $marketId;
}

if ($cityId > 0) {
    $whereParts[] = 'ci.city_id = :city_id';
    $queryParameters['city_id'] = $cityId;
}

if ($statusFilter === 'upcoming') {
    $whereParts[] = 'e.start_date > CURDATE()';
} elseif ($statusFilter === 'ongoing') {
    $whereParts[] =
        'CURDATE() BETWEEN e.start_date AND e.end_date';
} elseif ($statusFilter === 'past') {
    $whereParts[] = 'e.end_date < CURDATE()';
}

$whereSql = implode(' AND ', $whereParts);

/*
|--------------------------------------------------------------------------
| Filter Options
|--------------------------------------------------------------------------
*/

$cityStatement = $pdo->query(
    "SELECT DISTINCT
        ci.city_id,
        ci.city_name,
        ci.administrative_division
     FROM events e
     INNER JOIN users creator
        ON creator.user_id = e.user_id
     INNER JOIN markets m
        ON m.market_id = e.market_id
     INNER JOIN cities ci
        ON ci.city_id = m.city_id
     WHERE creator.role = 'admin'
       AND creator.status = 'active'
     ORDER BY
        ci.city_name ASC,
        ci.administrative_division ASC"
);

$cities = $cityStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Event List
|--------------------------------------------------------------------------
*/

$countStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM events e
     INNER JOIN users creator
        ON creator.user_id = e.user_id
     INNER JOIN markets m
        ON m.market_id = e.market_id
     INNER JOIN cities ci
        ON ci.city_id = m.city_id
     WHERE {$whereSql}"
);

$countStatement->execute($queryParameters);

$totalFilteredEvents =
    (int) $countStatement->fetchColumn();

$totalPages = max(
    1,
    (int) ceil($totalFilteredEvents / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$listSql =
    "SELECT
        e.event_id,
        e.event_name,
        e.start_date,
        e.end_date,
        e.description,
        e.created_at,
        m.market_id,
        m.market_name,
        m.address AS market_address,
        m.opening_hour,
        m.closing_hour,
        ci.city_id,
        ci.city_name,
        ci.administrative_division,
        (
            SELECT mp.photo_path
            FROM market_photo mp
            WHERE mp.market_id = m.market_id
            ORDER BY mp.photo_id ASC
            LIMIT 1
        ) AS primary_photo
     FROM events e
     INNER JOIN users creator
        ON creator.user_id = e.user_id
     INNER JOIN markets m
        ON m.market_id = e.market_id
     INNER JOIN cities ci
        ON ci.city_id = m.city_id
     WHERE {$whereSql}
     ORDER BY {$orderSql}
     LIMIT :limit_value OFFSET :offset_value";

$listStatement = $pdo->prepare($listSql);

foreach ($queryParameters as $name => $value) {
    $listStatement->bindValue(
        ':' . $name,
        $value,
        is_int($value)
            ? PDO::PARAM_INT
            : PDO::PARAM_STR
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
$events = $listStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Selected Event Details
|--------------------------------------------------------------------------
*/

$selectedEvent = null;
$selectedMarketPhotos = array();

$viewEventId = isset($_GET['view'])
    ? max(0, (int) $_GET['view'])
    : 0;

if ($viewEventId > 0) {
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
            m.address AS market_address,
            m.opening_hour,
            m.closing_hour,
            m.description AS market_description,
            ci.city_name,
            ci.administrative_division
         FROM events e
         INNER JOIN users creator
            ON creator.user_id = e.user_id
         INNER JOIN markets m
            ON m.market_id = e.market_id
         INNER JOIN cities ci
            ON ci.city_id = m.city_id
         WHERE e.event_id = :event_id
           AND creator.role = 'admin'
           AND creator.status = 'active'
         LIMIT 1"
    );

    $detailStatement->execute(array(
        'event_id' => $viewEventId
    ));

    $selectedEvent = $detailStatement->fetch();

    if (!$selectedEvent) {
        event_page_redirect('events.php');
    }

    $photoStatement = $pdo->prepare(
        "SELECT
            photo_id,
            photo_name,
            photo_path,
            description
         FROM market_photo
         WHERE market_id = :market_id
         ORDER BY photo_id ASC
         LIMIT 6"
    );

    $photoStatement->execute(array(
        'market_id' =>
            (int) $selectedEvent['market_id']
    ));

    $selectedMarketPhotos =
        $photoStatement->fetchAll();
}

/*
|--------------------------------------------------------------------------
| Page Values
|--------------------------------------------------------------------------
*/

$showingFrom = $totalFilteredEvents > 0
    ? $offset + 1
    : 0;

$showingTo = min(
    $offset + $perPage,
    $totalFilteredEvents
);

$pageTitle = 'Events | Local Farmers Marketplace';

require __DIR__ . '/header.php';
?>

<main class="min-h-screen bg-[#f7f8f3]">

<style>
    /* Events page only — keep Search + Clear Filters in one row on desktop */
    @media (min-width: 1024px) {
        body.market-public-view:not(.fm-compact-nav).market-page-events
        .events-filter-row {
            display: grid !important;
            grid-template-columns:
                minmax(0, 1.45fr)
                minmax(150px, 0.9fr)
                minmax(150px, 0.9fr)
                140px
                140px !important;
            gap: 12px !important;
            align-items: center !important;
            max-width: 1180px !important;
        }

        body.market-public-view:not(.fm-compact-nav).market-page-events
        .events-filter-row > * {
            min-width: 0 !important;
            width: 100% !important;
        }

        body.market-public-view:not(.fm-compact-nav).market-page-events
        .events-filter-row button[type="submit"],
        body.market-public-view:not(.fm-compact-nav).market-page-events
        .events-filter-row .events-clear-filter {
            white-space: nowrap !important;
        }
    }

    /* At high zoom / compact mode, allow stacking again. */
    body.market-public-view.fm-compact-nav.market-page-events
    .events-filter-row {
        grid-template-columns: 1fr !important;
    }
</style>


    <!-- Hero -->
    <section class="relative overflow-hidden border-b border-green-100 bg-gradient-to-br from-green-50 via-white to-amber-50">

        <div class="pointer-events-none absolute -left-32 top-8 h-80 w-80 rounded-full bg-green-200/30 blur-3xl"></div>
        <div class="pointer-events-none absolute -right-32 bottom-0 h-80 w-80 rounded-full bg-amber-200/30 blur-3xl"></div>

        <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-16">

            <div class="mx-auto max-w-6xl text-center">

                <span class="inline-flex items-center gap-2 rounded-full border border-green-200 bg-white px-3 py-2 text-xs font-bold text-green-700 shadow-sm">
                    <i class="fa-solid fa-calendar-days"></i>
                    Local Market Events
                </span>

                <h1 class="mt-5 text-4xl font-black tracking-tight text-[#0f2414] sm:text-5xl">
                    Discover upcoming
                    <span class="text-green-600">events.</span>
                </h1>

                <p class="mx-auto mt-4 max-w-2xl text-sm leading-7 text-slate-500 sm:text-base">
                    View festivals, seasonal activities and community events created by the administrator for local markets.
                </p>

                <!-- Event Search and Filters -->
                <form method="get"
                      action="events.php"
                      class="events-filter-row mx-auto mt-8 grid w-full max-w-[1180px] gap-3 rounded-2xl border border-slate-200 bg-white p-3 text-left shadow-card lg:grid-cols-[minmax(0,1.45fr)_minmax(150px,0.9fr)_minmax(150px,0.9fr)_140px_140px]">

                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                        <input type="search"
                               name="q"
                               value="<?php echo event_page_e($search); ?>"
                               placeholder="Search event, market or city..."
                               class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                    </div>

                    <select name="city_id"
                            class="h-12 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-600 outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                        <option value="0">All cities</option>

                        <?php foreach ($cities as $city): ?>
                            <option value="<?php echo (int) $city['city_id']; ?>"
                                <?php echo $cityId === (int) $city['city_id'] ? 'selected' : ''; ?>>

                                <?php echo event_page_e($city['city_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status"
                            class="h-12 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-600 outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                        <option value="all"
                            <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>
                            All status
                        </option>

                        <option value="upcoming"
                            <?php echo $statusFilter === 'upcoming' ? 'selected' : ''; ?>>
                            Upcoming
                        </option>

                        <option value="ongoing"
                            <?php echo $statusFilter === 'ongoing' ? 'selected' : ''; ?>>
                            Ongoing
                        </option>

                       
                    </select>

                    <button type="submit"
                            class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-green-600 px-6 text-sm font-bold text-white transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-200">

                        <i class="fa-solid fa-magnifying-glass"></i>
                        Search
                    </button>

                    <?php if ($search !== '' || $cityId > 0 || $statusFilter !== 'all'): ?>
                        <a href="events.php"
                           class="events-clear-filter inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-600 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700 focus:outline-none focus:ring-2 focus:ring-green-100">
                            <i class="fa-solid fa-rotate-left text-xs"></i>
                            Clear Filters
                        </a>
                    <?php else: ?>
                        <span class="events-clear-filter inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-slate-100 bg-slate-50 px-4 text-sm font-bold text-slate-300 cursor-not-allowed select-none"
                              aria-disabled="true">
                            <i class="fa-solid fa-rotate-left text-xs"></i>
                            Clear Filters
                        </span>
                    <?php endif; ?>
                </form>

            </div>
        </div>
    </section>
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

        <!-- Event List -->
        <section class="mt-7">

            <div class="mb-4">

                <h2 class="text-xl font-black text-slate-950">
                    Event List
                </h2>

                <p class="mt-1 text-xs text-slate-400">

                    Showing
                    <?php echo number_format($showingFrom); ?>
                    –
                    <?php echo number_format($showingTo); ?>
                    of
                    <?php echo number_format($totalFilteredEvents); ?>
                    events
                </p>
            </div>

            <?php if (empty($events)): ?>

                <div class="rounded-2xl border border-slate-200 bg-white px-5 py-20 text-center shadow-soft">

                    <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-green-50 text-green-600">

                        <i class="fa-regular fa-calendar-xmark text-2xl"></i>
                    </span>

                    <p class="mt-5 text-sm font-bold text-slate-700">
                        No events found
                    </p>

                    <p class="mt-2 text-xs text-slate-400">
                        Change the current search or filter options.
                    </p>
                </div>

            <?php else: ?>

                <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">

                    <?php foreach ($events as $event): ?>
                        <?php
                        $eventStatus = event_page_status(
                            $event['start_date'],
                            $event['end_date']
                        );

                        $duration = event_page_duration(
                            $event['start_date'],
                            $event['end_date']
                        );

                        $photoUrl = event_page_photo_url(
                            $event['primary_photo']
                        );

                        if ($eventStatus === 'ongoing') {
                            $statusClass =
                                'bg-green-50 text-green-700 ring-green-200';

                            $statusIcon =
                                'fa-solid fa-circle-play';
                        } elseif ($eventStatus === 'upcoming') {
                            $statusClass =
                                'bg-blue-50 text-blue-700 ring-blue-200';

                            $statusIcon =
                                'fa-regular fa-clock';
                        } else {
                            $statusClass =
                                'bg-slate-100 text-slate-600 ring-slate-200';

                            $statusIcon =
                                'fa-solid fa-clock-rotate-left';
                        }
                        ?>

                        <article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft transition hover:-translate-y-0.5 hover:border-green-200 hover:shadow-lg">

                            <a href="<?php echo event_page_e(
                                event_page_query_url(array(
                                    'view' => (int) $event['event_id']
                                ))
                            ); ?>"
                               class="relative block h-44 overflow-hidden bg-gradient-to-br from-green-50 to-slate-100">

                                <?php if ($photoUrl !== ''): ?>

                                    <img src="<?php echo event_page_e($photoUrl); ?>"
                                         alt="<?php echo event_page_e($event['market_name']); ?>"
                                         class="h-full w-full object-cover transition duration-300 group-hover:scale-105">

                                <?php else: ?>

                                    <span class="grid h-full place-items-center text-green-300">

                                        <i class="fa-solid fa-calendar-days text-5xl"></i>
                                    </span>

                                <?php endif; ?>

                                <div class="absolute inset-0 bg-gradient-to-t from-slate-950/60 via-transparent to-transparent"></div>

                                <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold capitalize ring-1 ring-inset <?php echo event_page_e($statusClass); ?>">

                                    <i class="<?php echo event_page_e($statusIcon); ?> text-[8px]"></i>

                                    <?php echo event_page_e($eventStatus); ?>
                                </span>

                                <div class="absolute inset-x-0 bottom-0 p-4 text-white">

                                    <p class="truncate text-xs font-semibold text-white/75">

                                        <?php echo event_page_e(
                                            $event['market_name']
                                        ); ?>
                                    </p>

                                    <h3 class="mt-1 line-clamp-1 text-lg font-black">

                                        <?php echo event_page_e(
                                            $event['event_name']
                                        ); ?>
                                    </h3>
                                </div>
                            </a>

                            <div class="p-4">

                                <div class="grid grid-cols-[64px_1fr] gap-4">

                                    <div class="rounded-xl bg-green-50 p-3 text-center">

                                        <p class="text-2xl font-black leading-none text-green-700">

                                            <?php echo event_page_e(
                                                date(
                                                    'd',
                                                    strtotime(
                                                        $event['start_date']
                                                    )
                                                )
                                            ); ?>
                                        </p>

                                        <p class="mt-1 text-[10px] font-black uppercase tracking-widest text-green-600">

                                            <?php echo event_page_e(
                                                date(
                                                    'M',
                                                    strtotime(
                                                        $event['start_date']
                                                    )
                                                )
                                            ); ?>
                                        </p>
                                    </div>

                                    <div>

                                        <p class="text-sm font-bold text-slate-800">

                                            <?php echo event_page_e(
                                                date(
                                                    'M d, Y',
                                                    strtotime(
                                                        $event['start_date']
                                                    )
                                                ) .
                                                ' → ' .
                                                date(
                                                    'M d, Y',
                                                    strtotime(
                                                        $event['end_date']
                                                    )
                                                )
                                            ); ?>
                                        </p>

                                        <p class="mt-1 text-xs text-slate-400">

                                            <?php echo number_format($duration); ?>

                                            <?php echo $duration === 1
                                                ? 'day'
                                                : 'days'; ?>
                                        </p>
                                    </div>
                                </div>

                                <p class="mt-4 line-clamp-2 min-h-10 text-xs leading-5 text-slate-500">

                                    <?php echo event_page_e(
                                        $event['description']
                                    ); ?>
                                </p>

                                <div class="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-4">

                                    <p class="min-w-0 truncate text-xs text-slate-400">

                                        <i class="fa-solid fa-location-dot mr-1 text-green-600"></i>

                                        <?php echo event_page_e(
                                            $event['city_name']
                                        ); ?>
                                    </p>

                                    <a href="<?php echo event_page_e(
                                        event_page_query_url(array(
                                            'view' => (int) $event['event_id']
                                        ))
                                    ); ?>"
                                       class="inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-xs font-bold text-white hover:bg-green-700">

                                        Details

                                        <i class="fa-solid fa-arrow-right text-[8px]"></i>
                                    </a>
                                </div>
                            </div>
                        </article>

                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

            <?php if ($totalPages > 1): ?>

                <div class="mt-7 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-soft sm:flex-row sm:items-center sm:justify-between">

                    <p class="text-xs text-slate-400">

                        Page
                        <?php echo number_format($page); ?>
                        of
                        <?php echo number_format($totalPages); ?>
                    </p>

                    <div class="flex flex-wrap items-center gap-1">

                        <a href="<?php echo event_page_e(
                            event_page_query_url(array(
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

                            <a href="<?php echo event_page_e(
                                event_page_query_url(array(
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

                        <a href="<?php echo event_page_e(
                            event_page_query_url(array(
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
    </div>
</main>

<!-- Event Details Modal -->
<?php if ($selectedEvent): ?>
    <?php
    $selectedStatus = event_page_status(
        $selectedEvent['start_date'],
        $selectedEvent['end_date']
    );

    $selectedDuration = event_page_duration(
        $selectedEvent['start_date'],
        $selectedEvent['end_date']
    );
    ?>

    <div class="fixed inset-0 z-[80] overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm">

        <div class="mx-auto flex min-h-full max-w-4xl items-center justify-center">

            <div class="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">

                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">

                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-green-600">
                            Event Details
                        </p>

                        <h2 class="mt-1 text-xl font-black text-slate-950">

                            <?php echo event_page_e(
                                $selectedEvent['event_name']
                            ); ?>
                        </h2>
                    </div>

                    <a href="<?php echo event_page_e(
                        event_page_query_url(array(
                            'view' => null
                        ))
                    ); ?>"
                       title="Close"
                       class="grid h-10 w-10 place-items-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700">

                        <i class="fa-solid fa-xmark"></i>
                    </a>
                </div>

                <div class="max-h-[78vh] overflow-y-auto p-5 sm:p-6">

                    <?php if (!empty($selectedMarketPhotos)): ?>

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">

                            <?php foreach ($selectedMarketPhotos as $photo): ?>
                                <?php
                                $detailPhotoUrl =
                                    event_page_photo_url(
                                        $photo['photo_path']
                                    );
                                ?>

                                <?php if ($detailPhotoUrl !== ''): ?>

                                    <img src="<?php echo event_page_e($detailPhotoUrl); ?>"
                                         alt="<?php echo event_page_e(
                                             $selectedEvent['market_name']
                                         ); ?>"
                                         class="h-40 w-full rounded-xl object-cover">

                                <?php endif; ?>

                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>

                        <div class="grid h-48 place-items-center rounded-2xl bg-green-50 text-green-300">

                            <i class="fa-solid fa-calendar-days text-6xl"></i>
                        </div>

                    <?php endif; ?>

                    <div class="mt-6 flex flex-wrap items-center gap-2">

                        <span class="rounded-full bg-green-50 px-3 py-1.5 text-xs font-bold capitalize text-green-700">

                            <?php echo event_page_e($selectedStatus); ?>
                        </span>

                        <span class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700">

                            <?php echo number_format($selectedDuration); ?>

                            <?php echo $selectedDuration === 1
                                ? 'day'
                                : 'days'; ?>
                        </span>
                    </div>

                    <p class="mt-5 text-sm leading-7 text-slate-600">

                        <?php echo nl2br(
                            event_page_e(
                                $selectedEvent['description']
                            )
                        ); ?>
                    </p>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">

                        <section class="rounded-2xl border border-slate-200 p-4">

                            <h3 class="text-sm font-black text-slate-900">
                                Schedule
                            </h3>

                            <div class="mt-4 space-y-3 text-xs leading-6 text-slate-500">

                                <p class="flex gap-3">

                                    <i class="fa-regular fa-calendar mt-1 w-4 text-center text-green-600"></i>

                                    <span>

                                        <?php echo event_page_e(
                                            date(
                                                'F d, Y',
                                                strtotime(
                                                    $selectedEvent['start_date']
                                                )
                                            ) .
                                            ' → ' .
                                            date(
                                                'F d, Y',
                                                strtotime(
                                                    $selectedEvent['end_date']
                                                )
                                            )
                                        ); ?>
                                    </span>
                                </p>

                                <p class="flex gap-3">

                                    <i class="fa-regular fa-clock mt-1 w-4 text-center text-green-600"></i>

                                    <span>

                                        <?php echo event_page_e(
                                            date(
                                                'g:i A',
                                                strtotime(
                                                    $selectedEvent['opening_hour']
                                                )
                                            ) .
                                            ' – ' .
                                            date(
                                                'g:i A',
                                                strtotime(
                                                    $selectedEvent['closing_hour']
                                                )
                                            )
                                        ); ?>
                                    </span>
                                </p>
                            </div>
                        </section>

                        <section class="rounded-2xl border border-slate-200 p-4">

                            <h3 class="text-sm font-black text-slate-900">
                                Market Location
                            </h3>

                            <div class="mt-4 space-y-3 text-xs leading-6 text-slate-500">

                                <p class="font-bold text-slate-800">

                                    <?php echo event_page_e(
                                        $selectedEvent['market_name']
                                    ); ?>
                                </p>

                                <p class="flex gap-3">

                                    <i class="fa-solid fa-location-dot mt-1 w-4 text-center text-green-600"></i>

                                    <span>

                                        <?php echo event_page_e(
                                            $selectedEvent['market_address'] .
                                            ', ' .
                                            $selectedEvent['city_name'] .
                                            (
                                                trim(
                                                    (string) $selectedEvent['administrative_division']
                                                ) !== ''
                                                    ? ', ' .
                                                        $selectedEvent['administrative_division']
                                                    : ''
                                            )
                                        ); ?>
                                    </span>
                                </p>
                            </div>
                        </section>
                    </div>

                    <?php if (
                        trim(
                            (string) $selectedEvent['market_description']
                        ) !== ''
                    ): ?>

                        <section class="mt-5 rounded-2xl bg-slate-50 p-4">

                            <h3 class="text-sm font-black text-slate-900">
                                About the Market
                            </h3>

                            <p class="mt-3 text-xs leading-6 text-slate-500">

                                <?php echo event_page_e(
                                    $selectedEvent['market_description']
                                ); ?>
                            </p>
                        </section>

                    <?php endif; ?>
                </div>

                <div class="flex justify-end border-t border-slate-100 px-5 py-4">

                    <a href="<?php echo event_page_e(
                        event_page_query_url(array(
                            'view' => null
                        ))
                    ); ?>"
                       class="rounded-xl bg-green-600 px-5 py-2.5 text-xs font-bold text-white hover:bg-green-700">

                        Close
                    </a>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>