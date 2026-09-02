<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
fm_require_role('user');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Final Payment - QR + Admin Verification
|--------------------------------------------------------------------------
| Save as:
| C:\xampp\htdocs\farmer_marketplace\pay_order.php
|
| Static QR image paths:
| uploads/payment_qr/kbzpay.png
| uploads/payment_qr/wave_money.png
|
| PHP 7.1 compatible.
|--------------------------------------------------------------------------
*/

function pay_e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function pay_redirect($location)
{
    header('Location: ' . $location);
    exit;
}

function pay_csrf_token()
{
    if (
        !isset($_SESSION['pay_order_csrf']) ||
        !is_string($_SESSION['pay_order_csrf']) ||
        $_SESSION['pay_order_csrf'] === ''
    ) {
        $_SESSION['pay_order_csrf'] =
            bin2hex(random_bytes(24));
    }

    return $_SESSION['pay_order_csrf'];
}

function pay_verify_csrf($token)
{
    return isset($_SESSION['pay_order_csrf']) &&
        is_string($_SESSION['pay_order_csrf']) &&
        hash_equals(
            $_SESSION['pay_order_csrf'],
            (string) $token
        );
}

function pay_method_label($method)
{
    $method = strtolower(
        trim((string) $method)
    );

    if ($method === 'kbzpay') {
        return 'KBZPay';
    }

    if ($method === 'wave_money') {
        return 'Wave Money';
    }

    return 'Not selected';
}

function pay_asset_url($path)
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

