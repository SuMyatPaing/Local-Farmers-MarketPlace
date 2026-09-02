<?php
declare(strict_types=1);

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/marketplace.php';
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

function productsQueryString(array $changes): string
{
    $current = [
        'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
        'vendor' => isset($_GET['vendor']) ? (int) $_GET['vendor'] : 0,
        'market' => isset($_GET['market']) ? (int) $_GET['market'] : 0,
        'category' => isset($_GET['category']) ? (int) $_GET['category'] : 0,
        'stock' => isset($_GET['stock']) ? (string) $_GET['stock'] : 'all',
        'sort' => isset($_GET['sort']) ? (string) $_GET['sort'] : 'newest',
        'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    ];

    foreach ($changes as $key => $value) {
        $current[$key] = $value;
    }

    return http_build_query($current);
}

function productPhotoUrl($path): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (preg_match('~^(https?:)?//~i', $path) || strpos($path, 'data:') === 0) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);

    if (strpos($path, '../') === 0 || strpos($path, './') === 0) {
        return $path;
    }

    if (strpos($path, '/') === 0) {
        return $path;
    }

    return '../' . ltrim($path, '/');
}

function productStockLabel(int $quantity): string
{
    if ($quantity <= 0) {
        return 'Out of stock';
    }

    if ($quantity <= 5) {
        return 'Low stock';
    }

    return 'In stock';
}

function productStockClass(int $quantity): string
{
    if ($quantity <= 0) {
        return 'bg-red-50 text-red-700 ring-red-600/10';
    }

    if ($quantity <= 5) {
        return 'bg-amber-50 text-amber-700 ring-amber-600/10';
    }

    return 'bg-green-50 text-green-700 ring-green-600/10';
}

function productInitials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'P';
    }

    $parts = preg_split('/\s+/u', $name);
    $letters = '';

    if (is_array($parts)) {
        foreach ($parts as $part) {
            if ($part !== '') {
                $letters .= mb_substr($part, 0, 1);
            }

            if (mb_strlen($letters) >= 2) {
                break;
            }
        }
    }

    return mb_strtoupper($letters !== '' ? $letters : mb_substr($name, 0, 1));
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$q = trim(isset($_GET['q']) ? (string) $_GET['q'] : '');
$vendorId = isset($_GET['vendor']) ? max(0, (int) $_GET['vendor']) : 0;
$marketId = isset($_GET['market']) ? max(0, (int) $_GET['market']) : 0;
$categoryId = isset($_GET['category']) ? max(0, (int) $_GET['category']) : 0;
$stock = isset($_GET['stock']) ? (string) $_GET['stock'] : 'all';
$sort = isset($_GET['sort']) ? (string) $_GET['sort'] : 'newest';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 10;

$allowedStockFilters = ['all', 'in_stock', 'low_stock', 'out_of_stock'];
if (!in_array($stock, $allowedStockFilters, true)) {
    $stock = 'all';
}

$allowedSorts = [
    'newest',
    'oldest',
    'name_asc',
    'name_desc',
    'price_high',
    'price_low',
    'stock_high',
    'stock_low',
    'rating_high',
    'sold_high',
];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(
        LOWER(TRIM(p.product_name)) LIKE LOWER(TRIM(:search_product))
        OR LOWER(TRIM(p.description)) LIKE LOWER(TRIM(:search_description))
        OR LOWER(TRIM(v.vendor_name)) LIKE LOWER(TRIM(:search_vendor))
        OR LOWER(TRIM(c.category_name)) LIKE LOWER(TRIM(:search_category))
        OR LOWER(TRIM(m.market_name)) LIKE LOWER(TRIM(:search_market))
        OR LOWER(TRIM(ci.city_name)) LIKE LOWER(TRIM(:search_city))
    )';

    $searchValue = '%' . trim($q) . '%';
    $params['search_product'] = $searchValue;
    $params['search_description'] = $searchValue;
    $params['search_vendor'] = $searchValue;
    $params['search_category'] = $searchValue;
    $params['search_market'] = $searchValue;
    $params['search_city'] = $searchValue;
}

