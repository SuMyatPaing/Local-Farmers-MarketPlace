<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
fm_require_role('user');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Clean Customer Dashboard
|--------------------------------------------------------------------------
| Save as:
| C:\xampp\htdocs\farmer_marketplace\userdashboard.php
|
| PHP 7.1 compatible.
|--------------------------------------------------------------------------
*/

function ud_e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function ud_redirect($location)
{
    header('Location: ' . $location);
    exit;
}

function ud_initial($name)
{
    $name = trim((string) $name);

    if ($name === '') {
        return 'U';
    }

    return strtoupper(substr($name, 0, 1));
}

function ud_date($value, $format)
{
    $time = strtotime((string) $value);

    return $time !== false
        ? date($format, $time)
        : '—';
}

function ud_order_status($total, $pending, $confirmed, $rejected)
{
    $total = (int) $total;
    $pending = (int) $pending;
    $confirmed = (int) $confirmed;
    $rejected = (int) $rejected;

    if ($total <= 0) {
        return array(
            'label' => 'Pending',
            'class' => 'bg-amber-50 text-amber-700 ring-amber-200'
        );
    }

    if ($confirmed === $total) {
        return array(
            'label' => 'Confirmed',
            'class' => 'bg-green-50 text-green-700 ring-green-200'
        );
    }

    if ($rejected === $total) {
        return array(
            'label' => 'Rejected',
            'class' => 'bg-red-50 text-red-700 ring-red-200'
        );
    }

    if ($pending === $total) {
        return array(
            'label' => 'Pending',
            'class' => 'bg-amber-50 text-amber-700 ring-amber-200'
        );
    }

    return array(
        'label' => 'Partially Processed',
        'class' => 'bg-blue-50 text-blue-700 ring-blue-200'
    );
}

function ud_payment_label($method)
{
    $method = strtolower((string) $method);

    if ($method === 'kbzpay') {
        return 'KBZPay';
    }

    if ($method === 'wave_money') {
        return 'Wave Money';
    }

    return 'Not selected';
}

function ud_cart_count()
{
    if (
        !isset($_SESSION['cart']) ||
        !is_array($_SESSION['cart'])
    ) {
        return 0;
    }

    $cart = $_SESSION['cart'];

    if (
        isset($cart['items']) &&
        is_array($cart['items'])
    ) {
        $cart = $cart['items'];
    }

    $count = 0;

    foreach ($cart as $productId => $quantity) {
        if (
            is_numeric($productId) &&
            is_numeric($quantity)
        ) {
            $count += max(0, (int) $quantity);
        }
    }

    return $count;
}

/*
|--------------------------------------------------------------------------
| Authenticated Customer
|--------------------------------------------------------------------------
*/

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Database
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
| Customer
|--------------------------------------------------------------------------
*/

$userStatement = $pdo->prepare(
    "SELECT
        user_name,
        email,
        phone_number
     FROM users
     WHERE user_id = :user_id
       AND role = 'user'
       AND status = 'active'
     LIMIT 1"
);

$userStatement->execute(array(
    'user_id' => $userId
));

$user = $userStatement->fetch();

if (!$user) {
    session_unset();
    session_destroy();

    ud_redirect(
        'signin.php?notice=login_required'
    );
}

$userName = (string) $user['user_name'];
$userEmail = (string) $user['email'];
$userPhone = (string) $user['phone_number'];
$userInitial = ud_initial($userName);
$cartCount = ud_cart_count();

/*
|--------------------------------------------------------------------------
| Order Statistics
|--------------------------------------------------------------------------
*/

$orderStats = array(
    'total_orders' => 0,
    'pending_vendor_orders' => 0,
    'confirmed_vendor_orders' => 0,
    'rejected_vendor_orders' => 0
);

$statsStatement = $pdo->prepare(
    "SELECT
        (
            SELECT COUNT(*)
            FROM purchase_process pp_count
            WHERE pp_count.user_id = :user_total
        ) AS total_orders,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_pending
            INNER JOIN purchase_process pp_pending
                ON pp_pending.purchase_id =
                   vo_pending.purchase_id
            WHERE pp_pending.user_id = :user_pending
              AND vo_pending.order_status = 'pending'
        ) AS pending_vendor_orders,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_confirmed
            INNER JOIN purchase_process pp_confirmed
                ON pp_confirmed.purchase_id =
                   vo_confirmed.purchase_id
            WHERE pp_confirmed.user_id = :user_confirmed
              AND vo_confirmed.order_status = 'confirmed'
        ) AS confirmed_vendor_orders,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_rejected
            INNER JOIN purchase_process pp_rejected
                ON pp_rejected.purchase_id =
                   vo_rejected.purchase_id
            WHERE pp_rejected.user_id = :user_rejected
              AND vo_rejected.order_status = 'rejected'
        ) AS rejected_vendor_orders"
);

