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
| Customer Checkout
|--------------------------------------------------------------------------
| Save as:
| C:\xampp\htdocs\farmer_marketplace\checkout.php
|
| Uses the supplied database tables:
| - purchase_process
| - vendor_orders
| - purchase_details
| - products
| - vendors
| - users
|
| PHP 7.1 compatible.
|--------------------------------------------------------------------------
*/

function checkout_e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function checkout_redirect($location)
{
    header('Location: ' . $location);
    exit;
}

function checkout_photo_url($path)
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

function checkout_csrf_token()
{
    if (
        !isset($_SESSION['checkout_csrf_token']) ||
        !is_string($_SESSION['checkout_csrf_token']) ||
        $_SESSION['checkout_csrf_token'] === ''
    ) {
        $_SESSION['checkout_csrf_token'] =
            bin2hex(random_bytes(24));
    }

    return $_SESSION['checkout_csrf_token'];
}

function checkout_verify_csrf($token)
{
    return isset($_SESSION['checkout_csrf_token']) &&
        is_string($_SESSION['checkout_csrf_token']) &&
        hash_equals(
            $_SESSION['checkout_csrf_token'],
            (string) $token
        );
}

function checkout_cart_items()
{
    if (
        !isset($_SESSION['cart']) ||
        !is_array($_SESSION['cart'])
    ) {
        return array();
    }

    $cart = $_SESSION['cart'];

    if (
        isset($cart['items']) &&
        is_array($cart['items'])
    ) {
        $cart = $cart['items'];
    }

    $items = array();

    foreach ($cart as $productId => $quantity) {
        if (
            is_numeric($productId) &&
            is_numeric($quantity)
        ) {
            $productId = (int) $productId;
            $quantity = (int) $quantity;

            if ($productId > 0 && $quantity > 0) {
                $items[$productId] = $quantity;
            }
        }
    }

    return $items;
}

