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
| Customer My Orders
|--------------------------------------------------------------------------
| Save as:
| C:\xampp\htdocs\farmer_marketplace\my_orders.php
|
| Displays the master purchase and its vendor-specific sub-orders.
| PHP 7.1 compatible.
|--------------------------------------------------------------------------
*/

function mo_e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function mo_redirect($location)
{
    header('Location: ' . $location);
    exit;
}

function mo_date($value, $format)
{
    $time = strtotime((string) $value);

    return $time !== false
        ? date($format, $time)
        : '—';
}

function mo_payment_label($method)
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

function mo_payment_status_label($status)
{
    $status = strtolower(
        trim((string) $status)
    );

    if ($status === 'pending_verification') {
        return 'Pending Verification';
    }

    if ($status === 'paid') {
        return 'Paid';
    }

    if ($status === 'rejected') {
        return 'Payment Rejected';
    }

    return 'Unpaid';
}

function mo_payment_status_class($status)
{
    $status = strtolower(
        trim((string) $status)
    );

    if ($status === 'pending_verification') {
        return 'bg-blue-50 text-blue-700 ring-blue-200';
    }

    if ($status === 'paid') {
        return 'bg-green-50 text-green-700 ring-green-200';
    }

    if ($status === 'rejected') {
        return 'bg-red-50 text-red-700 ring-red-200';
    }

    return 'bg-amber-50 text-amber-700 ring-amber-200';
}

function mo_vendor_status_class($status)
{
    $status = strtolower((string) $status);

    if ($status === 'confirmed') {
        return 'bg-green-50 text-green-700 ring-green-200';
    }

    if ($status === 'completed') {
        return 'bg-blue-50 text-blue-700 ring-blue-200';
    }

    if ($status === 'rejected') {
        return 'bg-red-50 text-red-700 ring-red-200';
    }

    return 'bg-amber-50 text-amber-700 ring-amber-200';
}

