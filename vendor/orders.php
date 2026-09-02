<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/marketplace.php';
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

if (!function_exists('vendor_order_csrf_token')) {
    function vendor_order_csrf_token()
    {
        if (
            !isset($_SESSION['vendor_order_csrf_token']) ||
            !is_string($_SESSION['vendor_order_csrf_token']) ||
            $_SESSION['vendor_order_csrf_token'] === ''
        ) {
            $_SESSION['vendor_order_csrf_token'] =
                bin2hex(random_bytes(24));
        }

        return $_SESSION['vendor_order_csrf_token'];
    }
}

if (!function_exists('vendor_order_verify_csrf')) {
    function vendor_order_verify_csrf($token)
    {
        return isset($_SESSION['vendor_order_csrf_token']) &&
            is_string($_SESSION['vendor_order_csrf_token']) &&
            hash_equals(
                $_SESSION['vendor_order_csrf_token'],
                (string) $token
            );
    }
}

if (!function_exists('vendor_order_flash')) {
    function vendor_order_flash($type, $message)
    {
        $_SESSION['vendor_order_flash'] = array(
            'type' => (string) $type,
            'message' => (string) $message
        );
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

/*
|--------------------------------------------------------------------------
| Vendor Order Actions
|--------------------------------------------------------------------------
| A vendor may confirm/reject only their own Pending vendor_order.
| A Confirmed vendor_order may later be marked Completed,
| but ONLY after Admin verifies the customer's payment as Paid.
| Rejecting restores reserved stock for that vendor's order items.
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    $action = isset($_POST['action'])
        ? trim((string) $_POST['action'])
        : '';

    $vendorOrderId = isset($_POST['vendor_order_id'])
        ? max(0, (int) $_POST['vendor_order_id'])
        : 0;

    if (!vendor_order_verify_csrf($csrfToken)) {
        vendor_order_flash(
            'error',
            'Your form session expired. Please try again.'
        );

        vendor_page_redirect('orders.php');
    }

    if (
        $vendorOrderId <= 0 ||
        !in_array($action, array('confirm', 'reject', 'complete'), true)
    ) {
        vendor_order_flash(
            'error',
            'Invalid vendor order request.'
        );

        vendor_page_redirect('orders.php');
    }

    $rejectionReason = isset($_POST['rejection_reason'])
        ? trim((string) $_POST['rejection_reason'])
        : '';

    if ($action === 'reject' && $rejectionReason === '') {
        vendor_order_flash(
            'error',
            'Please enter a rejection reason.'
        );

        vendor_page_redirect(
            'orders.php?view=' . $vendorOrderId
        );
    }

    try {
        $pdo->beginTransaction();

        $lockStatement = $pdo->prepare(
            "SELECT
                vo.vendor_order_id,
                vo.purchase_id,
                vo.vendor_id,
                vo.order_status,
                pp.payment_method,
                pp.payment_status
             FROM vendor_orders vo
             INNER JOIN purchase_process pp
                ON pp.purchase_id = vo.purchase_id
             WHERE vo.vendor_order_id = :vendor_order_id
               AND vo.vendor_id = :vendor_id
             LIMIT 1
             FOR UPDATE"
        );

        $lockStatement->execute(array(
            'vendor_order_id' => $vendorOrderId,
            'vendor_id' => $vendorId
        ));

        $lockedOrder = $lockStatement->fetch();

        if (!$lockedOrder) {
            throw new Exception(
                'This vendor order was not found or does not belong to you.'
            );
        }

        $currentOrderStatus =
            strtolower((string) $lockedOrder['order_status']);

        if (
            in_array($action, array('confirm', 'reject'), true) &&
            $currentOrderStatus !== 'pending'
        ) {
            throw new Exception(
                'Only Pending vendor orders can be confirmed or rejected.'
            );
        }

        $currentPaymentStatus =
            strtolower(
                trim(
                    (string) $lockedOrder['payment_status']
                )
            );

        if (
            $action === 'complete' &&
            $currentOrderStatus !== 'confirmed'
        ) {
            throw new Exception(
                'Only Confirmed vendor orders can be marked Completed.'
            );
        }

        if (
            $action === 'complete' &&
            $currentPaymentStatus !== 'paid'
        ) {
            throw new Exception(
                'This order cannot be completed until Admin verifies the customer payment as Paid.'
            );
        }

        $purchaseId =
            (int) $lockedOrder['purchase_id'];

        if ($action === 'confirm') {
            $updateStatement = $pdo->prepare(
                "UPDATE vendor_orders
                 SET
                    order_status = 'confirmed',
                    rejection_reason = NULL,
                    confirmed_at = NOW(),
                    rejected_at = NULL
                 WHERE vendor_order_id = :vendor_order_id
                   AND vendor_id = :vendor_id
                   AND order_status = 'pending'"
            );

            $updateStatement->execute(array(
                'vendor_order_id' => $vendorOrderId,
                'vendor_id' => $vendorId
            ));
        } elseif ($action === 'reject') {
            /*
            |--------------------------------------------------------------
            | Restore stock because checkout reserved/reduced stock
            | when the customer placed the order.
            |--------------------------------------------------------------
            */

            $itemStatement = $pdo->prepare(
                "SELECT
                    product_id,
                    quantity
                 FROM purchase_details
                 WHERE vendor_order_id = :vendor_order_id
                 FOR UPDATE"
            );

            $itemStatement->execute(array(
                'vendor_order_id' => $vendorOrderId
            ));

            $restoreStatement = $pdo->prepare(
                "UPDATE products
                 SET stock_quantity =
                    stock_quantity + :restore_quantity
                 WHERE product_id = :product_id"
            );

            foreach ($itemStatement->fetchAll() as $item) {
                if ($item['product_id'] === null) {
                    continue;
                }

                $restoreStatement->execute(array(
                    'restore_quantity' =>
                        (int) $item['quantity'],
                    'product_id' =>
                        (int) $item['product_id']
                ));
            }

            $updateStatement = $pdo->prepare(
                "UPDATE vendor_orders
                 SET
                    order_status = 'rejected',
                    rejection_reason = :rejection_reason,
                    rejected_at = NOW(),
                    confirmed_at = NULL
                 WHERE vendor_order_id = :vendor_order_id
                   AND vendor_id = :vendor_id
                   AND order_status = 'pending'"
            );

            $updateStatement->execute(array(
                'rejection_reason' => $rejectionReason,
                'vendor_order_id' => $vendorOrderId,
                'vendor_id' => $vendorId
            ));
        } else {
            /*
            |--------------------------------------------------------------
            | Complete only after Admin-verified payment and fulfillment/delivery.
            |--------------------------------------------------------------
            */

            $updateStatement = $pdo->prepare(
                "UPDATE vendor_orders
                 SET
                    order_status = 'completed',
                    completed_at = NOW()
                 WHERE vendor_order_id = :vendor_order_id
                   AND vendor_id = :vendor_id
                   AND order_status = 'confirmed'"
            );

            $updateStatement->execute(array(
                'vendor_order_id' => $vendorOrderId,
                'vendor_id' => $vendorId
            ));
        }

        /*
        |--------------------------------------------------------------
        | Synchronize the master purchase status.
        |
        | Rules:
        | - all vendor orders completed => completed
        | - all vendor orders confirmed/completed => confirmed
        | - all vendor orders rejected => rejected
        | - mixed or still waiting => pending
        |--------------------------------------------------------------
        */

        $syncStatement = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_count,
                SUM(
                    CASE
                        WHEN order_status = 'pending'
                        THEN 1 ELSE 0
                    END
                ) AS pending_count,
                SUM(
                    CASE
                        WHEN order_status = 'confirmed'
                        THEN 1 ELSE 0
                    END
                ) AS confirmed_count,
                SUM(
                    CASE
                        WHEN order_status = 'rejected'
                        THEN 1 ELSE 0
                    END
                ) AS rejected_count,
                SUM(
                    CASE
                        WHEN order_status = 'completed'
                        THEN 1 ELSE 0
                    END
                ) AS completed_count
             FROM vendor_orders
             WHERE purchase_id = :purchase_id"
        );

        $syncStatement->execute(array(
            'purchase_id' => $purchaseId
        ));

        $sync = $syncStatement->fetch();

        $totalCount =
            (int) $sync['total_count'];

        $pendingCount =
            (int) $sync['pending_count'];

        $confirmedCount =
            (int) $sync['confirmed_count'];

        $rejectedCount =
            (int) $sync['rejected_count'];

        $completedCount =
            (int) $sync['completed_count'];

        $masterStatus = 'pending';

        if (
            $totalCount > 0 &&
            $completedCount === $totalCount
        ) {
            $masterStatus = 'completed';
        } elseif (
            $totalCount > 0 &&
            ($confirmedCount + $completedCount) === $totalCount
        ) {
            $masterStatus = 'confirmed';
        } elseif (
            $totalCount > 0 &&
            $rejectedCount === $totalCount
        ) {
            $masterStatus = 'rejected';
        }

        $masterUpdateStatement = $pdo->prepare(
            "UPDATE purchase_process
             SET order_status = :order_status
             WHERE purchase_id = :purchase_id"
        );

        $masterUpdateStatement->execute(array(
            'order_status' => $masterStatus,
            'purchase_id' => $purchaseId
        ));

        $pdo->commit();

        if ($action === 'confirm') {
            $successMessage =
                'Vendor order confirmed successfully.';
        } elseif ($action === 'reject') {
            $successMessage =
                'Vendor order rejected and stock was restored.';
        } else {
            $successMessage =
                'Vendor order marked as completed successfully.';
        }

        vendor_order_flash(
            'success',
            $successMessage
        );

        vendor_page_redirect(
            'orders.php?view=' . $vendorOrderId
        );
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Vendor order database error: ' . $exception->getMessage());
        vendor_order_flash(
            'error',
            'The database could not complete the order action. Please try again.'
        );

        vendor_page_redirect('orders.php?view=' . $vendorOrderId);
    } catch (Exception $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        vendor_order_flash('error', $exception->getMessage());
        vendor_page_redirect('orders.php?view=' . $vendorOrderId);
    }
}

