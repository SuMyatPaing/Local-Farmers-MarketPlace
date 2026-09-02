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

function redirectToCities(): void
{
    header('Location: cities.php');
    exit;
}

function setCitiesFlash(string $type, string $message): void
{
    $_SESSION['cities_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function validateCitiesCsrfToken(): bool
{
    $submittedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $sessionToken = isset($_SESSION['csrf_token']) ? (string) $_SESSION['csrf_token'] : '';

    return $submittedToken !== ''
        && $sessionToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}

function citiesQueryString(array $changes): string
{
    $current = [
        'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
        'sort' => isset($_GET['sort']) ? (string) $_GET['sort'] : 'name_asc',
        'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    ];

    foreach ($changes as $key => $value) {
        $current[$key] = $value;
    }

    return http_build_query($current);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Create, update and delete actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCitiesCsrfToken()) {
        setCitiesFlash('error', 'Security token expired. Please try again.');
        redirectToCities();
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'create') {
            $cityName = trim(isset($_POST['city_name']) ? (string) $_POST['city_name'] : '');
            $division = trim(isset($_POST['administrative_division']) ? (string) $_POST['administrative_division'] : '');

            if ($cityName === '' || $division === '') {
                throw new RuntimeException('Please enter both the city name and administrative division.');
            }

            if (mb_strlen($cityName) > 100) {
                throw new RuntimeException('City name cannot be longer than 100 characters.');
            }

            if (mb_strlen($division) > 50) {
                throw new RuntimeException('Administrative division cannot be longer than 50 characters.');
            }

            $check = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM cities
                 WHERE LOWER(TRIM(city_name)) = LOWER(TRIM(:city_name))
                   AND LOWER(TRIM(administrative_division)) = LOWER(TRIM(:division))'
            );
            $check->execute([
                'city_name' => $cityName,
                'division' => $division,
            ]);

            if ((int) $check->fetchColumn() > 0) {
                throw new RuntimeException('That city already exists in the selected administrative division.');
            }

            $statement = $pdo->prepare(
                'INSERT INTO cities (city_name, administrative_division)
                 VALUES (:city_name, :division)'
            );
            $statement->execute([
                'city_name' => $cityName,
                'division' => $division,
            ]);

            setCitiesFlash('success', 'City created successfully.');
            redirectToCities();
        }

        if ($action === 'update') {
            $cityId = isset($_POST['city_id']) ? (int) $_POST['city_id'] : 0;
            $cityName = trim(isset($_POST['city_name']) ? (string) $_POST['city_name'] : '');
            $division = trim(isset($_POST['administrative_division']) ? (string) $_POST['administrative_division'] : '');

            if ($cityId <= 0 || $cityName === '' || $division === '') {
                throw new RuntimeException('Please complete all required fields.');
            }

            if (mb_strlen($cityName) > 100 || mb_strlen($division) > 50) {
                throw new RuntimeException('One or more fields are longer than the database limit.');
            }

            $check = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM cities
                 WHERE LOWER(TRIM(city_name)) = LOWER(TRIM(:city_name))
                   AND LOWER(TRIM(administrative_division)) = LOWER(TRIM(:division))
                   AND city_id <> :city_id'
            );
            $check->execute([
                'city_name' => $cityName,
                'division' => $division,
                'city_id' => $cityId,
            ]);

            if ((int) $check->fetchColumn() > 0) {
                throw new RuntimeException('Another city already uses that name and administrative division.');
            }

            $statement = $pdo->prepare(
                'UPDATE cities
                 SET city_name = :city_name,
                     administrative_division = :division
                 WHERE city_id = :city_id'
            );
            $statement->execute([
                'city_name' => $cityName,
                'division' => $division,
                'city_id' => $cityId,
            ]);

            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT COUNT(*) FROM cities WHERE city_id = :city_id');
                $exists->execute(['city_id' => $cityId]);
                if ((int) $exists->fetchColumn() === 0) {
                    throw new RuntimeException('City record was not found.');
                }
            }

            setCitiesFlash('success', 'City updated successfully.');
            redirectToCities();
        }

        if ($action === 'delete') {
            $cityId = isset($_POST['city_id']) ? (int) $_POST['city_id'] : 0;

            if ($cityId <= 0) {
                throw new RuntimeException('Invalid city delete request.');
            }

            $marketCheck = $pdo->prepare('SELECT COUNT(*) FROM markets WHERE city_id = :city_id');
            $marketCheck->execute(['city_id' => $cityId]);
            $marketCount = (int) $marketCheck->fetchColumn();

            if ($marketCount > 0) {
                throw new RuntimeException(
                    'This city cannot be deleted because ' . number_format($marketCount) .
                    ' market' . ($marketCount === 1 ? ' is' : 's are') . ' linked to it.'
                );
            }

            $statement = $pdo->prepare('DELETE FROM cities WHERE city_id = :city_id');
            $statement->execute(['city_id' => $cityId]);

            if ($statement->rowCount() === 0) {
                throw new RuntimeException('City record was not found.');
            }

            setCitiesFlash('success', 'City deleted successfully.');
            redirectToCities();
        }

        throw new RuntimeException('Unsupported action.');
    } catch (Throwable $exception) {
        setCitiesFlash('error', $exception->getMessage());
        redirectToCities();
    }
}

