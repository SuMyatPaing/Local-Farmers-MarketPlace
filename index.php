<?php
require_once __DIR__ . '/security.php';

require_once './auth.php';

if (!function_exists('home_e')) {
    function home_e($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('home_photo_url')) {
    function home_photo_url($path)
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

if (!function_exists('home_time_range')) {
    function home_time_range($openingHour, $closingHour)
    {
        $openingTimestamp = strtotime((string) $openingHour);
        $closingTimestamp = strtotime((string) $closingHour);

        if (
            $openingTimestamp === false ||
            $closingTimestamp === false
        ) {
            return 'Hours not available';
        }

        return date('g:i A', $openingTimestamp) .
            ' – ' .
            date('g:i A', $closingTimestamp);
    }
}

if (!function_exists('home_category_style')) {
    function home_category_style($categoryName)
    {
        $name = strtolower(trim((string) $categoryName));

        // Fruits
        if (
            strpos($name, 'fruit') !== false ||
            strpos($name, 'apple') !== false
        ) {
            return array(
                'icon' => 'fa-apple-whole',
                'class' => 'bg-red-100 text-red-600'
            );
        }

        // Vegetables
        if (
            strpos($name, 'vegetable') !== false ||
            strpos($name, 'leaf') !== false
        ) {
            return array(
                'icon' => 'fa-leaf',
                'class' => 'bg-green-100 text-green-700'
            );
        }

        // Herbs & Spices
        if (
            strpos($name, 'herb') !== false ||
            strpos($name, 'spice') !== false
        ) {
            return array(
                'icon' => 'fa-pepper-hot',
                'class' => 'bg-orange-100 text-orange-700'
            );
        }

        // Eggs
        if (strpos($name, 'egg') !== false) {
            return array(
                'icon' => 'fa-egg',
                'class' => 'bg-yellow-100 text-yellow-700'
            );
        }

        // Rice
        if (strpos($name, 'rice') !== false) {
            return array(
                'icon' => 'fa-bowl-rice',
                'class' => 'bg-amber-100 text-amber-700'
            );
        }

        // Grains & Cereals
        if (
            strpos($name, 'grain') !== false ||
            strpos($name, 'cereal') !== false ||
            strpos($name, 'wheat') !== false
        ) {
            return array(
                'icon' => 'fa-wheat-awn',
                'class' => 'bg-yellow-100 text-yellow-800'
            );
        }

        // Beans & Pulses
        if (
            strpos($name, 'bean') !== false ||
            strpos($name, 'pulse') !== false ||
            strpos($name, 'seed') !== false
        ) {
            return array(
                'icon' => 'fa-seedling',
                'class' => 'bg-lime-100 text-lime-700'
            );
        }

        // Fish & Seafood
        if (
            strpos($name, 'fish') !== false ||
            strpos($name, 'seafood') !== false
        ) {
            return array(
                'icon' => 'fa-fish',
                'class' => 'bg-blue-100 text-blue-600'
            );
        }

        // Meat
        if (
            strpos($name, 'meat') !== false ||
            strpos($name, 'chicken') !== false
        ) {
            return array(
                'icon' => 'fa-drumstick-bite',
                'class' => 'bg-rose-100 text-rose-600'
            );
        }

        // Dairy Products
        if (
            strpos($name, 'dairy') !== false ||
            strpos($name, 'milk') !== false ||
            strpos($name, 'cheese') !== false ||
            strpos($name, 'yogurt') !== false
        ) {
            return array(
                'icon' => 'fa-bottle-droplet',
                'class' => 'bg-sky-100 text-sky-700'
            );
        }

        // Tea & Coffee
        if (
            strpos($name, 'tea') !== false ||
            strpos($name, 'coffee') !== false
        ) {
            return array(
                'icon' => 'fa-mug-hot',
                'class' => 'bg-orange-100 text-orange-700'
            );
        }

        // Flowers & Plants
        if (
            strpos($name, 'flower') !== false ||
            strpos($name, 'plant') !== false
        ) {
            return array(
                'icon' => 'fa-spa',
                'class' => 'bg-pink-100 text-pink-600'
            );
        }

        // Local / Processed Foods
        if (
            strpos($name, 'processed') !== false ||
            strpos($name, 'pickle') !== false ||
            strpos($name, 'dried') !== false
        ) {
            return array(
                'icon' => 'fa-jar',
                'class' => 'bg-violet-100 text-violet-700'
            );
        }

        // Default
        return array(
            'icon' => 'fa-basket-shopping',
            'class' => 'bg-slate-100 text-slate-600'
        );
    }
}

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
| Dynamic Homepage Statistics
|--------------------------------------------------------------------------
*/

$statsStatement = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM markets) AS total_markets,
        (
            SELECT COUNT(*)
            FROM vendors v
            INNER JOIN users vu
                ON vu.user_id = v.user_id
            WHERE v.status = 'accepted'
              AND vu.status = 'active'
        ) AS accepted_vendors,
        (
            SELECT COUNT(*)
            FROM products p
            INNER JOIN vendors v
                ON v.vendor_id = p.vendor_id
            INNER JOIN users vu
                ON vu.user_id = v.user_id
            WHERE v.status = 'accepted'
              AND vu.status = 'active'
        ) AS total_products,
        (
            SELECT COUNT(*)
            FROM users
            WHERE role = 'user'
              AND status = 'active'
        ) AS total_customers"
);

$homepageStats = $statsStatement->fetch();

$totalMarkets = isset($homepageStats['total_markets'])
    ? (int) $homepageStats['total_markets']
    : 0;

$acceptedVendors = isset($homepageStats['accepted_vendors'])
    ? (int) $homepageStats['accepted_vendors']
    : 0;

$totalProducts = isset($homepageStats['total_products'])
    ? (int) $homepageStats['total_products']
    : 0;

$totalCustomers = isset($homepageStats['total_customers'])
    ? (int) $homepageStats['total_customers']
    : 0;

/*
|--------------------------------------------------------------------------
| Dynamic Cities
|--------------------------------------------------------------------------
*/

