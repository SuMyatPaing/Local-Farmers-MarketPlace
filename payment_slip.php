<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';

fm_require_login();

$purchaseId = isset($_GET['purchase_id'])
    ? (int) $_GET['purchase_id']
    : 0;

if ($purchaseId <= 0) {
    http_response_code(404);
    exit('Payment slip not found.');
}

$pdo = null;
require __DIR__ . '/config/database.php';

if (!($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is not available.');
}

$statement = $pdo->prepare(
    "SELECT purchase_id, user_id, payment_slip
     FROM purchase_process
     WHERE purchase_id = :purchase_id
     LIMIT 1"
);
$statement->execute(array('purchase_id' => $purchaseId));
$order = $statement->fetch(PDO::FETCH_ASSOC);

if (!$order || trim((string) $order['payment_slip']) === '') {
    http_response_code(404);
    exit('Payment slip not found.');
}

$role = isset($_SESSION['role'])
    ? strtolower((string) $_SESSION['role'])
    : '';
$userId = isset($_SESSION['user_id'])
    ? (int) $_SESSION['user_id']
    : 0;

$allowed = $role === 'admin' ||
    ($role === 'user' && $userId === (int) $order['user_id']);

if (!$allowed) {
    http_response_code(403);
    exit('You are not allowed to view this payment slip.');
}

$storageRoot = realpath(__DIR__ . '/uploads/payment_slips');
$storedPath = str_replace('\\', '/', trim((string) $order['payment_slip']));

if (
    $storageRoot === false ||
    strpos($storedPath, 'uploads/payment_slips/') !== 0
) {
    http_response_code(404);
    exit('Payment slip not found.');
}

$filePath = realpath(__DIR__ . '/' . $storedPath);

if (
    $filePath === false ||
    !is_file($filePath) ||
    strpos($filePath, $storageRoot . DIRECTORY_SEPARATOR) !== 0
) {
    http_response_code(404);
    exit('Payment slip not found.');
}

$imageInfo = @getimagesize($filePath);
$mime = $imageInfo && isset($imageInfo['mime'])
    ? strtolower((string) $imageInfo['mime'])
    : '';
$allowedTypes = array(
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp'
);

if (!isset($allowedTypes[$mime])) {
    http_response_code(415);
    exit('Unsupported payment slip type.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header(
    'Content-Disposition: inline; filename="payment-slip-' .
    $purchaseId . '.' . $allowedTypes[$mime] . '"'
);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
