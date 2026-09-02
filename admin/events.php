<?php
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

function redirectToEvents(): void
{
    header('Location: events.php');
    exit;
}

function setEventsFlash(string $type, string $message): void
{
    $_SESSION['events_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function validateEventsCsrfToken(): bool
{
    $submittedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $sessionToken = isset($_SESSION['csrf_token']) ? (string) $_SESSION['csrf_token'] : '';

    return $submittedToken !== ''
        && $sessionToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}

function eventsQueryString(array $changes): string
{
    $current = [
        'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
        'market' => isset($_GET['market']) ? (int) $_GET['market'] : 0,
        'timing' => isset($_GET['timing']) ? (string) $_GET['timing'] : 'all',
        'sort' => isset($_GET['sort']) ? (string) $_GET['sort'] : 'soonest',
        'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    ];

    foreach ($changes as $key => $value) {
        $current[$key] = $value;
    }

    return http_build_query($current);
}

function currentAdminUserId(PDO $pdo): int
{
    $sessionCandidates = ['user_id', 'admin_id', 'id'];

    foreach ($sessionCandidates as $key) {
        if (!empty($_SESSION[$key])) {
            $candidateId = (int) $_SESSION[$key];

            if ($candidateId > 0) {
                $statement = $pdo->prepare(
                    "SELECT user_id
                     FROM users
                     WHERE user_id = :user_id
                       AND role = 'admin'
                       AND status = 'active'
                     LIMIT 1"
                );
                $statement->execute(['user_id' => $candidateId]);
                $foundId = (int) $statement->fetchColumn();

                if ($foundId > 0) {
                    return $foundId;
                }
            }
        }
    }

    $statement = $pdo->query(
        "SELECT user_id
         FROM users
         WHERE role = 'admin'
           AND status = 'active'
         ORDER BY user_id ASC
         LIMIT 1"
    );
    $fallbackId = (int) $statement->fetchColumn();

    if ($fallbackId <= 0) {
        throw new RuntimeException('No active administrator account was found.');
    }

    return $fallbackId;
}

function parseEventDate(string $value, string $label): string
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();

    $hasErrors = $errors !== false
        && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0);

    if (!$date || $hasErrors || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException($label . ' is not a valid date.');
    }

    return $date->format('Y-m-d');
}

function validateEventFields(PDO $pdo, array $source): array
{
    $marketId = isset($source['market_id']) ? (int) $source['market_id'] : 0;
    $eventName = trim(isset($source['event_name']) ? (string) $source['event_name'] : '');
    $startDateInput = trim(isset($source['start_date']) ? (string) $source['start_date'] : '');
    $endDateInput = trim(isset($source['end_date']) ? (string) $source['end_date'] : '');
    $description = trim(isset($source['description']) ? (string) $source['description'] : '');

    if ($marketId <= 0 || $eventName === '' || $startDateInput === '' || $endDateInput === '') {
        throw new RuntimeException('Please complete all required event fields.');
    }

    if (mb_strlen($eventName) > 100) {
        throw new RuntimeException('Event name cannot be longer than 100 characters.');
    }

    if (mb_strlen($description) > 5000) {
        throw new RuntimeException('Description cannot be longer than 5,000 characters.');
    }

    $startDate = parseEventDate($startDateInput, 'Start date');
    $endDate = parseEventDate($endDateInput, 'End date');

    if ($endDate < $startDate) {
        throw new RuntimeException('End date must be the same as or later than the start date.');
    }

    $marketCheck = $pdo->prepare('SELECT COUNT(*) FROM markets WHERE market_id = :market_id');
    $marketCheck->execute(['market_id' => $marketId]);

    if ((int) $marketCheck->fetchColumn() === 0) {
        throw new RuntimeException('The selected market was not found.');
    }

    return [
        'market_id' => $marketId,
        'event_name' => $eventName,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'description' => $description,
    ];
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
| Create, update and delete actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateEventsCsrfToken()) {
        setEventsFlash('error', 'Security token expired. Please try again.');
        redirectToEvents();
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'create') {
            $event = validateEventFields($pdo, $_POST);
            $event['user_id'] = currentAdminUserId($pdo);

            $statement = $pdo->prepare(
                'INSERT INTO events (
                    market_id,
                    user_id,
                    event_name,
                    start_date,
                    end_date,
                    description
                 ) VALUES (
                    :market_id,
                    :user_id,
                    :event_name,
                    :start_date,
                    :end_date,
                    :description
                 )'
            );
            $statement->execute($event);

            setEventsFlash('success', 'Event created successfully.');
            redirectToEvents();
        }

        if ($action === 'update') {
            $eventId = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;

            if ($eventId <= 0) {
                throw new RuntimeException('Invalid event selected.');
            }

            $exists = $pdo->prepare('SELECT COUNT(*) FROM events WHERE event_id = :event_id');
            $exists->execute(['event_id' => $eventId]);

            if ((int) $exists->fetchColumn() === 0) {
                throw new RuntimeException('Event not found.');
            }

            $event = validateEventFields($pdo, $_POST);
            $event['event_id'] = $eventId;

            $statement = $pdo->prepare(
                'UPDATE events
                 SET market_id = :market_id,
                     event_name = :event_name,
                     start_date = :start_date,
                     end_date = :end_date,
                     description = :description
                 WHERE event_id = :event_id'
            );
            $statement->execute($event);

            setEventsFlash('success', 'Event updated successfully.');
            redirectToEvents();
        }

        if ($action === 'delete') {
            $eventId = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;

            if ($eventId <= 0) {
                throw new RuntimeException('Invalid event selected.');
            }

            $statement = $pdo->prepare('DELETE FROM events WHERE event_id = :event_id');
            $statement->execute(['event_id' => $eventId]);

            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Event not found.');
            }

            setEventsFlash('success', 'Event deleted successfully.');
            redirectToEvents();
        }

        throw new RuntimeException('Unsupported event action.');
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            setEventsFlash('error', 'The event could not be saved because a related record is missing.');
        } else {
            error_log('Admin event database error: ' . $exception->getMessage());
            setEventsFlash('error', 'The database could not complete the event action.');
        }
        redirectToEvents();
    } catch (Throwable $exception) {
        setEventsFlash('error', $exception->getMessage());
        redirectToEvents();
    }
}

