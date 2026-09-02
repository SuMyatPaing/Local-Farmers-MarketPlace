<?php
declare(strict_types=1);

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
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

function redirectToMarkets(): void
{
    header('Location: markets.php');
    exit;
}

function setMarketsFlash(string $type, string $message): void
{
    $_SESSION['markets_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function validateMarketsCsrfToken(): bool
{
    $submittedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $sessionToken = isset($_SESSION['csrf_token']) ? (string) $_SESSION['csrf_token'] : '';

    return $submittedToken !== ''
        && $sessionToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}

function marketsQueryString(array $changes): string
{
    $current = [
        'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
        'city' => isset($_GET['city']) ? (int) $_GET['city'] : 0,
        'sort' => isset($_GET['sort']) ? (string) $_GET['sort'] : 'newest',
        'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    ];

    foreach ($changes as $key => $value) {
        $current[$key] = $value;
    }

    return http_build_query($current);
}

function normalizeTimeValue(string $time): string
{
    $time = trim($time);
    if ($time === '') {
        return '';
    }

    $timestamp = strtotime($time);
    return $timestamp === false ? '' : date('H:i:s', $timestamp);
}

function displayMarketTime($time): string
{
    $value = trim((string) $time);
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('g:i A', $timestamp);
}

function marketPhotoUrl($path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $path = ltrim(str_replace('\\', '/', $path), '/');

    if (strpos($path, '../') === 0) {
        return $path;
    }

    return '../' . $path;
}

function deleteMarketPhotoFile($path): void
{
    $path = trim((string) $path);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return;
    }

    $relative = ltrim(str_replace('\\', '/', $path), '/');
    if (strpos($relative, '../') === 0) {
        return;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    if ($projectRoot === false) {
        return;
    }

    $fullPath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $realFile = realpath($fullPath);

    if ($realFile !== false
        && strpos($realFile, $projectRoot . DIRECTORY_SEPARATOR) === 0
        && is_file($realFile)) {
        @unlink($realFile);
    }
}

function saveMarketPhotoUpload(array $file): array
{
    if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        $errorCode = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Please select a market photo.');
        }

        throw new RuntimeException('The market photo could not be uploaded.');
    }

    $maximumBytes = 5 * 1024 * 1024;
    if (!isset($file['size']) || (int) $file['size'] <= 0 || (int) $file['size'] > $maximumBytes) {
        throw new RuntimeException('Market photo must be smaller than 5 MB.');
    }

    $temporaryPath = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('Invalid market photo upload.');
    }

    $mimeType = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $temporaryPath);
            if (is_string($detected)) {
                $mimeType = $detected;
            }
            finfo_close($finfo);
        }
    }

    if ($mimeType === '' && function_exists('mime_content_type')) {
        $detected = mime_content_type($temporaryPath);
        if (is_string($detected)) {
            $mimeType = $detected;
        }
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Only JPG, PNG, WEBP, or GIF market photos are allowed.');
    }

    $uploadDirectory = __DIR__ . '/../uploads/markets';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Could not create the market photo upload folder.');
    }

    try {
        $randomName = bin2hex(random_bytes(16));
    } catch (Throwable $exception) {
        $randomName = str_replace('.', '', uniqid('market_', true));
    }

    $fileName = $randomName . '.' . $allowedTypes[$mimeType];
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('Could not save the market photo.');
    }

    return [
        'stored_path' => 'uploads/markets/' . $fileName,
        'full_path' => $destination,
    ];
}

function hasMarketPhotoUpload(array $files): bool
{
    if (!isset($files['market_photo']) || !is_array($files['market_photo'])) {
        return false;
    }

    $error = isset($files['market_photo']['error'])
        ? (int) $files['market_photo']['error']
        : UPLOAD_ERR_NO_FILE;

    return $error !== UPLOAD_ERR_NO_FILE;
}

function insertMarketPhotoFromForm(
    PDO $pdo,
    int $marketId,
    string $marketName,
    string $marketDescription,
    array $files
): ?string {
    if (!hasMarketPhotoUpload($files)) {
        return null;
    }

    $upload = saveMarketPhotoUpload($files['market_photo']);

    $photoName = trim($marketName) . ' photo';
    if (function_exists('mb_substr')) {
        $photoName = mb_substr($photoName, 0, 100, 'UTF-8');
    } else {
        $photoName = substr($photoName, 0, 100);
    }

    $photoDescription = trim($marketDescription);
    if ($photoDescription === '') {
        $photoDescription = 'Photo of ' . trim($marketName);
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO market_photo
                (photo_name, photo_path, market_id, description)
             VALUES
                (:photo_name, :photo_path, :market_id, :description)'
        );

        $statement->execute([
            'photo_name' => $photoName,
            'photo_path' => $upload['stored_path'],
            'market_id' => $marketId,
            'description' => $photoDescription,
        ]);
    } catch (Throwable $exception) {
        if (isset($upload['full_path']) && is_file($upload['full_path'])) {
            @unlink($upload['full_path']);
        }

        throw $exception;
    }

    return isset($upload['full_path']) ? (string) $upload['full_path'] : null;
}

function replaceMarketPrimaryPhotoFromForm(
    PDO $pdo,
    int $marketId,
    string $marketName,
    string $marketDescription,
    array $files
): array {
    if (!hasMarketPhotoUpload($files)) {
        throw new RuntimeException('Please select a market image before saving changes.');
    }

    /*
     * Option B: Replace the existing primary/cover image instead of
     * inserting another photo row every time the market is edited.
     *
     * The application already treats the oldest photo_id as the cover
     * image (ORDER BY photo_id ASC LIMIT 1), so we update that same row.
     * Any additional gallery photos remain untouched.
     */
    $upload = saveMarketPhotoUpload($files['market_photo']);

    $photoName = trim($marketName) . ' photo';
    if (function_exists('mb_substr')) {
        $photoName = mb_substr($photoName, 0, 100, 'UTF-8');
    } else {
        $photoName = substr($photoName, 0, 100);
    }

    $photoDescription = trim($marketDescription);
    if ($photoDescription === '') {
        $photoDescription = 'Photo of ' . trim($marketName);
    }

    $oldPhotoPath = '';

    try {
        $primaryPhotoStatement = $pdo->prepare(
            'SELECT photo_id, photo_path
             FROM market_photo
             WHERE market_id = :market_id
             ORDER BY photo_id ASC
             LIMIT 1
             FOR UPDATE'
        );
        $primaryPhotoStatement->execute([
            'market_id' => $marketId,
        ]);

        $primaryPhoto = $primaryPhotoStatement->fetch(PDO::FETCH_ASSOC);

        if ($primaryPhoto) {
            $oldPhotoPath = isset($primaryPhoto['photo_path'])
                ? (string) $primaryPhoto['photo_path']
                : '';

            $updatePhotoStatement = $pdo->prepare(
                'UPDATE market_photo
                 SET photo_name = :photo_name,
                     photo_path = :photo_path,
                     description = :description
                 WHERE photo_id = :photo_id
                   AND market_id = :market_id'
            );

            $updatePhotoStatement->execute([
                'photo_name' => $photoName,
                'photo_path' => $upload['stored_path'],
                'description' => $photoDescription,
                'photo_id' => (int) $primaryPhoto['photo_id'],
                'market_id' => $marketId,
            ]);
        } else {
            /*
             * Safety fallback for old/incomplete data: if the market has
             * no photo row, create the required primary photo.
             */
            $insertPhotoStatement = $pdo->prepare(
                'INSERT INTO market_photo
                    (photo_name, photo_path, market_id, description)
                 VALUES
                    (:photo_name, :photo_path, :market_id, :description)'
            );

            $insertPhotoStatement->execute([
                'photo_name' => $photoName,
                'photo_path' => $upload['stored_path'],
                'market_id' => $marketId,
                'description' => $photoDescription,
            ]);
        }
    } catch (Throwable $exception) {
        if (isset($upload['full_path']) && is_file($upload['full_path'])) {
            @unlink($upload['full_path']);
        }

        throw $exception;
    }

    return [
        'new_full_path' => isset($upload['full_path'])
            ? (string) $upload['full_path']
            : '',
        'new_stored_path' => isset($upload['stored_path'])
            ? (string) $upload['stored_path']
            : '',
        'old_photo_path' => $oldPhotoPath,
    ];
}