function checkout_payment_label($method)
{
    $method = strtolower(trim((string) $method));

    if ($method === 'kbzpay') {
        return 'KBZPay';
    }

    if ($method === 'wave_money') {
        return 'Wave Money';
    }

    return 'Not selected yet';
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
| Customer Information
|--------------------------------------------------------------------------
*/

$userStatement = $pdo->prepare(
    "SELECT
        user_id,
        user_name,
        phone_number,
        email,
        status
     FROM users
     WHERE user_id = :user_id
       AND role = 'user'
     LIMIT 1"
);

$userStatement->execute(array(
    'user_id' => $userId
));

$customer = $userStatement->fetch();

if (!$customer || $customer['status'] !== 'active') {
    session_unset();
    session_destroy();

    checkout_redirect(
        'signin.php?notice=login_required'
    );
}

/*
|--------------------------------------------------------------------------
| Success Order
|--------------------------------------------------------------------------
*/

$successPurchase = null;
$successDetails = array();

$successId = isset($_GET['success'])
    ? max(0, (int) $_GET['success'])
    : 0;

if ($successId > 0) {
    $successStatement = $pdo->prepare(
        "SELECT
            purchase_id,
            total_amount,
            payment_method,
            payment_status,
            fulfillment_type,
            delivery_name,
            delivery_phone,
            delivery_address,
            customer_note,
            order_status,
            created_at
         FROM purchase_process
         WHERE purchase_id = :purchase_id
           AND user_id = :user_id
         LIMIT 1"
    );

    $successStatement->execute(array(
        'purchase_id' => $successId,
        'user_id' => $userId
    ));

    $successPurchase = $successStatement->fetch();

    if ($successPurchase) {
        $detailStatement = $pdo->prepare(
            "SELECT
                pd.vendor_order_id,
                pd.quantity,
                pd.unit_price,
                pd.subtotal,
                p.product_name,
                p.unit,
                vo.vendor_id,
                vo.order_status AS vendor_order_status,
                vo.subtotal AS vendor_subtotal,
                v.vendor_name
             FROM purchase_details pd
             LEFT JOIN products p
                ON p.product_id = pd.product_id
             LEFT JOIN vendor_orders vo
                ON vo.vendor_order_id = pd.vendor_order_id
             LEFT JOIN vendors v
                ON v.vendor_id = vo.vendor_id
             WHERE pd.purchase_id = :purchase_id
             ORDER BY
                vo.vendor_order_id ASC,
                pd.purchase_detail_id ASC"
        );

        $detailStatement->execute(array(
            'purchase_id' => $successId
        ));

        $successDetails = $detailStatement->fetchAll();
    }
}

$successVendorGroups = array();

foreach ($successDetails as $detail) {
    $vendorOrderId = isset($detail['vendor_order_id'])
        ? (int) $detail['vendor_order_id']
        : 0;

    if (!isset($successVendorGroups[$vendorOrderId])) {
        $successVendorGroups[$vendorOrderId] = array(
            'vendor_order_id' => $vendorOrderId,
            'vendor_name' => isset($detail['vendor_name'])
                ? (string) $detail['vendor_name']
                : 'Vendor',
            'status' => isset($detail['vendor_order_status'])
                ? (string) $detail['vendor_order_status']
                : 'pending',
            'subtotal' => isset($detail['vendor_subtotal'])
                ? (float) $detail['vendor_subtotal']
                : 0,
            'items' => array()
        );
    }

    $successVendorGroups[$vendorOrderId]['items'][] = $detail;
}

/*
|--------------------------------------------------------------------------
| Success Payment Readiness
|--------------------------------------------------------------------------
| Payment is allowed only when:
| - no Vendor Order is still Pending
| - at least one Vendor Order is Confirmed/Completed
|--------------------------------------------------------------------------
*/

$successPendingCount = 0;
$successAcceptedCount = 0;
$successRejectedCount = 0;
$successFinalAmount = 0;

foreach ($successVendorGroups as $vendorGroup) {
    $vendorStatus = strtolower(
        (string) $vendorGroup['status']
    );

    if ($vendorStatus === 'pending') {
        $successPendingCount++;
    } elseif ($vendorStatus === 'rejected') {
        $successRejectedCount++;
    } elseif (
        $vendorStatus === 'confirmed' ||
        $vendorStatus === 'completed'
    ) {
        $successAcceptedCount++;

        $successFinalAmount +=
            (float) $vendorGroup['subtotal'];
    }
}

$successPaymentReady =
    count($successVendorGroups) > 0 &&
    $successPendingCount === 0 &&
    $successAcceptedCount > 0;

/*
|--------------------------------------------------------------------------
| Load Current Cart
|--------------------------------------------------------------------------
*/

$cart = checkout_cart_items();
$cartProducts = array();
$cartCount = 0;
$subtotal = 0;
$cartError = '';

if (!empty($cart)) {
    $productIds = array_keys($cart);
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
           AND vu.status = 'active'";

    $cartStatement = $pdo->prepare($cartSql);

    foreach ($productIds as $index => $productId) {
        $cartStatement->bindValue(
            ':product_' . $index,
            (int) $productId,
            PDO::PARAM_INT
        );
    }

    $cartStatement->execute();

    $foundProducts = array();

    foreach ($cartStatement->fetchAll() as $product) {
        $productId = (int) $product['product_id'];
        $foundProducts[$productId] = $product;
    }

    foreach ($cart as $productId => $quantity) {
        if (!isset($foundProducts[$productId])) {
            $cartError =
                'One or more products are no longer available. ' .
                'Please return to your cart and review the items.';
            continue;
        }

        $product = $foundProducts[$productId];
        $stockQuantity =
            (int) $product['stock_quantity'];

        if ($quantity > $stockQuantity) {
            $cartError =
                'The quantity of ' .
                $product['product_name'] .
                ' is greater than the available stock.';
        }

        $lineTotal =
            (float) $product['price'] * $quantity;

        $product['quantity'] = $quantity;
        $product['line_total'] = $lineTotal;

        $cartProducts[] = $product;

        $cartCount += $quantity;
        $subtotal += $lineTotal;
    }
}
$checkoutVendorGroups = array();

foreach ($cartProducts as $product) {
    $vendorId = (int) $product['vendor_id'];

    if (!isset($checkoutVendorGroups[$vendorId])) {
        $checkoutVendorGroups[$vendorId] = array(
            'vendor_id' => $vendorId,
            'vendor_name' => (string) $product['vendor_name'],
            'items' => array(),
            'item_count' => 0,
            'subtotal' => 0
        );
    }

    $checkoutVendorGroups[$vendorId]['items'][] = $product;
    $checkoutVendorGroups[$vendorId]['item_count'] +=
        (int) $product['quantity'];
    $checkoutVendorGroups[$vendorId]['subtotal'] +=
        (float) $product['line_total'];
}

$checkoutVendorCount = count($checkoutVendorGroups);

/*
|--------------------------------------------------------------------------
| Place Order
|--------------------------------------------------------------------------
*/

$formError = '';
$fulfillmentType = isset($_POST['fulfillment_type'])
    ? strtolower(trim((string) $_POST['fulfillment_type']))
    : 'pickup';
$deliveryName = isset($_POST['delivery_name'])
    ? trim((string) $_POST['delivery_name'])
    : (string) $customer['user_name'];
$deliveryPhone = isset($_POST['delivery_phone'])
    ? trim((string) $_POST['delivery_phone'])
    : (string) $customer['phone_number'];
$deliveryAddress = isset($_POST['delivery_address'])
    ? trim((string) $_POST['delivery_address'])
    : '';
$customerNote = isset($_POST['customer_note'])
    ? trim((string) $_POST['customer_note'])
    : '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'place_order'
) {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!checkout_verify_csrf($csrfToken)) {
        $formError =
            'Your checkout session expired. Please refresh and try again.';
    } elseif (empty($cart)) {
        $formError =
            'Your cart is empty.';
    } elseif ($cartError !== '') {
        $formError = $cartError;
    } elseif (!in_array($fulfillmentType, array('pickup', 'delivery'), true)) {
        $formError = 'Choose Market Pickup or Delivery.';
    } elseif (strlen($customerNote) > 255) {
        $formError = 'Order note must not exceed 255 characters.';
    } elseif (
        $fulfillmentType === 'delivery' &&
        ($deliveryName === '' || $deliveryPhone === '' || $deliveryAddress === '')
    ) {
        $formError = 'Name, phone number and delivery address are required for Delivery.';
    } elseif (strlen($deliveryName) > 100 || strlen($deliveryPhone) > 30 || strlen($deliveryAddress) > 255) {
        $formError = 'Delivery contact information is too long.';
    } else {
        try {
            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------
            | Re-check every product inside the transaction.
            |--------------------------------------------------------------
            */

            $orderItems = array();
            $orderTotal = 0;

            foreach ($cart as $productId => $quantity) {
                $productStatement = $pdo->prepare(
                    "SELECT
                        p.product_id,
                        p.product_name,
                        p.price,
                        p.unit,
                        p.stock_quantity,
                        p.vendor_id,
                        v.vendor_name
                     FROM products p
                     INNER JOIN vendors v
                        ON v.vendor_id = p.vendor_id
                     INNER JOIN users vu
                        ON vu.user_id = v.user_id
                     WHERE p.product_id = :product_id
                       AND v.status = 'accepted'
                       AND vu.status = 'active'
                     LIMIT 1
                     FOR UPDATE"
                );

                $productStatement->execute(array(
                    'product_id' => $productId
                ));

                $product = $productStatement->fetch();

                if (!$product) {
                    throw new Exception(
                        'A product in your cart is no longer available.'
                    );
                }

                $availableStock =
                    (int) $product['stock_quantity'];

                if ($availableStock < $quantity) {
                    throw new Exception(
                        $product['product_name'] .
                        ' has only ' .
                        $availableStock .
                        ' unit(s) left.'
                    );
                }

                $unitPrice =
                    (float) $product['price'];

                $lineSubtotal =
                    $unitPrice * $quantity;

                $orderItems[] = array(
                    'product_id' =>
                        (int) $product['product_id'],
                    'vendor_id' =>
                        (int) $product['vendor_id'],
                    'vendor_name' =>
                        (string) $product['vendor_name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit' => (string) $product['unit'],
                    'subtotal' => $lineSubtotal
                );

                $orderTotal += $lineSubtotal;
            }

            /*
            |--------------------------------------------------------------
            | Create purchase_process record.
            |--------------------------------------------------------------
            */

            $purchaseStatement = $pdo->prepare(
                "INSERT INTO purchase_process
                    (
                        user_id,
                        total_amount,
                        payment_method,
                        payment_status,
                        fulfillment_type,
                        delivery_name,
                        delivery_phone,
                        delivery_address,
                        customer_note,
                        order_status
                    )
                 VALUES
                    (
                        :user_id,
                        :total_amount,
                        NULL,
                        'unpaid',
                        :fulfillment_type,
                        :delivery_name,
                        :delivery_phone,
                        :delivery_address,
                        :customer_note,
                        'pending'
                    )"
            );

            $purchaseStatement->execute(array(
                'user_id' => $userId,
                'total_amount' => $orderTotal,
                'fulfillment_type' => $fulfillmentType,
                'delivery_name' => $fulfillmentType === 'delivery' ? $deliveryName : null,
                'delivery_phone' => $fulfillmentType === 'delivery' ? $deliveryPhone : null,
                'delivery_address' => $fulfillmentType === 'delivery' ? $deliveryAddress : null,
                'customer_note' => $customerNote !== '' ? $customerNote : null
            ));

            $purchaseId =
                (int) $pdo->lastInsertId();

            /*
            |--------------------------------------------------------------
            | Group items by Vendor.
            |--------------------------------------------------------------
            */

            $vendorOrderGroups = array();

            foreach ($orderItems as $item) {
                $vendorId = (int) $item['vendor_id'];

                if (!isset($vendorOrderGroups[$vendorId])) {
                    $vendorOrderGroups[$vendorId] = array(
                        'vendor_id' => $vendorId,
                        'vendor_name' => $item['vendor_name'],
                        'subtotal' => 0,
                        'items' => array()
                    );
                }

                $vendorOrderGroups[$vendorId]['items'][] = $item;
                $vendorOrderGroups[$vendorId]['subtotal'] +=
                    (float) $item['subtotal'];
            }

            /*
            |--------------------------------------------------------------
            | Create one Vendor Order for each Vendor.
            |--------------------------------------------------------------
            */

            $vendorOrderStatement = $pdo->prepare(
                "INSERT INTO vendor_orders
                    (
                        purchase_id,
                        vendor_id,
                        subtotal,
                        order_status
                    )
                 VALUES
                    (
                        :purchase_id,
                        :vendor_id,
                        :subtotal,
                        'pending'
                    )"
            );

            $detailInsertStatement = $pdo->prepare(
                "INSERT INTO purchase_details
                    (
                        purchase_id,
                        vendor_order_id,
                        product_id,
                        quantity,
                        unit_price,
                        subtotal
                    )
                 VALUES
                    (
                        :purchase_id,
                        :vendor_order_id,
                        :product_id,
                        :quantity,
                        :unit_price,
                        :subtotal
                    )"
            );

            $stockUpdateStatement = $pdo->prepare(
                "UPDATE products
                 SET stock_quantity =
                    stock_quantity - :deduct_quantity
                 WHERE product_id = :product_id
                   AND stock_quantity >= :required_quantity"
            );

            foreach ($vendorOrderGroups as $vendorGroup) {
                $vendorOrderStatement->execute(array(
                    'purchase_id' => $purchaseId,
                    'vendor_id' =>
                        $vendorGroup['vendor_id'],
                    'subtotal' =>
                        $vendorGroup['subtotal']
                ));

                $vendorOrderId =
                    (int) $pdo->lastInsertId();

                foreach ($vendorGroup['items'] as $item) {
                    $detailInsertStatement->execute(array(
                        'purchase_id' => $purchaseId,
                        'vendor_order_id' =>
                            $vendorOrderId,
                        'product_id' =>
                            $item['product_id'],
                        'quantity' =>
                            $item['quantity'],
                        'unit_price' =>
                            $item['unit_price'],
                        'subtotal' =>
                            $item['subtotal']
                    ));

                    $stockUpdateStatement->execute(array(
                        'deduct_quantity' =>
                            $item['quantity'],
                        'product_id' =>
                            $item['product_id'],
                        'required_quantity' =>
                            $item['quantity']
                    ));

                    if (
                        $stockUpdateStatement->rowCount() !== 1
                    ) {
                        throw new Exception(
                            'Stock changed while placing your order. ' .
                            'Please try again.'
                        );
                    }
                }
            }

            $pdo->commit();

            /*
            |--------------------------------------------------------------
            | Clear cart only after successful transaction.
            |--------------------------------------------------------------
            */

            $_SESSION['cart'] = array();

            checkout_redirect(
                'checkout.php?success=' . $purchaseId
            );
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Checkout database error: ' . $exception->getMessage());
            $formError = 'The database could not place your order. Please try again.';
        } catch (Exception $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $formError = $exception->getMessage();
        }
    }
}

