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

function redirectToUsers(): void
{
    header('Location: users.php');
    exit;
}

function setFlash(string $type, string $message): void
{
    $_SESSION['users_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $letters = '';

    if (!is_array($parts)) {
        return 'U';
    }

    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }

    return $letters !== '' ? $letters : 'U';
}

function validateCsrfToken(): bool
{
    $submittedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $sessionToken = isset($_SESSION['csrf_token']) ? (string) $_SESSION['csrf_token'] : '';

    return $submittedToken !== ''
        && $sessionToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$allowedRoles = ['vendor', 'user'];
$allowedStatuses = ['active', 'suspended'];

/*
|--------------------------------------------------------------------------
| Status change and delete actions
|--------------------------------------------------------------------------
| This page manages customer/vendor accounts only.
| Admin accounts are intentionally excluded from this page.
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Security token expired. Please try again.');
        redirectToUsers();
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'toggle_status') {
            $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
            $newStatus = isset($_POST['new_status']) ? (string) $_POST['new_status'] : '';

            if ($userId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
                throw new RuntimeException('Invalid user status request.');
            }

            $accountStatement = $pdo->prepare(
                "SELECT role
                 FROM users
                 WHERE user_id = :user_id
                   AND role <> 'admin'
                 LIMIT 1"
            );
            $accountStatement->execute([
                'user_id' => $userId,
            ]);

            $accountRole = $accountStatement->fetchColumn();

            if ($accountRole === false) {
                throw new RuntimeException('Account was not found or cannot be managed from this page.');
            }

            $statement = $pdo->prepare(
                "UPDATE users
                 SET status = :status
                 WHERE user_id = :user_id
                   AND role <> 'admin'"
            );
            $statement->execute([
                'status' => $newStatus,
                'user_id' => $userId,
            ]);

            setFlash(
                'success',
                $newStatus === 'active'
                    ? 'User account activated.'
                    : 'User account suspended.'
            );

            redirectToUsers();
        }

        if ($action === 'delete') {
            $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

            if ($userId <= 0) {
                throw new RuntimeException('Invalid user account.');
            }

            $userStatement = $pdo->prepare(
                "SELECT role
                 FROM users
                 WHERE user_id = :user_id
                   AND role <> 'admin'
                 LIMIT 1"
            );
            $userStatement->execute([
                'user_id' => $userId,
            ]);

            $userRole = $userStatement->fetchColumn();

            if ($userRole === false) {
                throw new RuntimeException('Account was not found or cannot be deleted from this page.');
            }

            $statement = $pdo->prepare(
                "DELETE FROM users
                 WHERE user_id = :user_id
                   AND role <> 'admin'"
            );
            $statement->execute([
                'user_id' => $userId,
            ]);

            setFlash('success', 'User account deleted successfully.');
            redirectToUsers();
        }

        throw new RuntimeException('Invalid account action.');
    } catch (PDOException $exception) {
        setFlash(
            'error',
            'Database operation failed. Please check related records and try again.'
        );
        redirectToUsers();
    } catch (Throwable $exception) {
        setFlash('error', $exception->getMessage());
        redirectToUsers();
    }
}

/*
|--------------------------------------------------------------------------
| Filters, totals and pagination
|--------------------------------------------------------------------------
*/
$search = trim(
    isset($_GET['q'])
        ? (string) $_GET['q']
        : ''
);

$roleFilter = strtolower(
    trim(
        isset($_GET['role'])
            ? (string) $_GET['role']
            : 'all'
    )
);

$statusFilter = strtolower(
    trim(
        isset($_GET['status'])
            ? (string) $_GET['status']
            : 'all'
    )
);

$sort = strtolower(
    trim(
        isset($_GET['sort'])
            ? (string) $_GET['sort']
            : 'newest'
    )
);

if ($roleFilter !== 'all' && !in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = 'all';
}

if ($statusFilter !== 'all' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$sortOptions = [
    'newest' => 'created_at DESC',
    'oldest' => 'created_at ASC',
    'name_asc' => 'user_name ASC',
    'name_desc' => 'user_name DESC',
    'recent_login' => 'last_login DESC',
];
$orderBy = isset($sortOptions[$sort]) ? $sortOptions[$sort] : $sortOptions['newest'];

$whereParts = ["role <> 'admin'"];
$params = [];

if ($search !== '') {
    $whereParts[] = '(
        user_name LIKE :search_name OR
        email LIKE :search_email OR
        phone_number LIKE :search_phone
    )';

    $searchValue = '%' . $search . '%';

    $params['search_name'] =
        $searchValue;

    $params['search_email'] =
        $searchValue;

    $params['search_phone'] =
        $searchValue;
}

if ($roleFilter !== 'all') {
    $whereParts[] = 'role = :role';
    $params['role'] = $roleFilter;
}

if ($statusFilter !== 'all') {
    $whereParts[] = 'status = :status';
    $params['status'] = $statusFilter;
}

$whereSql = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';

$countStatement = $pdo->prepare('SELECT COUNT(*) FROM users' . $whereSql);
$countStatement->execute($params);
$filteredTotal = (int) $countStatement->fetchColumn();

$perPage = 10;
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, min($page, $totalPages));
$offset = ($page - 1) * $perPage;

