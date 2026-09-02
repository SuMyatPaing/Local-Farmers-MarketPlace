<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/marketplace.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Public Products Page
|--------------------------------------------------------------------------
| Save as:
| C:\xampp\htdocs\farmer_marketplace\products.php
|--------------------------------------------------------------------------
*/

if (!function_exists('product_page_e')) {
    function product_page_e($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('product_page_redirect')) {
    function product_page_redirect($location)
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('product_page_photo_url')) {
    function product_page_photo_url($path)
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

if (!function_exists('product_page_query_url')) {
    function product_page_query_url($overrides)
    {
        $parameters = $_GET;

        unset($parameters['view']);
        unset($parameters['review_page']);

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($parameters[$key]);
            } else {
                $parameters[$key] = $value;
            }
        }

        $query = http_build_query($parameters);

        return 'products.php' .
            ($query !== '' ? '?' . $query : '');
    }
}

if (!function_exists('product_page_csrf_token')) {
    function product_page_csrf_token()
    {
        if (
            !isset($_SESSION['product_page_csrf']) ||
            !is_string($_SESSION['product_page_csrf']) ||
            $_SESSION['product_page_csrf'] === ''
        ) {
            $_SESSION['product_page_csrf'] =
                bin2hex(random_bytes(32));
        }

        return $_SESSION['product_page_csrf'];
    }
}

if (!function_exists('product_page_verify_csrf')) {
    function product_page_verify_csrf($token)
    {
        return isset($_SESSION['product_page_csrf']) &&
            is_string($token) &&
            hash_equals(
                $_SESSION['product_page_csrf'],
                $token
            );
    }
}

if (!function_exists('product_page_flash')) {
    function product_page_flash($type, $message)
    {
        $_SESSION['product_page_flash'] = array(
            'type' => $type,
            'message' => $message
        );
    }
}

/*
|--------------------------------------------------------------------------
| Public Browsing
|--------------------------------------------------------------------------
| Guests may browse products and open Product Details.
| A customer Sign In is required only for Add to Cart or checkout actions.
|--------------------------------------------------------------------------
*/

$isLoggedIn = isset($_SESSION['user_id']) &&
    (int) $_SESSION['user_id'] > 0 &&
    isset($_SESSION['role']);

$role = $isLoggedIn
    ? strtolower((string) $_SESSION['role'])
    : '';

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
| Add Product to Session Cart
|--------------------------------------------------------------------------
| Cart format:
| $_SESSION['cart'][PRODUCT_ID] = QUANTITY
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action'])
        ? trim((string) $_POST['action'])
        : '';

    if ($action === 'add_to_cart') {
        $productId = isset($_POST['product_id'])
            ? max(0, (int) $_POST['product_id'])
            : 0;

        if (!$isLoggedIn) {
            $next = rawurlencode(
                'products.php?view=' . $productId
            );

            product_page_redirect(
                'signin.php?notice=login_required&next=' .
                $next
            );
        }

        $csrfToken = isset($_POST['csrf_token'])
            ? (string) $_POST['csrf_token']
            : '';

        if (!product_page_verify_csrf($csrfToken)) {
            product_page_flash(
                'error',
                'Your form session expired. Please try again.'
            );

            product_page_redirect('products.php');
        }

        if ($role !== 'user') {
            product_page_flash(
                'error',
                'Only customer accounts can add products to the cart.'
            );

            product_page_redirect('products.php');
        }

        $quantity = isset($_POST['quantity'])
            ? max(1, (int) $_POST['quantity'])
            : 1;

        $cartProductStatement = $pdo->prepare(
            "SELECT
                p.product_id,
                p.product_name,
                p.stock_quantity
             FROM products p
             INNER JOIN vendors v
                ON v.vendor_id = p.vendor_id
             INNER JOIN users vu
                ON vu.user_id = v.user_id
             WHERE p.product_id = :product_id
               AND v.status = 'accepted'
               AND vu.status = 'active'
             LIMIT 1"
        );

        $cartProductStatement->execute(array(
            'product_id' => $productId
        ));

        $cartProduct = $cartProductStatement->fetch();

        if (!$cartProduct) {
            product_page_flash(
                'error',
                'The selected product is not available.'
            );

            product_page_redirect('products.php');
        }

        $stockQuantity =
            (int) $cartProduct['stock_quantity'];

        if ($stockQuantity <= 0) {
            product_page_flash(
                'error',
                'This product is currently out of stock.'
            );

            product_page_redirect('products.php');
        }

        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = array();
        }

        $currentQuantity =
            isset($_SESSION['cart'][$productId])
                ? (int) $_SESSION['cart'][$productId]
                : 0;

        $newQuantity = min(
            $stockQuantity,
            $currentQuantity + $quantity
        );

        $_SESSION['cart'][$productId] =
            $newQuantity;

        product_page_flash(
            'success',
            $cartProduct['product_name'] .
            ' was added to your cart.'
        );

        product_page_redirect('cart.php');
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = isset($_GET['q'])
    ? trim((string) $_GET['q'])
    : '';

$marketId = isset($_GET['market_id'])
    ? max(0, (int) $_GET['market_id'])
    : 0;

$categoryId = isset($_GET['category_id'])
    ? max(0, (int) $_GET['category_id'])
    : 0;

$cityId = isset($_GET['city_id'])
    ? max(0, (int) $_GET['city_id'])
    : 0;

$stockFilter = isset($_GET['stock'])
    ? strtolower(trim((string) $_GET['stock']))
    : 'all';

