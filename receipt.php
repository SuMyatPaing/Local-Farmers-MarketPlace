<?php

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/marketplace.php';

fm_require_role('user');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* =========================================================
   HELPERS
========================================================= */

function receipt_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function receipt_redirect($location)
{
    header('Location: ' . $location);
    exit;
}

function receipt_date($value, $format = 'M d, Y - h:i A')
{
    $timestamp = strtotime((string) $value);

    return $timestamp !== false
        ? date($format, $timestamp)
        : '—';
}

function receipt_payment_label($method)
{
    $method = strtolower(trim((string) $method));

    if ($method === 'kbzpay') {
        return 'KBZPay';
    }

    if ($method === 'wave_money') {
        return 'Wave Money';
    }

    return ucfirst(str_replace('_', ' ', $method));
}

/* =========================================================
   USER + PURCHASE
========================================================= */

$userId = isset($_SESSION['user_id'])
    ? (int) $_SESSION['user_id']
    : 0;

$purchaseId = isset($_GET['purchase_id'])
    ? max(0, (int) $_GET['purchase_id'])
    : 0;

if ($userId <= 0 || $purchaseId <= 0) {
    receipt_redirect('my_orders.php');
}

/* =========================================================
   AUTO PRINT MODE
========================================================= */

$autoPrint =
    isset($_GET['print']) &&
    (string) $_GET['print'] === '1';

$printOnly =
    $autoPrint ||
    (
        isset($_GET['print_only']) &&
        (string) $_GET['print_only'] === '1'
    );

/* =========================================================
   DATABASE
========================================================= */

$pdo = null;

$databaseFile = __DIR__ . '/config/database.php';

if (file_exists($databaseFile)) {
    require_once $databaseFile;
}

if (!($pdo instanceof PDO) && function_exists('getPDO')) {
    $pdo = getPDO();
}