$listSql = 'SELECT user_id, user_name, phone_number, email, role, status, created_at, last_login
            FROM users' . $whereSql . '
            ORDER BY ' . $orderBy . '
            LIMIT :limit OFFSET :offset';
$listStatement = $pdo->prepare($listSql);

foreach ($params as $key => $value) {
    $listStatement->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$users = $listStatement->fetchAll(PDO::FETCH_ASSOC);

$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'admin'")->fetchColumn();
$activeUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'admin' AND status = 'active'")->fetchColumn();
$customerUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$suspendedUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'admin' AND status = 'suspended'")->fetchColumn();

$flash = isset($_SESSION['users_flash']) && is_array($_SESSION['users_flash'])
    ? $_SESSION['users_flash']
    : null;
unset($_SESSION['users_flash']);

function queryString(array $changes): string
{
    $current = [
        'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
        'role' => isset($_GET['role']) ? (string) $_GET['role'] : 'all',
        'status' => isset($_GET['status']) ? (string) $_GET['status'] : 'all',
        'sort' => isset($_GET['sort']) ? (string) $_GET['sort'] : 'newest',
        'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    ];

    foreach ($changes as $key => $value) {
        $current[$key] = $value;
    }

    return http_build_query($current);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users | Local Farmers Marketplace</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Users Page - Compact Admin UI at Browser Zoom 100%
        |--------------------------------------------------------------------------
        | Matches the compact admin sidebar/header/dashboard layout.
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

        .admin-users-scroll {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .admin-users-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .admin-users-main {
            min-width: 0;
        }

        .admin-users-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .admin-users-stat-card {
            min-width: 0;
        }

        .admin-users-table {
            width: 100%;
            min-width: 980px;
        }

        .admin-users-table th,
        .admin-users-table td {
            vertical-align: middle;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            .admin-users-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .admin-users-shell {
                margin-left: 176px !important;
            }

            .admin-users-main {
                padding: 14px 18px 24px !important;
            }

            .admin-users-stats {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 0.75rem;
                margin-bottom: 0.85rem !important;
            }

            .admin-users-stat-card {
                min-height: 84px;
                border-radius: 14px !important;
                padding: 12px 14px !important;
            }

            .admin-users-stat-card p:first-child {
                font-size: 9px !important;
                letter-spacing: .04em !important;
            }

            .admin-users-stat-card p.text-3xl {
                margin-top: 5px !important;
                font-size: 20px !important;
                line-height: 1 !important;
            }

            .admin-users-stat-card .h-12 {
                width: 38px !important;
                height: 38px !important;
                border-radius: 10px !important;
            }

            .admin-users-stat-card .text-xl {
                font-size: 15px !important;
            }

            .admin-users-panel {
                border-radius: 14px !important;
            }

            .admin-users-panel-head {
                padding: 13px 14px !important;
            }

            .admin-users-title {
                font-size: 14px !important;
            }

            .admin-users-subtitle {
                margin-top: 3px !important;
                font-size: 9px !important;
            }

            .admin-users-filter-grid {
                grid-template-columns:
                    minmax(240px, 1.55fr)
                    minmax(120px, .7fr)
                    minmax(120px, .7fr)
                    minmax(130px, .8fr)
                    auto !important;
                gap: 8px !important;
            }

            .admin-users-filter-grid input,
            .admin-users-filter-grid select,
            .admin-users-filter-grid button,
            .admin-users-filter-grid a {
                height: 36px !important;
                border-radius: 9px !important;
                font-size: 10px !important;
            }

            .admin-users-filter-grid input {
                padding-left: 34px !important;
                padding-right: 10px !important;
            }

            .admin-users-filter-grid .fa-magnifying-glass {
                left: 12px !important;
                font-size: 10px !important;
            }

            .admin-users-table {
                min-width: 0;
                table-layout: fixed;
            }

            .admin-users-table thead {
                font-size: 8px !important;
            }

            .admin-users-table th {
                padding-top: 8px !important;
                padding-bottom: 8px !important;
            }

            .admin-users-table td {
                padding-top: 9px !important;
                padding-bottom: 9px !important;
                font-size: 9px !important;
            }

            .admin-users-table th:nth-child(1),
            .admin-users-table td:nth-child(1) {
                width: 21%;
            }

            .admin-users-table th:nth-child(2),
            .admin-users-table td:nth-child(2) {
                width: 18%;
            }

            .admin-users-table th:nth-child(3),
            .admin-users-table td:nth-child(3) {
                width: 9%;
            }

            .admin-users-table th:nth-child(4),
            .admin-users-table td:nth-child(4) {
                width: 10%;
            }

            .admin-users-table th:nth-child(5),
            .admin-users-table td:nth-child(5) {
                width: 11%;
            }

            .admin-users-table th:nth-child(6),
            .admin-users-table td:nth-child(6) {
                width: 19%;
            }

            .admin-users-table th:nth-child(7),
            .admin-users-table td:nth-child(7) {
                width: 12%;
            }

            .admin-users-table .admin-user-avatar {
                width: 30px !important;
                height: 30px !important;
                font-size: 9px !important;
            }

            .admin-users-table .admin-user-name {
                font-size: 10px !important;
            }

            .admin-users-table .admin-user-id,
            .admin-users-table .admin-user-phone,
            .admin-users-table .admin-user-date {
                font-size: 8px !important;
            }

            .admin-users-table .admin-user-email {
                font-size: 9px !important;
            }

            .admin-users-table .admin-user-badge {
                border-radius: 6px !important;
                padding: 3px 7px !important;
                font-size: 7px !important;
            }

            .admin-users-table .admin-user-action {
                width: 30px !important;
                height: 30px !important;
                border-radius: 7px !important;
            }

            .admin-users-table .admin-user-action i {
                font-size: 9px !important;
            }

            .admin-users-pagination {
                padding: 10px 14px !important;
            }

            .admin-users-pagination p {
                font-size: 9px !important;
            }

            .admin-users-pagination a {
                width: 30px !important;
                min-width: 30px !important;
                height: 30px !important;
                border-radius: 7px !important;
                font-size: 9px !important;
            }
        }

        @media (max-width: 1023px) {
            .admin-users-table {
                min-width: 1050px;
            }
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>
<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">
<div class="min-h-screen">
    <?php require_once './sidebar.php'; ?>

    <div class="admin-users-shell min-h-screen lg:ml-64">
        <?php require_once './header.php'; ?>

        <main class="admin-users-main px-4 pb-10 pt-5 sm:px-6 xl:px-7">
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

            <section class="admin-users-stats mb-5">
                <article class="admin-users-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total Accounts</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalUsers)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-users text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="admin-users-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Active Accounts</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($activeUsers)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
                            <i class="fa-solid fa-user-check text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="admin-users-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Customers</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($customerUsers)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-user-group text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="admin-users-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Suspended</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($suspendedUsers)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-red-50 text-red-600">
                            <i class="fa-solid fa-user-lock text-xl"></i>
                        </div>
                    </div>
                </article>
            </section>

            <section class="admin-users-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
                <div class="admin-users-panel-head border-b border-slate-100 p-4 sm:p-5">

                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 class="admin-users-title text-lg font-extrabold text-slate-950">
                                User Accounts
                            </h1>

                            <p class="admin-users-subtitle mt-1 text-xs text-slate-400">
                                Manage customer and vendor login accounts.
                            </p>
                        </div>

                    </div>

                    <form method="get" action="users.php" class="admin-users-filter-grid grid gap-3 lg:grid-cols-[minmax(260px,1fr)_170px_170px_170px_auto]">
                        <label class="relative block">
                            <span class="sr-only">Search users</span>
                            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email or phone..."
                                   class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        </label>

                        <select name="role" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                            <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>All roles</option>
                            <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>Users</option>
                            <option value="vendor" <?= $roleFilter === 'vendor' ? 'selected' : '' ?>>Vendors</option>
                        </select>

                        <select name="status" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                        </select>

                        <select name="sort" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                            <option value="recent_login" <?= $sort === 'recent_login' ? 'selected' : '' ?>>Recent login</option>
                        </select>

                        <div class="flex gap-2">
                            <button type="submit" class="h-11 flex-1 rounded-xl bg-green-600 px-4 text-sm font-bold text-white transition hover:bg-green-700 lg:flex-none">
                                Filter
                            </button>
                            <a href="users.php" title="Clear filters" class="grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-800">
                                <i class="fa-solid fa-rotate-left"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <div class="admin-users-scroll overflow-x-auto">
                    <table class="admin-users-table w-full min-w-[1050px] text-left">
                        <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="px-5 py-3.5 font-semibold">User</th>
                                <th class="px-4 py-3.5 font-semibold">Contact</th>
                                <th class="px-4 py-3.5 font-semibold">Role</th>
                                <th class="px-4 py-3.5 font-semibold">Status</th>
                                <th class="px-4 py-3.5 font-semibold">Joined</th>
                                <th class="px-4 py-3.5 font-semibold">Last Login</th>
                                <th class="px-5 py-3.5 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php if (!$users): ?>
                            <tr>
                                <td colspan="7" class="px-5 py-16 text-center">
                                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-100 text-slate-400">
                                        <i class="fa-solid fa-user-slash text-xl"></i>
                                    </div>
                                    <p class="mt-3 text-sm font-bold text-slate-700">No users found</p>
                                    <p class="mt-1 text-xs text-slate-400">Change the filters to find another account.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <?php
                                $userId = (int) $user['user_id'];
                                $userName = (string) $user['user_name'];
                                $userRole = (string) $user['role'];
                                $userStatus = (string) $user['status'];

                                $roleClasses = [
                                    'vendor' => 'bg-amber-50 text-amber-700 ring-amber-200',
                                    'user' => 'bg-blue-50 text-blue-700 ring-blue-200',
                                ];
                                $roleClass = isset($roleClasses[$userRole]) ? $roleClasses[$userRole] : 'bg-slate-50 text-slate-700 ring-slate-200';
                                $statusClass = $userStatus === 'active'
                                    ? 'bg-green-50 text-green-700 ring-green-200'
                                    : 'bg-red-50 text-red-700 ring-red-200';
                                ?>
                                <tr class="transition hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="admin-user-avatar grid h-10 w-10 shrink-0 place-items-center rounded-full bg-gradient-to-br from-green-100 to-lime-100 text-xs font-extrabold text-green-700">
                                                <?= e(initials($userName)) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="admin-user-name truncate text-sm font-bold text-slate-800"><?= e($userName) ?></p>
                                                <p class="admin-user-id mt-0.5 text-[11px] text-slate-400">ID #<?= e($userId) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <p class="admin-user-email text-xs font-semibold text-slate-700"><?= e($user['email']) ?></p>
                                        <p class="admin-user-phone mt-1 text-[11px] text-slate-400"><?= e($user['phone_number']) ?></p>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="admin-user-badge inline-flex rounded-lg px-2.5 py-1 text-[10px] font-bold capitalize ring-1 ring-inset <?= e($roleClass) ?>">
                                            <?= e($userRole) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="admin-user-badge inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[10px] font-bold capitalize ring-1 ring-inset <?= e($statusClass) ?>">
                                            <span class="h-1.5 w-1.5 rounded-full <?= $userStatus === 'active' ? 'bg-green-500' : 'bg-red-500' ?>"></span>
                                            <?= e($userStatus) ?>
                                        </span>
                                    </td>
                                    <td class="admin-user-date px-4 py-4 text-xs text-slate-500">
                                        <?= e(date('M j, Y', strtotime((string) $user['created_at']))) ?>
                                    </td>
                                    <td class="admin-user-date px-4 py-4 text-xs text-slate-500">
                                        <?= $user['last_login'] ? e(date('M j, Y g:i A', strtotime((string) $user['last_login']))) : 'Never' ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex justify-end gap-2">
                                            <form method="post" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= e($userId) ?>">
                                                <input type="hidden" name="new_status" value="<?= $userStatus === 'active' ? 'suspended' : 'active' ?>">
                                                <button type="submit"
                                                        title="<?= $userStatus === 'active' ? 'Suspend user' : 'Activate user' ?>"
                                                        
                                                        class="admin-user-action grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:border-amber-200 hover:bg-amber-50 hover:text-amber-700 disabled:cursor-not-allowed disabled:opacity-40">
                                                    <i class="fa-solid <?= $userStatus === 'active' ? 'fa-user-lock' : 'fa-user-check' ?> text-xs"></i>
                                                </button>
                                            </form>

                                            <form method="post" class="inline" onsubmit="return confirm('Delete this account permanently? Related vendor information may also be deleted.');">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="user_id" value="<?= e($userId) ?>">
                                                <button type="submit"
                                                        title="Delete user"
                                                        
                                                        class="admin-user-action grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-40">
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

                <div class="admin-users-pagination flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-slate-500">
                        Showing
                        <span class="font-bold text-slate-700"><?= $filteredTotal > 0 ? e($offset + 1) : '0' ?></span>
                        to
                        <span class="font-bold text-slate-700"><?= e(min($offset + $perPage, $filteredTotal)) ?></span>
                        of
                        <span class="font-bold text-slate-700"><?= e($filteredTotal) ?></span>
                        results
                    </p>

                    <?php if ($totalPages > 1): ?>
                        <nav class="flex items-center gap-1" aria-label="Pagination">
                            <a href="?<?= e(queryString(['page' => max(1, $page - 1)])) ?>"
                               class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-sm text-slate-500 transition hover:bg-slate-50 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </a>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                            ?>
                                <a href="?<?= e(queryString(['page' => $pageNumber])) ?>"
                                   class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-xs font-bold transition <?= $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
                                    <?= e($pageNumber) ?>
                                </a>
                            <?php endfor; ?>

                            <a href="?<?= e(queryString(['page' => min($totalPages, $page + 1)])) ?>"
                               class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-sm text-slate-500 transition hover:bg-slate-50 <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>">
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </a>
                        </nav>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

</body>
</html>