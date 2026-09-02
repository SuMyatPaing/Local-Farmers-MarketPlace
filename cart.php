<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/marketplace.php';
fm_require_role('user');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Customer Shopping Cart
|--------------------------------------------------------------------------
| Save as:
| C:\xampp\htdocs\farmer_marketplace\cart.php
|
| PHP 7.1 compatible.
|--------------------------------------------------------------------------
*/

function cart_e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function cart_redirect($location)
{
    header('Location: ' . $location);
    exit;
}

function cart_photo_url($path)
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

function cart_csrf_token()
{
    if (
        !isset($_SESSION['cart_csrf_token']) ||
        !is_string($_SESSION['cart_csrf_token']) ||
        $_SESSION['cart_csrf_token'] === ''
    ) {
        $_SESSION['cart_csrf_token'] =
            bin2hex(random_bytes(24));
    }

    return $_SESSION['cart_csrf_token'];
}

function cart_verify_csrf($token)
{
    return isset($_SESSION['cart_csrf_token']) &&
        is_string($_SESSION['cart_csrf_token']) &&
        hash_equals(
            $_SESSION['cart_csrf_token'],
            (string) $token
        );
}

function cart_flash($type, $message)
{
    $_SESSION['cart_flash'] = array(
        'type' => (string) $type,
        'message' => (string) $message
    );
}

/*
|--------------------------------------------------------------------------
| Authenticated Customer
|--------------------------------------------------------------------------
*/

$userId = (int) $_SESSION['user_id'];

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
| Normalize Session Cart
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['cart']) ||
    !is_array($_SESSION['cart'])
) {
    $_SESSION['cart'] = array();
}

/*
|--------------------------------------------------------------------------
| Cart Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!cart_verify_csrf($csrfToken)) {
        cart_flash(
            'error',
            'Your form session expired. Please try again.'
        );

        cart_redirect('cart.php');
    }

    $action = isset($_POST['action'])
        ? trim((string) $_POST['action'])
        : '';

    if ($action === 'update_cart') {
        $quantities = isset($_POST['quantity']) &&
            is_array($_POST['quantity'])
                ? $_POST['quantity']
                : array();

        foreach ($quantities as $productId => $quantity) {
            $productId = (int) $productId;
            $quantity = max(0, (int) $quantity);

            if (
                $productId <= 0 ||
                !isset($_SESSION['cart'][$productId])
            ) {
                continue;
            }

            if ($quantity <= 0) {
                unset($_SESSION['cart'][$productId]);
                continue;
            }

            $stockStatement = $pdo->prepare(
                "SELECT stock_quantity
                 FROM products
                 WHERE product_id = :product_id
                 LIMIT 1"
            );

            $stockStatement->execute(array(
                'product_id' => $productId
            ));

            $stockQuantity =
                (int) $stockStatement->fetchColumn();

            if ($stockQuantity <= 0) {
                unset($_SESSION['cart'][$productId]);
                continue;
            }

            $_SESSION['cart'][$productId] = min(
                $quantity,
                $stockQuantity
            );
        }

        cart_flash(
            'success',
            'Your cart was updated.'
        );

        cart_redirect('cart.php');
    }

    if ($action === 'remove_item') {
        $productId = isset($_POST['product_id'])
            ? (int) $_POST['product_id']
            : 0;

        if (
            $productId > 0 &&
            isset($_SESSION['cart'][$productId])
        ) {
            unset($_SESSION['cart'][$productId]);

            cart_flash(
                'success',
                'The product was removed from your cart.'
            );
        }

        cart_redirect('cart.php');
    }

    if ($action === 'clear_cart') {
        $_SESSION['cart'] = array();

        cart_flash(
            'success',
            'Your cart is now empty.'
        );

        cart_redirect('cart.php');
    }
}

/*
|--------------------------------------------------------------------------
| Load Cart Products
|--------------------------------------------------------------------------
*/