$cityStatement = $pdo->query(
    "SELECT
        ci.city_id,
        ci.city_name,
        ci.administrative_division,
        COUNT(m.market_id) AS market_count
     FROM cities ci
     INNER JOIN markets m
        ON m.city_id = ci.city_id
     GROUP BY
        ci.city_id,
        ci.city_name,
        ci.administrative_division
     ORDER BY ci.city_name ASC"
);

$homepageCities = $cityStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Featured Markets
|--------------------------------------------------------------------------
*/

$marketStatement = $pdo->query(
    "SELECT
        m.market_id,
        m.market_name,
        m.opening_hour,
        m.closing_hour,
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
            SELECT COUNT(DISTINCT vm.vendor_id)
            FROM vendor_markets vm
            INNER JOIN vendors v
                ON v.vendor_id = vm.vendor_id
            INNER JOIN users vu
                ON vu.user_id = v.user_id
            WHERE vm.market_id = m.market_id
              AND v.status = 'accepted'
              AND vu.status = 'active'
        ) AS vendor_count,
        (
            SELECT COUNT(*)
            FROM categories c
            WHERE c.market_id = m.market_id
        ) AS category_count
     FROM markets m
     INNER JOIN cities ci
        ON ci.city_id = m.city_id
     ORDER BY
        vendor_count DESC,
        category_count DESC,
        m.created_at DESC,
        m.market_id DESC
     LIMIT 3"
);

$featuredMarkets = array();

foreach ($marketStatement->fetchAll() as $marketRow) {
    $featuredMarkets[] = array(
        'market_id' => (int) $marketRow['market_id'],
        'name' => (string) $marketRow['market_name'],
        'city' => (string) $marketRow['city_name'],
        'vendors' => (int) $marketRow['vendor_count'],
        'categories' => (int) $marketRow['category_count'],
        'hours' => home_time_range(
            $marketRow['opening_hour'],
            $marketRow['closing_hour']
        ),
        'image' => home_photo_url(
            $marketRow['primary_photo']
        )
    );
}

/*
|--------------------------------------------------------------------------
| Dynamic Categories
|--------------------------------------------------------------------------
*/

$categoryStatement = $pdo->query(
    "SELECT
        MIN(c.category_id) AS category_id,
        MIN(c.category_name) AS category_name,
        COUNT(DISTINCT CASE
            WHEN v.status = 'accepted'
             AND vu.status = 'active'
            THEN p.product_id
            ELSE NULL
        END) AS product_count,
        COUNT(DISTINCT c.market_id) AS market_count
     FROM categories c
     LEFT JOIN products p
        ON p.category_id = c.category_id
     LEFT JOIN vendors v
        ON v.vendor_id = p.vendor_id
     LEFT JOIN users vu
        ON vu.user_id = v.user_id
     GROUP BY
        LOWER(TRIM(c.category_name))
     ORDER BY
        product_count DESC,
        category_name ASC
     LIMIT 8"
);

$categories = array();

foreach ($categoryStatement->fetchAll() as $categoryRow) {
    $style = home_category_style(
        $categoryRow['category_name']
    );

    $categories[] = array(
        'category_id' => (int) $categoryRow['category_id'],
        'name' => (string) $categoryRow['category_name'],
        'count' => (int) $categoryRow['product_count'],
        'market_count' => isset($categoryRow['market_count'])
            ? (int) $categoryRow['market_count']
            : 0,
        'icon' => $style['icon'],
        'class' => $style['class']
    );
}

$popularCategories = array_slice(
    $categories,
    0,
    4
);

/*
|--------------------------------------------------------------------------
| Featured Products
|--------------------------------------------------------------------------
*/

$productStatement = $pdo->query(
    "SELECT
        p.product_id,
        p.product_name,
        p.price,
        p.stock_quantity,
        v.vendor_name,
        m.market_name,
        (
            SELECT pp.photo_path
            FROM product_photo pp
            WHERE pp.product_id = p.product_id
            ORDER BY pp.product_photo_id ASC
            LIMIT 1
        ) AS primary_photo,
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
        ) AS average_rating,
        COALESCE(
            (
                SELECT SUM(pd_sales.quantity)
                FROM purchase_details pd_sales
                INNER JOIN vendor_orders vo_sales
                    ON vo_sales.vendor_order_id = pd_sales.vendor_order_id
                WHERE pd_sales.product_id = p.product_id
                  AND vo_sales.order_status = 'completed'
            ),
            0
        ) AS units_sold
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
        units_sold DESC,
        average_rating DESC,
        review_count DESC,
        p.created_at DESC,
        p.product_id DESC
     LIMIT 4"
);

$featuredProducts = array();

foreach ($productStatement->fetchAll() as $productRow) {
    $featuredProducts[] = array(
        'product_id' => (int) $productRow['product_id'],
        'name' => (string) $productRow['product_name'],
        'vendor' => (string) $productRow['vendor_name'],
        'market' => (string) $productRow['market_name'],
        'price' => (float) $productRow['price'],
        'stock_quantity' => (int) $productRow['stock_quantity'],
        'rating' => (float) $productRow['average_rating'],
        'image' => home_photo_url(
            $productRow['primary_photo']
        )
    );
}

/*
|--------------------------------------------------------------------------
| Upcoming Events
|--------------------------------------------------------------------------
*/

$eventStatement = $pdo->query(
    "SELECT
        e.event_id,
        e.event_name,
        e.start_date,
        e.end_date,
        e.description,
        m.market_id,
        m.market_name,
        m.address AS market_address,
        m.opening_hour,
        m.closing_hour,
        m.description AS market_description,
        ci.city_name,
        ci.administrative_division,
        (
            SELECT mp.photo_path
            FROM market_photo mp
            WHERE mp.market_id = m.market_id
            ORDER BY mp.photo_id ASC
            LIMIT 1
        ) AS primary_photo
     FROM events e
     INNER JOIN users creator
        ON creator.user_id = e.user_id
     INNER JOIN markets m
        ON m.market_id = e.market_id
     INNER JOIN cities ci
        ON ci.city_id = m.city_id
     WHERE creator.role = 'admin'
       AND creator.status = 'active'
       AND e.end_date >= CURDATE()
     ORDER BY
        e.start_date ASC,
        e.event_id ASC
     LIMIT 3"
);