function pay_upload_slip($file, $purchaseId)
{
    if (
        !isset($file['error']) ||
        (int) $file['error'] !== UPLOAD_ERR_OK
    ) {
        throw new Exception(
            'Please upload your payment slip.'
        );
    }

    if (
        !isset($file['size']) ||
        (int) $file['size'] <= 0
    ) {
        throw new Exception(
            'The uploaded payment slip is empty.'
        );
    }

    if ((int) $file['size'] > 5 * 1024 * 1024) {
        throw new Exception(
            'Payment slip must be 5 MB or smaller.'
        );
    }

    if (
        !isset($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        throw new Exception(
            'Invalid payment slip upload.'
        );
    }

    $imageInfo = @getimagesize(
        $file['tmp_name']
    );

    if ($imageInfo === false) {
        throw new Exception(
            'Payment slip must be a valid image.'
        );
    }

    $mime = isset($imageInfo['mime'])
        ? strtolower((string) $imageInfo['mime'])
        : '';

    $extensions = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    );

    if (!isset($extensions[$mime])) {
        throw new Exception(
            'Only JPG, PNG or WEBP payment slips are allowed.'
        );
    }

    $relativeDir =
        'uploads/payment_slips';

    $absoluteDir =
        __DIR__ . '/' . $relativeDir;

    if (!is_dir($absoluteDir)) {
        if (
            !mkdir(
                $absoluteDir,
                0775,
                true
            ) &&
            !is_dir($absoluteDir)
        ) {
            throw new Exception(
                'Could not create payment slip upload folder.'
            );
        }
    }

    $fileName =
        'order_' .
        (int) $purchaseId .
        '_' .
        bin2hex(random_bytes(8)) .
        '.' .
        $extensions[$mime];

    $absolutePath =
        $absoluteDir .
        '/' .
        $fileName;

    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $absolutePath
        )
    ) {
        throw new Exception(
            'Could not save payment slip.'
        );
    }

    return array(
        'relative_path' =>
            $relativeDir .
            '/' .
            $fileName,
        'absolute_path' =>
            $absolutePath
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
        'Database connection is not available.'
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

$purchaseId = isset($_GET['purchase_id'])
    ? max(0, (int) $_GET['purchase_id'])
    : 0;

if ($purchaseId <= 0) {
    pay_redirect('my_orders.php');
}

/*
|--------------------------------------------------------------------------
| Order
|--------------------------------------------------------------------------
*/

$orderStatement = $pdo->prepare(
    "SELECT
        purchase_id,
        user_id,
        total_amount,
        payment_method,
        payment_status,
        transaction_id,
        payment_slip,
        payment_submitted_at,
        paid_at,
        verified_at,
        payment_rejection_reason,
        order_status,
        created_at
     FROM purchase_process
     WHERE purchase_id = :purchase_id
       AND user_id = :user_id
     LIMIT 1"
);

$orderStatement->execute(array(
    'purchase_id' => $purchaseId,
    'user_id' => $userId
));

$order = $orderStatement->fetch();

if (!$order) {
    pay_redirect('my_orders.php');
}

/*
|--------------------------------------------------------------------------
| Vendor Decisions / Final Amount
|--------------------------------------------------------------------------
*/

$vendorStatement = $pdo->prepare(
    "SELECT
        vo.vendor_order_id,
        vo.vendor_id,
        vo.subtotal,
        vo.order_status,
        vo.rejection_reason,
        v.vendor_name
     FROM vendor_orders vo
     INNER JOIN vendors v
        ON v.vendor_id = vo.vendor_id
     WHERE vo.purchase_id = :purchase_id
     ORDER BY vo.vendor_order_id ASC"
);

$vendorStatement->execute(array(
    'purchase_id' => $purchaseId
));

$vendorOrders =
    $vendorStatement->fetchAll();

$totalVendorCount =
    count($vendorOrders);

$pendingCount = 0;
$acceptedCount = 0;
$rejectedCount = 0;
$finalAmount = 0;

foreach ($vendorOrders as $vendorOrder) {
    $status = strtolower(
        (string) $vendorOrder['order_status']
    );

    if ($status === 'pending') {
        $pendingCount++;
    } elseif ($status === 'rejected') {
        $rejectedCount++;
    } elseif (
        $status === 'confirmed' ||
        $status === 'completed'
    ) {
        $acceptedCount++;

        $finalAmount +=
            (float) $vendorOrder['subtotal'];
    }
}

$paymentReady =
    $totalVendorCount > 0 &&
    $pendingCount === 0 &&
    $acceptedCount > 0;

/*
|--------------------------------------------------------------------------
| QR Configuration
|--------------------------------------------------------------------------
| Static paths work without payment_accounts.
| If payment_accounts exists and has an active QR path,
| it overrides the static path.
|--------------------------------------------------------------------------
*/

$paymentAccounts = array(
    'kbzpay' => array(
        'label' => 'KBZPay',
        'account_name' => '',
        'account_phone' => '',
        'qr_image_path' =>
            'uploads/payment_qr/kbzpay.png'
    ),
    'wave_money' => array(
        'label' => 'Wave Money',
        'account_name' => '',
        'account_phone' => '',
        'qr_image_path' =>
            'uploads/payment_qr/wave_money.png'
    )
);

try {
    $accountStatement = $pdo->query(
        "SELECT
            payment_method,
            account_name,
            account_phone,
            qr_image_path
         FROM payment_accounts
         WHERE is_active = 1
           AND payment_method IN (
               'kbzpay',
               'wave_money'
           )"
    );

    foreach ($accountStatement->fetchAll() as $account) {
        $method = strtolower(
            trim(
                (string)
                $account['payment_method']
            )
        );

        if (
            isset($paymentAccounts[$method]) &&
            trim(
                (string)
                $account['qr_image_path']
            ) !== ''
        ) {
            $databaseQrPath =
                trim(
                    (string)
                    $account['qr_image_path']
                );

            $databaseQrAbsolute =
                __DIR__ .
                '/' .
                ltrim(
                    str_replace(
                        '\\',
                        '/',
                        $databaseQrPath
                    ),
                    '/'
                );

            /*
            | Use the database QR path only when the actual image exists.
            | Otherwise keep the working static fallback path above.
            */
            if (is_file($databaseQrAbsolute)) {
                $paymentAccounts[$method]['account_name'] =
                    (string) $account['account_name'];

                $paymentAccounts[$method]['account_phone'] =
                    (string) $account['account_phone'];

                $paymentAccounts[$method]['qr_image_path'] =
                    $databaseQrPath;
            }
        }
    }
} catch (Exception $exception) {
    /*
    | payment_accounts is optional here.
    | Static QR paths above will still work.
    */
}

foreach ($paymentAccounts as $method => $account) {
    $relativePath =
        trim(
            (string)
            $account['qr_image_path']
        );

    $paymentAccounts[$method]['qr_url'] =
        pay_asset_url($relativePath);

    $paymentAccounts[$method]['qr_exists'] =
        $relativePath !== '' &&
        is_file(
            __DIR__ .
            '/' .
            ltrim(
                str_replace(
                    '\\',
                    '/',
                    $relativePath
                ),
                '/'
            )
        );
}

/*
|--------------------------------------------------------------------------
| Payment State
|--------------------------------------------------------------------------
*/

$paymentStatus = strtolower(
    trim(
        (string) $order['payment_status']
    )
);

if ($paymentStatus === '') {
    $paymentStatus = 'unpaid';
}

$isPendingVerification =
    $paymentStatus ===
    'pending_verification';

$isPaid =
    $paymentStatus === 'paid';

$isRejected =
    $paymentStatus === 'rejected';

$canSubmit =
    $paymentReady &&
    (
        $paymentStatus === 'unpaid' ||
        $paymentStatus === 'rejected'
    );

$formError = '';

$selectedMethod =
    isset($_POST['payment_method'])
        ? strtolower(
            trim(
                (string)
                $_POST['payment_method']
            )
        )
        : (
            $isRejected
                ? strtolower(
                    trim(
                        (string)
                        $order['payment_method']
                    )
                )
                : ''
        );


/*
|--------------------------------------------------------------------------
| Submit Payment Proof
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] ===
        'submit_payment'
) {
    $csrf = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    $selectedMethod =
        isset($_POST['payment_method'])
            ? strtolower(
                trim(
                    (string)
                    $_POST['payment_method']
                )
            )
            : '';

    $allowedMethods = array(
        'kbzpay',
        'wave_money'
    );

    $savedSlip = null;

    try {
        if (!pay_verify_csrf($csrf)) {
            throw new Exception(
                'Your payment session expired. Please refresh and try again.'
            );
        }

        if (!$paymentReady) {
            throw new Exception(
                'Payment is not available until all vendors respond.'
            );
        }

        if (
            !in_array(
                $paymentStatus,
                array(
                    'unpaid',
                    'rejected'
                ),
                true
            )
        ) {
            throw new Exception(
                'This payment can no longer be submitted.'
            );
        }

        if (
            !in_array(
                $selectedMethod,
                $allowedMethods,
                true
            )
        ) {
            throw new Exception(
                'Please choose KBZPay or Wave Money.'
            );
        }

        if (
            !isset(
                $paymentAccounts[
                    $selectedMethod
                ]
            )
        ) {
            throw new Exception(
                'Invalid payment method.'
            );
        }

        if (
            !$paymentAccounts[
                $selectedMethod
            ]['qr_exists']
        ) {
            throw new Exception(
                'QR image is missing for ' .
                pay_method_label(
                    $selectedMethod
                ) .
                '. Put the QR image in uploads/payment_qr/.'
            );
        }

        if (
            !isset(
                $_FILES['payment_slip']
            )
        ) {
            throw new Exception(
                'Please upload the payment slip.'
            );
        }

        $savedSlip =
            pay_upload_slip(
                $_FILES['payment_slip'],
                $purchaseId
            );

        $pdo->beginTransaction();

        $lockVendorStatement =
            $pdo->prepare(
                "SELECT
                    vendor_order_id,
                    subtotal,
                    order_status
                 FROM vendor_orders
                 WHERE purchase_id =
                       :purchase_id
                 FOR UPDATE"
            );

        $lockVendorStatement->execute(
            array(
                'purchase_id' =>
                    $purchaseId
            )
        );

        $lockedVendors =
            $lockVendorStatement->fetchAll();

        $lockedPending = 0;
        $lockedAccepted = 0;
        $lockedFinalAmount = 0;

        foreach (
            $lockedVendors
            as $lockedVendor
        ) {
            $lockedStatus =
                strtolower(
                    (string)
                    $lockedVendor[
                        'order_status'
                    ]
                );

            if ($lockedStatus === 'pending') {
                $lockedPending++;
            } elseif (
                $lockedStatus === 'confirmed' ||
                $lockedStatus === 'completed'
            ) {
                $lockedAccepted++;

                $lockedFinalAmount +=
                    (float)
                    $lockedVendor[
                        'subtotal'
                    ];
            }
        }

        if (
            $lockedPending > 0 ||
            $lockedAccepted <= 0
        ) {
            throw new Exception(
                'Vendor status changed. Payment is not ready yet.'
            );
        }

        $lockOrderStatement =
            $pdo->prepare(
                "SELECT
                    payment_status,
                    payment_slip
                 FROM purchase_process
                 WHERE purchase_id =
                       :purchase_id
                   AND user_id =
                       :user_id
                 LIMIT 1
                 FOR UPDATE"
            );

        $lockOrderStatement->execute(
            array(
                'purchase_id' =>
                    $purchaseId,
                'user_id' =>
                    $userId
            )
        );

        $lockedOrder =
            $lockOrderStatement->fetch();

        if (!$lockedOrder) {
            throw new Exception(
                'Order was not found.'
            );
        }

        $lockedPaymentStatus =
            strtolower(
                trim(
                    (string)
                    $lockedOrder[
                        'payment_status'
                    ]
                )
            );

        if (
            !in_array(
                $lockedPaymentStatus,
                array(
                    'unpaid',
                    'rejected'
                ),
                true
            )
        ) {
            throw new Exception(
                'This payment has already been submitted.'
            );
        }

        $oldSlipPath =
            trim(
                (string)
                $lockedOrder[
                    'payment_slip'
                ]
            );

        $updateStatement =
            $pdo->prepare(
                "UPDATE purchase_process
                 SET
                    total_amount =
                        :final_amount,
                    payment_method =
                        :payment_method,
                    payment_status =
                        'pending_verification',
                    transaction_id = NULL,
                    payment_slip =
                        :payment_slip,
                    payment_submitted_at =
                        NOW(),
                    paid_at = NULL,
                    verified_at = NULL,
                    verified_by = NULL,
                    payment_rejection_reason =
                        NULL
                 WHERE purchase_id =
                       :purchase_id
                   AND user_id =
                       :user_id
                   AND payment_status IN (
                       'unpaid',
                       'rejected'
                   )"
            );

        $updateStatement->execute(
            array(
                'final_amount' =>
                    $lockedFinalAmount,
                'payment_method' =>
                    $selectedMethod,
                'payment_slip' =>
                    $savedSlip[
                        'relative_path'
                    ],
                'purchase_id' =>
                    $purchaseId,
                'user_id' =>
                    $userId
            )
        );

        if (
            $updateStatement->rowCount()
            !== 1
        ) {
            throw new Exception(
                'Payment proof could not be submitted.'
            );
        }

        $pdo->commit();

        if ($oldSlipPath !== '') {
            $oldSlipAbsolute =
                __DIR__ .
                '/' .
                ltrim(
                    str_replace(
                        '\\',
                        '/',
                        $oldSlipPath
                    ),
                    '/'
                );

            if (
                is_file(
                    $oldSlipAbsolute
                ) &&
                realpath(
                    $oldSlipAbsolute
                ) !==
                realpath(
                    $savedSlip[
                        'absolute_path'
                    ]
                )
            ) {
                @unlink(
                    $oldSlipAbsolute
                );
            }
        }

        pay_redirect(
            'pay_order.php?purchase_id=' .
            $purchaseId .
            '&submitted=1'
        );
    } catch (Exception $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (
            is_array($savedSlip) &&
            isset($savedSlip['absolute_path']) &&
            is_file($savedSlip['absolute_path'])
        ) {
            @unlink($savedSlip['absolute_path']);
        }

        if ($exception instanceof PDOException) {
            error_log('Payment submission database error: ' . $exception->getMessage());
            $formError = 'The database could not save your payment submission. Please try again.';
        } else {
            $formError = $exception->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Reload after submit
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['submitted']) &&
    $_GET['submitted'] === '1'
) {
    $orderStatement->execute(array(
        'purchase_id' => $purchaseId,
        'user_id' => $userId
    ));

    $order = $orderStatement->fetch();

    $paymentStatus =
        strtolower(
            trim(
                (string)
                $order['payment_status']
            )
        );

    $isPendingVerification =
        $paymentStatus ===
        'pending_verification';

    $isPaid =
        $paymentStatus === 'paid';

    $isRejected =
        $paymentStatus === 'rejected';

    $canSubmit =
        $paymentReady &&
        (
            $paymentStatus === 'unpaid' ||
            $paymentStatus === 'rejected'
        );
}

$csrfToken =
    pay_csrf_token();

/*
|--------------------------------------------------------------------------
| Customer-Facing Payment State
|--------------------------------------------------------------------------
*/

$displayHeading = 'Final Payment';
$displaySubtitle =
    'Select KBZPay or Wave Money to display the QR code.';

if ($isPendingVerification) {
    $displayHeading = 'Payment Verification';
    $displaySubtitle = '';
} elseif ($isPaid) {
    $displayHeading = 'Payment Verified';
    $displaySubtitle = '';
} elseif ($isRejected) {
    $displayHeading = 'Payment Resubmission';
    $displaySubtitle =
        'Your previous payment proof was rejected. Please submit a new payment slip.';
}

$submittedAtLabel = '';

if (
    isset($order['payment_submitted_at']) &&
    trim(
        (string) $order['payment_submitted_at']
    ) !== ''
) {
    $submittedAtTimestamp = strtotime(
        (string) $order['payment_submitted_at']
    );

    if ($submittedAtTimestamp !== false) {
        $submittedAtLabel =
            date(
                'M d, Y, h:i A',
                $submittedAtTimestamp
            );
    }
}

$verifiedAtLabel = '';

if (
    isset($order['verified_at']) &&
    trim(
        (string) $order['verified_at']
    ) !== ''
) {
    $verifiedAtTimestamp = strtotime(
        (string) $order['verified_at']
    );

    if ($verifiedAtTimestamp !== false) {
        $verifiedAtLabel =
            date(
                'M d, Y, h:i A',
                $verifiedAtTimestamp
            );
    }
}
?>
<?php
$pageTitle =
    $displayHeading .
    ' | Local Farmers Marketplace';

require __DIR__ . '/header.php';
?>
<style>
    :root {
        --pay-green: #16a34a;
        --pay-green-dark: #15803d;
        --pay-green-soft: #f0fdf4;
        --pay-blue-soft: #eff6ff;
        --pay-amber-soft: #fffbeb;
        --pay-red-soft: #fef2f2;
        --pay-slate-950: #0f172a;
        --pay-slate-800: #1e293b;
        --pay-slate-700: #334155;
        --pay-slate-500: #64748b;
        --pay-slate-400: #94a3b8;
        --pay-slate-300: #cbd5e1;
        --pay-slate-200: #e2e8f0;
        --pay-slate-100: #f1f5f9;
    }

    body {
        background: #f8fafc;
    }

    /* Compact desktop width: does not fill the whole screen */
    #payOrderPage {
        display: block !important;
        width: calc(100% - 56px) !important;
        max-width: 820px !important;
        margin: 38px auto 40px !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }

    .payment-card {
        width: 100% !important;
        max-width: 820px !important;
        margin: 0 auto !important;
        overflow: hidden;
        border: 1px solid var(--pay-slate-200);
        border-radius: 18px;
        background: #ffffff;
        box-shadow:
            0 1px 2px rgba(15, 23, 42, .03),
            0 10px 28px rgba(15, 23, 42, .06);
        box-sizing: border-box !important;
    }

    .payment-header {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        padding: 18px 22px;
        border-bottom: 1px solid var(--pay-slate-100);
        background:
            radial-gradient(circle at right top, rgba(34, 197, 94, .11), transparent 38%),
            linear-gradient(135deg, #ffffff 0%, #f8fff9 100%);
    }

    .payment-header::after {
        content: "";
        position: absolute;
        right: -55px;
        top: -85px;
        width: 210px;
        height: 210px;
        border-radius: 999px;
        background: rgba(34, 197, 94, .05);
        pointer-events: none;
    }

    .header-copy,
    .header-total {
        position: relative;
        z-index: 1;
    }

    .order-label {
        margin: 0 0 5px;
        color: var(--pay-green);
        font-size: 10px;
        font-weight: 900;
        letter-spacing: .16em;
        text-transform: uppercase;
    }

    .payment-title {
        margin: 0;
        color: var(--pay-slate-950);
        font-size: 24px;
        line-height: 1.08;
        font-weight: 900;
    }

    .payment-subtitle {
        margin: 6px 0 0;
        color: var(--pay-slate-500);
        font-size: 12px;
        line-height: 1.5;
    }

    .header-total {
        min-width: 145px;
        padding: 8px 11px;
        border: 1px solid #bbf7d0;
        border-radius: 12px;
        background: rgba(255, 255, 255, .88);
        text-align: right;
    }

    .header-total-label {
        display: block;
        color: var(--pay-green);
        font-size: 8px;
        font-weight: 900;
        letter-spacing: .13em;
        text-transform: uppercase;
    }

    .header-total-value {
        display: block;
        margin-top: 3px;
        color: var(--pay-green-dark);
        font-size: 18px;
        line-height: 1.1;
        font-weight: 900;
    }

    /* Vendor summary is now a horizontal section, not a right sidebar */
    .vendor-section {
        padding: 14px 22px;
        border-bottom: 1px solid var(--pay-slate-100);
        background: #fbfdfb;
    }

    .vendor-section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 10px;
    }

    .vendor-heading {
        margin: 0;
        color: var(--pay-slate-950);
        font-size: 13px;
        font-weight: 900;
    }

    .vendor-help {
        margin: 2px 0 0;
        color: var(--pay-slate-500);
        font-size: 10px;
    }

    .vendor-list {
        display: grid;
        gap: 8px;
    }

    .vendor-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 8px 10px;
        border: 1px solid var(--pay-slate-200);
        border-radius: 12px;
        background: #ffffff;
    }

    .vendor-name {
        margin: 0;
        color: var(--pay-slate-950);
        font-size: 10px;
        font-weight: 900;
    }

    .vendor-right {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .vendor-status {
        display: inline-flex;
        padding: 3px 7px;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 900;
        text-transform: capitalize;
    }

    .vendor-status.green {
        background: #dcfce7;
        color: #166534;
    }

    .vendor-status.red {
        background: #fee2e2;
        color: #991b1b;
    }

    .vendor-status.amber {
        background: #fef3c7;
        color: #92400e;
    }

    .vendor-amount {
        min-width: 92px;
        color: var(--pay-green-dark);
        font-size: 10px;
        font-weight: 900;
        text-align: right;
    }

    .summary-note {
        margin-top: 8px;
        color: var(--pay-slate-500);
        font-size: 9px;
        line-height: 1.45;
    }

    .payment-content {
        padding: 18px 22px 22px;
    }

    .section-heading {
        display: flex;
        align-items: center;
        gap: 9px;
        margin-bottom: 15px;
    }

    .section-icon {
        display: grid;
        place-items: center;
        width: 28px;
        height: 28px;
        flex: 0 0 28px;
        border-radius: 10px;
        background: #dcfce7;
        color: var(--pay-green-dark);
        font-size: 12px;
    }

    .section-title {
        margin: 0;
        color: var(--pay-slate-950);
        font-size: 14px;
        font-weight: 900;
    }

    .section-help {
        margin: 2px 0 0;
        color: var(--pay-slate-500);
        font-size: 10px;
    }

    .pay-step + .pay-step {
        margin-top: 17px;
    }

    .step-label {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
        color: var(--pay-slate-700);
        font-size: 10px;
        font-weight: 900;
    }

    .step-no {
        display: grid;
        place-items: center;
        width: 22px;
        height: 22px;
        flex: 0 0 22px;
        border-radius: 999px;
        background: #dcfce7;
        color: var(--pay-green-dark);
        font-size: 10px;
        font-weight: 900;
    }

    .payment-methods {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .payment-method {
        display: block;
        cursor: pointer;
    }

    .payment-method input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .method-card {
        position: relative;
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 54px;
        padding: 8px 10px;
        border: 1.5px solid var(--pay-slate-200);
        border-radius: 12px;
        background: #ffffff;
        transition: .2s ease;
    }

    .payment-method:hover .method-card {
        border-color: #86efac;
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(22, 163, 74, .06);
    }

    .payment-method input:checked + .method-card {
        border-color: #22c55e;
        background: #f0fdf4;
        box-shadow: 0 0 0 3px rgba(34, 197, 94, .07);
    }

    .method-icon {
        display: grid;
        place-items: center;
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        border-radius: 11px;
        font-size: 13px;
    }

    .method-icon.kbz {
        background: #dbeafe;
        color: #2563eb;
    }

    .method-icon.wave {
        background: #fef3c7;
        color: #d97706;
    }

    .method-name {
        display: block;
        color: var(--pay-slate-950);
        font-size: 12px;
        font-weight: 900;
    }

    .method-note {
        display: block;
        margin-top: 2px;
        color: var(--pay-slate-500);
        font-size: 9px;
    }

    .method-check {
        position: absolute;
        right: 10px;
        top: 10px;
        display: none;
        width: 19px;
        height: 19px;
        place-items: center;
        border-radius: 999px;
        background: #22c55e;
        color: #ffffff;
        font-size: 8px;
    }

    .payment-method input:checked + .method-card .method-check {
        display: grid;
    }

    .payment-qr-panel {
        margin-top: 11px;
        padding: 11px;
        border-radius: 12px;
        text-align: center;
    }

    .payment-qr-panel.hidden {
        display: none;
    }

    .payment-qr-panel.kbz-panel {
        border: 1px solid #bfdbfe;
        background: var(--pay-blue-soft);
    }

    .payment-qr-panel.wave-panel {
        border: 1px solid #fde68a;
        background: var(--pay-amber-soft);
    }

    .qr-title {
        color: var(--pay-slate-950);
        font-size: 10px;
        font-weight: 900;
    }

    .qr-box {
        width: 118px;
        height: 118px;
        margin: 8px auto 0;
        padding: 7px;
        border: 1px solid var(--pay-slate-200);
        border-radius: 12px;
        background: #ffffff;
    }

    .qr-box img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .qr-amount {
        margin-top: 8px;
        color: var(--pay-green-dark);
        font-size: 10px;
        font-weight: 900;
    }

    .upload-box {
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 9px 10px;
        border: 1.5px dashed var(--pay-slate-300);
        border-radius: 12px;
        background: #f8fafc;
        cursor: pointer;
        transition: .2s ease;
    }

    .upload-box:hover {
        border-color: #4ade80;
        background: #f0fdf4;
    }

    .upload-icon {
        display: grid;
        place-items: center;
        width: 35px;
        height: 35px;
        flex: 0 0 35px;
        border-radius: 12px;
        background: #ffffff;
        color: var(--pay-green);
        box-shadow: 0 3px 10px rgba(15, 23, 42, .05);
    }

    .upload-name {
        display: block;
        overflow: hidden;
        color: var(--pay-slate-700);
        font-size: 10px;
        font-weight: 900;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .upload-hint {
        display: block;
        margin-top: 2px;
        color: var(--pay-slate-400);
        font-size: 9px;
    }

    .upload-browse {
        margin-left: auto;
        color: var(--pay-green);
        font-size: 9px;
        font-weight: 900;
    }

    .submit-btn {
        width: 100%;
        height: 38px;
        margin-top: 12px;
        border: 0;
        border-radius: 12px;
        background: linear-gradient(135deg, #16a34a, #22c55e);
        color: #ffffff;
        font-size: 12px;
        font-weight: 900;
        cursor: pointer;
        box-shadow: 0 8px 18px rgba(22, 163, 74, .16);
        transition: .2s ease;
    }

    .submit-btn:hover {
        transform: translateY(-1px);
        background: linear-gradient(135deg, #15803d, #16a34a);
    }

    .status-box {
        display: flex;
        gap: 10px;
        padding: 10px;
        border-radius: 13px;
    }

    .status-box.green {
        border: 1px solid #bbf7d0;
        background: var(--pay-green-soft);
        color: #166534;
    }

    .status-box.blue {
        border: 1px solid #bfdbfe;
        background: var(--pay-blue-soft);
        color: #1e40af;
    }

    .status-box.red {
        border: 1px solid #fecaca;
        background: var(--pay-red-soft);
        color: #991b1b;
    }

    .status-box.amber {
        border: 1px solid #fde68a;
        background: var(--pay-amber-soft);
        color: #92400e;
    }

    .status-icon {
        display: grid;
        place-items: center;
        width: 30px;
        height: 30px;
        flex: 0 0 30px;
        border-radius: 10px;
        background: rgba(255, 255, 255, .78);
    }

    .status-title {
        margin: 0;
        font-size: 10px;
        font-weight: 900;
    }

    .status-text {
        margin: 3px 0 0;
        font-size: 10px;
        line-height: 1.5;
    }

    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 9px;
        margin-top: 10px;
    }

    .info-card {
        padding: 9px 10px;
        border-radius: 12px;
        background: #f8fafc;
    }

    .info-label {
        color: var(--pay-slate-400);
        font-size: 8px;
        font-weight: 900;
        letter-spacing: .1em;
        text-transform: uppercase;
    }

    .info-value {
        margin-top: 3px;
        color: var(--pay-slate-950);
        font-size: 10px;
        font-weight: 900;
    }

    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 12px;
        color: var(--pay-green-dark);
        font-size: 10px;
        font-weight: 900;
        text-decoration: none;
    }

    .receipt-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 9px;
        margin-top: 14px;
    }

    .receipt-btn {
        display: inline-flex;
        min-height: 40px;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 10px;
        font-weight: 900;
        text-decoration: none;
        transition: .2s ease;
    }

    .receipt-btn-primary {
        background: var(--pay-green);
        color: #ffffff;
        box-shadow: 0 6px 14px rgba(22, 163, 74, .14);
    }

    .receipt-btn-primary:hover {
        background: var(--pay-green-dark);
        transform: translateY(-1px);
    }

    .receipt-btn-secondary {
        border: 1px solid var(--pay-slate-200);
        background: #ffffff;
        color: var(--pay-slate-700);
    }

    .receipt-btn-secondary:hover {
        background: var(--pay-slate-100);
    }

    @media (max-width: 700px) {
        #payOrderPage {
            width: calc(100% - 24px) !important;
            max-width: 620px !important;
            margin: 20px auto 30px !important;
        }

        .payment-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .header-total {
            width: 100%;
            text-align: left;
        }

        .payment-methods,
        .info-grid {
            grid-template-columns: 1fr;
        }

        .vendor-item {
            align-items: flex-start;
            flex-direction: column;
        }

        .vendor-right {
            width: 100%;
            justify-content: space-between;
        }

        .vendor-amount {
            text-align: left;
        }
    }

    @media (max-width: 480px) {
        #payOrderPage {
            width: calc(100% - 16px) !important;
            max-width: none !important;
            margin: 12px auto 24px !important;
        }

        .payment-header,
        .vendor-section,
        .payment-content {
            padding-left: 15px;
            padding-right: 15px;
        }

        .payment-title {
            font-size: 25px;
        }
    }
