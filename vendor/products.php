<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/marketplace.php';
fm_require_role('vendor');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Vendor Products Management
|--------------------------------------------------------------------------
| Vendors can manage only their own products.
| Categories are loaded only from markets assigned to the Vendor.
|--------------------------------------------------------------------------
*/

if (!function_exists('vp_e')) {
    function vp_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('vp_redirect')) {
    function vp_redirect($location)
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('vp_flash')) {
    function vp_flash($type, $message)
    {
        $_SESSION['vendor_product_flash'] = array(
            'type' => $type,
            'message' => $message
        );
    }
}

if (!function_exists('vp_csrf_token')) {
    function vp_csrf_token()
    {
        if (
            !isset($_SESSION['vendor_product_csrf']) ||
            !is_string($_SESSION['vendor_product_csrf']) ||
            $_SESSION['vendor_product_csrf'] === ''
        ) {
            $_SESSION['vendor_product_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['vendor_product_csrf'];
    }
}

if (!function_exists('vp_verify_csrf')) {
    function vp_verify_csrf($token)
    {
        return isset($_SESSION['vendor_product_csrf']) &&
            is_string($token) &&
            hash_equals($_SESSION['vendor_product_csrf'], $token);
    }
}

if (!function_exists('vp_photo_url')) {
    function vp_photo_url($path)
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

        return '../' . ltrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('vp_upload_product_image')) {
    function vp_upload_product_image($file)
    {
        if (
            !is_array($file) ||
            !isset($file['error']) ||
            (int) $file['error'] === UPLOAD_ERR_NO_FILE
        ) {
            return null;
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The product image could not be uploaded.');
        }

        if (!isset($file['size']) || (int) $file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('The product image must not exceed 5 MB.');
        }

        if (
            !isset($file['tmp_name']) ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            throw new RuntimeException('The uploaded product image is invalid.');
        }

        $mimeType = '';

        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = (string) $finfo->file($file['tmp_name']);
        }

        if ($mimeType === '' && function_exists('mime_content_type')) {
            $mimeType = (string) mime_content_type($file['tmp_name']);
        }

        $allowedTypes = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        );

        if (!isset($allowedTypes[$mimeType])) {
            throw new RuntimeException('Only JPG, PNG and WEBP product images are allowed.');
        }

        $uploadDirectory = dirname(__DIR__) . '/uploads/products';

        if (
            !is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0775, true) &&
            !is_dir($uploadDirectory)
        ) {
            throw new RuntimeException('The product upload folder could not be created.');
        }

        $fileName = 'product_' .
            date('Ymd_His') . '_' .
            bin2hex(random_bytes(8)) . '.' .
            $allowedTypes[$mimeType];

        $absolutePath = $uploadDirectory . '/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new RuntimeException('The product image could not be saved.');
        }

        return 'uploads/products/' . $fileName;
    }
}

if (!function_exists('vp_delete_local_photo')) {
    function vp_delete_local_photo($photoPath)
    {
        $photoPath = trim((string) $photoPath);

        if (
            $photoPath === '' ||
            preg_match('/^https?:\/\//i', $photoPath)
        ) {
            return;
        }

        $projectRoot = realpath(dirname(__DIR__));

        if ($projectRoot === false) {
            return;
        }

        $absolutePath = realpath(
            $projectRoot . '/' . ltrim(str_replace('\\', '/', $photoPath), '/')
        );

        $productUploadRoot = realpath($projectRoot . '/uploads/products');

        if (
            $absolutePath !== false &&
            $productUploadRoot !== false &&
            strpos($absolutePath, $productUploadRoot) === 0 &&
            is_file($absolutePath)
        ) {
            @unlink($absolutePath);
        }
    }
}

/* Database connection */
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

/* Vendor session */
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$sessionVendorId = isset($_SESSION['vendor_id']) ? (int) $_SESSION['vendor_id'] : 0;
$sessionRole = isset($_SESSION['role']) ? strtolower((string) $_SESSION['role']) : '';