if ($vendorId > 0) {
    $where[] = 'p.vendor_id = :vendor_id';
    $params['vendor_id'] = $vendorId;
}

if ($marketId > 0) {
    $where[] = 'm.market_id = :market_id';
    $params['market_id'] = $marketId;
}

if ($categoryId > 0) {
    $where[] = 'p.category_id = :category_id';
    $params['category_id'] = $categoryId;
}

if ($stock === 'in_stock') {
    $where[] = 'p.stock_quantity > 5';
} elseif ($stock === 'low_stock') {
    $where[] = 'p.stock_quantity BETWEEN 1 AND 5';
} elseif ($stock === 'out_of_stock') {
    $where[] = 'p.stock_quantity <= 0';
}

$whereSql = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

$orderBy = 'p.created_at DESC, p.product_id DESC';
if ($sort === 'oldest') {
    $orderBy = 'p.created_at ASC, p.product_id ASC';
} elseif ($sort === 'name_asc') {
    $orderBy = 'p.product_name ASC';
} elseif ($sort === 'name_desc') {
    $orderBy = 'p.product_name DESC';
} elseif ($sort === 'price_high') {
    $orderBy = 'p.price DESC, p.product_name ASC';
} elseif ($sort === 'price_low') {
    $orderBy = 'p.price ASC, p.product_name ASC';
} elseif ($sort === 'stock_high') {
    $orderBy = 'p.stock_quantity DESC, p.product_name ASC';
} elseif ($sort === 'stock_low') {
    $orderBy = 'p.stock_quantity ASC, p.product_name ASC';
} elseif ($sort === 'rating_high') {
    $orderBy = 'average_rating DESC, review_count DESC, p.product_name ASC';
} elseif ($sort === 'sold_high') {
    $orderBy = 'sold_quantity DESC, p.product_name ASC';
}

