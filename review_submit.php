<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
fm_require_role('user');
require_once __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fm_redirect(fm_url('products.php'));
}

$productId = isset($_POST['product_id']) ? max(0, (int) $_POST['product_id']) : 0;
$rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
$comment = isset($_POST['comment']) ? trim((string) $_POST['comment']) : '';
$token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
$returnUrl = fm_url('products.php' . ($productId > 0 ? '?view=' . $productId . '#reviews' : ''));

function review_return($type, $message, $returnUrl)
{
    $_SESSION['product_page_flash'] = array(
        'type' => (string) $type,
        'message' => (string) $message
    );
    fm_redirect($returnUrl);
}

if (!fm_verify_csrf($token)) {
    review_return('error', 'Your form session expired. Please try again.', $returnUrl);
}

if ($productId <= 0 || $rating < 1 || $rating > 5) {
    review_return('error', 'Choose a valid product and a rating from 1 to 5.', $returnUrl);
}

if ($comment === '' || strlen($comment) > 255) {
    review_return('error', 'Review comment is required and must not exceed 255 characters.', $returnUrl);
}

$userId = (int) $_SESSION['user_id'];

try {
    /*
    | A customer can review only a product from a fully paid and completed
    | vendor order. This prevents reviews for products that were never
    | actually fulfilled.
    */
    $eligibleStatement = $pdo->prepare(
        "SELECT 1
         FROM purchase_details pd
         INNER JOIN purchase_process pp
            ON pp.purchase_id = pd.purchase_id
         INNER JOIN vendor_orders vo
            ON vo.vendor_order_id = pd.vendor_order_id
         WHERE pp.user_id = :user_id
           AND pd.product_id = :product_id
           AND pp.payment_status = 'paid'
           AND vo.order_status = 'completed'
         LIMIT 1"
    );
    $eligibleStatement->execute(array(
        'user_id' => $userId,
        'product_id' => $productId
    ));

    if (!$eligibleStatement->fetchColumn()) {
        review_return(
            'error',
            'You can review this product after its paid order has been completed.',
            $returnUrl
        );
    }

    $existingStatement = $pdo->prepare(
        "SELECT review_id
         FROM reviews
         WHERE user_id = :user_id
           AND product_id = :product_id
         LIMIT 1"
    );
    $existingStatement->execute(array(
        'user_id' => $userId,
        'product_id' => $productId
    ));
    $reviewId = (int) $existingStatement->fetchColumn();

    if ($reviewId > 0) {
        $statement = $pdo->prepare(
            "UPDATE reviews
             SET rating = :rating,
                 comment = :comment
             WHERE review_id = :review_id
               AND user_id = :user_id"
        );
        $statement->execute(array(
            'rating' => $rating,
            'comment' => $comment,
            'review_id' => $reviewId,
            'user_id' => $userId
        ));
        review_return('success', 'Your review was updated successfully.', $returnUrl);
    }

    $statement = $pdo->prepare(
        "INSERT INTO reviews (user_id, product_id, rating, comment)
         VALUES (:user_id, :product_id, :rating, :comment)"
    );
    $statement->execute(array(
        'user_id' => $userId,
        'product_id' => $productId,
        'rating' => $rating,
        'comment' => $comment
    ));

    review_return('success', 'Thank you. Your review was submitted successfully.', $returnUrl);
} catch (PDOException $exception) {
    error_log('Review submission failed: ' . $exception->getMessage());
    review_return('error', 'The review could not be saved. Please try again.', $returnUrl);
}