if (!($pdo instanceof PDO)) {
    exit('Database connection is not available.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* =========================================================
   GET PAID ORDER
========================================================= */

$orderStatement = $pdo->prepare(
    "SELECT
        pp.purchase_id,
        pp.user_id,
        pp.total_amount,
        pp.payment_method,
        pp.payment_status,
        pp.transaction_id,
        pp.paid_at,
        pp.verified_at,
        pp.fulfillment_type,
        pp.order_status,
        pp.created_at,

        u.user_name,
        u.email,
        u.phone_number

     FROM purchase_process pp

     INNER JOIN users u
        ON u.user_id = pp.user_id

     WHERE pp.purchase_id = :purchase_id
       AND pp.user_id = :user_id
       AND pp.payment_status = 'paid'

     LIMIT 1"
);

$orderStatement->execute(array(
    'purchase_id' => $purchaseId,
    'user_id' => $userId
));

$order = $orderStatement->fetch();

if (!$order) {
    receipt_redirect(
        'pay_order.php?purchase_id=' . $purchaseId
    );
}

/* =========================================================
   CONFIRMED / COMPLETED ITEMS ONLY
========================================================= */

$itemStatement = $pdo->prepare(
    "SELECT
        vo.vendor_order_id,
        vo.vendor_id,
        vo.order_status,

        v.vendor_name,

        pd.purchase_detail_id,
        pd.product_id,
        pd.quantity,
        pd.unit_price,
        pd.subtotal AS item_subtotal,

        p.product_name,
        p.unit,

        m.market_name

     FROM vendor_orders vo

     INNER JOIN vendors v
        ON v.vendor_id = vo.vendor_id

     INNER JOIN purchase_details pd
        ON pd.vendor_order_id = vo.vendor_order_id

     LEFT JOIN products p
        ON p.product_id = pd.product_id

     LEFT JOIN categories c
        ON c.category_id = p.category_id

     LEFT JOIN markets m
        ON m.market_id = c.market_id

     WHERE vo.purchase_id = :purchase_id
       AND vo.order_status IN ('confirmed', 'completed')

     ORDER BY
        vo.vendor_order_id ASC,
        pd.purchase_detail_id ASC"
);

$itemStatement->execute(array(
    'purchase_id' => $purchaseId
));

$paidItems = $itemStatement->fetchAll();

/* =========================================================
   GROUP BY VENDOR
========================================================= */

$vendorGroups = array();
$receiptTotal = 0;

foreach ($paidItems as $item) {

    $vendorOrderId = (int) $item['vendor_order_id'];

    if (!isset($vendorGroups[$vendorOrderId])) {

        $vendorGroups[$vendorOrderId] = array(
            'vendor_order_id' => $vendorOrderId,
            'vendor_name' => (string) $item['vendor_name'],
            'status' => (string) $item['order_status'],
            'items' => array()
        );
    }

    $vendorGroups[$vendorOrderId]['items'][] = $item;

    $receiptTotal += (float) $item['item_subtotal'];
}

/* =========================================================
   REJECTED VENDOR COUNT
========================================================= */

$rejectedStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM vendor_orders
     WHERE purchase_id = :purchase_id
       AND order_status = 'rejected'"
);

$rejectedStatement->execute(array(
    'purchase_id' => $purchaseId
));

$rejectedVendorCount =
    (int) $rejectedStatement->fetchColumn();

/* =========================================================
   VERIFIED DATE
========================================================= */

if (!empty($order['verified_at'])) {

    $verifiedLabel =
        receipt_date($order['verified_at']);

} elseif (!empty($order['paid_at'])) {

    $verifiedLabel =
        receipt_date($order['paid_at']);

} else {

    $verifiedLabel = 'Verified';
}

/* =========================================================
   REFERENCE
========================================================= */

if (trim((string) $order['transaction_id']) !== '') {

    $receiptReference =
        (string) $order['transaction_id'];

} else {

    $receiptReference =
        'ORD-' .
        str_pad(
            (string) $purchaseId,
            6,
            '0',
            STR_PAD_LEFT
        );
}

$pageTitle =
    'Payment Receipt | Farmers Market';

if (!$printOnly) {
    require __DIR__ . '/header.php';
}

?>

<style>

:root {
    --green: #00ad48;
    --green-dark: #08752f;
    --green-soft: #ecfdf3;

    --navy: #071b38;

    --text: #344054;
    --muted: #667085;
    --light: #98a2b3;

    --border: #e4e7ec;
    --surface: #f7f9fb;

    --white: #ffffff;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f4f7f4;
    color: var(--navy);
}

/* =========================================================
   PAGE
========================================================= */

.receipt-page {
    padding: 28px 16px;
}

.receipt-card {

    width: 100%;
    max-width: 620px;

    margin: 0 auto;

    background: var(--white);

    border: 1px solid #dce5df;
    border-top: 5px solid var(--green);

    border-radius: 14px;

    overflow: hidden;

    box-shadow:
        0 10px 30px
        rgba(15, 23, 42, .07);
}

/* =========================================================
   HEADER
========================================================= */

.receipt-header {

    padding: 20px 22px 18px;

    border-bottom: 1px solid var(--border);

    background:
        linear-gradient(
            135deg,
            #ffffff,
            #f2fff6
        );
}

.brand-row {

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 14px;
}

.brand {

    display: flex;
    align-items: center;

    gap: 9px;
}

.brand-logo {

    width: 35px;
    height: 35px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 9px;

    background: var(--green);

    color: white;

    font-size: 13px;
    font-weight: 900;
}

.brand-name {

    margin: 0;

    font-size: 14px;
    font-weight: 900;
}

.brand-sub {

    margin: 2px 0 0;

    color: var(--muted);

    font-size: 9px;
}

.verified-badge {

    display: inline-flex;
    align-items: center;

    gap: 5px;

    padding: 6px 9px;

    border-radius: 999px;

    background: #e8fff1;

    color: var(--green-dark);

    font-size: 9px;
    font-weight: 900;
}

.verified-icon {

    width: 16px;
    height: 16px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 50%;

    background: var(--green);

    color: white;

    font-size: 9px;
}

/* =========================================================
   TITLE
========================================================= */

.title-row {

    display: flex;
    align-items: flex-end;
    justify-content: space-between;

    gap: 15px;

    margin-top: 16px;
}

.order-label {

    margin: 0 0 5px;

    color: var(--green);

    font-size: 9px;
    font-weight: 900;

    letter-spacing: 1.3px;
}

.receipt-title {

    margin: 0;

    font-size: 25px;
    line-height: 1.05;

    font-weight: 900;
}

.verified-date {

    margin: 5px 0 0;

    color: var(--muted);

    font-size: 9px;
}

.header-total {
    text-align: right;
}

.header-total-label {

    color: var(--muted);

    font-size: 8px;
    font-weight: 900;

    text-transform: uppercase;

    letter-spacing: .6px;
}

.header-total-value {

    display: block;

    margin-top: 4px;

    color: var(--green-dark);

    font-size: 21px;
    font-weight: 900;

    white-space: nowrap;
}

/* =========================================================
   BODY
========================================================= */

.receipt-body {
    padding: 18px 22px 20px;
}

/* =========================================================
   INFORMATION
========================================================= */

.info-grid {

    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 8px;

    margin-bottom: 17px;
}

.info-card {

    padding: 10px 11px;

    border-radius: 8px;

    background: var(--surface);
}

.info-label {

    margin-bottom: 4px;

    color: var(--light);

    font-size: 7px;
    font-weight: 900;

    text-transform: uppercase;

    letter-spacing: .6px;
}

.info-value {

    color: var(--navy);

    font-size: 10px;
    font-weight: 900;

    word-break: break-word;
}

.status-value {
    color: var(--green-dark);
}

/* =========================================================
   ITEMS
========================================================= */

.items-heading {

    margin: 0 0 8px;

    font-size: 12px;
    font-weight: 900;
}

.vendor-group {

    margin-bottom: 9px;

    overflow: hidden;

    border: 1px solid var(--border);

    border-radius: 9px;

    break-inside: avoid;
    page-break-inside: avoid;
}

.vendor-head {

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 10px;

    padding: 7px 9px;

    background: var(--green-soft);
}

.vendor-name {

    color: var(--green-dark);

    font-size: 9px;
    font-weight: 900;
}

.vendor-status {

    padding: 3px 6px;

    border-radius: 999px;

    background: #d8fbe5;

    color: var(--green-dark);

    font-size: 7px;
    font-weight: 900;

    text-transform: capitalize;
}

/* =========================================================
   TABLE
========================================================= */

.receipt-table {

    width: 100%;

    border-collapse: collapse;

    table-layout: fixed;
}

.receipt-table th {

    padding: 6px 8px;

    border-bottom: 1px solid var(--border);

    background: #fafbfc;

    color: var(--light);

    font-size: 7px;
    font-weight: 900;

    text-transform: uppercase;

    text-align: left;
}

.receipt-table td {

    padding: 7px 8px;

    border-bottom: 1px solid #f0f2f4;

    color: var(--text);

    font-size: 8px;

    vertical-align: top;
}

.receipt-table tbody tr:last-child td {
    border-bottom: 0;
}

.receipt-table th:nth-child(1),
.receipt-table td:nth-child(1) {
    width: 42%;
}

.receipt-table th:nth-child(2),
.receipt-table td:nth-child(2) {

    width: 12%;

    text-align: center;
}

.receipt-table th:nth-child(3),
.receipt-table td:nth-child(3),

.receipt-table th:nth-child(4),
.receipt-table td:nth-child(4) {

    width: 23%;

    text-align: right;
}

.product-name {

    display: block;

    color: var(--navy);

    font-weight: 900;
}

.product-market,
.product-unit {

    display: block;

    margin-top: 2px;

    color: var(--light);

    font-size: 6.5px;
}

.amount {

    color: var(--green-dark) !important;

    font-weight: 900;
}

/* =========================================================
   TOTAL
========================================================= */

.final-box {

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 14px;

    margin-top: 13px;

    padding: 11px 12px;

    border: 1px solid #afe4c1;

    border-radius: 9px;

    background:
        linear-gradient(
            90deg,
            #f1fff6,
            #ffffff
        );

    break-inside: avoid;
    page-break-inside: avoid;
}

.final-label {

    color: var(--green-dark);

    font-size: 9px;
    font-weight: 900;

    text-transform: uppercase;
}

.final-sub {

    margin-top: 3px;

    color: var(--muted);

    font-size: 7px;
}

.final-value {

    color: var(--green-dark);

    font-size: 19px;
    font-weight: 900;

    white-space: nowrap;
}

/* =========================================================
   NOTES
========================================================= */

.rejected-note {

    margin-top: 8px;

    padding: 7px 9px;

    border-radius: 7px;

    background: #fff8eb;

    color: #8a6116;

    font-size: 7px;

    line-height: 1.4;
}

.verified-note {

    display: flex;
    align-items: center;

    gap: 7px;

    margin-top: 9px;

    padding: 8px 9px;

    border-radius: 7px;

    background: #effff5;

    color: #456956;

    font-size: 7px;

    line-height: 1.4;

    break-inside: avoid;
    page-break-inside: avoid;
}

.note-icon {

    width: 17px;
    height: 17px;

    flex: 0 0 17px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 50%;

    background: var(--green);

    color: white;

    font-size: 8px;
}

/* =========================================================
   FOOTER
========================================================= */

.receipt-footer {

    display: flex;
    align-items: flex-start;
    justify-content: space-between;

    gap: 15px;

    margin-top: 11px;

    padding-top: 9px;

    border-top: 1px dashed #d8dee3;

    color: var(--muted);

    font-size: 7px;

    line-height: 1.4;

    break-inside: avoid;
    page-break-inside: avoid;
}

.footer-brand {

    color: var(--navy);

    font-weight: 900;
}

.footer-thanks {

    margin-top: 2px;

    color: var(--green-dark);

    font-weight: 900;
}

.footer-right {
    text-align: right;
}

/* =========================================================
   BUTTONS
========================================================= */

.receipt-actions {

    display: flex;

    gap: 8px;

    margin-top: 12px;
}

.receipt-btn {

    min-height: 36px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    padding: 0 12px;

    border-radius: 8px;

    font-size: 9px;
    font-weight: 900;

    text-decoration: none;

    cursor: pointer;
}

.print-btn {

    border: 0;

    background: var(--green);

    color: white;
}

.back-btn {

    border: 1px solid var(--border);

    background: white;

    color: #475467;
}

/* =========================================================
   AUTO PRINT SCREEN
========================================================= */

<?php if ($printOnly): ?>

body {
    background: #ffffff !important;
}

.receipt-page {

    padding: 0 !important;

    display: flex;
    justify-content: center;
}

.receipt-card {

    width: 125mm !important;
    max-width: 125mm !important;

    margin: 0 auto !important;

    border-radius: 0 !important;

    box-shadow: none !important;
}

.receipt-actions {
    display: none !important;
}

<?php endif; ?>

/* =========================================================
   PRINT — ONE PAGE FIX
========================================================= */

@media print {

    /*
       A4 is used intentionally.

       This gives enough vertical space so the compact
       voucher always remains on ONE sheet.
    */

    @page {

        size: A4 portrait;

        margin:
            8mm 10mm;
    }

    html,
    body {

        margin: 0 !important;
        padding: 0 !important;

        width: 100% !important;

        background: white !important;

        -webkit-print-color-adjust:
            exact !important;

        print-color-adjust:
            exact !important;
    }

    body {

        overflow: visible !important;
    }

    .receipt-page {

        width: 100% !important;

        margin: 0 !important;

        padding: 0 !important;

        display: flex !important;

        justify-content: center !important;

        align-items: flex-start !important;
    }

    .receipt-card {

        width: 125mm !important;

        max-width: 125mm !important;

        margin: 0 auto !important;

        border: 1px solid #dce5df !important;

        border-top:
            3px solid var(--green) !important;

        border-radius: 0 !important;

        box-shadow: none !important;

        overflow: visible !important;

        break-inside: avoid-page !important;

        page-break-inside: avoid !important;

        page-break-after: avoid !important;
    }

    /*
       Smaller print spacing is the main fix.
    */

    .receipt-header {

        padding:
            4mm 5mm 3.5mm !important;
    }

    .receipt-body {

        padding:
            3.5mm 5mm 4mm !important;
    }

    .brand-logo {

        width: 8mm !important;
        height: 8mm !important;

        font-size: 8pt !important;
    }

    .brand-name {

        font-size:
            9pt !important;
    }

    .brand-sub {

        font-size:
            5.5pt !important;
    }

    .verified-badge {

        padding:
            1.5mm 2.5mm !important;

        font-size:
            6pt !important;
    }

    .verified-icon {

        width:
            4mm !important;

        height:
            4mm !important;

        font-size:
            6pt !important;
    }

    .title-row {

        margin-top:
            3mm !important;
    }

    .order-label {

        margin-bottom:
            1mm !important;

        font-size:
            6pt !important;
    }

    .receipt-title {

        font-size:
            15pt !important;
    }

    .verified-date {

        margin-top:
            1mm !important;

        font-size:
            6pt !important;
    }

    .header-total-label {

        font-size:
            5.5pt !important;
    }

    .header-total-value {

        margin-top:
            1mm !important;

        font-size:
            13pt !important;
    }

    .info-grid {

        gap:
            2mm !important;

        margin-bottom:
            3mm !important;
    }

    .info-card {

        min-height:
            0 !important;

        padding:
            2.2mm 2.5mm !important;
    }

    .info-label {

        margin-bottom:
            1mm !important;

        font-size:
            5pt !important;
    }

    .info-value {

        font-size:
            7pt !important;
    }

    .items-heading {

        margin-bottom:
            2mm !important;

        font-size:
            8pt !important;
    }

    .vendor-group {

        margin-bottom:
            2mm !important;

        break-inside:
            avoid !important;

        page-break-inside:
            avoid !important;
    }

    .vendor-head {

        padding:
            1.7mm 2mm !important;
    }

    .vendor-name {

        font-size:
            6.5pt !important;
    }

    .vendor-status {

        padding:
            1mm 1.7mm !important;

        font-size:
            5pt !important;
    }

    .receipt-table th {

        padding:
            1.5mm 2mm !important;

        font-size:
            5pt !important;
    }

    .receipt-table td {

        padding:
            1.7mm 2mm !important;

        font-size:
            6pt !important;
    }

    .product-market,
    .product-unit {

        margin-top:
            .5mm !important;

        font-size:
            4.5pt !important;
    }

    .final-box {

        margin-top:
            2.5mm !important;

        padding:
            2.5mm 3mm !important;
    }

    .final-label {

        font-size:
            6pt !important;
    }

    .final-sub {

        margin-top:
            .7mm !important;

        font-size:
            5pt !important;
    }

    .final-value {

        font-size:
            13pt !important;
    }

    .rejected-note {

        margin-top:
            2mm !important;

        padding:
            1.7mm 2mm !important;

        font-size:
            5pt !important;
    }

    .verified-note {

        margin-top:
            2mm !important;

        padding:
            1.8mm 2mm !important;

        font-size:
            5pt !important;
    }

    .note-icon {

        width:
            4mm !important;

        height:
            4mm !important;

        flex-basis:
            4mm !important;

        font-size:
            5pt !important;
    }

    .receipt-footer {

        margin-top:
            2.5mm !important;

        padding-top:
            2mm !important;

        font-size:
            5pt !important;

        break-inside:
            avoid !important;

        page-break-inside:
            avoid !important;
    }

    .receipt-actions {

        display:
            none !important;
    }

}

/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 600px) {

    .receipt-page {
        padding: 14px 8px;
    }

    .receipt-header,
    .receipt-body {

        padding-left: 16px;
        padding-right: 16px;
    }

    .brand-row,
    .title-row,
    .final-box,
    .receipt-footer {

        align-items: flex-start;
        flex-direction: column;
    }

    .header-total,
    .footer-right {

        text-align: left;
    }

    .info-grid {

        grid-template-columns: 1fr;
    }

    .receipt-table th:nth-child(3),
    .receipt-table td:nth-child(3) {

        display: none;
    }

    .receipt-table th:nth-child(1),
    .receipt-table td:nth-child(1) {

        width: 50%;
    }

    .receipt-table th:nth-child(2),
    .receipt-table td:nth-child(2) {

        width: 18%;
    }

    .receipt-table th:nth-child(4),
    .receipt-table td:nth-child(4) {

        width: 32%;
    }
}