/*
|--------------------------------------------------------------------------
| Filter options
|--------------------------------------------------------------------------
*/
$vendorOptions = $pdo->query(
    "SELECT vendor_id, vendor_name
     FROM vendors
     WHERE status = 'accepted'
     ORDER BY vendor_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$marketOptions = $pdo->query(
    'SELECT m.market_id, m.market_name, ci.city_name
     FROM markets m
     INNER JOIN cities ci ON ci.city_id = m.city_id
     ORDER BY m.market_name ASC, ci.city_name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$categoryOptionSql =
    'SELECT c.category_id, c.category_name, c.market_id, m.market_name
     FROM categories c
     INNER JOIN markets m ON m.market_id = c.market_id';
$categoryOptionParams = [];

if ($marketId > 0) {
    $categoryOptionSql .= ' WHERE c.market_id = :market_id';
    $categoryOptionParams['market_id'] = $marketId;
}

$categoryOptionSql .= ' ORDER BY m.market_name ASC, c.category_name ASC';
$categoryOptionStatement = $pdo->prepare($categoryOptionSql);
$categoryOptionStatement->execute($categoryOptionParams);
$categoryOptions = $categoryOptionStatement->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/
$totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$inStockProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity > 5')->fetchColumn();
$lowStockProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity BETWEEN 1 AND 5')->fetchColumn();
$outOfStockProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity <= 0')->fetchColumn();
$inventoryValue = (float) $pdo->query('SELECT COALESCE(SUM(price * stock_quantity), 0) FROM products')->fetchColumn();

/*
|--------------------------------------------------------------------------
| Pagination and products
|--------------------------------------------------------------------------
*/
$countSql =
    'SELECT COUNT(*)
     FROM products p
     INNER JOIN vendors v ON v.vendor_id = p.vendor_id
     INNER JOIN categories c ON c.category_id = p.category_id
     INNER JOIN markets m ON m.market_id = c.market_id
     INNER JOIN cities ci ON ci.city_id = m.city_id' . $whereSql;

$countStatement = $pdo->prepare($countSql);
$countStatement->execute($params);
$filteredTotal = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$productSql =
    "SELECT
        p.product_id,
        p.vendor_id,
        p.product_name,
        p.category_id,
        p.price,
        p.unit,
        p.description,
        p.stock_quantity,
        p.created_at,
        p.updated_at,
        v.vendor_name,
        c.category_name,
        m.market_id,
        m.market_name,
        ci.city_name,
        ci.administrative_division,
        (
            SELECT pp.photo_path
            FROM product_photo pp
            WHERE pp.product_id = p.product_id
            ORDER BY pp.product_photo_id ASC
            LIMIT 1
        ) AS primary_photo,
        (
            SELECT COUNT(*)
            FROM product_photo pp_count
            WHERE pp_count.product_id = p.product_id
        ) AS photo_count,
        (
            SELECT COUNT(*)
            FROM reviews r_count
            WHERE r_count.product_id = p.product_id
        ) AS review_count,
        (
            SELECT COALESCE(AVG(r_avg.rating), 0)
            FROM reviews r_avg
            WHERE r_avg.product_id = p.product_id
        ) AS average_rating,
        (
            SELECT COALESCE(SUM(pd.quantity), 0)
            FROM purchase_details pd
            INNER JOIN purchase_process po ON po.purchase_id = pd.purchase_id
            WHERE pd.product_id = p.product_id
              AND po.order_status = 'confirmed'
        ) AS sold_quantity
     FROM products p
     INNER JOIN vendors v ON v.vendor_id = p.vendor_id
     INNER JOIN categories c ON c.category_id = p.category_id
     INNER JOIN markets m ON m.market_id = c.market_id
     INNER JOIN cities ci ON ci.city_id = m.city_id" .
    $whereSql .
    ' ORDER BY ' . $orderBy .
    ' LIMIT :limit OFFSET :offset';

$productStatement = $pdo->prepare($productSql);
foreach ($params as $name => $value) {
    $parameterType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $productStatement->bindValue(':' . $name, $value, $parameterType);
}
$productStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$productStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$productStatement->execute();
$products = $productStatement->fetchAll(PDO::FETCH_ASSOC);

$productPhotos = [];
if (count($products) > 0) {
    $productIds = [];
    foreach ($products as $productRow) {
        $productIds[] = (int) $productRow['product_id'];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $photoStatement = $pdo->prepare(
        'SELECT product_id, photo_path
         FROM product_photo
         WHERE product_id IN (' . $placeholders . ')
         ORDER BY product_id ASC, product_photo_id ASC'
    );

    foreach ($productIds as $index => $id) {
        $photoStatement->bindValue($index + 1, $id, PDO::PARAM_INT);
    }

    $photoStatement->execute();
    while ($photo = $photoStatement->fetch(PDO::FETCH_ASSOC)) {
        $photoProductId = (int) $photo['product_id'];
        if (!isset($productPhotos[$photoProductId])) {
            $productPhotos[$photoProductId] = [];
        }
        $productPhotos[$photoProductId][] = productPhotoUrl($photo['photo_path']);
    }
}

$showingFrom = $filteredTotal > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $filteredTotal);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products | Local Farmers Marketplace</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Admin Products - Compact Production UI
        |--------------------------------------------------------------------------
        | Browser zoom target: 100%
        | Scrollbars are hidden visually while scrolling still works.
        |--------------------------------------------------------------------------
        */

        html,
        body {
            overflow-x: hidden;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .products-scroll-hidden,
        .products-modal-scroll {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .products-scroll-hidden::-webkit-scrollbar,
        .products-modal-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .products-main {
            min-width: 0;
        }

        .products-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
        }

        .products-table {
            width: 100%;
            min-width: 900px;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            .products-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .products-shell {
                margin-left: 176px !important;
            }

            .products-main {
                padding: 12px 14px 22px !important;
            }

            .products-stats {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: .65rem;
                margin-bottom: .7rem !important;
            }

            .products-stat-card {
                min-height: 80px;
                border-radius: 14px !important;
                padding: 11px 13px !important;
            }

            .products-stat-card p:first-child {
                font-size: 9px !important;
                letter-spacing: .04em !important;
            }

            .products-stat-card p.text-3xl {
                margin-top: 5px !important;
                font-size: 20px !important;
                line-height: 1 !important;
            }

            .products-stat-card .h-12 {
                width: 38px !important;
                height: 38px !important;
                border-radius: 10px !important;
            }

            .products-stat-card .text-xl {
                font-size: 15px !important;
            }

            .products-filter-panel,
            .products-list-panel {
                border-radius: 14px !important;
            }

            .products-filter-panel {
                margin-bottom: .65rem !important;
                padding: 10px 12px !important;
            }

            .products-filter-form {
                grid-template-columns:
                    minmax(230px, 1.35fr)
                    minmax(135px, .72fr)
                    minmax(145px, .78fr)
                    minmax(130px, .68fr)
                    minmax(145px, .76fr)
                    auto !important;
                gap: 7px !important;
            }

            .products-filter-control,
            .products-filter-button,
            .products-clear-button {
                height: 34px !important;
                border-radius: 9px !important;
                font-size: 9px !important;
            }

            .products-search-input {
                padding-left: 34px !important;
                padding-right: 10px !important;
            }

            .products-search-icon {
                left: 12px !important;
                font-size: 10px !important;
            }

            .products-filter-actions {
                gap: 5px !important;
            }

            .products-list-head {
                padding: 10px 13px !important;
            }

            .products-list-title {
                font-size: 14px !important;
            }

            .products-list-subtitle {
                margin-top: 3px !important;
                font-size: 9px !important;
            }

            .products-table {
                min-width: 0 !important;
                table-layout: fixed;
            }

            .products-table th {
                padding: 7px 8px !important;
                font-size: 8px !important;
            }

            .products-table td {
                padding: 8px !important;
                vertical-align: middle !important;
            }

            .products-table th:nth-child(1),
            .products-table td:nth-child(1) { width: 22%; }

            .products-table th:nth-child(2),
            .products-table td:nth-child(2) { width: 20%; }

            .products-table th:nth-child(3),
            .products-table td:nth-child(3) { width: 13%; }

            .products-table th:nth-child(4),
            .products-table td:nth-child(4) { width: 13%; }

            .products-table th:nth-child(5),
            .products-table td:nth-child(5) { width: 15%; }

            .products-table th:nth-child(6),
            .products-table td:nth-child(6) { width: 10%; }

            .products-table th:nth-child(7),
            .products-table td:nth-child(7) { width: 7%; }

            .products-photo {
                width: 38px !important;
                height: 38px !important;
                border-radius: 9px !important;
            }

            .products-name,
            .products-vendor,
            .products-price {
                font-size: 9px !important;
            }

            .products-category,
            .products-market,
            .products-city,
            .products-unit,
            .products-added-time {
                font-size: 8px !important;
                line-height: 1.35 !important;
            }

            .products-stock-badge,
            .products-performance-line,
            .products-added-date {
                font-size: 8px !important;
            }

            .products-action-button {
                width: 27px !important;
                height: 27px !important;
                border-radius: 7px !important;
            }

            .products-action-button i {
                font-size: 9px !important;
            }

            .products-pagination {
                padding: 10px 14px !important;
            }

            .products-pagination,
            .products-pagination a,
            .products-pagination span {
                font-size: 9px !important;
            }
        }

        @media (max-width: 1023px) {
            .products-table {
                min-width: 900px;
            }
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>
<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">
<div class="min-h-screen">
    <?php require_once './sidebar.php'; ?>

    <div class="products-shell min-h-screen lg:ml-64">
        <?php require_once './header.php'; ?>

        <main class="products-main px-4 pb-10 pt-5 sm:px-6 xl:px-7">

            <section class="products-stats mb-5">
                <article class="products-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total Products</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalProducts)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-basket-shopping text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="products-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">In Stock</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($inStockProducts)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
                            <i class="fa-solid fa-circle-check text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="products-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Low Stock</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($lowStockProducts)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-amber-50 text-amber-600">
                            <i class="fa-solid fa-triangle-exclamation text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="products-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Out of Stock</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($outOfStockProducts)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-red-50 text-red-600">
                            <i class="fa-solid fa-circle-xmark text-xl"></i>
                        </div>
                    </div>
                </article>
            </section>

            <section class="products-filter-panel mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                <form method="get"
                      action="products.php"
                      class="products-filter-form grid gap-3 xl:grid-cols-[minmax(240px,1fr)_170px_180px_150px_180px_auto]">

                    <label class="relative block">
                        <i class="products-search-icon fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                        <input type="search"
                               name="q"
                               value="<?= e($q) ?>"
                               placeholder="Search product, vendor, market or category..."
                               class="products-filter-control products-search-input h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                    </label>

                    <select name="market"
                            class="products-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="0">All Markets</option>
                        <?php foreach ($marketOptions as $market): ?>
                            <option value="<?= e($market['market_id']) ?>" <?= $marketId === (int) $market['market_id'] ? 'selected' : '' ?>>
                                <?= e($market['market_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="category"
                            class="products-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="0">All Categories</option>
                        <?php foreach ($categoryOptions as $categoryOption): ?>
                            <option value="<?= e($categoryOption['category_id']) ?>" <?= $categoryId === (int) $categoryOption['category_id'] ? 'selected' : '' ?>>
                                <?= e($categoryOption['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="stock"
                            class="products-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="all" <?= $stock === 'all' ? 'selected' : '' ?>>All Stock</option>
                        <option value="in_stock" <?= $stock === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                        <option value="low_stock" <?= $stock === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="out_of_stock" <?= $stock === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                    </select>

                    <select name="sort"
                            class="products-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
                        <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Highest Price</option>
                        <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Lowest Price</option>
                        <option value="stock_high" <?= $sort === 'stock_high' ? 'selected' : '' ?>>Most Stock</option>
                        <option value="stock_low" <?= $sort === 'stock_low' ? 'selected' : '' ?>>Lowest Stock</option>
                        <option value="rating_high" <?= $sort === 'rating_high' ? 'selected' : '' ?>>Highest Rated</option>
                        <option value="sold_high" <?= $sort === 'sold_high' ? 'selected' : '' ?>>Best Selling</option>
                    </select>

                    <div class="products-filter-actions flex gap-2">
                        <button type="submit"
                                class="products-filter-button inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-sm font-bold text-white transition hover:bg-green-700">
                            <i class="fa-solid fa-filter text-xs"></i>
                            Filter
                        </button>

                        <a href="products.php"
                           class="products-clear-button inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-3.5 text-sm font-bold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700">
                            <i class="fa-solid fa-rotate-left text-xs"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </section>

            <section class="products-list-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
                <div class="products-list-head border-b border-slate-100 px-5 py-4">
                    <h2 class="products-list-title font-extrabold text-slate-950">Product List</h2>
                    <p class="products-list-subtitle mt-0.5 text-xs text-slate-500">
                        Showing <?= e(number_format($showingFrom)) ?>–<?= e(number_format($showingTo)) ?> of <?= e(number_format($filteredTotal)) ?> matching products
                    </p>
                </div>

                <?php if (count($products) === 0): ?>
                    <div class="px-5 py-20 text-center">
                        <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                            <i class="fa-solid fa-basket-shopping text-2xl"></i>
                        </div>
                        <h3 class="mt-4 text-lg font-extrabold text-slate-800">No products found</h3>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">Try changing the search text or filters. Products uploaded by accepted vendors will appear here.</p>
                        <a href="products.php" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">
                            <i class="fa-solid fa-rotate-left"></i>
                            Clear filters
                        </a>
                    </div>
                <?php else: ?>
                    <div class="products-scroll-hidden overflow-x-auto">
                        <table class="products-table min-w-full divide-y divide-slate-100">
                            <thead class="bg-slate-50/80">
                            <tr class="text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-400">
                                <th class="px-5 py-3.5">Product</th>
                                <th class="px-5 py-3.5">Vendor & Market</th>
                                <th class="px-5 py-3.5">Price</th>
                                <th class="px-5 py-3.5">Stock</th>
                                <th class="px-5 py-3.5">Performance</th>
                                <th class="px-5 py-3.5">Added</th>
                                <th class="px-5 py-3.5 text-right">Actions</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php foreach ($products as $product): ?>
                                <?php
                                $productIdValue = (int) $product['product_id'];
                                $stockQuantity = (int) $product['stock_quantity'];
                                $primaryPhotoUrl = productPhotoUrl($product['primary_photo']);
                                $rating = (float) $product['average_rating'];
                                $reviewCount = (int) $product['review_count'];
                                $soldQuantity = (int) $product['sold_quantity'];
                                $photosForModal = isset($productPhotos[$productIdValue]) ? $productPhotos[$productIdValue] : [];
                                $modalData = [
                                    'product_id' => $productIdValue,
                                    'product_name' => (string) $product['product_name'],
                                    'price' => number_format((float) $product['price'], 2),
                                    'unit' => fm_unit_label($product['unit']),
                                    'description' => (string) $product['description'],
                                    'stock_quantity' => $stockQuantity,
                                    'stock_label' => productStockLabel($stockQuantity),
                                    'vendor_name' => (string) $product['vendor_name'],
                                    'category_name' => (string) $product['category_name'],
                                    'market_name' => (string) $product['market_name'],
                                    'city_name' => (string) $product['city_name'],
                                    'administrative_division' => (string) $product['administrative_division'],
                                    'rating' => number_format($rating, 1),
                                    'review_count' => $reviewCount,
                                    'sold_quantity' => $soldQuantity,
                                    'created_at' => date('M j, Y g:i A', strtotime((string) $product['created_at'])),
                                    'updated_at' => date('M j, Y g:i A', strtotime((string) $product['updated_at'])),
                                    'photos' => $photosForModal,
                                ];
                                $modalJson = json_encode($modalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                ?>
                                <tr class="transition hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <div class="flex min-w-[230px] items-center gap-3">
                                            <?php if ($primaryPhotoUrl !== ''): ?>
                                                <img src="<?= e($primaryPhotoUrl) ?>" alt="<?= e($product['product_name']) ?>"
                                                     class="products-photo h-14 w-14 shrink-0 rounded-xl border border-slate-200 object-cover"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='grid';">
                                                <div style="display:none" class="products-photo h-14 w-14 shrink-0 place-items-center rounded-xl bg-green-50 text-sm font-extrabold text-green-700">
                                                    <?= e(productInitials((string) $product['product_name'])) ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="products-photo grid h-14 w-14 shrink-0 place-items-center rounded-xl bg-green-50 text-sm font-extrabold text-green-700">
                                                    <?= e(productInitials((string) $product['product_name'])) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="min-w-0">
                                                <p class="products-name max-w-[210px] truncate text-sm font-extrabold text-slate-900" title="<?= e($product['product_name']) ?>">
                                                    <?= e($product['product_name']) ?>
                                                </p>
                                                <p class="products-category mt-1 max-w-[210px] truncate text-xs text-slate-500"><?= e($product['category_name']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="min-w-[190px]">
                                            <p class="products-vendor text-sm font-bold text-slate-800"><?= e($product['vendor_name']) ?></p>
                                            <p class="products-market mt-1 text-xs text-slate-500">
                                                <i class="fa-solid fa-store mr-1 text-slate-400"></i>
                                                <?= e($product['market_name']) ?>
                                            </p>
                                            <p class="products-city mt-1 text-[11px] text-slate-400"><?= e($product['city_name']) ?></p>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="products-price whitespace-nowrap text-sm font-extrabold text-slate-900"><?= e(number_format((float) $product['price'], 2)) ?> MMK</p>
                                        <p class="products-unit mt-1 text-[11px] text-slate-400">per <?= e(fm_unit_label($product['unit'])) ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="products-stock-badge inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-extrabold ring-1 ring-inset <?= e(productStockClass($stockQuantity)) ?>">
                                            <?= e(productStockLabel($stockQuantity)) ?>
                                        </span>
                                        <p class="mt-1.5 text-xs font-bold text-slate-600"><?= e(number_format($stockQuantity)) ?> units</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="min-w-[130px] space-y-1.5">
                                            <p class="products-performance-line text-xs font-bold text-slate-700">
                                                <i class="fa-solid fa-star mr-1 text-amber-400"></i>
                                                <?= e(number_format($rating, 1)) ?>
                                                <span class="font-medium text-slate-400">
                                                    (<?= e(number_format($reviewCount)) ?> review<?= $reviewCount === 1 ? '' : 's' ?>)
                                                </span>
                                            </p>
                                            <p class="products-performance-line text-xs text-slate-500">
                                                <i class="fa-solid fa-bag-shopping mr-1 text-green-500"></i>
                                                <?= e(number_format($soldQuantity)) ?> sold
                                            </p>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="products-added-date whitespace-nowrap text-xs font-bold text-slate-600"><?= e(date('M j, Y', strtotime((string) $product['created_at']))) ?></p>
                                        <p class="products-added-time mt-1 text-[11px] text-slate-400"><?= e(date('g:i A', strtotime((string) $product['created_at']))) ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <button type="button"
                                                data-product="<?= e($modalJson !== false ? $modalJson : '{}') ?>"
                                                onclick="openProductModal(this)"
                                                class="products-action-button inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700"
                                                title="View product details">
                                            <i class="fa-regular fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="products-pagination flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-slate-500">
                            Page <span class="font-extrabold text-slate-700"><?= e(number_format($page)) ?></span>
                            of <span class="font-extrabold text-slate-700"><?= e(number_format($totalPages)) ?></span>
                        </p>

                        <div class="flex items-center gap-1.5">
                            <?php if ($page > 1): ?>
                                <a href="?<?= e(productsQueryString(['page' => $page - 1])) ?>"
                                   class="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-600 hover:bg-slate-50">
                                    <i class="fa-solid fa-chevron-left text-[10px]"></i>
                                    Previous
                                </a>
                            <?php else: ?>
                                <span class="inline-flex h-9 cursor-not-allowed items-center gap-2 rounded-xl border border-slate-100 px-3 text-xs font-bold text-slate-300">
                                    <i class="fa-solid fa-chevron-left text-[10px]"></i>
                                    Previous
                                </span>
                            <?php endif; ?>

                            <?php
                            $pageStart = max(1, $page - 2);
                            $pageEnd = min($totalPages, $page + 2);
                            for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++):
                            ?>
                                <a href="?<?= e(productsQueryString(['page' => $pageNumber])) ?>"
                                   class="grid h-9 min-w-9 place-items-center rounded-xl px-2 text-xs font-extrabold <?= $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
                                    <?= e($pageNumber) ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?<?= e(productsQueryString(['page' => $page + 1])) ?>"
                                   class="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-600 hover:bg-slate-50">
                                    Next
                                    <i class="fa-solid fa-chevron-right text-[10px]"></i>
                                </a>
                            <?php else: ?>
                                <span class="inline-flex h-9 cursor-not-allowed items-center gap-2 rounded-xl border border-slate-100 px-3 text-xs font-bold text-slate-300">
                                    Next
                                    <i class="fa-solid fa-chevron-right text-[10px]"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<!-- Product Details Modal -->
<div id="productModal" class="products-modal-scroll fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-950/55 p-4 backdrop-blur-sm">
    <div class="products-modal-scroll max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
        <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-5 py-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-green-600">Product Details</p>
                <h2 id="detailName" class="mt-1 text-lg font-extrabold text-slate-950">Product</h2>
            </div>
            <button type="button" onclick="closeProductModal()" class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="p-5">
            <div id="detailPhotos" class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4"></div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Price</p>
                    <p id="detailPrice" class="mt-1 text-base font-extrabold text-slate-900"></p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Stock</p>
                    <p id="detailStock" class="mt-1 text-base font-extrabold text-slate-900"></p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Performance</p>
                    <p id="detailPerformance" class="mt-1 text-base font-extrabold text-slate-900"></p>
                </div>
            </div>

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <section class="rounded-2xl border border-slate-200 p-5">
                    <h3 class="text-sm font-extrabold text-slate-900">Catalog Information</h3>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4 border-b border-slate-100 pb-3">
                            <dt class="text-slate-400">Vendor</dt>
                            <dd id="detailVendor" class="text-right font-bold text-slate-700"></dd>
                        </div>
                        <div class="flex justify-between gap-4 border-b border-slate-100 pb-3">
                            <dt class="text-slate-400">Category</dt>
                            <dd id="detailCategory" class="text-right font-bold text-slate-700"></dd>
                        </div>
                        <div class="flex justify-between gap-4 border-b border-slate-100 pb-3">
                            <dt class="text-slate-400">Market</dt>
                            <dd id="detailMarket" class="text-right font-bold text-slate-700"></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-400">Location</dt>
                            <dd id="detailLocation" class="text-right font-bold text-slate-700"></dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-2xl border border-slate-200 p-5">
                    <h3 class="text-sm font-extrabold text-slate-900">Record Information</h3>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4 border-b border-slate-100 pb-3">
                            <dt class="text-slate-400">Product ID</dt>
                            <dd id="detailId" class="text-right font-bold text-slate-700"></dd>
                        </div>
                        <div class="flex justify-between gap-4 border-b border-slate-100 pb-3">
                            <dt class="text-slate-400">Created</dt>
                            <dd id="detailCreated" class="text-right font-bold text-slate-700"></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-400">Last updated</dt>
                            <dd id="detailUpdated" class="text-right font-bold text-slate-700"></dd>
                        </div>
                    </dl>
                </section>
            </div>

            <section class="mt-5 rounded-2xl border border-slate-200 p-5">
                <h3 class="text-sm font-extrabold text-slate-900">Description</h3>
                <p id="detailDescription" class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-600"></p>
            </section>
        </div>
    </div>
</div>

<script>
    function text(id, value) {
        var element = document.getElementById(id);
        if (element) {
            element.textContent = value === null || value === undefined ? '' : String(value);
        }
    }

    function openProductModal(button) {
        var data = {};

        try {
            data = JSON.parse(button.getAttribute('data-product') || '{}');
        } catch (error) {
            data = {};
        }

        text('detailName', data.product_name || 'Product');
        text('detailPrice', (data.price || '0.00') + ' MMK');
        text('detailStock', String(data.stock_quantity || 0) + ' units · ' + (data.stock_label || ''));
        text('detailPerformance', (data.rating || '0.0') + ' ★ · ' + String(data.sold_quantity || 0) + ' sold');
        text('detailVendor', data.vendor_name || '—');
        text('detailCategory', data.category_name || '—');
        text('detailMarket', data.market_name || '—');
        text('detailLocation', [data.city_name, data.administrative_division].filter(Boolean).join(', ') || '—');
        text('detailId', '#' + String(data.product_id || ''));
        text('detailCreated', data.created_at || '—');
        text('detailUpdated', data.updated_at || '—');
        text('detailDescription', data.description || 'No description provided.');

        var gallery = document.getElementById('detailPhotos');
        gallery.innerHTML = '';

        var photos = Array.isArray(data.photos) ? data.photos : [];
        if (photos.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'col-span-full grid min-h-40 place-items-center rounded-2xl bg-slate-100 text-center text-slate-400';
            empty.innerHTML = '<div><i class="fa-regular fa-image text-3xl"></i><p class="mt-2 text-xs font-bold">No product photos</p></div>';
            gallery.appendChild(empty);
        } else {
            photos.forEach(function (photo, index) {
                var image = document.createElement('img');
                image.src = photo;
                image.alt = (data.product_name || 'Product') + ' photo ' + String(index + 1);
                image.className = 'h-36 w-full rounded-xl border border-slate-200 object-cover';
                image.onerror = function () {
                    this.remove();
                };
                gallery.appendChild(image);
            });
        }

        var modal = document.getElementById('productModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
    }

    function closeProductModal() {
        var modal = document.getElementById('productModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeProductModal();
        }
    });

    document.getElementById('productModal').addEventListener('click', function (event) {
        if (event.target === this) {
            closeProductModal();
        }
    });
</script>
</body>
</html>