$statsStatement->execute(array(
    'user_total' => $userId,
    'user_pending' => $userId,
    'user_confirmed' => $userId,
    'user_rejected' => $userId
));

$statsRow = $statsStatement->fetch();

if ($statsRow) {
    $orderStats = $statsRow;
}

/*
|--------------------------------------------------------------------------
| Recent Orders
|--------------------------------------------------------------------------
*/

$recentStatement = $pdo->prepare(
    "SELECT
        pp.purchase_id,
        pp.total_amount,
        pp.payment_method,
        pp.created_at,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_total
            WHERE vo_total.purchase_id =
                  pp.purchase_id
        ) AS vendor_count,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_pending
            WHERE vo_pending.purchase_id =
                  pp.purchase_id
              AND vo_pending.order_status = 'pending'
        ) AS pending_count,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_confirmed
            WHERE vo_confirmed.purchase_id =
                  pp.purchase_id
              AND vo_confirmed.order_status = 'confirmed'
        ) AS confirmed_count,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_rejected
            WHERE vo_rejected.purchase_id =
                  pp.purchase_id
              AND vo_rejected.order_status = 'rejected'
        ) AS rejected_count

     FROM purchase_process pp
     WHERE pp.user_id = :user_id
     ORDER BY pp.created_at DESC, pp.purchase_id DESC
     LIMIT 5"
);

$recentStatement->execute(array(
    'user_id' => $userId
));

$recentOrders = $recentStatement->fetchAll();

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
        ) AS primary_photo
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
       AND p.stock_quantity > 0
     ORDER BY p.created_at DESC, p.product_id DESC
     LIMIT 4"
);

$featuredProducts =
    $productStatement->fetchAll();