$sort = isset($_GET['sort'])
    ? strtolower(trim((string) $_GET['sort']))
    : 'newest';

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$allowedStockFilters = array(
    'all',
    'available',
    'low',
    'out'
);

if (!in_array(
    $stockFilter,
    $allowedStockFilters,
    true
)) {
    $stockFilter = 'all';
}

$sortOptions = array(
    'newest' => 'p.created_at DESC, p.product_id DESC',
    'price_low' => 'p.price ASC, p.product_name ASC',
    'price_high' => 'p.price DESC, p.product_name ASC',
    'name' => 'p.product_name ASC',
    'rating' => 'average_rating DESC, review_count DESC, p.product_name ASC'
);

if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}

$orderSql = $sortOptions[$sort];
$perPage = 12;

$whereParts = array(
    "v.status = 'accepted'",
    "vu.status = 'active'"
);

$queryParameters = array();

if ($search !== '') {
    $whereParts[] = '(
        p.product_name LIKE :search_product OR
        p.description LIKE :search_description OR
        c.category_name LIKE :search_category OR
        v.vendor_name LIKE :search_vendor OR
        m.market_name LIKE :search_market OR
        ci.city_name LIKE :search_city
    )';

    $searchValue = '%' . $search . '%';

    $queryParameters['search_product'] =
        $searchValue;

    $queryParameters['search_description'] =
        $searchValue;

    $queryParameters['search_category'] =
        $searchValue;

    $queryParameters['search_vendor'] =
        $searchValue;

    $queryParameters['search_market'] =
        $searchValue;

    $queryParameters['search_city'] =
        $searchValue;
}

if ($marketId > 0) {
    $whereParts[] = 'm.market_id = :market_id';
    $queryParameters['market_id'] = $marketId;
}

if ($categoryId > 0) {
    $whereParts[] = 'c.category_id = :category_id';
    $queryParameters['category_id'] = $categoryId;
}

if ($cityId > 0) {
    $whereParts[] = 'ci.city_id = :city_id';
    $queryParameters['city_id'] = $cityId;
}

if ($stockFilter === 'available') {
    $whereParts[] = 'p.stock_quantity > 10';
} elseif ($stockFilter === 'low') {
    $whereParts[] =
        'p.stock_quantity BETWEEN 1 AND 10';
} elseif ($stockFilter === 'out') {
    $whereParts[] = 'p.stock_quantity = 0';
}

$whereSql = implode(' AND ', $whereParts);

/*
|--------------------------------------------------------------------------
| Filter Options
|--------------------------------------------------------------------------
*/

$marketStatement = $pdo->query(
    "SELECT DISTINCT
        m.market_id,
        m.market_name,
        ci.city_name
     FROM products p
     INNER JOIN vendors v
        ON v.vendor_id = p.vendor_id
     INNER JOIN users vu
        ON vu.user_id = v.user_id
     INNER JOIN categories c
        ON c.category_id = p.category_id
     INNER JOIN markets m
        ON m.market_id = c.market_id
     INNER JOIN cities ci
        ON ci.city_id = m.city_id
     WHERE v.status = 'accepted'
       AND vu.status = 'active'
     ORDER BY m.market_name ASC"
);

$markets = $marketStatement->fetchAll();

$categoryStatement = $pdo->query(
    "SELECT DISTINCT
        c.category_id,
        c.category_name,
        m.market_name
     FROM products p
     INNER JOIN vendors v
        ON v.vendor_id = p.vendor_id
     INNER JOIN users vu
        ON vu.user_id = v.user_id
     INNER JOIN categories c
        ON c.category_id = p.category_id
     INNER JOIN markets m
        ON m.market_id = c.market_id
     WHERE v.status = 'accepted'
       AND vu.status = 'active'
     ORDER BY
        c.category_name ASC,
        m.market_name ASC"
);

$categories = $categoryStatement->fetchAll();

$cityStatement = $pdo->query(
    "SELECT DISTINCT
        ci.city_id,
        ci.city_name,
        ci.administrative_division
     FROM products p
     INNER JOIN vendors v
        ON v.vendor_id = p.vendor_id
     INNER JOIN users vu
        ON vu.user_id = v.user_id
     INNER JOIN categories c
        ON c.category_id = p.category_id
     INNER JOIN markets m
        ON m.market_id = c.market_id
     INNER JOIN cities ci
        ON ci.city_id = m.city_id
     WHERE v.status = 'accepted'
       AND vu.status = 'active'
     ORDER BY ci.city_name ASC"
);

$cities = $cityStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Product List
|--------------------------------------------------------------------------
*/

$countStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM products p
     INNER JOIN vendors v
        ON v.vendor_id = p.vendor_id
     INNER JOIN users vu
        ON vu.user_id = v.user_id
     INNER JOIN categories c
        ON c.category_id = p.category_id
     INNER JOIN markets m
        ON m.market_id = c.market_id
     INNER JOIN cities ci
        ON ci.city_id = m.city_id
     WHERE {$whereSql}"
);

$countStatement->execute($queryParameters);

$totalFilteredProducts =
    (int) $countStatement->fetchColumn();