$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$statusFilter = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : 'all';
$paymentFilter = isset($_GET['payment_method']) ? strtolower(trim((string) $_GET['payment_method'])) : 'all';
$dateFrom = isset($_GET['date_from']) ? trim((string) $_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string) $_GET['date_to']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 10;

$allowedStatuses = array('all', 'pending', 'confirmed', 'completed', 'rejected');
$allowedPayments = array('all', 'kbzpay', 'wave_money');

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

if (!in_array($paymentFilter, $allowedPayments, true)) {
    $paymentFilter = 'all';
}

$statsStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_orders,
        SUM(
            CASE
                WHEN vo.order_status = 'pending'
                THEN 1 ELSE 0
            END
        ) AS pending_orders,
        SUM(
            CASE
                WHEN vo.order_status = 'confirmed'
                THEN 1 ELSE 0
            END
        ) AS confirmed_orders,
        SUM(
            CASE
                WHEN vo.order_status = 'rejected'
                THEN 1 ELSE 0
            END
        ) AS rejected_orders,
        SUM(
            CASE
                WHEN vo.order_status = 'completed'
                THEN 1 ELSE 0
            END
        ) AS completed_orders,
        COALESCE(
            SUM(
                CASE
                    WHEN vo.order_status IN ('confirmed', 'completed')
                     AND pp.payment_status = 'paid'
                    THEN vo.subtotal
                    ELSE 0
                END
            ),
            0
        ) AS paid_sales
     FROM vendor_orders vo
     INNER JOIN purchase_process pp
        ON pp.purchase_id = vo.purchase_id
     WHERE vo.vendor_id = :vendor_id"
);

$statsStatement->execute(array('vendor_id' => $vendorId));
$stats = $statsStatement->fetch();

$whereParts = array('vo.vendor_id = :vendor_id');
$parameters = array('vendor_id' => $vendorId);

if ($search !== '') {
    $whereParts[] = '(
        CAST(pp.purchase_id AS CHAR) LIKE :search_purchase_id OR
        CAST(vo.vendor_order_id AS CHAR) LIKE :search_vendor_order_id OR
        u.user_name LIKE :search_customer_name OR
        u.email LIKE :search_customer_email OR
        EXISTS (
            SELECT 1
            FROM purchase_details pd_search
            INNER JOIN products p_search
                ON p_search.product_id = pd_search.product_id
            WHERE pd_search.vendor_order_id = vo.vendor_order_id
              AND p_search.product_name LIKE :search_product_name
        )
    )';

    $searchValue = '%' . $search . '%';

    $parameters['search_purchase_id'] =
        $searchValue;

    $parameters['search_vendor_order_id'] =
        $searchValue;

    $parameters['search_customer_name'] =
        $searchValue;

    $parameters['search_customer_email'] =
        $searchValue;

    $parameters['search_product_name'] =
        $searchValue;
}

if ($statusFilter !== 'all') {
    $whereParts[] = 'vo.order_status = :order_status';
    $parameters['order_status'] = $statusFilter;
}

if ($paymentFilter !== 'all') {
    $whereParts[] = 'pp.payment_method = :payment_method';
    $parameters['payment_method'] = $paymentFilter;
}

$validDateFrom =
    $dateFrom !== '' &&
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom);

$validDateTo =
    $dateTo !== '' &&
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo);

if ($validDateFrom) {
    $whereParts[] =
        'DATE(vo.created_at) >= :date_from';

    $parameters['date_from'] =
        $dateFrom;
}

if ($validDateTo) {
    $whereParts[] =
        'DATE(vo.created_at) <= :date_to';

    $parameters['date_to'] =
        $dateTo;
}

$whereSql = implode(' AND ', $whereParts);

$countStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM vendor_orders vo
     INNER JOIN purchase_process pp
        ON pp.purchase_id = vo.purchase_id
     LEFT JOIN users u
        ON u.user_id = pp.user_id
     WHERE {$whereSql}"
);

$countStatement->execute($parameters);
$totalFilteredOrders = (int) $countStatement->fetchColumn();