</style>


<main class="receipt-page">

<section class="receipt-card">

    <!-- =====================================================
         HEADER
    ====================================================== -->

    <header class="receipt-header">

        <div class="brand-row">

            <div class="brand">

                <div class="brand-logo">
                    FM
                </div>

                <div>

                    <p class="brand-name">
                        Farmers Market
                    </p>

                    <p class="brand-sub">
                        Local Marketplace
                    </p>

                </div>

            </div>


            <div class="verified-badge">

                <span class="verified-icon">
                    ✓
                </span>

                Payment Verified

            </div>

        </div>


        <div class="title-row">

            <div>

                <p class="order-label">

                    ORDER
                    #<?php
                    echo number_format(
                        $purchaseId
                    );
                    ?>

                </p>


                <h1 class="receipt-title">
                    Payment Receipt
                </h1>


                <p class="verified-date">

                    Verified on
                    <?php
                    echo receipt_e(
                        $verifiedLabel
                    );
                    ?>

                </p>

            </div>


            <div class="header-total">

                <div class="header-total-label">
                    Final Paid Amount
                </div>

                <span class="header-total-value">

                    <?php
                    echo number_format(
                        $receiptTotal
                    );
                    ?>

                    MMK

                </span>

            </div>

        </div>

    </header>


    <!-- =====================================================
         BODY
    ====================================================== -->

    <div class="receipt-body">


        <!-- PAYMENT INFO -->

        <section class="info-grid">

            <div class="info-card">

                <div class="info-label">
                    Customer
                </div>

                <div class="info-value">

                    <?php
                    echo receipt_e(
                        $order['user_name']
                    );
                    ?>

                </div>

            </div>


            <div class="info-card">

                <div class="info-label">
                    Payment Method
                </div>

                <div class="info-value">

                    <?php
                    echo receipt_e(
                        receipt_payment_label(
                            $order['payment_method']
                        )
                    );
                    ?>

                </div>

            </div>


            <div class="info-card">

                <div class="info-label">
                    Reference
                </div>

                <div class="info-value">

                    <?php
                    echo receipt_e(
                        $receiptReference
                    );
                    ?>

                </div>

            </div>


            <div class="info-card">

                <div class="info-label">
                    Payment Status
                </div>

                <div class="
                    info-value
                    status-value
                ">
                    VERIFIED
                </div>

            </div>

        </section>


        <!-- PURCHASED ITEMS -->

        <h2 class="items-heading">
            Purchased Items
        </h2>


        <?php if (empty($vendorGroups)): ?>

            <p style="font-size:10px;">
                No paid items were found.
            </p>

        <?php else: ?>


            <?php foreach ($vendorGroups as $vendorGroup): ?>

                <section class="vendor-group">

                    <div class="vendor-head">

                        <span class="vendor-name">

                            <?php
                            echo receipt_e(
                                $vendorGroup['vendor_name']
                            );
                            ?>

                        </span>


                        <span class="vendor-status">

                            <?php
                            echo receipt_e(
                                $vendorGroup['status']
                            );
                            ?>

                        </span>

                    </div>


                    <table class="receipt-table">

                        <thead>

                            <tr>

                                <th>Product</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th>Amount</th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($vendorGroup['items'] as $item): ?>

                            <tr>

                                <td>

                                    <span class="product-name">

                                        <?php
                                        echo receipt_e(
                                            $item['product_name'] !== null
                                                ? $item['product_name']
                                                : 'Product'
                                        );
                                        ?>

                                    </span>


                                    <?php if (!empty($item['market_name'])): ?>

                                        <span class="product-market">

                                            <?php
                                            echo receipt_e(
                                                $item['market_name']
                                            );
                                            ?>

                                        </span>

                                    <?php endif; ?>


                                    <?php if (!empty($item['unit'])): ?>

                                        <span class="product-unit">

                                            Unit:
                                            <?php
                                            echo receipt_e(
                                                $item['unit']
                                            );
                                            ?>

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php
                                    echo number_format(
                                        (int) $item['quantity']
                                    );
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    echo number_format(
                                        (float) $item['unit_price']
                                    );
                                    ?>

                                    MMK

                                </td>


                                <td class="amount">

                                    <?php
                                    echo number_format(
                                        (float) $item['item_subtotal']
                                    );
                                    ?>

                                    MMK

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </section>

            <?php endforeach; ?>

        <?php endif; ?>


        <!-- FINAL AMOUNT -->

        <section class="final-box">

            <div>

                <div class="final-label">
                    Final Paid Amount
                </div>

                <div class="final-sub">
                    Payment approved successfully.
                </div>

            </div>


            <div class="final-value">

                <?php
                echo number_format(
                    $receiptTotal
                );
                ?>

                MMK

            </div>

        </section>


        <!-- REJECTED VENDORS -->

        <?php if ($rejectedVendorCount > 0): ?>

            <div class="rejected-note">

                Rejected vendor items are excluded from
                this receipt and final paid amount.

            </div>

        <?php endif; ?>


        <!-- VERIFIED MESSAGE -->

        <div class="verified-note">

            <span class="note-icon">
                ✓
            </span>

            <span>

                This receipt confirms that the payment
                has been successfully verified by Admin.

            </span>

        </div>


        <!-- RECEIPT FOOTER -->

        <div class="receipt-footer">

            <div>

                <div class="footer-brand">
                    Farmers Market
                </div>

                <div class="footer-thanks">
                    Thank you for supporting local farmers.
                </div>

            </div>


            <div class="footer-right">

                Order
                #<?php
                echo number_format(
                    $purchaseId
                );
                ?>

                <br>

                <?php
                echo receipt_e(
                    $verifiedLabel
                );
                ?>

            </div>

        </div>


        <!-- BUTTONS -->

        <?php if (!$printOnly): ?>

            <div class="receipt-actions">

                <button
                    type="button"
                    class="
                        receipt-btn
                        print-btn
                    "
                    onclick="window.print();"
                >
                    🖨 Print Receipt
                </button>


                <a
                    href="my_orders.php"
                    class="
                        receipt-btn
                        back-btn
                    "
                >
                    ← Back to My Orders
                </a>

            </div>

        <?php endif; ?>

    </div>

</section>

</main>


<?php
/* =========================================================
   AUTO PRINT
========================================================= */
?>

<?php if ($autoPrint): ?>

<script>

(function () {

    var printStarted = false;

    function startPrint() {

        if (printStarted) {
            return;
        }

        printStarted = true;

        /*
         * Wait until the receipt is fully rendered.
         */

        setTimeout(function () {

            window.print();

        }, 400);
    }


    if (document.readyState === 'complete') {

        startPrint();

    } else {

        window.addEventListener(
            'load',
            startPrint
        );

    }


    /*
     * Print or Cancel finished:
     * return to Payment Verified page.
     */

    window.addEventListener(
        'afterprint',
        function () {

            window.location.replace(
                'pay_order.php' +
                '?purchase_id=<?php
                echo (int) $purchaseId;
                ?>' +
                '&submitted=1'
            );

        }
    );

})();

</script>

<?php endif; ?>


<?php

if (
    !$printOnly &&
    file_exists(__DIR__ . '/footer.php')
) {

    require __DIR__ . '/footer.php';
}

?>