/*
|--------------------------------------------------------------------------
| Filters, sorting and pagination
|--------------------------------------------------------------------------
*/
$search = trim(
    isset($_GET['q'])
        ? (string) $_GET['q']
        : ''
);

$selectedMarket = isset($_GET['market'])
    ? max(0, (int) $_GET['market'])
    : 0;

$timing = strtolower(
    trim(
        isset($_GET['timing'])
            ? (string) $_GET['timing']
            : 'all'
    )
);

$sort = strtolower(
    trim(
        isset($_GET['sort'])
            ? (string) $_GET['sort']
            : 'soonest'
    )
);

$allowedTimings = ['all', 'upcoming', 'ongoing', 'past'];
if (!in_array($timing, $allowedTimings, true)) {
    $timing = 'all';
}

$allowedSorts = [
    'soonest' => 'e.start_date ASC, e.event_id ASC',
    'latest_start' => 'e.start_date DESC, e.event_id DESC',
    'newest' => 'e.created_at DESC, e.event_id DESC',
    'oldest' => 'e.created_at ASC, e.event_id ASC',
    'name_asc' => 'e.event_name ASC',
    'name_desc' => 'e.event_name DESC',
    'market_asc' => 'm.market_name ASC, e.start_date ASC',
];

if (!isset($allowedSorts[$sort])) {
    $sort = 'soonest';
}

$orderBy = $allowedSorts[$sort];
$whereParts = [];
$params = [];

