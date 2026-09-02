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

if (
    (!isset($pdo) || !($pdo instanceof PDO)) &&
    function_exists('getPDO')
) {
    $pdo = getPDO();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit(
        'Database configuration must create a PDO connection named $pdo.'
    );
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

function contactRedirect(
    string $url = 'contact_messages.php'
): void {
    header('Location: ' . $url);
    exit;
}

function contactFlash(
    string $type,
    string $message
): void {
    $_SESSION['contact_messages_flash'] = array(
        'type' => $type,
        'message' => $message,
    );
}

function contactQueryString(
    array $changes
): string {
    $current = array(
        'q' => isset($_GET['q'])
            ? (string) $_GET['q']
            : '',

        'status' => isset($_GET['status'])
            ? (string) $_GET['status']
            : 'all',

        'page' => isset($_GET['page'])
            ? max(1, (int) $_GET['page'])
            : 1,
    );

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($current[$key]);
        } else {
            $current[$key] = $value;
        }
    }

    return http_build_query($current);
}

function contactInitials(
    string $name
): string {
    $name = trim($name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name);
    $letters = '';

    if (!is_array($parts)) {
        return strtoupper(substr($name, 0, 1));
    }

    foreach (array_slice($parts, 0, 2) as $part) {
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

    return $letters !== ''
        ? $letters
        : 'U';
}

/*
|--------------------------------------------------------------------------
| Contact Messages Table
|--------------------------------------------------------------------------
| This simplified Admin page uses only:
| - New
| - Read
|
| No Reply / Resolved / Reopen / Delete feature is used.
|--------------------------------------------------------------------------
*/

$tableExists = false;
$tableError = '';
$idColumn = 'contact_id';

try {
    $tableCheck = $pdo->query(
        "SHOW TABLES LIKE 'contact_messages'"
    );

    $tableExists =
        (bool) $tableCheck->fetchColumn();

    if (!$tableExists) {
        throw new RuntimeException(
            'The contact_messages table does not exist yet.'
        );
    }

    $columnStatement = $pdo->query(
        "SHOW COLUMNS FROM contact_messages"
    );

    $columns = $columnStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

    $columnNames = array();
    $primaryKey = '';

    foreach ($columns as $column) {
        $field = isset($column['Field'])
            ? (string) $column['Field']
            : '';

        $key = isset($column['Key'])
            ? (string) $column['Key']
            : '';

        if ($field !== '') {
            $columnNames[] = $field;
        }

        if ($key === 'PRI' && $field !== '') {
            $primaryKey = $field;
        }
    }

    if ($primaryKey !== '') {
        $idColumn = $primaryKey;
    } elseif (
        in_array(
            'contact_id',
            $columnNames,
            true
        )
    ) {
        $idColumn = 'contact_id';
    } elseif (
        in_array(
            'message_id',
            $columnNames,
            true
        )
    ) {
        $idColumn = 'message_id';
    } elseif (
        in_array(
            'id',
            $columnNames,
            true
        )
    ) {
        $idColumn = 'id';
    } else {
        throw new RuntimeException(
            'contact_messages needs a primary key.'
        );
    }

    $requiredColumns = array(
        'user_id',
        'name',
        'email',
        'subject',
        'message',
        'status',
        'created_at',
    );

    foreach (
        $requiredColumns as $requiredColumn
    ) {
        if (
            !in_array(
                $requiredColumn,
                $columnNames,
                true
            )
        ) {
            throw new RuntimeException(
                'Missing required column: ' .
                $requiredColumn
            );
        }
    }
} catch (Throwable $exception) {
    $tableExists = false;
    $tableError =
        $exception->getMessage();
}

/*
|--------------------------------------------------------------------------
| Filters + Pagination
|--------------------------------------------------------------------------
*/

$search = isset($_GET['q'])
    ? trim((string) $_GET['q'])
    : '';

$statusFilter = isset($_GET['status'])
    ? strtolower(
        trim((string) $_GET['status'])
    )
    : 'all';

$allowedStatusFilters = array(
    'all',
    'new',
    'read',
);

if (
    !in_array(
        $statusFilter,
        $allowedStatusFilters,
        true
    )
) {
    $statusFilter = 'all';
}

/*
|--------------------------------------------------------------------------
| Fixed Sort Order
|--------------------------------------------------------------------------
| Customer-name sorting was removed. Contact messages are always displayed
| newest first.
|--------------------------------------------------------------------------
*/
$orderBy = 'cm.created_at DESC';

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 10;

$messages = array();

$totalMessages = 0;
$newMessages = 0;
$readMessages = 0;

$filteredTotal = 0;
$totalPages = 1;
$offset = 0;
$showingFrom = 0;
$showingTo = 0;

if ($tableExists) {
    try {
        /*
        | Total = every stored contact message.
        */
        $totalMessages = (int) $pdo
            ->query(
                "SELECT COUNT(*)
                 FROM contact_messages"
            )
            ->fetchColumn();

        /*
        | New = explicitly status new.
        */
        $newMessages = (int) $pdo
            ->query(
                "SELECT COUNT(*)
                 FROM contact_messages
                 WHERE LOWER(status) = 'new'"
            )
            ->fetchColumn();

        /*
        | Read = anything that is not New.
        |
        | This also keeps old rows that may currently contain
        | 'resolved' visible as Read, so no database migration
        | is required after removing the Resolved feature.
        */
        $readMessages = (int) $pdo
            ->query(
                "SELECT COUNT(*)
                 FROM contact_messages
                 WHERE LOWER(status) <> 'new'"
            )
            ->fetchColumn();

        $whereParts = array(
            '1 = 1',
        );

        $params = array();

        if ($search !== '') {
            $whereParts[] =
                '(
                    cm.name LIKE :search_name
                    OR cm.email LIKE :search_email
                    OR cm.subject LIKE :search_subject
                    OR cm.message LIKE :search_message
                )';

            $searchValue =
                '%' . $search . '%';

            $params['search_name'] =
                $searchValue;

            $params['search_email'] =
                $searchValue;

            $params['search_subject'] =
                $searchValue;

            $params['search_message'] =
                $searchValue;
        }

        if ($statusFilter === 'new') {
            $whereParts[] =
                "LOWER(cm.status) = 'new'";
        } elseif ($statusFilter === 'read') {
            $whereParts[] =
                "LOWER(cm.status) <> 'new'";
        }

        $whereSql =
            implode(
                ' AND ',
                $whereParts
            );

        $countStatement =
            $pdo->prepare(
                "SELECT COUNT(*)
                 FROM contact_messages cm
                 WHERE {$whereSql}"
            );

        $countStatement->execute(
            $params
        );

        $filteredTotal =
            (int) $countStatement
                ->fetchColumn();

        $totalPages = max(
            1,
            (int) ceil(
                $filteredTotal /
                $perPage
            )
        );

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset =
            ($page - 1) * $perPage;

        $listSql =
            "SELECT
                cm.`{$idColumn}` AS contact_id,
                cm.user_id,
                cm.name,
                cm.email,
                cm.subject,
                cm.message,
                cm.status,
                cm.created_at,
                u.user_name AS account_name,
                u.role AS account_role
             FROM contact_messages cm
             LEFT JOIN users u
                ON u.user_id = cm.user_id
             WHERE {$whereSql}
             ORDER BY {$orderBy}
             LIMIT :limit_value
             OFFSET :offset_value";

        $listStatement =
            $pdo->prepare($listSql);

        foreach ($params as $name => $value) {
            $listStatement->bindValue(
                ':' . $name,
                $value,
                PDO::PARAM_STR
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

        $messages =
            $listStatement->fetchAll(
                PDO::FETCH_ASSOC
            );

        $showingFrom =
            $filteredTotal > 0
                ? $offset + 1
                : 0;

        $showingTo = min(
            $offset + $perPage,
            $filteredTotal
        );
    } catch (Throwable $exception) {
        $tableError =
            $exception->getMessage();

        $messages = array();
    }
}

/*
|--------------------------------------------------------------------------
| Selected Message
|--------------------------------------------------------------------------
| When Admin opens a New message, it automatically becomes Read.
|--------------------------------------------------------------------------
*/

$selectedMessage = null;

$viewMessageId = isset($_GET['view'])
    ? max(0, (int) $_GET['view'])
    : 0;

if (
    $tableExists &&
    $viewMessageId > 0
) {
    try {
        $detailStatement =
            $pdo->prepare(
                "SELECT
                    cm.`{$idColumn}` AS contact_id,
                    cm.user_id,
                    cm.name,
                    cm.email,
                    cm.subject,
                    cm.message,
                    cm.status,
                    cm.created_at,
                    u.user_name AS account_name,
                    u.phone_number AS account_phone,
                    u.role AS account_role,
                    u.status AS account_status
                 FROM contact_messages cm
                 LEFT JOIN users u
                    ON u.user_id = cm.user_id
                 WHERE cm.`{$idColumn}` = :message_id
                 LIMIT 1"
            );

        $detailStatement->execute(
            array(
                'message_id' =>
                    $viewMessageId,
            )
        );

        $selectedMessage =
            $detailStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$selectedMessage) {
            contactFlash(
                'error',
                'Contact message was not found.'
            );

            contactRedirect();
        }

        $currentStatus =
            strtolower(
                trim(
                    (string)
                    $selectedMessage['status']
                )
            );

        if ($currentStatus === 'new') {
            $markReadStatement =
                $pdo->prepare(
                    "UPDATE contact_messages
                     SET status = 'read'
                     WHERE `{$idColumn}` = :message_id
                       AND LOWER(status) = 'new'"
                );

            $markReadStatement->execute(
                array(
                    'message_id' =>
                        $viewMessageId,
                )
            );

            $selectedMessage['status'] =
                'read';

            $newMessages = max(
                0,
                $newMessages - 1
            );

            $readMessages++;
        } else {
            /*
            | Old resolved/other statuses are displayed
            | as Read in this simplified interface.
            */
            $selectedMessage['status'] =
                'read';
        }
    } catch (Throwable $exception) {
        contactFlash(
            'error',
            $exception->getMessage()
        );

        contactRedirect();
    }
}

/*
|--------------------------------------------------------------------------
| Flash
|--------------------------------------------------------------------------
*/

$flash =
    isset($_SESSION['contact_messages_flash']) &&
    is_array(
        $_SESSION['contact_messages_flash']
    )
        ? $_SESSION['contact_messages_flash']
        : null;

unset(
    $_SESSION['contact_messages_flash']
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>
        Contact Messages | Local Farmers Marketplace
    </title>

    <link rel="stylesheet"
          href="../assets/css/app.css">

    <link rel="stylesheet"
          href="../assets/css/theme.css">

    <link rel="stylesheet"
          href="../assets/vendor/fontawesome/css/all.min.css">

    <link rel="stylesheet"
          href="../assets/css/responsive.css?v=20260823-contact-simple">

    <style>
        html,
        body {
            max-width: 100%;
            overflow-x: hidden !important;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar,
        .contact-admin-scroll::-webkit-scrollbar,
        .contact-modal-scroll::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }

        .contact-admin-scroll,
        .contact-modal-scroll {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .contact-messages-main {
            min-width: 0;
        }

        .contact-stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
        }

        .contact-message-table {
            width: 100%;
            min-width: 980px;
        }

        .contact-message-table th,
        .contact-message-table td {
            vertical-align: middle;
        }

        @media (
            min-width: 640px
        ) and (
            max-width: 1023px
        ) {
            .contact-stats-grid {
                grid-template-columns:
                    repeat(
                        3,
                        minmax(0, 1fr)
                    );
            }
        }

        @media (
            min-width: 1024px
        ) {
            .contact-messages-shell {
                margin-left:
                    176px !important;
            }

            .contact-messages-main {
                padding:
                    14px 18px 24px !important;
            }

            .contact-stats-grid {
                grid-template-columns:
                    repeat(
                        3,
                        minmax(0, 1fr)
                    );

                gap: .75rem;

                margin-bottom:
                    .85rem !important;
            }

            .contact-stat-card {
                min-height: 84px;

                border-radius:
                    14px !important;

                padding:
                    12px 14px !important;
            }

            .contact-stat-card p:first-child {
                font-size:
                    9px !important;

                letter-spacing:
                    .04em !important;
            }

            .contact-stat-card p.text-3xl {
                margin-top:
                    5px !important;

                font-size:
                    20px !important;

                line-height:
                    1 !important;
            }

            .contact-stat-card
            .contact-stat-icon {
                width:
                    38px !important;

                height:
                    38px !important;

                border-radius:
                    10px !important;

                font-size:
                    14px !important;
            }

            .contact-panel {
                border-radius:
                    14px !important;
            }

            .contact-panel-head {
                padding:
                    13px 14px !important;
            }

            .contact-filter-grid {
                display:
                    grid !important;

                grid-template-columns:
                    minmax(250px, 1.7fr)
                    minmax(150px, .7fr)
                    auto !important;

                gap:
                    8px !important;

                align-items:
                    center !important;
            }

            .contact-filter-grid input,
            .contact-filter-grid select,
            .contact-filter-grid button,
            .contact-filter-grid a {
                height:
                    36px !important;

                border-radius:
                    9px !important;

                font-size:
                    10px !important;
            }

            .contact-message-table {
                min-width:
                    0 !important;

                table-layout:
                    fixed;
            }

            .contact-message-table thead {
                font-size:
                    8px !important;
            }

            .contact-message-table th {
                padding-top:
                    8px !important;

                padding-bottom:
                    8px !important;
            }

            .contact-message-table td {
                padding-top:
                    9px !important;

                padding-bottom:
                    9px !important;

                font-size:
                    9px !important;
            }

            .contact-message-table
            th:nth-child(1),
            .contact-message-table
            td:nth-child(1) {
                width: 20%;
            }

            .contact-message-table
            th:nth-child(2),
            .contact-message-table
            td:nth-child(2) {
                width: 18%;
            }

            .contact-message-table
            th:nth-child(3),
            .contact-message-table
            td:nth-child(3) {
                width: 30%;
            }

            .contact-message-table
            th:nth-child(4),
            .contact-message-table
            td:nth-child(4) {
                width: 11%;
            }

            .contact-message-table
            th:nth-child(5),
            .contact-message-table
            td:nth-child(5) {
                width: 13%;
            }

            .contact-message-table
            th:nth-child(6),
            .contact-message-table
            td:nth-child(6) {
                width: 8%;
            }

            .contact-pagination {
                padding:
                    10px 14px !important;
            }

            .contact-pagination p {
                font-size:
                    9px !important;
            }

            .contact-pagination a {
                min-width:
                    30px !important;

                height:
                    30px !important;

                border-radius:
                    7px !important;

                font-size:
                    9px !important;
            }
        }

        @media (
            max-width: 1023px
        ) {
            .contact-messages-shell {
                margin-left:
                    0 !important;
            }

            .contact-filter-grid {
                grid-template-columns:
                    1fr !important;
            }

            .contact-message-table {
                min-width:
                    980px;
            }
        }

        body.fm-compact-nav
        .contact-messages-shell {
            margin-left:
                0 !important;

            width:
                100% !important;

            max-width:
                100% !important;
        }
    </style>
</head>

<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">

<div class="min-h-screen">

    <?php
    require_once
        __DIR__ . '/sidebar.php';
    ?>

    <div class="contact-messages-shell min-h-screen lg:ml-64">

        <?php
        require_once
            __DIR__ . '/header.php';
        ?>

        <main class="contact-messages-main px-4 pb-10 pt-5 sm:px-6 xl:px-7">

            <?php if ($flash): ?>
                <?php
                $flashSuccess =
                    isset($flash['type']) &&
                    $flash['type'] === 'success';

                $flashClass =
                    $flashSuccess
                        ? 'border-green-200 bg-green-50 text-green-800'
                        : 'border-red-200 bg-red-50 text-red-800';

                $flashIcon =
                    $flashSuccess
                        ? 'fa-circle-check'
                        : 'fa-circle-exclamation';
                ?>

                <div id="contactFlash"
                     class="mb-4 flex items-center gap-3 rounded-xl border px-4 py-3 text-sm font-semibold <?php echo e($flashClass); ?>">

                    <i class="fa-solid <?php echo e($flashIcon); ?>"></i>

                    <span class="min-w-0 flex-1">
                        <?php
                        echo e(
                            isset($flash['message'])
                                ? $flash['message']
                                : ''
                        );
                        ?>
                    </span>

                    <button type="button"
                            title="Close"
                            onclick="document.getElementById('contactFlash').remove()"
                            class="grid h-7 w-7 place-items-center rounded-lg hover:bg-black/5">

                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php if ($tableError !== ''): ?>
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">

                    <p class="font-bold">
                        Contact messages could not be loaded.
                    </p>

                    <p class="mt-1 text-xs leading-5 text-red-600">
                        <?php echo e($tableError); ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <section class="contact-stats-grid mb-5">

                <article class="contact-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">

                    <div class="flex items-center justify-between">

                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Total Messages
                            </p>

                            <p class="mt-2 text-3xl font-extrabold text-slate-950">
                                <?php echo e(
                                    number_format(
                                        $totalMessages
                                    )
                                ); ?>
                            </p>
                        </div>

                        <span class="contact-stat-icon grid h-12 w-12 place-items-center rounded-xl bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-envelope"></i>
                        </span>
                    </div>
                </article>

                <article class="contact-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">

                    <div class="flex items-center justify-between">

                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                New
                            </p>

                            <p class="mt-2 text-3xl font-extrabold text-slate-950">
                                <?php echo e(
                                    number_format(
                                        $newMessages
                                    )
                                ); ?>
                            </p>
                        </div>

                        <span class="contact-stat-icon grid h-12 w-12 place-items-center rounded-xl bg-amber-50 text-amber-600">
                            <i class="fa-solid fa-envelope"></i>
                        </span>
                    </div>
                </article>

                <article class="contact-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">

                    <div class="flex items-center justify-between">

                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Read
                            </p>

                            <p class="mt-2 text-3xl font-extrabold text-slate-950">
                                <?php echo e(
                                    number_format(
                                        $readMessages
                                    )
                                ); ?>
                            </p>
                        </div>

                        <span class="contact-stat-icon grid h-12 w-12 place-items-center rounded-xl bg-violet-50 text-violet-600">
                            <i class="fa-solid fa-envelope-open"></i>
                        </span>
                    </div>
                </article>
            </section>

            <!-- Message List -->
            <section class="contact-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

                <div class="contact-panel-head border-b border-slate-100 p-4 sm:p-5">

                    <div class="mb-4">

                        <h1 class="text-lg font-extrabold text-slate-950">
                            Contact Messages
                        </h1>

                        <p class="mt-1 text-xs text-slate-400">
                            Showing
                            <?php echo e(
                                number_format(
                                    $showingFrom
                                )
                            ); ?>
                            –
                            <?php echo e(
                                number_format(
                                    $showingTo
                                )
                            ); ?>
                            of
                            <?php echo e(
                                number_format(
                                    $filteredTotal
                                )
                            ); ?>
                            matching messages
                        </p>
                    </div>

                    <form method="get"
                          action="contact_messages.php"
                          class="contact-filter-grid grid gap-3 lg:grid-cols-[minmax(260px,1fr)_170px_auto]">

                        <label class="relative block">

                            <span class="sr-only">
                                Search messages
                            </span>

                            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                            <input type="search"
                                   name="q"
                                   value="<?php echo e($search); ?>"
                                   placeholder="Search name, email, subject or message..."
                                   class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        </label>

                        <select name="status"
                                class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">

                            <option value="all"
                                <?php echo
                                    $statusFilter === 'all'
                                        ? 'selected'
                                        : '';
                                ?>>
                                All statuses
                            </option>

                            <option value="new"
                                <?php echo
                                    $statusFilter === 'new'
                                        ? 'selected'
                                        : '';
                                ?>>
                                New
                            </option>

                            <option value="read"
                                <?php echo
                                    $statusFilter === 'read'
                                        ? 'selected'
                                        : '';
                                ?>>
                                Read
                            </option>
                        </select>

                        <div class="flex gap-2">

                            <button type="submit"
                                    class="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-sm font-bold text-white transition hover:bg-green-700 lg:flex-none">

                                <i class="fa-solid fa-filter"></i>
                                Filter
                            </button>

                            <a href="contact_messages.php"
                               title="Clear filters"
                               class="grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-800">

                                <i class="fa-solid fa-rotate-left"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <div class="contact-admin-scroll overflow-x-auto">

                    <table class="contact-message-table text-left">

                        <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-400">

                            <tr>
                                <th class="px-5 py-3.5 font-semibold">
                                    Sender
                                </th>

                                <th class="px-4 py-3.5 font-semibold">
                                    Email
                                </th>

                                <th class="px-4 py-3.5 font-semibold">
                                    Subject & Message
                                </th>

                                <th class="px-4 py-3.5 font-semibold">
                                    Status
                                </th>

                                <th class="px-4 py-3.5 font-semibold">
                                    Received
                                </th>

                                <th class="px-5 py-3.5 text-right font-semibold">
                                    Action
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">

                        <?php if (empty($messages)): ?>

                            <tr>
                                <td colspan="6"
                                    class="px-5 py-16 text-center">

                                    <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-green-50 text-green-600">
                                        <i class="fa-regular fa-envelope text-xl"></i>
                                    </span>

                                    <p class="mt-4 text-sm font-bold text-slate-700">
                                        No contact messages found
                                    </p>

                                    <p class="mt-1 text-xs text-slate-400">
                                        New Contact Us submissions will appear here.
                                    </p>
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($messages as $messageRow): ?>

                                <?php
                                $rawStatus =
                                    strtolower(
                                        trim(
                                            (string)
                                            $messageRow['status']
                                        )
                                    );

                                $displayStatus =
                                    $rawStatus === 'new'
                                        ? 'new'
                                        : 'read';

                                if ($displayStatus === 'new') {
                                    $statusClass =
                                        'bg-amber-50 text-amber-700 ring-amber-200';

                                    $statusIcon =
                                        'fa-solid fa-circle';
                                } else {
                                    $statusClass =
                                        'bg-blue-50 text-blue-700 ring-blue-200';

                                    $statusIcon =
                                        'fa-solid fa-envelope-open';
                                }

                                $accountLabel =
                                    !empty($messageRow['user_id'])
                                        ? ucfirst(
                                            (string)
                                            $messageRow['account_role']
                                        ) . ' account'
                                        : 'Guest';

                                $viewUrl =
                                    'contact_messages.php?' .
                                    contactQueryString(
                                        array(
                                            'view' =>
                                                (int)
                                                $messageRow['contact_id'],
                                        )
                                    );
                                ?>

                                <tr class="<?php echo
                                    $displayStatus === 'new'
                                        ? 'bg-amber-50/20'
                                        : '';
                                ?>">

                                    <td class="px-5 py-4">

                                        <div class="flex items-center gap-3">

                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-green-100 text-[10px] font-extrabold text-green-700">

                                                <?php
                                                echo e(
                                                    contactInitials(
                                                        (string)
                                                        $messageRow['name']
                                                    )
                                                );
                                                ?>
                                            </span>

                                            <div class="min-w-0">

                                                <p class="truncate text-sm font-bold text-slate-800">
                                                    <?php echo e(
                                                        $messageRow['name']
                                                    ); ?>
                                                </p>

                                                <p class="mt-0.5 text-[10px] text-slate-400">
                                                    <?php echo e(
                                                        $accountLabel
                                                    ); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-4 py-4">

                                        <p class="truncate text-xs font-medium text-slate-600"
                                           title="<?php echo e(
                                               $messageRow['email']
                                           ); ?>">

                                            <?php echo e(
                                                $messageRow['email']
                                            ); ?>
                                        </p>
                                    </td>

                                    <td class="px-4 py-4">

                                        <p class="truncate text-xs font-bold text-slate-800"
                                           title="<?php echo e(
                                               $messageRow['subject']
                                           ); ?>">

                                            <?php echo e(
                                                $messageRow['subject']
                                            ); ?>
                                        </p>

                                        <p class="mt-1 truncate text-[10px] text-slate-400"
                                           title="<?php echo e(
                                               $messageRow['message']
                                           ); ?>">

                                            <?php echo e(
                                                $messageRow['message']
                                            ); ?>
                                        </p>
                                    </td>

                                    <td class="px-4 py-4">

                                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold capitalize ring-1 ring-inset <?php echo e($statusClass); ?>">

                                            <i class="<?php echo e($statusIcon); ?> text-[8px]"></i>

                                            <?php echo e(
                                                $displayStatus
                                            ); ?>
                                        </span>
                                    </td>

                                    <td class="px-4 py-4">

                                        <p class="text-xs font-semibold text-slate-600">
                                            <?php echo e(
                                                date(
                                                    'M d, Y',
                                                    strtotime(
                                                        (string)
                                                        $messageRow['created_at']
                                                    )
                                                )
                                            ); ?>
                                        </p>

                                        <p class="mt-1 text-[10px] text-slate-400">
                                            <?php echo e(
                                                date(
                                                    'g:i A',
                                                    strtotime(
                                                        (string)
                                                        $messageRow['created_at']
                                                    )
                                                )
                                            ); ?>
                                        </p>
                                    </td>

                                    <td class="px-5 py-4 text-right">

                                        <a href="<?php echo e($viewUrl); ?>"
                                           title="View message"
                                           class="inline-grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700">

                                            <i class="fa-solid fa-eye text-xs"></i>
                                        </a>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>

                    <div class="contact-pagination flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                        <p class="text-xs text-slate-400">
                            Page
                            <?php echo e(
                                number_format($page)
                            ); ?>
                            of
                            <?php echo e(
                                number_format(
                                    $totalPages
                                )
                            ); ?>
                        </p>

                        <div class="flex flex-wrap items-center gap-1">

                            <a href="contact_messages.php?<?php echo e(
                                contactQueryString(
                                    array(
                                        'page' =>
                                            max(
                                                1,
                                                $page - 1
                                            ),
                                    )
                                )
                            ); ?>"
                               class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50 <?php echo
                                   $page <= 1
                                       ? 'pointer-events-none opacity-40'
                                       : '';
                               ?>">

                                <i class="fa-solid fa-chevron-left text-[9px]"></i>
                                Previous
                            </a>

                            <?php
                            $startPage =
                                max(
                                    1,
                                    $page - 2
                                );

                            $endPage =
                                min(
                                    $totalPages,
                                    $page + 2
                                );
                            ?>

                            <?php for (
                                $pageNumber =
                                    $startPage;

                                $pageNumber <=
                                    $endPage;

                                $pageNumber++
                            ): ?>

                                <a href="contact_messages.php?<?php echo e(
                                    contactQueryString(
                                        array(
                                            'page' =>
                                                $pageNumber,
                                        )
                                    )
                                ); ?>"
                                   class="grid h-9 w-9 place-items-center rounded-lg text-xs font-bold <?php echo
                                       $pageNumber === $page
                                           ? 'bg-green-600 text-white'
                                           : 'border border-slate-200 text-slate-500 hover:bg-slate-50';
                                   ?>">

                                    <?php echo e(
                                        $pageNumber
                                    ); ?>
                                </a>

                            <?php endfor; ?>

                            <a href="contact_messages.php?<?php echo e(
                                contactQueryString(
                                    array(
                                        'page' =>
                                            min(
                                                $totalPages,
                                                $page + 1
                                            ),
                                    )
                                )
                            ); ?>"
                               class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50 <?php echo
                                   $page >=
                                   $totalPages
                                       ? 'pointer-events-none opacity-40'
                                       : '';
                               ?>">

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

