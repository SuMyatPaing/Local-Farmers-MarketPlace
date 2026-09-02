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

$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$ratingFilter = isset($_GET['rating']) ? (int) $_GET['rating'] : 0;
$marketFilter = isset($_GET['market_id']) ? (int) $_GET['market_id'] : 0;
$sort = isset($_GET['sort']) ? strtolower(trim((string) $_GET['sort'])) : 'newest';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 10;

$allowedSorts = array('newest', 'oldest', 'highest', 'lowest');

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

if ($ratingFilter < 1 || $ratingFilter > 5) {
    $ratingFilter = 0;
}

$marketStatement = $pdo->prepare(
    "SELECT DISTINCT
        m.market_id,
        m.market_name
     FROM products p
     INNER JOIN categories c ON c.category_id = p.category_id
     INNER JOIN markets m ON m.market_id = c.market_id
     WHERE p.vendor_id = :vendor_id
     ORDER BY m.market_name ASC"
);

$marketStatement->execute(array('vendor_id' => $vendorId));
$markets = $marketStatement->fetchAll();

$allowedMarketIds = array();

foreach ($markets as $market) {
    $allowedMarketIds[(int) $market['market_id']] = true;
}

if ($marketFilter > 0 && !isset($allowedMarketIds[$marketFilter])) {
    $marketFilter = 0;
}

$whereParts = array('p.vendor_id = :vendor_id');
$parameters = array('vendor_id' => $vendorId);

if ($search !== '') {
    $whereParts[] = '(
        r.comment LIKE :search_comment OR
        u.user_name LIKE :search_customer OR
        p.product_name LIKE :search_product OR
        c.category_name LIKE :search_category OR
        m.market_name LIKE :search_market
    )';

    $searchValue = '%' . $search . '%';
    $parameters['search_comment'] = $searchValue;
    $parameters['search_customer'] = $searchValue;
    $parameters['search_product'] = $searchValue;
    $parameters['search_category'] = $searchValue;
    $parameters['search_market'] = $searchValue;
}

if ($ratingFilter > 0) {
    $whereParts[] = 'r.rating = :rating';
    $parameters['rating'] = $ratingFilter;
}

if ($marketFilter > 0) {
    $whereParts[] = 'm.market_id = :market_id';
    $parameters['market_id'] = $marketFilter;
}

$whereSql = implode(' AND ', $whereParts);

$orderBySql = 'r.created_at DESC';

if ($sort === 'oldest') {
    $orderBySql = 'r.created_at ASC';
} elseif ($sort === 'highest') {
    $orderBySql = 'r.rating DESC, r.created_at DESC';
} elseif ($sort === 'lowest') {
    $orderBySql = 'r.rating ASC, r.created_at DESC';
}

$countStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM reviews r
     INNER JOIN products p ON p.product_id = r.product_id
     INNER JOIN categories c ON c.category_id = p.category_id
     INNER JOIN markets m ON m.market_id = c.market_id
     INNER JOIN users u ON u.user_id = r.user_id
     WHERE {$whereSql}"
);

$countStatement->execute($parameters);
$totalFilteredReviews = (int) $countStatement->fetchColumn();