if ($search !== '') {
    $whereParts[] = '(
        LOWER(TRIM(e.event_name)) LIKE LOWER(TRIM(:search_event))
        OR LOWER(TRIM(e.description)) LIKE LOWER(TRIM(:search_description))
        OR LOWER(TRIM(m.market_name)) LIKE LOWER(TRIM(:search_market))
        OR LOWER(TRIM(ci.city_name)) LIKE LOWER(TRIM(:search_city))
    )';

    $searchValue = '%' . trim($search) . '%';
    $params['search_event'] = $searchValue;
    $params['search_description'] = $searchValue;
    $params['search_market'] = $searchValue;
    $params['search_city'] = $searchValue;
}

if ($selectedMarket > 0) {
    $whereParts[] = 'e.market_id = :selected_market';
    $params['selected_market'] = $selectedMarket;
}

if ($timing === 'upcoming') {
    $whereParts[] = 'e.start_date > CURDATE()';
} elseif ($timing === 'ongoing') {
    $whereParts[] = 'e.start_date <= CURDATE() AND e.end_date >= CURDATE()';
} elseif ($timing === 'past') {
    $whereParts[] = 'e.end_date < CURDATE()';
}

$whereSql = count($whereParts) > 0 ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$countSql = "SELECT COUNT(*)
             FROM events e
             INNER JOIN markets m ON m.market_id = e.market_id
             INNER JOIN cities ci ON ci.city_id = m.city_id
             INNER JOIN users u ON u.user_id = e.user_id
             {$whereSql}";
$countStatement = $pdo->prepare($countSql);
$countStatement->execute($params);
$filteredTotal = (int) $countStatement->fetchColumn();

$perPage = 10;
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, min($page, $totalPages));
$offset = ($page - 1) * $perPage;

$listSql = "SELECT
                e.event_id,
                e.market_id,
                e.user_id,
                e.event_name,
                e.start_date,
                e.end_date,
                e.description,
                e.created_at,
                e.updated_at,
                m.market_name,
                m.address AS market_address,
                ci.city_name,
                ci.administrative_division,
                u.user_name AS admin_name,
                DATEDIFF(e.end_date, e.start_date) + 1 AS duration_days,
                CASE
                    WHEN e.end_date < CURDATE() THEN 'past'
                    WHEN e.start_date > CURDATE() THEN 'upcoming'
                    ELSE 'ongoing'
                END AS event_timing
            FROM events e
            INNER JOIN markets m ON m.market_id = e.market_id
            INNER JOIN cities ci ON ci.city_id = m.city_id
            INNER JOIN users u ON u.user_id = e.user_id
            {$whereSql}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset";

$listStatement = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStatement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$events = $listStatement->fetchAll(PDO::FETCH_ASSOC);