$totalPages = max(1, (int) ceil($totalFilteredOrders / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$listStatement = $pdo->prepare(
    "SELECT
        vo.vendor_order_id,
        vo.purchase_id,
        vo.vendor_id,
        vo.subtotal AS vendor_total,
        vo.order_status,
        vo.rejection_reason,
        vo.created_at,
        vo.updated_at,
        pp.user_id,
        pp.payment_method,
        pp.payment_status,
        pp.fulfillment_type,
        pp.delivery_name,
        pp.delivery_phone,
        pp.delivery_address,
        pp.customer_note,
        pp.payment_submitted_at,
        pp.paid_at,
        pp.verified_at,
        pp.total_amount AS complete_order_total,
        COALESCE(u.user_name, 'Guest Customer') AS customer_name,
        COALESCE(u.email, 'No email') AS customer_email,
        COALESCE(u.phone_number, 'No phone') AS customer_phone,
        COALESCE(
            (
                SELECT SUM(pd_count.quantity)
                FROM purchase_details pd_count
                WHERE pd_count.vendor_order_id = vo.vendor_order_id
            ),
            0
        ) AS total_quantity,
        COALESCE(
            (
                SELECT COUNT(DISTINCT pd_count.product_id)
                FROM purchase_details pd_count
                WHERE pd_count.vendor_order_id = vo.vendor_order_id
            ),
            0
        ) AS product_count
     FROM vendor_orders vo
     INNER JOIN purchase_process pp
        ON pp.purchase_id = vo.purchase_id
     LEFT JOIN users u
        ON u.user_id = pp.user_id
     WHERE {$whereSql}
     ORDER BY vo.created_at DESC, vo.vendor_order_id DESC
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

$orders = $listStatement->fetchAll();

$selectedOrder = null;
$selectedItems = array();
$viewId = isset($_GET['view'])
    ? max(0, (int) $_GET['view'])
    : 0;

if ($viewId > 0) {
    $detailStatement = $pdo->prepare(
        "SELECT
            vo.vendor_order_id,
            vo.purchase_id,
            vo.vendor_id,
            vo.subtotal AS vendor_total,
            vo.order_status,
            vo.rejection_reason,
            vo.confirmed_at,
            vo.rejected_at,
            vo.completed_at,
            vo.created_at,
            vo.updated_at,
            pp.user_id,
            pp.total_amount AS complete_order_total,
            pp.payment_method,
            pp.payment_status,
            pp.fulfillment_type,
            pp.delivery_name,
            pp.delivery_phone,
            pp.delivery_address,
            pp.customer_note,
            pp.payment_submitted_at,
            pp.paid_at,
            pp.verified_at,
            pp.order_status AS complete_order_status,
            COALESCE(u.user_name, 'Guest Customer') AS customer_name,
            COALESCE(u.email, 'No email') AS customer_email,
            COALESCE(u.phone_number, 'No phone') AS customer_phone,
            COALESCE(
                (
                    SELECT SUM(pd_sum.quantity)
                    FROM purchase_details pd_sum
                    WHERE pd_sum.vendor_order_id = vo.vendor_order_id
                ),
                0
            ) AS vendor_quantity
         FROM vendor_orders vo
         INNER JOIN purchase_process pp
            ON pp.purchase_id = vo.purchase_id
         LEFT JOIN users u
            ON u.user_id = pp.user_id
         WHERE vo.vendor_order_id = :vendor_order_id
           AND vo.vendor_id = :vendor_id
         LIMIT 1"
    );

    $detailStatement->execute(array(
        'vendor_order_id' => $viewId,
        'vendor_id' => $vendorId
    ));

    $selectedOrder = $detailStatement->fetch();

    if ($selectedOrder) {
        $itemStatement = $pdo->prepare(
            "SELECT
                pd.purchase_detail_id,
                pd.vendor_order_id,
                pd.product_id,
                pd.quantity,
                pd.unit_price,
                pd.subtotal,
                p.product_name,
                p.unit,
                p.stock_quantity,
                c.category_name,
                m.market_name,
                (
                    SELECT pph.photo_path
                    FROM product_photo pph
                    WHERE pph.product_id = p.product_id
                    ORDER BY pph.product_photo_id ASC
                    LIMIT 1
                ) AS product_photo
             FROM purchase_details pd
             LEFT JOIN products p
                ON p.product_id = pd.product_id
             LEFT JOIN categories c
                ON c.category_id = p.category_id
             LEFT JOIN markets m
                ON m.market_id = c.market_id
             WHERE pd.vendor_order_id = :vendor_order_id
             ORDER BY pd.purchase_detail_id ASC"
        );

        $itemStatement->execute(array(
            'vendor_order_id' => $viewId
        ));

        $selectedItems =
            $itemStatement->fetchAll();
    }
}

function vendor_order_url($overrides)
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

    return 'orders.php' . ($query !== '' ? '?' . $query : '');
}

$statusClasses = array(
    'pending' => 'bg-amber-50 text-amber-700 ring-amber-200',
    'confirmed' => 'bg-green-50 text-green-700 ring-green-200',
    'completed' => 'bg-blue-50 text-blue-700 ring-blue-200',
    'rejected' => 'bg-red-50 text-red-700 ring-red-200'
);

$paymentLabels = array(
    'kbzpay' => 'KBZPay',
    'wave_money' => 'Wave Money'
);

$paymentStatusLabels = array(
    'unpaid' => 'Unpaid',
    'pending_verification' => 'Pending Verification',
    'paid' => 'Paid',
    'rejected' => 'Payment Rejected'
);

$paymentStatusClasses = array(
    'unpaid' => 'bg-amber-50 text-amber-700 ring-amber-200',
    'pending_verification' => 'bg-blue-50 text-blue-700 ring-blue-200',
    'paid' => 'bg-green-50 text-green-700 ring-green-200',
    'rejected' => 'bg-red-50 text-red-700 ring-red-200'
);

$showingFrom = $totalFilteredOrders > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalFilteredOrders);

$flash = isset($_SESSION['vendor_order_flash'])
    ? $_SESSION['vendor_order_flash']
    : null;

unset($_SESSION['vendor_order_flash']);

$csrfToken = vendor_order_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders | Farmers Market Vendor</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">

    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    


    <style>
        /*
        |--------------------------------------------------------------------------
        | Vendor Orders Responsive Layout
        |--------------------------------------------------------------------------
        | Header search is the single search control for this page.
        | Desktop: KPI cards, filters and order table remain compact at 100%.
        | Scrollbars are visually hidden while scrolling remains functional.
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

        .vendor-orders-scroll {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .vendor-orders-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .vendor-orders-stats {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1rem;
        }

        .vendor-order-stat-value {
            white-space: nowrap;
            font-size: 1.25rem;
            line-height: 1.2;
        }

        .vendor-paid-sales-value {
            display: flex;
            align-items: baseline;
            gap: 0.3rem;
            white-space: nowrap;
        }

        .vendor-paid-sales-value .amount {
            font-size: 1.5rem;
            line-height: 1;
            font-weight: 800;
            color: #020617;
        }

        .vendor-paid-sales-value .currency {
            font-size: 0.75rem;
            font-weight: 800;
            color: #020617;
        }

        .vendor-orders-filter-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 0.75rem;
            align-items: stretch;
        }

        .vendor-orders-filter-control {
            width: 100%;
            min-width: 0;
        }

        .vendor-orders-table {
            width: 100%;
            min-width: 1080px;
        }

        .vendor-orders-table th,
        .vendor-orders-table td {
            vertical-align: middle;
        }

        @media (min-width: 640px) {
            .vendor-orders-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .vendor-orders-filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .vendor-orders-stats {
                grid-template-columns:
                    repeat(5, minmax(0, 1fr))
                    minmax(220px, 1.15fr);
                gap: 0.8rem;
            }
        }

        @media (min-width: 1280px) {
            .vendor-orders-stats {
                grid-template-columns:
                    repeat(5, minmax(0, 1fr))
                    minmax(220px, 1.15fr);
            }

            .vendor-orders-filter-grid {
                grid-template-columns:
                    minmax(170px, 1fr)
                    minmax(180px, 1fr)
                    minmax(150px, 0.9fr)
                    minmax(150px, 0.9fr)
                    140px
                    44px;
                align-items: center;
            }

            .vendor-orders-table {
                min-width: 0;
                table-layout: fixed;
            }

            .vendor-orders-table th:nth-child(1),
            .vendor-orders-table td:nth-child(1) {
                width: 13%;
            }

            .vendor-orders-table th:nth-child(2),
            .vendor-orders-table td:nth-child(2) {
                width: 14%;
            }

            .vendor-orders-table th:nth-child(3),
            .vendor-orders-table td:nth-child(3) {
                width: 8%;
            }

            .vendor-orders-table th:nth-child(4),
            .vendor-orders-table td:nth-child(4) {
                width: 11%;
            }

            .vendor-orders-table th:nth-child(5),
            .vendor-orders-table td:nth-child(5) {
                width: 13%;
            }

            .vendor-orders-table th:nth-child(6),
            .vendor-orders-table td:nth-child(6) {
                width: 9%;
            }

            .vendor-orders-table th:nth-child(7),
            .vendor-orders-table td:nth-child(7) {
                width: 9%;
            }

            .vendor-orders-table th:nth-child(8),
            .vendor-orders-table td:nth-child(8) {
                width: 23%;
            }

            .vendor-order-actions {
                gap: 0.4rem;
            }

            .vendor-order-actions .vendor-order-action-text {
                padding-left: 0.65rem;
                padding-right: 0.65rem;
            }
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">

    <style>
        /*
        |--------------------------------------------------------------------------
        | Vendor Orders - Reference Desktop Layout
        |--------------------------------------------------------------------------
        | Normal desktop layout is preserved from 1150px upward.
        | High zoom / compact mode remains handled by responsive.css below 1150px.
        |--------------------------------------------------------------------------
        */

        html,
        body {
            max-width: 100%;
            overflow-x: hidden !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar,
        .vendor-orders-scroll::-webkit-scrollbar {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
        }

        .vendor-orders-scroll {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        @media (min-width: 1150px) {
            #vendorSidebar {
                display: flex !important;
                width: 16rem !important;
                transform: translateX(0) !important;
                visibility: visible !important;
            }

            #vendorSidebarOverlay {
                display: none !important;
            }

            #vendorHeaderMenuButton,
            #closeVendorSidebar {
                display: none !important;
            }

            .vendor-orders-page-shell {
                margin-left: 16rem !important;
                width: calc(100% - 16rem) !important;
                max-width: calc(100% - 16rem) !important;
                padding-left: 0 !important;
            }

            .vendor-orders-page-shell header {
                min-height: 4rem !important;
                height: 4rem !important;
            }

            .vendor-orders-page-shell header > div {
                min-height: 4rem !important;
                padding-left: 1.5rem !important;
                padding-right: 1.5rem !important;
            }

            .vendor-orders-page-shell header form[method="get"] {
                display: block !important;
            }

            .vendor-orders-page-shell header a[title="Open marketplace"] {
                display: grid !important;
            }

            #vendorProfileButton > span:nth-child(2),
            #vendorProfileChevron {
                display: block !important;
            }

            .vendor-orders-page-shell main {
                width: 100% !important;
                max-width: 100% !important;
                padding: 1.25rem 1.5rem 2.5rem !important;
            }

            .vendor-orders-stats {
                display: grid !important;
                grid-template-columns:
                    repeat(5, minmax(0, 1fr))
                    minmax(205px, 1.08fr) !important;
                gap: 0.75rem !important;
                width: 100% !important;
            }

            .vendor-orders-stats > article {
                min-width: 0 !important;
                min-height: 86px !important;
                padding: 1rem !important;
            }

            .vendor-orders-stats > article .h-11 {
                width: 2.75rem !important;
                height: 2.75rem !important;
            }

            .vendor-order-stat-value {
                font-size: 1.25rem !important;
                line-height: 1.05 !important;
            }

            .vendor-paid-sales-value .amount {
                font-size: 1.35rem !important;
                line-height: 1 !important;
            }

            .vendor-paid-sales-value .currency {
                font-size: .7rem !important;
            }

            /* Match the supplied filter-bar reference at 100%:
               Status wide | Payment medium | From | To | Filter wide | Reset icon */
            .vendor-orders-filter-card {
                padding: 0.95rem 1rem !important;
            }

            .vendor-orders-filter-grid {
                display: grid !important;
                grid-template-columns:
                    minmax(250px, 1.55fr)
                    minmax(190px, .82fr)
                    minmax(205px, .90fr)
                    minmax(205px, .90fr)
                    minmax(230px, 1.05fr)
                    52px !important;
                gap: .65rem !important;
                align-items: center !important;
                width: 100% !important;
            }

            .vendor-orders-filter-grid > * {
                min-width: 0 !important;
                margin: 0 !important;
            }

            .vendor-orders-filter-control,
            .vendor-orders-filter-grid > button,
            .vendor-orders-filter-grid > a {
                height: 48px !important;
                border-radius: 12px !important;
            }

            .vendor-orders-filter-control {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
                font-size: 0.95rem !important;
            }

            .vendor-orders-filter-grid > button {
                min-width: 0 !important;
                padding-left: 1rem !important;
                padding-right: 1rem !important;
                font-size: 0.95rem !important;
            }

            .vendor-orders-filter-grid > a {
                width: 52px !important;
                min-width: 52px !important;
                justify-self: end !important;
            }

            .vendor-orders-scroll {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: hidden !important;
            }

            .vendor-orders-table {
                width: 100% !important;
                min-width: 0 !important;
                table-layout: fixed !important;
            }

            .vendor-orders-table th,
            .vendor-orders-table td {
                vertical-align: middle !important;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .vendor-orders-table th:nth-child(1),
            .vendor-orders-table td:nth-child(1) { width: 13% !important; }

            .vendor-orders-table th:nth-child(2),
            .vendor-orders-table td:nth-child(2) { width: 14% !important; }

            .vendor-orders-table th:nth-child(3),
            .vendor-orders-table td:nth-child(3) { width: 8% !important; }

            .vendor-orders-table th:nth-child(4),
            .vendor-orders-table td:nth-child(4) { width: 11% !important; }

            .vendor-orders-table th:nth-child(5),
            .vendor-orders-table td:nth-child(5) { width: 13% !important; }

            .vendor-orders-table th:nth-child(6),
            .vendor-orders-table td:nth-child(6) { width: 9% !important; }

            .vendor-orders-table th:nth-child(7),
            .vendor-orders-table td:nth-child(7) { width: 9% !important; }

            .vendor-orders-table th:nth-child(8),
            .vendor-orders-table td:nth-child(8) { width: 23% !important; }

            .vendor-order-actions {
                display: flex !important;
                align-items: center !important;
                justify-content: flex-end !important;
                flex-wrap: nowrap !important;
                gap: .4rem !important;
                white-space: nowrap !important;
                overflow: visible !important;
            }

            .vendor-order-actions form {
                flex: 0 0 auto !important;
            }

            .vendor-order-action-text {
                flex: 0 0 auto !important;
                padding-left: .62rem !important;
                padding-right: .62rem !important;
            }
        }

        @media (min-width: 1150px) and (max-width: 1450px) {
            .vendor-orders-filter-grid {
                grid-template-columns:
                    minmax(210px, 1.40fr)
                    minmax(165px, .78fr)
                    minmax(175px, .82fr)
                    minmax(175px, .82fr)
                    minmax(180px, .95fr)
                    48px !important;
                gap: .5rem !important;
            }

            .vendor-orders-filter-control,
            .vendor-orders-filter-grid > button,
            .vendor-orders-filter-grid > a {
                height: 46px !important;
            }

            .vendor-orders-filter-grid > a {
                width: 48px !important;
                min-width: 48px !important;
            }
        }

        @media (max-width: 1149.98px) {
            .vendor-orders-page-shell {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .vendor-orders-scroll {
                overflow-x: auto !important;
            }

            .vendor-orders-table {
                min-width: 1080px !important;
            }
        }
    </style>

    <style>
        /* ==============================================================
           FINAL FIX - Vendor Orders filter row
           Keeps every control on ONE ROW at normal desktop / 100% zoom.
           This loads last, so it overrides responsive.css and earlier rules.
           ============================================================== */

        @media (min-width: 1150px) {
            .vendor-orders-filter-card {
                width: 100% !important;
                max-width: 100% !important;
                overflow: hidden !important;
                padding: 14px 16px !important;
            }

            .vendor-orders-filter-grid {
                display: flex !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
                gap: 10px !important;
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
            }

            .vendor-orders-filter-grid > * {
                min-width: 0 !important;
                margin: 0 !important;
            }

            /* Status - widest */
            .vendor-orders-filter-grid > select[name="status"] {
                flex: 1.45 1 0 !important;
                width: auto !important;
            }

            /* Payment */
            .vendor-orders-filter-grid > select[name="payment_method"] {
                flex: .82 1 0 !important;
                width: auto !important;
            }

            /* Dates */
            .vendor-orders-filter-grid > input[name="date_from"],
            .vendor-orders-filter-grid > input[name="date_to"] {
                flex: .88 1 0 !important;
                width: auto !important;
            }

            /* Green filter button */
            .vendor-orders-filter-grid > button[type="submit"] {
                flex: .72 1 0 !important;
                width: auto !important;
                min-width: 118px !important;
            }

            /* Reset button */
            .vendor-orders-filter-grid > a[aria-label="Clear filters"] {
                flex: 0 0 48px !important;
                width: 48px !important;
                min-width: 48px !important;
                max-width: 48px !important;
            }

            .vendor-orders-filter-control,
            .vendor-orders-filter-grid > button,
            .vendor-orders-filter-grid > a {
                height: 46px !important;
                border-radius: 12px !important;
            }

            .vendor-orders-filter-control {
                padding-left: 14px !important;
                padding-right: 14px !important;
                font-size: 14px !important;
            }

            .vendor-orders-filter-grid > button {
                padding-left: 14px !important;
                padding-right: 14px !important;
                font-size: 14px !important;
            }
        }

        /* Slightly tighter desktop width / 110%-125% zoom */
        @media (min-width: 1150px) and (max-width: 1380px) {
            .vendor-orders-filter-grid {
                gap: 7px !important;
            }

            .vendor-orders-filter-grid > button[type="submit"] {
                min-width: 105px !important;
            }

            .vendor-orders-filter-control {
                padding-left: 11px !important;
                padding-right: 11px !important;
                font-size: 13px !important;
            }

            .vendor-orders-filter-grid > a[aria-label="Clear filters"] {
                flex-basis: 44px !important;
                width: 44px !important;
                min-width: 44px !important;
                max-width: 44px !important;
            }
        }
    </style>

</head>

<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">

<div class="min-h-screen">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="vendor-orders-page-shell min-h-screen">
        <?php require __DIR__ . '/header.php'; ?>

        <main class="px-4 pb-10 pt-5 sm:px-6 xl:px-7">

            <?php if ($flash): ?>

                <div class="mb-5 rounded-2xl border px-4 py-3 text-sm font-bold
                    <?php echo $flash['type'] === 'success'
                        ? 'border-green-200 bg-green-50 text-green-800'
                        : 'border-red-200 bg-red-50 text-red-800'; ?>">

                    <?php echo vendor_page_e($flash['message']); ?>
                </div>

            <?php endif; ?>


            <?php if ((int) $stats['pending_orders'] > 0): ?>

                <section class="mb-5 flex flex-col gap-4 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">

                    <div class="flex min-w-0 items-center gap-3">

                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-amber-100 text-amber-600">
                            <i class="fa-solid fa-bell"></i>
                        </span>

                        <div class="min-w-0">
                            <p class="text-sm font-black text-amber-950">
                                New Orders Waiting
                            </p>

                            <p class="mt-1 text-xs leading-5 text-amber-700">
                                You have
                                <strong>
                                    <?php echo number_format(
                                        (int) $stats['pending_orders']
                                    ); ?>
                                </strong>
                                pending order<?php echo (int) $stats['pending_orders'] === 1 ? '' : 's'; ?>
                                waiting for confirmation or rejection.
                            </p>
                        </div>
                    </div>

                    <a href="orders.php?status=pending"
                       class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl bg-amber-500 px-4 text-xs font-black text-white transition hover:bg-amber-600">
                        <i class="fa-solid fa-eye text-[10px]"></i>
                        View Pending Orders
                    </a>
                </section>

            <?php endif; ?>

            <section class="vendor-orders-stats">
                <?php
                $orderStats = array(
                    array(
                        'label' => 'Total Orders',
                        'value' => number_format((int) $stats['total_orders']),
                        'icon' => 'fa-clipboard-list',
                        'class' => 'bg-blue-100 text-blue-600'
                    ),
                    array(
                        'label' => 'Pending',
                        'value' => number_format((int) $stats['pending_orders']),
                        'icon' => 'fa-clock',
                        'class' => 'bg-amber-100 text-amber-600'
                    ),
                    array(
                        'label' => 'Confirmed',
                        'value' => number_format((int) $stats['confirmed_orders']),
                        'icon' => 'fa-circle-check',
                        'class' => 'bg-green-100 text-green-600'
                    ),
                    array(
                        'label' => 'Completed',
                        'value' => number_format((int) $stats['completed_orders']),
                        'icon' => 'fa-flag-checkered',
                        'class' => 'bg-blue-100 text-blue-600'
                    ),
                    array(
                        'label' => 'Rejected',
                        'value' => number_format((int) $stats['rejected_orders']),
                        'icon' => 'fa-circle-xmark',
                        'class' => 'bg-red-100 text-red-600'
                    ),
                    array(
                        'label' => 'Paid Sales',
                        'value' => number_format((float) $stats['paid_sales']),
                        'currency' => 'MMK',
                        'icon' => 'fa-coins',
                        'class' => 'bg-emerald-100 text-emerald-600',
                        'is_sales' => true
                    )
                );
                ?>

                <?php foreach ($orderStats as $item): ?>
                    <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                        <div class="flex items-center gap-3">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full <?php echo vendor_page_e($item['class']); ?>">
                                <i class="fa-solid <?php echo vendor_page_e($item['icon']); ?>"></i>
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="whitespace-nowrap text-xs font-semibold text-slate-500">
                                    <?php echo vendor_page_e($item['label']); ?>
                                </p>

                                <?php if (!empty($item['is_sales'])): ?>
                                    <div class="vendor-paid-sales-value mt-1">
                                        <span class="amount">
                                            <?php echo vendor_page_e($item['value']); ?>
                                        </span>

                                        <span class="currency">
                                            <?php echo vendor_page_e($item['currency']); ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <p class="vendor-order-stat-value mt-1 font-extrabold text-slate-950">
                                        <?php echo vendor_page_e($item['value']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="vendor-orders-filter-card mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                <form method="get"
                      action="orders.php"
                      class="vendor-orders-filter-grid">

                    <?php if ($search !== ''): ?>
                        <input type="hidden"
                               name="q"
                               value="<?php echo vendor_page_e($search); ?>">
                    <?php endif; ?>

                    <select name="status"
                            class="vendor-orders-filter-control rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>

                    <select name="payment_method"
                            class="vendor-orders-filter-control rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        <option value="all" <?php echo $paymentFilter === 'all' ? 'selected' : ''; ?>>All payments</option>
                        <option value="kbzpay" <?php echo $paymentFilter === 'kbzpay' ? 'selected' : ''; ?>>KBZPay</option>
                        <option value="wave_money" <?php echo $paymentFilter === 'wave_money' ? 'selected' : ''; ?>>Wave Money</option>
                    </select>

                    <input type="date"
                           name="date_from"
                           value="<?php echo vendor_page_e($dateFrom); ?>"
                           title="From date"
                           class="vendor-orders-filter-control rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                    <input type="date"
                           name="date_to"
                           value="<?php echo vendor_page_e($dateTo); ?>"
                           title="To date"
                           class="vendor-orders-filter-control rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                    <button type="submit"
                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-sm font-bold text-white transition hover:bg-green-700">
                        <i class="fa-solid fa-filter text-xs"></i>
                        Filter
                    </button>

                    <a href="orders.php"
                       title="Clear filters"
                       aria-label="Clear filters"
                       class="grid h-11 w-11 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-800">
                        <i class="fa-solid fa-rotate-left text-xs"></i>
                    </a>
                </form>
            </section>

            <section class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <h2 class="font-bold text-slate-950">Order List</h2>

                        <p class="mt-1 text-xs text-slate-400">
                            Showing <?php echo number_format($showingFrom); ?>–<?php echo number_format($showingTo); ?>
                            of <?php echo number_format($totalFilteredOrders); ?> orders
                        </p>
                    </div>

                    <span class="rounded-lg bg-green-50 px-3 py-2 text-[11px] font-semibold text-green-700">
                        <i class="fa-solid fa-store mr-1"></i>
                        Vendor-controlled
                    </span>
                </div>

                <div class="vendor-orders-scroll overflow-x-auto">
                    <table class="vendor-orders-table text-left">
                        <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Order</th>
                                <th class="px-4 py-3 font-semibold">Customer</th>
                                <th class="px-4 py-3 font-semibold">Items</th>
                                <th class="px-4 py-3 font-semibold">Vendor Total</th>
                                <th class="px-4 py-3 font-semibold">Payment</th>
                                <th class="px-4 py-3 font-semibold">Status</th>
                                <th class="px-4 py-3 font-semibold">Date</th>
                                <th class="px-5 py-3 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($orders)): ?>
                                <tr>
                                    <td colspan="8" class="px-5 py-16 text-center">
                                        <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-green-50 text-green-600">
                                            <i class="fa-solid fa-bag-shopping text-xl"></i>
                                        </div>

                                        <p class="mt-4 text-sm font-bold text-slate-700">No orders found</p>
                                        <p class="mt-1 text-xs text-slate-400">No orders containing your products match the filter.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($orders as $order): ?>
                                    <?php
                                    $orderStatus = strtolower((string) $order['order_status']);
                                    $statusClass = isset($statusClasses[$orderStatus])
                                        ? $statusClasses[$orderStatus]
                                        : 'bg-slate-50 text-slate-700 ring-slate-200';

                                    $paymentMethod =
                                        strtolower(
                                            trim(
                                                (string) $order['payment_method']
                                            )
                                        );

                                    $paymentLabel =
                                        isset($paymentLabels[$paymentMethod])
                                            ? $paymentLabels[$paymentMethod]
                                            : 'Not selected yet';

                                    $paymentStatus =
                                        strtolower(
                                            trim(
                                                (string) $order['payment_status']
                                            )
                                        );

                                    if ($paymentStatus === '') {
                                        $paymentStatus = 'unpaid';
                                    }

                                    $paymentStatusLabel =
                                        isset($paymentStatusLabels[$paymentStatus])
                                            ? $paymentStatusLabels[$paymentStatus]
                                            : ucfirst(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $paymentStatus
                                                )
                                            );

                                    $paymentStatusClass =
                                        isset($paymentStatusClasses[$paymentStatus])
                                            ? $paymentStatusClasses[$paymentStatus]
                                            : 'bg-slate-50 text-slate-700 ring-slate-200';
                                    ?>

                                    <tr class="hover:bg-slate-50/70">
                                        <td class="px-5 py-4">
                                            <p class="text-xs font-extrabold text-green-700">
                                                Vendor Order #<?php echo number_format((int) $order['vendor_order_id']); ?>
                                            </p>

                                            <p class="mt-1 text-[10px] text-slate-400">
                                                Master Order
                                                #<?php echo number_format((int) $order['purchase_id']); ?>
                                                ·
                                                <?php echo vendor_page_e(vendor_page_date($order['created_at'], 'g:i A')); ?>
                                            </p>
                                        </td>

                                        <td class="px-4 py-4">
                                            <div class="flex items-center gap-2">
                                                <span class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-[10px] font-extrabold text-slate-600">
                                                    <?php echo vendor_page_e(vendor_page_initials($order['customer_name'])); ?>
                                                </span>

                                                <div class="min-w-0">
                                                    <p class="max-w-36 truncate text-xs font-bold text-slate-700">
                                                        <?php echo vendor_page_e($order['customer_name']); ?>
                                                    </p>

                                                    <p class="max-w-36 truncate text-[10px] text-slate-400">
                                                        <?php echo vendor_page_e($order['customer_email']); ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="px-4 py-4">
                                            <p class="text-xs font-bold text-slate-700">
                                                <?php echo number_format((int) $order['total_quantity']); ?> units
                                            </p>

                                            <p class="mt-1 text-[10px] text-slate-400">
                                                <?php echo number_format((int) $order['product_count']); ?> products
                                            </p>
                                        </td>

                                        <td class="px-4 py-4 text-xs font-extrabold text-slate-800">
                                            <?php echo number_format((float) $order['vendor_total']); ?> MMK
                                        </td>

                                        <td class="px-4 py-4">

                                            <p class="text-xs font-semibold text-slate-600">
                                                <?php echo vendor_page_e($paymentLabel); ?>
                                            </p>

                                            <span class="mt-1.5 inline-flex rounded-md px-2 py-1 text-[9px] font-bold ring-1 ring-inset <?php echo vendor_page_e($paymentStatusClass); ?>">
                                                <?php echo vendor_page_e($paymentStatusLabel); ?>
                                            </span>
                                        </td>

                                        <td class="px-4 py-4">
                                            <span class="inline-flex rounded-md px-2 py-1 text-[10px] font-bold capitalize ring-1 ring-inset <?php echo vendor_page_e($statusClass); ?>">
                                                <?php echo vendor_page_e($orderStatus); ?>
                                            </span>
                                        </td>

                                        <td class="px-4 py-4 text-xs text-slate-500">
                                            <?php echo vendor_page_e(vendor_page_date($order['created_at'], 'M j, Y')); ?>
                                        </td>

                                        <td class="px-5 py-4">
                                            <div class="vendor-order-actions flex items-center justify-end gap-2">

                                                <a href="<?php echo vendor_page_e(
                                                    vendor_order_url(array(
                                                        'view' => (int) $order['vendor_order_id']
                                                    ))
                                                ); ?>"
                                                   title="View order"
                                                   class="inline-grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700">

                                                    <i class="fa-regular fa-eye text-xs"></i>
                                                </a>

                                                <?php if ($orderStatus === 'pending'): ?>

                                                    <form method="post"
                                                          action="orders.php"
                                                          class="inline">

                                                        <input type="hidden"
                                                               name="csrf_token"
                                                               value="<?php echo vendor_page_e($csrfToken); ?>">

                                                        <input type="hidden"
                                                               name="action"
                                                               value="confirm">

                                                        <input type="hidden"
                                                               name="vendor_order_id"
                                                               value="<?php echo (int) $order['vendor_order_id']; ?>">

                                                        <button type="submit"
                                                                title="Confirm order"
                                                                onclick="return confirm('Confirm this vendor order?');"
                                                                class="vendor-order-action-text inline-flex h-9 items-center justify-center gap-1.5 rounded-lg bg-green-600 px-3 text-[11px] font-bold text-white transition hover:bg-green-700">

                                                            <i class="fa-solid fa-check text-[10px]"></i>
                                                            Confirm
                                                        </button>
                                                    </form>

                                                    <button type="button"
                                                            title="Reject order"
                                                            onclick="openVendorRejectModal(<?php echo (int) $order['vendor_order_id']; ?>)"
                                                            class="vendor-order-action-text inline-flex h-9 items-center justify-center gap-1.5 rounded-lg bg-red-50 px-3 text-[11px] font-bold text-red-600 ring-1 ring-inset ring-red-200 transition hover:bg-red-100">

                                                        <i class="fa-solid fa-xmark text-[10px]"></i>
                                                        Reject
                                                    </button>

                                                <?php elseif ($orderStatus === 'confirmed'): ?>

                                                    <?php if ($paymentStatus === 'paid'): ?>

                                                        <form method="post"
                                                              action="orders.php"
                                                              class="inline">

                                                            <input type="hidden"
                                                                   name="csrf_token"
                                                                   value="<?php echo vendor_page_e($csrfToken); ?>">

                                                            <input type="hidden"
                                                                   name="action"
                                                                   value="complete">

                                                            <input type="hidden"
                                                                   name="vendor_order_id"
                                                                   value="<?php echo (int) $order['vendor_order_id']; ?>">

                                                            <button type="submit"
                                                                    title="Mark order as completed"
                                                                    onclick="return confirm('Mark this vendor order as completed after fulfillment/delivery?');"
                                                                    class="vendor-order-action-text inline-flex h-9 items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-3 text-[11px] font-bold text-white transition hover:bg-blue-700">

                                                                <i class="fa-solid fa-flag-checkered text-[10px]"></i>
                                                                Complete
                                                            </button>
                                                        </form>

                                                    <?php elseif ($paymentStatus === 'pending_verification'): ?>

                                                        <span class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-blue-50 px-3 text-[10px] font-bold text-blue-700 ring-1 ring-inset ring-blue-200">
                                                            <i class="fa-solid fa-hourglass-half text-[9px]"></i>
                                                            Payment Review
                                                        </span>

                                                    <?php elseif ($paymentStatus === 'rejected'): ?>

                                                        <span class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-red-50 px-3 text-[10px] font-bold text-red-700 ring-1 ring-inset ring-red-200">
                                                            <i class="fa-solid fa-triangle-exclamation text-[9px]"></i>
                                                            Payment Rejected
                                                        </span>

                                                    <?php else: ?>

                                                        <span class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-amber-50 px-3 text-[10px] font-bold text-amber-700 ring-1 ring-inset ring-amber-200">
                                                            <i class="fa-solid fa-wallet text-[9px]"></i>
                                                            Waiting Payment
                                                        </span>

                                                    <?php endif; ?>

                                                <?php elseif ($orderStatus === 'completed'): ?>

                                                    <span class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-blue-50 px-3 text-[11px] font-bold text-blue-700 ring-1 ring-inset ring-blue-200">

                                                        <i class="fa-solid fa-flag-checkered text-[10px]"></i>
                                                        Completed
                                                    </span>

                                                <?php elseif ($orderStatus === 'rejected'): ?>

                                                    <span class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-red-50 px-3 text-[11px] font-bold text-red-700 ring-1 ring-inset ring-red-200">

                                                        <i class="fa-solid fa-circle-xmark text-[10px]"></i>
                                                        Rejected
                                                    </span>

                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-slate-400">
                            Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?>
                        </p>

                        <div class="flex items-center gap-1">
                            <a href="<?php echo vendor_page_e(vendor_order_url(array('page' => max(1, $page - 1)))); ?>"
                               class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50 <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : ''; ?>">
                                <i class="fa-solid fa-chevron-left text-[9px]"></i>
                                Previous
                            </a>

                            <?php for ($pageNumber = max(1, $page - 2); $pageNumber <= min($totalPages, $page + 2); $pageNumber++): ?>
                                <a href="<?php echo vendor_page_e(vendor_order_url(array('page' => $pageNumber))); ?>"
                                   class="grid h-9 w-9 place-items-center rounded-lg text-xs font-bold <?php echo $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50'; ?>">
                                    <?php echo $pageNumber; ?>
                                </a>
                            <?php endfor; ?>

                            <a href="<?php echo vendor_page_e(vendor_order_url(array('page' => min($totalPages, $page + 1)))); ?>"
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