function ud_photo_url($path)
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

    $path = str_replace('\\', '/', $path);

    while (strpos($path, '../') === 0) {
        $path = substr($path, 3);
    }

    return ltrim($path, '/');
}
?>
<?php
$pageTitle = 'Dashboard | Local Farmers Marketplace';
require __DIR__ . '/header.php';
?>

    <main class="mx-auto max-w-7xl px-4 py-7 sm:px-6 lg:px-8 lg:py-9">

        <!-- Welcome -->
        <section class="rounded-2xl border border-green-100 bg-white p-6 shadow-soft sm:p-7">

            <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">

                <div>

                    <p class="text-xs font-black uppercase tracking-[0.18em] text-green-600">
                        Customer Dashboard
                    </p>

                    <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">

                        Welcome back, <?php echo ud_e($userName); ?>
                    </h1>

                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                        Track your orders, continue shopping and manage your account from one simple dashboard.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">

                    <a href="my_orders.php"
                       class="inline-flex h-11 items-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-black text-white hover:bg-green-700">

                        <i class="fa-solid fa-receipt"></i>
                        My Orders
                    </a>

                    <a href="products.php"
                       class="inline-flex h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 text-sm font-black text-slate-700 hover:bg-slate-50">

                        <i class="fa-solid fa-basket-shopping"></i>
                        Shop Products
                    </a>
                </div>
            </div>
        </section>

        <!-- Order Summary -->
        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">

            <a href="my_orders.php"
               class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition hover:border-blue-200">

                <div class="flex items-center justify-between">

                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-600">
                        <i class="fa-solid fa-box"></i>
                    </span>

                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                        Orders
                    </span>
                </div>

                <p class="mt-4 text-2xl font-black text-slate-950">
                    <?php echo number_format((int) $orderStats['total_orders']); ?>
                </p>

                <p class="mt-1 text-xs text-slate-400">
                    Total purchases
                </p>
            </a>

            <a href="my_orders.php"
               class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition hover:border-amber-200">

                <div class="flex items-center justify-between">

                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-amber-50 text-amber-600">
                        <i class="fa-solid fa-clock"></i>
                    </span>

                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                        Pending
                    </span>
                </div>

                <p class="mt-4 text-2xl font-black text-slate-950">
                    <?php echo number_format((int) $orderStats['pending_vendor_orders']); ?>
                </p>

                <p class="mt-1 text-xs text-slate-400">
                    Vendor orders waiting
                </p>
            </a>

            <a href="my_orders.php"
               class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition hover:border-green-200">

                <div class="flex items-center justify-between">

                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-green-50 text-green-600">
                        <i class="fa-solid fa-circle-check"></i>
                    </span>

                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                        Confirmed
                    </span>
                </div>

                <p class="mt-4 text-2xl font-black text-slate-950">
                    <?php echo number_format((int) $orderStats['confirmed_vendor_orders']); ?>
                </p>

                <p class="mt-1 text-xs text-slate-400">
                    Vendor orders accepted
                </p>
            </a>

            <a href="my_orders.php"
               class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition hover:border-red-200">

                <div class="flex items-center justify-between">

                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-red-50 text-red-600">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </span>

                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                        Rejected
                    </span>
                </div>

                <p class="mt-4 text-2xl font-black text-slate-950">
                    <?php echo number_format((int) $orderStats['rejected_vendor_orders']); ?>
                </p>

                <p class="mt-1 text-xs text-slate-400">
                    Vendor orders unavailable
                </p>
            </a>
        </section>

        <!-- Main Dashboard Grid -->
        <div class="mt-6 grid gap-6 xl:grid-cols-[1fr_320px]">

            <!-- Recent Orders -->
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">

                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">

                    <div>

                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-green-600">
                            Orders
                        </p>

                        <h2 class="mt-1 text-lg font-black text-slate-950">
                            Recent Orders
                        </h2>
                    </div>

                    <a href="my_orders.php"
                       class="text-xs font-black text-green-700 hover:text-green-800">
                        View all
                        <i class="fa-solid fa-arrow-right ml-1 text-[9px]"></i>
                    </a>
                </div>

                <?php if (empty($recentOrders)): ?>

                    <div class="p-10 text-center">

                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-50 text-slate-300">
                            <i class="fa-solid fa-receipt"></i>
                        </span>

                        <p class="mt-3 text-sm font-bold text-slate-600">
                            No orders yet
                        </p>

                        <a href="products.php"
                           class="mt-3 inline-flex text-xs font-black text-green-700">
                            Start shopping
                        </a>
                    </div>

                <?php else: ?>

                    <div class="divide-y divide-slate-100">

                        <?php foreach ($recentOrders as $order): ?>
                            <?php
                            $displayStatus = ud_order_status(
                                $order['vendor_count'],
                                $order['pending_count'],
                                $order['confirmed_count'],
                                $order['rejected_count']
                            );
                            ?>

                            <a href="my_orders.php?view=<?php echo (int) $order['purchase_id']; ?>"
                               class="grid gap-4 px-5 py-4 transition hover:bg-slate-50 sm:grid-cols-[110px_1fr_150px_120px] sm:items-center sm:px-6">

                                <div>

                                    <p class="text-sm font-black text-green-700">
                                        #<?php echo number_format((int) $order['purchase_id']); ?>
                                    </p>

                                    <p class="mt-1 text-[10px] text-slate-400">
                                        <?php echo ud_e(
                                            ud_date(
                                                $order['created_at'],
                                                'M j, Y'
                                            )
                                        ); ?>
                                    </p>
                                </div>

                                <div>

                                    <p class="text-xs font-bold text-slate-700">
                                        <?php echo number_format((int) $order['vendor_count']); ?>
                                        vendor(s)
                                    </p>

                                    <p class="mt-1 text-[10px] text-slate-400">
                                        <?php echo ud_e(
                                            ud_payment_label(
                                                $order['payment_method']
                                            )
                                        ); ?>
                                    </p>
                                </div>

                                <div>

                                    <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-black ring-1 ring-inset <?php echo ud_e($displayStatus['class']); ?>">
                                        <?php echo ud_e($displayStatus['label']); ?>
                                    </span>
                                </div>

                                <p class="text-left text-sm font-black text-slate-900 sm:text-right">
                                    <?php echo number_format((float) $order['total_amount']); ?>
                                    MMK
                                </p>
                            </a>

                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </section>

            <!-- Quick Actions -->
            <aside class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft">

                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-green-600">
                    Quick Actions
                </p>

                <h2 class="mt-1 text-lg font-black text-slate-950">
                    What do you need?
                </h2>

                <div class="mt-5 space-y-2">

                    <a href="my_orders.php"
                       class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 transition hover:border-green-200 hover:bg-green-50">

                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-amber-50 text-amber-600">
                            <i class="fa-solid fa-receipt"></i>
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-black text-slate-800">
                                My Orders
                            </p>
                            <p class="mt-0.5 text-[10px] text-slate-400">
                                Track vendor decisions
                            </p>
                        </div>

                        <i class="fa-solid fa-chevron-right text-[9px] text-slate-300"></i>
                    </a>

                    <a href="cart.php"
                       class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 transition hover:border-green-200 hover:bg-green-50">

                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-cart-shopping"></i>
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-black text-slate-800">
                                Shopping Cart
                            </p>
                            <p class="mt-0.5 text-[10px] text-slate-400">
                                <?php echo number_format($cartCount); ?> item(s)
                            </p>
                        </div>

                        <i class="fa-solid fa-chevron-right text-[9px] text-slate-300"></i>
                    </a>

                    <a href="products.php"
                       class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 transition hover:border-green-200 hover:bg-green-50">

                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-basket-shopping"></i>
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-black text-slate-800">
                                Shop Products
                            </p>
                            <p class="mt-0.5 text-[10px] text-slate-400">
                                Browse local products
                            </p>
                        </div>

                        <i class="fa-solid fa-chevron-right text-[9px] text-slate-300"></i>
                    </a>

                    <a href="profile.php"
                       class="flex items-center gap-3 rounded-xl border border-slate-100 p-3 transition hover:border-green-200 hover:bg-green-50">

                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-slate-100 text-slate-600">
                            <i class="fa-solid fa-user-pen"></i>
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-black text-slate-800">
                                My Profile
                            </p>
                            <p class="mt-0.5 text-[10px] text-slate-400">
                                Account information
                            </p>
                        </div>

                        <i class="fa-solid fa-chevron-right text-[9px] text-slate-300"></i>
                    </a>
                </div>
            </aside>
        </div>

        <!-- Products -->
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-soft sm:p-6">

            <div class="flex items-center justify-between gap-4">

                <div>

                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-green-600">
                        Fresh Products
                    </p>

                    <h2 class="mt-1 text-lg font-black text-slate-950">
                        Continue Shopping
                    </h2>
                </div>

                <a href="products.php"
                   class="text-xs font-black text-green-700">
                    Browse all
                    <i class="fa-solid fa-arrow-right ml-1 text-[9px]"></i>
                </a>
            </div>

            <?php if (empty($featuredProducts)): ?>

                <p class="mt-6 text-sm text-slate-400">
                    No products available right now.
                </p>

            <?php else: ?>

                <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                    <?php foreach ($featuredProducts as $product): ?>
                        <?php
                        $photoUrl =
                            ud_photo_url(
                                $product['primary_photo']
                            );
                        ?>

                        <a href="products.php?view=<?php echo (int) $product['product_id']; ?>"
                           class="group overflow-hidden rounded-2xl border border-slate-200 transition hover:border-green-200 hover:shadow-soft">

                            <div class="h-36 overflow-hidden bg-slate-100">

                                <?php if ($photoUrl !== ''): ?>

                                    <img src="<?php echo ud_e($photoUrl); ?>"
                                         alt="<?php echo ud_e($product['product_name']); ?>"
                                         class="h-full w-full object-cover transition duration-300 group-hover:scale-105">

                                <?php else: ?>

                                    <div class="grid h-full place-items-center bg-green-50 text-green-300">
                                        <i class="fa-solid fa-image text-3xl"></i>
                                    </div>

                                <?php endif; ?>
                            </div>

                            <div class="p-4">

                                <p class="truncate text-[10px] font-bold text-green-600">
                                    <?php echo ud_e($product['market_name']); ?>
                                </p>

                                <h3 class="mt-1 truncate text-sm font-black text-slate-900">
                                    <?php echo ud_e($product['product_name']); ?>
                                </h3>

                                <p class="mt-1 truncate text-[10px] text-slate-400">
                                    <?php echo ud_e($product['vendor_name']); ?>
                                </p>

                                <p class="mt-3 text-sm font-black text-green-700">
                                    <?php echo number_format((float) $product['price']); ?>
                                    MMK
                                </p>
                            </div>
                        </a>

                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </section>

        <!-- Account -->
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-soft">

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                <div class="flex items-center gap-4">

                    <span class="grid h-12 w-12 place-items-center rounded-full bg-green-600 text-sm font-black text-white">
                        <?php echo ud_e($userInitial); ?>
                    </span>

                    <div>
                        <p class="text-sm font-black text-slate-900">
                            <?php echo ud_e($userName); ?>
                        </p>

                        <p class="mt-1 text-xs text-slate-400">
                            <?php echo ud_e($userEmail); ?>
                            <?php if ($userPhone !== ''): ?>
                                · <?php echo ud_e($userPhone); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <a href="profile.php"
                   class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-green-200 px-4 text-xs font-black text-green-700 hover:bg-green-50">

                    <i class="fa-solid fa-user-pen"></i>
                    Edit Profile
                </a>
            </div>
        </section>
    </main>
<?php require __DIR__ . '/footer.php'; ?>