/*
|--------------------------------------------------------------------------
| Search, sort and pagination
|--------------------------------------------------------------------------
*/
$search = trim(
    isset($_GET['q'])
        ? (string) $_GET['q']
        : ''
);

$sort = strtolower(
    trim(
        isset($_GET['sort'])
            ? (string) $_GET['sort']
            : 'name_asc'
    )
);

$sortOptions = [
    'name_asc' => 'c.city_name ASC, c.administrative_division ASC',
    'name_desc' => 'c.city_name DESC, c.administrative_division ASC',
    'division_asc' => 'c.administrative_division ASC, c.city_name ASC',
    'most_markets' => 'market_count DESC, c.city_name ASC',
    'least_markets' => 'market_count ASC, c.city_name ASC',
];
if (!isset($sortOptions[$sort])) {
    $sort = 'name_asc';
}

$orderBy = $sortOptions[$sort];

$whereSql = '';
$params = [];

if ($search !== '') {
    $whereSql = ' WHERE (
        LOWER(TRIM(c.city_name)) LIKE LOWER(TRIM(:search_city)) OR
        LOWER(TRIM(c.administrative_division)) LIKE LOWER(TRIM(:search_division))
    )';

    $searchValue = '%' . trim($search) . '%';

    $params['search_city'] = $searchValue;
    $params['search_division'] = $searchValue;
}

$countStatement = $pdo->prepare('SELECT COUNT(*) FROM cities c' . $whereSql);
$countStatement->execute($params);
$filteredTotal = (int) $countStatement->fetchColumn();

$perPage = 10;
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, min($page, $totalPages));
$offset = ($page - 1) * $perPage;

$listSql = 'SELECT
                c.city_id,
                c.city_name,
                c.administrative_division,
                COUNT(m.market_id) AS market_count
            FROM cities c
            LEFT JOIN markets m ON m.city_id = c.city_id'
            . $whereSql . '
            GROUP BY c.city_id, c.city_name, c.administrative_division
            ORDER BY ' . $orderBy . '
            LIMIT :limit OFFSET :offset';

$listStatement = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStatement->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$cities = $listStatement->fetchAll(PDO::FETCH_ASSOC);

$totalCities = (int) $pdo->query('SELECT COUNT(*) FROM cities')->fetchColumn();
$totalDivisions = (int) $pdo->query('SELECT COUNT(DISTINCT administrative_division) FROM cities')->fetchColumn();
$totalMarkets = (int) $pdo->query('SELECT COUNT(*) FROM markets')->fetchColumn();
$citiesWithoutMarkets = (int) $pdo->query(
    'SELECT COUNT(*)
     FROM cities c
     LEFT JOIN markets m ON m.city_id = c.city_id
     WHERE m.market_id IS NULL'
)->fetchColumn();

$flash = isset($_SESSION['cities_flash']) && is_array($_SESSION['cities_flash'])
    ? $_SESSION['cities_flash']
    : null;
unset($_SESSION['cities_flash']);