if ($sessionRole !== '' && $sessionRole !== 'vendor') {
    vp_redirect('../signin.php');
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

    $vendorStatement->execute(array(
        'vendor_id' => $sessionVendorId
    ));
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

    $vendorStatement->execute(array(
        'user_id' => $userId
    ));
} else {
    vp_redirect('../signin.php');
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
    $vendorStatus = strtolower((string) $vendor['status']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Vendor Access</title>
        <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
        <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
        <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>
    <body class="grid min-h-screen place-items-center bg-[#f6faf5] p-5">
        <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-7 text-center shadow-xl">
            <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-amber-100 text-amber-600">
                <i class="fa-solid fa-clock text-2xl"></i>
            </div>

            <h1 class="mt-5 text-2xl font-extrabold text-slate-950">
                Vendor access unavailable
            </h1>

            <p class="mt-2 text-sm leading-6 text-slate-500">
                <?php if ($vendorStatus === 'pending'): ?>
                    Your vendor application is waiting for administrator approval.
                <?php elseif ($vendorStatus === 'rejected'): ?>
                    Your vendor application was rejected.
                <?php else: ?>
                    Your vendor account is not active.
                <?php endif; ?>
            </p>

            <?php if (
                $vendorStatus === 'rejected' &&
                trim((string) $vendor['rejection_reason']) !== ''
            ): ?>
                <div class="mt-5 rounded-xl bg-red-50 p-4 text-left text-sm text-red-700">
                    <strong>Reason:</strong>
                    <?php echo vp_e($vendor['rejection_reason']); ?>
                </div>
            <?php endif; ?>

            <a href="../logout.php"
               class="mt-6 inline-flex items-center gap-2 rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white hover:bg-green-700">
                <i class="fa-solid fa-right-from-bracket"></i>
                Logout
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* Permission */
$permissionStatement = $pdo->prepare(
    "SELECT upload_limit, can_delete
     FROM permission
     WHERE vendor_id = :vendor_id
     LIMIT 1"
);

$permissionStatement->execute(array(
    'vendor_id' => $vendorId
));

$permission = $permissionStatement->fetch();

$uploadLimit = $permission ? max(0, (int) $permission['upload_limit']) : 10;
$canDelete = $permission ? (int) $permission['can_delete'] === 1 : true;

/* Categories under markets assigned to this Vendor */
$categoryStatement = $pdo->prepare(
    "SELECT DISTINCT
        c.category_id,
        c.category_name,
        c.description,
        m.market_id,
        m.market_name,
        ci.city_name
     FROM vendor_markets vm
     INNER JOIN markets m
        ON m.market_id = vm.market_id
     INNER JOIN categories c
        ON c.market_id = m.market_id
     LEFT JOIN cities ci
        ON ci.city_id = m.city_id
     WHERE vm.vendor_id = :vendor_id
     ORDER BY
        m.market_name ASC,
        c.category_name ASC"
);

$categoryStatement->execute(array(
    'vendor_id' => $vendorId
));

$assignedCategories = $categoryStatement->fetchAll();
$allowedCategoryIds = array();

foreach ($assignedCategories as $assignedCategory) {
    $allowedCategoryIds[(int) $assignedCategory['category_id']] = true;
}

/* POST actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!vp_verify_csrf($csrfToken)) {
        vp_flash('error', 'Your form session expired. Please try again.');
        vp_redirect('products.php');
    }

    $action = isset($_POST['action'])
        ? (string) $_POST['action']
        : '';

    try {
        if ($action === 'save_product') {
            $productId = isset($_POST['product_id'])
                ? (int) $_POST['product_id']
                : 0;

            $productName = isset($_POST['product_name'])
                ? trim((string) $_POST['product_name'])
                : '';

            $categoryId = isset($_POST['category_id'])
                ? (int) $_POST['category_id']
                : 0;

            $priceInput = isset($_POST['price'])
                ? trim((string) $_POST['price'])
                : '';

            $unit = isset($_POST['unit'])
                ? strtolower(trim((string) $_POST['unit']))
                : 'piece';

            $allowedProductUnits = fm_product_units();
            if (!isset($allowedProductUnits[$unit])) {
                throw new RuntimeException('Select a valid product unit.');
            }

            $stockInput = isset($_POST['stock_quantity'])
                ? trim((string) $_POST['stock_quantity'])
                : '';

            $description = isset($_POST['description'])
                ? trim((string) $_POST['description'])
                : '';

            if ($productName === '' || strlen($productName) > 100) {
                throw new RuntimeException(
                    'Product name is required and must not exceed 100 characters.'
                );
            }

            if (!isset($allowedCategoryIds[$categoryId])) {
                throw new RuntimeException(
                    'Select a category from one of your assigned markets.'
                );
            }

            if (
                $priceInput === '' ||
                !is_numeric($priceInput) ||
                (float) $priceInput <= 0
            ) {
                throw new RuntimeException(
                    'Enter a valid product price greater than zero.'
                );
            }

            if (
                $stockInput === '' ||
                filter_var($stockInput, FILTER_VALIDATE_INT) === false ||
                (int) $stockInput < 0
            ) {
                throw new RuntimeException(
                    'Stock quantity must be zero or a positive whole number.'
                );
            }

            $price = round((float) $priceInput, 2);
            $stockQuantity = (int) $stockInput;

            $productImageFile = isset($_FILES['product_image'])
                ? $_FILES['product_image']
                : null;

            if (
                $productId <= 0 &&
                (
                    !is_array($productImageFile) ||
                    !isset($productImageFile['error']) ||
                    (int) $productImageFile['error'] === UPLOAD_ERR_NO_FILE
                )
            ) {
                throw new RuntimeException(
                    'Product image is required when adding a new product.'
                );
            }

            if ($productId > 0) {
                $ownershipStatement = $pdo->prepare(
                    "SELECT product_id
                     FROM products
                     WHERE product_id = :product_id
                       AND vendor_id = :vendor_id
                     LIMIT 1"
                );

                $ownershipStatement->execute(array(
                    'product_id' => $productId,
                    'vendor_id' => $vendorId
                ));

                if (!$ownershipStatement->fetch()) {
                    throw new RuntimeException(
                        'The selected product was not found.'
                    );
                }

                $updateStatement = $pdo->prepare(
                    "UPDATE products
                     SET product_name = :product_name,
                         category_id = :category_id,
                         price = :price,
                         unit = :unit,
                         description = :description,
                         stock_quantity = :stock_quantity
                     WHERE product_id = :product_id
                       AND vendor_id = :vendor_id"
                );

                $updateStatement->execute(array(
                    'product_name' => $productName,
                    'category_id' => $categoryId,
                    'price' => $price,
                    'unit' => $unit,
                    'description' => $description,
                    'stock_quantity' => $stockQuantity,
                    'product_id' => $productId,
                    'vendor_id' => $vendorId
                ));
            } else {
                $countStatement = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM products
                     WHERE vendor_id = :vendor_id"
                );

                $countStatement->execute(array(
                    'vendor_id' => $vendorId
                ));

                $currentProductCount = (int) $countStatement->fetchColumn();

                if (
                    $uploadLimit <= 0 ||
                    $currentProductCount >= $uploadLimit
                ) {
                    throw new RuntimeException(
                        'You have reached the product upload limit assigned by the administrator.'
                    );
                }

                $insertStatement = $pdo->prepare(
                    "INSERT INTO products (
                        vendor_id,
                        product_name,
                        category_id,
                        price,
                        unit,
                        description,
                        stock_quantity
                     ) VALUES (
                        :vendor_id,
                        :product_name,
                        :category_id,
                        :price,
                        :unit,
                        :description,
                        :stock_quantity
                     )"
                );

                $insertStatement->execute(array(
                    'vendor_id' => $vendorId,
                    'product_name' => $productName,
                    'category_id' => $categoryId,
                    'price' => $price,
                    'unit' => $unit,
                    'description' => $description,
                    'stock_quantity' => $stockQuantity
                ));

                $productId = (int) $pdo->lastInsertId();
            }

            $photoPath = vp_upload_product_image(
                $productImageFile
            );

            if ($photoPath !== null) {
                $photoStatement = $pdo->prepare(
                    "INSERT INTO product_photo (
                        product_id,
                        photo_path
                     ) VALUES (
                        :product_id,
                        :photo_path
                     )"
                );

                $photoStatement->execute(array(
                    'product_id' => $productId,
                    'photo_path' => $photoPath
                ));
            }

            vp_flash(
                'success',
                isset($_POST['product_id']) && (int) $_POST['product_id'] > 0
                    ? 'Product updated successfully.'
                    : 'Product added successfully.'
            );

            vp_redirect('products.php');
        }

        if ($action === 'delete_product') {
            $productId = isset($_POST['product_id'])
                ? (int) $_POST['product_id']
                : 0;

            if (!$canDelete) {
                throw new RuntimeException(
                    'The administrator has not allowed you to delete products.'
                );
            }

            $photoStatement = $pdo->prepare(
                "SELECT pp.photo_path
                 FROM product_photo pp
                 INNER JOIN products p ON p.product_id = pp.product_id
                 WHERE pp.product_id = :product_id
                   AND p.vendor_id = :vendor_id"
            );

            $photoStatement->execute(array(
                'product_id' => $productId,
                'vendor_id' => $vendorId
            ));

            $photoRows = $photoStatement->fetchAll();

            $deleteStatement = $pdo->prepare(
                "DELETE FROM products
                 WHERE product_id = :product_id
                   AND vendor_id = :vendor_id"
            );

            $deleteStatement->execute(array(
                'product_id' => $productId,
                'vendor_id' => $vendorId
            ));

            if ($deleteStatement->rowCount() < 1) {
                throw new RuntimeException(
                    'The selected product was not found.'
                );
            }

            foreach ($photoRows as $photoRow) {
                vp_delete_local_photo($photoRow['photo_path']);
            }

            vp_flash('success', 'Product deleted successfully.');
            vp_redirect('products.php');
        }

        if ($action === 'delete_photo') {
            $photoId = isset($_POST['product_photo_id'])
                ? (int) $_POST['product_photo_id']
                : 0;

            $photoStatement = $pdo->prepare(
                "SELECT
                    pp.product_photo_id,
                    pp.photo_path
                 FROM product_photo pp
                 INNER JOIN products p ON p.product_id = pp.product_id
                 WHERE pp.product_photo_id = :product_photo_id
                   AND p.vendor_id = :vendor_id
                 LIMIT 1"
            );

            $photoStatement->execute(array(
                'product_photo_id' => $photoId,
                'vendor_id' => $vendorId
            ));

            $photo = $photoStatement->fetch();

            if (!$photo) {
                throw new RuntimeException(
                    'The selected product image was not found.'
                );
            }

            $deletePhotoStatement = $pdo->prepare(
                "DELETE FROM product_photo
                 WHERE product_photo_id = :product_photo_id"
            );

            $deletePhotoStatement->execute(array(
                'product_photo_id' => $photoId
            ));

            vp_delete_local_photo($photo['photo_path']);

            vp_flash('success', 'Product image removed successfully.');
            vp_redirect(
                'products.php?action=edit&id=' .
                (int) (isset($_POST['product_id']) ? $_POST['product_id'] : 0)
            );
        }

        throw new RuntimeException('Unknown product action.');
    } catch (RuntimeException $exception) {
        vp_flash('error', $exception->getMessage());

        $redirectLocation = 'products.php';

        if (
            isset($_POST['product_id']) &&
            (int) $_POST['product_id'] > 0
        ) {
            $redirectLocation .= '?action=edit&id=' .
                (int) $_POST['product_id'];
        } elseif ($action === 'save_product') {
            $redirectLocation .= '?action=create';
        }

        vp_redirect($redirectLocation);
    } catch (PDOException $exception) {
        vp_flash(
            'error',
            'The database could not complete the product action. Please try again.'
        );

        vp_redirect('products.php');
    }
}

/* Flash message */
$flash = isset($_SESSION['vendor_product_flash'])
    ? $_SESSION['vendor_product_flash']
    : null;

unset($_SESSION['vendor_product_flash']);

/* Filters */
$search = isset($_GET['q'])
    ? trim((string) $_GET['q'])
    : '';

$categoryFilter = isset($_GET['category_id'])
    ? (int) $_GET['category_id']
    : 0;

$stockFilter = isset($_GET['stock'])
    ? strtolower(trim((string) $_GET['stock']))
    : 'all';

$allowedStockFilters = array(
    'all',
    'available',
    'low',
    'out'
);

if (!in_array($stockFilter, $allowedStockFilters, true)) {
    $stockFilter = 'all';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 10;

/* Statistics */
$statsStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_products,
        SUM(CASE WHEN stock_quantity > 10 THEN 1 ELSE 0 END) AS available_products,
        SUM(CASE WHEN stock_quantity BETWEEN 1 AND 10 THEN 1 ELSE 0 END) AS low_stock_products,
        SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock_products,
        COALESCE(SUM(price * stock_quantity), 0) AS inventory_value
     FROM products
     WHERE vendor_id = :vendor_id"
);

$statsStatement->execute(array(
    'vendor_id' => $vendorId
));

$statistics = $statsStatement->fetch();

$totalProducts = (int) $statistics['total_products'];
$availableProducts = (int) $statistics['available_products'];
$lowStockProducts = (int) $statistics['low_stock_products'];
$outOfStockProducts = (int) $statistics['out_of_stock_products'];
$inventoryValue = (float) $statistics['inventory_value'];
$remainingSlots = max(0, $uploadLimit - $totalProducts);
$uploadUsage = $uploadLimit > 0
    ? min(100, round(($totalProducts / $uploadLimit) * 100))
    : 100;

/* Product list */
$whereParts = array(
    'p.vendor_id = :vendor_id'
);

$listParameters = array(
    'vendor_id' => $vendorId
);

if ($search !== '') {
    $whereParts[] = '(
        p.product_name LIKE :search_product OR
        p.description LIKE :search_description OR
        c.category_name LIKE :search_category OR
        m.market_name LIKE :search_market
    )';

    $searchValue = '%' . $search . '%';

    $listParameters['search_product'] =
        $searchValue;

    $listParameters['search_description'] =
        $searchValue;

    $listParameters['search_category'] =
        $searchValue;

    $listParameters['search_market'] =
        $searchValue;
}

if (
    $categoryFilter > 0 &&
    isset($allowedCategoryIds[$categoryFilter])
) {
    $whereParts[] = 'p.category_id = :category_id';
    $listParameters['category_id'] = $categoryFilter;
}

if ($stockFilter === 'available') {
    $whereParts[] = 'p.stock_quantity > 10';
} elseif ($stockFilter === 'low') {
    $whereParts[] = 'p.stock_quantity BETWEEN 1 AND 10';
} elseif ($stockFilter === 'out') {
    $whereParts[] = 'p.stock_quantity = 0';
}

$whereSql = implode(' AND ', $whereParts);

$countSql = "
    SELECT COUNT(*)
    FROM products p
    INNER JOIN categories c ON c.category_id = p.category_id
    INNER JOIN markets m ON m.market_id = c.market_id
    WHERE {$whereSql}
";

$countStatement = $pdo->prepare($countSql);
$countStatement->execute($listParameters);

$totalFilteredProducts = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalFilteredProducts / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$listSql = "
    SELECT
        p.product_id,
        p.product_name,
        p.price,
        p.unit,
        p.description,
        p.stock_quantity,
        p.created_at,
        p.updated_at,
        c.category_id,
        c.category_name,
        m.market_id,
        m.market_name,
        ci.city_name,
        (
            SELECT pp.photo_path
            FROM product_photo pp
            WHERE pp.product_id = p.product_id
            ORDER BY pp.product_photo_id ASC
            LIMIT 1
        ) AS primary_photo,
        (
            SELECT COUNT(*)
            FROM product_photo ppc
            WHERE ppc.product_id = p.product_id
        ) AS photo_count
    FROM products p
    INNER JOIN categories c ON c.category_id = p.category_id
    INNER JOIN markets m ON m.market_id = c.market_id
    INNER JOIN cities ci ON ci.city_id = m.city_id
    WHERE {$whereSql}
    ORDER BY p.updated_at DESC, p.product_id DESC
    LIMIT :limit_value OFFSET :offset_value
";

$listStatement = $pdo->prepare($listSql);

foreach ($listParameters as $parameterName => $parameterValue) {
    $listStatement->bindValue(
        ':' . $parameterName,
        $parameterValue,
        is_int($parameterValue) ? PDO::PARAM_INT : PDO::PARAM_STR
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
$products = $listStatement->fetchAll();

/* Add or edit form */
$formAction = isset($_GET['action'])
    ? strtolower((string) $_GET['action'])
    : '';

$showForm = in_array(
    $formAction,
    array('create', 'edit'),
    true
);

$editProduct = null;
$editPhotos = array();

if ($formAction === 'edit') {
    $editProductId = isset($_GET['id'])
        ? (int) $_GET['id']
        : 0;

    $editStatement = $pdo->prepare(
        "SELECT
            p.product_id,
            p.product_name,
            p.category_id,
            p.price,
            p.unit,
            p.description,
            p.stock_quantity,
            c.category_name,
            m.market_name
         FROM products p
         INNER JOIN categories c ON c.category_id = p.category_id
         INNER JOIN markets m ON m.market_id = c.market_id
         WHERE p.product_id = :product_id
           AND p.vendor_id = :vendor_id
         LIMIT 1"
    );

    $editStatement->execute(array(
        'product_id' => $editProductId,
        'vendor_id' => $vendorId
    ));

    $editProduct = $editStatement->fetch();

    if (!$editProduct) {
        vp_flash('error', 'The selected product was not found.');
        vp_redirect('products.php');
    }

    $editPhotoStatement = $pdo->prepare(
        "SELECT product_photo_id, photo_path
         FROM product_photo
         WHERE product_id = :product_id
         ORDER BY product_photo_id ASC"
    );

    $editPhotoStatement->execute(array(
        'product_id' => $editProductId
    ));

    $editPhotos = $editPhotoStatement->fetchAll();
}

$csrfToken = vp_csrf_token();

function vp_query_url($overrides)
{
    $parameters = $_GET;

    unset($parameters['action']);
    unset($parameters['id']);

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($parameters[$key]);
        } else {
            $parameters[$key] = $value;
        }
    }

    $query = http_build_query($parameters);

    return 'products.php' . ($query !== '' ? '?' . $query : '');
}

$showingFrom = $totalFilteredProducts > 0
    ? $offset + 1
    : 0;

$showingTo = min(
    $offset + $perPage,
    $totalFilteredProducts
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>My Products | Farmers Market Vendor</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">

    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Vendor Products Responsive Layout
        |--------------------------------------------------------------------------
        | Keep the desktop screenshot-style layout at normal 100% browser zoom.
        | Desktop/laptop (>= 1024px):
        | - 5 statistic cards in one row
        | - all filters in one row
        | - full product table fitted into the content area
        | Tablet/mobile remains responsive.
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

        .vendor-scrollbar-hidden {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .vendor-scrollbar-hidden::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .vendor-stats-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1rem;
        }

        .vendor-filter-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 0.75rem;
            align-items: stretch;
        }

        .vendor-filter-control {
            width: 100%;
            min-width: 0;
        }

        .vendor-products-table {
            width: 100%;
            min-width: 980px;
        }

        .vendor-products-table th,
        .vendor-products-table td {
            vertical-align: middle;
        }

        /* Small tablets */
        @media (min-width: 640px) and (max-width: 1023px) {
            .vendor-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .vendor-filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        /* Desktop / laptop: preserve screenshot-style layout at 100% zoom */
        @media (min-width: 1024px) {
            .vendor-stats-grid {
                grid-template-columns:
                    repeat(4, minmax(0, 1fr))
                    minmax(235px, 1.1fr);
                gap: 0.8rem;
            }

            .vendor-filter-grid {
                grid-template-columns:
                    minmax(220px, 1.55fr)
                    minmax(160px, 1fr)
                    minmax(150px, 0.9fr)
                    135px
                    44px;
                gap: 0.75rem;
                align-items: center;
            }

            .vendor-products-table {
                min-width: 0;
                table-layout: fixed;
            }

            .vendor-products-table th:nth-child(1),
            .vendor-products-table td:nth-child(1) {
                width: 24%;
            }

            .vendor-products-table th:nth-child(2),
            .vendor-products-table td:nth-child(2) {
                width: 10%;
            }

            .vendor-products-table th:nth-child(3),
            .vendor-products-table td:nth-child(3) {
                width: 15%;
            }

            .vendor-products-table th:nth-child(4),
            .vendor-products-table td:nth-child(4) {
                width: 14%;
            }

            .vendor-products-table th:nth-child(5),
            .vendor-products-table td:nth-child(5) {
                width: 8%;
            }

            .vendor-products-table th:nth-child(6),
            .vendor-products-table td:nth-child(6) {
                width: 10%;
            }

            .vendor-products-table th:nth-child(7),
            .vendor-products-table td:nth-child(7) {
                width: 11%;
            }

            .vendor-products-table th:nth-child(8),
            .vendor-products-table td:nth-child(8) {
                width: 8%;
            }
        }

        /* Very small screens */
        @media (max-width: 639px) {
            .vendor-stats-grid {
                grid-template-columns: 1fr;
            }

            .vendor-filter-grid {
                grid-template-columns: 1fr;
            }

            .vendor-products-table {
                min-width: 980px;
                table-layout: auto;
            }
        }

        /* Keep normal 100% browser sizing on desktop. */
        @media (min-width: 1024px) {
            body,
            .vendor-products-page {
                zoom: 1;
                transform: none;
            }
        }

    </style>

</head>

<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">

<div class="vendor-products-page min-h-screen">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="min-h-screen lg:ml-64">
        <?php require __DIR__ . '/header.php'; ?>

        <main class="px-4 pb-10 pt-5 lg:px-5 xl:px-7">

            <?php if ($flash): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl border px-4 py-3.5 shadow-sm
                    <?php echo $flash['type'] === 'success'
                        ? 'border-green-200 bg-green-50 text-green-800'
                        : 'border-red-200 bg-red-50 text-red-800'; ?>">

                    <span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full
                        <?php echo $flash['type'] === 'success'
                            ? 'bg-green-100'
                            : 'bg-red-100'; ?>">

                        <i class="fa-solid <?php echo $flash['type'] === 'success'
                            ? 'fa-check'
                            : 'fa-triangle-exclamation'; ?> text-xs"></i>
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold">
                            <?php echo $flash['type'] === 'success'
                                ? 'Success'
                                : 'Unable to continue'; ?>
                        </p>

                        <p class="mt-0.5 text-xs leading-5">
                            <?php echo vp_e($flash['message']); ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <section class="vendor-stats-grid">

                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 place-items-center rounded-full bg-green-100 text-green-600">
                            <i class="fa-solid fa-basket-shopping"></i>
                        </span>

                        <div>
                            <p class="text-xs font-semibold text-slate-500">
                                Total Products
                            </p>

                            <p class="mt-1 text-2xl font-extrabold text-slate-950">
                                <?php echo number_format($totalProducts); ?>
                            </p>
                        </div>
                    </div>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 place-items-center rounded-full bg-emerald-100 text-emerald-600">
                            <i class="fa-solid fa-circle-check"></i>
                        </span>

                        <div>
                            <p class="text-xs font-semibold text-slate-500">
                                Available
                            </p>

                            <p class="mt-1 text-2xl font-extrabold text-slate-950">
                                <?php echo number_format($availableProducts); ?>
                            </p>
                        </div>
                    </div>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 place-items-center rounded-full bg-amber-100 text-amber-600">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </span>

                        <div>
                            <p class="text-xs font-semibold text-slate-500">
                                Low Stock
                            </p>

                            <p class="mt-1 text-2xl font-extrabold text-slate-950">
                                <?php echo number_format($lowStockProducts); ?>
                            </p>
                        </div>
                    </div>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 place-items-center rounded-full bg-red-100 text-red-600">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </span>

                        <div>
                            <p class="text-xs font-semibold text-slate-500">
                                Out of Stock
                            </p>

                            <p class="mt-1 text-2xl font-extrabold text-slate-950">
                                <?php echo number_format($outOfStockProducts); ?>
                            </p>
                        </div>
                    </div>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-blue-100 text-blue-600">
                            <i class="fa-solid fa-coins"></i>
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="whitespace-nowrap text-xs font-semibold text-slate-500">
                                Inventory Value
                            </p>

                            <div class="mt-1 flex items-baseline gap-1.5 whitespace-nowrap">
                                <span class="text-2xl font-extrabold leading-none text-slate-950">
                                    <?php echo number_format($inventoryValue); ?>
                                </span>

                                <span class="text-xs font-bold text-slate-400">
                                    MMK
                                </span>
                            </div>
                        </div>
                    </div>
                </article>
            </section>

            <!-- Upload Permission -->
            <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-card">

                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">

                    <div class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-green-100 text-green-600">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-bold text-slate-800">
                                    Product Upload Permission
                                </p>

                                <p class="mt-0.5 text-xs text-slate-400">
                                    Limit assigned by the administrator
                                </p>
                            </div>

                            <span class="shrink-0 text-xs font-bold text-green-700">
                                <?php echo number_format($totalProducts); ?>
                                /
                                <?php echo number_format($uploadLimit); ?>
                            </span>
                        </div>

                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-green-500"
                                 style="width: <?php echo (int) $uploadUsage; ?>%">
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                        <span class="grid h-8 w-8 place-items-center rounded-full
                            <?php echo $canDelete
                                ? 'bg-green-100 text-green-600'
                                : 'bg-red-100 text-red-600'; ?>">

                            <i class="fa-solid <?php echo $canDelete
                                ? 'fa-check'
                                : 'fa-lock'; ?> text-xs"></i>
                        </span>

                        <div>
                            <p class="text-[11px] font-bold text-slate-700">
                                Delete Permission
                            </p>

                            <p class="text-[10px] <?php echo $canDelete
                                ? 'text-green-600'
                                : 'text-red-600'; ?>">
                                <?php echo $canDelete
                                    ? 'Allowed'
                                    : 'Not allowed'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Search and Filters -->
            <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-card">

                <form method="get"
                      action="products.php"
                      class="vendor-filter-grid">

                    <div class="vendor-filter-control relative">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>

                        <input type="search"
                               name="q"
                               value="<?php echo vp_e($search); ?>"
                               placeholder="Search product, category or market..."
                               class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-9 pr-3 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                    </div>

                    <select name="category_id"
                            class="vendor-filter-control rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                        <option value="0">All categories</option>

                        <?php foreach ($assignedCategories as $category): ?>
                            <option value="<?php echo (int) $category['category_id']; ?>"
                                <?php echo $categoryFilter === (int) $category['category_id']
                                    ? 'selected'
                                    : ''; ?>>

                                <?php echo vp_e(
                                    $category['category_name'] .
                                    ' — ' .
                                    $category['market_name']
                                ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="stock"
                            class="vendor-filter-control rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                        <option value="all"
                            <?php echo $stockFilter === 'all' ? 'selected' : ''; ?>>
                            All stock levels
                        </option>

                        <option value="available"
                            <?php echo $stockFilter === 'available' ? 'selected' : ''; ?>>
                            Available
                        </option>

                        <option value="low"
                            <?php echo $stockFilter === 'low' ? 'selected' : ''; ?>>
                            Low stock
                        </option>

                        <option value="out"
                            <?php echo $stockFilter === 'out' ? 'selected' : ''; ?>>
                            Out of stock
                        </option>
                    </select>

                    <button type="submit"
                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 text-sm font-bold text-white transition hover:bg-green-700">
                        <i class="fa-solid fa-filter text-xs"></i>
                        Filter
                    </button>

                    <a href="products.php"
                       title="Clear filters"
                       aria-label="Clear filters"
                       class="grid h-11 w-11 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-800">
                        <i class="fa-solid fa-rotate-left text-xs"></i>
                    </a>
                </form>
            </section>

            <!-- Products -->
            <section class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

                <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h2 class="font-bold text-slate-950">
                            Product List
                        </h2>

                        <p class="mt-1 text-xs text-slate-400">
                            Showing
                            <?php echo number_format($showingFrom); ?>
                            –
                            <?php echo number_format($showingTo); ?>
                            of
                            <?php echo number_format($totalFilteredProducts); ?>
                            products
                        </p>
                    </div>

                    <div class="shrink-0">

                        <a href="products.php?action=create"
                           class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-green-700 sm:w-auto">

                            <i class="fa-solid fa-plus"></i>

                            Add Product
                        </a>
                    </div>
                </div>

                <?php if (empty($products)): ?>

                    <div class="px-5 py-16 text-center">

                        <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-green-50 text-green-600">
                            <i class="fa-solid fa-basket-shopping text-xl"></i>
                        </div>

                        <p class="mt-4 text-sm font-bold text-slate-700">
                            No products found
                        </p>

                        <p class="mt-1 text-xs text-slate-400">
                            Add your first product or change the current filters.
                        </p>

                        <a href="products.php?action=create"
                           class="mt-5 inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-green-700">

                            <i class="fa-solid fa-plus"></i>

                            Add Product
                        </a>
                    </div>

                <?php else: ?>

                    <div class="vendor-scrollbar-hidden overflow-x-auto">

                        <table class="vendor-products-table divide-y divide-slate-200">

                            <thead class="bg-slate-50">

                                <tr>
                                    <th scope="col"
                                        class="whitespace-nowrap px-5 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Product
                                    </th>

                                    <th scope="col"
                                        class="whitespace-nowrap px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Category
                                    </th>

                                    <th scope="col"
                                        class="whitespace-nowrap px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Market
                                    </th>

                                    <th scope="col"
                                        class="whitespace-nowrap px-4 py-3 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Price
                                    </th>

                                    <th scope="col"
                                        class="whitespace-nowrap px-4 py-3 text-center text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Stock
                                    </th>

                                    <th scope="col"
                                        class="whitespace-nowrap px-4 py-3 text-center text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Status
                                    </th>

                                    <th scope="col"
                                        class="whitespace-nowrap px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Updated
                                    </th>

                                    <th scope="col"
                                        class="whitespace-nowrap px-5 py-3 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100 bg-white">

                                <?php foreach ($products as $product): ?>
                                    <?php
                                    $stockQuantity = (int) $product['stock_quantity'];

                                    if ($stockQuantity === 0) {
                                        $stockLabel = 'Out of stock';
                                        $stockClass = 'bg-red-50 text-red-700 ring-red-200';
                                    } elseif ($stockQuantity <= 10) {
                                        $stockLabel = 'Low stock';
                                        $stockClass = 'bg-amber-50 text-amber-700 ring-amber-200';
                                    } else {
                                        $stockLabel = 'Available';
                                        $stockClass = 'bg-green-50 text-green-700 ring-green-200';
                                    }

                                    $photoUrl = vp_photo_url(
                                        $product['primary_photo']
                                    );
                                    ?>

                                    <tr class="transition hover:bg-green-50/40">

                                        <!-- Product -->
                                        <td class="px-5 py-4">

                                            <div class="flex min-w-0 items-center gap-3">

                                                <div class="h-12 w-12 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-gradient-to-br from-green-50 to-slate-100">

                                                    <?php if ($photoUrl !== ''): ?>

                                                        <img src="<?php echo vp_e($photoUrl); ?>"
                                                             alt="<?php echo vp_e($product['product_name']); ?>"
                                                             class="h-full w-full object-cover">

                                                    <?php else: ?>

                                                        <div class="grid h-full place-items-center text-green-300">
                                                            <i class="fa-solid fa-image text-lg"></i>
                                                        </div>

                                                    <?php endif; ?>
                                                </div>

                                                <div class="min-w-0">

                                                    <p class="max-w-[220px] truncate text-sm font-extrabold text-slate-900">
                                                        <?php echo vp_e($product['product_name']); ?>
                                                    </p>

                                                    <?php if (trim((string) $product['description']) !== ''): ?>

                                                        <p class="mt-1 max-w-[220px] truncate text-[11px] text-slate-400">
                                                            <?php echo vp_e($product['description']); ?>
                                                        </p>

                                                    <?php endif; ?>

                                                    <p class="mt-1 text-[9px] font-semibold text-slate-400">

                                                        <i class="fa-regular fa-images mr-1"></i>

                                                        <?php echo number_format(
                                                            (int) $product['photo_count']
                                                        ); ?>

                                                        image(s)
                                                    </p>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Category -->
                                        <td class="whitespace-nowrap px-4 py-4">

                                            <span class="inline-flex rounded-lg bg-violet-50 px-2.5 py-1.5 text-[11px] font-bold text-violet-700">

                                                <?php echo vp_e($product['category_name']); ?>
                                            </span>
                                        </td>

                                        <!-- Market -->
                                        <td class="px-4 py-4">

                                            <div class="min-w-0">

                                                <p class="truncate text-xs font-bold text-slate-700">
                                                    <?php echo vp_e($product['market_name']); ?>
                                                </p>

                                                <p class="mt-1 text-[10px] text-slate-400">

                                                    <i class="fa-solid fa-location-dot mr-1"></i>

                                                    <?php echo vp_e($product['city_name']); ?>
                                                </p>
                                            </div>
                                        </td>

                                        <!-- Price -->
                                        <td class="whitespace-nowrap px-4 py-4 text-right">

                                            <p class="text-sm font-extrabold text-green-700">

                                                <?php echo number_format(
                                                    (float) $product['price']
                                                ); ?>

                                                <span class="text-[9px] font-semibold text-slate-400">
                                                    MMK / <?php echo vp_e(fm_unit_label($product['unit'])); ?>
                                                </span>
                                            </p>
                                        </td>

                                        <!-- Stock -->
                                        <td class="whitespace-nowrap px-4 py-4 text-center">

                                            <p class="text-sm font-extrabold text-slate-800">
                                                <?php echo number_format($stockQuantity); ?>
                                            </p>

                                            <p class="mt-0.5 text-[9px] text-slate-400">
                                                <?php echo vp_e(fm_unit_label($product['unit'])); ?>
                                            </p>
                                        </td>

                                        <!-- Status -->
                                        <td class="whitespace-nowrap px-4 py-4 text-center">

                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 ring-inset <?php echo vp_e($stockClass); ?>">

                                                <?php echo vp_e($stockLabel); ?>
                                            </span>
                                        </td>

                                        <!-- Updated -->
                                        <td class="whitespace-nowrap px-4 py-4">

                                            <p class="text-xs font-semibold text-slate-600">

                                                <?php echo vp_e(
                                                    date(
                                                        'M d, Y',
                                                        strtotime($product['updated_at'])
                                                    )
                                                ); ?>
                                            </p>

                                            <p class="mt-1 text-[10px] text-slate-400">

                                                <?php echo vp_e(
                                                    date(
                                                        'g:i A',
                                                        strtotime($product['updated_at'])
                                                    )
                                                ); ?>
                                            </p>
                                        </td>

                                        <!-- Actions -->
                                        <td class="whitespace-nowrap px-5 py-4">

                                            <div class="flex items-center justify-end gap-2">

                                                <a href="products.php?action=edit&id=<?php echo (int) $product['product_id']; ?>"
                                                   title="Edit product"
                                                   class="grid h-9 w-9 place-items-center rounded-lg border border-green-200 bg-green-50 text-green-700 transition hover:bg-green-600 hover:text-white">

                                                    <i class="fa-solid fa-pen text-xs"></i>
                                                </a>

                                                <?php if ($canDelete): ?>

                                                    <form method="post"
                                                          action="products.php"
                                                          onsubmit="return confirm('Delete this product permanently?');">

                                                        <input type="hidden"
                                                               name="csrf_token"
                                                               value="<?php echo vp_e($csrfToken); ?>">

                                                        <input type="hidden"
                                                               name="action"
                                                               value="delete_product">

                                                        <input type="hidden"
                                                               name="product_id"
                                                               value="<?php echo (int) $product['product_id']; ?>">

                                                        <button type="submit"
                                                                title="Delete product"
                                                                class="grid h-9 w-9 place-items-center rounded-lg border border-red-200 bg-red-50 text-red-600 transition hover:bg-red-600 hover:text-white">

                                                            <i class="fa-solid fa-trash text-xs"></i>
                                                        </button>
                                                    </form>

                                                <?php else: ?>

                                                    <button type="button"
                                                            disabled
                                                            title="Product deletion is disabled by the administrator."
                                                            class="grid h-9 w-9 cursor-not-allowed place-items-center rounded-lg border border-slate-200 bg-slate-50 text-slate-300">

                                                        <i class="fa-solid fa-lock text-xs"></i>
                                                    </button>

                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                        <p class="text-xs text-slate-400">
                            Page
                            <?php echo number_format($page); ?>
                            of
                            <?php echo number_format($totalPages); ?>
                        </p>

                        <div class="flex items-center gap-1">

                            <a href="<?php echo vp_e(vp_query_url(array(
                                'page' => max(1, $page - 1)
                            ))); ?>"
                               class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50
                               <?php echo $page <= 1
                                    ? 'pointer-events-none opacity-40'
                                    : ''; ?>">
                                <i class="fa-solid fa-chevron-left text-[9px]"></i>
                                Previous
                            </a>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            ?>

                            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                                <a href="<?php echo vp_e(vp_query_url(array(
                                    'page' => $pageNumber
                                ))); ?>"
                                   class="grid h-9 w-9 place-items-center rounded-lg text-xs font-bold
                                   <?php echo $pageNumber === $page
                                        ? 'bg-green-600 text-white'
                                        : 'border border-slate-200 text-slate-500 hover:bg-slate-50'; ?>">

                                    <?php echo $pageNumber; ?>
                                </a>
                            <?php endfor; ?>

                            <a href="<?php echo vp_e(vp_query_url(array(
                                'page' => min($totalPages, $page + 1)
                            ))); ?>"
                               class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-500 hover:bg-slate-50
                               <?php echo $page >= $totalPages
                                    ? 'pointer-events-none opacity-40'
                                    : ''; ?>">
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

