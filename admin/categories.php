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

function redirectToCategories(): void
{
    header('Location: categories.php');
    exit;
}

function setCategoriesFlash(string $type, string $message): void
{
    $_SESSION['categories_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function validateCategoriesCsrfToken(): bool
{
    $submittedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $sessionToken = isset($_SESSION['csrf_token']) ? (string) $_SESSION['csrf_token'] : '';

    return $submittedToken !== ''
        && $sessionToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}

function categoriesQueryString(array $changes): string
{
    $current = [
        'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
        'market' => isset($_GET['market']) ? (int) $_GET['market'] : 0,
        'sort' => isset($_GET['sort']) ? (string) $_GET['sort'] : 'newest',
        'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    ];

    foreach ($changes as $key => $value) {
        $current[$key] = $value;
    }

    return http_build_query($current);
}

function validateCategoryCreateFields(PDO $pdo, array $source): array
{
    $categoryName = trim(isset($source['category_name']) ? (string) $source['category_name'] : '');
    $description = trim(isset($source['description']) ? (string) $source['description'] : '');

    $marketIds = isset($source['market_ids']) && is_array($source['market_ids'])
        ? $source['market_ids']
        : [];

    $marketIds = array_values(array_unique(array_filter(
        array_map('intval', $marketIds),
        static function ($marketId): bool {
            return $marketId > 0;
        }
    )));

    if ($categoryName === '' || count($marketIds) === 0) {
        throw new RuntimeException('Enter a category name and select at least one market.');
    }

    if (mb_strlen($categoryName) > 100) {
        throw new RuntimeException('Category name cannot be longer than 100 characters.');
    }

    $placeholders = implode(',', array_fill(0, count($marketIds), '?'));
    $marketCheck = $pdo->prepare(
        "SELECT market_id
         FROM markets
         WHERE market_id IN ($placeholders)"
    );
    $marketCheck->execute($marketIds);

    $validMarketIds = array_map('intval', $marketCheck->fetchAll(PDO::FETCH_COLUMN));

    sort($validMarketIds);
    $requestedMarketIds = $marketIds;
    sort($requestedMarketIds);

    if ($validMarketIds !== $requestedMarketIds) {
        throw new RuntimeException('One or more selected markets could not be found.');
    }

    return [
        'category_name' => $categoryName,
        'market_ids' => $marketIds,
        'description' => $description,
    ];
}

function validateCategoryFields(PDO $pdo, array $source, int $excludeCategoryId = 0): array
{
    $categoryName = trim(isset($source['category_name']) ? (string) $source['category_name'] : '');
    $marketId = isset($source['market_id']) ? (int) $source['market_id'] : 0;
    $description = trim(isset($source['description']) ? (string) $source['description'] : '');

    if ($categoryName === '' || $marketId <= 0) {
        throw new RuntimeException('Please complete all required category fields.');
    }

    if (mb_strlen($categoryName) > 100) {
        throw new RuntimeException('Category name cannot be longer than 100 characters.');
    }

    $marketCheck = $pdo->prepare('SELECT COUNT(*) FROM markets WHERE market_id = :market_id');
    $marketCheck->execute(['market_id' => $marketId]);

    if ((int) $marketCheck->fetchColumn() === 0) {
        throw new RuntimeException('The selected market was not found.');
    }

    $duplicateSql = 'SELECT COUNT(*)
                     FROM categories
                     WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(:category_name))
                       AND market_id = :market_id';
    $duplicateParams = [
        'category_name' => $categoryName,
        'market_id' => $marketId,
    ];

    if ($excludeCategoryId > 0) {
        $duplicateSql .= ' AND category_id <> :category_id';
        $duplicateParams['category_id'] = $excludeCategoryId;
    }

    $duplicateCheck = $pdo->prepare($duplicateSql);
    $duplicateCheck->execute($duplicateParams);

    if ((int) $duplicateCheck->fetchColumn() > 0) {
        throw new RuntimeException('This category already exists in the selected market.');
    }

    return [
        'category_name' => $categoryName,
        'market_id' => $marketId,
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
    if (!validateCategoriesCsrfToken()) {
        setCategoriesFlash('error', 'Security token expired. Please try again.');
        redirectToCategories();
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'create') {
            $category = validateCategoryCreateFields($pdo, $_POST);

            $duplicateCheck = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM categories
                 WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(:category_name))
                   AND market_id = :market_id'
            );

            $insertStatement = $pdo->prepare(
                'INSERT INTO categories (category_name, market_id, description)
                 VALUES (:category_name, :market_id, :description)'
            );

            $createdCount = 0;
            $skippedCount = 0;

            $pdo->beginTransaction();

            try {
                foreach ($category['market_ids'] as $marketId) {
                    $duplicateCheck->execute([
                        'category_name' => $category['category_name'],
                        'market_id' => $marketId,
                    ]);

                    if ((int) $duplicateCheck->fetchColumn() > 0) {
                        $skippedCount++;
                        continue;
                    }

                    $insertStatement->execute([
                        'category_name' => $category['category_name'],
                        'market_id' => $marketId,
                        'description' => $category['description'],
                    ]);

                    $createdCount++;
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            if ($createdCount === 0) {
                throw new RuntimeException(
                    'This category already exists in all selected markets.'
                );
            }

            $message = 'Category created in ' . number_format($createdCount) . ' market(s).';

            if ($skippedCount > 0) {
                $message .= ' ' . number_format($skippedCount) . ' existing market assignment(s) were skipped.';
            }

            setCategoriesFlash('success', $message);
            redirectToCategories();
        }

        if ($action === 'update') {
            $categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

            if ($categoryId <= 0) {
                throw new RuntimeException('Invalid category selected.');
            }

            $exists = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE category_id = :category_id');
            $exists->execute(['category_id' => $categoryId]);

            if ((int) $exists->fetchColumn() === 0) {
                throw new RuntimeException('Category not found.');
            }

            $category = validateCategoryFields($pdo, $_POST, $categoryId);
            $category['category_id'] = $categoryId;

            $statement = $pdo->prepare(
                'UPDATE categories
                 SET category_name = :category_name,
                     market_id = :market_id,
                     description = :description
                 WHERE category_id = :category_id'
            );
            $statement->execute($category);

            setCategoriesFlash('success', 'Category updated successfully.');
            redirectToCategories();
        }

        if ($action === 'delete') {
            $categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

            if ($categoryId <= 0) {
                throw new RuntimeException('Invalid category selected.');
            }

            $productCountStatement = $pdo->prepare(
                'SELECT COUNT(*) FROM products WHERE category_id = :category_id'
            );
            $productCountStatement->execute(['category_id' => $categoryId]);
            $productCount = (int) $productCountStatement->fetchColumn();

            if ($productCount > 0) {
                throw new RuntimeException(
                    'This category contains ' . number_format($productCount) .
                    ' product(s). Move or delete those products before deleting the category.'
                );
            }

            $statement = $pdo->prepare('DELETE FROM categories WHERE category_id = :category_id');
            $statement->execute(['category_id' => $categoryId]);

            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Category not found.');
            }

            setCategoriesFlash('success', 'Category deleted successfully.');
            redirectToCategories();
        }

        throw new RuntimeException('Unsupported category action.');
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            setCategoriesFlash('error', 'This category already exists in the selected market.');
        } else {
            error_log('Admin category database error: ' . $exception->getMessage());
            setCategoriesFlash('error', 'The database could not complete the category action.');
        }
        redirectToCategories();
    } catch (Throwable $exception) {
        setCategoriesFlash('error', $exception->getMessage());
        redirectToCategories();
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

$sort = strtolower(
    trim(
        isset($_GET['sort'])
            ? (string) $_GET['sort']
            : 'newest'
    )
);

$allowedSorts = [
    'newest' => 'c.created_at DESC, c.category_id DESC',
    'oldest' => 'c.created_at ASC, c.category_id ASC',
    'name_asc' => 'c.category_name ASC',
    'name_desc' => 'c.category_name DESC',
    'market_asc' => 'm.market_name ASC, c.category_name ASC',
    'products_desc' => 'product_count DESC, c.category_name ASC',
];

if (!isset($allowedSorts[$sort])) {
    $sort = 'newest';
}

$orderBy = $allowedSorts[$sort];
$whereParts = [];
$params = [];

if ($search !== '') {
    $whereParts[] = '(
        LOWER(TRIM(c.category_name)) LIKE LOWER(TRIM(:search_category)) OR
        LOWER(TRIM(c.description)) LIKE LOWER(TRIM(:search_description)) OR
        LOWER(TRIM(m.market_name)) LIKE LOWER(TRIM(:search_market)) OR
        LOWER(TRIM(ci.city_name)) LIKE LOWER(TRIM(:search_city))
    )';

    $searchValue = '%' . trim($search) . '%';

    $params['search_category'] = $searchValue;
    $params['search_description'] = $searchValue;
    $params['search_market'] = $searchValue;
    $params['search_city'] = $searchValue;
}

if ($selectedMarket > 0) {
    $whereParts[] = 'c.market_id = :market_id';
    $params['market_id'] = $selectedMarket;
}

$whereSql = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';

$countSql = 'SELECT COUNT(*)
             FROM categories c
             INNER JOIN markets m ON m.market_id = c.market_id
             INNER JOIN cities ci ON ci.city_id = m.city_id'
             . $whereSql;
$countStatement = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $countStatement->bindValue(':' . $key, $value, $key === 'market_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStatement->execute();
$filteredTotal = (int) $countStatement->fetchColumn();

$perPage = 10;
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, min($page, $totalPages));
$offset = ($page - 1) * $perPage;

$listSql = 'SELECT
                c.category_id,
                c.category_name,
                c.market_id,
                c.description,
                c.created_at,
                c.updated_at,
                m.market_name,
                m.address AS market_address,
                ci.city_name,
                ci.administrative_division,
                COUNT(p.product_id) AS product_count
            FROM categories c
            INNER JOIN markets m ON m.market_id = c.market_id
            INNER JOIN cities ci ON ci.city_id = m.city_id
            LEFT JOIN products p ON p.category_id = c.category_id'
            . $whereSql . '
            GROUP BY
                c.category_id,
                c.category_name,
                c.market_id,
                c.description,
                c.created_at,
                c.updated_at,
                m.market_name,
                m.address,
                ci.city_name,
                ci.administrative_division
            ORDER BY ' . $orderBy . '
            LIMIT :limit OFFSET :offset';

$listStatement = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStatement->bindValue(':' . $key, $value, $key === 'market_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$categories = $listStatement->fetchAll(PDO::FETCH_ASSOC);

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

$totalCategories = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
$marketsWithCategories = (int) $pdo->query('SELECT COUNT(DISTINCT market_id) FROM categories')->fetchColumn();
$totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$emptyCategories = (int) $pdo->query(
    'SELECT COUNT(*)
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.category_id
     WHERE p.product_id IS NULL'
)->fetchColumn();

$flash = isset($_SESSION['categories_flash']) && is_array($_SESSION['categories_flash'])
    ? $_SESSION['categories_flash']
    : null;
unset($_SESSION['categories_flash']);

$fromRecord = $filteredTotal > 0 ? $offset + 1 : 0;
$toRecord = min($offset + $perPage, $filteredTotal);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories | Local Farmers Marketplace</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Categories Page - Compact Production Admin UI
        |--------------------------------------------------------------------------
        | - Browser zoom 100% target
        | - no visible scrollbars
        | - compact KPI cards/filter/table
        | - no Category ID shown in list
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

        .categories-scroll-hidden,
        .categories-modal-scroll {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .categories-scroll-hidden::-webkit-scrollbar,
        .categories-modal-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .categories-main {
            min-width: 0;
        }

        .categories-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
        }

        .categories-table {
            width: 100%;
            min-width: 760px;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            .categories-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .categories-shell {
                margin-left: 176px !important;
            }

            .categories-main {
                padding: 12px 14px 22px !important;
            }

            .categories-stats {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: .65rem;
                margin-bottom: .7rem !important;
            }

            .categories-stat-card {
                min-height: 80px;
                padding: 11px 13px !important;
                border-radius: 14px !important;
            }

            .categories-stat-card p:first-child {
                font-size: 9px !important;
                letter-spacing: .04em !important;
            }

            .categories-stat-card p.text-3xl {
                margin-top: 5px !important;
                font-size: 20px !important;
                line-height: 1 !important;
            }

            .categories-stat-card .h-12 {
                width: 38px !important;
                height: 38px !important;
                border-radius: 10px !important;
            }

            .categories-stat-card .text-xl {
                font-size: 15px !important;
            }

            .categories-filter-panel,
            .categories-list-panel {
                border-radius: 14px !important;
            }

            .categories-filter-panel {
                padding: 10px 12px !important;
                margin-bottom: .65rem !important;
            }

            .categories-filter-form {
                grid-template-columns:
                    minmax(260px, 1.45fr)
                    minmax(160px, .72fr)
                    minmax(155px, .72fr)
                    auto !important;
                gap: 8px !important;
            }

            .categories-filter-control,
            .categories-filter-button,
            .categories-clear-button {
                height: 34px !important;
                border-radius: 9px !important;
                font-size: 9px !important;
            }

            .categories-search-input {
                padding-left: 34px !important;
                padding-right: 10px !important;
            }

            .categories-search-icon {
                left: 12px !important;
                font-size: 10px !important;
            }

            .categories-filter-actions {
                gap: 6px !important;
            }

            .categories-list-head {
                padding: 10px 13px !important;
            }

            .categories-list-title {
                font-size: 14px !important;
            }

            .categories-list-subtitle {
                margin-top: 3px !important;
                font-size: 9px !important;
            }

            .categories-add-button {
                border-radius: 9px !important;
                padding: 8px 12px !important;
                font-size: 10px !important;
            }

            .categories-table {
                min-width: 0 !important;
                table-layout: fixed;
            }

            .categories-table thead {
                font-size: 8px !important;
            }

            .categories-table th {
                padding: 7px 8px !important;
            }

            .categories-table td {
                padding: 8px !important;
                font-size: 8px !important;
                vertical-align: middle;
            }

            .categories-table th:nth-child(1),
            .categories-table td:nth-child(1) { width: 22%; }

            .categories-table th:nth-child(2),
            .categories-table td:nth-child(2) { width: 24%; }

            .categories-table th:nth-child(3),
            .categories-table td:nth-child(3) { width: 25%; }

            .categories-table th:nth-child(4),
            .categories-table td:nth-child(4) { width: 10%; }

            .categories-table th:nth-child(5),
            .categories-table td:nth-child(5) { width: 11%; }

            .categories-table th:nth-child(6),
            .categories-table td:nth-child(6) { width: 8%; }

            .categories-icon {
                width: 30px !important;
                height: 30px !important;
                border-radius: 8px !important;
            }

            .categories-name {
                font-size: 10px !important;
            }

            .categories-market-name {
                font-size: 9px !important;
            }

            .categories-market-city,
            .categories-description,
            .categories-updated {
                font-size: 8px !important;
                line-height: 1.4 !important;
            }

            .categories-product-badge {
                min-width: 30px !important;
                border-radius: 7px !important;
                padding: 4px 6px !important;
                font-size: 8px !important;
            }

            .categories-action-wrap {
                display: inline-flex !important;
                flex-wrap: nowrap !important;
                gap: 4px !important;
            }

            .categories-action-button {
                width: 26px !important;
                height: 26px !important;
                min-width: 26px !important;
                flex: 0 0 26px !important;
                border-radius: 7px !important;
            }

            .categories-action-button i {
                font-size: 8px !important;
            }

            .categories-pagination {
                padding: 10px 14px !important;
            }

            .categories-pagination p,
            .categories-pagination a {
                font-size: 9px !important;
            }

            .categories-pagination a {
                width: 30px !important;
                min-width: 30px !important;
                height: 30px !important;
                border-radius: 7px !important;
            }
        }

        @media (max-width: 1023px) {
            .categories-table {
                min-width: 760px;
            }
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>
<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">
<div class="min-h-screen">
    <?php require_once'./sidebar.php'; ?>

    <div class="categories-shell min-h-screen lg:ml-64">
        <?php require_once'./header.php'; ?>

        <main class="categories-main px-4 pb-10 pt-5 sm:px-6 xl:px-7">
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
                        <p class="mt-1 text-xs leading-5 text-amber-800">Every category must belong to a market. Add at least one market before creating categories.</p>
                    </div>
                    <a href="markets.php" class="ml-auto shrink-0 rounded-xl bg-amber-600 px-3 py-2 text-xs font-bold text-white hover:bg-amber-700">Go to Markets</a>
                </div>
            <?php endif; ?>

            <section class="categories-stats mb-5">
                <article class="categories-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total Categories</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalCategories)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-tags text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="categories-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Markets Covered</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($marketsWithCategories)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-store text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="categories-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Listed Products</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalProducts)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-amber-50 text-amber-600">
                            <i class="fa-solid fa-basket-shopping text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="categories-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Empty Categories</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($emptyCategories)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-violet-50 text-violet-600">
                            <i class="fa-solid fa-box-open text-xl"></i>
                        </div>
                    </div>
                </article>
            </section>

            <!-- Search / Filter ABOVE Category Table -->
            <section class="categories-filter-panel mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-card sm:p-5">

                <form method="get"
                      action="categories.php"
                      class="categories-filter-form grid gap-3 lg:grid-cols-[minmax(0,1fr)_240px_190px_auto]">

                    <label class="relative block">
                        <i class="categories-search-icon fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                        <input type="search"
                               name="q"
                               value="<?= e($search) ?>"
                               placeholder="Search category, market or city..."
                               class="categories-filter-control categories-search-input h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                    </label>

                    <select name="market"
                            class="categories-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-100">

                        <option value="0">All Markets</option>

                        <?php foreach ($marketOptions as $market): ?>
                            <option value="<?= e($market['market_id']) ?>"
                                <?= $selectedMarket === (int) $market['market_id'] ? 'selected' : '' ?>>

                                <?= e($market['market_name'] . ' — ' . $market['city_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="sort"
                            class="categories-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-100">

                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
                        <option value="market_asc" <?= $sort === 'market_asc' ? 'selected' : '' ?>>Market A–Z</option>
                        <option value="products_desc" <?= $sort === 'products_desc' ? 'selected' : '' ?>>Most Products</option>
                    </select>

                    <div class="categories-filter-actions flex gap-2">

                        <button type="submit"
                                class="categories-filter-button inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-bold text-white transition hover:bg-green-700">

                            <i class="fa-solid fa-filter text-xs"></i>
                            Filter
                        </button>

                        <a href="categories.php"
                           class="categories-clear-button inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700">

                            <i class="fa-solid fa-rotate-left text-xs"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </section>

            <section class="categories-list-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

                <div class="categories-list-head flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h2 class="categories-list-title text-lg font-extrabold text-slate-950">
                            Category List
                        </h2>

                        <p class="categories-list-subtitle mt-1 text-xs text-slate-400">
                            Showing
                            <?= e(number_format($fromRecord)) ?>
                            to
                            <?= e(number_format($toRecord)) ?>
                            of
                            <?= e(number_format($filteredTotal)) ?>
                            category(s)
                        </p>
                    </div>

                    <button type="button"
                            onclick="openCreateModal()"
                            class="categories-add-button inline-flex w-fit items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-green-700 <?= count($marketOptions) === 0 ? 'cursor-not-allowed opacity-50' : '' ?>"
                            <?= count($marketOptions) === 0 ? 'disabled' : '' ?>>

                        <i class="fa-solid fa-plus text-xs"></i>
                        Add Category
                    </button>
                </div>

                <div class="categories-scroll-hidden overflow-x-auto">
                    <table class="categories-table min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50/80">
                        <tr>
                            <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Category</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Market</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Description</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Products</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Updated</th>
                            <th class="px-5 py-3.5 text-right text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Actions</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                        <?php if (!$categories): ?>
                            <tr>
                                <td colspan="6" class="px-5 py-16 text-center">
                                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                                        <i class="fa-solid fa-tags text-xl"></i>
                                    </div>
                                    <p class="mt-3 text-sm font-extrabold text-slate-700">No categories found</p>
                                    <p class="mt-1 text-xs text-slate-400">Try changing the filters or add a new category.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($categories as $category): ?>
                                <?php
                                $categoryId = (int) $category['category_id'];
                                $productCount = (int) $category['product_count'];
                                $categoryJson = json_encode([
                                    'category_id' => $categoryId,
                                    'category_name' => (string) $category['category_name'],
                                    'market_id' => (int) $category['market_id'],
                                    'description' => (string) $category['description'],
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                                if ($categoryJson === false) {
                                    $categoryJson = '{}';
                                }
                                $descriptionPreview = mb_strlen((string) $category['description']) > 90
                                    ? mb_substr((string) $category['description'], 0, 90) . '…'
                                    : (string) $category['description'];
                                $updatedTimestamp = strtotime((string) $category['updated_at']);
                                $updatedLabel = $updatedTimestamp === false ? '—' : date('M j, Y', $updatedTimestamp);
                                ?>
                                <tr class="transition hover:bg-slate-50/70">
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="categories-icon grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600">
                                                <i class="fa-solid fa-tag text-sm"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="categories-name truncate text-sm font-extrabold text-slate-900"><?= e($category['category_name']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="categories-market-name whitespace-nowrap text-sm font-bold text-slate-700"><?= e($category['market_name']) ?></p>
                                        <p class="categories-market-city mt-1 whitespace-nowrap text-xs text-slate-400">
                                            <i class="fa-solid fa-location-dot mr-1 text-[10px]"></i>
                                            <?= e($category['city_name']) ?>
                                        </p>
                                    </td>
                                    <td class="categories-description max-w-sm px-5 py-4 text-sm leading-5 text-slate-500">
                                        <?= e($descriptionPreview) ?>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <a
                                            href="products.php?category=<?= e($categoryId) ?>"
                                            class="categories-product-badge inline-flex items-center gap-1.5 rounded-full <?= $productCount > 0 ? 'bg-blue-50 text-blue-700 hover:bg-blue-100' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?> px-2.5 py-1 text-xs font-bold transition"
                                            title="View products in <?= e($category['category_name']) ?>"
                                        >
                                            <i class="fa-solid fa-basket-shopping text-[10px]"></i>
                                            <?= e(number_format($productCount)) ?>
                                        </a>
                                    </td>
                                    <td class="categories-updated whitespace-nowrap px-5 py-4 text-sm font-medium text-slate-500">
                                        <?= e($updatedLabel) ?>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-right">
                                        <div class="categories-action-wrap inline-flex items-center gap-2">
                                            <button type="button" onclick='openEditModal(<?= $categoryJson ?>)'
                                                    class="categories-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700"
                                                    title="Edit category">
                                                <i class="fa-solid fa-pen text-xs"></i>
                                            </button>

                                            <form method="post" onsubmit="return confirm('Delete this category? This action cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="category_id" value="<?= e($categoryId) ?>">
                                                <button type="submit"
                                                        class="categories-action-button grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600 <?= $productCount > 0 ? 'cursor-not-allowed opacity-40' : '' ?>"
                                                        title="<?= $productCount > 0 ? 'Move or delete linked products first' : 'Delete category' ?>"
                                                        <?= $productCount > 0 ? 'disabled' : '' ?>>
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

                <div class="categories-pagination flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs font-medium text-slate-400">
                        Showing <span class="font-bold text-slate-600"><?= e(number_format($fromRecord)) ?></span>
                        to <span class="font-bold text-slate-600"><?= e(number_format($toRecord)) ?></span>
                        of <span class="font-bold text-slate-600"><?= e(number_format($filteredTotal)) ?></span> categories
                    </p>

                    <?php if ($totalPages > 1): ?>
                        <nav class="flex items-center gap-1.5">
                            <a href="?<?= e(categoriesQueryString(['page' => max(1, $page - 1)])) ?>"
                               class="grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-sm text-slate-500 transition hover:bg-slate-50 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </a>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            ?>
                            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                                <a href="?<?= e(categoriesQueryString(['page' => $pageNumber])) ?>"
                                   class="grid h-9 min-w-9 place-items-center rounded-xl px-2 text-xs font-extrabold transition <?= $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50' ?>">
                                    <?= e($pageNumber) ?>
                                </a>
                            <?php endfor; ?>

                            <a href="?<?= e(categoriesQueryString(['page' => min($totalPages, $page + 1)])) ?>"
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

<!-- Create Category Modal -->
<div id="createModal" class="categories-modal-scroll fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="text-lg font-extrabold text-slate-950">Add Category</h2>
                <p class="mt-0.5 text-xs text-slate-500">Create one category for one or more markets.</p>
            </div>
            <button type="button" onclick="closeModal('createModal')" class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form id="createCategoryForm" method="post" class="space-y-4 p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">

            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">Category Name *</span>
                <input type="text" name="category_name" required maxlength="100" placeholder="Example: Fresh Vegetables"
                       class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
            </label>

            <fieldset class="block">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <div>
                        <span class="block text-xs font-bold text-slate-600">Markets *</span>
                        <span class="mt-0.5 block text-[11px] text-slate-400">
                            Select one or more markets for this category.
                        </span>
                    </div>

                    <button type="button"
                            onclick="toggleAllCreateMarkets()"
                            class="shrink-0 rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-bold text-slate-500 hover:bg-slate-50">
                        Select / Clear All
                    </button>
                </div>

                <div class="max-h-48 space-y-2 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <?php foreach ($marketOptions as $market): ?>
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg bg-white px-3 py-2.5 transition hover:bg-green-50">
                            <input type="checkbox"
                                   name="market_ids[]"
                                   value="<?= e($market['market_id']) ?>"
                                   class="create-market-checkbox h-4 w-4 rounded border-slate-300 text-green-600 focus:ring-green-500">

                            <span class="min-w-0">
                                <span class="block truncate text-sm font-bold text-slate-700">
                                    <?= e($market['market_name']) ?>
                                </span>
                                <span class="block truncate text-[11px] text-slate-400">
                                    <?= e($market['city_name'] . ' — ' . $market['administrative_division']) ?>
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">
                    Description
                    <span class="font-medium text-slate-400">(Optional)</span>
                </span>
                <textarea name="description" rows="3" placeholder="Describe the products that belong to this category..."
                          class="w-full resize-none rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"></textarea>
            </label>

            <div class="rounded-xl bg-blue-50 px-3.5 py-3 text-xs leading-5 text-blue-800">
                <i class="fa-solid fa-circle-info mr-1"></i>
                Select all markets where this category should be available. The system creates the market assignments in one action and prevents duplicates inside the same market.
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('createModal')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">Create Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Category Modal -->
<div id="editModal" class="categories-modal-scroll fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="text-lg font-extrabold text-slate-950">Edit Category</h2>
                <p class="mt-0.5 text-xs text-slate-500">Update category details and its market.</p>
            </div>
            <button type="button" onclick="closeModal('editModal')" class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" class="space-y-4 p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit_category_id" name="category_id" value="">

            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">Category Name *</span>
                <input type="text" id="edit_category_name" name="category_name" required maxlength="100"
                       class="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
            </label>

            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">Market *</span>
                <select id="edit_market_id" name="market_id" required class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                    <option value="">Select a market</option>
                    <?php foreach ($marketOptions as $market): ?>
                        <option value="<?= e($market['market_id']) ?>">
                            <?= e($market['market_name'] . ' — ' . $market['city_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">
                    Description
                    <span class="font-medium text-slate-400">(Optional)</span>
                </span>
                <textarea id="edit_description" name="description" rows="3"
                          class="w-full resize-none rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"></textarea>
            </label>

            <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('editModal')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleAllCreateMarkets() {
        var boxes = Array.prototype.slice.call(
            document.querySelectorAll('.create-market-checkbox')
        );

        if (boxes.length === 0) {
            return;
        }

        var allChecked = boxes.every(function (box) {
            return box.checked;
        });

        boxes.forEach(function (box) {
            box.checked = !allChecked;
        });
    }

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
        showModal('createModal');
    }

    function openEditModal(category) {
        document.getElementById('edit_category_id').value = category.category_id || '';
        document.getElementById('edit_category_name').value = category.category_name || '';
        document.getElementById('edit_market_id').value = category.market_id || '';
        document.getElementById('edit_description').value = category.description || '';
        showModal('editModal');
    }

    var createCategoryForm = document.getElementById('createCategoryForm');

    if (createCategoryForm) {
        createCategoryForm.addEventListener('submit', function (event) {
            var selectedMarkets = document.querySelectorAll(
                '.create-market-checkbox:checked'
            );

            if (selectedMarkets.length === 0) {
                event.preventDefault();
                alert('Please select at least one market.');
            }
        });
    }

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

    window.setTimeout(function () {
        var flashMessage = document.getElementById('flashMessage');
        if (flashMessage) {
            flashMessage.remove();
        }
    }, 5000);
</script>
</body>
</html>