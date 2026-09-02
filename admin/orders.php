<?php
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

function ordersQueryString(array $changes): string
{
    $current = [
        'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
        'status' => isset($_GET['status']) ? (string) $_GET['status'] : 'all',
        'date_from' => isset($_GET['date_from']) ? (string) $_GET['date_from'] : '',
        'date_to' => isset($_GET['date_to']) ? (string) $_GET['date_to'] : '',
        'sort' => isset($_GET['sort']) ? (string) $_GET['sort'] : 'newest',
        'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    ];

    foreach ($changes as $key => $value) {
        $current[$key] = $value;
    }

    return http_build_query($current);
}

function cleanOrdersReturnQuery($raw): string
{
    $parsed = [];
    parse_str((string) $raw, $parsed);

    $allowed = ['q', 'status', 'date_from', 'date_to', 'sort', 'page'];
    $clean = [];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $parsed) && !is_array($parsed[$key])) {
            $clean[$key] = (string) $parsed[$key];
        }
    }

    return http_build_query($clean);
}

function validOrderDate($value): bool
{
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTime && $date->format('Y-m-d') === $value;
}

function orderNumber(int $purchaseId): string
{
    return 'ORD-' . str_pad((string) $purchaseId, 6, '0', STR_PAD_LEFT);
}

function orderStatusLabel($status): string
{
    $status = strtolower((string) $status);

    if ($status === 'confirmed') {
        return 'Confirmed';
    }

    if ($status === 'completed') {
        return 'Completed';
    }

    if ($status === 'rejected') {
        return 'Rejected';
    }

    return 'Pending';
}

function orderStatusClass($status): string
{
    $status = strtolower((string) $status);

    if ($status === 'confirmed') {
        return 'bg-green-50 text-green-700 ring-green-600/10';
    }

    if ($status === 'completed') {
        return 'bg-blue-50 text-blue-700 ring-blue-600/10';
    }

    if ($status === 'rejected') {
        return 'bg-red-50 text-red-700 ring-red-600/10';
    }

    return 'bg-amber-50 text-amber-700 ring-amber-600/10';
}

function orderStatusIcon($status): string
{
    $status = strtolower((string) $status);

    if ($status === 'confirmed') {
        return 'fa-circle-check';
    }

    if ($status === 'completed') {
        return 'fa-flag-checkered';
    }

    if ($status === 'rejected') {
        return 'fa-circle-xmark';
    }

    return 'fa-clock';
}

function paymentMethodLabel($method): string
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

function paymentMethodClass($method): string
{
    $method = strtolower(trim((string) $method));

    if ($method === 'kbzpay') {
        return 'bg-blue-50 text-blue-700';
    }

    if ($method === 'wave_money') {
        return 'bg-yellow-50 text-yellow-700';
    }

    return 'bg-slate-100 text-slate-600';
}

function paymentStatusLabel($status): string
{
    $status = strtolower(trim((string) $status));

    if ($status === 'pending_verification') {
        return 'Pending Verification';
    }

    if ($status === 'paid') {
        return 'Successful';
    }

    if ($status === 'rejected') {
        return 'Payment Rejected';
    }

    return 'Unpaid';
}