<!-- Reject Vendor Order Modal -->
<div id="vendorRejectModal"
     class="fixed inset-0 z-[90] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">

    <div class="w-full max-w-md overflow-hidden rounded-2xl border border-red-100 bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">

            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-red-500">
                    Vendor Order
                </p>

                <h2 class="mt-1 text-lg font-extrabold text-slate-950">
                    Reject Order
                </h2>
            </div>

            <button type="button"
                    onclick="closeVendorRejectModal()"
                    class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">

                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post"
              action="orders.php"
              class="p-5">

            <input type="hidden"
                   name="csrf_token"
                   value="<?php echo vendor_page_e($csrfToken); ?>">

            <input type="hidden"
                   name="action"
                   value="reject">

            <input type="hidden"
                   id="rejectVendorOrderId"
                   name="vendor_order_id"
                   value="">

            <label for="rejectReason"
                   class="block text-xs font-bold text-slate-700">

                Rejection Reason
            </label>

            <textarea id="rejectReason"
                      name="rejection_reason"
                      rows="4"
                      maxlength="255"
                      required
                      placeholder="e.g. Product is unavailable or out of stock"
                      class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-3 text-sm outline-none transition focus:border-red-400 focus:ring-2 focus:ring-red-100"></textarea>

            <p class="mt-2 text-[10px] leading-5 text-slate-400">
                When you reject this order, the reserved product quantity will be returned to stock.
            </p>

            <div class="mt-5 flex justify-end gap-2">

                <button type="button"
                        onclick="closeVendorRejectModal()"
                        class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600 hover:bg-slate-50">

                    Cancel
                </button>

                <button type="submit"
                        onclick="return confirm('Reject this vendor order?');"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-red-600 px-4 text-xs font-black text-white hover:bg-red-700">

                    <i class="fa-solid fa-circle-xmark"></i>
                    Reject Order
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openVendorRejectModal(vendorOrderId) {
    var modal = document.getElementById('vendorRejectModal');
    var input = document.getElementById('rejectVendorOrderId');
    var reason = document.getElementById('rejectReason');

    if (!modal || !input) {
        return;
    }

    input.value = vendorOrderId;

    if (reason) {
        reason.value = '';
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');

    if (reason) {
        setTimeout(function () {
            reason.focus();
        }, 100);
    }
}

function closeVendorRejectModal() {
    var modal = document.getElementById('vendorRejectModal');

    if (!modal) {
        return;
    }

    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeVendorRejectModal();
    }
});
</script>