$events = array();

foreach ($eventStatement->fetchAll() as $eventRow) {
    $startTimestamp = strtotime(
        (string) $eventRow['start_date']
    );

    $endTimestamp = strtotime(
        (string) $eventRow['end_date']
    );

    $dateRange = '';

    if (
        $startTimestamp !== false &&
        $endTimestamp !== false
    ) {
        $dateRange =
            date('M d, Y', $startTimestamp) .
            ' – ' .
            date('M d, Y', $endTimestamp);
    }

    $todayTimestamp = strtotime(date('Y-m-d'));

    $eventStatus = 'Upcoming';

    if (
        $startTimestamp !== false &&
        $endTimestamp !== false
    ) {
        if (
            $todayTimestamp >= $startTimestamp &&
            $todayTimestamp <= $endTimestamp
        ) {
            $eventStatus = 'Ongoing';
        } elseif ($todayTimestamp > $endTimestamp) {
            $eventStatus = 'Past';
        }
    }

    $eventDuration = 1;

    if (
        $startTimestamp !== false &&
        $endTimestamp !== false
    ) {
        $eventDuration = max(
            1,
            (int) floor(
                ($endTimestamp - $startTimestamp) / 86400
            ) + 1
        );
    }

    $marketHours = '';

    $openingTimestamp = strtotime(
        (string) $eventRow['opening_hour']
    );

    $closingTimestamp = strtotime(
        (string) $eventRow['closing_hour']
    );

    if (
        $openingTimestamp !== false &&
        $closingTimestamp !== false
    ) {
        $marketHours =
            date('g:i A', $openingTimestamp) .
            ' – ' .
            date('g:i A', $closingTimestamp);
    }

    $marketLocation =
        (string) $eventRow['market_address'];

    if (
        trim((string) $eventRow['city_name']) !== ''
    ) {
        $marketLocation .=
            ($marketLocation !== '' ? ', ' : '') .
            (string) $eventRow['city_name'];
    }

    if (
        trim(
            (string) $eventRow['administrative_division']
        ) !== ''
    ) {
        $marketLocation .=
            ($marketLocation !== '' ? ', ' : '') .
            (string) $eventRow['administrative_division'];
    }

    $events[] = array(
        'event_id' => (int) $eventRow['event_id'],
        'day' => $startTimestamp !== false
            ? date('d', $startTimestamp)
            : '--',
        'month' => $startTimestamp !== false
            ? strtoupper(date('M', $startTimestamp))
            : '---',
        'name' => (string) $eventRow['event_name'],
        'market' => (string) $eventRow['market_name'],
        'time' => $dateRange,
        'description' => (string) $eventRow['description'],
        'status' => $eventStatus,
        'duration' => $eventDuration,
        'market_hours' => $marketHours,
        'market_location' => $marketLocation,
        'market_description' =>
            (string) $eventRow['market_description'],
        'image' => home_photo_url(
            $eventRow['primary_photo']
        )
    );
}

/*
|--------------------------------------------------------------------------
| Vendor Initials and Hero Image
|--------------------------------------------------------------------------
*/

$vendorInitialStatement = $pdo->query(
    "SELECT v.vendor_name
     FROM vendors v
     INNER JOIN users vu
        ON vu.user_id = v.user_id
     WHERE v.status = 'accepted'
       AND vu.status = 'active'
     ORDER BY v.reviewed_at DESC, v.vendor_id DESC
     LIMIT 3"
);

$vendorInitials = array();

foreach ($vendorInitialStatement->fetchAll() as $vendorInitialRow) {
    $vendorName = trim(
        (string) $vendorInitialRow['vendor_name']
    );

    $words = preg_split('/\s+/', $vendorName);
    $initial = '';

    foreach ($words as $word) {
        if ($word !== '') {
            $initial .= strtoupper(substr($word, 0, 1));
        }

        if (strlen($initial) >= 2) {
            break;
        }
    }

    $vendorInitials[] = $initial !== ''
        ? $initial
        : 'V';
}

$heroImage = !empty($featuredMarkets)
    ? $featuredMarkets[0]['image']
    : '';

$homeUser = fm_current_user();

$homeRole = $homeUser !== null
    ? strtolower((string) $homeUser['role'])
    : '';