$csrfToken = checkout_csrf_token();

$pageTitle = 'Checkout';

require __DIR__ . '/header.php';
?>

<style>
    /*
    |--------------------------------------------------------------------------
    | Checkout Responsive Layout
    |--------------------------------------------------------------------------
    | Desktop:
    |   Left column  : Contact Information + Order Products
    |   Right column : Pickup / Delivery + Order Summary
    |
    | This avoids the large empty space that appears when Delivery fields
    | make the Fulfillment card taller than the Contact Information card.
    |
    | Mobile / tablet:
    |   Contact -> Fulfillment -> Products -> Summary
    |--------------------------------------------------------------------------
    */

    .checkout-layout {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    /*
     * On smaller screens the column wrappers disappear from layout,
     * allowing the four cards to follow the simple mobile reading order.
     */
    .checkout-column {
        display: contents;
    }

    .checkout-contact {
        order: 1;
    }

    .checkout-fulfillment {
        order: 2;
    }

    .checkout-products {
        order: 3;
    }

    .checkout-summary {
        order: 4;
    }

    .checkout-card {
        min-width: 0;
    }

    @media (min-width: 1024px) {
        .checkout-layout {
            display: grid;
            grid-template-columns:
                minmax(0, 1.35fr)
                minmax(400px, 0.85fr);
            gap: 1.5rem;
            align-items: start;
        }

        .checkout-column {
            display: flex;
            min-width: 0;
            flex-direction: column;
            gap: 1.5rem;
        }

        .checkout-summary-sticky {
            position: sticky;
            top: 6rem;
        }
    }
</style>


<main class="min-h-screen bg-[#f7f8f3]">

<?php if ($successPurchase): ?>

    <!-- Success -->
    <section class="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6 lg:px-8">

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_4px_18px_rgba(15,23,42,0.06)]">

            <!-- Compact Success Header -->
            <div class="border-b border-slate-100 bg-green-50/70 px-5 py-5 sm:px-7">

                <div class="flex items-center gap-4">

                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-green-600 text-base text-white shadow-sm">
                        <i class="fa-solid fa-check"></i>
                    </span>

                    <div class="min-w-0">
                        <h1 class="text-xl font-black text-slate-900 sm:text-2xl">
                            <?php echo $successPaymentReady
                                ? 'Order ready for payment'
                                : 'Order placed successfully'; ?>
                        </h1>

                        <p class="mt-1 text-xs font-semibold text-slate-400">
                            Order #<?php echo number_format(
                                (int) $successPurchase['purchase_id']
                            ); ?>
                        </p>

                    </div>
                </div>
            </div>

            <div class="p-5 sm:p-7">

                <?php
                $successOrderStatus = strtolower(
                    trim((string) $successPurchase['order_status'])
                );

                $successStatusCardClass =
                    'border-amber-200 bg-amber-50';

                $successStatusIconClass =
                    'bg-amber-100 text-amber-700';

                $successStatusTextClass =
                    'text-amber-900';

                $successStatusIcon =
                    'fa-clock';

                if ($successOrderStatus === 'confirmed') {
                    $successStatusCardClass =
                        'border-blue-200 bg-blue-50';

                    $successStatusIconClass =
                        'bg-blue-100 text-blue-700';

                    $successStatusTextClass =
                        'text-blue-900';

                    $successStatusIcon =
                        'fa-circle-check';
                } elseif ($successOrderStatus === 'completed') {
                    $successStatusCardClass =
                        'border-green-200 bg-green-50';

                    $successStatusIconClass =
                        'bg-green-100 text-green-700';

                    $successStatusTextClass =
                        'text-green-900';

                    $successStatusIcon =
                        'fa-circle-check';
                } elseif ($successOrderStatus === 'rejected') {
                    $successStatusCardClass =
                        'border-red-200 bg-red-50';

                    $successStatusIconClass =
                        'bg-red-100 text-red-700';

                    $successStatusTextClass =
                        'text-red-900';

                    $successStatusIcon =
                        'fa-circle-xmark';
                }
                ?>

                <!-- Order Status + Total -->
                <div class="grid gap-4 sm:grid-cols-2">

                    <div class="rounded-2xl border p-4 <?php echo $successStatusCardClass; ?>">

                        <div class="flex items-center gap-3">

                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl <?php echo $successStatusIconClass; ?>">
                                <i class="fa-solid <?php echo checkout_e($successStatusIcon); ?> text-xs"></i>
                            </span>

                            <div class="min-w-0">
                                <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">
                                    Order Status
                                </p>

                                <p class="mt-1 text-sm font-black capitalize <?php echo $successStatusTextClass; ?>">
                                    <?php echo checkout_e(
                                        $successPurchase['order_status']
                                    ); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-green-200 bg-green-50 p-4">

                        <div class="flex items-center gap-3">

                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-green-600 text-white">
                                <i class="fa-solid fa-coins text-xs"></i>
                            </span>

                            <div class="min-w-0">
                                <p class="text-[9px] font-black uppercase tracking-wider text-green-600">
                                    <?php echo $successPaymentReady
                                        ? 'Final Amount'
                                        : 'Estimated Total'; ?>
                                </p>

                                <p class="mt-1 text-lg font-black text-green-800">
                                    <?php echo number_format(
                                        $successPaymentReady
                                            ? $successFinalAmount
                                            : (float) $successPurchase['total_amount']
                                    ); ?>
                                    MMK
                                </p>
                            </div>
                        </div>

                        <?php if (
                            $successPaymentReady &&
                            $successRejectedCount > 0
                        ): ?>

                            <p class="mt-2 text-[9px] font-semibold text-green-700">
                                Rejected vendor amounts have been excluded.
                            </p>

                        <?php endif; ?>
                    </div>
                </div>

                <!-- Fulfillment -->
                <?php if ($successPurchase['fulfillment_type'] === 'delivery'): ?>

                    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-4">

                        <div class="flex items-center gap-3">

                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-green-100 text-green-700">
                                <i class="fa-solid fa-truck-fast text-xs"></i>
                            </span>

                            <div>
                                <p class="text-[9px] font-black uppercase tracking-wider text-green-600">
                                    Fulfillment
                                </p>

                                <p class="mt-1 text-sm font-black text-slate-900">
                                    Delivery
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">

                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">
                                    Recipient
                                </p>

                                <p class="mt-1 text-xs font-bold text-slate-700">
                                    <?php echo checkout_e(
                                        $successPurchase['delivery_name']
                                    ); ?>
                                </p>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">
                                    Phone
                                </p>

                                <p class="mt-1 text-xs font-bold text-slate-700">
                                    <?php echo checkout_e(
                                        $successPurchase['delivery_phone']
                                    ); ?>
                                </p>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-3 sm:col-span-2">
                                <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">
                                    Delivery Address
                                </p>

                                <p class="mt-1 text-xs leading-5 text-slate-600">
                                    <?php echo checkout_e(
                                        $successPurchase['delivery_address']
                                    ); ?>
                                </p>
                            </div>
                        </div>

                        <?php if (trim((string) $successPurchase['customer_note']) !== ''): ?>

                            <div class="mt-3 rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
                                <strong>Order Note:</strong>
                                <?php echo checkout_e(
                                    $successPurchase['customer_note']
                                ); ?>
                            </div>

                        <?php endif; ?>
                    </section>

                <?php else: ?>

                    <section class="mt-5 flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3.5">

                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-green-100 text-green-700">
                            <i class="fa-solid fa-store text-xs"></i>
                        </span>

                        <div>
                            <p class="text-[9px] font-black uppercase tracking-wider text-green-600">
                                Fulfillment
                            </p>

                            <p class="mt-0.5 text-sm font-black text-slate-900">
                                Market Pickup
                            </p>
                        </div>
                    </section>

                <?php endif; ?>

                <!-- Vendor Orders -->
                <section class="mt-5">

                    <div class="flex items-end justify-between gap-3">

                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-green-600">
                                Vendor Orders
                            </p>

                            <h2 class="mt-1 text-lg font-black text-slate-900">
                                Order breakdown
                            </h2>
                        </div>

                        <?php
                        $successVendorCount = count($successVendorGroups);
                        ?>

                        <span class="rounded-full bg-green-50 px-3 py-1 text-[10px] font-bold text-green-700">
                            <?php echo number_format($successVendorCount); ?>
                            <?php echo $successVendorCount === 1
                                ? 'Vendor'
                                : 'Vendors'; ?>
                        </span>
                    </div>

                    <div class="mt-4 space-y-3">

                        <?php foreach ($successVendorGroups as $vendorGroup): ?>

                            <?php
                            $vendorStatus = strtolower(
                                (string) $vendorGroup['status']
                            );

                            $vendorStatusClass =
                                'bg-amber-100 text-amber-700';

                            if ($vendorStatus === 'confirmed') {
                                $vendorStatusClass =
                                    'bg-blue-100 text-blue-700';
                            } elseif ($vendorStatus === 'completed') {
                                $vendorStatusClass =
                                    'bg-green-100 text-green-700';
                            } elseif ($vendorStatus === 'rejected') {
                                $vendorStatusClass =
                                    'bg-red-100 text-red-700';
                            }
                            ?>

                            <article class="overflow-hidden rounded-2xl border border-slate-200">

                                <div class="flex flex-col gap-3 border-b border-slate-100 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">

                                    <div class="flex items-center gap-3">

                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-green-600 text-white">
                                            <i class="fa-solid fa-store text-xs"></i>
                                        </span>

                                        <div class="min-w-0">

                                            <p class="text-[9px] font-black uppercase tracking-wider text-green-600">
                                                Vendor Order
                                                #<?php echo (int) $vendorGroup['vendor_order_id']; ?>
                                            </p>

                                            <p class="mt-0.5 truncate text-sm font-black text-slate-900">
                                                <?php echo checkout_e(
                                                    $vendorGroup['vendor_name']
                                                ); ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between gap-4 sm:justify-end">

                                        <span class="inline-flex rounded-full px-3 py-1 text-[10px] font-black capitalize <?php echo $vendorStatusClass; ?>">
                                            <?php echo checkout_e(
                                                $vendorGroup['status']
                                            ); ?>
                                        </span>

                                        <span class="text-sm font-black text-green-700">
                                            <?php echo number_format(
                                                (float) $vendorGroup['subtotal']
                                            ); ?>
                                            MMK
                                        </span>
                                    </div>
                                </div>

                                <div class="divide-y divide-slate-100">

                                    <?php foreach ($vendorGroup['items'] as $detail): ?>

                                        <div class="flex items-center justify-between gap-4 px-4 py-3">

                                            <div class="min-w-0">

                                                <p class="truncate text-sm font-bold text-slate-800">
                                                    <?php echo checkout_e(
                                                        $detail['product_name'] !== null
                                                            ? $detail['product_name']
                                                            : 'Product'
                                                    ); ?>
                                                </p>

                                                <p class="mt-1 text-[10px] text-slate-400">
                                                    <?php echo (int) $detail['quantity']; ?>
                                                    ×
                                                    <?php echo number_format(
                                                        (float) $detail['unit_price']
                                                    ); ?>
                                                    MMK /
                                                    <?php echo checkout_e(
                                                        fm_unit_label(
                                                            $detail['unit']
                                                        )
                                                    ); ?>
                                                </p>
                                            </div>

                                            <p class="shrink-0 text-xs font-black text-green-700 sm:text-sm">
                                                <?php echo number_format(
                                                    (float) $detail['subtotal']
                                                ); ?>
                                                MMK
                                            </p>
                                        </div>

                                    <?php endforeach; ?>
                                </div>
                            </article>

                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- One Status / Payment Message -->
                <?php if ($successPaymentReady): ?>

                    <!-- Payment-ready state is already clear from the
                         heading, final amount and Pay Now button. -->

                <?php elseif ($successPendingCount > 0): ?>

                    <div class="mt-5 flex gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-4">

                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-blue-600 text-white">
                            <i class="fa-solid fa-hourglass-half text-xs"></i>
                        </span>

                        <div>
                            <p class="text-sm font-black text-blue-900">
                                Waiting for vendor confirmation
                            </p>

                            <p class="mt-1 text-xs leading-5 text-blue-700">
                                <?php echo number_format($successPendingCount); ?>
                                vendor order<?php echo $successPendingCount === 1 ? '' : 's'; ?> <?php echo $successPendingCount === 1 ? 'is' : 'are'; ?> pending.
                                Payment will be available after all vendors respond.
                            </p>
                        </div>
                    </div>

                <?php else: ?>

                    <div class="mt-5 flex gap-3 rounded-2xl border border-red-200 bg-red-50 p-4">

                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-red-600 text-white">
                            <i class="fa-solid fa-circle-xmark text-xs"></i>
                        </span>

                        <div>
                            <p class="text-sm font-black text-red-900">
                                No payment is required
                            </p>

                            <p class="mt-1 text-xs leading-5 text-red-700">
                                All vendor orders were rejected.
                            </p>
                        </div>
                    </div>

                <?php endif; ?>

                <!-- Final Actions -->
                <div class="<?php echo $successPaymentReady ? 'mt-5' : 'mt-6'; ?> flex flex-col gap-3 sm:flex-row sm:justify-end">

                    <?php if ($successPaymentReady): ?>

                        <a href="pay_order.php?purchase_id=<?php echo (int) $successPurchase['purchase_id']; ?>"
                           class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-black text-white transition hover:bg-green-700">

                            <i class="fa-solid fa-wallet text-xs"></i>
                            Pay Now
                        </a>

                    <?php endif; ?>

                    <a href="my_orders.php"
                       class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-green-200 bg-white px-5 text-sm font-black text-green-700 transition hover:bg-green-50">

                        <i class="fa-solid fa-receipt text-xs"></i>
                        View My Orders
                    </a>

                    <a href="products.php"
                       class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 text-sm font-black text-slate-600 transition hover:bg-slate-50">

                        <i class="fa-solid fa-basket-shopping text-xs"></i>
                        Continue Shopping
                    </a>
                </div>
            </div>
        </div>
    </section>