function validateMarketFields(PDO $pdo, array $source, int $excludeMarketId = 0): array
{
    $marketName = trim(isset($source['market_name']) ? (string) $source['market_name'] : '');
    $address = trim(isset($source['address']) ? (string) $source['address'] : '');
    $cityId = isset($source['city_id']) ? (int) $source['city_id'] : 0;
    $openingHour = normalizeTimeValue(isset($source['opening_hour']) ? (string) $source['opening_hour'] : '');
    $closingHour = normalizeTimeValue(isset($source['closing_hour']) ? (string) $source['closing_hour'] : '');
    $description = trim(isset($source['description']) ? (string) $source['description'] : '');

    if ($marketName === '' || $cityId <= 0 || $openingHour === '' || $closingHour === '') {
        throw new RuntimeException('Please complete all required market fields.');
    }

    if (mb_strlen($marketName) > 255) {
        throw new RuntimeException('Market name cannot be longer than 255 characters.');
    }

    if (mb_strlen($address) > 255) {
        throw new RuntimeException('Address cannot be longer than 255 characters.');
    }

    if ($openingHour >= $closingHour) {
        throw new RuntimeException('Closing time must be later than opening time.');
    }

    $cityCheck = $pdo->prepare('SELECT COUNT(*) FROM cities WHERE city_id = :city_id');
    $cityCheck->execute(['city_id' => $cityId]);
    if ((int) $cityCheck->fetchColumn() === 0) {
        throw new RuntimeException('The selected city was not found.');
    }

    $duplicateSql = 'SELECT COUNT(*)
                     FROM markets
                     WHERE LOWER(market_name) = LOWER(:market_name)
                       AND city_id = :city_id';
    $duplicateParams = [
        'market_name' => $marketName,
        'city_id' => $cityId,
    ];

    if ($excludeMarketId > 0) {
        $duplicateSql .= ' AND market_id <> :market_id';
        $duplicateParams['market_id'] = $excludeMarketId;
    }

    $duplicateCheck = $pdo->prepare($duplicateSql);
    $duplicateCheck->execute($duplicateParams);
    if ((int) $duplicateCheck->fetchColumn() > 0) {
        throw new RuntimeException('A market with this name already exists in the selected city.');
    }

    return [
        'market_name' => $marketName,
        'address' => $address,
        'city_id' => $cityId,
        'opening_hour' => $openingHour,
        'closing_hour' => $closingHour,
        'description' => $description,
    ];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Create, update, delete and photo actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateMarketsCsrfToken()) {
        setMarketsFlash('error', 'Security token expired. Please try again.');
        redirectToMarkets();
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'create') {
            $market = validateMarketFields($pdo, $_POST);

            if (!hasMarketPhotoUpload($_FILES)) {
                throw new RuntimeException('Please select a market image.');
            }

            $uploadedFullPath = null;

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare(
                    'INSERT INTO markets
                        (market_name, address, city_id, opening_hour, closing_hour, description)
                     VALUES
                        (:market_name, :address, :city_id, :opening_hour, :closing_hour, :description)'
                );
                $statement->execute($market);

                $marketId = (int) $pdo->lastInsertId();

                $uploadedFullPath = insertMarketPhotoFromForm(
                    $pdo,
                    $marketId,
                    $market['market_name'],
                    $market['description'],
                    $_FILES
                );

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($uploadedFullPath !== null && is_file($uploadedFullPath)) {
                    @unlink($uploadedFullPath);
                }

                throw $exception;
            }

            setMarketsFlash(
                'success',
                'Market and image created successfully.'
            );
            redirectToMarkets();
        }

        if ($action === 'update') {
            $marketId = isset($_POST['market_id']) ? (int) $_POST['market_id'] : 0;
            if ($marketId <= 0) {
                throw new RuntimeException('Invalid market update request.');
            }

            $market = validateMarketFields($pdo, $_POST, $marketId);
            $market['market_id'] = $marketId;

            /*
             * The image is optional when editing.
             * If no new image is selected, keep the current market image.
             * If a new image is selected, replace the current primary image.
             */
            $hasReplacementImage = hasMarketPhotoUpload($_FILES);
            $replacement = null;

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare(
                    'UPDATE markets
                     SET market_name = :market_name,
                         address = :address,
                         city_id = :city_id,
                         opening_hour = :opening_hour,
                         closing_hour = :closing_hour,
                         description = :description
                     WHERE market_id = :market_id'
                );
                $statement->execute($market);

                $exists = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM markets
                     WHERE market_id = :market_id'
                );
                $exists->execute(['market_id' => $marketId]);

                if ((int) $exists->fetchColumn() === 0) {
                    throw new RuntimeException('Market record was not found.');
                }

                /*
                 * Replace the current cover photo only when the Admin
                 * actually selects a new image. Otherwise keep the existing
                 * image unchanged.
                 */
                if ($hasReplacementImage) {
                    $replacement = replaceMarketPrimaryPhotoFromForm(
                        $pdo,
                        $marketId,
                        $market['market_name'],
                        $market['description'],
                        $_FILES
                    );
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                /*
                 * If anything fails after the new file was saved, remove
                 * that new file. The old image is kept because its DB row
                 * was rolled back.
                 */
                if (
                    is_array($replacement) &&
                    !empty($replacement['new_full_path']) &&
                    is_file((string) $replacement['new_full_path'])
                ) {
                    @unlink((string) $replacement['new_full_path']);
                }

                throw $exception;
            }

            /*
             * Delete the old physical image only AFTER the database commit.
             * This prevents losing the old image if the transaction fails.
             */
            if (
                is_array($replacement) &&
                !empty($replacement['old_photo_path']) &&
                $replacement['old_photo_path'] !== $replacement['new_stored_path']
            ) {
                deleteMarketPhotoFile((string) $replacement['old_photo_path']);
            }

            setMarketsFlash(
                'success',
                $hasReplacementImage
                    ? 'Market updated and image replaced successfully.'
                    : 'Market updated successfully. Existing image was kept.'
            );
            redirectToMarkets();
        }

        if ($action === 'delete_photo') {
            $photoId = isset($_POST['photo_id']) ? (int) $_POST['photo_id'] : 0;
            if ($photoId <= 0) {
                throw new RuntimeException('Invalid photo delete request.');
            }

            $photoStatement = $pdo->prepare(
                'SELECT photo_id, photo_path, market_id
                 FROM market_photo
                 WHERE photo_id = :photo_id
                 LIMIT 1'
            );
            $photoStatement->execute(['photo_id' => $photoId]);
            $photo = $photoStatement->fetch(PDO::FETCH_ASSOC);

            if (!$photo) {
                throw new RuntimeException('Market photo was not found.');
            }

            $marketId = (int) $photo['market_id'];

            $photoCountStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM market_photo
                 WHERE market_id = :market_id'
            );
            $photoCountStatement->execute(['market_id' => $marketId]);
            $marketPhotoCount = (int) $photoCountStatement->fetchColumn();

            /*
             * Every market must always keep at least one image.
             * Therefore the final remaining image cannot be deleted.
             */
            if ($marketPhotoCount <= 1) {
                throw new RuntimeException(
                    'This is the only image for this market. Add another image before deleting it.'
                );
            }

            $deleteStatement = $pdo->prepare(
                'DELETE FROM market_photo
                 WHERE photo_id = :photo_id'
            );
            $deleteStatement->execute(['photo_id' => $photoId]);

            if ($deleteStatement->rowCount() === 0) {
                throw new RuntimeException('Market photo was not found.');
            }

            deleteMarketPhotoFile($photo['photo_path']);

            setMarketsFlash('success', 'Market photo deleted successfully.');
            redirectToMarkets();
        }

        if ($action === 'delete') {
            $marketId = isset($_POST['market_id']) ? (int) $_POST['market_id'] : 0;
            if ($marketId <= 0) {
                throw new RuntimeException('Invalid market delete request.');
            }

            $marketStatement = $pdo->prepare('SELECT market_name FROM markets WHERE market_id = :market_id');
            $marketStatement->execute(['market_id' => $marketId]);
            $marketRecord = $marketStatement->fetch(PDO::FETCH_ASSOC);
            if (!$marketRecord) {
                throw new RuntimeException('Market record was not found.');
            }

            $productStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM products p
                 INNER JOIN categories c ON c.category_id = p.category_id
                 WHERE c.market_id = :market_id'
            );
            $productStatement->execute(['market_id' => $marketId]);
            $productCount = (int) $productStatement->fetchColumn();

            $vendorStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM vendor_markets
                 WHERE market_id = :market_id'
            );
            $vendorStatement->execute(['market_id' => $marketId]);
            $vendorCount = (int) $vendorStatement->fetchColumn();

            $eventStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM events
                 WHERE market_id = :market_id'
            );
            $eventStatement->execute(['market_id' => $marketId]);
            $eventCount = (int) $eventStatement->fetchColumn();

            $categoryStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM categories
                 WHERE market_id = :market_id'
            );
            $categoryStatement->execute(['market_id' => $marketId]);
            $categoryCount = (int) $categoryStatement->fetchColumn();

            $photoCountStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM market_photo
                 WHERE market_id = :market_id'
            );
            $photoCountStatement->execute(['market_id' => $marketId]);
            $photoCount = (int) $photoCountStatement->fetchColumn();

            $blockingRecords = array();

            if ($productCount > 0) {
                $blockingRecords[] =
                    number_format($productCount) . ' product' . ($productCount === 1 ? '' : 's');
            }

            if ($vendorCount > 0) {
                $blockingRecords[] =
                    number_format($vendorCount) . ' vendor assignment' . ($vendorCount === 1 ? '' : 's');
            }

            if ($eventCount > 0) {
                $blockingRecords[] =
                    number_format($eventCount) . ' event' . ($eventCount === 1 ? '' : 's');
            }

            if ($categoryCount > 0) {
                $blockingRecords[] =
                    number_format($categoryCount) . ' categor' . ($categoryCount === 1 ? 'y' : 'ies');
            }

            /*
             * Photos do not block market deletion.
             * They are owned by the market and are removed automatically.
             */
            if (count($blockingRecords) > 0) {
                throw new RuntimeException(
                    'This market cannot be deleted. Remove linked ' .
                    implode(', ', $blockingRecords) .
                    ' first.'
                );
            }

            $photoStatement = $pdo->prepare('SELECT photo_path FROM market_photo WHERE market_id = :market_id');
            $photoStatement->execute(['market_id' => $marketId]);
            $photoPaths = $photoStatement->fetchAll(PDO::FETCH_COLUMN);

            $pdo->beginTransaction();
            try {
                /*
                 * Market photos are owned by the market, so deleting an otherwise
                 * empty market also deletes its photo records automatically.
                 */
                $deletePhotoRows = $pdo->prepare(
                    'DELETE FROM market_photo WHERE market_id = :market_id'
                );
                $deletePhotoRows->execute(['market_id' => $marketId]);

                $deleteStatement = $pdo->prepare(
                    'DELETE FROM markets WHERE market_id = :market_id'
                );
                $deleteStatement->execute(['market_id' => $marketId]);

                if ($deleteStatement->rowCount() === 0) {
                    throw new RuntimeException('Market record was not found.');
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            foreach ($photoPaths as $photoPath) {
                deleteMarketPhotoFile($photoPath);
            }

            setMarketsFlash('success', 'Market deleted successfully.');
            redirectToMarkets();
        }

        throw new RuntimeException('Unsupported action.');
    } catch (Throwable $exception) {
        if ($exception instanceof PDOException) {
            error_log('Admin market database error: ' . $exception->getMessage());
            setMarketsFlash('error', 'The database could not complete the market action.');
        } else {
            setMarketsFlash('error', $exception->getMessage());
        }
        redirectToMarkets();
    }
}