$showVendorCta = $homeRole !== 'admin';
$isVendorHome = $homeRole === 'vendor';
?>
<?php
$pageTitle = 'Local Farmers Marketplace';
require_once './header.php';
?>
<main>


    <!-- Hero -->
    <section class="relative overflow-hidden bg-[#f7f8f3]">
        <div class="pointer-events-none absolute -left-40 top-16 h-96 w-96 rounded-full bg-green-200/30 blur-3xl"></div>
        <div class="pointer-events-none absolute -right-32 bottom-0 h-96 w-96 rounded-full bg-amber-200/30 blur-3xl"></div>

        <div class="home-hero-grid relative mx-auto grid max-w-7xl items-center gap-12 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[1.05fr_0.95fr] lg:px-8 lg:py-24">

            <div class="home-hero-copy">
                <div class="inline-flex items-center gap-2 rounded-full border border-green-200 bg-white px-3 py-2 text-xs font-bold text-green-700 shadow-sm">
                    <span class="grid h-6 w-6 place-items-center rounded-full bg-green-100">
                        <i class="fa-solid fa-leaf text-[10px]"></i>
                    </span>
                    Fresh, local and trusted
                </div>

                <h1 class="mt-6 max-w-3xl text-4xl font-black leading-tight tracking-tight text-[#0f2414] sm:text-5xl lg:text-6xl">
                    Fresh from local farms
                    <span class="text-green-600">to your table.</span>
                </h1>

                <p class="mt-5 max-w-2xl text-base leading-8 text-slate-600 sm:text-lg">
                    Discover nearby markets, shop fresh products directly from accepted vendors and support local farming communities.
                </p>

                <form action="<?php echo fm_e(fm_url('products.php')); ?>"
                      method="get"
                      class="mt-8 rounded-2xl border border-slate-200 bg-white p-3 shadow-card">

                    <div class="grid gap-2 md:grid-cols-[1fr_210px_auto]">

                        <label class="relative block">
                            <span class="sr-only">Search products or markets</span>

                            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                            <input type="search"
                                   name="q"
                                   placeholder="Search products or markets"
                                   class="w-full rounded-xl bg-slate-50 py-3.5 pl-11 pr-4 text-sm outline-none transition focus:bg-white focus:ring-2 focus:ring-green-100">
                        </label>

                        <label class="relative block">
                            <span class="sr-only">Choose city</span>

                            <i class="fa-solid fa-location-dot pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-green-600"></i>

                            <select name="city_id"
                                    class="w-full appearance-none rounded-xl bg-slate-50 py-3.5 pl-11 pr-9 text-sm font-semibold text-slate-600 outline-none focus:bg-white focus:ring-2 focus:ring-green-100">

                                <option value="0">
                                    All cities
                                </option>

                                <?php foreach ($homepageCities as $city): ?>

                                    <option value="<?php echo (int) $city['city_id']; ?>">

                                        <?php echo home_e(
                                            $city['city_name'] .
                                            ' (' .
                                            number_format(
                                                (int) $city['market_count']
                                            ) .
                                            ')'
                                        ); ?>
                                    </option>

                                <?php endforeach; ?>
                            </select>

                            <i class="fa-solid fa-chevron-down pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-[10px] text-slate-400"></i>
                        </label>

                        <button type="submit"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-green-600 px-6 py-3.5 text-sm font-black text-white shadow-lg shadow-green-900/15 transition hover:bg-green-700">
                            Search
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </button>
                    </div>
                </form>

              
            </div>

            <div class="home-hero-media relative">
                <div class="absolute -left-6 top-16 z-20 hidden rounded-2xl border border-white/70 bg-white/95 p-4 shadow-card backdrop-blur sm:block">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 place-items-center rounded-full bg-green-100 text-green-600">
                            <i class="fa-solid fa-truck-fast"></i>
                        </span>

                        <div>
                            <p class="text-xs font-black text-slate-800">Fresh local products</p>
                            <p class="mt-0.5 text-[10px] text-slate-400">Direct from trusted vendors</p>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-[2rem] border-8 border-white bg-white shadow-card">

                    <?php if ($heroImage !== ''): ?>

                        <img src="<?php echo home_e($heroImage); ?>"
                             alt="Featured local farmers market"
                             class="h-[460px] w-full object-cover sm:h-[540px]">

                    <?php else: ?>

                        <div class="grid h-[460px] place-items-center bg-gradient-to-br from-green-100 to-amber-50 text-green-400 sm:h-[540px]">

                            <i class="fa-solid fa-store text-7xl"></i>
                        </div>

                    <?php endif; ?>
                </div>

                <div class="absolute -bottom-5 right-5 z-20 rounded-2xl bg-[#0f2414] p-5 text-white shadow-2xl">
                    <div class="flex items-center gap-4">
                        <div class="flex -space-x-2">

                            <?php foreach ($vendorInitials as $initial): ?>

                                <span class="grid h-10 w-10 place-items-center rounded-full border-2 border-[#0f2414] bg-green-500 text-xs font-black">

                                    <?php echo home_e($initial); ?>
                                </span>

                            <?php endforeach; ?>
                        </div>

                        <div>
                            <p class="text-sm font-black">
                                <?php echo number_format($acceptedVendors); ?>
                                trusted vendors
                            </p>
                            <p class="mt-0.5 text-[10px] text-green-200">Approved by marketplace admins</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats -->
    <section class="border-y border-green-100 bg-slate-50/70">
        <div class="mx-auto grid max-w-7xl gap-4 px-4 py-10 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">

            <!-- Local Markets -->
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition duration-300 hover:-translate-y-1 hover:border-green-200 hover:shadow-card">

                <div class="flex items-center justify-between gap-4">

                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">
                            Local Markets
                        </p>

                        <p class="mt-2 text-3xl font-black tracking-tight text-slate-950">
                            <?php echo number_format($totalMarkets); ?>
                        </p>
                    </div>

                    <span class="grid h-14 w-14 place-items-center rounded-2xl bg-green-100 text-green-700">
                        <i class="fa-solid fa-store text-lg"></i>
                    </span>
                </div>

                <p class="mt-4 text-xs leading-5 text-slate-500">
                    Nearby local markets available to explore.
                </p>
            </article>

            <!-- Accepted Vendors -->
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition duration-300 hover:-translate-y-1 hover:border-green-200 hover:shadow-card">

                <div class="flex items-center justify-between gap-4">

                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">
                            Accepted Vendors
                        </p>

                        <p class="mt-2 text-3xl font-black tracking-tight text-slate-950">
                            <?php echo number_format($acceptedVendors); ?>
                        </p>
                    </div>

                    <span class="grid h-14 w-14 place-items-center rounded-2xl bg-green-100 text-green-700">
                        <i class="fa-solid fa-user-check text-lg"></i>
                    </span>
                </div>

                <p class="mt-4 text-xs leading-5 text-slate-500">
                    Trusted Vendors approved by the marketplace.
                </p>
            </article>

            <!-- Fresh Products -->
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition duration-300 hover:-translate-y-1 hover:border-amber-200 hover:shadow-card">

                <div class="flex items-center justify-between gap-4">

                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">
                            Fresh Products
                        </p>

                        <p class="mt-2 text-3xl font-black tracking-tight text-slate-950">
                            <?php echo number_format($totalProducts); ?>
                        </p>
                    </div>

                    <span class="grid h-14 w-14 place-items-center rounded-2xl bg-amber-100 text-amber-700">
                        <i class="fa-solid fa-basket-shopping text-lg"></i>
                    </span>
                </div>

                <p class="mt-4 text-xs leading-5 text-slate-500">
                    Fresh produce and farm goods from local Vendors.
                </p>
            </article>

            <!-- Customers -->
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition duration-300 hover:-translate-y-1 hover:border-blue-200 hover:shadow-card">

                <div class="flex items-center justify-between gap-4">

                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">
                            Customers
                        </p>

                        <p class="mt-2 text-3xl font-black tracking-tight text-slate-950">
                            <?php echo number_format($totalCustomers); ?>
                        </p>
                    </div>

                    <span class="grid h-14 w-14 place-items-center rounded-2xl bg-blue-100 text-blue-700">
                        <i class="fa-solid fa-users text-lg"></i>
                    </span>
                </div>

                <p class="mt-4 text-xs leading-5 text-slate-500">
                    Customers connected with local markets and Vendors.
                </p>
            </article>

        </div>
    </section>

    <!-- Featured Markets -->
    <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-black uppercase tracking-[0.22em] text-green-600">
                    Explore nearby
                </p>

                <h2 class="mt-2 text-3xl font-black tracking-tight text-[#0f2414] sm:text-4xl">
                    Featured local markets
                </h2>

                <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-500">
                    Find trusted markets, accepted vendors and fresh products close to your community.
                </p>
            </div>

            <a href="<?php echo fm_e(fm_url('markets.php')); ?>"
               class="inline-flex items-center gap-2 text-sm font-black text-green-700 transition hover:text-green-800">
                View all markets
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
        </div>

        <div class="mt-8 grid gap-6 lg:grid-cols-3">
            <?php foreach ($featuredMarkets as $market): ?>
                <article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft transition hover:-translate-y-1 hover:shadow-card">
                    <div class="relative h-60 overflow-hidden">
                        <?php if ($market['image'] !== ''): ?>

                            <img src="<?php echo home_e($market['image']); ?>"
                                 alt="<?php echo home_e($market['name']); ?>"
                                 class="h-full w-full object-cover transition duration-500 group-hover:scale-105">

                        <?php else: ?>

                            <div class="grid h-full place-items-center bg-gradient-to-br from-green-100 to-slate-100 text-green-300">

                                <i class="fa-solid fa-store text-6xl"></i>
                            </div>

                        <?php endif; ?>

                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/75 via-transparent to-transparent"></div>

                        <span class="absolute left-4 top-4 rounded-full bg-white/95 px-3 py-1.5 text-[10px] font-black text-green-700 shadow-sm backdrop-blur">
                            <i class="fa-solid fa-location-dot mr-1"></i>
                            <?php echo home_e($market['city']); ?>
                        </span>

                        <div class="absolute inset-x-0 bottom-0 p-5 text-white">
                            <h3 class="text-xl font-black">
                                <?php echo home_e($market['name']); ?>
                            </h3>

                            <p class="mt-1 text-xs text-white/75">
                                <i class="fa-regular fa-clock mr-1"></i>
                                <?php echo home_e($market['hours']); ?>
                            </p>
                        </div>
                    </div>

                    <div class="p-5">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-green-50 p-3">
                                <p class="text-lg font-black text-green-700">
                                    <?php echo number_format($market['vendors']); ?>
                                </p>
                                <p class="mt-0.5 text-[10px] font-semibold text-green-700/70">Vendors</p>
                            </div>

                            <div class="rounded-xl bg-amber-50 p-3">
                                <p class="text-lg font-black text-amber-700">
                                    <?php echo number_format($market['categories']); ?>
                                </p>
                                <p class="mt-0.5 text-[10px] font-semibold text-amber-700/70">Categories</p>
                            </div>
                        </div>

                        <a href="markets.php?view=<?php echo (int) $market['market_id']; ?>"
                           class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-green-200 px-4 py-3 text-sm font-black text-green-700 transition hover:bg-green-50">
                            Explore Market
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Categories -->
    <section class="bg-white py-16 lg:py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <p class="text-xs font-black uppercase tracking-[0.22em] text-green-600">
                    Shop by category
                </p>

                <h2 class="mt-2 text-3xl font-black tracking-tight text-[#0f2414] sm:text-4xl">
                    Everything fresh in one place
                </h2>

                <p class="mx-auto mt-3 max-w-2xl text-sm leading-7 text-slate-500">
                    Browse locally produced food and farm products from approved marketplace vendors.
                </p>
            </div>

            <div class="mt-10 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <?php foreach ($categories as $category): ?>
                    <a href="<?php echo fm_e(
                        fm_url(
                            'products.php?q=' .
                            rawurlencode((string) $category['name'])
                        )
                    ); ?>"
                       class="group rounded-2xl border border-slate-200 bg-white p-4 text-center transition hover:-translate-y-1 hover:border-green-200 hover:shadow-soft">

                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl <?php echo home_e($category['class']); ?> transition group-hover:scale-105">
                            <i class="fa-solid <?php echo home_e($category['icon']); ?> text-xl"></i>
                        </span>

                        <p class="mt-3 truncate text-sm font-black text-slate-800">
                            <?php echo home_e($category['name']); ?>
                        </p>

                        <p class="mt-1 text-[10px] font-semibold text-slate-400">
                            <?php echo number_format($category['count']); ?> products
                        </p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Products -->
    <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-black uppercase tracking-[0.22em] text-green-600">
                    Best sellers
                </p>

                <h2 class="mt-2 text-3xl font-black tracking-tight text-[#0f2414] sm:text-4xl">
                    Popular local products
                </h2>
            </div>

            <a href="<?php echo fm_e(fm_url('products.php')); ?>"
               class="inline-flex items-center gap-2 text-sm font-black text-green-700 hover:text-green-800">
                Browse all products
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
        </div>

        <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <?php foreach ($featuredProducts as $product): ?>
                <?php
                $productTargetUrl = fm_is_logged_in()
                    ? fm_url(
                        'products.php?view=' .
                        (int) $product['product_id']
                    )
                    : fm_url(
                        'signin.php?notice=login_required&next=' .
                        rawurlencode(
                            'products.php?view=' .
                            (int) $product['product_id']
                        )
                    );
                ?>

                <a href="<?php echo fm_e($productTargetUrl); ?>"
                   title="<?php echo fm_is_logged_in()
                       ? 'View product details'
                       : 'Sign In to view and purchase this product'; ?>"
                   class="group block overflow-hidden rounded-[1.5rem] border border-slate-200 bg-white shadow-soft transition hover:-translate-y-1 hover:shadow-card">

                    <div class="relative h-52 overflow-hidden bg-slate-100">

                        <?php if ($product['image'] !== ''): ?>

                            <img src="<?php echo home_e($product['image']); ?>"
                                 alt="<?php echo home_e($product['name']); ?>"
                                 class="h-full w-full object-cover transition duration-500 group-hover:scale-105">

                        <?php else: ?>

                            <div class="grid h-full place-items-center bg-gradient-to-br from-green-50 to-slate-100 text-green-300">

                                <i class="fa-solid fa-image text-5xl"></i>
                            </div>

                        <?php endif; ?>

                        <span class="absolute left-3 top-3 rounded-full px-3 py-1.5 text-[10px] font-black text-white shadow-sm
                            <?php echo (int) $product['stock_quantity'] > 0
                                ? 'bg-green-600'
                                : 'bg-red-600'; ?>">

                            <?php echo (int) $product['stock_quantity'] > 0
                                ? 'Fresh'
                                : 'Out of Stock'; ?>
                        </span>

                    </div>

                    <div class="p-5">

                        <div class="flex items-center justify-between gap-3">

                            <span class="inline-flex items-center gap-1 text-xs font-bold text-amber-500">

                                <i class="fa-solid fa-star"></i>

                                <?php echo number_format(
                                    (float) $product['rating'],
                                    1
                                ); ?>
                            </span>

                            <span class="truncate text-[10px] font-semibold text-slate-400">

                                <?php echo home_e(
                                    $product['market']
                                ); ?>
                            </span>
                        </div>

                        <h3 class="mt-3 truncate text-base font-black text-slate-900 transition group-hover:text-green-700">

                            <?php echo home_e(
                                $product['name']
                            ); ?>
                        </h3>

                        <p class="mt-1 truncate text-xs text-slate-400">

                            by
                            <?php echo home_e(
                                $product['vendor']
                            ); ?>
                        </p>

                        <div class="mt-4 flex items-end justify-between gap-3 border-t border-slate-100 pt-4">

                            <div>
                                <p class="text-lg font-black text-green-700">

                                    <?php echo number_format(
                                        (float) $product['price']
                                    ); ?>

                                    MMK
                                </p>

                                <p class="text-[10px] text-slate-400">

                                    Stock:
                                    <?php echo number_format(
                                        (int) $product['stock_quantity']
                                    ); ?>
                                </p>
                            </div>

                            <span class="grid h-10 w-10 place-items-center rounded-xl bg-green-600 text-white shadow-sm transition group-hover:bg-green-700">

                                <?php if (fm_is_logged_in()): ?>

                                    <i class="fa-solid fa-arrow-right text-sm"></i>

                                <?php else: ?>

                                    <i class="fa-solid fa-right-to-bracket text-sm"></i>

                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Events -->
    <section class="relative overflow-hidden bg-[#eef4e8] py-16 lg:py-20">

        <div class="pointer-events-none absolute -left-28 top-8 h-72 w-72 rounded-full bg-[#dbe9d2]/70 blur-3xl"></div>
        <div class="pointer-events-none absolute -right-24 bottom-0 h-80 w-80 rounded-full bg-[#f4ead7]/70 blur-3xl"></div>

        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <span class="inline-flex items-center gap-2 rounded-full border border-[#d4e3cc] bg-white/70 px-3 py-2 text-[11px] font-black uppercase tracking-[0.18em] text-[#5d7a52] shadow-sm backdrop-blur">
                        <i class="fa-regular fa-calendar"></i>
                        Community calendar
                    </span>

                    <h2 class="mt-4 text-3xl font-black tracking-tight text-[#20301f] sm:text-4xl">
                        Upcoming market events
                    </h2>

                    <p class="mt-3 max-w-2xl text-sm leading-7 text-[#6d7b68]">
                        Join local food festivals, weekend markets and seasonal harvest events.
                    </p>
                </div>

                <a href="<?php echo fm_e(fm_url('events.php')); ?>"
                   class="inline-flex items-center gap-2 text-sm font-black text-[#4d7743] transition hover:text-[#355f2e]">
                    View all events
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            </div>

            <?php if (empty($events)): ?>

                <div class="mt-8 rounded-[1.5rem] border border-[#d9e5d3] bg-white/75 px-6 py-14 text-center shadow-sm backdrop-blur">
                    <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[#dfead8] text-[#5b7d50]">
                        <i class="fa-regular fa-calendar-xmark text-xl"></i>
                    </span>
                    <h3 class="mt-4 text-base font-black text-[#273326]">No upcoming events</h3>
                    <p class="mt-2 text-sm text-[#7a8676]">New market events will appear here when they are available.</p>
                </div>

            <?php else: ?>

                <div class="mt-8 grid gap-5 lg:grid-cols-3">
                    <?php foreach ($events as $event): ?>
                        <article class="group rounded-[1.5rem] border border-[#d8e4d2] bg-white/85 p-5 shadow-[0_12px_32px_rgba(67,87,58,0.06)] backdrop-blur transition hover:-translate-y-1 hover:border-[#c5d8bc] hover:shadow-[0_18px_42px_rgba(67,87,58,0.10)]">

                            <div class="flex gap-4">
                                <div class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-[#dcebd3] text-center text-[#3f6b37] ring-1 ring-[#d0e1c8]">
                                    <span>
                                        <span class="block text-2xl font-black leading-none">
                                            <?php echo home_e($event['day']); ?>
                                        </span>
                                        <span class="mt-1 block text-[10px] font-black tracking-wider">
                                            <?php echo home_e($event['month']); ?>
                                        </span>
                                    </span>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <h3 class="truncate text-base font-black text-[#273326]">
                                        <?php echo home_e($event['name']); ?>
                                    </h3>

                                    <p class="mt-1 truncate text-xs font-semibold text-[#72816d]">
                                        <i class="fa-solid fa-store mr-1 text-[#6d9660]"></i>
                                        <?php echo home_e($event['market']); ?>
                                    </p>

                                    <p class="mt-1 text-xs text-[#879181]">
                                        <i class="fa-regular fa-clock mr-1 text-[#6d9660]"></i>
                                        <?php echo home_e($event['time']); ?>
                                    </p>
                                </div>
                            </div>

                            <p class="mt-4 line-clamp-3 text-xs leading-6 text-[#72806f]">
                                <?php echo home_e($event['description']); ?>
                            </p>

                            <button type="button"
                                    class="event-details-button mt-5 inline-flex items-center gap-2 rounded-xl bg-[#edf5e9] px-3.5 py-2 text-xs font-black text-[#4d7743] transition group-hover:bg-[#dfeeda] group-hover:text-[#355f2e]"
                                    data-event-name="<?php echo home_e($event['name']); ?>"
                                    data-event-market="<?php echo home_e($event['market']); ?>"
                                    data-event-time="<?php echo home_e($event['time']); ?>"
                                    data-event-description="<?php echo home_e($event['description']); ?>"
                                    data-event-status="<?php echo home_e($event['status']); ?>"
                                    data-event-duration="<?php echo (int) $event['duration']; ?>"
                                    data-event-hours="<?php echo home_e($event['market_hours']); ?>"
                                    data-event-location="<?php echo home_e($event['market_location']); ?>"
                                    data-market-description="<?php echo home_e($event['market_description']); ?>"
                                    data-event-image="<?php echo home_e($event['image']); ?>">
                                Event details
                                <i class="fa-solid fa-arrow-right text-[10px]"></i>
                            </button>
                        </article>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </div>
    </section>


    <!-- Event Details Modal -->
    <div id="eventDetailsModal"
         class="fixed inset-0 z-[90] hidden overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm"
         aria-hidden="true">

        <div class="mx-auto flex min-h-full max-w-4xl items-center justify-center">

            <div class="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">

                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">

                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-green-600">
                            Event Details
                        </p>

                        <h2 id="modalEventName"
                            class="mt-1 text-xl font-black text-slate-950">
                            Event
                        </h2>
                    </div>

                    <button type="button"
                            id="closeEventModalButton"
                            title="Close"
                            class="grid h-10 w-10 place-items-center rounded-xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="max-h-[78vh] overflow-y-auto p-5 sm:p-6">

                    <!-- Event / Market Image -->
                    <div id="modalEventImageWrap"
                         class="hidden">
                        <img id="modalEventImage"
                             src=""
                             alt="Event market"
                             class="h-40 w-full max-w-xs rounded-xl object-cover">
                    </div>

                    <div id="modalEventImageFallback"
                         class="grid h-48 place-items-center rounded-2xl bg-green-50 text-green-300">
                        <i class="fa-solid fa-calendar-days text-6xl"></i>
                    </div>

                    <!-- Status -->
                    <div class="mt-6 flex flex-wrap items-center gap-2">

                        <span id="modalEventStatus"
                              class="rounded-full bg-green-50 px-3 py-1.5 text-xs font-bold capitalize text-green-700">
                            Ongoing
                        </span>

                        <span id="modalEventDuration"
                              class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700">
                            1 day
                        </span>
                    </div>

                    <p id="modalEventDescription"
                       class="mt-5 whitespace-pre-line text-sm leading-7 text-slate-600">
                        —
                    </p>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">

                        <!-- Schedule -->
                        <section class="rounded-2xl border border-slate-200 p-4">

                            <h3 class="text-sm font-black text-slate-900">
                                Schedule
                            </h3>

                            <div class="mt-4 space-y-3 text-xs leading-6 text-slate-500">

                                <p class="flex gap-3">

                                    <i class="fa-regular fa-calendar mt-1 w-4 text-center text-green-600"></i>

                                    <span id="modalEventTime">
                                        —
                                    </span>
                                </p>

                                <p class="flex gap-3">

                                    <i class="fa-regular fa-clock mt-1 w-4 text-center text-green-600"></i>

                                    <span id="modalEventHours">
                                        —
                                    </span>
                                </p>
                            </div>
                        </section>

                        <!-- Market Location -->
                        <section class="rounded-2xl border border-slate-200 p-4">

                            <h3 class="text-sm font-black text-slate-900">
                                Market Location
                            </h3>

                            <div class="mt-4 space-y-3 text-xs leading-6 text-slate-500">

                                <p id="modalEventMarket"
                                   class="font-bold text-slate-800">
                                    —
                                </p>

                                <p class="flex gap-3">

                                    <i class="fa-solid fa-location-dot mt-1 w-4 text-center text-green-600"></i>

                                    <span id="modalEventLocation">
                                        —
                                    </span>
                                </p>
                            </div>
                        </section>
                    </div>

                    <!-- About Market -->
                    <section id="modalMarketAboutSection"
                             class="mt-5 rounded-2xl bg-slate-50 p-4">

                        <h3 class="text-sm font-black text-slate-900">
                            About the Market
                        </h3>

                        <p id="modalMarketDescription"
                           class="mt-3 whitespace-pre-line text-xs leading-6 text-slate-500">
                            —
                        </p>
                    </section>
                </div>

                <!-- Modal Footer -->
                <div class="flex justify-end border-t border-slate-100 px-5 py-4">

                    <button type="button"
                            id="closeEventModalFooterButton"
                            class="rounded-xl bg-green-600 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-green-700">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Vendor CTA -->
    <?php if ($showVendorCta): ?>

        <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20">

            <div class="relative overflow-hidden rounded-[2rem] bg-gradient-to-r from-green-600 to-green-700 px-6 py-12 text-white shadow-card sm:px-10 lg:px-14">

                <div class="pointer-events-none absolute -right-16 -top-20 h-72 w-72 rounded-full border-[36px] border-white/10"></div>

                <div class="pointer-events-none absolute -bottom-24 right-40 h-64 w-64 rounded-full bg-lime-300/15 blur-3xl"></div>

                <div class="relative grid items-center gap-8 lg:grid-cols-[1fr_auto]">

                    <?php if ($isVendorHome): ?>

                        <div>
                            <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-2 text-xs font-bold backdrop-blur">
                                <i class="fa-solid fa-store"></i>
                                Vendor Account
                            </span>

                            <h2 class="mt-5 max-w-3xl text-3xl font-black tracking-tight sm:text-4xl">
                                Manage your marketplace business.
                            </h2>

                            <p class="mt-4 max-w-2xl text-sm leading-7 text-green-50/85">
                                Manage your assigned markets, products and customer orders
                                from your Vendor Dashboard.
                            </p>
                        </div>

                        <div>
                            <a href="<?php echo fm_e(fm_dashboard_url()); ?>"
                               class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3.5 text-sm font-black text-green-700 shadow-lg transition hover:bg-green-50">

                                Go to Vendor Dashboard
                                <i class="fa-solid fa-arrow-right text-xs"></i>
                            </a>
                        </div>

                    <?php else: ?>

                        <div>
                            <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-2 text-xs font-bold backdrop-blur">
                                <i class="fa-solid fa-store"></i>
                                For local Vendors
                            </span>

                            <h2 class="mt-5 max-w-3xl text-3xl font-black tracking-tight sm:text-4xl">
                                Grow your local business with our marketplace.
                            </h2>

                            <p class="mt-4 max-w-2xl text-sm leading-7 text-green-50/85">
                                Register as a Vendor, wait for administrator approval and
                                manage products under your assigned markets.
                            </p>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row lg:flex-col">

                            <a href="<?php echo fm_e(fm_url('vendorsignup.php')); ?>"
                               class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3.5 text-sm font-black text-green-700 shadow-lg transition hover:bg-green-50">

                                Register as Vendor
                                <i class="fa-solid fa-arrow-right text-xs"></i>
                            </a>

                            <?php if ($homeUser === null): ?>

                                <a href="<?php echo fm_e(fm_url('signin.php')); ?>"
                                   class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/30 bg-white/10 px-6 py-3.5 text-sm font-black text-white backdrop-blur transition hover:bg-white/15">

                                    Vendor Login
                                </a>

                            <?php endif; ?>
                        </div>

                    <?php endif; ?>

                </div>
            </div>
        </section>

    <?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('eventDetailsModal');
    var closeTop = document.getElementById('closeEventModalButton');
    var closeBottom = document.getElementById('closeEventModalFooterButton');
    var buttons = document.querySelectorAll('.event-details-button');

    var modalName = document.getElementById('modalEventName');
    var modalMarket = document.getElementById('modalEventMarket');
    var modalTime = document.getElementById('modalEventTime');
    var modalHours = document.getElementById('modalEventHours');
    var modalLocation = document.getElementById('modalEventLocation');
    var modalDescription = document.getElementById('modalEventDescription');
    var modalStatus = document.getElementById('modalEventStatus');
    var modalDuration = document.getElementById('modalEventDuration');
    var modalMarketDescription = document.getElementById('modalMarketDescription');
    var modalMarketAboutSection = document.getElementById('modalMarketAboutSection');
    var modalImageWrap = document.getElementById('modalEventImageWrap');
    var modalImageFallback = document.getElementById('modalEventImageFallback');
    var modalImage = document.getElementById('modalEventImage');

    function openEventModal(button) {
        if (!modal || !button) {
            return;
        }

        var eventName =
            button.getAttribute('data-event-name') || 'Event';

        var marketName =
            button.getAttribute('data-event-market') || '—';

        var eventTime =
            button.getAttribute('data-event-time') || '—';

        var eventHours =
            button.getAttribute('data-event-hours') || '—';

        var eventLocation =
            button.getAttribute('data-event-location') || '—';

        var description =
            button.getAttribute('data-event-description') || 'No description available.';

        var status =
            button.getAttribute('data-event-status') || 'Upcoming';

        var duration =
            parseInt(
                button.getAttribute('data-event-duration') || '1',
                10
            );

        var marketDescription =
            button.getAttribute('data-market-description') || '';

        var image =
            button.getAttribute('data-event-image') || '';

        modalName.textContent = eventName;
        modalMarket.textContent = marketName;
        modalTime.textContent = eventTime;
        modalHours.textContent = eventHours;
        modalLocation.textContent = eventLocation;
        modalDescription.textContent = description;
        modalStatus.textContent = status;

        modalDuration.textContent =
            duration.toLocaleString() +
            (duration === 1 ? ' day' : ' days');

        if (status.toLowerCase() === 'ongoing') {
            modalStatus.className =
                'rounded-full bg-green-50 px-3 py-1.5 text-xs font-bold capitalize text-green-700';
        } else if (status.toLowerCase() === 'past') {
            modalStatus.className =
                'rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold capitalize text-slate-600';
        } else {
            modalStatus.className =
                'rounded-full bg-amber-50 px-3 py-1.5 text-xs font-bold capitalize text-amber-700';
        }

        if (marketDescription.trim() !== '') {
            modalMarketDescription.textContent =
                marketDescription;

            modalMarketAboutSection.classList.remove(
                'hidden'
            );
        } else {
            modalMarketAboutSection.classList.add(
                'hidden'
            );
        }

        if (image.trim() !== '') {
            modalImage.src = image;
            modalImage.alt =
                marketName + ' event image';

            modalImageWrap.classList.remove(
                'hidden'
            );

            modalImageFallback.classList.add(
                'hidden'
            );
        } else {
            modalImage.removeAttribute('src');

            modalImageWrap.classList.add(
                'hidden'
            );

            modalImageFallback.classList.remove(
                'hidden'
            );
        }

        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
    }

    function closeEventModal() {
        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            openEventModal(button);
        });
    });

    if (closeTop) {
        closeTop.addEventListener(
            'click',
            closeEventModal
        );
    }

    if (closeBottom) {
        closeBottom.addEventListener(
            'click',
            closeEventModal
        );
    }

    if (modal) {
        modal.addEventListener(
            'click',
            function (event) {
                if (event.target === modal) {
                    closeEventModal();
                }
            }
        );
    }

    document.addEventListener(
        'keydown',
        function (event) {
            if (
                event.key === 'Escape' &&
                modal &&
                !modal.classList.contains('hidden')
            ) {
                closeEventModal();
            }
        }
    );
});
</script>

</main>
<?php require __DIR__ . '/footer.php'; ?>