function mo_master_display_status(
    $total,
    $pending,
    $confirmed,
    $rejected,
    $completed
) {
    $total = (int) $total;
    $pending = (int) $pending;
    $confirmed = (int) $confirmed;
    $rejected = (int) $rejected;
    $completed = (int) $completed;

    if ($total <= 0 || $pending === $total) {
        return array(
            'label' => 'Pending',
            'class' => 'bg-amber-50 text-amber-700 ring-amber-200'
        );
    }

    if ($completed === $total) {
        return array(
            'label' => 'Completed',
            'class' => 'bg-blue-50 text-blue-700 ring-blue-200'
        );
    }

    if (
        $total > 0 &&
        ($confirmed + $completed) === $total
    ) {
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

    return array(
        'label' => 'Partially Processed',
        'class' => 'bg-violet-50 text-violet-700 ring-violet-200'
    );
}

function mo_initial($name)
{
    $name = trim((string) $name);

    return $name !== ''
        ? strtoupper(substr($name, 0, 1))
        : 'U';
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
        email
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
    mo_redirect('signin.php');
}

$userName = (string) $user['user_name'];
$userInitial = mo_initial($userName);

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$statusFilter = isset($_GET['status'])
    ? strtolower(trim((string) $_GET['status']))
    : 'all';

$allowedStatuses = array(
    'all',
    'pending',
    'confirmed',
    'completed',
    'rejected',
    'partial'
);

if (
    !in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {
    $statusFilter = 'all';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 10;

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$statsStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_orders,

        SUM(
            CASE
                WHEN derived.vendor_count > 0
                 AND derived.pending_count =
                     derived.vendor_count
                THEN 1 ELSE 0
            END
        ) AS pending_orders,

        SUM(
            CASE
                WHEN derived.vendor_count > 0
                 AND (
                     derived.confirmed_count +
                     derived.completed_count
                 ) = derived.vendor_count
                 AND derived.completed_count <>
                     derived.vendor_count
                THEN 1 ELSE 0
            END
        ) AS confirmed_orders,

        SUM(
            CASE
                WHEN derived.vendor_count > 0
                 AND derived.completed_count =
                     derived.vendor_count
                THEN 1 ELSE 0
            END
        ) AS completed_orders,

        SUM(
            CASE
                WHEN derived.vendor_count > 0
                 AND derived.rejected_count =
                     derived.vendor_count
                THEN 1 ELSE 0
            END
        ) AS rejected_orders,

        SUM(
            CASE
                WHEN derived.vendor_count > 0
                 AND derived.pending_count <>
                     derived.vendor_count
                 AND derived.completed_count <>
                     derived.vendor_count
                 AND (
                     derived.confirmed_count +
                     derived.completed_count
                 ) <> derived.vendor_count
                 AND derived.rejected_count <>
                     derived.vendor_count
                THEN 1 ELSE 0
            END
        ) AS partial_orders

     FROM (
        SELECT
            pp.purchase_id,

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
                  AND vo_pending.order_status =
                      'pending'
            ) AS pending_count,

            (
                SELECT COUNT(*)
                FROM vendor_orders vo_confirmed
                WHERE vo_confirmed.purchase_id =
                      pp.purchase_id
                  AND vo_confirmed.order_status =
                      'confirmed'
            ) AS confirmed_count,

            (
                SELECT COUNT(*)
                FROM vendor_orders vo_completed
                WHERE vo_completed.purchase_id =
                      pp.purchase_id
                  AND vo_completed.order_status =
                      'completed'
            ) AS completed_count,

            (
                SELECT COUNT(*)
                FROM vendor_orders vo_rejected
                WHERE vo_rejected.purchase_id =
                      pp.purchase_id
                  AND vo_rejected.order_status =
                      'rejected'
            ) AS rejected_count

        FROM purchase_process pp
        WHERE pp.user_id = :user_id
     ) derived"
);

$statsStatement->execute(array(
    'user_id' => $userId
));

$stats = $statsStatement->fetch();

/*
|--------------------------------------------------------------------------
| Build Filter SQL
|--------------------------------------------------------------------------
*/

$whereParts = array(
    'pp.user_id = :user_id'
);

$params = array(
    'user_id' => $userId
);

if ($statusFilter === 'pending') {
    $whereParts[] =
        "(
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_pending
            WHERE vo_filter_pending.purchase_id =
                  pp.purchase_id
              AND vo_filter_pending.order_status =
                  'pending'
        ) = (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_total_pending
            WHERE vo_filter_total_pending.purchase_id =
                  pp.purchase_id
        )
        AND (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_total_pending_nonzero
            WHERE vo_filter_total_pending_nonzero.purchase_id =
                  pp.purchase_id
        ) > 0";

} elseif ($statusFilter === 'completed') {
    $whereParts[] =
        "(
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_completed
            WHERE vo_filter_completed.purchase_id =
                  pp.purchase_id
              AND vo_filter_completed.order_status =
                  'completed'
        ) = (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_total_completed
            WHERE vo_filter_total_completed.purchase_id =
                  pp.purchase_id
        )
        AND (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_total_completed_nonzero
            WHERE vo_filter_total_completed_nonzero.purchase_id =
                  pp.purchase_id
        ) > 0";

} elseif ($statusFilter === 'confirmed') {
    $whereParts[] =
        "(
            (
                SELECT COUNT(*)
                FROM vendor_orders vo_filter_confirmed
                WHERE vo_filter_confirmed.purchase_id =
                      pp.purchase_id
                  AND vo_filter_confirmed.order_status =
                      'confirmed'
            )
            +
            (
                SELECT COUNT(*)
                FROM vendor_orders vo_filter_confirmed_completed
                WHERE vo_filter_confirmed_completed.purchase_id =
                      pp.purchase_id
                  AND vo_filter_confirmed_completed.order_status =
                      'completed'
            )
        ) = (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_total_confirmed
            WHERE vo_filter_total_confirmed.purchase_id =
                  pp.purchase_id
        )
        AND (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_total_confirmed_nonzero
            WHERE vo_filter_total_confirmed_nonzero.purchase_id =
                  pp.purchase_id
        ) > 0
        AND (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_all_completed
            WHERE vo_filter_all_completed.purchase_id =
                  pp.purchase_id
              AND vo_filter_all_completed.order_status =
                  'completed'
        ) <> (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_all_completed_total
            WHERE vo_filter_all_completed_total.purchase_id =
                  pp.purchase_id
        )";

} elseif ($statusFilter === 'rejected') {
    $whereParts[] =
        "(
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_rejected
            WHERE vo_filter_rejected.purchase_id =
                  pp.purchase_id
              AND vo_filter_rejected.order_status =
                  'rejected'
        ) = (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_total_rejected
            WHERE vo_filter_total_rejected.purchase_id =
                  pp.purchase_id
        )
        AND (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_total_rejected_nonzero
            WHERE vo_filter_total_rejected_nonzero.purchase_id =
                  pp.purchase_id
        ) > 0";

} elseif ($statusFilter === 'partial') {
    $whereParts[] =
        "(
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_partial_total
            WHERE vo_filter_partial_total.purchase_id =
                  pp.purchase_id
        ) > 0

        AND (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_partial_pending
            WHERE vo_filter_partial_pending.purchase_id =
                  pp.purchase_id
              AND vo_filter_partial_pending.order_status =
                  'pending'
        ) <> (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_partial_total_pending
            WHERE vo_filter_partial_total_pending.purchase_id =
                  pp.purchase_id
        )

        AND (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_partial_completed
            WHERE vo_filter_partial_completed.purchase_id =
                  pp.purchase_id
              AND vo_filter_partial_completed.order_status =
                  'completed'
        ) <> (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_partial_total_completed
            WHERE vo_filter_partial_total_completed.purchase_id =
                  pp.purchase_id
        )

        AND (
            (
                SELECT COUNT(*)
                FROM vendor_orders vo_filter_partial_confirmed
                WHERE vo_filter_partial_confirmed.purchase_id =
                      pp.purchase_id
                  AND vo_filter_partial_confirmed.order_status =
                      'confirmed'
            )
            +
            (
                SELECT COUNT(*)
                FROM vendor_orders vo_filter_partial_confirmed_completed
                WHERE vo_filter_partial_confirmed_completed.purchase_id =
                      pp.purchase_id
                  AND vo_filter_partial_confirmed_completed.order_status =
                      'completed'
            )
        ) <> (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_partial_total_accepted
            WHERE vo_filter_partial_total_accepted.purchase_id =
                  pp.purchase_id
        )

        AND (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_partial_rejected
            WHERE vo_filter_partial_rejected.purchase_id =
                  pp.purchase_id
              AND vo_filter_partial_rejected.order_status =
                  'rejected'
        ) <> (
            SELECT COUNT(*)
            FROM vendor_orders vo_filter_partial_total_rejected
            WHERE vo_filter_partial_total_rejected.purchase_id =
                  pp.purchase_id
        )";
}

$whereSql = implode(' AND ', $whereParts);

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$countStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM purchase_process pp
     WHERE {$whereSql}"
);

$countStatement->execute($params);

$totalOrders =
    (int) $countStatement->fetchColumn();

$totalPages = max(
    1,
    (int) ceil($totalOrders / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Master Orders
|--------------------------------------------------------------------------
*/

$listStatement = $pdo->prepare(
    "SELECT
        pp.purchase_id,
        pp.total_amount,
        pp.payment_method,
        pp.payment_status,
        pp.fulfillment_type,
        pp.order_status,
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
              AND vo_pending.order_status =
                  'pending'
        ) AS pending_count,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_confirmed
            WHERE vo_confirmed.purchase_id =
                  pp.purchase_id
              AND vo_confirmed.order_status =
                  'confirmed'
        ) AS confirmed_count,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_completed
            WHERE vo_completed.purchase_id =
                  pp.purchase_id
              AND vo_completed.order_status =
                  'completed'
        ) AS completed_count,

        (
            SELECT COUNT(*)
            FROM vendor_orders vo_rejected
            WHERE vo_rejected.purchase_id =
                  pp.purchase_id
              AND vo_rejected.order_status =
                  'rejected'
        ) AS rejected_count,

        (
            SELECT COALESCE(
                SUM(vo_pay.subtotal),
                0
            )
            FROM vendor_orders vo_pay
            WHERE vo_pay.purchase_id =
                  pp.purchase_id
              AND vo_pay.order_status IN (
                  'confirmed',
                  'completed'
              )
        ) AS payable_amount

     FROM purchase_process pp
     WHERE {$whereSql}
     ORDER BY pp.created_at DESC, pp.purchase_id DESC
     LIMIT :limit_value OFFSET :offset_value"
);