/*
|--------------------------------------------------------------------------
| Filters, sorting and pagination
|--------------------------------------------------------------------------
*/
$search = trim(
    isset($_GET['q'])
        ? (string) $_GET['q']
        : ''
);

$cityFilter = isset($_GET['city'])
    ? max(0, (int) $_GET['city'])
    : 0;

$sort = strtolower(
    trim(
        isset($_GET['sort'])
            ? (string) $_GET['sort']
            : 'newest'
    )
);

$sortOptions = [
    'newest' => 'm.created_at DESC, m.market_id DESC',
    'oldest' => 'm.created_at ASC, m.market_id ASC',
    'name_asc' => 'm.market_name ASC',
    'name_desc' => 'm.market_name DESC',
    'most_vendors' => 'vendor_count DESC, m.market_name ASC',
    'most_categories' => 'category_count DESC, m.market_name ASC',
];
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}

$orderBy = $sortOptions[$sort];

$whereParts = [];
$params = [];

if ($search !== '') {
    $whereParts[] = '(
        LOWER(TRIM(m.market_name)) LIKE LOWER(TRIM(:search_market)) OR
        LOWER(TRIM(m.address)) LIKE LOWER(TRIM(:search_address)) OR
        LOWER(TRIM(c.city_name)) LIKE LOWER(TRIM(:search_city)) OR
        LOWER(TRIM(c.administrative_division)) LIKE LOWER(TRIM(:search_division))
    )';

    $searchValue = '%' . trim($search) . '%';

    $params['search_market'] = $searchValue;
    $params['search_address'] = $searchValue;
    $params['search_city'] = $searchValue;
    $params['search_division'] = $searchValue;
}

if ($cityFilter > 0) {
    $whereParts[] = 'm.city_id = :city_id';
    $params['city_id'] = $cityFilter;
}

$whereSql = count($whereParts) > 0 ? ' WHERE ' . implode(' AND ', $whereParts) : '';

$countStatement = $pdo->prepare(
    'SELECT COUNT(*)
     FROM markets m
     INNER JOIN cities c ON c.city_id = m.city_id' . $whereSql
);
$countStatement->execute($params);
$filteredTotal = (int) $countStatement->fetchColumn();

$perPage = 10;
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, min($page, $totalPages));
$offset = ($page - 1) * $perPage;