</style>

<main id="payOrderPage" style="display:block !important;width:calc(100% - 48px) !important;max-width:820px !important;margin:48px auto 44px !important;padding:0 !important;box-sizing:border-box !important;">
    <section class="payment-card">

        <!-- Unified Header -->
        <header class="payment-header">
            <div class="header-copy">
                <p class="order-label">Order #<?php echo number_format($purchaseId); ?></p>
                <h1 class="payment-title"><?php echo pay_e($displayHeading); ?></h1>

                <?php if ($displaySubtitle !== ''): ?>
                    <p class="payment-subtitle"><?php echo pay_e($displaySubtitle); ?></p>
                <?php endif; ?>
            </div>

            <div class="header-total">
                <span class="header-total-label">Final Amount</span>
                <span class="header-total-value"><?php echo number_format($finalAmount); ?> MMK</span>
            </div>
        </header>

        <!-- Vendor Summary: Horizontal Section -->
        <section class="vendor-section">
            <div class="vendor-section-head">
                <div>
                    <h2 class="vendor-heading">Vendor Summary</h2>
                    <p class="vendor-help">Vendor responses included in your final total.</p>
                </div>
            </div>

            <div class="vendor-list">
                <?php foreach ($vendorOrders as $vendorOrder): ?>
                    <?php
                    $vendorStatus = strtolower((string)$vendorOrder['order_status']);
                    $vendorBadgeClass = ($vendorStatus === 'confirmed' || $vendorStatus === 'completed')
                        ? 'green'
                        : ($vendorStatus === 'rejected' ? 'red' : 'amber');
                    ?>

                    <div class="vendor-item">
                        <p class="vendor-name"><?php echo pay_e($vendorOrder['vendor_name']); ?></p>

                        <div class="vendor-right">
                            <span class="vendor-status <?php echo $vendorBadgeClass; ?>">
                                <?php echo pay_e($vendorStatus); ?>
                            </span>

                            <span class="vendor-amount"
                                  style="<?php echo $vendorStatus === 'rejected'
                                      ? 'color:#94a3b8;text-decoration:line-through;'
                                      : ''; ?>">
                                <?php echo number_format((float)$vendorOrder['subtotal']); ?> MMK
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($rejectedCount > 0): ?>
                <p class="summary-note">
                    Rejected vendor amounts are excluded from the final payment.
                </p>
            <?php endif; ?>
        </section>

        <!-- Payment Content -->
        <section class="payment-content">

            <?php if ($isPaid): ?>

                <div class="section-heading">
                    <span class="section-icon">
                        <i class="fa-solid fa-check"></i>
                    </span>

                    <div>
                        <h2 class="section-title">Payment Verified</h2>
                        <p class="section-help">Your payment has been approved successfully.</p>
                    </div>
                </div>

                <div class="status-box green">
                    <div class="status-icon">
                        <i class="fa-solid fa-check"></i>
                    </div>

                    <div>
                        <p class="status-title">Payment Approved</p>
                        <p class="status-text">
                            Your <?php echo pay_e(pay_method_label($order['payment_method'])); ?>
                            payment has been verified by Admin.
                        </p>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label">Payment Method</div>
                        <div class="info-value">
                            <?php echo pay_e(pay_method_label($order['payment_method'])); ?>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Verified</div>
                        <div class="info-value">
                            <?php echo pay_e($verifiedAtLabel !== '' ? $verifiedAtLabel : 'Verified'); ?>
                        </div>
                    </div>
                </div>

                <div class="receipt-actions">
                    <a href="receipt.php?purchase_id=<?php echo (int) $purchaseId; ?>&print=1"
   class="receipt-btn receipt-btn-primary">
    <i class="fa-solid fa-print"></i>
    Print Receipt