$marketOptions = $pdo->query(
    'SELECT
        m.market_id,
        m.market_name,
        ci.city_name,
        ci.administrative_division
     FROM markets m
     INNER JOIN cities ci ON ci.city_id = m.city_id
     ORDER BY ci.city_name ASC, m.market_name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$eventStats = $pdo->query(
    "SELECT
        COUNT(*) AS total_events,
        SUM(CASE WHEN start_date > CURDATE() THEN 1 ELSE 0 END) AS upcoming_events,
        SUM(CASE WHEN start_date <= CURDATE() AND end_date >= CURDATE() THEN 1 ELSE 0 END) AS ongoing_events,
        SUM(CASE WHEN end_date < CURDATE() THEN 1 ELSE 0 END) AS past_events
     FROM events"
)->fetch(PDO::FETCH_ASSOC);

$totalEvents = isset($eventStats['total_events']) ? (int) $eventStats['total_events'] : 0;
$upcomingEvents = isset($eventStats['upcoming_events']) ? (int) $eventStats['upcoming_events'] : 0;
$ongoingEvents = isset($eventStats['ongoing_events']) ? (int) $eventStats['ongoing_events'] : 0;

$flash = isset($_SESSION['events_flash']) && is_array($_SESSION['events_flash'])
    ? $_SESSION['events_flash']
    : null;
unset($_SESSION['events_flash']);

$fromRecord = $filteredTotal > 0 ? $offset + 1 : 0;
$toRecord = min($offset + $perPage, $filteredTotal);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events | Local Farmers Marketplace</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
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

        .events-scroll-hidden,
        .events-modal-scroll {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .events-scroll-hidden::-webkit-scrollbar,
        .events-modal-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .events-main {
            min-width: 0;
        }

        .events-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
        }

        .events-table {
            width: 100%;
            min-width: 760px;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            .events-stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .events-shell {
                margin-left: 176px !important;
            }

            .events-main {
                padding: 12px 14px 22px !important;
            }

            .events-stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: .65rem;
                margin-bottom: .7rem !important;
            }

            .events-stat-card {
                min-height: 80px;
                padding: 11px 13px !important;
                border-radius: 14px !important;
            }

            .events-stat-card p:first-child {
                font-size: 9px !important;
                letter-spacing: .04em !important;
            }

            .events-stat-card p.text-3xl {
                margin-top: 5px !important;
                font-size: 20px !important;
                line-height: 1 !important;
            }

            .events-stat-card .h-12 {
                width: 38px !important;
                height: 38px !important;
                border-radius: 10px !important;
            }

            .events-stat-card .text-xl {
                font-size: 15px !important;
            }

            .events-filter-panel,
            .events-list-panel {
                border-radius: 14px !important;
            }

            .events-filter-panel {
                padding: 10px 12px !important;
                margin-bottom: .65rem !important;
            }

            .events-filter-form {
                grid-template-columns:
                    minmax(250px, 1.25fr)
                    minmax(155px, .75fr)
                    minmax(145px, .68fr)
                    minmax(155px, .72fr)
                    auto !important;
                gap: 8px !important;
            }

            .events-filter-control,
            .events-filter-button,
            .events-clear-button {
                height: 34px !important;
                border-radius: 9px !important;
                font-size: 9px !important;
            }

            .events-search-input {
                padding-left: 34px !important;
                padding-right: 10px !important;
            }

            .events-search-icon {
                left: 12px !important;
                font-size: 10px !important;
            }

            .events-filter-actions {
                gap: 6px !important;
            }

            .events-list-head {
                padding: 10px 13px !important;
            }

            .events-list-title {
                font-size: 14px !important;
            }

            .events-list-subtitle {
                margin-top: 3px !important;
                font-size: 9px !important;
            }

            .events-add-button {
                padding: 8px 12px !important;
                border-radius: 9px !important;
                font-size: 10px !important;
            }

            .events-status-chip {
                padding: 4px 8px !important;
                font-size: 8px !important;
            }

            .events-table {
                min-width: 0 !important;
                table-layout: fixed;
            }

            .events-table thead {
                font-size: 8px !important;
            }

            .events-table th {
                padding: 7px 8px !important;
            }

            .events-table td {
                padding: 8px !important;
                font-size: 8px !important;
                vertical-align: middle !important;
            }

            .events-table th:nth-child(1),
            .events-table td:nth-child(1) { width: 27%; }

            .events-table th:nth-child(2),
            .events-table td:nth-child(2) { width: 25%; }

            .events-table th:nth-child(3),
            .events-table td:nth-child(3) { width: 25%; }

            .events-table th:nth-child(4),
            .events-table td:nth-child(4) { width: 13%; }

            .events-table th:nth-child(5),
            .events-table td:nth-child(5) { width: 10%; }

            .events-icon {
                width: 30px !important;
                height: 30px !important;
                border-radius: 8px !important;
            }

            .events-name {
                font-size: 10px !important;
            }

            .events-description,
            .events-market-meta,
            .events-duration {
                font-size: 8px !important;
                line-height: 1.4 !important;
            }

            .events-market-name,
            .events-schedule {
                font-size: 9px !important;
            }

            .events-status-badge {
                padding: 4px 7px !important;
                font-size: 8px !important;
            }

            .events-action-wrap {
                display: inline-flex !important;
                flex-wrap: nowrap !important;
                gap: 4px !important;
            }

            .events-action-button {
                width: 26px !important;
                height: 26px !important;
                min-width: 26px !important;
                flex: 0 0 26px !important;
                border-radius: 7px !important;
            }

            .events-action-button i {
                font-size: 8px !important;
            }

            .events-pagination {
                padding: 10px 14px !important;
            }

            .events-pagination p,
            .events-pagination a {
                font-size: 9px !important;
            }

            .events-pagination a {
                width: 30px !important;
                min-width: 30px !important;
                height: 30px !important;
                border-radius: 7px !important;
            }
        }

        @media (max-width: 1023px) {
            .events-table {
                min-width: 760px;
            }
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>
<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">
<div class="min-h-screen">
    <?php require_once './sidebar.php'; ?>

    <div class="events-shell min-h-screen lg:ml-64">
        <?php require_once './header.php'; ?>

        <main class="events-main px-4 pb-10 pt-5 sm:px-6 xl:px-7">
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

            <?php if (count($marketOptions) === 0): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                    <div>
                        <p class="font-extrabold">Create a market first</p>
                        <p class="mt-1 text-xs leading-5 text-amber-800">Every event must belong to a market. Add at least one market before creating an event.</p>
                    </div>
                    <a href="markets.php" class="ml-auto shrink-0 rounded-xl bg-amber-600 px-3 py-2 text-xs font-bold text-white hover:bg-amber-700">Go to Markets</a>
                </div>
            <?php endif; ?>

            <section class="events-stats mb-5">
                <article class="events-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total Events</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalEvents)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-slate-100 text-slate-600">
                            <i class="fa-solid fa-calendar-days text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="events-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Upcoming</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($upcomingEvents)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-clock text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="events-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Ongoing</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($ongoingEvents)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-bolt text-xl"></i>
                        </div>
                    </div>
                </article>

            </section>

            <!-- Search / Filter ABOVE Event Table -->
            <section class="events-filter-panel mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                <form method="get"
                      action="events.php"
                      class="events-filter-form grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(260px,1fr)_220px_170px_180px_auto]">
                    <label class="relative block">
                        <i class="events-search-icon fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search events, markets or cities..."
                               class="events-filter-control events-search-input h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                    </label>

                    <select name="market" class="events-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="0">All Markets</option>
                        <?php foreach ($marketOptions as $market): ?>
                            <option value="<?= e($market['market_id']) ?>" <?= $selectedMarket === (int) $market['market_id'] ? 'selected' : '' ?>>
                                <?= e($market['market_name'] . ' — ' . $market['city_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="timing" class="events-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="all" <?= $timing === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="upcoming" <?= $timing === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                        <option value="ongoing" <?= $timing === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                        <option value="past" <?= $timing === 'past' ? 'selected' : '' ?>>Past</option>
                    </select>

                    <select name="sort" class="events-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="soonest" <?= $sort === 'soonest' ? 'selected' : '' ?>>Start Date: Soonest</option>
                        <option value="latest_start" <?= $sort === 'latest_start' ? 'selected' : '' ?>>Start Date: Latest</option>
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Recently Added</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest Added</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
                        <option value="market_asc" <?= $sort === 'market_asc' ? 'selected' : '' ?>>Market A–Z</option>
                    </select>

                    <div class="events-filter-actions flex gap-2">

                        <button type="submit"
                                class="events-filter-button inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-bold text-white transition hover:bg-green-700">

                            <i class="fa-solid fa-filter text-xs"></i>
                            Filter
                        </button>

                        <a href="events.php"
                           class="events-clear-button inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700">

                            <i class="fa-solid fa-rotate-left text-xs"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </section>

            <section class="events-list-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

                <div class="events-list-head flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h2 class="events-list-title text-lg font-extrabold text-slate-950">
                            Event List
                        </h2>

                        <p class="events-list-subtitle mt-1 text-xs text-slate-400">
                            Showing
                            <?= e(number_format($fromRecord)) ?>
                            to
                            <?= e(number_format($toRecord)) ?>
                            of
                            <?= e(number_format($filteredTotal)) ?>
                            event(s)
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">

                        <span class="events-status-chip rounded-full bg-green-50 px-3 py-1.5 text-xs font-bold text-green-700">
                            <?= e(ucfirst($timing)) ?>
                        </span>

                        <button type="button"
                                onclick="openCreateModal()"
                                class="events-add-button inline-flex items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-green-700 <?= count($marketOptions) === 0 ? 'cursor-not-allowed opacity-50' : '' ?>"
                                <?= count($marketOptions) === 0 ? 'disabled' : '' ?>>

                            <i class="fa-solid fa-plus text-xs"></i>
                            Add Event
                        </button>
                    </div>
                </div>

                <div class="events-scroll-hidden overflow-x-auto">
                    <table class="events-table w-full min-w-[1100px] text-left">
                        <thead class="bg-slate-50 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-5 py-3.5">Event</th>
                            <th class="px-5 py-3.5">Market</th>
                            <th class="px-5 py-3.5">Schedule</th>
                            <th class="px-5 py-3.5">Status</th>
                            <th class="px-5 py-3.5 text-right">Actions</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php if (count($events) === 0): ?>
                            <tr>
                                <td colspan="5" class="px-5 py-16 text-center">
                                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-100 text-slate-400">
                                        <i class="fa-regular fa-calendar-xmark text-xl"></i>
                                    </div>
                                    <p class="mt-4 text-sm font-extrabold text-slate-700">No events found</p>
                                    <p class="events-market-meta mt-1 text-xs text-slate-400">Change the filters or create a new event.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($events as $event): ?>
                                <?php
                                $eventTiming = (string) $event['event_timing'];
                                if ($eventTiming === 'ongoing') {
                                    $statusClasses = 'bg-green-50 text-green-700 ring-green-600/10';
                                    $statusIcon = 'fa-circle-play';
                                } elseif ($eventTiming === 'upcoming') {
                                    $statusClasses = 'bg-blue-50 text-blue-700 ring-blue-600/10';
                                    $statusIcon = 'fa-clock';
                                } else {
                                    $statusClasses = 'bg-slate-100 text-slate-600 ring-slate-500/10';
                                    $statusIcon = 'fa-circle-check';
                                }

                                $startDateObject = new DateTime((string) $event['start_date']);
                                $endDateObject = new DateTime((string) $event['end_date']);
                                $sameDate = $event['start_date'] === $event['end_date'];
                                $editData = [
                                    'event_id' => (int) $event['event_id'],
                                    'market_id' => (int) $event['market_id'],
                                    'event_name' => (string) $event['event_name'],
                                    'start_date' => (string) $event['start_date'],
                                    'end_date' => (string) $event['end_date'],
                                    'description' => (string) $event['description'],
                                ];
                                ?>
                                <tr class="transition hover:bg-slate-50/70">
                                    <td class="px-5 py-4 align-top">
                                        <div class="flex items-start gap-3">
                                            <div class="events-icon mt-0.5 grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600">
                                                <i class="fa-solid fa-calendar-day"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="events-name max-w-xs truncate text-sm font-extrabold text-slate-900"><?= e($event['event_name']) ?></p>
                                                <p class="events-description mt-1 max-w-xs line-clamp-2 text-xs leading-5 text-slate-500"><?= e($event['description']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        <p class="events-market-name text-sm font-bold text-slate-700"><?= e($event['market_name']) ?></p>
                                        <p class="mt-1 text-xs text-slate-400">
                                            <i class="fa-solid fa-location-dot mr-1"></i>
                                            <?= e($event['city_name'] . ', ' . $event['administrative_division']) ?>
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        <p class="events-schedule text-sm font-bold text-slate-700">
                                            <?= e($startDateObject->format('M d, Y')) ?>
                                            <?php if (!$sameDate): ?>
                                                <span class="mx-1 text-slate-300">→</span>
                                                <?= e($endDateObject->format('M d, Y')) ?>
                                            <?php endif; ?>
                                        </p>
                                        <p class="events-duration mt-1 text-xs text-slate-400">
                                            <?= e(number_format((int) $event['duration_days'])) ?> day<?= (int) $event['duration_days'] === 1 ? '' : 's' ?>
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        <span class="events-status-badge inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-extrabold ring-1 ring-inset <?= e($statusClasses) ?>">
                                            <i class="fa-solid <?= e($statusIcon) ?> text-[10px]"></i>
                                            <?= e(ucfirst($eventTiming)) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 align-top text-right">
                                        <div class="events-action-wrap inline-flex items-center gap-2">
                                            <button type="button"
                                                    data-event="<?= e(json_encode($editData, JSON_UNESCAPED_UNICODE)) ?>"
                                                    onclick="openEditModal(this)"
                                                    title="Edit event"
                                                    class="events-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-600">
                                                <i class="fa-solid fa-pen-to-square text-xs"></i>
                                            </button>

                                            <form method="post" onsubmit="return confirm('Delete this event permanently?');" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="event_id" value="<?= e($event['event_id']) ?>">
                                                <button type="submit" title="Delete event"
                                                        class="events-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600">
                                                    <i class="fa-solid fa-trash-can text-xs"></i>
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

                <div class="events-pagination flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs font-medium text-slate-400">
                        Showing <span class="font-bold text-slate-600"><?= e(number_format($fromRecord)) ?></span>
                        to <span class="font-bold text-slate-600"><?= e(number_format($toRecord)) ?></span>
                        of <span class="font-bold text-slate-600"><?= e(number_format($filteredTotal)) ?></span> events
                    </p>

                    <?php if ($totalPages > 1): ?>
                        <nav class="flex items-center gap-1.5">
                            <a href="?<?= e(eventsQueryString(['page' => max(1, $page - 1)])) ?>"
                               class="grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-sm text-slate-500 transition hover:bg-slate-50 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </a>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            ?>
                            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                                <a href="?<?= e(eventsQueryString(['page' => $pageNumber])) ?>"
                                   class="grid h-9 min-w-9 place-items-center rounded-xl px-2 text-xs font-extrabold transition <?= $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50' ?>">
                                    <?= e($pageNumber) ?>
                                </a>
                            <?php endfor; ?>

                            <a href="?<?= e(eventsQueryString(['page' => min($totalPages, $page + 1)])) ?>"
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

<!-- Create Event Modal -->
<div id="createModal" class="events-modal-scroll fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="events-modal-scroll max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
        <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-5 py-4">
            <div>
                <h2 class="text-lg font-extrabold text-slate-950">Add Event</h2>
                <p class="mt-0.5 text-xs text-slate-500">Create a new event for a farmers market.</p>
            </div>
            <button type="button" onclick="closeModal('createModal')" class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" class="space-y-4 p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600">Event Name *</span>
                    <input type="text" name="event_name" required maxlength="100" placeholder="Example: Weekend Organic Food Fair"
                           class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600">Market *</span>
                    <select name="market_id" required class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="">Select a market</option>
                        <?php foreach ($marketOptions as $market): ?>
                            <option value="<?= e($market['market_id']) ?>">
                                <?= e($market['market_name'] . ' — ' . $market['city_name'] . ', ' . $market['administrative_division']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600">Start Date *</span>
                    <input id="create_start_date" type="date" name="start_date" required
                           class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600">End Date *</span>
                    <input id="create_end_date" type="date" name="end_date" required
                           class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600">
                        Description
                        <span class="font-medium text-slate-400">(Optional)</span>
                    </span>
                    <textarea name="description" maxlength="5000" rows="4" placeholder="Describe the event, activities and important information..."
                              class="w-full resize-none rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"></textarea>
                </label>
            </div>

            <div class="rounded-xl bg-blue-50 px-3.5 py-3 text-xs leading-5 text-blue-800">
                <i class="fa-solid fa-circle-info mr-1"></i>
                The end date may be the same as the start date for a one-day event.
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('createModal')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">Create Event</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Event Modal -->
<div id="editModal" class="events-modal-scroll fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="events-modal-scroll max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
        <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-5 py-4">
            <div>
                <h2 class="text-lg font-extrabold text-slate-950">Edit Event</h2>
                <p class="mt-0.5 text-xs text-slate-500">Update event details, market and schedule.</p>
            </div>
            <button type="button" onclick="closeModal('editModal')" class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" class="space-y-4 p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit_event_id" name="event_id" value="">

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600">Event Name *</span>
                    <input id="edit_event_name" type="text" name="event_name" required maxlength="100"
                           class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600">Market *</span>
                    <select id="edit_market_id" name="market_id" required class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="">Select a market</option>
                        <?php foreach ($marketOptions as $market): ?>
                            <option value="<?= e($market['market_id']) ?>">
                                <?= e($market['market_name'] . ' — ' . $market['city_name'] . ', ' . $market['administrative_division']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600">Start Date *</span>
                    <input id="edit_start_date" type="date" name="start_date" required
                           class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600">End Date *</span>
                    <input id="edit_end_date" type="date" name="end_date" required
                           class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-1.5 block text-xs font-bold text-slate-600">
                        Description
                        <span class="font-medium text-slate-400">(Optional)</span>
                    </span>
                    <textarea id="edit_description" name="description" maxlength="5000" rows="4"
                              class="w-full resize-none rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"></textarea>
                </label>
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('editModal')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">Save Changes</button>
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

    function openCreateModal() {
        var startDate = document.getElementById('create_start_date');
        var endDate = document.getElementById('create_end_date');

        if (startDate && !startDate.value) {
            var today = new Date();
            var timezoneOffset = today.getTimezoneOffset() * 60000;
            var localDate = new Date(today.getTime() - timezoneOffset).toISOString().slice(0, 10);
            startDate.value = localDate;
            endDate.value = localDate;
            endDate.min = localDate;
        }

        showModal('createModal');
    }

    function openEditModal(button) {
        if (!button || !button.dataset.event) {
            return;
        }

        var eventData;

        try {
            eventData = JSON.parse(button.dataset.event);
        } catch (error) {
            alert('Unable to load this event.');
            return;
        }

        document.getElementById('edit_event_id').value = eventData.event_id || '';
        document.getElementById('edit_market_id').value = eventData.market_id || '';
        document.getElementById('edit_event_name').value = eventData.event_name || '';
        document.getElementById('edit_start_date').value = eventData.start_date || '';
        document.getElementById('edit_end_date').value = eventData.end_date || '';
        document.getElementById('edit_end_date').min = eventData.start_date || '';
        document.getElementById('edit_description').value = eventData.description || '';

        showModal('editModal');
    }

    function connectDateFields(startId, endId) {
        var startField = document.getElementById(startId);
        var endField = document.getElementById(endId);

        if (!startField || !endField) {
            return;
        }

        startField.addEventListener('change', function () {
            endField.min = startField.value;
            if (endField.value && endField.value < startField.value) {
                endField.value = startField.value;
            }
        });
    }

    connectDateFields('create_start_date', 'create_end_date');
    connectDateFields('edit_start_date', 'edit_end_date');

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal('createModal');
            closeModal('editModal');
        }
    });

    ['createModal', 'editModal'].forEach(function (id) {
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