$totalPages = max(1, (int) ceil($totalFilteredReviews / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$listStatement = $pdo->prepare(
    "SELECT
        r.review_id,
        r.rating,
        r.comment,
        r.created_at,
        r.updated_at,
        u.user_id,
        u.user_name,
        u.email,
        p.product_id,
        p.product_name,
        p.price,
        c.category_name,
        m.market_id,
        m.market_name,
        (
            SELECT pp.photo_path
            FROM product_photo pp
            WHERE pp.product_id = p.product_id
            ORDER BY pp.product_photo_id ASC
            LIMIT 1
        ) AS product_photo
     FROM reviews r
     INNER JOIN products p ON p.product_id = r.product_id
     INNER JOIN categories c ON c.category_id = p.category_id
     INNER JOIN markets m ON m.market_id = c.market_id
     INNER JOIN users u ON u.user_id = r.user_id
     WHERE {$whereSql}
     ORDER BY {$orderBySql}
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

$reviews = $listStatement->fetchAll();

$selectedReview = null;
$viewId = isset($_GET['view']) ? (int) $_GET['view'] : 0;

if ($viewId > 0) {
    $detailStatement = $pdo->prepare(
        "SELECT
            r.review_id,
            r.rating,
            r.comment,
            r.created_at,
            r.updated_at,
            u.user_name,
            u.email,
            p.product_id,
            p.product_name,
            p.price,
            p.description AS product_description,
            c.category_name,
            m.market_name,
            (
                SELECT pp.photo_path
                FROM product_photo pp
                WHERE pp.product_id = p.product_id
                ORDER BY pp.product_photo_id ASC
                LIMIT 1
            ) AS product_photo
         FROM reviews r
         INNER JOIN products p ON p.product_id = r.product_id
         INNER JOIN categories c ON c.category_id = p.category_id
         INNER JOIN markets m ON m.market_id = c.market_id
         INNER JOIN users u ON u.user_id = r.user_id
         WHERE r.review_id = :review_id
           AND p.vendor_id = :vendor_id
         LIMIT 1"
    );

    $detailStatement->execute(array(
        'review_id' => $viewId,
        'vendor_id' => $vendorId
    ));

    $selectedReview = $detailStatement->fetch();
}

function vendor_review_url($overrides)
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

    return 'reviews.php' . ($query !== '' ? '?' . $query : '');
}

$showingFrom = $totalFilteredReviews > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalFilteredReviews);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews | Farmers Market Vendor</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">

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

        .review-table-scroll {
            overflow-x: auto;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .review-table-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .review-filter-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: .75rem;
            align-items: end;
        }

        @media (min-width: 760px) {
            .review-filter-grid {
                grid-template-columns:
                    minmax(260px, 1.8fr)
                    minmax(150px, .8fr)
                    minmax(190px, 1fr)
                    minmax(170px, .9fr)
                    110px
                    88px;
            }
        }

        .review-table {
            width: 100%;
            min-width: 1080px;
            border-collapse: collapse;
        }

        .review-table th {
            padding: 12px 14px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #64748b;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .025em;
            text-align: left;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .review-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .review-table tbody tr {
            transition: background-color .18s ease;
        }

        .review-table tbody tr:hover {
            background: #f8fafc;
        }

        .review-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .review-id {
            color: #0f172a;
            font-size: 11px;
            font-weight: 900;
        }

        .review-sub-id {
            margin-top: 2px;
            color: #94a3b8;
            font-size: 9px;
        }

        .review-customer {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 170px;
        }

        .review-avatar {
            display: grid;
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            place-items: center;
            border-radius: 999px;
            background: #dcfce7;
            color: #15803d;
            font-size: 10px;
            font-weight: 900;
        }

        .review-name {
            color: #0f172a;
            font-size: 11px;
            font-weight: 800;
        }

        .review-email {
            margin-top: 2px;
            max-width: 180px;
            overflow: hidden;
            color: #94a3b8;
            font-size: 9px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .review-product {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 225px;
        }

        .review-product-image {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f0fdf4;
        }

        .review-product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .review-product-placeholder {
            display: grid;
            width: 100%;
            height: 100%;
            place-items: center;
            color: #86efac;
        }

        .review-product-name {
            color: #0f172a;
            font-size: 11px;
            font-weight: 800;
        }

        .review-product-meta {
            margin-top: 2px;
            max-width: 180px;
            overflow: hidden;
            color: #94a3b8;
            font-size: 9px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .review-rating-wrap {
            min-width: 260px;
        }

        .review-stars {
            display: flex;
            align-items: center;
            gap: 3px;
            color: #f59e0b;
            font-size: 11px;
        }

        .review-rating-number {
            margin-left: 6px;
            color: #0f172a;
            font-size: 9px;
            font-weight: 900;
        }

        .review-comment {
            margin-top: 5px;
            max-width: 310px;
            overflow: hidden;
            color: #475569;
            font-size: 9px;
            line-height: 1.45;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .review-date {
            color: #334155;
            font-size: 10px;
            white-space: nowrap;
        }

        .review-time {
            margin-top: 2px;
            color: #94a3b8;
            font-size: 8px;
        }

        .review-action {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #ffffff;
            color: #64748b;
            transition: .18s ease;
        }

        .review-action:hover {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #15803d;
        }
    </style>
</head>

<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">

<div class="min-h-screen">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="min-h-screen lg:ml-64">
        <?php require __DIR__ . '/header.php'; ?>

        <main class="px-4 pb-10 pt-5 sm:px-6 xl:px-7">

            <!-- Find Reviews -->
            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                <div class="mb-3">
                    <h2 class="text-base font-extrabold text-slate-950">
                        Find Reviews
                    </h2>

                    <p class="mt-1 text-[10px] text-slate-400">
                        Search and narrow reviews for your products.
                    </p>
                </div>

                <form method="get"
                      action="reviews.php"
                      class="review-filter-grid">

                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-600">
                            Search
                        </label>

                        <div class="relative">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-400"></i>

                            <input type="search"
                                   name="q"
                                   value="<?php echo vendor_page_e($search); ?>"
                                   placeholder="Customer, product, market or comment"
                                   class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm text-slate-700 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-600">
                            Rating
                        </label>

                        <select name="rating"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                            <option value="0">All ratings</option>

                            <?php for ($rating = 5; $rating >= 1; $rating--): ?>
                                <option value="<?php echo $rating; ?>"
                                    <?php echo $ratingFilter === $rating ? 'selected' : ''; ?>>
                                    <?php echo $rating; ?> stars
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-600">
                            Market
                        </label>

                        <select name="market_id"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                            <option value="0">All markets</option>

                            <?php foreach ($markets as $market): ?>
                                <option value="<?php echo (int) $market['market_id']; ?>"
                                    <?php echo $marketFilter === (int) $market['market_id'] ? 'selected' : ''; ?>>
                                    <?php echo vendor_page_e($market['market_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-600">
                            Sort
                        </label>

                        <select name="sort"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>
                                Newest first
                            </option>
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>
                                Oldest first
                            </option>
                            <option value="highest" <?php echo $sort === 'highest' ? 'selected' : ''; ?>>
                                Highest rating
                            </option>
                            <option value="lowest" <?php echo $sort === 'lowest' ? 'selected' : ''; ?>>
                                Lowest rating
                            </option>
                        </select>
                    </div>

                    <button type="submit"
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-sm font-bold text-white transition hover:bg-green-700">
                        <i class="fa-solid fa-filter text-xs"></i>
                        Filter
                    </button>

                    <a href="reviews.php"
                       class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">
                        <i class="fa-solid fa-rotate-left text-xs"></i>
                        Clear
                    </a>
                </form>
            </section>

            <!-- Review List -->
            <section class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <h2 class="text-base font-extrabold text-slate-950">
                            Review List
                        </h2>

                        <p class="mt-1 text-[10px] text-slate-400">
                            Showing
                            <?php echo number_format($showingFrom); ?>–<?php echo number_format($showingTo); ?>
                            of
                            <?php echo number_format($totalFilteredReviews); ?>
                            matching reviews
                        </p>
                    </div>

                    <span class="rounded-lg bg-slate-50 px-3 py-2 text-[10px] font-semibold text-slate-500">
                        <i class="fa-solid fa-shield-halved mr-1 text-green-600"></i>
                        Read only
                    </span>
                </div>

                <?php if (empty($reviews)): ?>

                    <div class="px-5 py-14 text-center">
                        <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-green-50 text-green-600">
                            <i class="fa-regular fa-comment-dots text-xl"></i>
                        </div>

                        <p class="mt-4 text-sm font-bold text-slate-700">
                            No reviews found
                        </p>

                        <p class="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-400">
                            Customer reviews for your products will appear here.
                        </p>

                        <?php if (
                            $search !== '' ||
                            $ratingFilter > 0 ||
                            $marketFilter > 0 ||
                            $sort !== 'newest'
                        ): ?>
                            <a href="reviews.php"
                               class="mt-4 inline-flex items-center gap-2 rounded-xl border border-green-200 bg-white px-4 py-2 text-xs font-bold text-green-700 transition hover:bg-green-50">
                                <i class="fa-solid fa-rotate-left text-[10px]"></i>
                                Clear Filters
                            </a>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <div class="review-table-scroll">
                        <table class="review-table">
                            <thead>
                                <tr>
                                    <th>Review</th>
                                    <th>Customer</th>
                                    <th>Product</th>
                                    <th>Rating &amp; Comment</th>
                                    <th>Date</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($reviews as $review): ?>
                                    <?php
                                    $photoUrl = vendor_page_photo_url($review['product_photo']);
                                    ?>

                                    <tr>
                                        <td>
                                            <div class="review-id">
                                                REV-<?php echo str_pad(
                                                    (string) (int) $review['review_id'],
                                                    5,
                                                    '0',
                                                    STR_PAD_LEFT
                                                ); ?>
                                            </div>

                                            <div class="review-sub-id">
                                                ID <?php echo number_format((int) $review['review_id']); ?>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="review-customer">
                                                <span class="review-avatar">
                                                    <?php echo vendor_page_e(
                                                        vendor_page_initials($review['user_name'])
                                                    ); ?>
                                                </span>

                                                <div class="min-w-0">
                                                    <div class="review-name">
                                                        <?php echo vendor_page_e($review['user_name']); ?>
                                                    </div>

                                                    <div class="review-email">
                                                        <?php echo vendor_page_e($review['email']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="review-product">
                                                <div class="review-product-image">
                                                    <?php if ($photoUrl !== ''): ?>
                                                        <img src="<?php echo vendor_page_e($photoUrl); ?>"
                                                             alt="<?php echo vendor_page_e($review['product_name']); ?>">
                                                    <?php else: ?>
                                                        <div class="review-product-placeholder">
                                                            <i class="fa-solid fa-basket-shopping"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="min-w-0">
                                                    <div class="review-product-name">
                                                        <?php echo vendor_page_e($review['product_name']); ?>
                                                    </div>

                                                    <div class="review-product-meta">
                                                        <?php echo vendor_page_e(
                                                            $review['category_name'] .
                                                            ' · ' .
                                                            $review['market_name']
                                                        ); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="review-rating-wrap">
                                                <div class="review-stars">
                                                    <?php for ($star = 1; $star <= 5; $star++): ?>
                                                        <i class="fa-solid fa-star <?php echo $star > (int) $review['rating'] ? 'text-slate-200' : ''; ?>"></i>
                                                    <?php endfor; ?>

                                                    <span class="review-rating-number">
                                                        <?php echo number_format((float) $review['rating'], 1); ?>
                                                    </span>
                                                </div>

                                                <div class="review-comment">
                                                    <?php echo vendor_page_e($review['comment']); ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="review-date">
                                                <?php echo vendor_page_e(
                                                    vendor_page_date(
                                                        $review['created_at'],
                                                        'M d, Y'
                                                    )
                                                ); ?>
                                            </div>

                                            <div class="review-time">
                                                <?php echo vendor_page_e(
                                                    vendor_page_date(
                                                        $review['created_at'],
                                                        'h:i A'
                                                    )
                                                ); ?>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="flex justify-end">
                                                <a href="<?php echo vendor_page_e(
                                                    vendor_review_url(array(
                                                        'view' => (int) $review['review_id']
                                                    ))
                                                ); ?>"
                                                   title="View review details"
                                                   aria-label="View review details"
                                                   class="review-action">
                                                    <i class="fa-regular fa-eye text-xs"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-slate-400">
                            Page <?php echo number_format($page); ?>
                            of <?php echo number_format($totalPages); ?>
                        </p>

                        <div class="flex items-center gap-1">
                            <a href="<?php echo vendor_page_e(
                                vendor_review_url(array(
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
                                    vendor_review_url(array(
                                        'page' => $pageNumber
                                    ))
                                ); ?>"
                                   class="grid h-9 w-9 place-items-center rounded-lg text-xs font-bold <?php echo $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50'; ?>">
                                    <?php echo $pageNumber; ?>
                                </a>
                            <?php endfor; ?>

                            <a href="<?php echo vendor_page_e(
                                vendor_review_url(array(
                                    'page' => min($totalPages, $page + 1)
                                ))
                            ); ?>"
                               class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50 <?php echo $page >= $totalPages ? 'pointer-events-none opacity-40' : ''; ?>">
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

<?php if ($selectedReview): ?>
    <?php
    $selectedPhoto = vendor_page_photo_url($selectedReview['product_photo']);
    ?>

    <div class="fixed inset-0 z-[70] overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm">
        <div class="mx-auto flex min-h-full max-w-2xl items-center justify-center">
            <article class="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">

                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-green-600">
                            Review Details
                        </p>

                        <h2 class="mt-1 text-xl font-extrabold text-slate-950">
                            <?php echo vendor_page_e($selectedReview['product_name']); ?>
                        </h2>
                    </div>

                    <a href="<?php echo vendor_page_e(
                        vendor_review_url(array())
                    ); ?>"
                       class="grid h-10 w-10 place-items-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                </div>

                <div class="p-5">
                    <div class="flex gap-4">
                        <div class="h-24 w-24 shrink-0 overflow-hidden rounded-xl bg-green-50">
                            <?php if ($selectedPhoto !== ''): ?>
                                <img src="<?php echo vendor_page_e($selectedPhoto); ?>"
                                     alt="<?php echo vendor_page_e($selectedReview['product_name']); ?>"
                                     class="h-full w-full object-cover">
                            <?php else: ?>
                                <div class="grid h-full place-items-center text-green-300">
                                    <i class="fa-solid fa-basket-shopping text-2xl"></i>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-extrabold text-slate-800">
                                <?php echo vendor_page_e($selectedReview['product_name']); ?>
                            </p>

                            <p class="mt-1 text-xs text-slate-400">
                                <?php echo vendor_page_e(
                                    $selectedReview['category_name'] .
                                    ' · ' .
                                    $selectedReview['market_name']
                                ); ?>
                            </p>

                            <p class="mt-2 text-sm font-bold text-green-700">
                                <?php echo number_format((float) $selectedReview['price']); ?> MMK
                            </p>
                        </div>
                    </div>

                    <div class="mt-5 rounded-xl bg-slate-50 p-5">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-bold text-slate-800">
                                    <?php echo vendor_page_e($selectedReview['user_name']); ?>
                                </p>

                                <p class="mt-1 text-[10px] text-slate-400">
                                    <?php echo vendor_page_e($selectedReview['email']); ?>
                                </p>
                            </div>

                            <div class="text-right">
                                <div class="flex gap-1 text-sm text-amber-400">
                                    <?php for ($star = 1; $star <= 5; $star++): ?>
                                        <i class="fa-solid fa-star <?php echo $star > (int) $selectedReview['rating'] ? 'text-slate-200' : ''; ?>"></i>
                                    <?php endfor; ?>
                                </div>

                                <p class="mt-1 text-[10px] text-slate-400">
                                    <?php echo vendor_page_e(
                                        vendor_page_date(
                                            $selectedReview['created_at'],
                                            'M j, Y'
                                        )
                                    ); ?>
                                </p>
                            </div>
                        </div>

                        <p class="mt-5 text-base leading-7 text-slate-600">
                            “<?php echo vendor_page_e($selectedReview['comment']); ?>”
                        </p>
                    </div>
                </div>
            </article>
        </div>
    </div>
<?php endif; ?>

</body>
</html>