$fromRecord = $filteredTotal > 0 ? $offset + 1 : 0;
$toRecord = min($offset + $perPage, $filteredTotal);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cities | Local Farmers Marketplace</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Cities Page - Compact Production Admin UI at Browser Zoom 100%
        |--------------------------------------------------------------------------
        | - compact KPI cards and table rows
        | - aligns with the 176px compact admin sidebar
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

        .cities-scroll-hidden {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .cities-scroll-hidden::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .cities-main {
            min-width: 0;
        }

        .cities-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
        }

        .cities-stat-card,
        .cities-filter-panel,
        .cities-list-panel {
            min-width: 0;
        }

        .cities-table {
            width: 100%;
            min-width: 760px;
        }

        .cities-table th,
        .cities-table td {
            vertical-align: middle;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            .cities-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .cities-shell {
                margin-left: 176px !important;
            }

            .cities-main {
                padding: 14px 18px 24px !important;
            }

            .cities-stats {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: .75rem;
                margin-bottom: .85rem !important;
            }

            .cities-stat-card {
                min-height: 84px;
                border-radius: 14px !important;
                padding: 12px 14px !important;
            }

            .cities-stat-card p:first-child {
                font-size: 9px !important;
                letter-spacing: .04em !important;
            }

            .cities-stat-card p.text-3xl {
                margin-top: 5px !important;
                font-size: 20px !important;
                line-height: 1 !important;
            }

            .cities-stat-card .h-12 {
                width: 38px !important;
                height: 38px !important;
                border-radius: 10px !important;
            }

            .cities-stat-card .text-xl {
                font-size: 15px !important;
            }

            .cities-filter-panel,
            .cities-list-panel {
                border-radius: 14px !important;
            }

            .cities-filter-panel {
                padding: 12px 14px !important;
                margin-bottom: .85rem !important;
            }

            .cities-filter-form {
                gap: 8px !important;
            }

            .cities-filter-control,
            .cities-filter-button,
            .cities-clear-button {
                height: 36px !important;
                border-radius: 9px !important;
                font-size: 10px !important;
            }

            .cities-search-input {
                padding-left: 34px !important;
                padding-right: 10px !important;
            }

            .cities-search-icon {
                left: 12px !important;
                font-size: 10px !important;
            }

            .cities-filter-button {
                padding-left: 13px !important;
                padding-right: 13px !important;
            }

            .cities-clear-button {
                padding-left: 11px !important;
                padding-right: 11px !important;
            }

            .cities-list-head {
                padding: 11px 14px !important;
            }

            .cities-list-title {
                font-size: 14px !important;
            }

            .cities-list-subtitle {
                margin-top: 3px !important;
                font-size: 9px !important;
            }

            .cities-add-button {
                border-radius: 9px !important;
                padding: 8px 12px !important;
                font-size: 10px !important;
            }

            .cities-table {
                min-width: 0;
                table-layout: fixed;
            }

            .cities-table thead {
                font-size: 8px !important;
            }

            .cities-table th {
                padding: 8px 10px !important;
            }

            .cities-table td {
                padding: 9px 10px !important;
            }

            .cities-table th:nth-child(1),
            .cities-table td:nth-child(1) {
                width: 28%;
            }

            .cities-table th:nth-child(2),
            .cities-table td:nth-child(2) {
                width: 34%;
            }

            .cities-table th:nth-child(3),
            .cities-table td:nth-child(3) {
                width: 18%;
            }

            .cities-table th:nth-child(4),
            .cities-table td:nth-child(4) {
                width: 20%;
            }

            .cities-city-icon {
                width: 30px !important;
                height: 30px !important;
                border-radius: 8px !important;
                font-size: 10px !important;
            }

            .cities-city-name {
                font-size: 10px !important;
            }

            .cities-city-id,
            .cities-division {
                font-size: 8px !important;
            }

            .cities-market-badge {
                border-radius: 7px !important;
                padding: 4px 7px !important;
                font-size: 8px !important;
            }

            .cities-action-button {
                width: 30px !important;
                height: 30px !important;
                border-radius: 7px !important;
            }

            .cities-action-button i {
                font-size: 9px !important;
            }

            .cities-pagination {
                padding: 10px 14px !important;
            }

            .cities-pagination p {
                font-size: 9px !important;
            }

            .cities-pagination a {
                width: 30px !important;
                min-width: 30px !important;
                height: 30px !important;
                border-radius: 7px !important;
                font-size: 9px !important;
            }
        }

        @media (max-width: 1023px) {
            .cities-table {
                min-width: 760px;
            }
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>
<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">
<div class="min-h-screen">
    <?php require_once './sidebar.php'; ?>

    <div class="cities-shell min-h-screen lg:ml-64">
        <?php require_once './header.php'; ?>

        <main class="cities-main px-4 pb-10 pt-5 sm:px-6 xl:px-7">
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

            <section class="cities-stats mb-5">
                <article class="cities-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total Cities</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalCities)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-city text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="cities-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Divisions / States</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalDivisions)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-map text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="cities-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Linked Markets</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalMarkets)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-amber-50 text-amber-600">
                            <i class="fa-solid fa-store text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="cities-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Without Markets</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($citiesWithoutMarkets)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-violet-50 text-violet-600">
                            <i class="fa-solid fa-location-dot text-xl"></i>
                        </div>
                    </div>
                </article>
            </section>

            <!-- Search / Filter ABOVE the City table section -->
            <section class="cities-filter-panel mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-card sm:p-5">
                <form method="get"
                      action="cities.php"
                      class="cities-filter-form flex flex-col gap-3 lg:flex-row lg:items-center">

                    <div class="relative flex-1">
                        <i class="cities-search-icon fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                        <input type="search"
                               name="q"
                               value="<?= e($search) ?>"
                               placeholder="Search city name or division/state..."
                               class="cities-filter-control cities-search-input h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                    </div>

                    <select name="sort"
                            class="cities-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3.5 text-sm font-medium text-slate-600 outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">

                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>
                            Name: A to Z
                        </option>

                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>
                            Name: Z to A
                        </option>

                        <option value="division_asc" <?= $sort === 'division_asc' ? 'selected' : '' ?>>
                            Division / State
                        </option>

                        <option value="most_markets" <?= $sort === 'most_markets' ? 'selected' : '' ?>>
                            Most Markets
                        </option>

                        <option value="least_markets" <?= $sort === 'least_markets' ? 'selected' : '' ?>>
                            Least Markets
                        </option>
                    </select>

                    <button type="submit"
                            class="cities-filter-button inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-bold text-white transition hover:bg-green-700">

                        <i class="fa-solid fa-filter text-xs"></i>
                        Filter
                    </button>

                    <a href="cities.php"
                       class="cities-clear-button inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700">

                        <i class="fa-solid fa-rotate-left text-xs"></i>
                        Clear
                    </a>
                </form>
            </section>

            <section class="cities-list-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

                <div class="cities-list-head flex flex-col gap-3 border-b border-slate-100 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">

                    <div>
                        <h2 class="cities-list-title text-lg font-extrabold text-slate-950">
                            City List
                        </h2>

                        <p class="cities-list-subtitle mt-1 text-xs text-slate-400">
                            Manage cities used by the local farmers marketplace.
                        </p>
                    </div>

                    <button type="button"
                            onclick="openCreateModal()"
                            class="cities-add-button inline-flex w-fit items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-green-700">

                        <i class="fa-solid fa-plus text-xs"></i>
                        Add City
                    </button>
                </div>

                <!-- City Table -->
                <div class="cities-scroll-hidden overflow-x-auto">
                    <table class="cities-table min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50/80">
                        <tr>
                            <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-400">City</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Administrative Division</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Markets</th>
                            <th class="px-5 py-3.5 text-right text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Actions</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                        <?php if (!$cities): ?>
                            <tr>
                                <td colspan="4" class="px-5 py-16 text-center">
                                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                                        <i class="fa-solid fa-city text-xl"></i>
                                    </div>
                                    <h3 class="mt-4 text-sm font-extrabold text-slate-800">No cities found</h3>
                                    <p class="mt-1 text-sm text-slate-400">Try a different search or add your first city.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($cities as $city): ?>
                                <?php
                                $cityId = (int) $city['city_id'];
                                $marketCount = (int) $city['market_count'];
                                $cityJson = json_encode([
                                    'city_id' => $cityId,
                                    'city_name' => (string) $city['city_name'],
                                    'administrative_division' => (string) $city['administrative_division'],
                                    'market_count' => $marketCount,
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                                ?>
                                <tr class="transition hover:bg-slate-50/70">
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="cities-city-icon grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600">
                                                <i class="fa-solid fa-location-dot"></i>
                                            </div>
                                            <div>
                                                <p class="cities-city-name text-sm font-extrabold text-slate-900"><?= e($city['city_name']) ?></p>
                                                <p class="cities-city-id mt-0.5 text-xs text-slate-400">City ID: #<?= e($cityId) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="cities-division whitespace-nowrap px-5 py-4 text-sm font-medium text-slate-600">
                                        <?= e($city['administrative_division']) ?>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <?php if ($marketCount > 0): ?>
                                            <a
                                                href="markets.php?city=<?= e($cityId) ?>"
                                                class="cities-market-badge inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 transition hover:bg-blue-100 hover:text-blue-800"
                                                title="View markets in <?= e($city['city_name']) ?>"
                                            >
                                                <i class="fa-solid fa-store text-[10px]"></i>
                                                <?= e(number_format($marketCount)) ?>
                                            </a>
                                        <?php else: ?>
                                            <span
                                                class="cities-market-badge inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500"
                                                title="No markets linked"
                                            >
                                                <i class="fa-solid fa-store text-[10px]"></i>
                                                0
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <button type="button" onclick='openEditModal(<?= $cityJson ?>)'
                                                    class="cities-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700"
                                                    title="Edit city">
                                                <i class="fa-solid fa-pen text-xs"></i>
                                            </button>

                                            <form method="post" onsubmit="return confirm('Delete this city? This action cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="city_id" value="<?= e($cityId) ?>">
                                                <button type="submit"
                                                        class="cities-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600 <?= $marketCount > 0 ? 'cursor-not-allowed opacity-40' : '' ?>"
                                                        title="<?= $marketCount > 0 ? 'Remove linked markets first' : 'Delete city' ?>"
                                                        <?= $marketCount > 0 ? 'disabled' : '' ?>>
                                                    <i class="fa-solid fa-trash text-xs"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="cities-pagination flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs font-medium text-slate-400">
                        Showing <span class="font-bold text-slate-600"><?= e(number_format($fromRecord)) ?></span>
                        to <span class="font-bold text-slate-600"><?= e(number_format($toRecord)) ?></span>
                        of <span class="font-bold text-slate-600"><?= e(number_format($filteredTotal)) ?></span> cities
                    </p>

                    <?php if ($totalPages > 1): ?>
                        <nav class="flex items-center gap-1.5">
                            <a href="?<?= e(citiesQueryString(['page' => max(1, $page - 1)])) ?>"
                               class="grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-sm text-slate-500 transition hover:bg-slate-50 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </a>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            ?>
                            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                                <a href="?<?= e(citiesQueryString(['page' => $pageNumber])) ?>"
                                   class="grid h-9 min-w-9 place-items-center rounded-xl px-2 text-xs font-extrabold transition <?= $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50' ?>">
                                    <?= e($pageNumber) ?>
                                </a>
                            <?php endfor; ?>

                            <a href="?<?= e(citiesQueryString(['page' => min($totalPages, $page + 1)])) ?>"
                               class="grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-sm text-slate-500 transition hover:bg-slate-50 <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>">
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </a>
                        </nav>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- Create City Modal -->
<div id="createModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="text-lg font-extrabold text-slate-950">Add City</h2>
                <p class="mt-0.5 text-xs text-slate-500">Create a city before adding its markets.</p>
            </div>
            <button type="button" onclick="closeModal('createModal')" class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" class="space-y-4 p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">

            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">City Name *</span>
                <input type="text" name="city_name" required maxlength="100" placeholder="Example: Mandalay"
                       class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
            </label>

            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">Administrative Division / State *</span>
                <input type="text" name="administrative_division" required maxlength="50" placeholder="Example: Mandalay Region"
                       class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
            </label>

            <div class="rounded-xl bg-blue-50 px-3.5 py-3 text-xs leading-5 text-blue-800">
                <i class="fa-solid fa-circle-info mr-1"></i>
                A city can contain multiple markets. The same city and division combination cannot be added twice.
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('createModal')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">Create City</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit City Modal -->
<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="text-lg font-extrabold text-slate-950">Edit City</h2>
                <p class="mt-0.5 text-xs text-slate-500">Update the city and administrative division.</p>
            </div>
            <button type="button" onclick="closeModal('editModal')" class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" class="space-y-4 p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit_city_id" name="city_id" value="">

            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">City Name *</span>
                <input type="text" id="edit_city_name" name="city_name" required maxlength="100"
                       class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
            </label>

            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">Administrative Division / State *</span>
                <input type="text" id="edit_administrative_division" name="administrative_division" required maxlength="50"
                       class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
            </label>

            <div id="edit_market_warning"
                 class="hidden rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-xs leading-5 text-amber-800">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                <span id="edit_market_warning_text"></span>
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('editModal')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCreateModal() {
        var modal = document.getElementById('createModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
    }

    function openEditModal(city) {
        document.getElementById('edit_city_id').value = city.city_id || '';
        document.getElementById('edit_city_name').value = city.city_name || '';
        document.getElementById('edit_administrative_division').value = city.administrative_division || '';

        var marketCount = parseInt(city.market_count || 0, 10);
        var warning = document.getElementById('edit_market_warning');
        var warningText = document.getElementById('edit_market_warning_text');

        if (warning && warningText) {
            if (marketCount > 0) {
                warningText.textContent =
                    'This city has ' + marketCount + ' linked market' +
                    (marketCount === 1 ? '' : 's') +
                    '. Renaming the city or division will also change the location shown for those linked markets.';

                warning.classList.remove('hidden');
            } else {
                warning.classList.add('hidden');
                warningText.textContent = '';
            }
        }

        var modal = document.getElementById('editModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
    }

    function closeModal(id) {
        var modal = document.getElementById(id);
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal('createModal');
            closeModal('editModal');
        }
    });

    ['createModal', 'editModal'].forEach(function (id) {
        var modal = document.getElementById(id);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal(id);
            }
        });
    });
</script>
</body>
</html>