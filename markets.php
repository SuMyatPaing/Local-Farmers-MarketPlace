<?php
require_once __DIR__ . '/security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Public Markets Page
|--------------------------------------------------------------------------
| Save as:
| C:\xampp\htdocs\farmer_marketplace\markets.php
|--------------------------------------------------------------------------
*/

if (!function_exists('market_page_e')) {
    function market_page_e($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('market_page_redirect')) {
    function market_page_redirect($location)
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('market_page_photo_url')) {
    function market_page_photo_url($path)
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

if (!function_exists('market_page_query_url')) {
    function market_page_query_url($overrides)
    {
        $parameters = $_GET;

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($parameters[$key]);
            } else {
                $parameters[$key] = $value;
            }
        }

        $query = http_build_query($parameters);

        return 'markets.php' .
            ($query !== '' ? '?' . $query : '');
    }
}

if (!function_exists('market_page_initial')) {
    function market_page_initial($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'U';
        }

        return strtoupper(substr($name, 0, 1));
    }
}

/*
|--------------------------------------------------------------------------
| Public Browsing
|--------------------------------------------------------------------------
| Guests may browse markets and open Market Details.
| Sign In is required only when they continue to a purchase action.
|--------------------------------------------------------------------------
*/

$isLoggedIn = isset($_SESSION['user_id']) &&
    (int) $_SESSION['user_id'] > 0 &&
    isset($_SESSION['role']);

$role = $isLoggedIn
    ? strtolower((string) $_SESSION['role'])
    : '';
$userName = isset($_SESSION['user_name'])
    ? (string) $_SESSION['user_name']
    : 'Account';
$userInitial = market_page_initial($userName);

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
| Search, City Filter and Pagination
|--------------------------------------------------------------------------
*/

$search = isset($_GET['q'])
    ? trim((string) $_GET['q'])
    : '';

$cityId = isset($_GET['city_id'])
    ? max(0, (int) $_GET['city_id'])
    : 0;

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 9;

$whereParts = array('1 = 1');
$queryParameters = array();

if ($search !== '') {
    $whereParts[] = '(
        m.market_name LIKE :search_market OR
        m.address LIKE :search_address OR
        m.description LIKE :search_description OR
        ci.city_name LIKE :search_city OR
        ci.administrative_division LIKE :search_division
    )';

    $searchValue = '%' . $search . '%';

    $queryParameters['search_market'] =
        $searchValue;

    $queryParameters['search_address'] =
        $searchValue;

    $queryParameters['search_description'] =
        $searchValue;

    $queryParameters['search_city'] =
        $searchValue;

    $queryParameters['search_division'] =
        $searchValue;
}

if ($cityId > 0) {
    $whereParts[] = 'm.city_id = :city_id';
    $queryParameters['city_id'] = $cityId;
}

$whereSql = implode(' AND ', $whereParts);

/*
|--------------------------------------------------------------------------
| Cities
|--------------------------------------------------------------------------
*/

$cityStatement = $pdo->query(
    "SELECT
        ci.city_id,
        ci.city_name,
        ci.administrative_division,
        COUNT(m.market_id) AS market_count
     FROM cities ci
     LEFT JOIN markets m
        ON m.city_id = ci.city_id
     GROUP BY
        ci.city_id,
        ci.city_name,
        ci.administrative_division
     HAVING COUNT(m.market_id) > 0
     ORDER BY ci.city_name ASC"
);

$cities = $cityStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Market List
|--------------------------------------------------------------------------
*/

$countStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM markets m
     INNER JOIN cities ci
        ON ci.city_id = m.city_id
     WHERE {$whereSql}"
);

$countStatement->execute($queryParameters);
$totalFilteredMarkets =
    (int) $countStatement->fetchColumn();