$listSql = 'SELECT
                m.market_id,
                m.market_name,
                m.address,
                m.city_id,
                m.opening_hour,
                m.closing_hour,
                m.description,
                m.created_at,
                m.updated_at,
                c.city_name,
                c.administrative_division,
                (SELECT COUNT(*) FROM categories cat WHERE cat.market_id = m.market_id) AS category_count,
                (SELECT COUNT(*) FROM vendor_markets vm WHERE vm.market_id = m.market_id) AS vendor_count,
                (
                    SELECT COUNT(*)
                    FROM products p_count
                    INNER JOIN categories cat_count
                        ON cat_count.category_id = p_count.category_id
                    WHERE cat_count.market_id = m.market_id
                ) AS product_count,
                (SELECT COUNT(*) FROM events ev WHERE ev.market_id = m.market_id) AS event_count,
                (SELECT COUNT(*) FROM market_photo mp_count WHERE mp_count.market_id = m.market_id) AS photo_count,
                (SELECT mp.photo_path
                 FROM market_photo mp
                 WHERE mp.market_id = m.market_id
                 ORDER BY mp.photo_id ASC
                 LIMIT 1) AS cover_photo
            FROM markets m
            INNER JOIN cities c ON c.city_id = m.city_id'
            . $whereSql . '
            ORDER BY ' . $orderBy . '
            LIMIT :limit OFFSET :offset';