function paymentStatusClass($status): string
{
    $status = strtolower(trim((string) $status));

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

function orderCustomerInitials($name): string
{
    $name = trim((string) $name);

    if ($name === '') {
        return 'G';
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

function paymentSlipUrl($purchaseId, $path): string
{
    if ((int) $purchaseId <= 0 || trim((string) $path) === '') {
        return '';
    }

    /* Payment proofs are streamed through an authenticated endpoint. */
    return '../payment_slip.php?purchase_id=' . (int) $purchaseId;
}

function adminOrderPaymentCsrfToken(): string
{
    if (
        !isset($_SESSION['admin_orders_payment_csrf']) ||
        !is_string($_SESSION['admin_orders_payment_csrf']) ||
        $_SESSION['admin_orders_payment_csrf'] === ''
    ) {
        $_SESSION['admin_orders_payment_csrf'] =
            bin2hex(random_bytes(24));
    }

    return $_SESSION['admin_orders_payment_csrf'];
}

function resolveAdminUserId(PDO $pdo): int
{
    $sessionRole = isset($_SESSION['role'])
        ? strtolower(trim((string) $_SESSION['role']))
        : '';

    /*
    | Verification actions are Admin-only.
    | Do not recover an Admin account for a non-admin browser session.
    */
    if ($sessionRole !== 'admin') {
        return 0;
    }

    $sessionId = 0;

    if (isset($_SESSION['user_id'])) {
        $sessionId = (int) $_SESSION['user_id'];
    } elseif (isset($_SESSION['admin_id'])) {
        $sessionId = (int) $_SESSION['admin_id'];
    } elseif (isset($_SESSION['id'])) {
        $sessionId = (int) $_SESSION['id'];
    }

    /*
    | Normal case: the session already points to the active Admin.
    */
    if ($sessionId > 0) {
        $statement = $pdo->prepare(
            "SELECT
                user_id,
                user_name,
                email
             FROM users
             WHERE user_id = :user_id
               AND role = 'admin'
               AND status = 'active'
             LIMIT 1"
        );

        $statement->execute(array(
            'user_id' => $sessionId
        ));

        $admin = $statement->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            $_SESSION['user_id'] = (int) $admin['user_id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['user_name'] = (string) $admin['user_name'];
            $_SESSION['name'] = (string) $admin['user_name'];
            $_SESSION['admin_name'] = (string) $admin['user_name'];
            $_SESSION['email'] = (string) $admin['email'];
            $_SESSION['admin_email'] = (string) $admin['email'];

            return (int) $admin['user_id'];
        }
    }

    /*
    | This project uses one fixed Admin account.
    | If the Admin row was recreated / its user_id changed while the browser
    | still has an old Admin session, recover the one active Admin record.
    */
    $statement = $pdo->query(
        "SELECT
            user_id,
            user_name,
            email
         FROM users
         WHERE role = 'admin'
           AND status = 'active'
         ORDER BY user_id ASC
         LIMIT 1"
    );

    $admin = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        return 0;
    }

    $_SESSION['user_id'] = (int) $admin['user_id'];
    $_SESSION['role'] = 'admin';
    $_SESSION['user_name'] = (string) $admin['user_name'];
    $_SESSION['name'] = (string) $admin['user_name'];
    $_SESSION['admin_name'] = (string) $admin['user_name'];
    $_SESSION['email'] = (string) $admin['email'];
    $_SESSION['admin_email'] = (string) $admin['email'];

    return (int) $admin['user_id'];
}

/*
|--------------------------------------------------------------------------
| Admin Order Monitoring
|--------------------------------------------------------------------------
| Order status is controlled by vendor_orders.
| Admin does not manually change Pending / Confirmed / Rejected here.
| Payment verification is handled in this Orders page.
|--------------------------------------------------------------------------
*/

$flash = isset($_SESSION['admin_orders_flash'])
    ? $_SESSION['admin_orders_flash']
    : null;

unset($_SESSION['admin_orders_flash']);

$paymentCsrfToken =
    adminOrderPaymentCsrfToken();

/*
|--------------------------------------------------------------------------
| Verify / Reject Payment from Order Detail Modal
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['payment_action'])
) {
    $paymentAction = strtolower(
        trim(
            (string) $_POST['payment_action']
        )
    );

    $purchaseId = isset($_POST['purchase_id'])
        ? max(0, (int) $_POST['purchase_id'])
        : 0;

    $submittedCsrf = isset($_POST['payment_csrf_token'])
        ? (string) $_POST['payment_csrf_token']
        : '';

    $rejectionReason = isset($_POST['rejection_reason'])
        ? trim((string) $_POST['rejection_reason'])
        : '';

    $adminUserId =
        resolveAdminUserId($pdo);

    if ($adminUserId <= 0) {
        $_SESSION['admin_orders_flash'] = array(
            'type' => 'error',
            'message' => 'Admin login was not found. Please sign in again.'
        );

        header('Location: ../signin.php');
        exit;
    }

    if (
        !isset($_SESSION['admin_orders_payment_csrf']) ||
        !is_string($_SESSION['admin_orders_payment_csrf']) ||
        $submittedCsrf === '' ||
        !hash_equals(
            (string) $_SESSION['admin_orders_payment_csrf'],
            $submittedCsrf
        )
    ) {
        /*
        | Do NOT log Admin out for a stale form token.
        | Generate a fresh token on the next request and return to Orders.
        */
        unset($_SESSION['admin_orders_payment_csrf']);

        $_SESSION['admin_orders_flash'] = array(
            'type' => 'error',
            'message' => 'Security token expired. Please open the order and try Verify Payment again.'
        );

        header(
            'Location: orders.php?open_payment=' .
            $purchaseId
        );
        exit;
    }

    if (
        $purchaseId <= 0 ||
        !in_array(
            $paymentAction,
            array(
                'verify_payment',
                'reject_payment'
            ),
            true
        )
    ) {
        $_SESSION['admin_orders_flash'] = array(
            'type' => 'error',
            'message' => 'Invalid payment verification request.'
        );

        header('Location: orders.php');
        exit;
    }

    if (
        $paymentAction === 'reject_payment' &&
        $rejectionReason === ''
    ) {
        $_SESSION['admin_orders_flash'] = array(
            'type' => 'error',
            'message' => 'Please enter a rejection reason.'
        );

        header(
            'Location: orders.php?open_payment=' .
            $purchaseId
        );
        exit;
    }

    try {
        $pdo->beginTransaction();

        $lockStatement = $pdo->prepare(
            "SELECT
                purchase_id,
                total_amount,
                payment_method,
                payment_status,
                transaction_id,
                payment_slip
             FROM purchase_process
             WHERE purchase_id = :purchase_id
             LIMIT 1
             FOR UPDATE"
        );

        $lockStatement->execute(array(
            'purchase_id' => $purchaseId
        ));

        $lockedPayment =
            $lockStatement->fetch(PDO::FETCH_ASSOC);

        if (!$lockedPayment) {
            throw new RuntimeException(
                'Payment order was not found.'
            );
        }

        if (
            strtolower(
                trim(
                    (string)
                    $lockedPayment['payment_status']
                )
            ) !== 'pending_verification'
        ) {
            throw new RuntimeException(
                'Only Pending Verification payments can be verified or rejected.'
            );
        }

        /*
        | Current customer payment flow does not collect a Transaction ID.
        | For Admin verification, the submitted payment must have:
        | - a selected payment method
        | - an uploaded payment slip
        |
        | transaction_id may be NULL / empty.
        */
        if (
            trim(
                (string)
                $lockedPayment['payment_method']
            ) === '' ||
            trim(
                (string)
                $lockedPayment['payment_slip']
            ) === ''
        ) {
            throw new RuntimeException(
                'Payment submission is incomplete.'
            );
        }

        $amountStatement = $pdo->prepare(
            "SELECT
                SUM(
                    CASE
                        WHEN order_status = 'pending'
                        THEN 1
                        ELSE 0
                    END
                ) AS pending_count,
                COALESCE(
                    SUM(
                        CASE
                            WHEN order_status IN (
                                'confirmed',
                                'completed'
                            )
                            THEN subtotal
                            ELSE 0
                        END
                    ),
                    0
                ) AS expected_amount
             FROM vendor_orders
             WHERE purchase_id = :purchase_id"
        );

        $amountStatement->execute(array(
            'purchase_id' => $purchaseId
        ));

        $amountCheck =
            $amountStatement->fetch(PDO::FETCH_ASSOC);

        $pendingVendorCount =
            (int) $amountCheck['pending_count'];

        $expectedAmount =
            (float) $amountCheck['expected_amount'];

        if ($pendingVendorCount > 0) {
            throw new RuntimeException(
                'A Vendor Order is still Pending.'
            );
        }

        if ($expectedAmount <= 0) {
            throw new RuntimeException(
                'There is no payable confirmed Vendor Order.'
            );
        }

        if (
            abs(
                $expectedAmount -
                (float) $lockedPayment['total_amount']
            ) > 0.01
        ) {
            throw new RuntimeException(
                'Payment amount does not match the confirmed Vendor Order total.'
            );
        }

        if ($paymentAction === 'verify_payment') {
            $updateStatement = $pdo->prepare(
                "UPDATE purchase_process
                 SET
                    payment_status = 'paid',
                    paid_at = NOW(),
                    verified_at = NOW(),
                    verified_by = :verified_by,
                    payment_rejection_reason = NULL
                 WHERE purchase_id = :purchase_id
                   AND payment_status = 'pending_verification'"
            );

            $updateStatement->execute(array(
                'verified_by' => $adminUserId,
                'purchase_id' => $purchaseId
            ));

            $successMessage =
                'Payment verified successfully. Payment status is now Successful.';
        } else {
            $updateStatement = $pdo->prepare(
                "UPDATE purchase_process
                 SET
                    payment_status = 'rejected',
                    paid_at = NULL,
                    verified_at = NOW(),
                    verified_by = :verified_by,
                    payment_rejection_reason = :rejection_reason
                 WHERE purchase_id = :purchase_id
                   AND payment_status = 'pending_verification'"
            );

            $updateStatement->execute(array(
                'verified_by' => $adminUserId,
                'rejection_reason' => $rejectionReason,
                'purchase_id' => $purchaseId
            ));

            $successMessage =
                'Payment proof rejected successfully.';
        }

        if ($updateStatement->rowCount() !== 1) {
            throw new RuntimeException(
                'Payment status could not be updated.'
            );
        }

        $pdo->commit();

        $_SESSION['admin_orders_flash'] = array(
            'type' => 'success',
            'message' => $successMessage
        );

        header('Location: orders.php');
        exit;
    } catch (Exception $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception instanceof PDOException) {
            error_log('Admin payment verification database error: ' . $exception->getMessage());
            $message = 'The database could not update the payment. Please try again.';
        } else {
            $message = $exception->getMessage();
        }

        $_SESSION['admin_orders_flash'] = array(
            'type' => 'error',
            'message' => $message
        );

        header(
            'Location: orders.php?open_payment=' .
            $purchaseId
        );
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$q = trim(isset($_GET['q']) ? (string) $_GET['q'] : '');
$status = isset($_GET['status']) ? strtolower((string) $_GET['status']) : 'all';
$payment = 'all';
$dateFrom = trim(isset($_GET['date_from']) ? (string) $_GET['date_from'] : '');
$dateTo = trim(isset($_GET['date_to']) ? (string) $_GET['date_to'] : '');
$sort = isset($_GET['sort']) ? strtolower((string) $_GET['sort']) : 'newest';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 10;

$allowedStatusFilters = ['all', 'pending', 'confirmed', 'completed', 'rejected'];
if (!in_array($status, $allowedStatusFilters, true)) {
    $status = 'all';
}

$allowedSorts = ['newest', 'oldest', 'amount_high', 'amount_low', 'customer_asc', 'customer_desc'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

if ($dateFrom !== '' && !validOrderDate($dateFrom)) {
    $dateFrom = '';
}

if ($dateTo !== '' && !validOrderDate($dateTo)) {
    $dateTo = '';
}

$derivedStatusSql = "
    CASE
        WHEN EXISTS (
            SELECT 1
            FROM vendor_orders vo_pending
            WHERE vo_pending.purchase_id = pp.purchase_id
              AND vo_pending.order_status = 'pending'
        ) THEN 'pending'

        WHEN EXISTS (
            SELECT 1
            FROM vendor_orders vo_any
            WHERE vo_any.purchase_id = pp.purchase_id
        )
        AND NOT EXISTS (
            SELECT 1
            FROM vendor_orders vo_non_rejected
            WHERE vo_non_rejected.purchase_id = pp.purchase_id
              AND vo_non_rejected.order_status <> 'rejected'
        ) THEN 'rejected'

        WHEN EXISTS (
            SELECT 1
            FROM vendor_orders vo_confirmed
            WHERE vo_confirmed.purchase_id = pp.purchase_id
              AND vo_confirmed.order_status = 'confirmed'
        ) THEN 'confirmed'

        WHEN EXISTS (
            SELECT 1
            FROM vendor_orders vo_completed
            WHERE vo_completed.purchase_id = pp.purchase_id
              AND vo_completed.order_status = 'completed'
        )
        AND NOT EXISTS (
            SELECT 1
            FROM vendor_orders vo_not_done
            WHERE vo_not_done.purchase_id = pp.purchase_id
              AND vo_not_done.order_status IN ('pending', 'confirmed')
        ) THEN 'completed'

        WHEN EXISTS (
            SELECT 1
            FROM vendor_orders vo_accepted
            WHERE vo_accepted.purchase_id = pp.purchase_id
              AND vo_accepted.order_status IN ('confirmed', 'completed')
        ) THEN 'confirmed'

        ELSE pp.order_status
    END
";

$where = [];
$params = [];

if ($q !== '') {
    $searchValue = '%' . trim($q) . '%';

    $where[] = "(
        CAST(pp.purchase_id AS CHAR) LIKE :search_order
        OR LOWER(TRIM(u.user_name)) LIKE LOWER(TRIM(:search_customer))
        OR LOWER(TRIM(u.email)) LIKE LOWER(TRIM(:search_email))
        OR TRIM(u.phone_number) LIKE TRIM(:search_phone)
        OR EXISTS (
            SELECT 1
            FROM purchase_details pd_search
            LEFT JOIN products p_search ON p_search.product_id = pd_search.product_id
            LEFT JOIN vendors v_search ON v_search.vendor_id = p_search.vendor_id
            WHERE pd_search.purchase_id = pp.purchase_id
              AND (
                  LOWER(TRIM(p_search.product_name)) LIKE LOWER(TRIM(:search_product))
                  OR LOWER(TRIM(v_search.vendor_name)) LIKE LOWER(TRIM(:search_vendor))
              )
        )
    )";

    $params['search_order'] = $searchValue;
    $params['search_customer'] = $searchValue;
    $params['search_email'] = $searchValue;
    $params['search_phone'] = $searchValue;
    $params['search_product'] = $searchValue;
    $params['search_vendor'] = $searchValue;
}

if ($status !== 'all') {
    $where[] = '(' . $derivedStatusSql . ') = :status';
    $params['status'] = $status;
}

if ($dateFrom !== '') {
    $where[] = 'pp.created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where[] = 'pp.created_at < :date_to';
    $dateToExclusive = new DateTime($dateTo . ' 00:00:00');
    $dateToExclusive->modify('+1 day');
    $params['date_to'] = $dateToExclusive->format('Y-m-d H:i:s');
}

$whereSql = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

$orderBy = 'pp.created_at DESC, pp.purchase_id DESC';
if ($sort === 'oldest') {
    $orderBy = 'pp.created_at ASC, pp.purchase_id ASC';
} elseif ($sort === 'amount_high') {
    $orderBy = 'pp.total_amount DESC, pp.created_at DESC';
} elseif ($sort === 'amount_low') {
    $orderBy = 'pp.total_amount ASC, pp.created_at DESC';
} elseif ($sort === 'customer_asc') {
    $orderBy = 'COALESCE(u.user_name, \'\') ASC, pp.created_at DESC';
} elseif ($sort === 'customer_desc') {
    $orderBy = 'COALESCE(u.user_name, \'\') DESC, pp.created_at DESC';
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/
$statistics = $pdo->query(
    "SELECT
        COUNT(*) AS total_orders,

        SUM(
            CASE
                WHEN derived_order_status = 'pending'
                THEN 1 ELSE 0
            END
        ) AS pending_orders,

        SUM(
            CASE
                WHEN derived_order_status = 'confirmed'
                THEN 1 ELSE 0
            END
        ) AS confirmed_orders,

        SUM(
            CASE
                WHEN derived_order_status = 'rejected'
                THEN 1 ELSE 0
            END
        ) AS rejected_orders,

        COALESCE(
            SUM(
                CASE
                    WHEN payment_status = 'paid'
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS paid_sales

     FROM (
        SELECT
            pp.purchase_id,
            pp.total_amount,
            pp.payment_status,
            " . $derivedStatusSql . " AS derived_order_status
        FROM purchase_process pp
     ) order_stats"
)->fetch(PDO::FETCH_ASSOC);

$totalOrders =
    isset($statistics['total_orders'])
        ? (int) $statistics['total_orders']
        : 0;

$pendingOrders =
    isset($statistics['pending_orders'])
        ? (int) $statistics['pending_orders']
        : 0;

$confirmedOrders =
    isset($statistics['confirmed_orders'])
        ? (int) $statistics['confirmed_orders']
        : 0;

$rejectedOrders =
    isset($statistics['rejected_orders'])
        ? (int) $statistics['rejected_orders']
        : 0;

$paidSales =
    isset($statistics['paid_sales'])
        ? (float) $statistics['paid_sales']
        : 0.0;


/*
|--------------------------------------------------------------------------
| Admin Payment Notification
|--------------------------------------------------------------------------
| Admin is notified only when a customer has submitted a payment proof
| that is waiting for verification.
|--------------------------------------------------------------------------
*/
$pendingPaymentVerificationCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM purchase_process
     WHERE payment_status = 'pending_verification'"
)->fetchColumn();

/*
| This value can also be read by the page JavaScript to add a small
| notification badge beside the Orders item in the Admin sidebar.
*/
$adminOrdersNotificationCount = $pendingPaymentVerificationCount;

/*
|--------------------------------------------------------------------------
| Pagination and order list
|--------------------------------------------------------------------------
*/
$countSql =
    'SELECT COUNT(*)
     FROM purchase_process pp
     LEFT JOIN users u ON u.user_id = pp.user_id' . $whereSql;

$countStatement = $pdo->prepare($countSql);
$countStatement->execute($params);
$filteredTotal = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$orderSql =
    "SELECT
        pp.purchase_id,
        pp.user_id,
        pp.total_amount,
        pp.payment_method,
        pp.payment_status,
        pp.transaction_id,
        pp.payment_slip,
        pp.payment_submitted_at,
        pp.paid_at,
        pp.verified_at,
        pp.payment_rejection_reason,
        pp.fulfillment_type,
        pp.delivery_name,
        pp.delivery_phone,
        pp.delivery_address,
        pp.customer_note,
        pp.order_status,
        " . $derivedStatusSql . " AS derived_order_status,
        pp.created_at,
        pp.updated_at,
        u.user_name,
        u.email,
        u.phone_number,
        (
            SELECT COUNT(*)
            FROM purchase_details pd_count
            WHERE pd_count.purchase_id = pp.purchase_id
        ) AS item_lines,
        (
            SELECT COALESCE(SUM(pd_quantity.quantity), 0)
            FROM purchase_details pd_quantity
            WHERE pd_quantity.purchase_id = pp.purchase_id
        ) AS total_quantity,
        (
            SELECT GROUP_CONCAT(DISTINCT p_names.product_name ORDER BY p_names.product_name SEPARATOR ', ')
            FROM purchase_details pd_names
            LEFT JOIN products p_names ON p_names.product_id = pd_names.product_id
            WHERE pd_names.purchase_id = pp.purchase_id
        ) AS product_names,
        (
            SELECT GROUP_CONCAT(DISTINCT v_names.vendor_name ORDER BY v_names.vendor_name SEPARATOR ', ')
            FROM purchase_details pd_vendors
            LEFT JOIN products p_vendors ON p_vendors.product_id = pd_vendors.product_id
            LEFT JOIN vendors v_names ON v_names.vendor_id = p_vendors.vendor_id
            WHERE pd_vendors.purchase_id = pp.purchase_id
        ) AS vendor_names
     FROM purchase_process pp
     LEFT JOIN users u ON u.user_id = pp.user_id" . $whereSql .
    ' ORDER BY ' . $orderBy .
    ' LIMIT :limit OFFSET :offset';

$orderStatement = $pdo->prepare($orderSql);
foreach ($params as $key => $value) {
    $orderStatement->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$orderStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$orderStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$orderStatement->execute();
$orders = $orderStatement->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Fetch all line items for the visible orders
|--------------------------------------------------------------------------
*/
$orderDetails = [];
$orderIds = [];

foreach ($orders as $order) {
    $orderIds[] = (int) $order['purchase_id'];
}

if (count($orderIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $detailSql =
        "SELECT
            pd.purchase_detail_id,
            pd.purchase_id,
            pd.product_id,
            pd.quantity,
            pd.unit_price,
            pd.subtotal,
            pd.created_at,
            p.product_name,
            COALESCE(p.unit, 'piece') AS unit,
            p.description AS product_description,
            v.vendor_name,
            c.category_name,
            m.market_name,
            (
                SELECT pphoto.photo_path
                FROM product_photo pphoto
                WHERE pphoto.product_id = p.product_id
                ORDER BY pphoto.product_photo_id ASC
                LIMIT 1
            ) AS photo_path
         FROM purchase_details pd
         LEFT JOIN products p ON p.product_id = pd.product_id
         LEFT JOIN vendors v ON v.vendor_id = p.vendor_id
         LEFT JOIN categories c ON c.category_id = p.category_id
         LEFT JOIN markets m ON m.market_id = c.market_id
         WHERE pd.purchase_id IN ($placeholders)
         ORDER BY pd.purchase_id DESC, pd.purchase_detail_id ASC";

    $detailStatement = $pdo->prepare($detailSql);
    foreach ($orderIds as $index => $orderId) {
        $detailStatement->bindValue($index + 1, $orderId, PDO::PARAM_INT);
    }
    $detailStatement->execute();

    foreach ($detailStatement->fetchAll(PDO::FETCH_ASSOC) as $detail) {
        $detailPurchaseId = (int) $detail['purchase_id'];
        if (!isset($orderDetails[$detailPurchaseId])) {
            $orderDetails[$detailPurchaseId] = [];
        }
        $orderDetails[$detailPurchaseId][] = $detail;
    }
}

$showingFrom = $filteredTotal > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $filteredTotal);
$currentReturnQuery = ordersQueryString([]);
$hasActiveFilters = $q !== '' || $status !== 'all' || $dateFrom !== '' || $dateTo !== '' || $sort !== 'newest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders | Local Farmers Marketplace</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Admin Orders - Compact Production UI
        |--------------------------------------------------------------------------
        | Browser zoom target: 100%.
        | Scrollbars are hidden visually; scrolling remains functional.
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

        .orders-scroll-hidden,
        .orders-modal-scroll {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .orders-scroll-hidden::-webkit-scrollbar,
        .orders-modal-scroll::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .orders-main {
            min-width: 0;
        }


        /* ================================================================
           Payment Verification Notification
           ================================================================ */
        .orders-notification-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: .75rem;
            padding: 11px 14px;
            border: 1px solid #bfdbfe;
            border-radius: 14px;
            background: linear-gradient(135deg, #eff6ff 0%, #f8fbff 100%);
            box-shadow: 0 5px 15px rgba(37, 99, 235, .05);
        }

        .orders-notification-content {
            display: flex;
            min-width: 0;
            align-items: center;
            gap: 11px;
        }

        .orders-notification-icon {
            position: relative;
            display: grid;
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            place-items: center;
            border-radius: 11px;
            background: #dbeafe;
            color: #2563eb;
        }

        .orders-notification-pulse {
            position: absolute;
            right: -2px;
            top: -2px;
            width: 9px;
            height: 9px;
            border: 2px solid #eff6ff;
            border-radius: 999px;
            background: #ef4444;
        }

        .orders-notification-title {
            color: #1e3a8a;
            font-size: 11px;
            font-weight: 900;
            line-height: 1.35;
        }

        .orders-notification-text {
            margin-top: 2px;
            color: #64748b;
            font-size: 9px;
            line-height: 1.45;
        }

        .orders-notification-button {
            display: inline-flex;
            min-height: 34px;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 0 12px;
            border: 1px solid #bfdbfe;
            border-radius: 9px;
            background: #ffffff;
            color: #1d4ed8;
            font-size: 9px;
            font-weight: 900;
            text-decoration: none;
            cursor: pointer;
            transition: .18s ease;
        }

        .orders-notification-button:hover {
            border-color: #93c5fd;
            background: #dbeafe;
        }

        .admin-orders-notification-badge {
            display: inline-grid;
            min-width: 18px;
            height: 18px;
            margin-left: auto;
            padding: 0 5px;
            place-items: center;
            border: 2px solid #0f4a2d;
            border-radius: 999px;
            background: #ef4444;
            color: #ffffff;
            font-size: 8px;
            font-weight: 900;
            line-height: 1;
        }

        .orders-payment-attention {
            animation: ordersPaymentAttention 1.25s ease 2;
        }

        @keyframes ordersPaymentAttention {
            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(37, 99, 235, 0);
            }

            50% {
                box-shadow: 0 0 0 5px rgba(37, 99, 235, .14);
            }
        }

        @media (max-width: 640px) {
            .orders-notification-banner {
                align-items: flex-start;
                flex-direction: column;
            }

            .orders-notification-button {
                width: 100%;
            }
        }

        .orders-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
        }

        .orders-table {
            width: 100%;
            min-width: 980px;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            .orders-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .orders-shell {
                margin-left: 176px !important;
            }

            .orders-main {
                padding: 12px 14px 24px !important;
                max-width: none !important;
            }

            .orders-stats {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: .7rem;
                margin-bottom: .75rem !important;
            }

            .orders-stat-card {
                min-height: 82px;
                padding: 12px 14px !important;
                border-radius: 14px !important;
            }

            .orders-stat-card p:first-child {
                font-size: 9px !important;
                letter-spacing: .04em !important;
            }

            .orders-stat-card p.text-3xl {
                margin-top: 5px !important;
                font-size: 20px !important;
                line-height: 1 !important;
            }

            .orders-stat-card .h-12 {
                width: 38px !important;
                height: 38px !important;
                border-radius: 10px !important;
            }

            .orders-stat-card .text-xl {
                font-size: 15px !important;
            }

            .orders-filter-panel,
            .orders-list-panel {
                border-radius: 14px !important;
            }

            .orders-filter-panel {
                padding: 10px 12px !important;
                margin-bottom: .7rem !important;
            }

            .orders-filter-form {
                display: grid !important;
                grid-template-columns:
                    minmax(290px, 1.45fr)
                    145px
                    145px
                    145px
                    160px
                    auto !important;
                align-items: center !important;
                gap: 8px !important;
                width: 100% !important;
                min-width: 0 !important;
            }

            .orders-filter-form > * {
                grid-column: auto !important;
                min-width: 0 !important;
                width: 100% !important;
                max-width: none !important;
            }

            .orders-filter-form > .orders-filter-actions {
                width: auto !important;
                justify-self: end !important;
            }

            .orders-filter-control,
            .orders-filter-button,
            .orders-clear-button {
                height: 34px !important;
                border-radius: 9px !important;
                font-size: 9px !important;
            }

            .orders-search-input {
                font-size: 9px !important;
            }

            .orders-search-input {
                padding-left: 34px !important;
                padding-right: 10px !important;
            }

            .orders-search-icon {
                left: 12px !important;
                font-size: 10px !important;
            }


            .orders-date-label {
                left: 10px !important;
                top: 3px !important;
                font-size: 7px !important;
            }

            .orders-date-input {
                padding-left: 10px !important;
                padding-right: 8px !important;
                padding-top: 10px !important;
                font-size: 8px !important;
            }

            .orders-filter-actions {
                display: inline-flex !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
                gap: 6px !important;
                width: auto !important;
                white-space: nowrap !important;
            }

            .orders-filter-button {
                flex: 0 0 auto !important;
                width: 78px !important;
                min-width: 78px !important;
                padding-left: 12px !important;
                padding-right: 12px !important;
            }

            .orders-clear-button {
                flex: 0 0 auto !important;
                width: 76px !important;
                min-width: 76px !important;
                padding-left: 11px !important;
                padding-right: 11px !important;
            }

            .orders-list-head {
                padding: 10px 14px !important;
            }

            .orders-list-title {
                font-size: 14px !important;
            }

            .orders-list-subtitle {
                margin-top: 3px !important;
                font-size: 9px !important;
            }

            .orders-table {
                min-width: 0 !important;
                table-layout: fixed;
            }

            .orders-table th {
                padding: 7px 8px !important;
                font-size: 8px !important;
            }

            .orders-table td {
                padding: 9px 8px !important;
                vertical-align: middle !important;
            }

            .orders-table th:nth-child(1),
            .orders-table td:nth-child(1) { width: 9%; }

            .orders-table th:nth-child(2),
            .orders-table td:nth-child(2) { width: 17%; }

            .orders-table th:nth-child(3),
            .orders-table td:nth-child(3) { width: 16%; }

            .orders-table th:nth-child(4),
            .orders-table td:nth-child(4) { width: 14%; }

            .orders-table th:nth-child(5),
            .orders-table td:nth-child(5) { width: 10%; }

            .orders-table th:nth-child(6),
            .orders-table td:nth-child(6) { width: 12%; }

            .orders-table th:nth-child(7),
            .orders-table td:nth-child(7) { width: 10%; }

            .orders-table th:nth-child(8),
            .orders-table td:nth-child(8) { width: 12%; }

            .orders-order-number,
            .orders-customer-name,
            .orders-item-name,
            .orders-amount,
            .orders-placed-date {
                font-size: 9px !important;
            }

            .orders-order-id,
            .orders-customer-email,
            .orders-item-meta,
            .orders-placed-time {
                font-size: 8px !important;
            }

            .orders-customer-avatar {
                width: 30px !important;
                height: 30px !important;
                font-size: 8px !important;
            }

            .orders-payment-badge,
            .orders-payment-status,
            .orders-status-badge {
                font-size: 8px !important;
                padding: 4px 7px !important;
            }

            .orders-action-button {
                width: 28px !important;
                height: 28px !important;
                min-width: 28px !important;
                padding: 0 !important;
                border-radius: 7px !important;
            }

            .orders-action-button span {
                display: none !important;
            }

            .orders-action-button i {
                font-size: 9px !important;
            }

            .orders-payment-review-button,
            .orders-payment-done-badge,
            .orders-payment-rejected-badge {
                min-width: 0 !important;
                white-space: nowrap !important;
            }

            .orders-payment-review-button i,
            .orders-payment-done-badge i,
            .orders-payment-rejected-badge i {
                font-size: 9px !important;
            }

            .orders-payment-review-button span,
            .orders-payment-done-badge span,
            .orders-payment-rejected-badge span {
                display: inline !important;
                font-size: 8px !important;
            }

            .orders-pagination {
                padding: 10px 14px !important;
            }

            .orders-pagination,
            .orders-pagination a {
                font-size: 9px !important;
            }
        }

        @media (max-width: 1023px) {
            .orders-filter-form {
                display: grid !important;
                grid-template-columns: 1fr !important;
            }

            .orders-filter-form > *,
            .orders-filter-form > label:first-child,
            .orders-filter-form > select[name="status"],
            .orders-date-field,
            .orders-filter-form > select[name="sort"],
            .orders-filter-form > .orders-filter-actions {
                width: 100% !important;
                max-width: none !important;
                min-width: 0 !important;
                flex: none !important;
            }

            .orders-filter-actions {
                display: flex !important;
            }

            .orders-filter-button,
            .orders-clear-button {
                flex: 1 1 0 !important;
            }

            .orders-table {
                min-width: 980px;
            }
        }
    
        @media (min-width: 1024px) {
            .orders-scroll-hidden {
                overflow-x: hidden !important;
            }
        }


        /* ================================================================
           Order Detail Modal - Polished Desktop UI
           ================================================================ */
        .order-detail-overlay {
            padding: 24px !important;
            background: rgba(15, 23, 42, .56) !important;
            backdrop-filter: blur(4px);
        }

        .order-detail-modal {
            width: min(calc(100vw - 48px), 980px) !important;
            max-width: 980px !important;
            max-height: calc(100vh - 48px) !important;
            margin: 0 auto !important;
            border-radius: 20px !important;
            overflow: hidden !important;
            background: #fff;
            box-shadow: 0 24px 70px rgba(15, 23, 42, .24) !important;
        }

        .order-detail-header {
            padding: 16px 20px !important;
            background: linear-gradient(180deg, #ffffff 0%, #fbfefc 100%);
        }

        .order-detail-title {
            font-size: 18px !important;
            line-height: 1.1 !important;
        }

        .order-detail-date {
            margin-top: 4px !important;
            font-size: 10px !important;
        }

        .order-detail-close {
            width: 34px !important;
            height: 34px !important;
            border-radius: 10px !important;
        }

        .order-detail-body {
            display: grid !important;
            grid-template-columns: 1fr !important;
            gap: 14px !important;
            padding: 16px 18px 18px !important;
            max-height: calc(100vh - 126px);
            overflow-y: auto;
            scrollbar-width: none;
        }

        .order-detail-body::-webkit-scrollbar {
            display: none;
        }

        .order-detail-main-title {
            margin-bottom: 10px !important;
        }

        .order-detail-main-title h3 {
            font-size: 13px !important;
        }

        .order-detail-main-title span {
            font-size: 9px !important;
        }

        .order-detail-items {
            display: grid !important;
            gap: 9px !important;
        }

        .order-detail-item {
            gap: 10px !important;
            padding: 10px !important;
            border-radius: 14px !important;
            background: #fff;
            transition: .18s ease;
        }

        .order-detail-item:hover {
            border-color: #bbf7d0 !important;
            box-shadow: 0 6px 16px rgba(22, 163, 74, .06);
        }

        .order-detail-item-image,
        .order-detail-item-fallback {
            width: 54px !important;
            height: 54px !important;
            border-radius: 11px !important;
        }

        .order-detail-product-name {
            font-size: 12px !important;
        }

        .order-detail-product-meta {
            margin-top: 3px !important;
            font-size: 9px !important;
        }

        .order-detail-product-price-row {
            margin-top: 6px !important;
            font-size: 9px !important;
        }

        .order-detail-sidebar {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 12px !important;
            align-items: start !important;
            align-content: start !important;
        }

        .order-detail-side-card {
            padding: 12px !important;
            border-radius: 14px !important;
        }

        .order-detail-side-card h3 {
            font-size: 11px !important;
        }

        .order-detail-side-card p,
        .order-detail-side-card span,
        .order-detail-side-card div {
            line-height: 1.4;
        }

        .order-detail-customer-avatar {
            width: 34px !important;
            height: 34px !important;
            font-size: 9px !important;
        }

        .order-detail-customer-name {
            font-size: 11px !important;
        }

        .order-detail-small {
            font-size: 9px !important;
        }

        .order-detail-payment-summary {
            padding: 12px !important;
        }

        .order-detail-payment-summary .mt-3 {
            margin-top: 8px !important;
        }

        .order-detail-payment-summary .space-y-2\.5 > :not([hidden]) ~ :not([hidden]) {
            margin-top: 7px !important;
        }

        .order-detail-slip {
            max-height: 132px !important;
            height: 132px !important;
            padding: 4px !important;
            background: #f8fafc !important;
        }

        .order-detail-total {
            font-size: 15px !important;
        }

        .order-detail-control-card {
            padding: 12px !important;
            border-radius: 14px !important;
        }

        .order-detail-payment-summary,
        .order-detail-control-card {
            grid-column: auto !important;
            align-self: start !important;
            min-width: 0 !important;
        }

        .order-detail-payment-summary {
            grid-column: 1 !important;
        }

        .order-detail-control-card {
            grid-column: 2 !important;
        }

        .order-detail-control-card .mt-3 {
            margin-top: 8px !important;
        }

        .order-detail-control-actions {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 8px !important;
            margin-top: 9px !important;
        }

        .order-detail-control-actions form {
            min-width: 0;
        }

        .order-detail-control-actions button {
            height: 36px !important;
            min-height: 36px !important;
            padding: 0 10px !important;
            border-radius: 10px !important;
            font-size: 9px !important;
            white-space: nowrap;
        }

        @media (max-width: 900px) {
            .order-detail-overlay {
                padding: 14px !important;
            }

            .order-detail-modal {
                width: min(calc(100vw - 28px), 760px) !important;
                max-width: 760px !important;
                max-height: calc(100vh - 28px) !important;
            }

            .order-detail-body {
                grid-template-columns: 1fr !important;
                max-height: calc(100vh - 108px);
            }

            .order-detail-sidebar {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .order-detail-payment-summary {
                grid-column: 1 !important;
            }

            .order-detail-control-card {
                grid-column: 2 !important;
            }
        }

        @media (max-width: 640px) {
            .order-detail-overlay {
                padding: 8px !important;
            }

            .order-detail-modal {
                width: calc(100vw - 16px) !important;
                max-width: none !important;
                max-height: calc(100vh - 16px) !important;
                border-radius: 16px !important;
            }

            .order-detail-header {
                padding: 13px 14px !important;
            }

            .order-detail-body {
                padding: 12px !important;
                max-height: calc(100vh - 92px);
            }

            .order-detail-sidebar {
                grid-template-columns: 1fr !important;
            }

            .order-detail-payment-summary,
            .order-detail-control-card {
                grid-column: auto !important;
            }

            .order-detail-control-actions {
                grid-template-columns: 1fr !important;
            }
        }

    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>
<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">
<div class="min-h-screen">
    <?php require_once './sidebar.php'; ?>

    <div class="orders-shell min-h-screen lg:ml-64">
        <?php require_once './header.php'; ?>

        <main class="orders-main px-4 pb-10 pt-5 sm:px-6 xl:px-7">
            <?php if (is_array($flash)): ?>
                <?php $flashIsSuccess = isset($flash['type']) && $flash['type'] === 'success'; ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm font-semibold <?= $flashIsSuccess ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                    <i class="fa-solid <?= $flashIsSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?> mt-0.5"></i>
                    <span class="flex-1"><?= e(isset($flash['message']) ? $flash['message'] : '') ?></span>
                    <button type="button" onclick="this.parentElement.remove()" class="text-current/60 hover:text-current" aria-label="Close message">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php if ($pendingPaymentVerificationCount > 0): ?>
                <section class="orders-notification-banner"
                         id="adminPaymentNotification">

                    <div class="orders-notification-content">

                        <div class="orders-notification-icon">
                            <i class="fa-solid fa-bell"></i>
                            <span class="orders-notification-pulse"></span>
                        </div>

                        <div class="min-w-0">
                            <p class="orders-notification-title">
                                <?= e(number_format($pendingPaymentVerificationCount)) ?>
                                payment<?= $pendingPaymentVerificationCount === 1 ? '' : 's' ?>
                                need verification
                            </p>

                            <p class="orders-notification-text">
                                Customer payment proof<?= $pendingPaymentVerificationCount === 1 ? ' is' : 's are' ?>
                                waiting for Admin review.
                            </p>
                        </div>
                    </div>

                    <button type="button"
                            onclick="focusFirstPaymentReview()"
                            class="orders-notification-button">
                        <i class="fa-solid fa-receipt"></i>
                        Review Payment<?= $pendingPaymentVerificationCount === 1 ? '' : 's' ?>
                    </button>
                </section>
            <?php endif; ?>

            <section class="orders-stats mb-5">
                <article class="orders-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total Orders</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalOrders)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-clipboard-list text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="orders-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pending</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($pendingOrders)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-600">
                            <i class="fa-solid fa-clock text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="orders-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Confirmed</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($confirmedOrders)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-circle-check text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="orders-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Rejected</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($rejectedOrders)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-red-50 text-red-600">
                            <i class="fa-solid fa-circle-xmark text-xl"></i>
                        </div>
                    </div>
                </article>

            </section>

            <section class="orders-filter-panel mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                <form method="get" class="orders-filter-form grid gap-3 md:grid-cols-2">
                    <label class="relative">
                        <i class="orders-search-icon fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search order, customer, product or vendor..."
                               class="orders-filter-control orders-search-input h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                    </label>

                    <select name="status" class="orders-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>

                    <label class="orders-date-field relative">
                        <span class="orders-date-label pointer-events-none absolute left-3 top-1.5 text-[9px] font-bold uppercase tracking-wider text-slate-400">From</span>
                        <input type="date" name="date_from" value="<?= e($dateFrom) ?>"
                               class="orders-filter-control orders-date-input h-11 w-full rounded-xl border border-slate-200 bg-white px-3 pt-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                    </label>

                    <label class="orders-date-field relative">
                        <span class="orders-date-label pointer-events-none absolute left-3 top-1.5 text-[9px] font-bold uppercase tracking-wider text-slate-400">To</span>
                        <input type="date" name="date_to" value="<?= e($dateTo) ?>"
                               class="orders-filter-control orders-date-input h-11 w-full rounded-xl border border-slate-200 bg-white px-3 pt-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                    </label>

                    <select name="sort" class="orders-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                        <option value="amount_high" <?= $sort === 'amount_high' ? 'selected' : '' ?>>Highest amount</option>
                        <option value="amount_low" <?= $sort === 'amount_low' ? 'selected' : '' ?>>Lowest amount</option>
                        <option value="customer_asc" <?= $sort === 'customer_asc' ? 'selected' : '' ?>>Customer A–Z</option>
                        <option value="customer_desc" <?= $sort === 'customer_desc' ? 'selected' : '' ?>>Customer Z–A</option>
                    </select>

                    <div class="orders-filter-actions flex gap-2">
                        <button type="submit"
                                class="orders-filter-button inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-sm font-bold text-white transition hover:bg-green-700">
                            <i class="fa-solid fa-filter text-xs"></i>
                            Filter
                        </button>

                        <a href="orders.php"
                           title="Clear filters"
                           class="orders-clear-button inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-3.5 text-sm font-bold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700">
                            <i class="fa-solid fa-rotate-left text-xs"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </section>

            <section class="orders-list-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
                <div class="orders-list-head border-b border-slate-100 px-5 py-4">
                    <h2 class="orders-list-title font-extrabold text-slate-950">Order List</h2>
                    <p class="orders-list-subtitle mt-0.5 text-xs text-slate-500">
                        Showing <?= e(number_format($showingFrom)) ?>–<?= e(number_format($showingTo)) ?> of <?= e(number_format($filteredTotal)) ?> matching orders
                    </p>
                </div>

                <?php if (count($orders) === 0): ?>
                    <div class="px-5 py-20 text-center">
                        <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                            <i class="fa-solid fa-clipboard-list text-2xl"></i>
                        </div>
                        <h3 class="mt-4 text-lg font-extrabold text-slate-800">No orders found</h3>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">Orders saved in purchase_process and purchase_details will appear here.</p>
                        <?php if ($hasActiveFilters): ?>
                            <a href="orders.php" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">
                                <i class="fa-solid fa-rotate-left"></i>
                                Clear filters
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="orders-scroll-hidden overflow-x-auto">
                        <table class="orders-table min-w-full divide-y divide-slate-100">
                            <thead class="bg-slate-50/80">
                            <tr class="text-left text-[11px] font-extrabold uppercase tracking-wider text-slate-400">
                                <th class="px-5 py-3.5">Order</th>
                                <th class="px-5 py-3.5">Customer</th>
                                <th class="px-5 py-3.5">Items</th>
                                <th class="px-5 py-3.5">Payment</th>
                                <th class="px-5 py-3.5">Amount</th>
                                <th class="px-5 py-3.5">Order Status</th>
                                <th class="px-5 py-3.5">Placed</th>
                                <th class="px-5 py-3.5 text-right">Actions</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $purchaseId = (int) $order['purchase_id'];
                                $customerName = trim((string) $order['user_name']) !== '' ? (string) $order['user_name'] : 'Guest / Removed User';
                                $details = isset($orderDetails[$purchaseId]) ? $orderDetails[$purchaseId] : [];
                                $firstProduct = count($details) > 0
                                    ? (trim((string) $details[0]['product_name']) !== '' ? (string) $details[0]['product_name'] : 'Removed product')
                                    : 'No item details';
                                $moreItems = max(0, count($details) - 1);

                                $displayOrderStatus =
                                    isset($order['derived_order_status'])
                                        ? (string) $order['derived_order_status']
                                        : (string) $order['order_status'];

                                $displayPaymentStatus =
                                    trim((string) $order['payment_status']) !== ''
                                        ? strtolower((string) $order['payment_status'])
                                        : 'unpaid';
                                ?>
                                <tr class="align-middle transition hover:bg-slate-50/70">
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <button type="button" onclick="openOrderModal('order-modal-<?= e($purchaseId) ?>')" class="text-left">
                                            <p class="orders-order-number text-sm font-extrabold text-green-700 hover:text-green-800">
                                                #<?= e(orderNumber($purchaseId)) ?>
                                            </p>
                                        </button>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex min-w-[190px] items-center gap-3">
                                            <div class="orders-customer-avatar grid h-9 w-9 shrink-0 place-items-center rounded-full bg-green-100 text-xs font-extrabold text-green-700">
                                                <?= e(orderCustomerInitials($customerName)) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="orders-customer-name truncate text-sm font-bold text-slate-800"><?= e($customerName) ?></p>
                                                <p class="orders-customer-email truncate text-xs text-slate-400"><?= e($order['email'] !== null ? $order['email'] : 'No email') ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="min-w-[180px]">
                                            <p class="orders-item-name truncate text-sm font-semibold text-slate-700"><?= e($firstProduct) ?></p>
                                            <p class="orders-item-meta mt-1 text-xs text-slate-400">
                                                <?= e(number_format((int) $order['total_quantity'])) ?> unit<?= (int) $order['total_quantity'] === 1 ? '' : 's' ?>
                                                <?php if ($moreItems > 0): ?>
                                                    · +<?= e($moreItems) ?> more item<?= $moreItems === 1 ? '' : 's' ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">

                                        <span class="orders-payment-badge inline-flex rounded-full px-2.5 py-1 text-xs font-bold <?= e(paymentMethodClass($order['payment_method'])) ?>">
                                            <?= e(paymentMethodLabel($order['payment_method'])) ?>
                                        </span>

                                        <div class="mt-1.5">

                                            <span class="orders-payment-status inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 ring-inset <?= e(paymentStatusClass($displayPaymentStatus)) ?>">
                                                <?= e(paymentStatusLabel($displayPaymentStatus)) ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <p class="orders-amount text-sm font-extrabold text-slate-900">
                                            <?= e(number_format((float) $order['total_amount'], 0)) ?> MMK
                                        </p>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <span class="orders-status-badge inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold ring-1 ring-inset <?= e(orderStatusClass($displayOrderStatus)) ?>">
                                            <i class="fa-solid <?= e(orderStatusIcon($displayOrderStatus)) ?>"></i>
                                            <?= e(orderStatusLabel($displayOrderStatus)) ?>
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <p class="orders-placed-date text-sm font-semibold text-slate-700"><?= e(date('M j, Y', strtotime((string) $order['created_at']))) ?></p>
                                        <p class="orders-placed-time mt-1 text-xs text-slate-400"><?= e(date('g:i A', strtotime((string) $order['created_at']))) ?></p>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-right">
                                        <div class="inline-flex items-center justify-end gap-2">
                                            <button type="button"
                                                    onclick="openOrderModal('order-modal-<?= e($purchaseId) ?>')"
                                                    class="orders-action-button inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-bold text-slate-600 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700"
                                                    title="View order details">
                                                <i class="fa-solid fa-eye"></i>
                                                <span>View</span>
                                            </button>

                                            <?php if ($displayPaymentStatus === 'pending_verification'): ?>
                                                <button type="button"
                                                        onclick="openOrderModal('order-modal-<?= e($purchaseId) ?>')"
                                                        class="orders-payment-review-button inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 text-xs font-extrabold text-blue-700 transition hover:bg-blue-100"
                                                        title="Review customer payment">
                                                    <i class="fa-solid fa-hourglass-half"></i>
                                                    <span>Payment Review</span>
                                                </button>
                                            <?php elseif ($displayPaymentStatus === 'paid'): ?>
                                                <span class="orders-payment-done-badge inline-flex h-9 items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3 text-xs font-extrabold text-green-700">
                                                    <i class="fa-solid fa-circle-check"></i>
                                                    <span>Verified</span>
                                                </span>
                                            <?php elseif ($displayPaymentStatus === 'rejected'): ?>
                                                <span class="orders-payment-rejected-badge inline-flex h-9 items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 text-xs font-extrabold text-red-700">
                                                    <i class="fa-solid fa-circle-xmark"></i>
                                                    <span>Rejected</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="orders-pagination flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs font-medium text-slate-500">Page <?= e($page) ?> of <?= e($totalPages) ?></p>
                            <nav class="flex items-center gap-1.5" aria-label="Order pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?<?= e(ordersQueryString(['page' => $page - 1])) ?>"
                                       class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-bold text-slate-600 hover:bg-slate-50">
                                        <i class="fa-solid fa-chevron-left text-[10px]"></i>
                                        Previous
                                    </a>
                                <?php endif; ?>

                                <?php
                                $pageStart = max(1, $page - 2);
                                $pageEnd = min($totalPages, $page + 2);
                                ?>
                                <?php for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
                                    <a href="?<?= e(ordersQueryString(['page' => $pageNumber])) ?>"
                                       class="grid h-9 w-9 place-items-center rounded-lg text-xs font-extrabold <?= $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
                                        <?= e($pageNumber) ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?= e(ordersQueryString(['page' => $page + 1])) ?>"
                                       class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-bold text-slate-600 hover:bg-slate-50">
                                        Next
                                        <i class="fa-solid fa-chevron-right text-[10px]"></i>
                                    </a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<?php foreach ($orders as $order): ?>
    <?php
    $modalPurchaseId = (int) $order['purchase_id'];
    $modalCustomerName = trim((string) $order['user_name']) !== '' ? (string) $order['user_name'] : 'Guest / Removed User';
    $modalDetails = isset($orderDetails[$modalPurchaseId]) ? $orderDetails[$modalPurchaseId] : [];

    $modalOrderStatus =
        isset($order['derived_order_status'])
            ? (string) $order['derived_order_status']
            : (string) $order['order_status'];

    $modalPaymentStatus =
        trim((string) $order['payment_status']) !== ''
            ? strtolower((string) $order['payment_status'])
            : 'unpaid';

    $modalPaymentSlipUrl =
        paymentSlipUrl(
            $modalPurchaseId,
            isset($order['payment_slip'])
                ? $order['payment_slip']
                : ''
        );
    ?>
    <div id="order-modal-<?= e($modalPurchaseId) ?>" class="order-detail-overlay orders-modal-scroll fixed inset-0 z-[70] hidden overflow-y-auto bg-slate-950/55 p-4 backdrop-blur-sm" onclick="closeOrderModalFromBackdrop(event, this)">
        <div class="order-detail-modal orders-modal-scroll mx-auto my-6 w-full max-w-4xl overflow-hidden rounded-3xl bg-white shadow-2xl">
            <div class="order-detail-header flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="order-detail-title text-xl font-extrabold text-slate-950"><?= e(orderNumber($modalPurchaseId)) ?></h2>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold ring-1 ring-inset <?= e(orderStatusClass($modalOrderStatus)) ?>">
                            <i class="fa-solid <?= e(orderStatusIcon($modalOrderStatus)) ?>"></i>
                            <?= e(orderStatusLabel($modalOrderStatus)) ?>
                        </span>
                    </div>
                    <p class="order-detail-date mt-1 text-sm text-slate-500">Placed <?= e(date('F j, Y \a\t g:i A', strtotime((string) $order['created_at']))) ?></p>
                </div>
                <button type="button" onclick="closeOrderModal('order-modal-<?= e($modalPurchaseId) ?>')" class="order-detail-close grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-500 transition hover:bg-slate-200 hover:text-slate-800">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="order-detail-body grid gap-5 p-5 sm:p-6 lg:grid-cols-[1fr_300px]">
                <div>
                    <div class="order-detail-main-title mb-3 flex items-center justify-between">
                        <h3 class="font-extrabold text-slate-900">Order Items</h3>
                        <span class="text-xs font-bold text-slate-400"><?= e(number_format((int) $order['total_quantity'])) ?> total units</span>
                    </div>

                    <div class="order-detail-items space-y-3">
                        <?php if (count($modalDetails) === 0): ?>
                            <div class="rounded-2xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500">No purchase detail records were found.</div>
                        <?php else: ?>
                            <?php foreach ($modalDetails as $detail): ?>
                                <?php
                                $detailProductName = trim((string) $detail['product_name']) !== '' ? (string) $detail['product_name'] : 'Removed product';
                                $detailPhoto = productPhotoUrl($detail['photo_path']);
                                ?>
                                <article class="order-detail-item flex gap-3 rounded-2xl border border-slate-200 p-3">
                                    <?php if ($detailPhoto !== ''): ?>
                                        <img src="<?= e($detailPhoto) ?>" alt="<?= e($detailProductName) ?>" class="order-detail-item-image h-16 w-16 shrink-0 rounded-xl object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='grid';">
                                        <div class="order-detail-item-fallback hidden h-16 w-16 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600">
                                            <i class="fa-solid fa-basket-shopping"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="order-detail-item-fallback grid h-16 w-16 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600">
                                            <i class="fa-solid fa-basket-shopping"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div class="min-w-0 flex-1">
                                        <p class="order-detail-product-name truncate text-sm font-extrabold text-slate-900"><?= e($detailProductName) ?></p>
                                        <p class="order-detail-product-meta mt-1 truncate text-xs text-slate-500">
                                            <?= e(trim((string) $detail['vendor_name']) !== '' ? $detail['vendor_name'] : 'Vendor unavailable') ?>
                                            <?php if (trim((string) $detail['market_name']) !== ''): ?>
                                                · <?= e($detail['market_name']) ?>
                                            <?php endif; ?>
                                        </p>
                                        <div class="order-detail-product-price-row mt-2 flex flex-wrap items-center justify-between gap-2 text-xs">
                                            <span class="font-semibold text-slate-500">Qty <?= e(number_format((int) $detail['quantity'])) ?> × <?= e(number_format((float) $detail['unit_price'], 2)) ?> MMK / <?= e(fm_unit_label($detail['unit'])) ?></span>
                                            <span class="font-extrabold text-slate-900"><?= e(number_format((float) $detail['subtotal'], 2)) ?> MMK</span>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="order-detail-sidebar space-y-4">
                    <section class="order-detail-side-card rounded-2xl bg-slate-50 p-4">
                        <h3 class="text-sm font-extrabold text-slate-900">Customer</h3>
                        <div class="mt-3 flex items-center gap-3">
                            <div class="order-detail-customer-avatar grid h-10 w-10 shrink-0 place-items-center rounded-full bg-green-100 text-xs font-extrabold text-green-700">
                                <?= e(orderCustomerInitials($modalCustomerName)) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="order-detail-customer-name truncate text-sm font-bold text-slate-800"><?= e($modalCustomerName) ?></p>
                                <p class="order-detail-small truncate text-xs text-slate-400"><?= e($order['email'] !== null ? $order['email'] : 'No email') ?></p>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center gap-2 text-xs text-slate-500">
                            <i class="fa-solid fa-phone w-4 text-center text-slate-400"></i>
                            <span><?= e($order['phone_number'] !== null ? $order['phone_number'] : 'No phone number') ?></span>
                        </div>
                    </section>

                    <section class="order-detail-side-card rounded-2xl border border-green-200 bg-green-50 p-4">
                        <h3 class="text-sm font-extrabold text-green-950">Fulfillment</h3>
                        <p class="mt-2 text-sm font-bold text-green-800"><?php echo e(fm_fulfillment_label($order['fulfillment_type'])); ?></p>
                        <?php if (strtolower((string) $order['fulfillment_type']) === 'delivery'): ?>
                            <div class="mt-3 space-y-1 text-xs text-slate-600">
                                <p><strong class="text-slate-800"><?php echo e($order['delivery_name']); ?></strong> · <?php echo e($order['delivery_phone']); ?></p>
                                <p class="leading-5"><?php echo e($order['delivery_address']); ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if (trim((string) $order['customer_note']) !== ''): ?>
                            <p class="mt-3 border-t border-green-200 pt-3 text-xs text-green-800"><strong>Note:</strong> <?php echo e($order['customer_note']); ?></p>
                        <?php endif; ?>
                    </section>

                    <section class="order-detail-side-card order-detail-payment-summary rounded-2xl border border-slate-200 p-4">
                        <h3 class="text-sm font-extrabold text-slate-900">Payment Summary</h3>
                        <div class="mt-3 space-y-2.5 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-slate-500">Method</span>
                                <span class="font-bold text-slate-800"><?= e(paymentMethodLabel($order['payment_method'])) ?></span>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <span class="text-slate-500">Payment status</span>

                                <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 ring-inset <?= e(paymentStatusClass($modalPaymentStatus)) ?>">
                                    <?= e(paymentStatusLabel($modalPaymentStatus)) ?>
                                </span>
                            </div>

                            <?php if (trim((string) $order['transaction_id']) !== ''): ?>

                                <div class="flex items-start justify-between gap-3">

                                    <span class="text-slate-500">
                                        Transaction ID
                                    </span>

                                    <span class="max-w-[160px] break-all text-right text-xs font-bold text-slate-800">
                                        <?= e($order['transaction_id']) ?>
                                    </span>
                                </div>

                            <?php endif; ?>

                            <?php if ($modalPaymentSlipUrl !== ''): ?>

                                <div class="border-t border-slate-100 pt-3">

                                    <div class="mb-2 flex items-center justify-between gap-3">

                                        <span class="text-slate-500">
                                            Payment Slip
                                        </span>

                                        <a href="<?= e($modalPaymentSlipUrl) ?>"
                                           target="_blank"
                                           rel="noopener"
                                           class="text-[10px] font-extrabold text-blue-600 hover:text-blue-700">

                                            <i class="fa-solid fa-up-right-from-square mr-1"></i>
                                            Full Size
                                        </a>
                                    </div>

                                    <a href="<?= e($modalPaymentSlipUrl) ?>"
                                       target="_blank"
                                       rel="noopener"
                                       class="block overflow-hidden rounded-xl border border-slate-200 bg-slate-50">

                                        <img src="<?= e($modalPaymentSlipUrl) ?>"
                                             alt="Customer Payment Slip"
                                             class="order-detail-slip h-44 w-full object-contain"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">

                                        <div class="hidden h-32 items-center justify-center px-4 text-center text-xs font-bold text-red-600">
                                            <div>
                                                <i class="fa-solid fa-image-slash mb-2 block text-xl"></i>
                                                Payment slip image could not be loaded.
                                            </div>
                                        </div>
                                    </a>
                                </div>

                            <?php elseif ($modalPaymentStatus === 'pending_verification'): ?>

                                <div class="rounded-xl bg-amber-50 px-3 py-2.5 text-xs font-bold text-amber-700">
                                    <i class="fa-solid fa-triangle-exclamation mr-1.5"></i>
                                    No payment slip uploaded.
                                </div>

                            <?php endif; ?>

                            <div class="flex items-center justify-between gap-3">
                                <span class="text-slate-500">Item lines</span>
                                <span class="font-bold text-slate-800"><?= e(number_format((int) $order['item_lines'])) ?></span>
                            </div>
                            <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-3">
                                <span class="font-extrabold text-slate-900">Total</span>
                                <span class="order-detail-total text-lg font-extrabold text-green-700"><?= e(number_format((float) $order['total_amount'], 2)) ?> MMK</span>
                            </div>
                        </div>
                    </section>

                    <section class="order-detail-side-card order-detail-control-card rounded-2xl border border-green-200 bg-green-50 p-4">

                        <p class="text-xs font-extrabold uppercase tracking-wide text-green-800">
                            Vendor-controlled Order
                        </p>

                        <p class="mt-2 text-sm font-bold text-green-900">
                            <?= e(orderStatusLabel($modalOrderStatus)) ?>
                        </p>

                        <p class="mt-1 text-xs leading-5 text-green-700">
                            Vendors control Confirm / Reject / Complete.
                            Admin monitors the order and verifies customer payment.
                        </p>

                        <?php if ($modalPaymentStatus === 'pending_verification'): ?>

                            <div class="mt-3 rounded-xl bg-white/80 p-3">

                                <p class="text-[11px] font-bold leading-5 text-slate-600">
                                    Check the payment slip, amount, selected payment method
                                    and Farmers Market receiver account before verification.
                                </p>

                                <div class="order-detail-control-actions mt-3 grid gap-2">

                                    <form method="post"
                                          action="orders.php">

                                        <input type="hidden"
                                               name="payment_csrf_token"
                                               value="<?= e($paymentCsrfToken) ?>">

                                        <input type="hidden"
                                               name="payment_action"
                                               value="verify_payment">

                                        <input type="hidden"
                                               name="purchase_id"
                                               value="<?= e($modalPurchaseId) ?>">

                                        <button type="submit"
                                                onclick="return confirm('You checked the payment slip. Verify this payment as Paid?');"
                                                class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-sm font-extrabold text-white transition hover:bg-green-700">

                                            <i class="fa-solid fa-circle-check"></i>
                                            Verify Payment
                                        </button>
                                    </form>

                                    <button type="button"
                                            onclick="openOrderPaymentRejectModal(<?= e($modalPurchaseId) ?>)"
                                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-red-50 px-4 text-sm font-extrabold text-red-600 ring-1 ring-inset ring-red-200 transition hover:bg-red-100">

                                        <i class="fa-solid fa-circle-xmark"></i>
                                        Reject Payment
                                    </button>
                                </div>
                            </div>

                        <?php elseif ($modalPaymentStatus === 'paid'): ?>

                            <div class="mt-3 flex items-center gap-2 rounded-xl bg-white/70 px-3 py-2.5 text-xs font-bold text-green-700">
                                <i class="fa-solid fa-circle-check"></i>
                                Customer payment verified
                            </div>

                        <?php elseif ($modalPaymentStatus === 'rejected'): ?>

                            <div class="mt-3 rounded-xl bg-white/70 px-3 py-2.5 text-xs font-bold text-red-700">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    Payment proof rejected
                                </div>

                                <?php if (trim((string) $order['payment_rejection_reason']) !== ''): ?>

                                    <p class="mt-1 font-medium">
                                        <?= e($order['payment_rejection_reason']) ?>
                                    </p>

                                <?php endif; ?>
                            </div>

                        <?php else: ?>

                            <div class="mt-3 flex items-center gap-2 rounded-xl bg-white/70 px-3 py-2.5 text-xs font-bold text-amber-700">
                                <i class="fa-solid fa-wallet"></i>
                                Waiting for customer payment
                            </div>

                        <?php endif; ?>
                    </section>
                </aside>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<div id="orderPaymentRejectModal"
     class="orders-modal-scroll fixed inset-0 z-[120] hidden items-center justify-center overflow-y-auto bg-slate-950/65 p-4 backdrop-blur-sm">

    <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">

            <div>
                <p class="text-[10px] font-extrabold uppercase tracking-wider text-red-500">
                    Payment Verification
                </p>

                <h3 class="mt-1 text-lg font-extrabold text-slate-950">
                    Reject Payment Proof
                </h3>
            </div>

            <button type="button"
                    onclick="closeOrderPaymentRejectModal()"
                    class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100">

                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post"
              action="orders.php"
              class="p-5">

            <input type="hidden"
                   name="payment_csrf_token"
                   value="<?= e($paymentCsrfToken) ?>">

            <input type="hidden"
                   name="payment_action"
                   value="reject_payment">

            <input type="hidden"
                   id="orderRejectPaymentPurchaseId"
                   name="purchase_id"
                   value="">

            <label for="orderRejectPaymentReason"
                   class="block text-xs font-extrabold text-slate-700">

                Rejection Reason
            </label>

            <textarea id="orderRejectPaymentReason"
                      name="rejection_reason"
                      rows="4"
                      maxlength="255"
                      required
                      placeholder="e.g. Amount does not match, payment slip is unclear, or proof is invalid"
                      class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-3 text-sm outline-none focus:border-red-400 focus:ring-2 focus:ring-red-100"></textarea>

            <div class="mt-5 flex justify-end gap-2">

                <button type="button"
                        onclick="closeOrderPaymentRejectModal()"
                        class="rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-extrabold text-slate-600">

                    Cancel
                </button>

                <button type="submit"
                        onclick="return confirm('Reject this payment proof?');"
                        class="rounded-xl bg-red-600 px-4 py-2.5 text-xs font-extrabold text-white hover:bg-red-700">

                    Reject Payment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    /*
    |--------------------------------------------------------------------------
    | Admin Orders Notification
    |--------------------------------------------------------------------------
    | - Adds a red notification count beside "Orders" in the Admin sidebar.
    | - Clicking the banner action scrolls to the first Payment Review button.
    | - The notification naturally disappears when there is no longer any
    |   payment_status = pending_verification record.
    |--------------------------------------------------------------------------
    */
    var adminPendingPaymentCount =
        <?php echo (int) $pendingPaymentVerificationCount; ?>;

    function installAdminOrdersNotificationBadge() {
        if (adminPendingPaymentCount <= 0) {
            return;
        }

        var links =
            document.querySelectorAll(
                'a[href="orders.php"], a[href="./orders.php"], a[href$="/admin/orders.php"]'
            );

        if (!links || links.length === 0) {
            return;
        }

        var orderLink = links[0];

        if (
            orderLink.querySelector(
                '.admin-orders-notification-badge'
            )
        ) {
            return;
        }

        orderLink.style.display = 'flex';
        orderLink.style.alignItems = 'center';

        var badge = document.createElement('span');

        badge.className =
            'admin-orders-notification-badge';

        badge.setAttribute(
            'aria-label',
            adminPendingPaymentCount +
            ' payment notifications'
        );

        badge.textContent =
            adminPendingPaymentCount > 99
                ? '99+'
                : String(
                    adminPendingPaymentCount
                );

        orderLink.appendChild(badge);
    }

    function focusFirstPaymentReview() {
        var paymentButton =
            document.querySelector(
                '.orders-payment-review-button'
            );

        if (!paymentButton) {
            /*
            | A pending payment can be on a later pagination page.
            | Reload the Orders list at page 1 with newest items first.
            */
            window.location.href =
                'orders.php?sort=newest&page=1';
            return;
        }

        var row =
            paymentButton.closest('tr');

        if (row) {
            row.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });

            row.classList.add(
                'orders-payment-attention'
            );

            window.setTimeout(
                function () {
                    row.classList.remove(
                        'orders-payment-attention'
                    );
                },
                2800
            );
        }

        paymentButton.focus();
    }

    document.addEventListener(
        'DOMContentLoaded',
        function () {
            installAdminOrdersNotificationBadge();

            if (adminPendingPaymentCount > 0) {
                document.title =
                    '(' +
                    adminPendingPaymentCount +
                    ') Orders | Local Farmers Marketplace';
            }
        }
    );

    function openOrderModal(id) {
        var modal = document.getElementById(id);
        if (!modal) {
            return;
        }

        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function openOrderPaymentRejectModal(purchaseId) {
        var modal =
            document.getElementById(
                'orderPaymentRejectModal'
            );

        var purchaseInput =
            document.getElementById(
                'orderRejectPaymentPurchaseId'
            );

        var reason =
            document.getElementById(
                'orderRejectPaymentReason'
            );

        if (!modal || !purchaseInput) {
            return;
        }

        purchaseInput.value = purchaseId;

        if (reason) {
            reason.value = '';
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeOrderPaymentRejectModal() {
        var modal =
            document.getElementById(
                'orderPaymentRejectModal'
            );

        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function closeOrderModal(id) {
        var modal = document.getElementById(id);
        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    function closeOrderModalFromBackdrop(event, modal) {
        if (event.target === modal) {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    }


    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        var rejectModal =
            document.getElementById(
                'orderPaymentRejectModal'
            );

        if (
            rejectModal &&
            !rejectModal.classList.contains('hidden')
        ) {
            closeOrderPaymentRejectModal();
            return;
        }

        var openModal =
            document.querySelector(
                '[id^="order-modal-"]:not(.hidden)'
            );

        if (openModal) {
            openModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    });

    <?php
    $openPaymentId = isset($_GET['open_payment'])
        ? max(0, (int) $_GET['open_payment'])
        : 0;
    ?>

    <?php if ($openPaymentId > 0): ?>

        document.addEventListener(
            'DOMContentLoaded',
            function () {
                openOrderModal(
                    'order-modal-<?php echo (int) $openPaymentId; ?>'
                );
            }
        );

    <?php endif; ?>
</script>
</body>
</html>