<?php if ($selectedOrder): ?>
    <?php
    $selectedStatus = strtolower((string) $selectedOrder['order_status']);
    $selectedStatusClass = isset($statusClasses[$selectedStatus])
        ? $statusClasses[$selectedStatus]
        : 'bg-slate-50 text-slate-700 ring-slate-200';

    $selectedPayment =
        strtolower(
            trim(
                (string) $selectedOrder['payment_method']
            )
        );

    $selectedPaymentLabel =
        isset($paymentLabels[$selectedPayment])
            ? $paymentLabels[$selectedPayment]
            : 'Not selected yet';

    $selectedPaymentStatus =
        strtolower(
            trim(
                (string) $selectedOrder['payment_status']
            )
        );

    if ($selectedPaymentStatus === '') {
        $selectedPaymentStatus = 'unpaid';
    }

    $selectedPaymentStatusLabel =
        isset($paymentStatusLabels[$selectedPaymentStatus])
            ? $paymentStatusLabels[$selectedPaymentStatus]
            : ucfirst(
                str_replace(
                    '_',
                    ' ',
                    $selectedPaymentStatus
                )
            );

    $selectedPaymentStatusClass =
        isset($paymentStatusClasses[$selectedPaymentStatus])
            ? $paymentStatusClasses[$selectedPaymentStatus]
            : 'bg-slate-50 text-slate-700 ring-slate-200';
    ?>

    <div class="vendor-orders-scroll fixed inset-0 z-[70] overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm">
        <div class="mx-auto flex min-h-full max-w-4xl items-center justify-center">
            <article class="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-green-600">Order Details</p>

                        <div class="mt-1 flex items-center gap-3">
                            <h2 class="text-xl font-extrabold text-slate-950">
                                Vendor Order #<?php echo number_format((int) $selectedOrder['vendor_order_id']); ?>
                            </h2>

                            <span class="inline-flex rounded-md px-2 py-1 text-[10px] font-bold capitalize ring-1 ring-inset <?php echo vendor_page_e($selectedStatusClass); ?>">
                                <?php echo vendor_page_e($selectedStatus); ?>
                            </span>
                        </div>

                        <p class="mt-1 text-[10px] text-slate-400">
                            Master Order
                            #<?php echo number_format((int) $selectedOrder['purchase_id']); ?>
                        </p>
                    </div>

                    <a href="<?php echo vendor_page_e(vendor_order_url(array())); ?>"
                       class="grid h-10 w-10 place-items-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                </div>

                <div class="vendor-orders-scroll max-h-[80vh] overflow-y-auto p-5">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl bg-slate-50 p-4">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Customer</p>
                            <p class="mt-2 truncate text-sm font-bold text-slate-700">
                                <?php echo vendor_page_e($selectedOrder['customer_name']); ?>
                            </p>
                            <p class="mt-1 truncate text-[10px] text-slate-400">
                                <?php echo vendor_page_e($selectedOrder['customer_email']); ?>
                            </p>
                        </div>

                        <div class="rounded-xl bg-slate-50 p-4">

                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                Payment
                            </p>

                            <p class="mt-2 text-sm font-bold text-slate-700">
                                <?php echo vendor_page_e($selectedPaymentLabel); ?>
                            </p>

                            <span class="mt-2 inline-flex rounded-md px-2 py-1 text-[9px] font-bold ring-1 ring-inset <?php echo vendor_page_e($selectedPaymentStatusClass); ?>">
                                <?php echo vendor_page_e($selectedPaymentStatusLabel); ?>
                            </span>

                            <?php if (
                                $selectedPaymentStatus === 'paid' &&
                                isset($selectedOrder['verified_at']) &&
                                $selectedOrder['verified_at'] !== null
                            ): ?>

                                <p class="mt-2 text-[9px] text-green-600">
                                    Admin verified:
                                    <?php echo vendor_page_e(
                                        vendor_page_date(
                                            $selectedOrder['verified_at'],
                                            'M j, Y · g:i A'
                                        )
                                    ); ?>
                                </p>

                            <?php endif; ?>
                        </div>

                        <div class="rounded-xl bg-slate-50 p-4">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Vendor Amount</p>
                            <p class="mt-2 text-sm font-bold text-green-700">
                                <?php echo number_format((float) $selectedOrder['vendor_total']); ?> MMK
                            </p>
                        </div>

                        <div class="rounded-xl bg-slate-50 p-4">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Ordered</p>
                            <p class="mt-2 text-sm font-bold text-slate-700">
                                <?php echo vendor_page_e(vendor_page_date($selectedOrder['created_at'], 'M j, Y')); ?>
                            </p>
                            <p class="mt-1 text-[10px] text-slate-400">
                                <?php echo vendor_page_e(vendor_page_date($selectedOrder['created_at'], 'g:i A')); ?>
                            </p>
                        </div>
                    </div>

                    <section class="mt-5 rounded-xl border border-green-200 bg-green-50 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-green-600">Fulfillment</p>
                                <p class="mt-1 text-sm font-black text-green-950"><?php echo vendor_page_e(fm_fulfillment_label($selectedOrder['fulfillment_type'])); ?></p>
                            </div>
                            <?php if (strtolower((string) $selectedOrder['fulfillment_type']) === 'delivery'): ?>
                                <div class="min-w-0 sm:text-right">
                                    <p class="text-xs font-bold text-slate-800"><?php echo vendor_page_e($selectedOrder['delivery_name']); ?> · <?php echo vendor_page_e($selectedOrder['delivery_phone']); ?></p>
                                    <p class="mt-1 max-w-xl text-xs leading-5 text-slate-600"><?php echo vendor_page_e($selectedOrder['delivery_address']); ?></p>
                                </div>
                            <?php else: ?>
                                <p class="text-xs text-green-700">Coordinate pickup with the customer using the contact information above.</p>
                            <?php endif; ?>
                        </div>
                        <?php if (trim((string) $selectedOrder['customer_note']) !== ''): ?>
                            <p class="mt-3 border-t border-green-200 pt-3 text-xs text-green-800"><strong>Customer note:</strong> <?php echo vendor_page_e($selectedOrder['customer_note']); ?></p>
                        <?php endif; ?>
                    </section>

                    <div class="mt-5 overflow-hidden rounded-xl border border-slate-200">
                        <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                            <h3 class="text-sm font-bold text-slate-800">Your Products in This Order</h3>
                        </div>

                        <div class="divide-y divide-slate-100">
                            <?php foreach ($selectedItems as $item): ?>
                                <?php $itemPhoto = vendor_page_photo_url($item['product_photo']); ?>

                                <div class="flex items-center gap-4 p-4">
                                    <div class="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-green-50">
                                        <?php if ($itemPhoto !== ''): ?>
                                            <img src="<?php echo vendor_page_e($itemPhoto); ?>"
                                                 alt="<?php echo vendor_page_e($item['product_name']); ?>"
                                                 class="h-full w-full object-cover">
                                        <?php else: ?>
                                            <div class="grid h-full place-items-center text-green-300">
                                                <i class="fa-solid fa-basket-shopping"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-bold text-slate-800">
                                            <?php echo vendor_page_e($item['product_name']); ?>
                                        </p>

                                        <p class="mt-1 truncate text-[10px] text-slate-400">
                                            <?php echo vendor_page_e((string) $item['category_name'] . ' · ' . (string) $item['market_name']); ?>
                                        </p>
                                    </div>

                                    <div class="text-right">
                                        <p class="text-xs font-bold text-slate-700">
                                            <?php echo number_format((int) $item['quantity']); ?> ×
                                            <?php echo number_format((float) $item['unit_price']); ?> / <?php echo vendor_page_e(fm_unit_label($item['unit'])); ?>
                                        </p>

                                        <p class="mt-1 text-sm font-extrabold text-green-700">
                                            <?php echo number_format((float) $item['subtotal']); ?> MMK
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($selectedStatus === 'pending'): ?>

                        <div class="mt-5 grid gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">

                            <div>

                                <p class="text-sm font-black text-amber-900">
                                    Respond to this order
                                </p>

                                <p class="mt-1 text-xs leading-5 text-amber-700">
                                    Confirm if you can fulfill the order.
                                    Reject only when you cannot fulfill it.
                                </p>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">

                                <form method="post"
                                      action="orders.php">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?php echo vendor_page_e($csrfToken); ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="confirm">

                                    <input type="hidden"
                                           name="vendor_order_id"
                                           value="<?php echo (int) $selectedOrder['vendor_order_id']; ?>">

                                    <button type="submit"
                                            onclick="return confirm('Confirm this vendor order?');"
                                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-sm font-black text-white hover:bg-green-700">

                                        <i class="fa-solid fa-circle-check"></i>

                                        Confirm Order
                                    </button>
                                </form>

                                <form method="post"
                                      action="orders.php"
                                      class="rounded-xl border border-red-200 bg-white p-3">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?php echo vendor_page_e($csrfToken); ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="reject">

                                    <input type="hidden"
                                           name="vendor_order_id"
                                           value="<?php echo (int) $selectedOrder['vendor_order_id']; ?>">

                                    <label class="mb-2 block text-[10px] font-black uppercase tracking-wider text-red-500">
                                        Rejection reason
                                    </label>

                                    <textarea name="rejection_reason"
                                              rows="3"
                                              maxlength="255"
                                              required
                                              placeholder="e.g. Product is unavailable"
                                              class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs outline-none focus:border-red-400 focus:ring-2 focus:ring-red-100"></textarea>

                                    <button type="submit"
                                            onclick="return confirm('Reject this vendor order? Reserved stock will be restored.');"
                                            class="mt-3 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-red-600 px-4 text-xs font-black text-white hover:bg-red-700">

                                        <i class="fa-solid fa-circle-xmark"></i>

                                        Reject Order
                                    </button>
                                </form>
                            </div>
                        </div>

                    <?php elseif ($selectedStatus === 'confirmed'): ?>

                        <?php if ($selectedPaymentStatus === 'paid'): ?>

                            <div class="mt-5 rounded-2xl border border-green-200 bg-green-50 p-4">

                                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                                    <div>

                                        <p class="text-sm font-black text-green-900">
                                            Payment verified · Order ready to fulfill
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-green-700">
                                            Admin verified the customer payment.
                                            After you finish preparing/delivering this order,
                                            mark it Completed.
                                        </p>
                                    </div>

                                    <form method="post"
                                          action="orders.php"
                                          class="shrink-0">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?php echo vendor_page_e($csrfToken); ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="complete">

                                        <input type="hidden"
                                               name="vendor_order_id"
                                               value="<?php echo (int) $selectedOrder['vendor_order_id']; ?>">

                                        <button type="submit"
                                                onclick="return confirm('Mark this vendor order as completed after fulfillment/delivery?');"
                                                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-black text-white hover:bg-blue-700">

                                            <i class="fa-solid fa-flag-checkered"></i>
                                            Mark Completed
                                        </button>
                                    </form>
                                </div>
                            </div>

                        <?php elseif ($selectedPaymentStatus === 'pending_verification'): ?>

                            <div class="mt-5 rounded-2xl border border-blue-200 bg-blue-50 p-4">

                                <div class="flex gap-3">

                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-600 text-white">
                                        <i class="fa-solid fa-hourglass-half"></i>
                                    </span>

                                    <div>
                                        <p class="text-sm font-black text-blue-900">
                                            Payment is under Admin verification
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-blue-700">
                                            The customer submitted a payment proof.
                                            Wait until Admin changes Payment Status to Paid.
                                            Complete is locked until then.
                                        </p>
                                    </div>
                                </div>
                            </div>

                        <?php elseif ($selectedPaymentStatus === 'rejected'): ?>

                            <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4">

                                <div class="flex gap-3">

                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-red-600 text-white">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                    </span>

                                    <div>
                                        <p class="text-sm font-black text-red-900">
                                            Customer payment was rejected
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-red-700">
                                            Wait for the customer to submit a valid payment proof
                                            and for Admin to verify it.
                                        </p>
                                    </div>
                                </div>
                            </div>

                        <?php else: ?>

                            <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">

                                <div class="flex gap-3">

                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-500 text-white">
                                        <i class="fa-solid fa-wallet"></i>
                                    </span>

                                    <div>
                                        <p class="text-sm font-black text-amber-900">
                                            Waiting for customer payment
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-amber-700">
                                            Your Vendor Order is confirmed, but payment has not been verified yet.
                                            Complete will become available only after Payment Status is Paid.
                                        </p>
                                    </div>
                                </div>
                            </div>

                        <?php endif; ?>

                    <?php elseif ($selectedStatus === 'completed'): ?>

                        <div class="mt-5 rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs leading-5 text-blue-700">

                            <i class="fa-solid fa-flag-checkered mr-1"></i>

                            This vendor order has been
                            <strong>completed</strong>.

                            <?php if (
                                isset($selectedOrder['completed_at']) &&
                                $selectedOrder['completed_at'] !== null
                            ): ?>

                                <span class="ml-1 text-blue-500">
                                    <?php echo vendor_page_e(
                                        vendor_page_date(
                                            $selectedOrder['completed_at'],
                                            'M j, Y · g:i A'
                                        )
                                    ); ?>
                                </span>

                            <?php endif; ?>
                        </div>

                    <?php else: ?>

                        <div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-xs leading-5 text-red-700">

                            <i class="fa-solid fa-circle-xmark mr-1"></i>

                            This vendor order has been
                            <strong><?php echo vendor_page_e($selectedStatus); ?></strong>.

                            <?php if (
                                $selectedStatus === 'rejected' &&
                                trim((string) $selectedOrder['rejection_reason']) !== ''
                            ): ?>

                                <div class="mt-2 rounded-lg bg-white/70 px-3 py-2">
                                    <strong>Reason:</strong>
                                    <?php echo vendor_page_e(
                                        $selectedOrder['rejection_reason']
                                    ); ?>
                                </div>

                            <?php endif; ?>
                        </div>

                    <?php endif; ?>
                </div>
            </article>
        </div>
    </div>
<?php endif; ?>

</body>
</html>