$totalPages = max(
    1,
    (int) ceil($totalFilteredProducts / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$listSql =
    "SELECT
        p.product_id,
        p.product_name,
        p.price,
        p.unit,
        p.description,
        p.stock_quantity,
        p.created_at,
        p.updated_at,
        v.vendor_name,
        c.category_id,
        c.category_name,
        m.market_id,
        m.market_name,
        ci.city_id,
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
            FROM product_photo pp2
            WHERE pp2.product_id = p.product_id
        ) AS photo_count,
        (
            SELECT COUNT(*)
            FROM reviews r
            WHERE r.product_id = p.product_id
        ) AS review_count,
        COALESCE(
            (
                SELECT AVG(r2.rating)
                FROM reviews r2
                WHERE r2.product_id = p.product_id
            ),
            0
        ) AS average_rating
     FROM products p
     INNER JOIN vendors v
        ON v.vendor_id = p.vendor_id
     INNER JOIN users vu
        ON vu.user_id = v.user_id
     INNER JOIN categories c
        ON c.category_id = p.category_id
     INNER JOIN markets m
        ON m.market_id = c.market_id
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
$products = $listStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Selected Product Detail
|--------------------------------------------------------------------------
*/

$selectedProduct = null;
$selectedPhotos = array();
$selectedReviews = array();
$canReviewSelectedProduct = false;
$currentUserReview = null;

$viewProductId = isset($_GET['view'])
    ? max(0, (int) $_GET['view'])
    : 0;

/*
|--------------------------------------------------------------------------
| Review Pagination Inside Product Modal
|--------------------------------------------------------------------------
| Only a few reviews are loaded at a time. This keeps the modal compact
| and avoids loading hundreds of review rows for popular products.
|--------------------------------------------------------------------------
*/

$reviewPage = isset($_GET['review_page'])
    ? max(1, (int) $_GET['review_page'])
    : 1;

$reviewsPerPage = 4;
$totalReviewPages = 1;

if ($viewProductId > 0) {
    $detailStatement = $pdo->prepare(
        "SELECT
            p.product_id,
            p.product_name,
            p.price,
            p.unit,
            p.description,
            p.stock_quantity,
            p.created_at,
            p.updated_at,
            v.vendor_name,
            v.address AS vendor_address,
            c.category_id,
            c.category_name,
            m.market_id,
            m.market_name,
            m.address AS market_address,
            m.opening_hour,
            m.closing_hour,
            ci.city_name,
            ci.administrative_division,
            (
                SELECT COUNT(*)
                FROM reviews r
                WHERE r.product_id = p.product_id
            ) AS review_count,
            COALESCE(
                (
                    SELECT AVG(r2.rating)
                    FROM reviews r2
                    WHERE r2.product_id = p.product_id
                ),
                0
            ) AS average_rating
         FROM products p
         INNER JOIN vendors v
            ON v.vendor_id = p.vendor_id
         INNER JOIN users vu
            ON vu.user_id = v.user_id
         INNER JOIN categories c
            ON c.category_id = p.category_id
         INNER JOIN markets m
            ON m.market_id = c.market_id
         INNER JOIN cities ci
            ON ci.city_id = m.city_id
         WHERE p.product_id = :product_id
           AND v.status = 'accepted'
           AND vu.status = 'active'
         LIMIT 1"
    );

    $detailStatement->execute(array(
        'product_id' => $viewProductId
    ));

    $selectedProduct = $detailStatement->fetch();

    if (!$selectedProduct) {
        product_page_redirect('products.php');
    }

    $photoStatement = $pdo->prepare(
        "SELECT
            product_photo_id,
            photo_path
         FROM product_photo
         WHERE product_id = :product_id
         ORDER BY product_photo_id ASC"
    );

    $photoStatement->execute(array(
        'product_id' => $viewProductId
    ));

    $selectedPhotos = $photoStatement->fetchAll();

    $totalReviewPages = max(
        1,
        (int) ceil(
            (int) $selectedProduct['review_count'] /
            $reviewsPerPage
        )
    );

    if ($reviewPage > $totalReviewPages) {
        $reviewPage = $totalReviewPages;
    }

    $reviewOffset = ($reviewPage - 1) * $reviewsPerPage;

    $reviewStatement = $pdo->prepare(
        "SELECT
            r.rating,
            r.comment,
            r.created_at,
            u.user_name
         FROM reviews r
         INNER JOIN users u
            ON u.user_id = r.user_id
         WHERE r.product_id = :product_id
         ORDER BY r.created_at DESC
         LIMIT :review_limit OFFSET :review_offset"
    );

    $reviewStatement->bindValue(
        ':product_id',
        $viewProductId,
        PDO::PARAM_INT
    );

    $reviewStatement->bindValue(
        ':review_limit',
        $reviewsPerPage,
        PDO::PARAM_INT
    );

    $reviewStatement->bindValue(
        ':review_offset',
        $reviewOffset,
        PDO::PARAM_INT
    );

    $reviewStatement->execute();

    $selectedReviews = $reviewStatement->fetchAll();

    if ($role === 'user' && isset($_SESSION['user_id'])) {
        $reviewEligibilityStatement = $pdo->prepare(
            "SELECT 1
             FROM purchase_details pd
             INNER JOIN purchase_process pp
                ON pp.purchase_id = pd.purchase_id
             INNER JOIN vendor_orders vo
                ON vo.vendor_order_id = pd.vendor_order_id
             WHERE pp.user_id = :user_id
               AND pd.product_id = :product_id
               AND pp.payment_status = 'paid'
               AND vo.order_status = 'completed'
             LIMIT 1"
        );
        $reviewEligibilityStatement->execute(array(
            'user_id' => (int) $_SESSION['user_id'],
            'product_id' => $viewProductId
        ));
        $canReviewSelectedProduct = (bool) $reviewEligibilityStatement->fetchColumn();

        $myReviewStatement = $pdo->prepare(
            "SELECT rating, comment
             FROM reviews
             WHERE user_id = :user_id
               AND product_id = :product_id
             LIMIT 1"
        );
        $myReviewStatement->execute(array(
            'user_id' => (int) $_SESSION['user_id'],
            'product_id' => $viewProductId
        ));
        $currentUserReview = $myReviewStatement->fetch(PDO::FETCH_ASSOC);
    }
}

/*
|--------------------------------------------------------------------------
| Page Values
|--------------------------------------------------------------------------
*/

$showingFrom = $totalFilteredProducts > 0
    ? $offset + 1
    : 0;

$showingTo = min(
    $offset + $perPage,
    $totalFilteredProducts
);

$cartCount = 0;

if (
    isset($_SESSION['cart']) &&
    is_array($_SESSION['cart'])
) {
    foreach ($_SESSION['cart'] as $cartQuantity) {
        $cartCount += max(0, (int) $cartQuantity);
    }
}

$flash = isset($_SESSION['product_page_flash'])
    ? $_SESSION['product_page_flash']
    : null;

unset($_SESSION['product_page_flash']);

$csrfToken = product_page_csrf_token();

$pageTitle = 'Products | Local Farmers Marketplace';

require __DIR__ . '/header.php';
?>

<main class="min-h-screen bg-[#f7f8f3]">

<style>
    /* Products page filter layout */
    .products-filter-row {
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;
        align-items: center;
    }

    /* Keep all controls side-by-side on normal desktop widths */
    @media (min-width: 1024px) {
        .products-filter-row {
            grid-template-columns: minmax(0, 1fr) 250px 140px 150px !important;
        }

        .products-filter-row > * {
            min-width: 0;
            width: 100%;
        }
    }
</style>

    <!-- Hero -->
    <section class="relative overflow-hidden border-b border-green-100 bg-gradient-to-br from-green-50 via-white to-amber-50">

        <div class="pointer-events-none absolute -left-32 top-8 h-80 w-80 rounded-full bg-green-200/30 blur-3xl"></div>
        <div class="pointer-events-none absolute -right-32 bottom-0 h-80 w-80 rounded-full bg-amber-200/30 blur-3xl"></div>

        <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-16">

            <div class="mx-auto max-w-5xl text-center">

                <span class="inline-flex items-center gap-2 rounded-full border border-green-200 bg-white px-3 py-2 text-xs font-bold text-green-700 shadow-sm">
                    <i class="fa-solid fa-basket-shopping"></i>
                    Fresh Local Products
                </span>

                <h1 class="mt-5 text-4xl font-black tracking-tight text-[#0f2414] sm:text-5xl">
                    Browse marketplace
                    <span class="text-green-600">products.</span>
                </h1>

                <p class="mx-auto mt-4 max-w-2xl text-sm leading-7 text-slate-500 sm:text-base">
                    Explore fresh products from accepted Vendors and discover the local markets where each item is available.
                </p>

                <!-- Product Search and Filters -->
                <form method="get"
                      action="products.php"
                      class="products-filter-row mx-auto mt-8 w-full max-w-5xl rounded-2xl border border-slate-200 bg-white p-3 text-left shadow-card">

                    <!-- Search -->
                    <div class="relative min-w-0">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                        <input type="search"
                               name="q"
                               value="<?php echo product_page_e($search); ?>"
                               placeholder="Search product, Vendor or market..."
                               class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                    </div>

                    <!-- Market Filter -->
                    <select name="market_id"
                            class="h-12 min-w-0 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-600 outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                        <option value="0">All markets</option>

                        <?php foreach ($markets as $market): ?>
                            <option value="<?php echo (int) $market['market_id']; ?>"
                                <?php echo $marketId === (int) $market['market_id'] ? 'selected' : ''; ?>>

                                <?php echo product_page_e($market['market_name']); ?>
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
                    <?php if (
                        $search !== '' ||
                        $marketId > 0 ||
                        $categoryId > 0 ||
                        $cityId > 0 ||
                        $stockFilter !== 'all' ||
                        $sort !== 'newest'
                    ): ?>

                        <a href="products.php"
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
        </div>
    </section>
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

        <?php if ($flash): ?>

            <div class="mb-5 flex items-start gap-3 rounded-2xl border px-4 py-3.5 shadow-sm
                <?php echo $flash['type'] === 'success'
                    ? 'border-green-200 bg-green-50 text-green-800'
                    : 'border-red-200 bg-red-50 text-red-800'; ?>">

                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full
                    <?php echo $flash['type'] === 'success'
                        ? 'bg-green-100'
                        : 'bg-red-100'; ?>">

                    <i class="fa-solid <?php echo $flash['type'] === 'success'
                        ? 'fa-check'
                        : 'fa-triangle-exclamation'; ?> text-xs"></i>
                </span>

                <div>
                    <p class="text-sm font-bold">

                        <?php echo $flash['type'] === 'success'
                            ? 'Success'
                            : 'Unable to continue'; ?>
                    </p>

                    <p class="mt-1 text-xs leading-5">

                        <?php echo product_page_e(
                            $flash['message']
                        ); ?>
                    </p>
                </div>
            </div>

        <?php endif; ?>

        <!-- Product List -->
        <section class="mt-7">

            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">

                <div>
                    <h2 class="text-xl font-black text-slate-950">
                        Product List
                    </h2>

                    <p class="mt-1 text-xs text-slate-400">

                        Showing
                        <?php echo number_format($showingFrom); ?>
                        –
                        <?php echo number_format($showingTo); ?>
                        of
                        <?php echo number_format($totalFilteredProducts); ?>
                        products
                    </p>
                </div>
            </div>

            <?php if (empty($products)): ?>

                <div class="rounded-2xl border border-slate-200 bg-white px-5 py-20 text-center shadow-soft">

                    <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-green-50 text-green-600">

                        <i class="fa-solid fa-basket-shopping text-2xl"></i>
                    </span>

                    <p class="mt-5 text-sm font-bold text-slate-700">
                        No products found
                    </p>

                    <p class="mt-2 text-xs text-slate-400">
                        Change the search or filter options and try again.
                    </p>
                </div>

            <?php else: ?>

                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">

                    <?php foreach ($products as $product): ?>
                        <?php
                        $photoUrl = product_page_photo_url(
                            $product['primary_photo']
                        );

                        $stockQuantity =
                            (int) $product['stock_quantity'];

                        if ($stockQuantity <= 0) {
                            $stockLabel = 'Out of stock';
                            $stockClass =
                                'bg-red-50 text-red-700 ring-red-200';
                        } elseif ($stockQuantity <= 10) {
                            $stockLabel = 'Low stock';
                            $stockClass =
                                'bg-amber-50 text-amber-700 ring-amber-200';
                        } else {
                            $stockLabel = 'Available';
                            $stockClass =
                                'bg-green-50 text-green-700 ring-green-200';
                        }
                        ?>

                        <article class="group flex h-full flex-col overflow-hidden rounded-[1.35rem] border border-slate-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:border-green-200 hover:shadow-xl">

                            <!-- Product Image -->
                            <div class="relative h-52 overflow-hidden bg-slate-100">

                                <a href="<?php echo product_page_e(
                                    product_page_query_url(array(
                                        'view' => (int) $product['product_id']
                                    ))
                                ); ?>"
                                   class="block h-full">

                                    <?php if ($photoUrl !== ''): ?>

                                        <img src="<?php echo product_page_e($photoUrl); ?>"
                                             alt="<?php echo product_page_e($product['product_name']); ?>"
                                             class="h-full w-full object-cover transition duration-500 group-hover:scale-105">

                                    <?php else: ?>

                                        <span class="grid h-full place-items-center bg-gradient-to-br from-green-50 to-slate-100 text-green-300">

                                            <i class="fa-solid fa-image text-5xl"></i>
                                        </span>

                                    <?php endif; ?>
                                </a>

                                <!-- Fresh / Stock Badge -->
                                <span class="absolute left-3 top-3 rounded-full px-3 py-1.5 text-[10px] font-black shadow-sm
                                    <?php echo $stockQuantity > 0
                                        ? 'bg-green-600 text-white'
                                        : 'bg-red-600 text-white'; ?>">

                                    <?php echo $stockQuantity > 0
                                        ? 'Fresh'
                                        : 'Out of Stock'; ?>
                                </span>

                            </div>

                            <!-- Product Details -->
                            <div class="flex flex-1 flex-col p-5">

                                <div class="flex items-center justify-between gap-3">

                                    <p class="shrink-0 text-xs font-bold text-amber-500">

                                        <i class="fa-solid fa-star mr-1"></i>

                                        <?php echo number_format(
                                            (float) $product['average_rating'],
                                            1
                                        ); ?>
                                    </p>

                                    <p class="truncate text-[10px] font-medium text-slate-400">

                                        <?php echo product_page_e(
                                            $product['market_name']
                                        ); ?>
                                    </p>
                                </div>

                                <a href="<?php echo product_page_e(
                                    product_page_query_url(array(
                                        'view' => (int) $product['product_id']
                                    ))
                                ); ?>"
                                   class="mt-4 line-clamp-1 text-base font-black text-slate-950 transition hover:text-green-700">

                                    <?php echo product_page_e(
                                        $product['product_name']
                                    ); ?>
                                </a>

                                <p class="mt-2 truncate text-xs text-slate-400">

                                    by
                                    <span class="font-medium">

                                        <?php echo product_page_e(
                                            $product['vendor_name']
                                        ); ?>
                                    </span>
                                </p>

                                <div class="mt-4 border-t border-slate-100 pt-4">

                                    <div class="flex items-end justify-between gap-3">

                                        <div>
                                            <p class="text-xl font-black text-green-700">

                                                <?php echo number_format(
                                                    (float) $product['price']
                                                ); ?>

                                                <span class="text-xs font-bold">
                                                    MMK / <?php echo product_page_e(fm_unit_label($product['unit'])); ?>
                                                </span>
                                            </p>

                                            <p class="mt-1 text-[10px] text-slate-400">

                                                Stock:
                                                <?php echo number_format(
                                                    $stockQuantity
                                                ); ?>
                                            </p>
                                        </div>

                                        <?php if (
                                            $role === 'user' &&
                                            $stockQuantity > 0
                                        ): ?>

                                            <a href="<?php echo product_page_e(
                                                product_page_query_url(array(
                                                    'view' => (int) $product['product_id']
                                                ))
                                            ); ?>"
                                               title="View product details and add to cart"
                                               class="grid h-11 w-11 place-items-center rounded-xl bg-green-600 text-white shadow-sm transition hover:bg-green-700">

                                                <i class="fa-solid fa-cart-plus text-sm"></i>
                                            </a>

                                        <?php elseif ($stockQuantity <= 0): ?>

                                            <button type="button"
                                                    disabled
                                                    title="Out of stock"
                                                    class="grid h-11 w-11 cursor-not-allowed place-items-center rounded-xl bg-slate-200 text-slate-400">

                                                <i class="fa-solid fa-cart-shopping text-sm"></i>
                                            </button>

                                        <?php elseif (!$isLoggedIn): ?>

                                            <a href="signin.php?notice=login_required&amp;next=<?php echo rawurlencode(
                                                'products.php?view=' .
                                                (int) $product['product_id']
                                            ); ?>"
                                               title="Sign In to add this product to your cart"
                                               class="grid h-11 w-11 place-items-center rounded-xl bg-green-600 text-white shadow-sm transition hover:bg-green-700">

                                                <i class="fa-solid fa-cart-plus text-sm"></i>
                                            </a>

                                        <?php else: ?>

                                            <a href="<?php echo product_page_e(
                                                product_page_query_url(array(
                                                    'view' => (int) $product['product_id']
                                                ))
                                            ); ?>"
                                               title="View product details"
                                               class="grid h-11 w-11 place-items-center rounded-xl bg-green-600 text-white shadow-sm transition hover:bg-green-700">

                                                <i class="fa-solid fa-eye text-sm"></i>
                                            </a>

                                        <?php endif; ?>
                                    </div>
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

                        <a href="<?php echo product_page_e(
                            product_page_query_url(array(
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

                            <a href="<?php echo product_page_e(
                                product_page_query_url(array(
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

                        <a href="<?php echo product_page_e(
                            product_page_query_url(array(
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

<!-- Product Detail Modal -->
<?php if ($selectedProduct): ?>

    <div class="fixed inset-0 z-[80] overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm">

        <div class="mx-auto flex min-h-full max-w-4xl items-center justify-center">

            <div class="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">

                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">

                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-green-600">
                            Product Details
                        </p>

                        <h2 class="mt-1 text-xl font-black text-slate-950">

                            <?php echo product_page_e(
                                $selectedProduct['product_name']
                            ); ?>
                        </h2>
                    </div>

                    <a href="<?php echo product_page_e(
                        product_page_query_url(array(
                            'view' => null
                        ))
                    ); ?>"
                       title="Close"
                       class="grid h-10 w-10 place-items-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700">

                        <i class="fa-solid fa-xmark"></i>
                    </a>
                </div>

                <div class="max-h-[74vh] overflow-y-auto p-4 sm:p-5">

                    <div class="grid gap-5 lg:grid-cols-[0.9fr_1.1fr]">

                        <!-- Images -->
                        <div>

                            <?php if (!empty($selectedPhotos)): ?>

                                <?php
                                $mainPhotoUrl =
                                    product_page_photo_url(
                                        $selectedPhotos[0]['photo_path']
                                    );
                                ?>

                                <img id="selectedProductMainImage"
                                     src="<?php echo product_page_e($mainPhotoUrl); ?>"
                                     alt="<?php echo product_page_e($selectedProduct['product_name']); ?>"
                                     class="h-64 w-full rounded-2xl border border-slate-200 object-cover sm:h-72">

                                <?php if (count($selectedPhotos) > 1): ?>

                                    <div class="mt-3 grid grid-cols-4 gap-2">

                                        <?php foreach ($selectedPhotos as $photo): ?>
                                            <?php
                                            $thumbnailUrl =
                                                product_page_photo_url(
                                                    $photo['photo_path']
                                                );
                                            ?>

                                            <button type="button"
                                                    data-product-image="<?php echo product_page_e($thumbnailUrl); ?>"
                                                    class="overflow-hidden rounded-xl border border-slate-200 hover:border-green-500">

                                                <img src="<?php echo product_page_e($thumbnailUrl); ?>"
                                                     alt="Product image"
                                                     class="h-20 w-full object-cover">
                                            </button>

                                        <?php endforeach; ?>
                                    </div>

                                <?php endif; ?>

                            <?php else: ?>

                                <div class="grid h-64 place-items-center rounded-2xl bg-green-50 text-green-300 sm:h-72">

                                    <i class="fa-solid fa-image text-6xl"></i>
                                </div>

                            <?php endif; ?>
                        </div>

                        <!-- Information -->
                        <div>

                            <div class="flex flex-wrap items-center gap-2">

                                <span class="rounded-full bg-violet-50 px-3 py-1.5 text-xs font-bold text-violet-700">

                                    <?php echo product_page_e(
                                        $selectedProduct['category_name']
                                    ); ?>
                                </span>

                                <span class="rounded-full bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700">

                                    <i class="fa-solid fa-star mr-1"></i>

                                    <?php echo number_format(
                                        (float) $selectedProduct['average_rating'],
                                        1
                                    ); ?>

                                    (
                                    <?php echo number_format(
                                        (int) $selectedProduct['review_count']
                                    ); ?>
                                    )
                                </span>
                            </div>

                            <p class="mt-5 text-3xl font-black text-green-700">

                                <?php echo number_format(
                                    (float) $selectedProduct['price']
                                ); ?>

                                <span class="text-sm font-semibold text-slate-400">
                                    MMK / <?php echo product_page_e(fm_unit_label(isset($selectedProduct['unit']) && trim((string) $selectedProduct['unit']) !== '' ? $selectedProduct['unit'] : 'item')); ?>
                                </span>
                            </p>

                            <p class="mt-4 text-sm leading-7 text-slate-600">

                                <?php echo nl2br(
                                    product_page_e(
                                        $selectedProduct['description']
                                    )
                                ); ?>
                            </p>

                            <div class="mt-6 grid gap-3 sm:grid-cols-2">

                                <div class="rounded-xl bg-slate-50 p-4">

                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                        Vendor
                                    </p>

                                    <p class="mt-1 text-sm font-bold text-slate-800">

                                        <?php echo product_page_e(
                                            $selectedProduct['vendor_name']
                                        ); ?>
                                    </p>
                                </div>

                                <div class="rounded-xl bg-slate-50 p-4">

                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                        Stock Quantity
                                    </p>

                                    <p class="mt-1 text-sm font-bold text-slate-800">

                                        <?php echo number_format(
                                            (int) $selectedProduct['stock_quantity']
                                        ); ?>

                                        <?php echo product_page_e(fm_unit_label(isset($selectedProduct['unit']) && trim((string) $selectedProduct['unit']) !== '' ? $selectedProduct['unit'] : 'item')); ?>(s)
                                    </p>
                                </div>

                                <div class="rounded-xl bg-slate-50 p-4 sm:col-span-2">

                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                        Market
                                    </p>

                                    <p class="mt-1 text-sm font-bold text-slate-800">

                                        <?php echo product_page_e(
                                            $selectedProduct['market_name']
                                        ); ?>
                                    </p>

                                    <p class="mt-1 text-xs text-slate-400">

                                        <i class="fa-solid fa-location-dot mr-1"></i>

                                        <?php echo product_page_e(
                                            $selectedProduct['market_address'] .
                                            ', ' .
                                            $selectedProduct['city_name']
                                        ); ?>
                                    </p>
                                </div>
                            </div>

                            <?php if (
                                $role === 'user' &&
                                (int) $selectedProduct['stock_quantity'] > 0
                            ): ?>

                                <form method="post"
                                      action="products.php"
                                      class="mt-6 flex flex-col gap-3 rounded-2xl border border-green-200 bg-green-50 p-4 sm:flex-row sm:items-end">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?php echo product_page_e($csrfToken); ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="add_to_cart">

                                    <input type="hidden"
                                           name="product_id"
                                           value="<?php echo (int) $selectedProduct['product_id']; ?>">

                                    <div class="flex-1">

                                        <label for="productQuantity"
                                               class="mb-2 block text-xs font-bold text-green-900">
                                            Quantity
                                        </label>

                                        <input id="productQuantity"
                                               type="number"
                                               name="quantity"
                                               min="1"
                                               max="<?php echo (int) $selectedProduct['stock_quantity']; ?>"
                                               value="1"
                                               required
                                               class="h-11 w-full rounded-xl border border-green-200 bg-white px-4 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                                    </div>

                                    <button type="submit"
                                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-6 text-sm font-bold text-white hover:bg-green-700">

                                        <i class="fa-solid fa-cart-plus"></i>

                                        Add to Cart
                                    </button>
                                </form>

                            <?php elseif (
                                (int) $selectedProduct['stock_quantity'] <= 0
                            ): ?>

                                <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700">

                                    <i class="fa-solid fa-circle-xmark mr-2"></i>

                                    This product is currently out of stock.
                                </div>

                            <?php elseif (!$isLoggedIn): ?>

                                <div class="mt-6 rounded-2xl border border-blue-200 bg-blue-50 p-4">

                                    <p class="text-sm font-bold text-blue-900">

                                        <i class="fa-solid fa-lock mr-2"></i>

                                        Sign In required
                                    </p>

                                    <p class="mt-2 text-xs leading-6 text-blue-700">

                                        You may view product information without an account. Sign In before adding this product to your cart or completing a purchase.
                                    </p>

                                    <a href="signin.php?notice=login_required&amp;next=<?php echo rawurlencode(
                                        'products.php?view=' .
                                        (int) $selectedProduct['product_id']
                                    ); ?>"
                                       class="mt-4 inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-6 text-sm font-bold text-white hover:bg-green-700">

                                        <i class="fa-solid fa-right-to-bracket"></i>

                                        Sign In to Purchase
                                    </a>
                                </div>

                            <?php endif; ?>
                        </div>
                    </div>

                    <section id="reviews" class="mt-7 border-t border-slate-100 pt-6">
                        <?php if ($canReviewSelectedProduct): ?>
                            <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 p-5">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-green-600 text-white">
                                        <i class="fa-solid fa-star"></i>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-sm font-black text-green-950">
                                            <?php echo $currentUserReview ? 'Update Your Review' : 'Write a Review'; ?>
                                        </h3>
                                        <p class="mt-1 text-xs leading-5 text-green-700">Only customers with a paid, completed order for this product can submit a review.</p>

                                        <form method="post" action="review_submit.php" class="mt-4 grid gap-3 sm:grid-cols-3 sm:items-end">
                                            <input type="hidden" name="csrf_token" value="<?php echo product_page_e(fm_csrf_token()); ?>">
                                            <input type="hidden" name="product_id" value="<?php echo (int) $selectedProduct['product_id']; ?>">

                                            <label class="block">
                                                <span class="mb-2 block text-xs font-bold text-green-900">Rating</span>
                                                <select name="rating" required class="h-11 w-full rounded-xl border border-green-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                                                    <?php for ($ratingOption = 5; $ratingOption >= 1; $ratingOption--): ?>
                                                        <option value="<?php echo $ratingOption; ?>" <?php echo $currentUserReview && (int) $currentUserReview['rating'] === $ratingOption ? 'selected' : ''; ?>>
                                                            <?php echo $ratingOption; ?> star<?php echo $ratingOption === 1 ? '' : 's'; ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </label>

                                            <label class="block">
                                                <span class="mb-2 block text-xs font-bold text-green-900">Comment</span>
                                                <input type="text" name="comment" required maxlength="255" value="<?php echo product_page_e($currentUserReview ? $currentUserReview['comment'] : ''); ?>" placeholder="Share your experience with this product" class="h-11 w-full rounded-xl border border-green-200 bg-white px-4 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                                            </label>

                                            <button type="submit" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-bold text-white hover:bg-green-700">
                                                <i class="fa-solid fa-paper-plane text-xs"></i>
                                                <?php echo $currentUserReview ? 'Update' : 'Submit'; ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($role === 'user'): ?>
                            <div class="mb-6 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs leading-5 text-slate-500">
                                <i class="fa-solid fa-circle-info mr-1 text-green-600"></i>
                                Review submission becomes available after your order for this product is paid and marked Completed.
                            </div>
                        <?php endif; ?>

                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">

                            <div>
                                <h3 class="text-base font-black text-slate-900">
                                    Recent Reviews
                                </h3>

                                <?php if ((int) $selectedProduct['review_count'] > 0): ?>
                                    <p class="mt-1 text-[10px] text-slate-400">
                                        Showing page
                                        <?php echo number_format($reviewPage); ?>
                                        of
                                        <?php echo number_format($totalReviewPages); ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <span class="w-fit rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700">
                                <?php echo number_format(
                                    (int) $selectedProduct['review_count']
                                ); ?>
                                total
                            </span>
                        </div>

                        <?php if (empty($selectedReviews)): ?>

                            <p class="mt-4 rounded-xl bg-slate-50 p-4 text-xs text-slate-400">
                                No reviews have been submitted for this product.
                            </p>

                        <?php else: ?>

                            <div class="mt-4 grid gap-3 md:grid-cols-2">

                                <?php foreach ($selectedReviews as $review): ?>

                                    <article class="rounded-xl border border-slate-200 bg-white p-4">

                                        <div class="flex items-start justify-between gap-3">

                                            <div>
                                                <p class="text-sm font-bold text-slate-800">
                                                    <?php echo product_page_e(
                                                        $review['user_name']
                                                    ); ?>
                                                </p>

                                                <p class="mt-1 text-[10px] text-slate-400">
                                                    <?php echo product_page_e(
                                                        date(
                                                            'M d, Y',
                                                            strtotime(
                                                                $review['created_at']
                                                            )
                                                        )
                                                    ); ?>
                                                </p>
                                            </div>

                                            <span class="shrink-0 text-xs font-bold text-amber-500">
                                                <i class="fa-solid fa-star mr-1"></i>
                                                <?php echo (int) $review['rating']; ?>
                                            </span>
                                        </div>

                                        <p class="mt-3 break-words text-xs leading-6 text-slate-500">
                                            <?php echo product_page_e(
                                                $review['comment']
                                            ); ?>
                                        </p>
                                    </article>

                                <?php endforeach; ?>
                            </div>

                            <?php if ($totalReviewPages > 1): ?>

                                <div class="mt-4 flex flex-wrap items-center justify-center gap-2 border-t border-slate-100 pt-4">

                                    <a href="<?php echo product_page_e(
                                        product_page_query_url(array(
                                            'view' => (int) $selectedProduct['product_id'],
                                            'review_page' => max(1, $reviewPage - 1)
                                        ))
                                    ); ?>#reviews"
                                       class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-bold text-slate-500 transition hover:bg-slate-50
                                       <?php echo $reviewPage <= 1
                                           ? 'pointer-events-none opacity-40'
                                           : ''; ?>">

                                        <i class="fa-solid fa-chevron-left text-[9px]"></i>
                                        Previous
                                    </a>

                                    <?php
                                    $reviewStartPage = max(
                                        1,
                                        $reviewPage - 2
                                    );

                                    $reviewEndPage = min(
                                        $totalReviewPages,
                                        $reviewPage + 2
                                    );
                                    ?>

                                    <?php for (
                                        $reviewPageNumber = $reviewStartPage;
                                        $reviewPageNumber <= $reviewEndPage;
                                        $reviewPageNumber++
                                    ): ?>

                                        <a href="<?php echo product_page_e(
                                            product_page_query_url(array(
                                                'view' => (int) $selectedProduct['product_id'],
                                                'review_page' => $reviewPageNumber
                                            ))
                                        ); ?>#reviews"
                                           class="grid h-9 w-9 place-items-center rounded-lg text-xs font-bold transition
                                           <?php echo $reviewPageNumber === $reviewPage
                                               ? 'bg-green-600 text-white'
                                               : 'border border-slate-200 text-slate-500 hover:bg-slate-50'; ?>">

                                            <?php echo $reviewPageNumber; ?>
                                        </a>

                                    <?php endfor; ?>

                                    <a href="<?php echo product_page_e(
                                        product_page_query_url(array(
                                            'view' => (int) $selectedProduct['product_id'],
                                            'review_page' => min(
                                                $totalReviewPages,
                                                $reviewPage + 1
                                            )
                                        ))
                                    ); ?>#reviews"
                                       class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-bold text-slate-500 transition hover:bg-slate-50
                                       <?php echo $reviewPage >= $totalReviewPages
                                           ? 'pointer-events-none opacity-40'
                                           : ''; ?>">

                                        Next
                                        <i class="fa-solid fa-chevron-right text-[9px]"></i>
                                    </a>
                                </div>

                            <?php endif; ?>

                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var imageButtons = document.querySelectorAll(
            '[data-product-image]'
        );

        var mainImage = document.getElementById(
            'selectedProductMainImage'
        );

        if (mainImage) {
            for (
                var index = 0;
                index < imageButtons.length;
                index++
            ) {
                imageButtons[index].addEventListener(
                    'click',
                    function () {
                        mainImage.src = this.getAttribute(
                            'data-product-image'
                        );
                    }
                );
            }
        }
    });
</script>

<?php require __DIR__ . '/footer.php'; ?>