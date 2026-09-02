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

function reviewsQueryString(array $changes): string
{
    $current = [
        'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
        'rating' => isset($_GET['rating']) ? (string) $_GET['rating'] : 'all',
        'vendor' => isset($_GET['vendor']) ? (int) $_GET['vendor'] : 0,
        'market' => isset($_GET['market']) ? (int) $_GET['market'] : 0,
        'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    ];

    foreach ($changes as $key => $value) {
        $current[$key] = $value;
    }

    return http_build_query($current);
}


function reviewNumber(int $reviewId): string
{
    return 'REV-' . str_pad((string) $reviewId, 5, '0', STR_PAD_LEFT);
}

function reviewerInitials($name): string
{
    $name = trim((string) $name);

    if ($name === '') {
        return 'U';
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

    if ($letters === '') {
        $letters = mb_substr($name, 0, 1);
    }

    return mb_strtoupper($letters);
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

    if (strpos($path, '../') === 0 || strpos($path, './') === 0 || strpos($path, '/') === 0) {
        return $path;
    }

    return '../' . ltrim($path, '/');
}

function reviewDateLabel($date): string
{
    $timestamp = strtotime((string) $date);

    if ($timestamp === false) {
        return '—';
    }

    return date('M d, Y', $timestamp);
}

function reviewDateTimeLabel($date): string
{
    $timestamp = strtotime((string) $date);

    if ($timestamp === false) {
        return '—';
    }

    return date('M d, Y · h:i A', $timestamp);
}

function reviewStarsHtml($rating, $sizeClass = 'text-sm'): string
{
    $rating = max(0, min(5, (int) $rating));
    $html = '<span class="inline-flex items-center gap-0.5 ' . e($sizeClass) . '" aria-label="' . e($rating) . ' out of 5 stars">';

    for ($star = 1; $star <= 5; $star++) {
        $class = $star <= $rating ? 'text-amber-400' : 'text-slate-200';
        $html .= '<i class="fa-solid fa-star ' . $class . '"></i>';
    }

    $html .= '</span>';

    return $html;
}

function truncateReviewComment($comment, $length = 115): string
{
    $comment = trim((string) $comment);

    if (mb_strlen($comment) <= $length) {
        return $comment;
    }

    return rtrim(mb_substr($comment, 0, $length - 1)) . '…';
}

/*
|--------------------------------------------------------------------------
| Filter values
|--------------------------------------------------------------------------
*/
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$ratingFilter = isset($_GET['rating']) ? strtolower(trim((string) $_GET['rating'])) : 'all';
$vendorFilter = isset($_GET['vendor']) ? max(0, (int) $_GET['vendor']) : 0;
$marketFilter = isset($_GET['market']) ? max(0, (int) $_GET['market']) : 0;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 10;

$allowedRatings = ['all', '1', '2', '3', '4', '5'];

if (!in_array($ratingFilter, $allowedRatings, true)) {
    $ratingFilter = 'all';
}

/*
|--------------------------------------------------------------------------
| Dropdown data
|--------------------------------------------------------------------------
*/
$vendors = [];
$markets = [];

try {
    $vendors = $pdo->query(
        "SELECT vendor_id, vendor_name
         FROM vendors
         ORDER BY vendor_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $markets = $pdo->query(
        "SELECT market_id, market_name
         FROM markets
         ORDER BY market_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $vendors = [];
    $markets = [];
}

/*
|--------------------------------------------------------------------------
| Review statistics
|--------------------------------------------------------------------------
*/
$statistics = [
    'total_reviews' => 0,
    'average_rating' => 0.0,
    'verified_reviews' => 0,
];


try {
    $statisticsStatement = $pdo->query(
        "SELECT
            COUNT(*) AS total_reviews,
            COALESCE(AVG(r.rating), 0) AS average_rating,
            SUM(
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM purchase_process pp
                    INNER JOIN purchase_details pd
                        ON pd.purchase_id = pp.purchase_id
                    WHERE pp.user_id = r.user_id
                      AND pd.product_id = r.product_id
                      AND pp.order_status = 'confirmed'
                ) THEN 1 ELSE 0 END
            ) AS verified_reviews
         FROM reviews r"
    );

    $statisticsRow = $statisticsStatement->fetch(PDO::FETCH_ASSOC);

    if (is_array($statisticsRow)) {
        $statistics['total_reviews'] = (int) $statisticsRow['total_reviews'];
        $statistics['average_rating'] = (float) $statisticsRow['average_rating'];
        $statistics['verified_reviews'] = (int) $statisticsRow['verified_reviews'];
    }

    
} catch (Throwable $exception) {
    $statistics = [
        'total_reviews' => 0,
        'average_rating' => 0.0,
        'verified_reviews' => 0,
    ];
}

/*
|--------------------------------------------------------------------------
| Search conditions
|--------------------------------------------------------------------------
*/
$whereParts = [];
$countParameters = [];
$listParameters = [];

if ($q !== '') {
    $searchValue = '%' . trim($q) . '%';

    $whereParts[] = "(
        LOWER(TRIM(u.user_name)) LIKE LOWER(TRIM(:search_user))
        OR LOWER(TRIM(u.email)) LIKE LOWER(TRIM(:search_email))
        OR TRIM(u.phone_number) LIKE TRIM(:search_phone)
        OR LOWER(TRIM(p.product_name)) LIKE LOWER(TRIM(:search_product))
        OR LOWER(TRIM(v.vendor_name)) LIKE LOWER(TRIM(:search_vendor))
        OR LOWER(TRIM(m.market_name)) LIKE LOWER(TRIM(:search_market))
        OR LOWER(TRIM(r.comment)) LIKE LOWER(TRIM(:search_comment))
    )";

    $searchParameters = [
        'search_user' => $searchValue,
        'search_email' => $searchValue,
        'search_phone' => $searchValue,
        'search_product' => $searchValue,
        'search_vendor' => $searchValue,
        'search_market' => $searchValue,
        'search_comment' => $searchValue,
    ];

    $countParameters = array_merge($countParameters, $searchParameters);
    $listParameters = array_merge($listParameters, $searchParameters);
}

if ($ratingFilter !== 'all') {
    $whereParts[] = 'r.rating = :rating_filter';
    $countParameters['rating_filter'] = (int) $ratingFilter;
    $listParameters['rating_filter'] = (int) $ratingFilter;
}

if ($vendorFilter > 0) {
    $whereParts[] = 'v.vendor_id = :vendor_filter';
    $countParameters['vendor_filter'] = $vendorFilter;
    $listParameters['vendor_filter'] = $vendorFilter;
}

if ($marketFilter > 0) {
    $whereParts[] = 'm.market_id = :market_filter';
    $countParameters['market_filter'] = $marketFilter;
    $listParameters['market_filter'] = $marketFilter;
}



$verifiedExpression = "EXISTS (
    SELECT 1
    FROM purchase_process pp_verify
    INNER JOIN purchase_details pd_verify
        ON pd_verify.purchase_id = pp_verify.purchase_id
    WHERE pp_verify.user_id = r.user_id
      AND pd_verify.product_id = r.product_id
      AND pp_verify.order_status = 'confirmed'
)";


$whereSql = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$orderBySql = 'r.created_at DESC, r.review_id DESC';

/*
|--------------------------------------------------------------------------
| Count and list reviews
|--------------------------------------------------------------------------
*/
$totalFilteredReviews = 0;
$totalPages = 1;
$reviews = [];
$queryError = '';

$baseJoins = "
    FROM reviews r
    INNER JOIN users u
        ON u.user_id = r.user_id
    INNER JOIN products p
        ON p.product_id = r.product_id
    INNER JOIN vendors v
        ON v.vendor_id = p.vendor_id
    INNER JOIN categories c
        ON c.category_id = p.category_id
    INNER JOIN markets m
        ON m.market_id = c.market_id
    INNER JOIN cities ci
        ON ci.city_id = m.city_id
";

try {
    $countSql = 'SELECT COUNT(*) ' . $baseJoins . ' ' . $whereSql;
    $countStatement = $pdo->prepare($countSql);
    $countStatement->execute($countParameters);
    $totalFilteredReviews = (int) $countStatement->fetchColumn();

    $totalPages = max(1, (int) ceil($totalFilteredReviews / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;

    $listSql = "
        SELECT
            r.review_id,
            r.rating,
            r.comment,
            r.created_at,
            r.updated_at,
            u.user_id,
            u.user_name,
            u.email,
            u.phone_number,
            u.status AS user_status,
            p.product_id,
            p.product_name,
            p.price,
            v.vendor_id,
            v.vendor_name,
            c.category_id,
            c.category_name,
            m.market_id,
            m.market_name,
            ci.city_name,
            ci.administrative_division,
            (
                SELECT MIN(pph.photo_path)
                FROM product_photo pph
                WHERE pph.product_id = p.product_id
            ) AS product_photo,
            CASE WHEN {$verifiedExpression} THEN 1 ELSE 0 END AS is_verified_purchase
        {$baseJoins}
        {$whereSql}
        ORDER BY {$orderBySql}
        LIMIT :limit_value OFFSET :offset_value
    ";

    $listStatement = $pdo->prepare($listSql);

    foreach ($listParameters as $parameterName => $parameterValue) {
        if (is_int($parameterValue)) {
            $listStatement->bindValue(':' . $parameterName, $parameterValue, PDO::PARAM_INT);
        } else {
            $listStatement->bindValue(':' . $parameterName, $parameterValue, PDO::PARAM_STR);
        }
    }

    $listStatement->bindValue(':limit_value', $perPage, PDO::PARAM_INT);
    $listStatement->bindValue(':offset_value', $offset, PDO::PARAM_INT);
    $listStatement->execute();
    $reviews = $listStatement->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('Admin review list error: ' . $exception->getMessage());
    $reviews = [];
    $totalFilteredReviews = 0;
    $totalPages = 1;
    $queryError = 'The review list could not be loaded from the database.';
}

$showingFrom = $totalFilteredReviews > 0 ? (($page - 1) * $perPage) + 1 : 0;
$showingTo = min($page * $perPage, $totalFilteredReviews);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews | Farmers Market Admin</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    

<style>
    html,
    body {
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    html::-webkit-scrollbar,
    body::-webkit-scrollbar,
    .reviews-scroll-hidden::-webkit-scrollbar,
    .reviews-modal-scroll::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    .reviews-scroll-hidden,
    .reviews-modal-scroll {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .reviews-page-main {
        min-width: 0;
    }

    @media (min-width: 1024px) {
        .reviews-page-shell {
            margin-left: 176px !important;
            padding-left: 0 !important;
        }

        .reviews-page-main {
            padding: 12px 14px 24px !important;
        }

        .reviews-filter-card {
            border-radius: 14px !important;
            padding: 11px 13px !important;
            margin-bottom: 12px !important;
        }

        .reviews-filter-head {
            margin-bottom: 9px !important;
        }

        .reviews-filter-head h3 {
            font-size: 13px !important;
            line-height: 1.25 !important;
        }

        .reviews-filter-head p {
            margin-top: 3px !important;
            font-size: 8px !important;
        }

        /* Screen size / browser zoom 100% */
        .reviews-filter-form {
            display: grid !important;
            grid-template-columns:
                minmax(280px, 1.7fr)
                125px
                150px
                150px
                auto !important;
            gap: 8px !important;
            align-items: end !important;
            width: 100% !important;
        }

        .reviews-filter-form > * {
            min-width: 0 !important;
            grid-column: auto !important;
        }

        .reviews-filter-form label {
            margin-bottom: 4px !important;
            font-size: 8px !important;
        }

        .reviews-control,
        .reviews-filter-button,
        .reviews-clear-button {
            height: 34px !important;
            border-radius: 9px !important;
            font-size: 9px !important;
        }

        .reviews-search-input {
            padding-left: 34px !important;
            padding-right: 10px !important;
        }

        .reviews-search-icon {
            left: 12px !important;
            font-size: 10px !important;
        }

        .reviews-filter-actions {
            display: inline-flex !important;
            align-items: end !important;
            gap: 6px !important;
            width: auto !important;
            white-space: nowrap !important;
        }

        .reviews-filter-button {
            width: 76px !important;
            min-width: 76px !important;
            padding: 0 10px !important;
        }

        .reviews-clear-button {
            width: 68px !important;
            min-width: 68px !important;
            padding: 0 10px !important;
        }

        .reviews-list-panel {
            border-radius: 14px !important;
        }

        .reviews-list-head {
            padding: 10px 14px !important;
        }

        .reviews-list-head h3 {
            font-size: 14px !important;
        }

        .reviews-list-head p {
            margin-top: 3px !important;
            font-size: 9px !important;
        }

        .reviews-scroll-hidden {
            overflow-x: hidden !important;
        }

        .reviews-table {
            width: 100% !important;
            min-width: 0 !important;
            table-layout: fixed !important;
        }

        .reviews-table th {
            padding: 7px 8px !important;
            font-size: 8px !important;
        }

        .reviews-table td {
            padding: 9px 8px !important;
            vertical-align: middle !important;
        }

        /* REVIEW | CUSTOMER | PRODUCT | RATING & COMMENT | DATE | ACTIONS */
        .reviews-table th:nth-child(1),
        .reviews-table td:nth-child(1) { width: 11%; }

        .reviews-table th:nth-child(2),
        .reviews-table td:nth-child(2) { width: 19%; }

        .reviews-table th:nth-child(3),
        .reviews-table td:nth-child(3) { width: 22%; }

        .reviews-table th:nth-child(4),
        .reviews-table td:nth-child(4) { width: 29%; }

        /* Pull Date and Actions further left */
        .reviews-table th:nth-child(5),
        .reviews-table td:nth-child(5) {
            width: 13%;
            padding-left: 2px !important;
        }

        .reviews-table th:nth-child(6),
        .reviews-table td:nth-child(6) {
            width: 6%;
            text-align: left !important;
            padding-left: 2px !important;
            padding-right: 8px !important;
        }

        .reviews-review-no,
        .reviews-name,
        .reviews-product-name,
        .reviews-date {
            font-size: 9px !important;
        }

        .reviews-meta,
        .reviews-comment,
        .reviews-rating-score,
        .reviews-time {
            font-size: 8px !important;
            line-height: 1.4 !important;
        }

        .reviews-avatar {
            width: 30px !important;
            height: 30px !important;
            font-size: 8px !important;
        }

        .reviews-product-photo {
            width: 34px !important;
            height: 34px !important;
            border-radius: 8px !important;
        }

        .reviews-action-button {
            width: 27px !important;
            height: 27px !important;
            border-radius: 7px !important;
        }

        .reviews-action-button i {
            font-size: 9px !important;
        }
    }

    @media (max-width: 1023px) {
        .reviews-page-shell {
            margin-left: 0 !important;
            padding-left: 0 !important;
        }

        .reviews-filter-form {
            display: grid !important;
            grid-template-columns: 1fr !important;
        }

        .reviews-filter-actions {
            display: flex !important;
        }

        .reviews-filter-button,
        .reviews-clear-button {
            flex: 1 1 0 !important;
            width: auto !important;
        }

        .reviews-scroll-hidden {
            overflow-x: auto !important;
        }

        .reviews-table {
            min-width: 900px;
        }
    }
</style>



    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>
<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">
    <?php require_once'./sidebar.php'; ?>

    <div class="reviews-page-shell min-h-screen pl-64">
        <?php require_once './header.php'; ?>

        <main class="reviews-page-main p-4 sm:p-6 xl:p-7">

            <?php if ($queryError !== ''): ?>
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <div class="flex items-start gap-3">
                        <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                        <div>
                            <p class="font-bold">Reviews could not be loaded.</p>
                            <p class="mt-1 text-xs text-red-600">
                                Check that your imported database contains the reviews, users, products, vendors, categories, markets, cities, purchase_process, purchase_details, and product_photo tables.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <section class="mb-6">
                <div class="reviews-filter-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="reviews-filter-head mb-5 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-extrabold text-slate-900">Find Reviews</h3>
                            <p class="mt-1 text-xs text-slate-500">Search and narrow the review list.</p>
                        </div>
                    </div>

                    <form method="get" action="reviews.php" class="reviews-filter-form">
                        <div>
                            <label for="q" class="mb-1.5 block text-xs font-bold text-slate-600">Search</label>
                            <div class="relative">
                                <i class="reviews-search-icon fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                                <input id="q"
                                       name="q"
                                       type="search"
                                       value="<?= e($q) ?>"
                                       placeholder="Customer, product, vendor, market or comment"
                                       class="reviews-control reviews-search-input w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-green-500 focus:bg-white focus:ring-4 focus:ring-green-100">
                            </div>
                        </div>

                        <div>
                            <label for="rating" class="mb-1.5 block text-xs font-bold text-slate-600">Rating</label>
                            <select id="rating"
                                    name="rating"
                                    class="reviews-control w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-4 focus:ring-green-100">
                                <option value="all" <?= $ratingFilter === 'all' ? 'selected' : '' ?>>All ratings</option>
                                <?php for ($ratingOption = 5; $ratingOption >= 1; $ratingOption--): ?>
                                    <option value="<?= e($ratingOption) ?>" <?= $ratingFilter === (string) $ratingOption ? 'selected' : '' ?>>
                                        <?= e($ratingOption) ?> star<?= $ratingOption === 1 ? '' : 's' ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div>
                            <label for="vendor" class="mb-1.5 block text-xs font-bold text-slate-600">Vendor</label>
                            <select id="vendor"
                                    name="vendor"
                                    class="reviews-control w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-4 focus:ring-green-100">
                                <option value="0">All vendors</option>
                                <?php foreach ($vendors as $vendor): ?>
                                    <option value="<?= e($vendor['vendor_id']) ?>" <?= $vendorFilter === (int) $vendor['vendor_id'] ? 'selected' : '' ?>>
                                        <?= e($vendor['vendor_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="market" class="mb-1.5 block text-xs font-bold text-slate-600">Market</label>
                            <select id="market"
                                    name="market"
                                    class="reviews-control w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-4 focus:ring-green-100">
                                <option value="0">All markets</option>
                                <?php foreach ($markets as $market): ?>
                                    <option value="<?= e($market['market_id']) ?>" <?= $marketFilter === (int) $market['market_id'] ? 'selected' : '' ?>>
                                        <?= e($market['market_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="reviews-filter-actions">
                            <button type="submit"
                                    class="reviews-filter-button inline-flex items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-green-700 focus:outline-none focus:ring-4 focus:ring-green-200">
                                <i class="fa-solid fa-filter text-xs"></i>
                                Filter
                            </button>

                            <a href="reviews.php"
                               class="reviews-clear-button inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                                Clear
                            </a>
                        </div>
                    </form>
                </div>


            </section>

            <section class="reviews-list-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
                <div class="reviews-list-head border-b border-slate-200 px-5 py-4">
                    <div>
                        <h3 class="text-base font-extrabold text-slate-900">Review List</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Showing <?= e(number_format($showingFrom)) ?>–<?= e(number_format($showingTo)) ?> of <?= e(number_format($totalFilteredReviews)) ?> matching reviews
                        </p>
                    </div>
                </div>

                <div class="reviews-scroll-hidden overflow-x-auto">
                    <table class="reviews-table min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Review</th>
                                <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Customer</th>
                                <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Product</th>
                                <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Rating & Comment</th>
                                <th class="px-5 py-3.5 text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Date</th>
                                <th class="px-5 py-3.5 text-right text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <?php if (!$reviews): ?>
                                <tr>
                                    <td colspan="6" class="px-5 py-16 text-center">
                                        <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                                            <i class="fa-regular fa-star text-2xl"></i>
                                        </div>
                                        <h4 class="mt-4 text-base font-extrabold text-slate-800">No reviews found</h4>
                                        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                                            There are no reviews matching the current filters. Clear the filters or wait for customers to submit product feedback.
                                        </p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reviews as $review): ?>
                                    <?php
                                    $photoUrl = productPhotoUrl($review['product_photo']);
                                    $isVerified = (int) $review['is_verified_purchase'] === 1;
                                    $reviewPayload = [
                                        'reviewNumber' => reviewNumber((int) $review['review_id']),
                                        'customerName' => (string) $review['user_name'],
                                        'customerEmail' => (string) $review['email'],
                                        'customerPhone' => (string) $review['phone_number'],
                                        'customerStatus' => ucfirst((string) $review['user_status']),
                                        'productName' => (string) $review['product_name'],
                                        'productPrice' => number_format((float) $review['price'], 2) . ' MMK',
                                        'productPhoto' => $photoUrl,
                                        'vendorName' => (string) $review['vendor_name'],
                                        'categoryName' => (string) $review['category_name'],
                                        'marketName' => (string) $review['market_name'],
                                        'location' => (string) $review['city_name'] . ', ' . (string) $review['administrative_division'],
                                        'rating' => (int) $review['rating'],
                                        'comment' => (string) $review['comment'],
                                        'isVerified' => $isVerified,
                                        'createdAt' => reviewDateTimeLabel($review['created_at']),
                                        'updatedAt' => reviewDateTimeLabel($review['updated_at']),
                                    ];
                                    $reviewPayloadJson = json_encode($reviewPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    ?>
                                    <tr class="align-top transition hover:bg-slate-50/70">
                                        <td class="whitespace-nowrap px-5 py-4">
                                            <p class="reviews-review-no text-sm font-extrabold text-slate-900">
                                                <?= e(reviewNumber((int) $review['review_id'])) ?>
                                            </p>
                                            <p class="reviews-meta mt-1 text-xs text-slate-400">
                                                ID <?= e($review['review_id']) ?>
                                            </p>
                                        </td>

                                        <td class="px-5 py-4">
                                            <div class="flex min-w-[190px] items-center gap-3">
                                                <div class="reviews-avatar grid h-10 w-10 shrink-0 place-items-center rounded-full bg-green-100 text-xs font-extrabold text-green-700">
                                                    <?= e(reviewerInitials($review['user_name'])) ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="reviews-name truncate text-sm font-bold text-slate-800">
                                                        <?= e($review['user_name']) ?>
                                                    </p>
                                                    <p class="reviews-meta mt-0.5 truncate text-xs text-slate-400">
                                                        <?= e($review['email']) ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="px-5 py-4">
                                            <div class="flex min-w-[220px] items-center gap-3">
                                                <?php if ($photoUrl !== ''): ?>
                                                    <img src="<?= e($photoUrl) ?>" alt="<?= e($review['product_name']) ?>"
                                                         class="reviews-product-photo h-11 w-11 shrink-0 rounded-xl border border-slate-200 object-cover"
                                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='grid';">
                                                    <div class="hidden h-11 w-11 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600">
                                                        <i class="fa-solid fa-basket-shopping"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600">
                                                        <i class="fa-solid fa-basket-shopping"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="min-w-0">
                                                    <p class="reviews-product-name truncate text-sm font-bold text-slate-800">
                                                        <?= e($review['product_name']) ?>
                                                    </p>
                                                    <p class="reviews-meta mt-0.5 truncate text-xs text-slate-400">
                                                        <?= e($review['vendor_name']) ?> · <?= e($review['market_name']) ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="px-5 py-4">
                                            <div class="min-w-[250px] max-w-sm">
                                                <div class="flex items-center gap-2">
                                                    <?= reviewStarsHtml((int) $review['rating'], 'text-xs') ?>
                                                    <span class="reviews-rating-score text-xs font-extrabold text-slate-600">
                                                        <?= e($review['rating']) ?>.0
                                                    </span>
                                                </div>
                                                <p class="reviews-comment mt-2 text-sm leading-5 text-slate-600">
                                                    <?= e(truncateReviewComment($review['comment'])) ?>
                                                </p>
                                            </div>
                                        </td>

                                        <td class="whitespace-nowrap px-5 py-4">
                                            <p class="reviews-date text-sm font-semibold text-slate-700">
                                                <?= e(reviewDateLabel($review['created_at'])) ?>
                                            </p>
                                            <p class="reviews-time mt-1 text-xs text-slate-400">
                                                <?= e(date('h:i A', strtotime((string) $review['created_at']))) ?>
                                            </p>
                                        </td>

                                        <td class="whitespace-nowrap px-5 py-4 text-left">
                                            <button type="button"
                                                    data-review="<?= e($reviewPayloadJson !== false ? $reviewPayloadJson : '{}') ?>"
                                                    onclick="openReviewModal(this)"
                                                    class="reviews-action-button inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700"
                                                    title="View review details">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="flex flex-col gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs font-semibold text-slate-500">
                            Page <?= e(number_format($page)) ?> of <?= e(number_format($totalPages)) ?>
                        </p>

                        <nav class="flex flex-wrap items-center gap-1.5" aria-label="Review pagination">
                            <?php if ($page > 1): ?>
                                <a href="?<?= e(reviewsQueryString(['page' => $page - 1])) ?>"
                                   class="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600 transition hover:bg-slate-50">
                                    <i class="fa-solid fa-chevron-left text-[10px]"></i>
                                    Previous
                                </a>
                            <?php endif; ?>

                            <?php
                            $paginationStart = max(1, $page - 2);
                            $paginationEnd = min($totalPages, $page + 2);
                            ?>

                            <?php if ($paginationStart > 1): ?>
                                <a href="?<?= e(reviewsQueryString(['page' => 1])) ?>"
                                   class="grid h-9 min-w-9 place-items-center rounded-xl border border-slate-200 bg-white px-2 text-xs font-bold text-slate-600 transition hover:bg-slate-50">1</a>
                                <?php if ($paginationStart > 2): ?>
                                    <span class="px-1 text-slate-400">…</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($pageNumber = $paginationStart; $pageNumber <= $paginationEnd; $pageNumber++): ?>
                                <a href="?<?= e(reviewsQueryString(['page' => $pageNumber])) ?>"
                                   class="grid h-9 min-w-9 place-items-center rounded-xl px-2 text-xs font-extrabold transition
                                          <?= $pageNumber === $page
                                              ? 'bg-green-600 text-white shadow-lg shadow-green-600/20'
                                              : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?>">
                                    <?= e($pageNumber) ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($paginationEnd < $totalPages): ?>
                                <?php if ($paginationEnd < $totalPages - 1): ?>
                                    <span class="px-1 text-slate-400">…</span>
                                <?php endif; ?>
                                <a href="?<?= e(reviewsQueryString(['page' => $totalPages])) ?>"
                                   class="grid h-9 min-w-9 place-items-center rounded-xl border border-slate-200 bg-white px-2 text-xs font-bold text-slate-600 transition hover:bg-slate-50">
                                    <?= e($totalPages) ?>
                                </a>
                            <?php endif; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?<?= e(reviewsQueryString(['page' => $page + 1])) ?>"
                                   class="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600 transition hover:bg-slate-50">
                                    Next
                                    <i class="fa-solid fa-chevron-right text-[10px]"></i>
                                </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <div id="reviewModal" class="reviews-modal-scroll fixed inset-0 z-50 hidden overflow-y-auto" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm" onclick="closeReviewModal()"></div>

        <div class="relative flex min-h-full items-center justify-center p-4">
            <div class="reviews-modal-scroll relative max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-3xl bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-green-600">Review details</p>
                        <h3 id="modalReviewNumber" class="mt-1 text-lg font-extrabold text-slate-950">Review</h3>
                    </div>
                    <button type="button" onclick="closeReviewModal()"
                            class="grid h-10 w-10 place-items-center rounded-xl bg-slate-100 text-slate-500 transition hover:bg-slate-200 hover:text-slate-800">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="space-y-6 p-5 sm:p-6">
                    <section class="rounded-2xl bg-slate-50 p-5">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div id="modalStars" class="flex items-center gap-1"></div>
                                <p id="modalComment" class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700"></p>
                            </div>
                            <span id="modalVerifiedBadge" class="inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold"></span>
                        </div>
                    </section>

                    <div class="grid gap-5 md:grid-cols-2">
                        <section class="rounded-2xl border border-slate-200 p-5">
                            <div class="mb-4 flex items-center gap-3">
                                <div class="grid h-10 w-10 place-items-center rounded-xl bg-green-50 text-green-600">
                                    <i class="fa-solid fa-user"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Customer</p>
                                    <h4 id="modalCustomerName" class="text-sm font-extrabold text-slate-900"></h4>
                                </div>
                            </div>
                            <dl class="space-y-3 text-sm">
                                <div>
                                    <dt class="text-xs font-bold text-slate-400">Email</dt>
                                    <dd id="modalCustomerEmail" class="mt-1 break-all font-semibold text-slate-700"></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-bold text-slate-400">Phone</dt>
                                    <dd id="modalCustomerPhone" class="mt-1 font-semibold text-slate-700"></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-bold text-slate-400">Account status</dt>
                                    <dd id="modalCustomerStatus" class="mt-1 font-semibold text-slate-700"></dd>
                                </div>
                            </dl>
                        </section>

                        <section class="rounded-2xl border border-slate-200 p-5">
                            <div class="mb-4 flex items-center gap-3">
                                <img id="modalProductPhoto" src="" alt="Product" class="hidden h-12 w-12 rounded-xl border border-slate-200 object-cover">
                                <div id="modalProductFallback" class="grid h-12 w-12 place-items-center rounded-xl bg-green-50 text-green-600">
                                    <i class="fa-solid fa-basket-shopping"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Product</p>
                                    <h4 id="modalProductName" class="truncate text-sm font-extrabold text-slate-900"></h4>
                                </div>
                            </div>
                            <dl class="space-y-3 text-sm">
                                <div>
                                    <dt class="text-xs font-bold text-slate-400">Vendor</dt>
                                    <dd id="modalVendorName" class="mt-1 font-semibold text-slate-700"></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-bold text-slate-400">Category</dt>
                                    <dd id="modalCategoryName" class="mt-1 font-semibold text-slate-700"></dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-bold text-slate-400">Price</dt>
                                    <dd id="modalProductPrice" class="mt-1 font-semibold text-slate-700"></dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <section class="grid gap-4 rounded-2xl border border-slate-200 p-5 sm:grid-cols-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Market</p>
                            <p id="modalMarketName" class="mt-1 text-sm font-bold text-slate-800"></p>
                        </div>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Location</p>
                            <p id="modalLocation" class="mt-1 text-sm font-bold text-slate-800"></p>
                        </div>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Submitted</p>
                            <p id="modalCreatedAt" class="mt-1 text-sm font-bold text-slate-800"></p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <script>
        function setText(id, value) {
            var element = document.getElementById(id);

            if (element) {
                element.textContent = value || '—';
            }
        }

        function renderModalStars(rating) {
            var container = document.getElementById('modalStars');

            if (!container) {
                return;
            }

            container.innerHTML = '';

            for (var star = 1; star <= 5; star++) {
                var icon = document.createElement('i');
                icon.className = 'fa-solid fa-star text-lg ' + (star <= rating ? 'text-amber-400' : 'text-slate-200');
                container.appendChild(icon);
            }

            var score = document.createElement('span');
            score.className = 'ml-2 text-sm font-extrabold text-slate-700';
            score.textContent = rating + '.0 / 5';
            container.appendChild(score);
        }

        function openReviewModal(button) {
            var modal = document.getElementById('reviewModal');
            var rawReview = button.getAttribute('data-review');
            var review = {};

            try {
                review = JSON.parse(rawReview || '{}');
            } catch (error) {
                return;
            }

            setText('modalReviewNumber', review.reviewNumber);
            setText('modalComment', review.comment);
            setText('modalCustomerName', review.customerName);
            setText('modalCustomerEmail', review.customerEmail);
            setText('modalCustomerPhone', review.customerPhone);
            setText('modalCustomerStatus', review.customerStatus);
            setText('modalProductName', review.productName);
            setText('modalVendorName', review.vendorName);
            setText('modalCategoryName', review.categoryName);
            setText('modalProductPrice', review.productPrice);
            setText('modalMarketName', review.marketName);
            setText('modalLocation', review.location);
            setText('modalCreatedAt', review.createdAt);
            renderModalStars(Number(review.rating) || 0);

            var badge = document.getElementById('modalVerifiedBadge');

            if (review.isVerified) {
                badge.className = 'inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 ring-1 ring-inset ring-blue-600/10';
                badge.innerHTML = '<i class="fa-solid fa-circle-check"></i> Verified purchase';
            } else {
                badge.className = 'inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600 ring-1 ring-inset ring-slate-500/10';
                badge.innerHTML = '<i class="fa-solid fa-minus"></i> Unverified review';
            }

            var productPhoto = document.getElementById('modalProductPhoto');
            var productFallback = document.getElementById('modalProductFallback');

            if (review.productPhoto) {
                productPhoto.src = review.productPhoto;
                productPhoto.classList.remove('hidden');
                productFallback.classList.add('hidden');
                productPhoto.onerror = function () {
                    productPhoto.classList.add('hidden');
                    productFallback.classList.remove('hidden');
                };
            } else {
                productPhoto.src = '';
                productPhoto.classList.add('hidden');
                productFallback.classList.remove('hidden');
            }

            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('overflow-hidden');
        }

        function closeReviewModal() {
            var modal = document.getElementById('reviewModal');

            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeReviewModal();
            }
        });
    </script>
</body>
</html>