<!-- Message Detail Modal -->
<?php if ($selectedMessage): ?>

    <?php
    $closeModalUrl =
        'contact_messages.php?' .
        contactQueryString(
            array(
                'view' => null,
            )
        );
    ?>

    <div class="fixed inset-0 z-[90] overflow-y-auto bg-slate-950/60 p-3 backdrop-blur-sm sm:p-4">

        <div class="mx-auto flex min-h-full max-w-2xl items-center justify-center">

            <div class="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">

                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">

                    <div class="min-w-0">

                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-green-600">
                            Contact Message
                        </p>

                        <h2 class="mt-1 truncate text-lg font-black text-slate-950">
                            <?php echo e(
                                $selectedMessage['subject']
                            ); ?>
                        </h2>
                    </div>

                    <a href="<?php echo e(
                        $closeModalUrl
                    ); ?>"
                       title="Close"
                       class="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">

                        <i class="fa-solid fa-xmark"></i>
                    </a>
                </div>

                <div class="contact-modal-scroll max-h-[72vh] overflow-y-auto p-5">

                    <div class="flex flex-wrap items-center gap-2">

                        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700 ring-1 ring-inset ring-blue-200">

                            <i class="fa-solid fa-envelope-open text-[9px]"></i>
                            Read
                        </span>

                        <span class="text-xs text-slate-400">
                            <?php echo e(
                                date(
                                    'M d, Y · g:i A',
                                    strtotime(
                                        (string)
                                        $selectedMessage['created_at']
                                    )
                                )
                            ); ?>
                        </span>
                    </div>

                    <section class="mt-5 grid gap-3 sm:grid-cols-2">

                        <div class="rounded-xl bg-slate-50 p-4">

                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                Sender
                            </p>

                            <p class="mt-1 text-sm font-bold text-slate-800">
                                <?php echo e(
                                    $selectedMessage['name']
                                ); ?>
                            </p>

                            <p class="mt-1 break-all text-xs font-medium text-slate-500">
                                <?php echo e(
                                    $selectedMessage['email']
                                ); ?>
                            </p>
                        </div>

                        <div class="rounded-xl bg-slate-50 p-4">

                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                Account
                            </p>

                            <?php if (
                                !empty(
                                    $selectedMessage['user_id']
                                )
                            ): ?>

                                <p class="mt-1 text-sm font-bold text-slate-800">
                                    <?php echo e(
                                        !empty(
                                            $selectedMessage['account_name']
                                        )
                                            ? $selectedMessage['account_name']
                                            : $selectedMessage['name']
                                    ); ?>
                                </p>

                                <p class="mt-1 text-xs capitalize text-slate-400">
                                    <?php echo e(
                                        (string)
                                        $selectedMessage['account_role']
                                    ); ?>
                                    account
                                </p>

                                <?php if (
                                    !empty(
                                        $selectedMessage['account_phone']
                                    )
                                ): ?>

                                    <p class="mt-1 text-xs text-slate-400">
                                        <?php echo e(
                                            $selectedMessage['account_phone']
                                        ); ?>
                                    </p>

                                <?php endif; ?>

                            <?php else: ?>

                                <p class="mt-1 text-sm font-bold text-slate-800">
                                    Guest
                                </p>

                                <p class="mt-1 text-xs text-slate-400">
                                    Not signed in
                                </p>

                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="mt-4 rounded-2xl border border-slate-200 p-4">

                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                            Subject
                        </p>

                        <p class="mt-2 text-sm font-bold text-slate-900">
                            <?php echo e(
                                $selectedMessage['subject']
                            ); ?>
                        </p>
                    </section>

                    <section class="mt-4 rounded-2xl border border-slate-200 p-4">

                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                            Message
                        </p>

                        <p class="mt-3 whitespace-pre-wrap break-words text-sm leading-7 text-slate-600"><?php echo e(
                            $selectedMessage['message']
                        ); ?></p>
                    </section>
                </div>

                <div class="flex justify-end border-t border-slate-100 px-5 py-4">

                    <a href="<?php echo e(
                        $closeModalUrl
                    ); ?>"
                       class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-5 text-xs font-bold text-slate-600 transition hover:bg-slate-50">

                        Close
                    </a>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

</body>
</html>