</a>
                    <a href="my_orders.php"
                       class="receipt-btn receipt-btn-secondary">
                        <i class="fa-solid fa-arrow-left"></i>
                        Back to My Orders
                    </a>
                </div>

            <?php elseif ($isPendingVerification): ?>

                <div class="section-heading">
                    <span class="section-icon">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </span>

                    <div>
                        <h2 class="section-title">Verification Details</h2>
                        <p class="section-help">Your payment proof is waiting for Admin review.</p>
                    </div>
                </div>

                <div class="status-box blue">
                    <div class="status-icon">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>

                    <div>
                        <p class="status-title">Waiting for Admin Verification</p>
                        <p class="status-text">
                            Your payment proof has been submitted successfully.
                        </p>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label">Payment Method</div>
                        <div class="info-value">
                            <?php echo pay_e(pay_method_label($order['payment_method'])); ?>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-label">Submitted</div>
                        <div class="info-value">
                            <?php echo pay_e($submittedAtLabel !== '' ? $submittedAtLabel : 'Recently'); ?>
                        </div>
                    </div>
                </div>

                <a href="my_orders.php" class="back-link">
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to My Orders
                </a>

            <?php else: ?>

                <div class="section-heading">
                    <span class="section-icon">
                        <i class="fa-solid fa-wallet"></i>
                    </span>

                    <div>
                        <h2 class="section-title">Payment Details</h2>
                        <p class="section-help">Choose a payment method and upload your payment slip.</p>
                    </div>
                </div>

                <?php if ($pendingCount > 0): ?>

                    <div class="status-box amber">
                        <div class="status-icon">
                            <i class="fa-solid fa-clock"></i>
                        </div>

                        <div>
                            <p class="status-title">Waiting for Vendors</p>
                            <p class="status-text">
                                <?php echo number_format($pendingCount); ?>
                                vendor order(s) are still pending.
                            </p>
                        </div>
                    </div>

                <?php elseif ($acceptedCount <= 0): ?>

                    <div class="status-box red">
                        <div class="status-icon">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </div>

                        <div>
                            <p class="status-title">No Payment Required</p>
                            <p class="status-text">All vendor orders were rejected.</p>
                        </div>
                    </div>

                <?php else: ?>

                    <?php if ($isRejected): ?>
                        <div class="status-box red" style="margin-bottom:12px;">
                            <div class="status-icon">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>

                            <div>
                                <p class="status-title">Payment Proof Rejected</p>
                                <p class="status-text">
                                    <?php echo pay_e(
                                        trim((string)$order['payment_rejection_reason']) !== ''
                                            ? $order['payment_rejection_reason']
                                            : 'Please submit a valid payment proof again.'
                                    ); ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($formError !== ''): ?>
                        <div class="status-box red" style="margin-bottom:12px;">
                            <div class="status-icon">
                                <i class="fa-solid fa-circle-exclamation"></i>
                            </div>

                            <div>
                                <p class="status-title">Unable to Submit Payment</p>
                                <p class="status-text"><?php echo pay_e($formError); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post"
                          enctype="multipart/form-data"
                          action="pay_order.php?purchase_id=<?php echo $purchaseId; ?>">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?php echo pay_e($csrfToken); ?>">

                        <input type="hidden"
                               name="action"
                               value="submit_payment">

                        <div class="pay-step">
                            <div class="step-label">
                                <span class="step-no">1</span>
                                Choose Payment Method
                            </div>

                            <div class="payment-methods">

                                <label class="payment-method">
                                    <input type="radio"
                                           name="payment_method"
                                           value="kbzpay"
                                           <?php echo $selectedMethod === 'kbzpay' ? 'checked' : ''; ?>
                                           required
                                           onchange="showPaymentQr('kbzpay')">

                                    <span class="method-card">
                                        <span class="method-icon kbz">
                                            <i class="fa-solid fa-mobile-screen-button"></i>
                                        </span>

                                        <span>
                                            <span class="method-name">KBZPay</span>
                                            <span class="method-note">Pay securely using KBZPay QR</span>
                                        </span>

                                        <span class="method-check">
                                            <i class="fa-solid fa-check"></i>
                                        </span>
                                    </span>
                                </label>

                                <label class="payment-method">
                                    <input type="radio"
                                           name="payment_method"
                                           value="wave_money"
                                           <?php echo $selectedMethod === 'wave_money' ? 'checked' : ''; ?>
                                           required
                                           onchange="showPaymentQr('wave_money')">

                                    <span class="method-card">
                                        <span class="method-icon wave">
                                            <i class="fa-solid fa-wallet"></i>
                                        </span>

                                        <span>
                                            <span class="method-name">Wave Money</span>
                                            <span class="method-note">Pay securely using Wave Money</span>
                                        </span>

                                        <span class="method-check">
                                            <i class="fa-solid fa-check"></i>
                                        </span>
                                    </span>
                                </label>

                            </div>

                            <!-- KBZPay QR -->
                            <div id="qr-kbzpay"
                                 class="payment-qr-panel kbz-panel <?php echo $selectedMethod === 'kbzpay' ? '' : 'hidden'; ?>">

                                <div class="qr-title">Scan KBZPay QR</div>

                                <?php if ($paymentAccounts['kbzpay']['qr_exists']): ?>

                                    <div class="qr-box">
                                        <img src="<?php echo pay_e($paymentAccounts['kbzpay']['qr_url']); ?>"
                                             alt="KBZPay QR">
                                    </div>

                                    <div class="qr-amount">
                                        Pay exactly <?php echo number_format($finalAmount); ?> MMK
                                    </div>

                                <?php else: ?>

                                    <div class="status-box red" style="margin-top:9px;text-align:left;">
                                        <div class="status-icon">
                                            <i class="fa-solid fa-qrcode"></i>
                                        </div>

                                        <div>
                                            <p class="status-title">KBZPay QR image is missing.</p>
                                            <p class="status-text">uploads/payment_qr/kbzpay.png</p>
                                        </div>
                                    </div>

                                <?php endif; ?>
                            </div>

                            <!-- Wave Money QR -->
                            <div id="qr-wave_money"
                                 class="payment-qr-panel wave-panel <?php echo $selectedMethod === 'wave_money' ? '' : 'hidden'; ?>">

                                <div class="qr-title">Scan Wave Money QR</div>

                                <?php if ($paymentAccounts['wave_money']['qr_exists']): ?>

                                    <div class="qr-box">
                                        <img src="<?php echo pay_e($paymentAccounts['wave_money']['qr_url']); ?>"
                                             alt="Wave Money QR">
                                    </div>

                                    <div class="qr-amount">
                                        Pay exactly <?php echo number_format($finalAmount); ?> MMK
                                    </div>

                                <?php else: ?>

                                    <div class="status-box red" style="margin-top:9px;text-align:left;">
                                        <div class="status-icon">
                                            <i class="fa-solid fa-qrcode"></i>
                                        </div>

                                        <div>
                                            <p class="status-title">Wave Money QR image is missing.</p>
                                            <p class="status-text">uploads/payment_qr/wave_money.png</p>
                                        </div>
                                    </div>

                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="pay-step">
                            <div class="step-label">
                                <span class="step-no">2</span>
                                Upload Payment Slip
                            </div>

                            <label for="payment_slip" class="upload-box">
                                <span class="upload-icon">
                                    <i class="fa-solid fa-cloud-arrow-up"></i>
                                </span>

                                <span style="min-width:0;flex:1;">
                                    <span id="slipFileName" class="upload-name">
                                        Choose payment slip
                                    </span>

                                    <span class="upload-hint">
                                        JPG, PNG or WEBP · Max 5 MB
                                    </span>
                                </span>

                                <span class="upload-browse">Browse</span>
                            </label>

                            <input type="file"
                                   id="payment_slip"
                                   name="payment_slip"
                                   accept="image/jpeg,image/png,image/webp"
                                   required
                                   onchange="updateSlipFileName(this)"
                                   style="display:none;">
                        </div>

                        <button type="submit" class="submit-btn">
                            <i class="fa-solid fa-paper-plane" style="margin-right:6px;"></i>
                            Submit for Verification
                        </button>

                    </form>

                <?php endif; ?>

            <?php endif; ?>

        </section>

    </section>
</main>

<script>
    function showPaymentQr(method) {
        var panels =
            document.querySelectorAll('.payment-qr-panel');

        var i;

        for (i = 0; i < panels.length; i++) {
            panels[i].classList.add('hidden');
        }

        var selected =
            document.getElementById('qr-' + method);

        if (selected) {
            selected.classList.remove('hidden');
        }
    }

    function updateSlipFileName(input) {
        var label =
            document.getElementById('slipFileName');

        if (!label) {
            return;
        }

        if (
            input.files &&
            input.files.length > 0
        ) {
            label.textContent =
                input.files[0].name;
        } else {
            label.textContent =
                'Choose payment slip';
        }
    }

    document.addEventListener(
        'DOMContentLoaded',
        function () {
            var selected =
                document.querySelector(
                    'input[name="payment_method"]:checked'
                );

            if (selected) {
                showPaymentQr(selected.value);
            }
        }
    );
</script>