$listStatement = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStatement->bindValue(':' . $key, $value, $key === 'city_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$markets = $listStatement->fetchAll(PDO::FETCH_ASSOC);

$citiesStatement = $pdo->query(
    'SELECT city_id, city_name, administrative_division
     FROM cities
     ORDER BY administrative_division ASC, city_name ASC'
);
$cities = $citiesStatement->fetchAll(PDO::FETCH_ASSOC);

$photosStatement = $pdo->query(
    'SELECT
        mp.photo_id,
        mp.photo_name,
        mp.photo_path,
        mp.market_id,
        mp.description,
        mp.created_at,
        m.market_name
     FROM market_photo mp
     INNER JOIN markets m ON m.market_id = mp.market_id
     ORDER BY mp.photo_id DESC'
);
$allMarketPhotos = $photosStatement->fetchAll(PDO::FETCH_ASSOC);

$totalMarkets = (int) $pdo->query('SELECT COUNT(*) FROM markets')->fetchColumn();
$citiesCovered = (int) $pdo->query('SELECT COUNT(DISTINCT city_id) FROM markets')->fetchColumn();
$totalAssignedVendors = (int) $pdo->query('SELECT COUNT(DISTINCT vendor_id) FROM vendor_markets')->fetchColumn();
$totalMarketPhotos = (int) $pdo->query('SELECT COUNT(*) FROM market_photo')->fetchColumn();

$flash = isset($_SESSION['markets_flash']) && is_array($_SESSION['markets_flash'])
    ? $_SESSION['markets_flash']
    : null;
unset($_SESSION['markets_flash']);

$fromRecord = $filteredTotal > 0 ? $offset + 1 : 0;
$toRecord = min($offset + $perPage, $filteredTotal);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Markets | Local Farmers Marketplace</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Markets Page - Compact Production Admin UI at Browser Zoom 100%
        |--------------------------------------------------------------------------
        | Desktop layout:
        | MARKET | LOCATION | HOURS | VENDORS | PRODUCTS | EVENTS | PHOTOS | ACTIONS
        |
        | Categories and Created are intentionally omitted from the list view
        | to keep the table readable without horizontal scrolling.
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

        .markets-scroll-hidden,
        .markets-modal-scroll,
        .markets-page-scroll-hidden {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .markets-scroll-hidden::-webkit-scrollbar,
        .markets-modal-scroll::-webkit-scrollbar,
        .markets-page-scroll-hidden::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        .markets-main {
            min-width: 0;
        }

        .markets-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
        }

        .markets-stat-card,
        .markets-filter-panel,
        .markets-list-panel {
            min-width: 0;
        }

        .markets-table {
            width: 100%;
            min-width: 920px;
        }

        .markets-table th,
        .markets-table td {
            vertical-align: middle;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            .markets-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .markets-shell {
                margin-left: 176px !important;
            }

            .markets-main {
                padding: 12px 14px 22px !important;
                max-width: none !important;
            }

            .markets-stats {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: .65rem;
                margin-bottom: .7rem !important;
            }

            .markets-stat-card {
                min-height: 80px;
                border-radius: 14px !important;
                padding: 11px 13px !important;
            }

            .markets-stat-card p:first-child {
                font-size: 9px !important;
                letter-spacing: .04em !important;
            }

            .markets-stat-card p.text-3xl {
                margin-top: 5px !important;
                font-size: 20px !important;
                line-height: 1 !important;
            }

            .markets-stat-card .h-12 {
                width: 38px !important;
                height: 38px !important;
                border-radius: 10px !important;
            }

            .markets-stat-card .text-xl {
                font-size: 15px !important;
            }

            .markets-filter-panel,
            .markets-list-panel {
                border-radius: 14px !important;
            }

            .markets-filter-panel {
                padding: 10px 12px !important;
                margin-bottom: .65rem !important;
            }

            .markets-filter-form {
                grid-template-columns:
                    minmax(260px, 1.45fr)
                    minmax(155px, .72fr)
                    minmax(165px, .78fr)
                    auto !important;
                gap: 8px !important;
            }

            .markets-filter-control,
            .markets-filter-button,
            .markets-clear-button {
                height: 34px !important;
                border-radius: 9px !important;
                font-size: 9px !important;
            }

            .markets-search-input {
                padding-left: 34px !important;
                padding-right: 10px !important;
            }

            .markets-search-icon {
                left: 12px !important;
                font-size: 10px !important;
            }

            .markets-filter-actions {
                gap: 6px !important;
            }

            .markets-filter-button {
                padding-left: 13px !important;
                padding-right: 13px !important;
            }

            .markets-clear-button {
                padding-left: 11px !important;
                padding-right: 11px !important;
            }

            .markets-list-head {
                padding: 10px 13px !important;
            }

            .markets-list-title {
                font-size: 14px !important;
            }

            .markets-list-subtitle {
                margin-top: 3px !important;
                font-size: 9px !important;
            }

            .markets-add-button {
                border-radius: 9px !important;
                padding: 8px 12px !important;
                font-size: 10px !important;
            }

            .markets-table {
                min-width: 0 !important;
                table-layout: fixed;
            }

            .markets-table thead {
                font-size: 8px !important;
            }

            .markets-table th {
                padding: 7px 8px !important;
            }

            .markets-table td {
                padding: 8px !important;
                font-size: 8px !important;
            }

            .markets-table th:nth-child(1),
            .markets-table td:nth-child(1) { width: 22%; }

            .markets-table th:nth-child(2),
            .markets-table td:nth-child(2) { width: 23%; }

            .markets-table th:nth-child(3),
            .markets-table td:nth-child(3) { width: 15%; }

            .markets-table th:nth-child(4),
            .markets-table td:nth-child(4) { width: 8%; }

            .markets-table th:nth-child(5),
            .markets-table td:nth-child(5) { width: 8%; }

            .markets-table th:nth-child(6),
            .markets-table td:nth-child(6) { width: 8%; }

            .markets-table th:nth-child(7),
            .markets-table td:nth-child(7) { width: 8%; }

            .markets-table th:nth-child(8),
            .markets-table td:nth-child(8) { width: 8%; }

            .markets-cover {
                width: 34px !important;
                height: 34px !important;
                border-radius: 8px !important;
            }

            .markets-market-name {
                font-size: 9px !important;
            }

            .markets-market-id,
            .markets-location-meta {
                font-size: 8px !important;
            }

            .markets-location-city {
                font-size: 9px !important;
            }

            .markets-location-address {
                margin-top: 3px !important;
                font-size: 8px !important;
                line-height: 1.35 !important;
            }

            .markets-hours {
                font-size: 8px !important;
            }

            .markets-count-badge {
                min-width: 30px !important;
                border-radius: 7px !important;
                padding: 4px 6px !important;
                font-size: 8px !important;
            }

            .markets-action-wrap {
                display: inline-flex !important;
                flex-wrap: nowrap !important;
                gap: 4px !important;
                white-space: nowrap !important;
                max-width: 100% !important;
            }

            .markets-action-button {
                width: 26px !important;
                height: 26px !important;
                min-width: 26px !important;
                flex: 0 0 26px !important;
                border-radius: 7px !important;
            }

            .markets-action-button i {
                font-size: 8px !important;
            }

            .markets-pagination {
                padding: 10px 14px !important;
            }

            .markets-pagination p {
                font-size: 9px !important;
            }

            .markets-pagination a {
                width: 30px !important;
                min-width: 30px !important;
                height: 30px !important;
                border-radius: 7px !important;
                font-size: 9px !important;
            }
        }

        @media (max-width: 1023px) {
            .markets-table {
                min-width: 920px;
            }
        }
    
        @media (min-width: 1024px) {
            .markets-scroll-hidden {
                overflow-x: hidden !important;
            }
        }

    
        .markets-modal-form {
            max-height: calc(92vh - 72px);
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .markets-modal-form::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }

    </style>

    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>
<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">
<div class="min-h-screen">
    <?php require_once'./sidebar.php'; ?>

    <div class="markets-shell min-h-screen lg:ml-64">
        <?php require_once'./header.php'; ?>

        <main class="markets-main px-4 pb-10 pt-5 sm:px-6 xl:px-7">
            <?php if ($flash): ?>
                <?php
                $isSuccess = isset($flash['type']) && $flash['type'] === 'success';
                $flashClasses = $isSuccess
                    ? 'border-green-200 bg-green-50 text-green-800'
                    : 'border-red-200 bg-red-50 text-red-800';
                $flashIcon = $isSuccess ? 'fa-circle-check' : 'fa-circle-exclamation';
                ?>
                <div id="flashMessage" class="mb-5 flex items-center gap-3 rounded-xl border px-4 py-3 text-sm font-semibold <?= e($flashClasses) ?>">
                    <i class="fa-solid <?= e($flashIcon) ?>"></i>
                    <span class="flex-1"><?= e(isset($flash['message']) ? $flash['message'] : '') ?></span>
                    <button type="button" onclick="document.getElementById('flashMessage').remove()" class="grid h-7 w-7 place-items-center rounded-lg hover:bg-black/5">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            <?php endif; ?>

            <section class="markets-stats mb-5">
                <article class="markets-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Total Markets</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalMarkets)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-store text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="markets-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Cities Covered</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($citiesCovered)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-location-dot text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="markets-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Assigned Vendors</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalAssignedVendors)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-amber-50 text-amber-600">
                            <i class="fa-solid fa-truck-field text-xl"></i>
                        </div>
                    </div>
                </article>

                <article class="markets-stat-card rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Market Photos</p>
                            <p class="mt-2 text-3xl font-extrabold text-slate-950"><?= e(number_format($totalMarketPhotos)) ?></p>
                        </div>
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-violet-50 text-violet-600">
                            <i class="fa-solid fa-images text-xl"></i>
                        </div>
                    </div>
                </article>
            </section>

            <!-- Search / Filter -->
            <section class="markets-filter-panel mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                <form method="get"
                      action="markets.php"
                      class="markets-filter-form grid gap-3 md:grid-cols-[minmax(0,1fr)_220px_220px_auto]">

                    <label class="relative block">
                        <i class="markets-search-icon fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                        <input type="search"
                               name="q"
                               value="<?= e($search) ?>"
                               placeholder="Search market, address, city or division..."
                               class="markets-filter-control markets-search-input h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                    </label>

                    <select name="city"
                            class="markets-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">

                        <option value="0">All Cities</option>

                        <?php foreach ($cities as $city): ?>
                            <option value="<?= e($city['city_id']) ?>"
                                <?= $cityFilter === (int) $city['city_id'] ? 'selected' : '' ?>>

                                <?= e($city['city_name'] . ' — ' . $city['administrative_division']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="sort"
                            class="markets-filter-control h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">

                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
                        <option value="most_vendors" <?= $sort === 'most_vendors' ? 'selected' : '' ?>>Most Vendors</option>
                        <option value="most_categories" <?= $sort === 'most_categories' ? 'selected' : '' ?>>Most Categories</option>
                    </select>

                    <div class="markets-filter-actions flex gap-2">

                        <button type="submit"
                                class="markets-filter-button inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-bold text-white transition hover:bg-green-700 md:flex-none">

                            <i class="fa-solid fa-filter text-xs"></i>
                            Filter
                        </button>

                        <a href="markets.php"
                           class="markets-clear-button inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700">

                            <i class="fa-solid fa-rotate-left text-xs"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </section>

            <!-- Market Table -->
            <section class="markets-list-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

                <div class="markets-list-head flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <h2 class="markets-list-title text-lg font-extrabold text-slate-950">
                            Market List
                        </h2>

                        <p class="markets-list-subtitle mt-1 text-xs text-slate-400">
                            Showing
                            <?= e(number_format($fromRecord)) ?>
                            to
                            <?= e(number_format($toRecord)) ?>
                            of
                            <?= e(number_format($filteredTotal)) ?>
                            market(s)
                        </p>
                    </div>

                    <?php if (count($cities) > 0): ?>

                        <button type="button"
                                onclick="openCreateModal()"
                                class="markets-add-button inline-flex w-fit items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-green-700">

                            <i class="fa-solid fa-plus text-xs"></i>
                            Add Market
                        </button>

                    <?php else: ?>

                        <a href="cities.php"
                           class="inline-flex w-fit items-center justify-center gap-2 rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-amber-600">

                            <i class="fa-solid fa-city text-xs"></i>
                            Add a City First
                        </a>

                    <?php endif; ?>
                </div>

                <?php if (count($markets) === 0): ?>

                    <div class="px-6 py-16 text-center">

                        <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-store-slash text-xl"></i>
                        </div>

                        <h3 class="mt-4 text-sm font-extrabold text-slate-800">
                            No markets found
                        </h3>

                        <p class="mt-1 text-sm text-slate-400">
                            <?= ($search !== '' || $cityFilter > 0)
                                ? 'Try changing your search or city filter.'
                                : 'Create a city first, then add your first farmers market.' ?>
                        </p>
                    </div>

                <?php else: ?>

                    <div class="markets-scroll-hidden overflow-x-auto">

                        <table class="markets-table w-full divide-y divide-slate-100">

                            <thead class="bg-slate-50/80">
                                <tr>
                                    <th class="text-left font-extrabold uppercase tracking-wider text-slate-400">Market</th>
                                    <th class="text-left font-extrabold uppercase tracking-wider text-slate-400">Location</th>
                                    <th class="text-left font-extrabold uppercase tracking-wider text-slate-400">Hours</th>
                                    <th class="text-center font-extrabold uppercase tracking-wider text-slate-400">Vendors</th>
                                    <th class="text-center font-extrabold uppercase tracking-wider text-slate-400">Products</th>
                                    <th class="text-center font-extrabold uppercase tracking-wider text-slate-400">Events</th>
                                    <th class="text-center font-extrabold uppercase tracking-wider text-slate-400">Photos</th>
                                    <th class="text-right font-extrabold uppercase tracking-wider text-slate-400">Actions</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100 bg-white">

                                <?php foreach ($markets as $market): ?>
                                    <?php
                                    $marketJson = json_encode(
                                        array(
                                            'market_id' => (int) $market['market_id'],
                                            'market_name' => $market['market_name'],
                                            'address' => $market['address'],
                                            'city_id' => (int) $market['city_id'],
                                            'opening_hour' => substr((string) $market['opening_hour'], 0, 5),
                                            'closing_hour' => substr((string) $market['closing_hour'], 0, 5),
                                            'description' => $market['description']
                                        ),
                                        JSON_HEX_TAG |
                                        JSON_HEX_APOS |
                                        JSON_HEX_AMP |
                                        JSON_HEX_QUOT
                                    );

                                    $coverPhoto = marketPhotoUrl($market['cover_photo']);

                                    $linkedCategoryCount = (int) $market['category_count'];
                                    $linkedProductCount = (int) $market['product_count'];
                                    $linkedVendorCount = (int) $market['vendor_count'];
                                    $linkedEventCount = (int) $market['event_count'];
                                    $linkedPhotoCount = (int) $market['photo_count'];

                                    /*
                                    | Delete rule:
                                    | - Edit is always allowed.
                                    | - Required market photos do NOT block deletion.
                                    | - Categories, vendors, products, or events DO block deletion.
                                    */
                                    $canDeleteMarket =
                                        $linkedCategoryCount === 0
                                        && $linkedProductCount === 0
                                        && $linkedVendorCount === 0
                                        && $linkedEventCount === 0;

                                    $deleteReason = 'Delete market';

                                    if (!$canDeleteMarket) {
                                        $deleteParts = array();

                                        if ($linkedProductCount > 0) {
                                            $deleteParts[] = number_format($linkedProductCount) . ' product' . ($linkedProductCount === 1 ? '' : 's');
                                        }

                                        if ($linkedVendorCount > 0) {
                                            $deleteParts[] = number_format($linkedVendorCount) . ' vendor assignment' . ($linkedVendorCount === 1 ? '' : 's');
                                        }

                                        if ($linkedEventCount > 0) {
                                            $deleteParts[] = number_format($linkedEventCount) . ' event' . ($linkedEventCount === 1 ? '' : 's');
                                        }

                                        if ($linkedCategoryCount > 0) {
                                            $deleteParts[] = number_format($linkedCategoryCount) . ' categor' . ($linkedCategoryCount === 1 ? 'y' : 'ies');
                                        }

                                        $deleteReason = 'Remove linked ' . implode(', ', $deleteParts) . ' first';
                                    }
                                    ?>

                                    <tr class="transition hover:bg-green-50/30">

                                        <td>
                                            <div class="flex min-w-0 items-center gap-2.5">

                                                <div class="markets-cover shrink-0 overflow-hidden border border-slate-200 bg-green-50">

                                                    <?php if ($coverPhoto !== ''): ?>

                                                        <img
                                                            src="<?= e($coverPhoto) ?>"
                                                            alt="<?= e($market['market_name']) ?>"
                                                            class="h-full w-full object-cover"
                                                        >

                                                    <?php else: ?>

                                                        <div class="grid h-full w-full place-items-center text-green-500">
                                                            <i class="fa-solid fa-store"></i>
                                                        </div>

                                                    <?php endif; ?>

                                                </div>

                                                <div class="min-w-0">
                                                    <p
                                                        class="markets-market-name truncate font-extrabold text-slate-900"
                                                        title="<?= e($market['market_name']) ?>"
                                                    >
                                                        <?= e($market['market_name']) ?>
                                                    </p>

                                                    <p class="markets-market-id mt-1 text-slate-400">
                                                        Market #<?= e($market['market_id']) ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <p class="markets-location-city truncate font-bold text-slate-700">
                                                <?= e($market['city_name']) ?>
                                            </p>

                                            <p class="markets-location-meta mt-1 truncate text-slate-400">
                                                <?= e($market['administrative_division']) ?>
                                            </p>

                                            <?php if (trim((string) $market['address']) !== ''): ?>
                                                <p
                                                    class="markets-location-address line-clamp-2 text-slate-500"
                                                    title="<?= e($market['address']) ?>"
                                                >
                                                    <i class="fa-solid fa-location-dot mr-1 text-slate-400"></i>
                                                    <?= e($market['address']) ?>
                                                </p>
                                            <?php else: ?>
                                                <p class="markets-location-address text-slate-300">
                                                    Address not provided
                                                </p>
                                            <?php endif; ?>
                                        </td>

                                        <td class="markets-hours whitespace-nowrap font-semibold text-slate-600">
                                            <?= e(displayMarketTime($market['opening_hour'])) ?>
                                            –
                                            <?= e(displayMarketTime($market['closing_hour'])) ?>
                                        </td>

                                        <td class="text-center">
                                            <span class="markets-count-badge inline-flex justify-center bg-amber-50 font-bold text-amber-700">
                                                <?= e(number_format($linkedVendorCount)) ?>
                                            </span>
                                        </td>

                                        <td class="text-center">
                                            <a
                                                href="products.php?market=<?= e($market['market_id']) ?>"
                                                class="markets-count-badge inline-flex justify-center bg-green-50 font-bold text-green-700 transition hover:bg-green-100"
                                                title="View products in <?= e($market['market_name']) ?>"
                                            >
                                                <?= e(number_format($linkedProductCount)) ?>
                                            </a>
                                        </td>

                                        <td class="text-center">
                                            <a
                                                href="events.php?market=<?= e($market['market_id']) ?>"
                                                class="markets-count-badge inline-flex justify-center bg-violet-50 font-bold text-violet-700 transition hover:bg-violet-100"
                                                title="View events in <?= e($market['market_name']) ?>"
                                            >
                                                <?= e(number_format($linkedEventCount)) ?>
                                            </a>
                                        </td>

                                        <td class="text-center">
                                            <?php if ($linkedPhotoCount > 0): ?>
                                                <button
                                                    type="button"
                                                    onclick="openGalleryModal(<?= e($market['market_id']) ?>, <?= e(json_encode($market['market_name'])) ?>)"
                                                    class="markets-count-badge inline-flex justify-center bg-slate-100 font-bold text-slate-600 transition hover:bg-violet-50 hover:text-violet-700"
                                                    title="View market photos"
                                                >
                                                    <?= e(number_format($linkedPhotoCount)) ?>
                                                </button>
                                            <?php else: ?>
                                                <span
                                                    class="markets-count-badge inline-flex justify-center bg-slate-100 font-bold text-slate-500"
                                                    title="No market photos yet. Use Edit Market to add an image."
                                                >
                                                    0
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-right">

                                            <div class="markets-action-wrap items-center justify-end">

                                                <button
                                                    type="button"
                                                    onclick='openEditModal(<?= $marketJson ?>)'
                                                    title="Edit market"
                                                    class="markets-action-button grid place-items-center border border-slate-200 text-slate-500 transition hover:border-green-200 hover:bg-green-50 hover:text-green-700"
                                                >
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>

                                                <form
                                                    method="post"
                                                    class="inline"
                                                    onsubmit="return confirm('Delete this market and its market image(s)? This action cannot be undone.');"
                                                >
                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e($_SESSION['csrf_token']) ?>"
                                                    >

                                                    <input type="hidden" name="action" value="delete">

                                                    <input
                                                        type="hidden"
                                                        name="market_id"
                                                        value="<?= e($market['market_id']) ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        title="<?= e($deleteReason) ?>"
                                                        <?= !$canDeleteMarket ? 'disabled' : '' ?>
                                                        class="markets-action-button grid place-items-center border border-slate-200 text-slate-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600 <?= !$canDeleteMarket ? 'cursor-not-allowed opacity-40' : '' ?>"
                                                    >
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>

                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>

                <!-- Pagination -->
                <div class="markets-pagination flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                    <p class="text-xs font-medium text-slate-400">
                        Showing
                        <span class="font-bold text-slate-600">
                            <?= e(number_format($fromRecord)) ?>
                        </span>
                        to
                        <span class="font-bold text-slate-600">
                            <?= e(number_format($toRecord)) ?>
                        </span>
                        of
                        <span class="font-bold text-slate-600">
                            <?= e(number_format($filteredTotal)) ?>
                        </span>
                        markets
                    </p>

                    <?php if ($totalPages > 1): ?>

                        <nav class="flex items-center gap-1.5"
                             aria-label="Markets pagination">

                            <a href="?<?= e(marketsQueryString(array('page' => max(1, $page - 1)))) ?>"
                               class="grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-sm text-slate-500 transition hover:bg-slate-50 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>">

                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </a>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min(
                                $totalPages,
                                $page + 2
                            );
                            ?>

                            <?php for (
                                $pageNumber = $startPage;
                                $pageNumber <= $endPage;
                                $pageNumber++
                            ): ?>

                                <a href="?<?= e(marketsQueryString(array('page' => $pageNumber))) ?>"
                                   class="grid h-9 min-w-9 place-items-center rounded-xl px-2 text-xs font-extrabold transition <?= $pageNumber === $page ? 'bg-green-600 text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50' ?>">

                                    <?= e($pageNumber) ?>
                                </a>

                            <?php endfor; ?>

                            <a href="?<?= e(marketsQueryString(array('page' => min($totalPages, $page + 1)))) ?>"
                               class="grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-sm text-slate-500 transition hover:bg-slate-50 <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>">

                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </a>
                        </nav>

                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- Create Market Modal -->
<div id="createModal" class="markets-modal-scroll fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="my-6 w-full max-w-lg max-h-[92vh] overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
            <div>
                <h2 class="text-lg font-extrabold text-slate-950">Add Market</h2>
                <p class="mt-0.5 text-xs text-slate-500">A market image is required when creating a market. Address and description are optional.</p>
            </div>
            <button type="button" onclick="closeModal('createModal')" class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" enctype="multipart/form-data" class="markets-modal-form space-y-4 overflow-y-auto p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">

            <div class="grid gap-x-4 gap-y-4 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-xs font-bold text-slate-600">Market Name *</span>
                    <input type="text" name="market_name" required maxlength="255" placeholder="Example: Mandalay Farmers Market"
                           class="h-10 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-xs font-bold text-slate-600">City *</span>
                    <select name="city_id" required class="h-10 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="">Select a city</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?= e($city['city_id']) ?>"><?= e($city['city_name'] . ' — ' . $city['administrative_division']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-xs font-bold text-slate-600">
                        Address
                        <span class="font-medium text-slate-400">(Optional)</span>
                    </span>
                    <input type="text" name="address" maxlength="255" placeholder="Street, township, landmark..."
                           class="h-10 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-bold text-slate-600">Opening Time *</span>
                    <input type="time" name="opening_hour" required value="06:00"
                           class="h-10 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-bold text-slate-600">Closing Time *</span>
                    <input type="time" name="closing_hour" required value="18:00"
                           class="h-10 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-xs font-bold text-slate-600">
                        Description
                        <span class="font-medium text-slate-400">(Optional)</span>
                    </span>
                    <textarea name="description" rows="3" placeholder="Describe the market, products, facilities, or opening days..."
                              class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"></textarea>
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-xs font-bold text-slate-600">
                        Market Image *
                    </span>

                    <input
                        type="file"
                        id="create_market_photo"
                        name="market_photo"
                        required
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        data-required-market-image="true"
                        class="block w-full rounded-xl border border-slate-200 bg-slate-50 text-sm text-slate-500 file:mr-4 file:border-0 file:bg-green-600 file:px-3.5 file:py-2.5 file:text-sm file:font-bold file:text-white hover:file:bg-green-700"
                    >

                    <span class="mt-1.5 block text-[11px] text-slate-400">
                        JPG, PNG, WEBP, or GIF. Maximum 5 MB.
                    </span>
                </label>

                <div id="createPhotoPreviewWrap"
                     class="hidden sm:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-2">
                    <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">
                        Image Preview
                    </p>
                    <img id="createPhotoPreview"
                         src=""
                         alt="Market image preview"
                         class="h-36 w-full rounded-lg bg-white object-cover">
                </div>
            </div>

            <div class="mt-1 flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('createModal')" class="rounded-xl border border-slate-200 px-3.5 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-green-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-green-700">Create Market</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Market Modal -->
<div id="editModal" class="markets-modal-scroll fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="my-6 w-full max-w-lg max-h-[92vh] overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
            <div>
                <h2 class="text-lg font-extrabold text-slate-950">Edit Market</h2>
                <p class="mt-0.5 text-xs text-slate-500">Update only the fields you want to change. Select a new image only if you want to replace the current market image.</p>
            </div>
            <button type="button" onclick="closeModal('editModal')" class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" enctype="multipart/form-data" class="markets-modal-form space-y-4 overflow-y-auto p-5">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit_market_id" name="market_id" value="">

            <div class="grid gap-x-4 gap-y-4 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-xs font-bold text-slate-600">Market Name *</span>
                    <input type="text" id="edit_market_name" name="market_name" required maxlength="255"
                           class="h-10 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-xs font-bold text-slate-600">City *</span>
                    <select id="edit_city_id" name="city_id" required class="h-10 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                        <option value="">Select a city</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?= e($city['city_id']) ?>"><?= e($city['city_name'] . ' — ' . $city['administrative_division']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-xs font-bold text-slate-600">
                        Address
                        <span class="font-medium text-slate-400">(Optional)</span>
                    </span>
                    <input type="text" id="edit_address" name="address" maxlength="255"
                           class="h-10 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-bold text-slate-600">Opening Time *</span>
                    <input type="time" id="edit_opening_hour" name="opening_hour" required
                           class="h-10 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-bold text-slate-600">Closing Time *</span>
                    <input type="time" id="edit_closing_hour" name="closing_hour" required
                           class="h-10 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-xs font-bold text-slate-600">
                        Description
                        <span class="font-medium text-slate-400">(Optional)</span>
                    </span>
                    <textarea id="edit_description" name="description" rows="3"
                              class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100"></textarea>
                </label>

                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-xs font-bold text-slate-600">
                        Market Image
                        <span class="font-medium text-slate-400">(Optional)</span>
                    </span>

                    <input
                        type="file"
                        id="edit_market_photo"
                        name="market_photo"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        class="block w-full rounded-xl border border-slate-200 bg-slate-50 text-sm text-slate-500 file:mr-4 file:border-0 file:bg-green-600 file:px-3.5 file:py-2.5 file:text-sm file:font-bold file:text-white hover:file:bg-green-700"
                    >

                    <span class="mt-1.5 block text-[11px] text-slate-400">
                        Leave this empty to keep the current image. Select a new image only if you want to replace it. JPG, PNG, WEBP, or GIF. Maximum 5 MB.
                    </span>
                </label>

                <div id="editPhotoPreviewWrap"
                     class="hidden sm:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-2">
                    <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">
                        Image Preview
                    </p>
                    <img id="editPhotoPreview"
                         src=""
                         alt="Market image preview"
                         class="h-36 w-full rounded-lg bg-white object-cover">
                </div>
            </div>

            <div class="mt-1 flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onclick="closeModal('editModal')" class="rounded-xl border border-slate-200 px-3.5 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-green-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-green-700">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Market Gallery Modal -->
<div id="galleryModal" class="markets-modal-scroll fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm">
    <div class="my-6 w-full max-w-5xl overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="text-lg font-extrabold text-slate-950">Market Photo Gallery</h2>
                <p id="gallery_market_name_label" class="mt-0.5 text-xs text-slate-500"></p>
            </div>
            <button type="button" onclick="closeModal('galleryModal')" class="grid h-9 w-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div id="galleryGrid" class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3"></div>
    </div>
</div>

<script>
    var marketPhotos = <?= json_encode(array_map(function ($photo) {
        return [
            'photo_id' => (int) $photo['photo_id'],
            'photo_name' => $photo['photo_name'],
            'photo_path' => marketPhotoUrl($photo['photo_path']),
            'market_id' => (int) $photo['market_id'],
            'description' => $photo['description'],
            'created_at' => $photo['created_at'],
        ];
    }, $allMarketPhotos), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    var csrfToken = <?= json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    function openModal(id) {
        var modal = document.getElementById(id);
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
    }

    function closeModal(id) {
        var modal = document.getElementById(id);
        modal.classList.add('hidden');
        modal.classList.remove('flex');

        var openModalCount = document.querySelectorAll('.fixed.inset-0.flex').length;
        if (openModalCount === 0) {
            document.body.classList.remove('overflow-hidden');
        }
    }

    function openCreateModal() {
        var createPhotoInput = document.getElementById('create_market_photo');
        var createPreview = document.getElementById('createPhotoPreview');
        var createPreviewWrap = document.getElementById('createPhotoPreviewWrap');

        if (createPhotoInput) {
            createPhotoInput.value = '';
        }

        if (createPreview) {
            createPreview.src = '';
        }

        if (createPreviewWrap) {
            createPreviewWrap.classList.add('hidden');
        }

        openModal('createModal');
    }

    function openEditModal(market) {
        document.getElementById('edit_market_id').value = market.market_id || '';
        document.getElementById('edit_market_name').value = market.market_name || '';
        document.getElementById('edit_address').value = market.address || '';
        document.getElementById('edit_city_id').value = market.city_id || '';
        document.getElementById('edit_opening_hour').value = market.opening_hour || '';
        document.getElementById('edit_closing_hour').value = market.closing_hour || '';
        document.getElementById('edit_description').value = market.description || '';

        var editPhotoInput = document.getElementById('edit_market_photo');
        var editPreview = document.getElementById('editPhotoPreview');
        var editPreviewWrap = document.getElementById('editPhotoPreviewWrap');

        if (editPhotoInput) {
            editPhotoInput.value = '';
        }

        if (editPreview) {
            editPreview.src = '';
        }

        if (editPreviewWrap) {
            editPreviewWrap.classList.add('hidden');
        }

        openModal('editModal');
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function openGalleryModal(marketId, marketName) {
        var grid = document.getElementById('galleryGrid');
        var photos = marketPhotos.filter(function (photo) {
            return Number(photo.market_id) === Number(marketId);
        });

        document.getElementById('gallery_market_name_label').textContent = marketName;
        grid.innerHTML = '';

        if (photos.length === 0) {
            grid.innerHTML = '<div class="col-span-full py-14 text-center text-sm text-slate-500">No photos are available for this market.</div>';
        } else {
            var canDeletePhoto = photos.length > 1;

            photos.forEach(function (photo) {
                var card = document.createElement('article');
                card.className = 'overflow-hidden rounded-2xl border border-slate-200 bg-white';

                var deleteControl = '';

                if (canDeletePhoto) {
                    deleteControl =
                        '<form method="post" onsubmit="return confirm(\'Delete this photo?\');">' +
                            '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken) + '">' +
                            '<input type="hidden" name="action" value="delete_photo">' +
                            '<input type="hidden" name="photo_id" value="' + Number(photo.photo_id) + '">' +
                            '<button type="submit" class="grid h-8 w-8 place-items-center rounded-lg border border-red-100 text-red-500 transition hover:bg-red-50" title="Delete photo">' +
                                '<i class="fa-solid fa-trash text-xs"></i>' +
                            '</button>' +
                        '</form>';
                } else {
                    deleteControl =
                        '<button type="button" disabled class="grid h-8 w-8 cursor-not-allowed place-items-center rounded-lg border border-slate-200 text-slate-300 opacity-60" title="This market must keep at least one image">' +
                            '<i class="fa-solid fa-trash text-xs"></i>' +
                        '</button>';
                }

                card.innerHTML =
                    '<img src="' + escapeHtml(photo.photo_path) + '" alt="' + escapeHtml(photo.photo_name) + '" class="h-48 w-full bg-slate-100 object-cover">' +
                    '<div class="p-4">' +
                        '<div class="flex items-start justify-between gap-3">' +
                            '<div class="min-w-0">' +
                                '<h3 class="truncate text-sm font-extrabold text-slate-900">' + escapeHtml(photo.photo_name) + '</h3>' +
                                '<p class="mt-1 text-xs leading-5 text-slate-500">' + escapeHtml(photo.description) + '</p>' +
                            '</div>' +
                            deleteControl +
                        '</div>' +
                        (!canDeletePhoto
                            ? '<p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[11px] font-medium text-amber-700">This is the required market image. Add another image before deleting it.</p>'
                            : '') +
                    '</div>';

                grid.appendChild(card);
            });
        }

        openModal('galleryModal');
    }

    function bindMarketImagePreview(inputId, wrapId, imageId) {
        var input = document.getElementById(inputId);
        var wrap = document.getElementById(wrapId);
        var image = document.getElementById(imageId);

        if (!input || !wrap || !image) {
            return;
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files.length > 0
                ? input.files[0]
                : null;

            image.removeAttribute('src');
            wrap.classList.add('hidden');

            if (!file) {
                return;
            }

            if (!file.type || file.type.indexOf('image/') !== 0) {
                input.value = '';
                input.setCustomValidity('Please select a valid image file.');
                input.reportValidity();
                return;
            }

            input.setCustomValidity('');

            var reader = new FileReader();

            reader.onload = function (event) {
                image.src = event.target.result;
                wrap.classList.remove('hidden');
            };

            reader.onerror = function () {
                input.value = '';
                input.setCustomValidity('The selected image could not be previewed.');
                input.reportValidity();
                wrap.classList.add('hidden');
            };

            reader.readAsDataURL(file);
        });
    }

    bindMarketImagePreview(
        'create_market_photo',
        'createPhotoPreviewWrap',
        'createPhotoPreview'
    );

    bindMarketImagePreview(
        'edit_market_photo',
        'editPhotoPreviewWrap',
        'editPhotoPreview'
    );

    var createMarketPhotoInput = document.getElementById('create_market_photo');

    if (createMarketPhotoInput) {
        createMarketPhotoInput.addEventListener('invalid', function () {
            if (!createMarketPhotoInput.files || createMarketPhotoInput.files.length === 0) {
                createMarketPhotoInput.setCustomValidity('Please select a market image.');
            }
        });

        createMarketPhotoInput.addEventListener('change', function () {
            createMarketPhotoInput.setCustomValidity('');
        });
    }

    var editMarketPhotoInput = document.getElementById('edit_market_photo');

    if (editMarketPhotoInput) {
        editMarketPhotoInput.addEventListener('change', function () {
            editMarketPhotoInput.setCustomValidity('');
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            ['createModal', 'editModal', 'galleryModal'].forEach(function (id) {
                closeModal(id);
            });
        }
    });

    ['createModal', 'editModal', 'galleryModal'].forEach(function (id) {
        var modal = document.getElementById(id);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal(id);
            }
        });
    });
</script>
</body>
</html>