$totalPages = max(
    1,
    (int) ceil($totalFilteredMarkets / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$listSql =
    "SELECT
        m.market_id,
        m.market_name,
        m.address,
        m.opening_hour,
        m.closing_hour,
        m.description,
        m.created_at,
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
            FROM categories c
            WHERE c.market_id = m.market_id
        ) AS category_count,
        (
            SELECT COUNT(*)
            FROM vendors v
            INNER JOIN users vendor_user
                ON vendor_user.user_id = v.user_id
            WHERE v.status = 'accepted'
              AND vendor_user.status = 'active'
              AND (
                    EXISTS (
                        SELECT 1
                        FROM vendor_markets vm
                        WHERE vm.vendor_id = v.vendor_id
                          AND vm.market_id = m.market_id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM products vp
                        INNER JOIN categories vc
                            ON vc.category_id = vp.category_id
                        WHERE vp.vendor_id = v.vendor_id
                          AND vc.market_id = m.market_id
                    )
              )
        ) AS vendor_count,
        (
            SELECT COUNT(*)
            FROM products p
            INNER JOIN categories pc
                ON pc.category_id = p.category_id
            WHERE pc.market_id = m.market_id
        ) AS product_count,
        (
            SELECT COUNT(*)
            FROM events e
            WHERE e.market_id = m.market_id
              AND e.end_date >= CURDATE()
        ) AS event_count
     FROM markets m
     INNER JOIN cities ci
        ON ci.city_id = m.city_id
     WHERE {$whereSql}
     ORDER BY m.created_at DESC, m.market_name ASC
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
$markets = $listStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Market Detail Modal
|--------------------------------------------------------------------------
*/

$selectedMarket = null;
$selectedPhotos = array();
$selectedCategories = array();
$selectedEvents = array();

$viewMarketId = isset($_GET['view'])
    ? max(0, (int) $_GET['view'])
    : 0;

if ($viewMarketId > 0) {
    $detailStatement = $pdo->prepare(
        "SELECT
            m.market_id,
            m.market_name,
            m.address,
            m.opening_hour,
            m.closing_hour,
            m.description,
            ci.city_name,
            ci.administrative_division,
            (
                SELECT COUNT(*)
                FROM vendors v
                INNER JOIN users vendor_user
                    ON vendor_user.user_id = v.user_id
                WHERE v.status = 'accepted'
                  AND vendor_user.status = 'active'
                  AND (
                        EXISTS (
                            SELECT 1
                            FROM vendor_markets vm
                            WHERE vm.vendor_id = v.vendor_id
                              AND vm.market_id = m.market_id
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM products vp
                            INNER JOIN categories vc
                                ON vc.category_id = vp.category_id
                            WHERE vp.vendor_id = v.vendor_id
                              AND vc.market_id = m.market_id
                        )
                  )
            ) AS vendor_count,
            (
                SELECT COUNT(*)
                FROM products p
                INNER JOIN categories c
                    ON c.category_id = p.category_id
                WHERE c.market_id = m.market_id
            ) AS product_count
         FROM markets m
         INNER JOIN cities ci
            ON ci.city_id = m.city_id
         WHERE m.market_id = :market_id
         LIMIT 1"
    );

    $detailStatement->execute(array(
        'market_id' => $viewMarketId
    ));

    $selectedMarket = $detailStatement->fetch();

    if (!$selectedMarket) {
        market_page_redirect('markets.php');
    }

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
            category_id,
            category_name,
            description
         FROM categories
         WHERE market_id = :market_id
         ORDER BY category_name ASC"
    );

    $categoryStatement->execute(array(
        'market_id' => $viewMarketId
    ));

    $selectedCategories =
        $categoryStatement->fetchAll();

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

$showingFrom = $totalFilteredMarkets > 0
    ? $offset + 1
    : 0;

$showingTo = min(
    $offset + $perPage,
    $totalFilteredMarkets
);
?>
<?php
$pageTitle = 'Markets | Local Farmers Marketplace';
require __DIR__ . '/header.php';
?>

<main>

<style>
    /* Markets page filter layout */
    .markets-filter-row {
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;
        align-items: center;
    }

    @media (min-width: 1024px) {
        .markets-filter-row {
            grid-template-columns:
                minmax(0, 1fr)
                260px
                140px
                150px !important;
        }

        .markets-filter-row > * {
            min-width: 0;
            width: 100%;
        }
    }
</style>

    <!-- Hero -->
    <section class="relative overflow-hidden bg-gradient-to-br from-green-50 via-white to-amber-50">

        <div class="pointer-events-none absolute -left-32 top-8 h-80 w-80 rounded-full bg-green-200/30 blur-3xl"></div>
        <div class="pointer-events-none absolute -right-32 bottom-0 h-80 w-80 rounded-full bg-amber-200/30 blur-3xl"></div>

        <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-18">

            <div class="mx-auto max-w-3xl text-center">

                <span class="inline-flex items-center gap-2 rounded-full border border-green-200 bg-white px-3 py-2 text-xs font-bold text-green-700 shadow-sm">
                    <i class="fa-solid fa-store"></i>
                    Local Market Directory
                </span>

                <h1 class="mt-5 text-4xl font-black tracking-tight text-[#0f2414] sm:text-5xl">
                    Discover local
                    <span class="text-green-600">farmers markets.</span>
                </h1>

                <p class="mx-auto mt-4 max-w-2xl text-sm leading-7 text-slate-500 sm:text-base">
                    Browse nearby markets, view available categories, accepted Vendors, products and upcoming events.
                </p>
            </div>

            <form method="get"
                  action="markets.php"
                  class="markets-filter-row mx-auto mt-8 w-full max-w-5xl rounded-2xl border border-slate-200 bg-white p-3 shadow-card">

                <!-- Search -->
                <div class="relative min-w-0">
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                    <input type="search"
                           name="q"
                           value="<?php echo market_page_e($search); ?>"
                           placeholder="Search market, city or address..."
                           class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                </div>

                <!-- City Filter -->
                <select name="city_id"
                        class="h-12 min-w-0 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-600 outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                    <option value="0">All cities</option>

                    <?php foreach ($cities as $city): ?>
                        <option value="<?php echo (int) $city['city_id']; ?>"
                            <?php echo $cityId === (int) $city['city_id'] ? 'selected' : ''; ?>>

                           <?php echo market_page_e($city['city_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Search Button -->
                <button type="submit"
                        class="inline-flex h-12 w-full items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-green-600 px-5 text-sm font-bold text-white transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-200">

                    <i class="fa-solid fa-magnifying-glass"></i>
                    Search
                </button>

                <!-- Clear Filters -->
                <?php if ($search !== '' || $cityId > 0): ?>
                    <a href="markets.php"
                       class="inline-flex h-12 w-full items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-600 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700 focus:outline-none focus:ring-2 focus:ring-green-100">

                        <i class="fa-solid fa-rotate-left text-xs"></i>
                        Clear Filters
                    </a>
                <?php else: ?>
                    <span class="inline-flex h-12 w-full cursor-not-allowed select-none items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-slate-100 bg-slate-50 px-4 text-sm font-bold text-slate-300"
                          aria-disabled="true">

                        <i class="fa-solid fa-rotate-left text-xs"></i>
                        Clear Filters
                    </span>
                <?php endif; ?>
            </form>
        </div>
    </section>

    <!-- Market Cards -->
    <section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

        <div>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-green-600">Market Directory</p>
            <h2 class="mt-1 text-2xl font-black text-slate-950">Available Markets</h2>
            <p class="mt-1 text-sm text-slate-400">Showing <?php echo number_format($showingFrom); ?>–<?php echo number_format($showingTo); ?> of <?php echo number_format($totalFilteredMarkets); ?> markets</p>
        </div>

        <?php if (empty($markets)): ?>

            <div class="mt-6 rounded-2xl border border-slate-200 bg-white px-5 py-20 text-center shadow-card">
                <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-green-50 text-green-600"><i class="fa-solid fa-store-slash text-2xl"></i></span>
                <h3 class="mt-5 text-base font-black text-slate-800">No markets found</h3>
                <p class="mt-2 text-sm text-slate-400">Try a different market name, city or address.</p>
            </div>

        <?php else: ?>

            <div class="mt-6 grid gap-6 md:grid-cols-2 xl:grid-cols-3">

                <?php foreach ($markets as $market): ?>
                    <?php $photoUrl = market_page_photo_url($market['primary_photo']); ?>

                    <article class="group overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-card transition duration-300 hover:-translate-y-1 hover:border-green-200 hover:shadow-xl">

                        <!-- Market Image -->
                        <div class="relative h-72 overflow-hidden bg-gradient-to-br from-green-50 to-slate-100">

                            <?php if ($photoUrl !== ''): ?>

                                <img src="<?php echo market_page_e($photoUrl); ?>"
                                     alt="<?php echo market_page_e($market['market_name']); ?>"
                                     class="h-full w-full object-cover transition duration-500 group-hover:scale-105">

                            <?php else: ?>

                                <div class="grid h-full place-items-center bg-green-50 text-green-300">
                                    <i class="fa-solid fa-store text-6xl"></i>
                                </div>

                            <?php endif; ?>

                            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/85 via-slate-950/10 to-transparent"></div>

                            <!-- City Badge -->
                            <span class="absolute left-5 top-5 inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-xs font-black text-green-700 shadow-lg">

                                <i class="fa-solid fa-location-dot"></i>

                                <?php echo market_page_e(
                                    $market['city_name']
                                ); ?>
                            </span>

                            <!-- Market Name and Opening Time -->
                            <div class="absolute inset-x-0 bottom-0 p-6 text-white">

                                <h3 class="truncate text-2xl font-black tracking-tight">

                                    <?php echo market_page_e(
                                        $market['market_name']
                                    ); ?>
                                </h3>

                                <p class="mt-2 flex items-center gap-2 text-sm font-medium text-white/90">

                                    <i class="fa-regular fa-clock"></i>

                                    <?php echo market_page_e(
                                        date(
                                            'g:i A',
                                            strtotime(
                                                $market['opening_hour']
                                            )
                                        ) .
                                        ' – ' .
                                        date(
                                            'g:i A',
                                            strtotime(
                                                $market['closing_hour']
                                            )
                                        )
                                    ); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Market Information -->
                        <div class="p-6">

                            <div class="grid grid-cols-2 gap-4">

                                <!-- Vendors -->
                                <div class="rounded-2xl bg-green-50 p-4">

                                    <p class="text-2xl font-black text-green-700">

                                        <?php echo number_format(
                                            (int) $market['vendor_count']
                                        ); ?>
                                    </p>

                                    <p class="mt-2 text-xs font-semibold text-green-700/75">
                                        Vendors
                                    </p>
                                </div>

                                <!-- Categories -->
                                <div class="rounded-2xl bg-amber-50 p-4">

                                    <p class="text-2xl font-black text-amber-700">

                                        <?php echo number_format(
                                            (int) $market['category_count']
                                        ); ?>
                                    </p>

                                    <p class="mt-2 text-xs font-semibold text-amber-700/75">
                                        Categories
                                    </p>
                                </div>
                            </div>

                            <a href="<?php echo market_page_e(
                                market_page_query_url(array(
                                    'view' => (int) $market['market_id']
                                ))
                            ); ?>"
                               class="mt-5 inline-flex w-full items-center justify-center gap-3 rounded-2xl border border-green-300 bg-white px-5 py-4 text-sm font-black text-green-700 transition hover:bg-green-600 hover:text-white">

                                Explore Market

                                <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

        <?php if ($totalPages > 1): ?>

            <div class="mt-8 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-card sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-slate-400">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></p>

                <div class="flex flex-wrap items-center gap-1">
                    <a href="<?php echo market_page_e(market_page_query_url(array('page' => max(1, $page - 1)))); ?>" class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50 <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : ''; ?>"><i class="fa-solid fa-chevron-left text-[9px]"></i>Previous</a>

                    <?php for ($pageNumber = max(1, $page - 2); $pageNumber <= min($totalPages, $page + 2); $pageNumber++): ?>
                        <a href="<?php echo market_page_e(market_page_query_url(array('page' => $pageNumber))); ?>" class="grid h-9 w-9 place-items-center rounded-lg text-xs font-bold <?php echo $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50'; ?>"><?php echo $pageNumber; ?></a>
                    <?php endfor; ?>

                    <a href="<?php echo market_page_e(market_page_query_url(array('page' => min($totalPages, $page + 1)))); ?>" class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50 <?php echo $page >= $totalPages ? 'pointer-events-none opacity-40' : ''; ?>">Next<i class="fa-solid fa-chevron-right text-[9px]"></i></a>
                </div>
            </div>

        <?php endif; ?>
    </section>
</main>

<!-- Market Detail Modal -->
<?php if ($selectedMarket): ?>

    <div class="fixed inset-0 z-[70] overflow-y-auto bg-slate-950/60 p-3 backdrop-blur-sm sm:p-4">

        <div class="mx-auto flex min-h-full max-w-4xl items-center justify-center">

            <div class="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">

                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3.5 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-green-600">
                            Market Details
                        </p>

                        <h2 class="mt-1 truncate text-lg font-black text-slate-950 sm:text-xl">
                            <?php echo market_page_e($selectedMarket['market_name']); ?>
                        </h2>
                    </div>

                    <a href="<?php echo market_page_e(
                        market_page_query_url(array('view' => null))
                    ); ?>"
                       title="Close"
                       class="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">

                        <i class="fa-solid fa-xmark"></i>
                    </a>
                </div>

                <!-- Modal Body -->
                <div class="max-h-[72vh] overflow-y-auto p-4 sm:p-5">

                    <!-- Photo + Market Information -->
                    <div class="grid gap-4 lg:grid-cols-[0.9fr_1.1fr]">

                        <!-- Market Photos -->
                        <section>
                            <?php if (!empty($selectedPhotos)): ?>

                                <?php
                                $mainMarketPhoto = market_page_photo_url(
                                    $selectedPhotos[0]['photo_path']
                                );
                                ?>

                                <?php if ($mainMarketPhoto !== ''): ?>
                                    <img
                                        id="marketDetailMainImage"
                                        src="<?php echo market_page_e($mainMarketPhoto); ?>"
                                        alt="<?php echo market_page_e($selectedPhotos[0]['photo_name']); ?>"
                                        class="h-56 w-full rounded-2xl border border-slate-200 object-cover sm:h-64"
                                    >
                                <?php endif; ?>

                                <?php if (count($selectedPhotos) > 1): ?>
                                    <div class="mt-2 grid grid-cols-4 gap-2">
                                        <?php foreach ($selectedPhotos as $photo): ?>
                                            <?php
                                            $detailPhotoUrl = market_page_photo_url(
                                                $photo['photo_path']
                                            );
                                            ?>

                                            <?php if ($detailPhotoUrl !== ''): ?>
                                                <button
                                                    type="button"
                                                    data-market-detail-image="<?php echo market_page_e($detailPhotoUrl); ?>"
                                                    class="overflow-hidden rounded-lg border border-slate-200 transition hover:border-green-500"
                                                >
                                                    <img
                                                        src="<?php echo market_page_e($detailPhotoUrl); ?>"
                                                        alt="<?php echo market_page_e($photo['photo_name']); ?>"
                                                        class="h-14 w-full object-cover"
                                                    >
                                                </button>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                            <?php else: ?>

                                <div class="grid h-56 place-items-center rounded-2xl bg-green-50 text-green-300 sm:h-64">
                                    <i class="fa-solid fa-image text-5xl"></i>
                                </div>

                            <?php endif; ?>
                        </section>

                        <!-- Market Information -->
                        <section class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 sm:p-5">

                            <div class="flex items-center gap-2">
                                <span class="grid h-8 w-8 place-items-center rounded-lg bg-green-100 text-green-700">
                                    <i class="fa-solid fa-store text-xs"></i>
                                </span>

                                <h3 class="text-sm font-black text-slate-900">
                                    Market Information
                                </h3>
                            </div>

                            <div class="mt-4 space-y-3 text-xs leading-6 text-slate-500">

                                <p class="flex gap-3">
                                    <i class="fa-solid fa-location-dot mt-1 w-4 shrink-0 text-center text-green-600"></i>

                                    <span>
                                        <?php echo market_page_e(
                                            $selectedMarket['address'] .
                                            ', ' .
                                            $selectedMarket['city_name'] .
                                            ', ' .
                                            $selectedMarket['administrative_division']
                                        ); ?>
                                    </span>
                                </p>

                                <p class="flex gap-3">
                                    <i class="fa-regular fa-clock mt-1 w-4 shrink-0 text-center text-green-600"></i>

                                    <span>
                                        <?php echo market_page_e(
                                            date(
                                                'g:i A',
                                                strtotime($selectedMarket['opening_hour'])
                                            ) .
                                            ' – ' .
                                            date(
                                                'g:i A',
                                                strtotime($selectedMarket['closing_hour'])
                                            )
                                        ); ?>
                                    </span>
                                </p>

                                <div class="grid grid-cols-2 gap-2 pt-1">

                                    <div class="rounded-xl border border-green-100 bg-white p-3">
                                        <p class="text-lg font-black text-green-700">
                                            <?php echo number_format(
                                                (int) $selectedMarket['vendor_count']
                                            ); ?>
                                        </p>

                                        <p class="mt-0.5 text-[10px] font-semibold text-slate-400">
                                            Accepted Vendors
                                        </p>
                                    </div>

                                    <div class="rounded-xl border border-green-100 bg-white p-3">
                                        <p class="text-lg font-black text-green-700">
                                            <?php echo number_format(
                                                (int) $selectedMarket['product_count']
                                            ); ?>
                                        </p>

                                        <p class="mt-0.5 text-[10px] font-semibold text-slate-400">
                                            Available Products
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- Description + Categories -->
                    <div class="mt-4 grid gap-4 md:grid-cols-2">

                        <!-- Description -->
                        <section class="rounded-2xl border border-slate-200 p-4 sm:p-5">
                            <div class="flex items-center gap-2">
                                <span class="grid h-8 w-8 place-items-center rounded-lg bg-amber-50 text-amber-700">
                                    <i class="fa-solid fa-align-left text-xs"></i>
                                </span>

                                <h3 class="text-sm font-black text-slate-900">
                                    Description
                                </h3>
                            </div>

                            <p class="mt-3 text-xs leading-6 text-slate-500">
                                <?php echo market_page_e(
                                    $selectedMarket['description']
                                ); ?>
                            </p>
                        </section>

                        <!-- Categories -->
                        <section class="rounded-2xl border border-slate-200 p-4 sm:p-5">

                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-green-50 text-green-700">
                                        <i class="fa-solid fa-layer-group text-xs"></i>
                                    </span>

                                    <h3 class="text-sm font-black text-slate-900">
                                        Categories
                                    </h3>
                                </div>

                                <span class="rounded-full bg-green-50 px-2.5 py-1 text-[10px] font-bold text-green-700">
                                    <?php echo number_format(
                                        count($selectedCategories)
                                    ); ?>
                                </span>
                            </div>

                            <?php if (empty($selectedCategories)): ?>

                                <p class="mt-3 text-xs text-slate-400">
                                    No categories are available for this market.
                                </p>

                            <?php else: ?>

                                <div class="mt-3 flex flex-wrap gap-2">

                                    <?php foreach ($selectedCategories as $category): ?>

                                        <span class="rounded-full border border-green-200 bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700">
                                            <?php echo market_page_e(
                                                $category['category_name']
                                            ); ?>
                                        </span>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>
                        </section>
                    </div>

                    <!-- Events -->
                    <section class="mt-4 rounded-2xl border border-slate-200 p-4 sm:p-5">

                        <div class="flex items-center justify-between gap-3">

                            <div class="flex items-center gap-2">
                                <span class="grid h-8 w-8 place-items-center rounded-lg bg-amber-50 text-amber-700">
                                    <i class="fa-regular fa-calendar text-xs"></i>
                                </span>

                                <h3 class="text-sm font-black text-slate-900">
                                    Upcoming and Ongoing Events
                                </h3>
                            </div>

                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-bold text-amber-700">
                                <?php echo number_format(
                                    count($selectedEvents)
                                ); ?>
                            </span>
                        </div>

                        <?php if (empty($selectedEvents)): ?>

                            <p class="mt-3 text-xs text-slate-400">
                                No active events are available for this market.
                            </p>

                        <?php else: ?>

                            <div class="mt-3 grid gap-3 md:grid-cols-2">

                                <?php foreach ($selectedEvents as $event): ?>

                                    <article class="rounded-xl border border-amber-100 bg-amber-50/60 p-3.5">

                                        <h4 class="text-xs font-black text-slate-900">
                                            <?php echo market_page_e(
                                                $event['event_name']
                                            ); ?>
                                        </h4>

                                        <p class="mt-2 text-[10px] font-semibold text-amber-700">
                                            <i class="fa-regular fa-calendar mr-1"></i>

                                            <?php echo market_page_e(
                                                date(
                                                    'M d, Y',
                                                    strtotime($event['start_date'])
                                                ) .
                                                ' – ' .
                                                date(
                                                    'M d, Y',
                                                    strtotime($event['end_date'])
                                                )
                                            ); ?>
                                        </p>

                                        <p class="mt-2 line-clamp-2 text-[10px] leading-5 text-slate-500">
                                            <?php echo market_page_e(
                                                $event['description']
                                            ); ?>
                                        </p>
                                    </article>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>
                    </section>
                </div>

                <!-- Modal Footer -->
                <div class="flex flex-col-reverse gap-2 border-t border-slate-100 px-4 py-3.5 sm:flex-row sm:justify-end sm:px-5">

                    <a href="<?php echo market_page_e(
                        market_page_query_url(array('view' => null))
                    ); ?>"
                       class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600 transition hover:bg-slate-50">

                        Close
                    </a>

                    <a href="products.php?market_id=<?php echo (int) $selectedMarket['market_id']; ?>"
                       class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-xs font-bold text-white transition hover:bg-green-700">

                        <i class="fa-solid fa-basket-shopping"></i>

                        Browse Products
                    </a>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var mobileMenuButton = document.getElementById('mobileMenuButton');
        var mobileMenu = document.getElementById('mobileMenu');
        var accountButton = document.getElementById('accountButton');
        var accountMenu = document.getElementById('accountMenu');
        var accountChevron = document.getElementById('accountChevron');
        var marketDetailMainImage = document.getElementById('marketDetailMainImage');
        var marketDetailImageButtons = document.querySelectorAll('[data-market-detail-image]');

        if (marketDetailMainImage && marketDetailImageButtons.length > 0) {
            for (var imageIndex = 0; imageIndex < marketDetailImageButtons.length; imageIndex++) {
                marketDetailImageButtons[imageIndex].addEventListener('click', function () {
                    marketDetailMainImage.src = this.getAttribute('data-market-detail-image');
                });
            }
        }

        if (mobileMenuButton && mobileMenu) {
            mobileMenuButton.addEventListener('click', function () {
                mobileMenu.classList.toggle('hidden');
            });
        }

        function closeAccountMenu() {
            if (!accountButton || !accountMenu) {
                return;
            }

            accountMenu.classList.add('hidden');
            accountButton.setAttribute('aria-expanded', 'false');

            if (accountChevron) {
                accountChevron.classList.remove('rotate-180');
            }
        }

        if (accountButton && accountMenu) {
            accountButton.addEventListener('click', function (event) {
                event.stopPropagation();

                var hidden = accountMenu.classList.contains('hidden');
                accountMenu.classList.toggle('hidden');
                accountButton.setAttribute('aria-expanded', hidden ? 'true' : 'false');

                if (accountChevron) {
                    accountChevron.classList.toggle('rotate-180', hidden);
                }
            });

            accountMenu.addEventListener('click', function (event) {
                event.stopPropagation();
            });

            document.addEventListener('click', closeAccountMenu);

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeAccountMenu();
                }
            });
        }
    });
</script>
<?php require __DIR__ . '/footer.php'; ?>