<!-- Product Form Modal -->
<?php if ($showForm): ?>
    <?php
    $isEdit = is_array($editProduct);
    $formProductId = $isEdit
        ? (int) $editProduct['product_id']
        : 0;

    $formProductName = $isEdit
        ? (string) $editProduct['product_name']
        : '';

    $formCategoryId = $isEdit
        ? (int) $editProduct['category_id']
        : 0;

    $formPrice = $isEdit
        ? (string) $editProduct['price']
        : '';

    $formUnit = $isEdit
        ? fm_normalize_product_unit($editProduct['unit'])
        : 'piece';

    $formStock = $isEdit
        ? (string) $editProduct['stock_quantity']
        : '0';

    $formDescription = $isEdit
        ? (string) $editProduct['description']
        : '';
    ?>

    <div class="fixed inset-0 z-[70] overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm">

        <div class="mx-auto flex min-h-full max-w-3xl items-center justify-center">

            <div class="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">

                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">

                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-green-600">
                            <?php echo $isEdit
                                ? 'Update Product'
                                : 'New Product'; ?>
                        </p>

                        <h2 class="mt-1 text-xl font-extrabold text-slate-950">
                            <?php echo $isEdit
                                ? 'Edit ' . vp_e($formProductName)
                                : 'Add a new product'; ?>
                        </h2>
                    </div>

                    <a href="products.php"
                       title="Close"
                       class="grid h-10 w-10 place-items-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                </div>

                <form id="productSaveForm"
                      method="post"
                      action="products.php"
                      enctype="multipart/form-data"
                      class="p-5 pb-0">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?php echo vp_e($csrfToken); ?>">

                    <input type="hidden"
                           name="action"
                           value="save_product">

                    <input type="hidden"
                           name="product_id"
                           value="<?php echo $formProductId; ?>">

                    <div class="grid gap-5 md:grid-cols-2">

                        <div class="md:col-span-2">
                            <label for="productName"
                                   class="mb-2 block text-xs font-bold text-slate-700">
                                Product Name
                                <span class="text-red-500">*</span>
                            </label>

                            <input id="productName"
                                   type="text"
                                   name="product_name"
                                   maxlength="100"
                                   required
                                   value="<?php echo vp_e($formProductName); ?>"
                                   placeholder="Example: Organic Tomatoes"
                                   class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        </div>

                        <div>
                            <label for="categoryId"
                                   class="mb-2 block text-xs font-bold text-slate-700">
                                Category and Market
                                <span class="text-red-500">*</span>
                            </label>

                            <select id="categoryId"
                                    name="category_id"
                                    required
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                                <option value="">Select category from assigned market</option>

                                <?php foreach ($assignedCategories as $category): ?>
                                    <option value="<?php echo (int) $category['category_id']; ?>"
                                        <?php echo $formCategoryId === (int) $category['category_id']
                                            ? 'selected'
                                            : ''; ?>>

                                        <?php echo vp_e(
                                            $category['category_name'] .
                                            ' — ' .
                                            $category['market_name'] .
                                            ', ' .
                                            $category['city_name']
                                        ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <?php if (empty($assignedCategories)): ?>

                                <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] leading-5 text-amber-700">

                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>

                                    No category is available. Ask the administrator to assign a market to your Vendor account and create categories for that market.
                                </p>

                            <?php else: ?>

                                <p class="mt-2 text-[10px] leading-5 text-slate-400">

                                    Only categories belonging to your assigned markets are shown.
                                </p>

                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="productPrice"
                                   class="mb-2 block text-xs font-bold text-slate-700">
                                Price (MMK)
                                <span class="text-red-500">*</span>
                            </label>

                            <input id="productPrice"
                                   type="number"
                                   name="price"
                                   min="0.01"
                                   step="0.01"
                                   required
                                   value="<?php echo vp_e($formPrice); ?>"
                                   placeholder="0.00"
                                   class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        </div>


                        <div>
                            <label for="productUnit" class="mb-2 block text-xs font-bold text-slate-700">
                                Selling Unit <span class="text-red-500">*</span>
                            </label>
                            <select id="productUnit" name="unit" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                                <?php foreach (fm_product_units() as $unitValue => $unitLabel): ?>
                                    <option value="<?php echo vp_e($unitValue); ?>" <?php echo $formUnit === $unitValue ? 'selected' : ''; ?>>
                                        <?php echo vp_e($unitLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="mt-1.5 text-[10px] text-slate-400">The price is shown per selected unit.</p>
                        </div>

                        <div>
                            <label for="stockQuantity"
                                   class="mb-2 block text-xs font-bold text-slate-700">
                                Stock Quantity
                                <span class="text-red-500">*</span>
                            </label>

                            <input id="stockQuantity"
                                   type="number"
                                   name="stock_quantity"
                                   min="0"
                                   step="1"
                                   required
                                   value="<?php echo vp_e($formStock); ?>"
                                   class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        </div>

                        <div>
                            <label for="productImage"
                                   class="mb-2 block text-xs font-bold text-slate-700">

                                <?php if ($isEdit): ?>

                                    Product Image
                                    <span class="font-normal text-slate-400">
                                        (Optional)
                                    </span>

                                <?php else: ?>

                                    Product Image
                                    <span class="text-red-500">*</span>

                                <?php endif; ?>

                            </label>

                            <input id="productImage"
                                   type="file"
                                   name="product_image"
                                   accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                   <?php echo !$isEdit ? 'required' : ''; ?>
                                   class="block w-full rounded-xl border border-slate-200 bg-slate-50 text-xs text-slate-500 file:mr-3 file:border-0 file:bg-green-50 file:px-3 file:py-2.5 file:text-xs file:font-bold file:text-green-700 hover:file:bg-green-100">

                            <p class="mt-1.5 text-[10px] text-slate-400">
                                <?php echo $isEdit
                                    ? 'Optional. Add a new image only if needed. JPG, PNG or WEBP, max 5 MB.'
                                    : 'Required. JPG, PNG or WEBP, max 5 MB.'; ?>
                            </p>
                        </div>

                        <div class="md:col-span-2">
                            <label for="productDescription"
                                   class="mb-2 block text-xs font-bold text-slate-700">
                                Description
                                <span class="font-normal text-slate-400">
                                    (Optional)
                                </span>
                            </label>

                            <textarea id="productDescription"
                                      name="description"
                                      rows="3"
                                      placeholder="Describe product quality, source, packaging or other important details."
                                      class="w-full resize-none rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100"><?php echo vp_e($formDescription); ?></textarea>
                        </div>
                    </div>

                    <div id="productImagePreview"
                         class="mt-4 hidden rounded-xl border border-slate-200 bg-slate-50 p-3">

                        <div class="flex items-center gap-3">

                            <img id="productImagePreviewElement"
                                 src=""
                                 alt="Selected product"
                                 class="h-20 w-20 shrink-0 rounded-lg border border-slate-200 object-cover">

                            <div>
                                <p class="text-xs font-bold text-slate-700">
                                    Selected Image
                                </p>

                                <p class="mt-1 text-[10px] leading-4 text-slate-400">
                                    This image will be uploaded when you save the product.
                                </p>
                            </div>

                        </div>
                    </div>

                </form>

                <?php if ($isEdit && !empty($editPhotos)): ?>

                    <div class="mx-5 mt-5 rounded-xl border border-slate-200 bg-slate-50/70 p-4">

                        <div class="flex items-center justify-between gap-3">

                            <div>
                                <h3 class="text-sm font-bold text-slate-800">
                                    Existing Images
                                </h3>

                                <p class="mt-1 text-[10px] leading-4 text-slate-400">
                                    Remove only images you no longer need.
                                </p>
                            </div>

                            <span class="shrink-0 rounded-full bg-white px-2.5 py-1 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">
                                <?php echo number_format(count($editPhotos)); ?>
                                image<?php echo count($editPhotos) === 1 ? '' : 's'; ?>
                            </span>

                        </div>

                        <div class="mt-3 flex flex-wrap gap-3">

                            <?php foreach ($editPhotos as $editPhoto): ?>

                                <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-2">

                                    <img src="<?php echo vp_e(vp_photo_url($editPhoto['photo_path'])); ?>"
                                         alt="<?php echo vp_e($formProductName); ?>"
                                         class="h-20 w-20 shrink-0 rounded-lg object-cover">

                                    <form method="post"
                                          action="products.php"
                                          onsubmit="return confirm('Remove this product image?');">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?php echo vp_e($csrfToken); ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="delete_photo">

                                        <input type="hidden"
                                               name="product_id"
                                               value="<?php echo $formProductId; ?>">

                                        <input type="hidden"
                                               name="product_photo_id"
                                               value="<?php echo (int) $editPhoto['product_photo_id']; ?>">

                                        <button type="submit"
                                                class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-red-200 bg-white px-3 text-xs font-bold text-red-600 transition hover:bg-red-50">
                                            <i class="fa-solid fa-trash text-[10px]"></i>
                                            Remove
                                        </button>

                                    </form>

                                </div>

                            <?php endforeach; ?>

                        </div>
                    </div>

                <?php endif; ?>

                <div class="mx-5 mt-5 flex flex-col-reverse gap-2 border-t border-slate-100 pb-5 pt-5 sm:flex-row sm:justify-end">

                    <a href="products.php"
                       class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-50">
                        Cancel
                    </a>

                    <button type="submit"
                            form="productSaveForm"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-green-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-green-700">

                        <i class="fa-solid <?php echo $isEdit
                            ? 'fa-floppy-disk'
                            : 'fa-plus'; ?>"></i>

                        <?php echo $isEdit
                            ? 'Save Changes'
                            : 'Add Product'; ?>

                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var imageInput = document.getElementById('productImage');
        var previewWrapper = document.getElementById('productImagePreview');
        var previewElement = document.getElementById('productImagePreviewElement');

        if (!imageInput || !previewWrapper || !previewElement) {
            return;
        }

        imageInput.addEventListener('change', function () {
            var file = this.files && this.files[0]
                ? this.files[0]
                : null;

            if (!file) {
                previewWrapper.classList.add('hidden');
                previewElement.src = '';
                return;
            }

            var reader = new FileReader();

            reader.onload = function (event) {
                previewElement.src = event.target.result;
                previewWrapper.classList.remove('hidden');
            };

            reader.readAsDataURL(file);
        });
    });
</script>

</body>
</html>