$cartItems = array();

/*
|--------------------------------------------------------------------------
| Cart counters
|--------------------------------------------------------------------------
| $cartCount     = total units in the cart.
| $productCount  = number of different product lines in the cart.
|--------------------------------------------------------------------------
*/
$cartCount = 0;
$productCount = 0;
$subtotal = 0;

$productIds = array();

foreach ($_SESSION['cart'] as $productId => $quantity) {
    $productId = (int) $productId;
    $quantity = max(0, (int) $quantity);

    if ($productId > 0 && $quantity > 0) {
        $productIds[] = $productId;
    }
}

if (!empty($productIds)) {
    $placeholders = array();

    foreach ($productIds as $index => $productId) {
        $placeholders[] = ':product_' . $index;
    }

    $cartSql =
        "SELECT
            p.product_id,
            p.product_name,
            p.price,
            p.unit,
            p.stock_quantity,
            p.vendor_id,
            v.vendor_name,
            c.category_name,
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
         WHERE p.product_id IN (" .
            implode(', ', $placeholders) .
         ")
           AND v.status = 'accepted'
           AND vu.status = 'active'
         ORDER BY p.product_id DESC";

    $cartStatement = $pdo->prepare($cartSql);

    foreach ($productIds as $index => $productId) {
        $cartStatement->bindValue(
            ':product_' . $index,
            $productId,
            PDO::PARAM_INT
        );
    }

    $cartStatement->execute();

    foreach ($cartStatement->fetchAll() as $product) {
        $productId = (int) $product['product_id'];

        if (!isset($_SESSION['cart'][$productId])) {
            continue;
        }

        $quantity = min(
            max(1, (int) $_SESSION['cart'][$productId]),
            max(0, (int) $product['stock_quantity'])
        );

        if ($quantity <= 0) {
            unset($_SESSION['cart'][$productId]);
            continue;
        }

        $_SESSION['cart'][$productId] = $quantity;

        $lineTotal =
            (float) $product['price'] * $quantity;

        $product['quantity'] = $quantity;
        $product['line_total'] = $lineTotal;

        $cartItems[] = $product;

        $productCount++;
        $cartCount += $quantity;
        $subtotal += $lineTotal;
    }
}

/*
|--------------------------------------------------------------------------
| Group Cart Items by Vendor
|--------------------------------------------------------------------------
| The cart remains one customer cart, but products are displayed by vendor.
| checkout.php can later create one vendor_order for each group.
|--------------------------------------------------------------------------
*/

$vendorGroups = array();

foreach ($cartItems as $item) {
    $vendorId = (int) $item['vendor_id'];

    if (!isset($vendorGroups[$vendorId])) {
        $vendorGroups[$vendorId] = array(
            'vendor_id' => $vendorId,
            'vendor_name' => (string) $item['vendor_name'],
            'items' => array(),
            'product_count' => 0,
            'unit_count' => 0,
            'subtotal' => 0
        );
    }

    $vendorGroups[$vendorId]['items'][] = $item;

    $vendorGroups[$vendorId]['product_count']++;
    $vendorGroups[$vendorId]['unit_count'] +=
        (int) $item['quantity'];

    $vendorGroups[$vendorId]['subtotal'] +=
        (float) $item['line_total'];
}

$vendorCount = count($vendorGroups);

$flash = isset($_SESSION['cart_flash'])
    ? $_SESSION['cart_flash']
    : null;

unset($_SESSION['cart_flash']);

$csrfToken = cart_csrf_token();

$pageTitle = 'Shopping Cart';

require __DIR__ . '/header.php';
?>

<main class="min-h-screen bg-[#f7f8f3]">

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <?php if ($flash): ?>

            <div class="mb-5 rounded-2xl border px-4 py-3 text-sm font-bold
                <?php echo $flash['type'] === 'success'
                    ? 'border-green-200 bg-green-50 text-green-800'
                    : 'border-red-200 bg-red-50 text-red-800'; ?>">

                <?php echo cart_e($flash['message']); ?>
            </div>

        <?php endif; ?>

        <?php if (empty($cartItems)): ?>

            <section class="rounded-2xl border border-slate-200 bg-white px-6 py-20 text-center shadow-sm">

                <span class="mx-auto grid h-20 w-20 place-items-center rounded-full bg-green-50 text-green-300">

                    <i class="fa-solid fa-cart-shopping text-3xl"></i>
                </span>

                <h2 class="mt-5 text-xl font-black text-slate-900">
                    Your cart is empty
                </h2>

                <p class="mx-auto mt-2 max-w-md text-sm leading-7 text-slate-400">
                    Add fresh products from accepted local vendors to begin your order.
                </p>

                <a href="products.php"
                   class="mt-6 inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-6 text-sm font-black text-white hover:bg-green-700">

                    <i class="fa-solid fa-basket-shopping"></i>

                    Browse Products
                </a>
            </section>

        <?php else: ?>

            <div class="grid gap-7 lg:grid-cols-[1fr_340px]">

                <!-- Cart Items -->
                <section>

                    <form id="update-cart-form"
                          method="post"
                          action="cart.php">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?php echo cart_e($csrfToken); ?>">

                        <input type="hidden"
                               name="action"
                               value="update_cart">

                        <div class="space-y-5">

                            <?php foreach ($vendorGroups as $vendorGroup): ?>

                                <section class="overflow-hidden rounded-[1.5rem] border border-slate-200 bg-white shadow-sm">

                                    <!-- Vendor Header -->
                                    <div class="flex flex-col gap-3 border-b border-slate-100 bg-gradient-to-r from-green-50 to-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                                        <div class="flex items-center gap-3">

                                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-green-600 text-white">

                                                <i class="fa-solid fa-store text-sm"></i>
                                            </span>

                                            <div>

                                                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-green-600">
                                                    Vendor
                                                </p>

                                                <h2 class="mt-0.5 text-base font-black text-slate-900">

                                                    <?php echo cart_e(
                                                        $vendorGroup['vendor_name']
                                                    ); ?>
                                                </h2>
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-3">

                                            <span class="rounded-full bg-white px-3 py-1.5 text-[10px] font-black text-slate-500 shadow-sm">

                                                <?php echo number_format(
                                                    (int) $vendorGroup['product_count']
                                                ); ?>
                                                product<?php echo (int) $vendorGroup['product_count'] === 1 ? '' : 's'; ?>
                                                &bull;
                                                <?php echo number_format(
                                                    (int) $vendorGroup['unit_count']
                                                ); ?>
                                                unit<?php echo (int) $vendorGroup['unit_count'] === 1 ? '' : 's'; ?>
                                            </span>

                                            <div class="text-right">

                                                <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">
                                                    Vendor Subtotal
                                                </p>

                                                <p class="mt-0.5 text-sm font-black text-green-700">

                                                    <?php echo number_format(
                                                        (float) $vendorGroup['subtotal']
                                                    ); ?>
                                                    MMK
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Vendor Products -->
                                    <div class="divide-y divide-slate-100">

                                        <?php foreach ($vendorGroup['items'] as $item): ?>
                                            <?php
                                            $photoUrl = cart_photo_url(
                                                $item['primary_photo']
                                            );
                                            ?>

                                            <article class="p-4 sm:p-5">

                                                <div class="flex flex-col gap-5 sm:flex-row">

                                                    <a href="products.php?view=<?php echo (int) $item['product_id']; ?>"
                                                       class="h-32 w-full shrink-0 overflow-hidden rounded-xl bg-slate-100 sm:w-36">

                                                        <?php if ($photoUrl !== ''): ?>

                                                            <img src="<?php echo cart_e($photoUrl); ?>"
                                                                 alt="<?php echo cart_e($item['product_name']); ?>"
                                                                 class="h-full w-full object-cover">

                                                        <?php else: ?>

                                                            <span class="grid h-full place-items-center bg-green-50 text-green-300">

                                                                <i class="fa-solid fa-image text-3xl"></i>
                                                            </span>

                                                        <?php endif; ?>
                                                    </a>

                                                    <div class="min-w-0 flex-1">

                                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

                                                            <div>

                                                                <p class="text-[10px] font-black uppercase tracking-wider text-green-600">

                                                                    <?php echo cart_e(
                                                                        $item['market_name']
                                                                    ); ?>
                                                                </p>

                                                                <a href="products.php?view=<?php echo (int) $item['product_id']; ?>"
                                                                   class="mt-1 block text-base font-black text-slate-900 hover:text-green-700">

                                                                    <?php echo cart_e(
                                                                        $item['product_name']
                                                                    ); ?>
                                                                </a>

                                                                <p class="mt-1 text-xs text-slate-400">

                                                                    <?php echo cart_e(
                                                                        $item['category_name']
                                                                    ); ?>
                                                                </p>
                                                            </div>

                                                            <p class="text-lg font-black text-green-700">

                                                                <?php echo number_format(
                                                                    (float) $item['line_total']
                                                                ); ?>
                                                                MMK
                                                            </p>
                                                        </div>

                                                        <div class="mt-5 flex flex-wrap items-end justify-between gap-4">

                                                            <div>

                                                                <label class="mb-2 block text-[10px] font-black uppercase tracking-wider text-slate-400">
                                                                    Quantity
                                                                </label>

                                                                <input type="number"
                                                                       name="quantity[<?php echo (int) $item['product_id']; ?>]"
                                                                       min="0"
                                                                       max="<?php echo (int) $item['stock_quantity']; ?>"
                                                                       value="<?php echo (int) $item['quantity']; ?>"
                                                                       class="h-10 w-24 rounded-xl border border-slate-200 px-3 text-sm font-bold outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">

                                                                <p class="mt-1 text-[10px] text-slate-400">

                                                                    Stock:
                                                                    <?php echo number_format(
                                                                        (int) $item['stock_quantity']
                                                                    ); ?>
                                                                </p>
                                                            </div>

                                                            <div class="flex items-end gap-4">

                                                                <div class="text-right">

                                                                    <p class="text-[10px] text-slate-400">
                                                                        Unit price
                                                                    </p>

                                                                    <p class="mt-1 text-sm font-black text-slate-800">

                                                                        <?php echo number_format(
                                                                            (float) $item['price']
                                                                        ); ?>
                                                                        MMK / <?php echo cart_e(fm_unit_label($item['unit'])); ?>
                                                                    </p>
                                                                </div>

                                                                <button type="submit"
                                                                        form="remove-<?php echo (int) $item['product_id']; ?>"
                                                                        title="Remove product"
                                                                        class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-red-50 text-red-500 transition hover:bg-red-100">

                                                                    <i class="fa-solid fa-trash text-xs"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </article>

                                        <?php endforeach; ?>
                                    </div>
                                </section>

                            <?php endforeach; ?>
                        </div>

                    </form>

                    <?php foreach ($cartItems as $item): ?>

                        <form id="remove-<?php echo (int) $item['product_id']; ?>"
                              method="post"
                              action="cart.php">

                            <input type="hidden"
                                   name="csrf_token"
                                   value="<?php echo cart_e($csrfToken); ?>">

                            <input type="hidden"
                                   name="action"
                                   value="remove_item">

                            <input type="hidden"
                                   name="product_id"
                                   value="<?php echo (int) $item['product_id']; ?>">
                        </form>

                    <?php endforeach; ?>

                    <!-- Cart Action Buttons -->
                    <div class="mt-5 flex flex-wrap justify-end gap-3">

                        <button type="submit"
                                form="update-cart-form"
                                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-green-200 bg-white px-5 text-sm font-black text-green-700 transition hover:border-green-300 hover:bg-green-50">

                            <i class="fa-solid fa-rotate text-xs"></i>
                            Update Cart
                        </button>

                        <form method="post"
                              action="cart.php">

                            <input type="hidden"
                                   name="csrf_token"
                                   value="<?php echo cart_e($csrfToken); ?>">

                            <input type="hidden"
                                   name="action"
                                   value="clear_cart">

                            <button type="submit"
                                    onclick="return confirm('Clear all products from your cart?');"
                                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-red-200 bg-white px-5 text-sm font-black text-red-600 transition hover:border-red-300 hover:bg-red-50">

                                <i class="fa-solid fa-trash-can text-xs"></i>
                                Clear Cart
                            </button>
                        </form>
                    </div>
                </section>

                <!-- Summary -->
                <aside>

                    <div class="sticky top-24 rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">

                        <p class="text-xs font-black uppercase tracking-[0.18em] text-green-600">
                            Order Summary
                        </p>

                        <h2 class="mt-1 text-xl font-black text-slate-900">
                            Your Cart
                        </h2>

                        <div class="mt-5 space-y-3 border-y border-slate-100 py-5">

                            <div class="flex items-center justify-between text-sm">

                                <span class="text-slate-500">
                                    Vendors
                                </span>

                                <span class="font-black text-slate-800">

                                    <?php echo number_format($vendorCount); ?>
                                </span>
                            </div>

                            <div class="flex items-center justify-between text-sm">

                                <span class="text-slate-500">
                                    Products
                                </span>

                                <span class="font-black text-slate-800">

                                    <?php echo number_format($productCount); ?>
                                </span>
                            </div>

                            <div class="flex items-center justify-between text-sm">

                                <span class="text-slate-500">
                                    Total Units
                                </span>

                                <span class="font-black text-slate-800">

                                    <?php echo number_format($cartCount); ?>
                                </span>
                            </div>

                            <?php foreach ($vendorGroups as $vendorGroup): ?>

                                <div class="flex items-start justify-between gap-3 text-xs">

                                    <span class="min-w-0 flex-1 truncate text-slate-400">

                                        <?php echo cart_e(
                                            $vendorGroup['vendor_name']
                                        ); ?>
                                    </span>

                                    <span class="shrink-0 font-bold text-slate-600">

                                        <?php echo number_format(
                                            (float) $vendorGroup['subtotal']
                                        ); ?>
                                        MMK
                                    </span>
                                </div>

                            <?php endforeach; ?>

                            <div class="flex items-center justify-between border-t border-slate-100 pt-3 text-sm">

                                <span class="font-bold text-slate-500">
                                    Subtotal
                                </span>

                                <span class="font-black text-slate-800">

                                    <?php echo number_format($subtotal); ?>
                                    MMK
                                </span>
                            </div>
                        </div>

                        <div class="mt-5 flex items-end justify-between gap-3">

                            <span class="text-sm font-bold text-slate-500">
                                Total
                            </span>

                            <span class="text-2xl font-black text-green-700">

                                <?php echo number_format($subtotal); ?>
                                MMK
                            </span>
                        </div>

                        <a href="checkout.php"
                           class="mt-5 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-black text-white shadow-sm transition hover:bg-green-700">

                            Proceed to Checkout

                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>

                        <div class="mt-4 rounded-xl bg-green-50 p-3">

                            <div class="flex gap-2">

                                <i class="fa-solid fa-circle-info mt-0.5 text-xs text-green-600"></i>

                                <p class="text-[10px] leading-5 text-green-800">
                                    You can buy from multiple vendors in one cart.
                                    At checkout, the purchase will be separated into
                                    vendor-specific orders.
                                </p>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>

        <?php endif; ?>
    </div>
</main>

<?php
if (file_exists(__DIR__ . '/footer.php')) {
    require __DIR__ . '/footer.php';
}
?>