foreach ($params as $name => $value) {
    $listStatement->bindValue(
        ':' . $name,
        $value,
        PDO::PARAM_INT
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

$orders = $listStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Load Vendor Orders for Current Page
|--------------------------------------------------------------------------
*/

$vendorGroups = array();

if (!empty($orders)) {
    $purchaseIds = array();

    foreach ($orders as $order) {
        $purchaseIds[] =
            (int) $order['purchase_id'];
    }

    $placeholders = array();

    foreach ($purchaseIds as $index => $purchaseId) {
        $placeholders[] =
            ':purchase_' . $index;
    }

    $vendorListStatement = $pdo->prepare(
        "SELECT
            vo.vendor_order_id,
            vo.purchase_id,
            vo.subtotal,
            vo.order_status,
            vo.rejection_reason,
            v.vendor_name
         FROM vendor_orders vo
         INNER JOIN vendors v
            ON v.vendor_id = vo.vendor_id
         WHERE vo.purchase_id IN (" .
            implode(', ', $placeholders) .
         ")
         ORDER BY
            vo.purchase_id DESC,
            vo.vendor_order_id ASC"
    );

    foreach ($purchaseIds as $index => $purchaseId) {
        $vendorListStatement->bindValue(
            ':purchase_' . $index,
            $purchaseId,
            PDO::PARAM_INT
        );
    }

    $vendorListStatement->execute();

    foreach ($vendorListStatement->fetchAll() as $vendorOrder) {
        $purchaseId =
            (int) $vendorOrder['purchase_id'];

        if (!isset($vendorGroups[$purchaseId])) {
            $vendorGroups[$purchaseId] = array();
        }

        $vendorGroups[$purchaseId][] =
            $vendorOrder;
    }
}

/*
|--------------------------------------------------------------------------
| Selected Order Detail
|--------------------------------------------------------------------------
*/

$selectedOrder = null;
$selectedVendors = array();
$selectedItems = array();

$viewId = isset($_GET['view'])
    ? max(0, (int) $_GET['view'])
    : 0;

if ($viewId > 0) {
    $detailStatement = $pdo->prepare(
        "SELECT
            purchase_id,
            total_amount,
            payment_method,
            payment_status,
            transaction_id,
            payment_submitted_at,
            paid_at,
            verified_at,
            payment_rejection_reason,
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

    $detailStatement->execute(array(
        'purchase_id' => $viewId,
        'user_id' => $userId
    ));

    $selectedOrder = $detailStatement->fetch();

    if ($selectedOrder) {
        $vendorStatement = $pdo->prepare(
            "SELECT
                vo.vendor_order_id,
                vo.vendor_id,
                vo.subtotal,
                vo.order_status,
                vo.rejection_reason,
                vo.confirmed_at,
                vo.rejected_at,
                vo.created_at,
                v.vendor_name
             FROM vendor_orders vo
             INNER JOIN vendors v
                ON v.vendor_id = vo.vendor_id
             WHERE vo.purchase_id = :purchase_id
             ORDER BY vo.vendor_order_id ASC"
        );

        $vendorStatement->execute(array(
            'purchase_id' => $viewId
        ));

        $selectedVendors =
            $vendorStatement->fetchAll();

        if (!empty($selectedVendors)) {
            foreach ($selectedVendors as $vendorOrder) {
                $vendorOrderId =
                    (int) $vendorOrder['vendor_order_id'];

                $itemStatement = $pdo->prepare(
                    "SELECT
                        pd.purchase_detail_id,
                        pd.quantity,
                        pd.unit_price,
                        pd.subtotal,
                        COALESCE(
                            p.product_name,
                            'Product'
                        ) AS product_name,
                        COALESCE(p.unit, 'piece') AS unit
                     FROM purchase_details pd
                     LEFT JOIN products p
                        ON p.product_id =
                           pd.product_id
                     WHERE pd.vendor_order_id =
                           :vendor_order_id
                     ORDER BY
                        pd.purchase_detail_id ASC"
                );

                $itemStatement->execute(array(
                    'vendor_order_id' =>
                        $vendorOrderId
                ));

                $selectedItems[$vendorOrderId] =
                    $itemStatement->fetchAll();
            }
        }
    }
}

function mo_url($overrides)
{
    $params = $_GET;
    unset($params['view']);

    foreach ($overrides as $key => $value) {
        if (
            $value === null ||
            $value === ''
        ) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    $query = http_build_query($params);

    return 'my_orders.php' .
        ($query !== '' ? '?' . $query : '');
}

$showFrom = $totalOrders > 0
    ? $offset + 1
    : 0;

$showTo = min(
    $offset + $perPage,
    $totalOrders
);

/*
|--------------------------------------------------------------------------
| Pagination Number Window
|--------------------------------------------------------------------------
| Show at most five numbered page buttons around the current page.
|--------------------------------------------------------------------------
*/

$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);

if (($paginationEnd - $paginationStart) < 4) {
    if ($paginationStart === 1) {
        $paginationEnd = min(
            $totalPages,
            $paginationStart + 4
        );
    } elseif ($paginationEnd === $totalPages) {
        $paginationStart = max(
            1,
            $paginationEnd - 4
        );
    }
}
?>
<?php
$pageTitle = 'My Orders | Local Farmers Marketplace';
require __DIR__ . '/header.php';
?>

    <main class="mx-auto max-w-7xl px-4 py-7 sm:px-6 lg:px-8 lg:py-9">

        <!-- Heading -->
        <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">

            <div>

                <h1 class="text-3xl font-black tracking-tight text-slate-950">
                    My Orders
                </h1>

            </div>

            <a href="products.php"
               class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-green-200 bg-white px-5 text-sm font-black text-green-700 transition hover:bg-green-50">

                <i class="fa-solid fa-basket-shopping"></i>
                Continue Shopping
            </a>
        </section>

        <!-- Stats -->
        <section class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft">
                <p class="text-xs font-bold text-slate-400">
                    Total Orders
                </p>

                <p class="mt-2 text-2xl font-black text-slate-950">
                    <?php echo number_format((int) $stats['total_orders']); ?>
                </p>
            </div>

            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <p class="text-xs font-bold text-amber-600">
                    Pending
                </p>

                <p class="mt-2 text-2xl font-black text-amber-900">
                    <?php echo number_format((int) $stats['pending_orders']); ?>
                </p>
            </div>

            <div class="rounded-2xl border border-green-200 bg-green-50 p-5">
                <p class="text-xs font-bold text-green-600">
                    Confirmed
                </p>

                <p class="mt-2 text-2xl font-black text-green-900">
                    <?php echo number_format((int) $stats['confirmed_orders']); ?>
                </p>
            </div>

            <div class="rounded-2xl border border-blue-200 bg-blue-50 p-5">
                <p class="text-xs font-bold text-blue-600">
                    Completed
                </p>

                <p class="mt-2 text-2xl font-black text-blue-900">
                    <?php echo number_format((int) $stats['completed_orders']); ?>
                </p>
            </div>

            <div class="rounded-2xl border border-red-200 bg-red-50 p-5">
                <p class="text-xs font-bold text-red-600">
                    Rejected
                </p>

                <p class="mt-2 text-2xl font-black text-red-900">
                    <?php echo number_format((int) $stats['rejected_orders']); ?>
                </p>
            </div>

            <div class="rounded-2xl border border-violet-200 bg-violet-50 p-5">
                <p class="text-xs font-bold text-violet-600">
                    Partially Processed
                </p>

                <p class="mt-2 text-2xl font-black text-violet-900">
                    <?php echo number_format((int) $stats['partial_orders']); ?>
                </p>
            </div>
        </section>

        <!-- Filter -->
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-3 shadow-soft">

            <div class="flex flex-wrap gap-2">

                <?php
                $filterOptions = array(
                    'all' => 'All Orders',
                    'pending' => 'Pending',
                    'confirmed' => 'Confirmed',
                    'completed' => 'Completed',
                    'rejected' => 'Rejected',
                    'partial' => 'Partially Processed'
                );
                ?>

                <?php foreach ($filterOptions as $key => $label): ?>

                    <a href="<?php echo mo_e(
                        mo_url(
                            array(
                                'status' =>
                                    $key === 'all'
                                        ? null
                                        : $key,
                                'page' => null
                            )
                        )
                    ); ?>"
                       class="rounded-xl px-4 py-2 text-xs font-black transition
                       <?php echo $statusFilter === $key
                            ? 'bg-green-600 text-white'
                            : 'bg-slate-50 text-slate-500 hover:bg-green-50 hover:text-green-700'; ?>">

                        <?php echo mo_e($label); ?>
                    </a>

                <?php endforeach; ?>
            </div>
        </section>

        <!-- Order History Table -->
        <section class="mt-6 overflow-hidden rounded-[1.5rem] border border-slate-200 bg-white shadow-soft">

            <div class="flex flex-col gap-2 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                <div>
                    <h2 class="text-lg font-black text-slate-950">
                        Your Purchases
                    </h2>
                </div>

                <p class="text-xs text-slate-400">
                    Showing
                    <?php echo number_format($showFrom); ?>–<?php echo number_format($showTo); ?>
                    of
                    <?php echo number_format($totalOrders); ?>
                    orders
                </p>
            </div>

            <?php if (empty($orders)): ?>

                <div class="px-5 py-16 text-center">

                    <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-slate-50 text-slate-300">
                        <i class="fa-solid fa-receipt text-xl"></i>
                    </span>

                    <h3 class="mt-4 text-base font-black text-slate-800">
                        No orders found
                    </h3>

                    <p class="mt-2 text-sm text-slate-400">
                        Orders you place will appear here.
                    </p>

                    <a href="products.php"
                       class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-green-600 px-4 text-xs font-black text-white hover:bg-green-700">

                        <i class="fa-solid fa-basket-shopping"></i>
                        Shop Products
                    </a>
                </div>

            <?php else: ?>

                <div class="overflow-x-auto">

                    <table class="w-full min-w-[1050px] text-left">

                        <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-wider text-slate-400">

                            <tr>
                                <th class="px-5 py-3.5">
                                    Order ID
                                </th>

                                <th class="px-4 py-3.5">
                                    Date
                                </th>

                                <th class="px-4 py-3.5">
                                    Vendors
                                </th>

                                <th class="px-4 py-3.5">
                                    Payment
                                </th>

                                <th class="px-4 py-3.5">
                                    Total
                                </th>

                                <th class="px-4 py-3.5">
                                    Status
                                </th>

                                <th class="px-5 py-3.5 text-right">
                                    Action
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">

                            <?php foreach ($orders as $order): ?>
                                <?php
                                $purchaseId =
                                    (int) $order['purchase_id'];

                                $displayStatus =
                                    mo_master_display_status(
                                        $order['vendor_count'],
                                        $order['pending_count'],
                                        $order['confirmed_count'],
                                        $order['rejected_count'],
                                        $order['completed_count']
                                    );

                                $orderVendors =
                                    isset($vendorGroups[$purchaseId])
                                        ? $vendorGroups[$purchaseId]
                                        : array();
                                ?>

                                <tr class="align-top transition hover:bg-slate-50/70">

                                    <!-- Order -->
                                    <td class="px-5 py-4">

                                        <p class="text-sm font-black text-green-700">
                                            #<?php echo number_format($purchaseId); ?>
                                        </p>

                                        <?php
                                        $rowVendorCount =
                                            (int) $order['vendor_count'];
                                        ?>

                                        <p class="mt-1 text-[10px] text-slate-400">
                                            <?php echo number_format($rowVendorCount); ?>
                                            <?php echo $rowVendorCount === 1
                                                ? 'Vendor'
                                                : 'Vendors'; ?>
                                        </p>
                                    </td>

                                    <!-- Date -->
                                    <td class="px-4 py-4">

                                        <p class="text-xs font-bold text-slate-700">
                                            <?php echo mo_e(
                                                mo_date(
                                                    $order['created_at'],
                                                    'M j, Y'
                                                )
                                            ); ?>
                                        </p>

                                        <p class="mt-1 text-[10px] text-slate-400">
                                            <?php echo mo_e(
                                                mo_date(
                                                    $order['created_at'],
                                                    'g:i A'
                                                )
                                            ); ?>
                                        </p>
                                    </td>

                                    <!-- Vendors -->
                                    <td class="px-4 py-4">

                                        <?php if (empty($orderVendors)): ?>

                                            <span class="text-xs text-slate-400">
                                                No vendor details
                                            </span>

                                        <?php else: ?>

                                            <div class="space-y-2">

                                                <?php foreach ($orderVendors as $vendorOrder): ?>
                                                    <?php
                                                    $vendorStatus =
                                                        strtolower(
                                                            (string) $vendorOrder['order_status']
                                                        );
                                                    ?>

                                                    <div class="flex max-w-[240px] items-center justify-between gap-3">

                                                        <div class="min-w-0">

                                                            <p class="truncate text-xs font-bold text-slate-700">
                                                                <?php echo mo_e(
                                                                    $vendorOrder['vendor_name']
                                                                ); ?>
                                                            </p>

                                                            <p class="mt-0.5 text-[9px] text-slate-400">
                                                                Vendor #<?php echo number_format(
                                                                    (int) $vendorOrder['vendor_order_id']
                                                                ); ?>
                                                            </p>
                                                        </div>

                                                        <span class="shrink-0 rounded-full px-2 py-1 text-[8px] font-black capitalize ring-1 ring-inset <?php echo mo_e(
                                                            mo_vendor_status_class(
                                                                $vendorStatus
                                                            )
                                                        ); ?>">

                                                            <?php echo mo_e($vendorStatus); ?>
                                                        </span>
                                                    </div>

                                                <?php endforeach; ?>
                                            </div>

                                        <?php endif; ?>
                                    </td>

                                    <!-- Payment -->
                                    <td class="px-4 py-4">
                                        <?php
                                        $paymentMethod =
                                            trim((string) $order['payment_method']);

                                        $paymentStatus =
                                            strtolower(
                                                trim(
                                                    (string) $order['payment_status']
                                                )
                                            );

                                        if ($paymentStatus === '') {
                                            $paymentStatus = 'unpaid';
                                        }

                                        $paymentReady =
                                            (int) $order['vendor_count'] > 0 &&
                                            (int) $order['pending_count'] === 0 &&
                                            (
                                                (int) $order['confirmed_count'] +
                                                (int) $order['completed_count']
                                            ) > 0;
                                        ?>

                                        <?php if ((int) $order['pending_count'] > 0): ?>

                                            <span class="inline-flex items-center gap-2 rounded-lg bg-amber-50 px-2.5 py-2 text-[10px] font-bold text-amber-700">
                                                <i class="fa-solid fa-hourglass-half"></i>
                                                Awaiting Vendor Response
                                            </span>

                                        <?php elseif (!$paymentReady): ?>

                                            <span class="inline-flex items-center gap-2 rounded-lg bg-slate-50 px-2.5 py-2 text-[10px] font-bold text-slate-500">
                                                <i class="fa-solid fa-ban"></i>
                                                No Payment
                                            </span>

                                        <?php elseif ($paymentStatus === 'paid'): ?>

                                            <div>
                                                <span class="inline-flex items-center gap-2 rounded-lg bg-green-50 px-2.5 py-2 text-[10px] font-bold text-green-700">
                                                    <i class="fa-solid fa-circle-check"></i>
                                                    Paid
                                                </span>

                                                <p class="mt-1 text-[9px] font-bold text-slate-400">
                                                    <?php echo mo_e(
                                                        mo_payment_label(
                                                            $paymentMethod
                                                        )
                                                    ); ?>
                                                </p>
                                            </div>

                                        <?php elseif ($paymentStatus === 'pending_verification'): ?>

                                            <div>
                                                <span class="inline-flex items-center gap-2 rounded-lg bg-blue-50 px-2.5 py-2 text-[10px] font-bold text-blue-700">
                                                    <i class="fa-solid fa-hourglass-half"></i>
                                                    Pending Verification
                                                </span>

                                                <p class="mt-1 text-[9px] font-bold text-slate-400">
                                                    <?php echo mo_e(
                                                        mo_payment_label(
                                                            $paymentMethod
                                                        )
                                                    ); ?>
                                                </p>
                                            </div>

                                        <?php elseif ($paymentStatus === 'rejected'): ?>

                                            <div>
                                                <span class="inline-flex items-center gap-2 rounded-lg bg-red-50 px-2.5 py-2 text-[10px] font-bold text-red-700">
                                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                                    Payment Rejected
                                                </span>

                                                <p class="mt-1 text-[9px] text-red-500">
                                                    Resubmit payment proof
                                                </p>
                                            </div>

                                        <?php else: ?>

                                            <span class="inline-flex items-center gap-2 rounded-lg bg-violet-50 px-2.5 py-2 text-[10px] font-bold text-violet-700">
                                                <i class="fa-solid fa-wallet"></i>
                                                Payment Ready
                                            </span>

                                        <?php endif; ?>
                                    </td>

                                    <!-- Total -->
                                    <td class="px-4 py-4">

                                        <?php if ((int) $order['pending_count'] > 0): ?>

                                            <p class="whitespace-nowrap text-sm font-black text-slate-900">
                                                <?php echo number_format(
                                                    (float) $order['total_amount']
                                                ); ?>
                                                MMK
                                            </p>

                                            <p class="mt-1 text-[9px] font-bold text-amber-500">
                                                Estimated
                                            </p>

                                        <?php else: ?>

                                            <p class="whitespace-nowrap text-sm font-black text-green-700">
                                                <?php echo number_format(
                                                    (float) $order['payable_amount']
                                                ); ?>
                                                MMK
                                            </p>

                                            <p class="mt-1 text-[9px] font-bold text-green-600">
                                                Final Amount
                                            </p>

                                        <?php endif; ?>
                                    </td>

                                    <!-- Status -->
                                    <td class="px-4 py-4">

                                        <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1.5 text-[9px] font-black ring-1 ring-inset <?php echo mo_e(
                                            $displayStatus['class']
                                        ); ?>">

                                            <?php echo mo_e(
                                                $displayStatus['label']
                                            ); ?>
                                        </span>
                                    </td>

                                    <!-- Action -->
                                    <td class="px-5 py-4">

                                        <div class="flex items-center justify-end gap-2">

                                            <a href="<?php echo mo_e(
                                                mo_url(
                                                    array(
                                                        'view' => $purchaseId
                                                    )
                                                )
                                            ); ?>"
                                               class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-[10px] font-black text-slate-600 transition hover:bg-slate-50">

                                                <i class="fa-regular fa-eye"></i>
                                                View Details
                                            </a>

                                            <?php if (
                                                $paymentReady &&
                                                $paymentStatus === 'unpaid'
                                            ): ?>

                                                <a href="pay_order.php?purchase_id=<?php echo $purchaseId; ?>"
                                                   class="inline-flex h-9 items-center gap-2 rounded-lg bg-green-600 px-3 text-[10px] font-black text-white transition hover:bg-green-700">

                                                    <i class="fa-solid fa-wallet"></i>
                                                    Pay Now
                                                </a>

                                            <?php elseif (
                                                $paymentReady &&
                                                $paymentStatus === 'rejected'
                                            ): ?>

                                                <a href="pay_order.php?purchase_id=<?php echo $purchaseId; ?>"
                                                   class="inline-flex h-9 items-center gap-2 rounded-lg bg-red-600 px-3 text-[10px] font-black text-white transition hover:bg-red-700">

                                                    <i class="fa-solid fa-rotate"></i>
                                                    Resubmit
                                                </a>

                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </section>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>

            <nav class="mt-6 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-soft sm:flex-row sm:items-center sm:justify-between"
                 aria-label="Order history pagination">

                <div>
                    <p class="text-xs font-semibold text-slate-500">
                        Showing
                        <span class="font-black text-slate-700">
                            <?php echo number_format($showFrom); ?>–<?php echo number_format($showTo); ?>
                        </span>
                        of
                        <span class="font-black text-slate-700">
                            <?php echo number_format($totalOrders); ?>
                        </span>
                        orders
                    </p>

                    <p class="mt-1 text-[10px] text-slate-400">
                        Page <?php echo number_format($page); ?>
                        of <?php echo number_format($totalPages); ?>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">

                    <!-- Previous -->
                    <a href="<?php echo mo_e(
                        mo_url(
                            array(
                                'page' => max(1, $page - 1)
                            )
                        )
                    ); ?>"
                       aria-label="Previous page"
                       class="inline-flex h-9 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-600 transition
                       <?php echo $page <= 1
                            ? 'pointer-events-none opacity-40'
                            : 'hover:border-green-200 hover:bg-green-50 hover:text-green-700'; ?>">

                        <i class="fa-solid fa-chevron-left text-[9px]"></i>
                        Previous
                    </a>

                    <!-- First page shortcut -->
                    <?php if ($paginationStart > 1): ?>

                        <a href="<?php echo mo_e(
                            mo_url(array('page' => 1))
                        ); ?>"
                           class="grid h-9 min-w-9 place-items-center rounded-xl border border-slate-200 bg-white px-2 text-xs font-black text-slate-600 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700">
                            1
                        </a>

                        <?php if ($paginationStart > 2): ?>
                            <span class="px-1 text-xs font-bold text-slate-300">
                                …
                            </span>
                        <?php endif; ?>

                    <?php endif; ?>

                    <!-- Numbered pages -->
                    <?php for (
                        $pageNumber = $paginationStart;
                        $pageNumber <= $paginationEnd;
                        $pageNumber++
                    ): ?>

                        <a href="<?php echo mo_e(
                            mo_url(
                                array(
                                    'page' => $pageNumber
                                )
                            )
                        ); ?>"
                           aria-current="<?php echo $pageNumber === $page
                                ? 'page'
                                : 'false'; ?>"
                           class="grid h-9 min-w-9 place-items-center rounded-xl px-2 text-xs font-black transition
                           <?php echo $pageNumber === $page
                                ? 'bg-green-600 text-white shadow-sm'
                                : 'border border-slate-200 bg-white text-slate-600 hover:border-green-200 hover:bg-green-50 hover:text-green-700'; ?>">

                            <?php echo number_format($pageNumber); ?>
                        </a>

                    <?php endfor; ?>

                    <!-- Last page shortcut -->
                    <?php if ($paginationEnd < $totalPages): ?>

                        <?php if ($paginationEnd < ($totalPages - 1)): ?>
                            <span class="px-1 text-xs font-bold text-slate-300">
                                …
                            </span>
                        <?php endif; ?>

                        <a href="<?php echo mo_e(
                            mo_url(
                                array(
                                    'page' => $totalPages
                                )
                            )
                        ); ?>"
                           class="grid h-9 min-w-9 place-items-center rounded-xl border border-slate-200 bg-white px-2 text-xs font-black text-slate-600 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700">

                            <?php echo number_format($totalPages); ?>
                        </a>

                    <?php endif; ?>

                    <!-- Next -->
                    <a href="<?php echo mo_e(
                        mo_url(
                            array(
                                'page' => min(
                                    $totalPages,
                                    $page + 1
                                )
                            )
                        )
                    ); ?>"
                       aria-label="Next page"
                       class="inline-flex h-9 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-600 transition
                       <?php echo $page >= $totalPages
                            ? 'pointer-events-none opacity-40'
                            : 'hover:border-green-200 hover:bg-green-50 hover:text-green-700'; ?>">

                        Next
                        <i class="fa-solid fa-chevron-right text-[9px]"></i>
                    </a>
                </div>
            </nav>

        <?php endif; ?>

    </main>

    <!-- Order Detail Modal -->
    <?php if ($selectedOrder): ?>
        <?php
        $detailVendorCount =
            count($selectedVendors);

        $detailPending = 0;
        $detailConfirmed = 0;
        $detailCompleted = 0;
        $detailRejected = 0;

        foreach ($selectedVendors as $vendorOrder) {
            $status =
                strtolower(
                    (string) $vendorOrder['order_status']
                );

            if ($status === 'confirmed') {
                $detailConfirmed++;
            } elseif ($status === 'completed') {
                $detailCompleted++;
            } elseif ($status === 'rejected') {
                $detailRejected++;
            } else {
                $detailPending++;
            }
        }

        $detailStatus =
            mo_master_display_status(
                $detailVendorCount,
                $detailPending,
                $detailConfirmed,
                $detailRejected,
                $detailCompleted
            );
        ?>

        <div class="fixed inset-0 z-[80] overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm">

            <div class="mx-auto flex min-h-full max-w-4xl items-center justify-center">

                <article class="w-full overflow-hidden rounded-[1.5rem] bg-white shadow-2xl">

                    <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4">

                        <div>

                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-green-600">
                                Order Details
                            </p>

                            <div class="mt-1 flex flex-wrap items-center gap-2">

                                <h2 class="text-xl font-black text-slate-950">
                                    Order #<?php echo number_format((int) $selectedOrder['purchase_id']); ?>
                                </h2>

                                <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-black ring-1 ring-inset <?php echo mo_e($detailStatus['class']); ?>">
                                    <?php echo mo_e($detailStatus['label']); ?>
                                </span>
                            </div>

                            <p class="mt-1 text-xs text-slate-400">
                                <?php echo mo_e(
                                    mo_date(
                                        $selectedOrder['created_at'],
                                        'M j, Y · g:i A'
                                    )
                                ); ?>
                            </p>
                        </div>

                        <a href="<?php echo mo_e(mo_url(array())); ?>"
                           class="grid h-10 w-10 place-items-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700">

                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    </div>

                    <div class="max-h-[80vh] overflow-y-auto p-5">

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">

                            <div class="rounded-xl bg-slate-50 p-4">

                                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                                    Total
                                </p>

                                <p class="mt-2 text-lg font-black text-green-700">
                                    <?php echo number_format((float) $selectedOrder['total_amount']); ?>
                                    MMK
                                </p>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-4">

                                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                                    Payment Method
                                </p>

                                <p class="mt-2 text-sm font-black text-slate-800">
                                    <?php echo mo_e(
                                        mo_payment_label(
                                            $selectedOrder['payment_method']
                                        )
                                    ); ?>
                                </p>
                            </div>

                            <?php
                            $selectedPaymentStatus =
                                strtolower(
                                    trim(
                                        (string) $selectedOrder['payment_status']
                                    )
                                );

                            if ($selectedPaymentStatus === '') {
                                $selectedPaymentStatus = 'unpaid';
                            }
                            ?>

                            <div class="rounded-xl bg-slate-50 p-4">

                                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                                    Payment Status
                                </p>

                                <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-[10px] font-black ring-1 ring-inset <?php echo mo_e(
                                    mo_payment_status_class(
                                        $selectedPaymentStatus
                                    )
                                ); ?>">

                                    <?php echo mo_e(
                                        mo_payment_status_label(
                                            $selectedPaymentStatus
                                        )
                                    ); ?>
                                </span>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-4">

                                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                                    Vendors
                                </p>

                                <p class="mt-2 text-lg font-black text-slate-800">
                                    <?php echo number_format($detailVendorCount); ?>
                                </p>
                            </div>
                        </div>

                        <div class="mt-5 rounded-2xl border border-green-200 bg-green-50 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p class="text-[10px] font-black uppercase tracking-wider text-green-600">Fulfillment</p>
                                    <p class="mt-1 text-sm font-black text-green-950"><?php echo mo_e(fm_fulfillment_label($selectedOrder['fulfillment_type'])); ?></p>
                                </div>
                                <?php if (strtolower((string) $selectedOrder['fulfillment_type']) === 'delivery'): ?>
                                    <div class="min-w-0 sm:text-right">
                                        <p class="text-xs font-bold text-slate-800"><?php echo mo_e($selectedOrder['delivery_name']); ?> · <?php echo mo_e($selectedOrder['delivery_phone']); ?></p>
                                        <p class="mt-1 max-w-xl text-xs leading-5 text-slate-600"><?php echo mo_e($selectedOrder['delivery_address']); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (trim((string) $selectedOrder['customer_note']) !== ''): ?>
                                <p class="mt-3 border-t border-green-200 pt-3 text-xs text-green-800"><strong>Order note:</strong> <?php echo mo_e($selectedOrder['customer_note']); ?></p>
                            <?php endif; ?>
                        </div>

                        <?php
                        $detailFinalAmount = 0;

                        foreach ($selectedVendors as $vendorOrder) {
                            $detailVendorStatus = strtolower(
                                (string) $vendorOrder['order_status']
                            );

                            if (
                                in_array(
                                    $detailVendorStatus,
                                    array('confirmed', 'completed'),
                                    true
                                )
                            ) {
                                $detailFinalAmount +=
                                    (float) $vendorOrder['subtotal'];
                            }
                        }

                        $detailPaymentReady =
                            $detailVendorCount > 0 &&
                            $detailPending === 0 &&
                            ($detailConfirmed + $detailCompleted) > 0;

                        $detailPaymentMethod =
                            trim(
                                (string) $selectedOrder['payment_method']
                            );

                        $detailPaymentStatus =
                            strtolower(
                                (string) $selectedOrder['payment_status']
                            );
                        ?>

                        <div class="mt-5 rounded-2xl border p-4
                            <?php echo !$detailPaymentReady
                                ? 'border-amber-200 bg-amber-50'
                                : (
                                    $detailPaymentStatus === 'paid'
                                        ? 'border-green-200 bg-green-50'
                                        : (
                                            $detailPaymentStatus === 'pending_verification'
                                                ? 'border-blue-200 bg-blue-50'
                                                : (
                                                    $detailPaymentStatus === 'rejected'
                                                        ? 'border-red-200 bg-red-50'
                                                        : 'border-violet-200 bg-violet-50'
                                                )
                                        )
                                ); ?>">

                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                                <div>

                                    <p class="text-xs font-black uppercase tracking-wider text-slate-500">
                                        Payment
                                    </p>

                                    <?php if (!$detailPaymentReady): ?>

                                        <p class="mt-1 text-sm font-black text-amber-900">
                                            Waiting for vendor responses
                                        </p>

                                        <p class="mt-1 text-xs text-amber-700">
                                            Payment becomes available after all vendors confirm or reject.
                                        </p>

                                    <?php elseif ($detailPaymentStatus === 'paid'): ?>

                                        <p class="mt-1 text-sm font-black text-green-900">
                                            Payment Verified ·
                                            <?php echo mo_e(
                                                mo_payment_label(
                                                    $detailPaymentMethod
                                                )
                                            ); ?>
                                        </p>

                                        <?php if (
                                            isset($selectedOrder['verified_at']) &&
                                            $selectedOrder['verified_at'] !== null
                                        ): ?>

                                            <p class="mt-1 text-xs text-green-700">
                                                Verified by Admin on
                                                <?php echo mo_e(
                                                    mo_date(
                                                        $selectedOrder['verified_at'],
                                                        'M j, Y · g:i A'
                                                    )
                                                ); ?>
                                            </p>

                                        <?php endif; ?>

                                    <?php elseif ($detailPaymentStatus === 'pending_verification'): ?>

                                        <p class="mt-1 text-sm font-black text-blue-900">
                                            Payment Submitted · Pending Verification
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-blue-700">
                                            Your payment proof has been submitted and is waiting for Admin verification.
                                        </p>

                                        <?php if (
                                            isset($selectedOrder['transaction_id']) &&
                                            trim((string) $selectedOrder['transaction_id']) !== ''
                                        ): ?>

                                            <p class="mt-2 text-[10px] font-bold text-blue-700">
                                                Transaction ID:
                                                <?php echo mo_e(
                                                    $selectedOrder['transaction_id']
                                                ); ?>
                                            </p>

                                        <?php endif; ?>

                                    <?php elseif ($detailPaymentStatus === 'rejected'): ?>

                                        <p class="mt-1 text-sm font-black text-red-900">
                                            Payment Proof Rejected
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-red-700">
                                            <?php echo mo_e(
                                                trim(
                                                    (string)
                                                    $selectedOrder['payment_rejection_reason']
                                                ) !== ''
                                                    ? $selectedOrder['payment_rejection_reason']
                                                    : 'Please submit a new valid payment proof.'
                                            ); ?>
                                        </p>

                                    <?php else: ?>

                                        <p class="mt-1 text-sm font-black text-violet-900">
                                            Final Amount:
                                            <?php echo number_format($detailFinalAmount); ?>
                                            MMK
                                        </p>

                                        <p class="mt-1 text-xs text-violet-700">
                                            Rejected vendor amounts are already excluded.
                                            You can now pay using KBZPay or Wave Money.
                                        </p>

                                    <?php endif; ?>
                                </div>

                                <?php if (
                                    $detailPaymentReady &&
                                    $detailPaymentStatus === 'unpaid'
                                ): ?>

                                    <a href="pay_order.php?purchase_id=<?php echo (int) $selectedOrder['purchase_id']; ?>"
                                       class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-xs font-black text-white hover:bg-green-700">

                                        <i class="fa-solid fa-wallet"></i>
                                        Pay Final Amount
                                    </a>

                                <?php elseif (
                                    $detailPaymentReady &&
                                    $detailPaymentStatus === 'rejected'
                                ): ?>

                                    <a href="pay_order.php?purchase_id=<?php echo (int) $selectedOrder['purchase_id']; ?>"
                                       class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-red-600 px-4 text-xs font-black text-white hover:bg-red-700">

                                        <i class="fa-solid fa-rotate"></i>
                                        Resubmit Payment
                                    </a>

                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-5 space-y-4">

                            <?php foreach ($selectedVendors as $vendorOrder): ?>
                                <?php
                                $vendorOrderId =
                                    (int) $vendorOrder['vendor_order_id'];

                                $vendorStatus =
                                    strtolower(
                                        (string) $vendorOrder['order_status']
                                    );

                                $items =
                                    isset($selectedItems[$vendorOrderId])
                                        ? $selectedItems[$vendorOrderId]
                                        : array();
                                ?>

                                <section class="overflow-hidden rounded-2xl border border-slate-200">

                                    <div class="flex items-center justify-between gap-4 border-b border-slate-100 bg-slate-50 px-4 py-3">

                                        <div>

                                            <p class="text-sm font-black text-slate-900">
                                                <?php echo mo_e($vendorOrder['vendor_name']); ?>
                                            </p>

                                            <p class="mt-1 text-[10px] text-slate-400">
                                                Vendor Order #<?php echo number_format($vendorOrderId); ?>
                                            </p>
                                        </div>

                                        <div class="text-right">

                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-black capitalize ring-1 ring-inset <?php echo mo_e(mo_vendor_status_class($vendorStatus)); ?>">
                                                <?php echo mo_e($vendorStatus); ?>
                                            </span>

                                            <p class="mt-2 text-sm font-black text-green-700">
                                                <?php echo number_format((float) $vendorOrder['subtotal']); ?>
                                                MMK
                                            </p>
                                        </div>
                                    </div>

                                    <div class="divide-y divide-slate-100">

                                        <?php foreach ($items as $item): ?>

                                            <div class="flex items-center justify-between gap-4 px-4 py-3">

                                                <div class="min-w-0">

                                                    <p class="truncate text-xs font-black text-slate-800">
                                                        <?php echo mo_e($item['product_name']); ?>
                                                    </p>

                                                    <p class="mt-1 text-[10px] text-slate-400">
                                                        <?php echo number_format((int) $item['quantity']); ?>
                                                        ×
                                                        <?php echo number_format((float) $item['unit_price']); ?>
                                                        MMK / <?php echo mo_e(fm_unit_label($item['unit'])); ?>
                                                    </p>
                                                </div>

                                                <p class="shrink-0 text-xs font-black text-green-700">
                                                    <?php echo number_format((float) $item['subtotal']); ?>
                                                    MMK
                                                </p>
                                            </div>

                                        <?php endforeach; ?>
                                    </div>

                                    <?php if (
                                        $vendorStatus === 'rejected' &&
                                        trim((string) $vendorOrder['rejection_reason']) !== ''
                                    ): ?>

                                        <div class="border-t border-red-100 bg-red-50 px-4 py-3 text-xs text-red-700">

                                            <strong>Rejection reason:</strong>
                                            <?php echo mo_e($vendorOrder['rejection_reason']); ?>
                                        </div>

                                    <?php endif; ?>
                                </section>

                            <?php endforeach; ?>
                        </div>
                    </div>
                </article>
            </div>
        </div>

    <?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>