<?php else: ?>

    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <?php if (empty($cartProducts)): ?>

            <section class="rounded-2xl border border-slate-200 bg-white px-6 py-20 text-center shadow-sm">

                <span class="mx-auto grid h-20 w-20 place-items-center rounded-full bg-green-50 text-green-300">

                    <i class="fa-solid fa-cart-shopping text-3xl"></i>
                </span>

                <h2 class="mt-5 text-xl font-black text-slate-900">
                    Your cart is empty
                </h2>

                <p class="mx-auto mt-2 max-w-md text-sm leading-7 text-slate-400">
                    Add products to your cart before continuing to checkout.
                </p>

                <a href="products.php"
                   class="mt-6 inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-6 text-sm font-black text-white hover:bg-green-700">

                    <i class="fa-solid fa-basket-shopping"></i>
                    Browse Products
                </a>
            </section>

        <?php else: ?>

            <?php if ($formError !== ''): ?>

                <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800">

                    <i class="fa-solid fa-circle-exclamation mr-2"></i>

                    <?php echo checkout_e($formError); ?>
                </div>

            <?php endif; ?>

            <?php if ($cartError !== '' && $formError === ''): ?>

                <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">

                    <i class="fa-solid fa-triangle-exclamation mr-2"></i>

                    <?php echo checkout_e($cartError); ?>
                </div>

            <?php endif; ?>

            <form method="post"
                  action="checkout.php"
                  class="space-y-6">

                <input type="hidden"
                       name="csrf_token"
                       value="<?php echo checkout_e($csrfToken); ?>">

                <input type="hidden"
                       name="action"
                       value="place_order">

                <!-- Responsive Independent Checkout Columns -->
                <div class="checkout-layout">

                    <!-- Left Column -->
                    <div class="checkout-column checkout-left-column">

                    <!-- Customer -->
                    <section class="checkout-card checkout-contact rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_10px_rgba(15,23,42,0.04)] sm:p-6">

                        <div class="flex items-center gap-3">

                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-green-100 text-green-600">

                                <i class="fa-solid fa-user"></i>
                            </span>

                            <div>

                                <p class="text-xs font-black uppercase tracking-[0.18em] text-green-600">
                                    Customer
                                </p>

                                <h2 class="mt-1 text-lg font-black text-slate-900">
                                    Contact Information
                                </h2>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-4 sm:grid-cols-2">

                            <div class="rounded-xl bg-slate-50 p-4">

                                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                                    Name
                                </p>

                                <p class="mt-2 text-sm font-bold text-slate-800">
                                    <?php echo checkout_e(
                                        $customer['user_name']
                                    ); ?>
                                </p>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-4">

                                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                                    Phone
                                </p>

                                <p class="mt-2 text-sm font-bold text-slate-800">
                                    <?php echo checkout_e(
                                        $customer['phone_number']
                                    ); ?>
                                </p>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-4 sm:col-span-2">

                                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                                    Email
                                </p>

                                <p class="mt-2 text-sm font-bold text-slate-800">
                                    <?php echo checkout_e(
                                        $customer['email']
                                    ); ?>
                                </p>
                            </div>
                        </div>

                    </section>

                    <!-- Products -->
                    <section class="checkout-card checkout-products rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_10px_rgba(15,23,42,0.04)] sm:p-6">

                        <div class="flex items-center justify-between">

                            <div>

                                <p class="text-xs font-black uppercase tracking-[0.18em] text-green-600">
                                    Items
                                </p>

                                <h2 class="mt-1 text-lg font-black text-slate-900">
                                    Order Products
                                </h2>
                            </div>

                            <span class="rounded-full bg-green-50 px-3 py-1.5 text-xs font-black text-green-700">

                                <?php echo number_format($cartCount); ?>
                                item(s)
                            </span>
                        </div>

                        <div class="mt-5 space-y-4">

                            <?php foreach ($checkoutVendorGroups as $vendorGroup): ?>

                                <section class="overflow-hidden rounded-2xl border border-slate-200">

                                    <div class="flex items-center justify-between gap-4 border-b border-slate-100 bg-green-50 px-4 py-3">

                                        <div class="flex items-center gap-3">

                                            <span class="grid h-9 w-9 place-items-center rounded-xl bg-green-600 text-white">

                                                <i class="fa-solid fa-store text-xs"></i>
                                            </span>

                                            <div>

                                                <p class="text-[9px] font-black uppercase tracking-wider text-green-600">
                                                    Vendor
                                                </p>

                                                <p class="text-sm font-black text-slate-900">

                                                    <?php echo checkout_e(
                                                        $vendorGroup['vendor_name']
                                                    ); ?>
                                                </p>
                                            </div>
                                        </div>

                                        <div class="text-right">

                                            <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">
                                                Subtotal
                                            </p>

                                            <p class="mt-1 text-sm font-black text-green-700">

                                                <?php echo number_format(
                                                    (float) $vendorGroup['subtotal']
                                                ); ?>
                                                MMK
                                            </p>
                                        </div>
                                    </div>

                                    <div class="divide-y divide-slate-100">

                                        <?php foreach ($vendorGroup['items'] as $product): ?>
                                            <?php
                                            $photoUrl = checkout_photo_url(
                                                $product['primary_photo']
                                            );
                                            ?>

                                            <div class="flex gap-4 p-4">

                                                <div class="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-slate-100">

                                                    <?php if ($photoUrl !== ''): ?>

                                                        <img src="<?php echo checkout_e($photoUrl); ?>"
                                                             alt="<?php echo checkout_e($product['product_name']); ?>"
                                                             class="h-full w-full object-cover">

                                                    <?php else: ?>

                                                        <span class="grid h-full place-items-center bg-green-50 text-green-300">

                                                            <i class="fa-solid fa-image text-xl"></i>
                                                        </span>

                                                    <?php endif; ?>
                                                </div>

                                                <div class="min-w-0 flex-1">

                                                    <p class="truncate text-sm font-black text-slate-900">

                                                        <?php echo checkout_e(
                                                            $product['product_name']
                                                        ); ?>
                                                    </p>

                                                    <p class="mt-1 truncate text-[10px] text-slate-400">

                                                        <?php echo checkout_e(
                                                            $product['market_name']
                                                        ); ?>
                                                    </p>

                                                    <div class="mt-3 flex items-end justify-between gap-3">

                                                        <p class="text-xs text-slate-500">
                                                            Qty:
                                                            <span class="font-black text-slate-800">

                                                                <?php echo (int) $product['quantity']; ?>
                                                            </span>
                                                        </p>

                                                        <p class="whitespace-nowrap text-xs font-extrabold text-green-700 sm:text-[13px]">

                                                            <?php echo number_format(
                                                                (float) $product['line_total']
                                                            ); ?>
                                                            MMK / <?php echo checkout_e(fm_unit_label($product['unit'])); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>

                                        <?php endforeach; ?>
                                    </div>
                                </section>

                            <?php endforeach; ?>
                        </div>
                    </section>
                    </div>

                    <!-- Right Column -->
                    <div class="checkout-column checkout-right-column">

                    <!-- Pickup / Delivery -->
                    <section class="checkout-card checkout-fulfillment rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_10px_rgba(15,23,42,0.04)] sm:p-6">
                        <div class="flex items-center gap-3">
                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-green-100 text-green-600">
                                <i class="fa-solid fa-truck-fast"></i>
                            </span>
                            <div>
                                <p class="text-xs font-black uppercase tracking-[0.18em] text-green-600">Fulfillment</p>
                                <h2 class="mt-1 text-lg font-black text-slate-900">Pickup or Delivery</h2>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            <label class="cursor-pointer rounded-2xl border border-slate-200 p-4 transition hover:border-green-300 hover:bg-green-50">
                                <div class="flex items-start gap-3">
                                    <input type="radio" name="fulfillment_type" value="pickup" <?php echo $fulfillmentType !== 'delivery' ? 'checked' : ''; ?> class="mt-1 h-4 w-4 accent-green-600">
                                    <div>
                                        <p class="text-sm font-black text-slate-900">Market Pickup</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">Collect the confirmed items from the vendor/market arrangement.</p>
                                    </div>
                                </div>
                            </label>

                            <label class="cursor-pointer rounded-2xl border border-slate-200 p-4 transition hover:border-green-300 hover:bg-green-50">
                                <div class="flex items-start gap-3">
                                    <input type="radio" name="fulfillment_type" value="delivery" <?php echo $fulfillmentType === 'delivery' ? 'checked' : ''; ?> class="mt-1 h-4 w-4 accent-green-600">
                                    <div>
                                        <p class="text-sm font-black text-slate-900">Delivery</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">Provide the recipient and address that Vendors can use for fulfillment.</p>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <div id="deliveryFields" class="mt-4 grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-2 block text-xs font-bold text-slate-600">Recipient Name *</span>
                                <input type="text" id="deliveryName" name="delivery_name" maxlength="100" value="<?php echo checkout_e($deliveryName); ?>" class="h-11 w-full rounded-xl border border-slate-200 px-4 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                            </label>
                            <label class="block">
                                <span class="mb-2 block text-xs font-bold text-slate-600">Delivery Phone *</span>
                                <input type="text" id="deliveryPhone" name="delivery_phone" maxlength="30" value="<?php echo checkout_e($deliveryPhone); ?>" class="h-11 w-full rounded-xl border border-slate-200 px-4 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="mb-2 block text-xs font-bold text-slate-600">Delivery Address *</span>
                                <textarea id="deliveryAddress" name="delivery_address" maxlength="255" rows="2" placeholder="House, street, township/city" class="w-full resize-none rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"><?php echo checkout_e($deliveryAddress); ?></textarea>
                            </label>
                        </div>

                        <label class="mt-4 block">
                            <span class="mb-2 block text-xs font-bold text-slate-600">Order Note <span class="font-normal text-slate-400">(optional)</span></span>
                            <input type="text" name="customer_note" maxlength="255" value="<?php echo checkout_e($customerNote); ?>" placeholder="Example: Call before delivery" class="h-11 w-full rounded-xl border border-slate-200 px-4 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        </label>
                    </section>

                <!-- Summary -->
                <aside class="checkout-summary">

                    <div class="checkout-card checkout-summary-sticky rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_10px_rgba(15,23,42,0.04)]">

                        <p class="text-xs font-black uppercase tracking-[0.18em] text-green-600">
                            Order Summary
                        </p>

                        <h2 class="mt-1 text-xl font-black text-slate-900">
                            Estimated Order Total
                        </h2>

                        <div class="mt-5 space-y-3 border-y border-slate-100 py-5">

                            <div class="flex items-center justify-between text-sm">

                                <span class="text-slate-500">
                                    Vendors
                                </span>

                                <span class="font-black text-slate-800">
                                    <?php echo number_format($checkoutVendorCount); ?>
                                </span>
                            </div>

                            <div class="flex items-center justify-between text-sm">

                                <span class="text-slate-500">
                                    Items
                                </span>

                                <span class="font-black text-slate-800">
                                    <?php echo number_format($cartCount); ?>
                                </span>
                            </div>

                            <?php foreach ($checkoutVendorGroups as $vendorGroup): ?>

                                <div class="flex items-start justify-between gap-3 text-xs">

                                    <span class="min-w-0 flex-1 truncate text-slate-400">

                                        <?php echo checkout_e(
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
                                Estimated Total
                            </span>

                            <span class="text-2xl font-black text-green-700">
                                <?php echo number_format($subtotal); ?>
                                MMK
                            </span>
                        </div>

                        <button type="submit"
                                <?php echo $cartError !== ''
                                    ? 'disabled'
                                    : ''; ?>
                                class="mt-5 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-black text-white shadow-sm transition hover:bg-green-700 disabled:cursor-not-allowed disabled:bg-slate-300">

                            <i class="fa-solid fa-clipboard-check text-xs"></i>
                            Place Order
                        </button>

                        <div class="mt-3 flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-[10px] leading-5 text-slate-500">

                            <i class="fa-solid fa-circle-info mt-0.5 shrink-0 text-green-600"></i>

                            <p>
                                Payment will be available after all vendors respond.
                                Rejected vendor amounts will be excluded.
                            </p>
                        </div>
                    </div>
                </aside>
                    </div>
                </div>
            </form>

        <?php endif; ?>
    </div>

<?php endif; ?>

</main>


<script>
(function () {
    var radios = document.querySelectorAll('input[name="fulfillment_type"]');
    var fields = document.getElementById('deliveryFields');
    var nameInput = document.getElementById('deliveryName');
    var phoneInput = document.getElementById('deliveryPhone');
    var addressInput = document.getElementById('deliveryAddress');

    function updateFulfillmentFields() {
        var selected = document.querySelector('input[name="fulfillment_type"]:checked');
        var delivery = selected && selected.value === 'delivery';
        if (fields) { fields.classList.toggle('hidden', !delivery); }
        [nameInput, phoneInput, addressInput].forEach(function (input) {
            if (input) { input.required = !!delivery; }
        });
    }

    Array.prototype.forEach.call(radios, function (radio) {
        radio.addEventListener('change', updateFulfillmentFields);
    });
    updateFulfillmentFields();
}());
</script>

<?php
if (file_exists(__DIR__ . '/footer.php')) {
    require __DIR__ . '/footer.php';
}
?>