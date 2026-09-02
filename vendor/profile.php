<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
fm_require_role('vendor');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Vendor Profile Settings
|--------------------------------------------------------------------------
| File:
| C:\xampp\htdocs\farmer_marketplace\vendor\profile.php
|
| This page manages:
| - Profile image
| - Email address
| - Phone number
| - Current password
| - New password
| - Confirm password
|--------------------------------------------------------------------------
*/

if (!function_exists('vendor_profile_e')) {
    function vendor_profile_e($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('vendor_profile_redirect')) {
    function vendor_profile_redirect($location)
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('vendor_profile_flash')) {
    function vendor_profile_flash($type, $message)
    {
        $_SESSION['vendor_profile_flash'] = array(
            'type' => $type,
            'message' => $message
        );
    }
}

if (!function_exists('vendor_profile_csrf_token')) {
    function vendor_profile_csrf_token()
    {
        if (
            !isset($_SESSION['vendor_profile_csrf']) ||
            !is_string($_SESSION['vendor_profile_csrf']) ||
            $_SESSION['vendor_profile_csrf'] === ''
        ) {
            $_SESSION['vendor_profile_csrf'] =
                bin2hex(random_bytes(32));
        }

        return $_SESSION['vendor_profile_csrf'];
    }
}

if (!function_exists('vendor_profile_verify_csrf')) {
    function vendor_profile_verify_csrf($token)
    {
        return isset($_SESSION['vendor_profile_csrf']) &&
            is_string($token) &&
            hash_equals(
                $_SESSION['vendor_profile_csrf'],
                $token
            );
    }
}

if (!function_exists('vendor_profile_image_url')) {
    function vendor_profile_image_url($path)
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

        return '../' . ltrim(
            str_replace('\\', '/', $path),
            '/'
        );
    }
}

if (!function_exists('vendor_profile_upload_image')) {
    function vendor_profile_upload_image(
        $file,
        $vendorId
    ) {
        if (
            !is_array($file) ||
            !isset($file['error']) ||
            (int) $file['error'] === UPLOAD_ERR_NO_FILE
        ) {
            return null;
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                'The profile image could not be uploaded.'
            );
        }

        if (
            !isset($file['size']) ||
            (int) $file['size'] > 3 * 1024 * 1024
        ) {
            throw new RuntimeException(
                'The profile image must not exceed 3 MB.'
            );
        }

        if (
            !isset($file['tmp_name']) ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            throw new RuntimeException(
                'The uploaded profile image is invalid.'
            );
        }

        $mimeType = '';

        if (class_exists('finfo')) {
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);

            $mimeType = (string) $fileInfo->file(
                $file['tmp_name']
            );
        }

        if (
            $mimeType === '' &&
            function_exists('mime_content_type')
        ) {
            $mimeType = (string) mime_content_type(
                $file['tmp_name']
            );
        }

        $allowedTypes = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        );

        if (!isset($allowedTypes[$mimeType])) {
            throw new RuntimeException(
                'Only JPG, PNG and WEBP profile images are allowed.'
            );
        }

        $uploadDirectory =
            dirname(__DIR__) . '/uploads/profiles';

        if (
            !is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0775, true) &&
            !is_dir($uploadDirectory)
        ) {
            throw new RuntimeException(
                'The profile image folder could not be created.'
            );
        }

        $fileName =
            'vendor_' .
            (int) $vendorId .
            '_' .
            date('Ymd_His') .
            '_' .
            bin2hex(random_bytes(6)) .
            '.' .
            $allowedTypes[$mimeType];

        $absolutePath =
            $uploadDirectory . '/' . $fileName;

        if (!move_uploaded_file(
            $file['tmp_name'],
            $absolutePath
        )) {
            throw new RuntimeException(
                'The profile image could not be saved.'
            );
        }

        return 'uploads/profiles/' . $fileName;
    }
}

if (!function_exists('vendor_profile_delete_image')) {
    function vendor_profile_delete_image($imagePath)
    {
        $imagePath = trim((string) $imagePath);

        if (
            $imagePath === '' ||
            preg_match('/^https?:\/\//i', $imagePath)
        ) {
            return;
        }

        $projectRoot = realpath(dirname(__DIR__));

        if ($projectRoot === false) {
            return;
        }

        $profileUploadRoot = realpath(
            $projectRoot . '/uploads/profiles'
        );

        $absolutePath = realpath(
            $projectRoot . '/' .
            ltrim(
                str_replace('\\', '/', $imagePath),
                '/'
            )
        );

        if (
            $profileUploadRoot !== false &&
            $absolutePath !== false &&
            strpos(
                $absolutePath,
                $profileUploadRoot
            ) === 0 &&
            is_file($absolutePath)
        ) {
            @unlink($absolutePath);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

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

try {
} catch (PDOException $exception) {
    exit(
        'The profile_image column could not be created. ' .
        'Run the supplied SQL file once.'
    );
}

/*
|--------------------------------------------------------------------------
| Vendor Login and Profile
|--------------------------------------------------------------------------
*/

$userId = isset($_SESSION['user_id'])
    ? (int) $_SESSION['user_id']
    : 0;

$sessionVendorId = isset($_SESSION['vendor_id'])
    ? (int) $_SESSION['vendor_id']
    : 0;

$sessionRole = isset($_SESSION['role'])
    ? strtolower((string) $_SESSION['role'])
    : '';

if ($sessionRole !== 'vendor') {
    vendor_profile_redirect('../signin.php');
}

function vendor_profile_load_vendor(
    PDO $pdo,
    $userId,
    $vendorId
) {
    if ($vendorId > 0) {
        $statement = $pdo->prepare(
            "SELECT
                v.vendor_id,
                v.user_id,
                v.vendor_name,
                v.address,
                v.profile_image,
                v.status AS vendor_status,
                v.rejection_reason,
                v.created_at AS vendor_created_at,
                v.updated_at AS vendor_updated_at,
                u.user_name,
                u.phone_number,
                u.email,
                u.user_password,
                u.role,
                u.status AS account_status,
                u.created_at AS account_created_at,
                u.last_login
             FROM vendors v
             INNER JOIN users u
                ON u.user_id = v.user_id
             WHERE v.vendor_id = :vendor_id
             LIMIT 1"
        );

        $statement->execute(array(
            'vendor_id' => (int) $vendorId
        ));
    } else {
        $statement = $pdo->prepare(
            "SELECT
                v.vendor_id,
                v.user_id,
                v.vendor_name,
                v.address,
                v.profile_image,
                v.status AS vendor_status,
                v.rejection_reason,
                v.created_at AS vendor_created_at,
                v.updated_at AS vendor_updated_at,
                u.user_name,
                u.phone_number,
                u.email,
                u.user_password,
                u.role,
                u.status AS account_status,
                u.created_at AS account_created_at,
                u.last_login
             FROM vendors v
             INNER JOIN users u
                ON u.user_id = v.user_id
             WHERE v.user_id = :user_id
             LIMIT 1"
        );

        $statement->execute(array(
            'user_id' => (int) $userId
        ));
    }

    return $statement->fetch(PDO::FETCH_ASSOC);
}

$vendor = vendor_profile_load_vendor(
    $pdo,
    $userId,
    $sessionVendorId
);

if (!$vendor) {
    exit('Vendor profile was not found.');
}

$vendorId = (int) $vendor['vendor_id'];
$userId = (int) $vendor['user_id'];

$_SESSION['vendor_id'] = $vendorId;
$_SESSION['vendor_name'] =
    (string) $vendor['vendor_name'];

$_SESSION['vendor_profile_image'] =
    (string) $vendor['profile_image'];

if (
    strtolower((string) $vendor['account_status']) !==
    'active'
) {
    vendor_profile_redirect('../logout.php');
}

/*
|--------------------------------------------------------------------------
| Save Profile
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!vendor_profile_verify_csrf($csrfToken)) {
        vendor_profile_flash(
            'error',
            'Your form session expired. Please try again.'
        );

        vendor_profile_redirect('profile.php');
    }

    $action = isset($_POST['action'])
        ? trim((string) $_POST['action'])
        : '';

    try {
        if ($action === 'remove_profile_image') {
            $oldImagePath =
                (string) $vendor['profile_image'];

            $statement = $pdo->prepare(
                "UPDATE vendors
                 SET profile_image = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE vendor_id = :vendor_id
                   AND user_id = :user_id"
            );

            $statement->execute(array(
                'vendor_id' => $vendorId,
                'user_id' => $userId
            ));

            vendor_profile_delete_image(
                $oldImagePath
            );

            $_SESSION['vendor_profile_image'] = '';

            vendor_profile_flash(
                'success',
                'Your profile image was removed.'
            );

            vendor_profile_redirect('profile.php');
        }

        if ($action !== 'save_profile') {
            throw new RuntimeException(
                'Unknown profile action.'
            );
        }

        $email = isset($_POST['email'])
            ? strtolower(
                trim((string) $_POST['email'])
            )
            : '';

        $phoneNumber =
            isset($_POST['phone_number'])
                ? trim((string) $_POST['phone_number'])
                : '';

        $currentPassword =
            isset($_POST['current_password'])
                ? (string) $_POST['current_password']
                : '';

        $newPassword =
            isset($_POST['new_password'])
                ? (string) $_POST['new_password']
                : '';

        $confirmPassword =
            isset($_POST['confirm_password'])
                ? (string) $_POST['confirm_password']
                : '';

        if (!filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )) {
            throw new RuntimeException(
                'Enter a valid email address.'
            );
        }

        if (strlen($email) > 100) {
            throw new RuntimeException(
                'Email address must not exceed 100 characters.'
            );
        }

        if ($phoneNumber === '') {
            throw new RuntimeException(
                'Phone number is required.'
            );
        }

        if (strlen($phoneNumber) > 20) {
            throw new RuntimeException(
                'Phone number must not exceed 20 characters.'
            );
        }

        $duplicateStatement = $pdo->prepare(
            "SELECT user_id
             FROM users
             WHERE email = :email
               AND user_id <> :user_id
             LIMIT 1"
        );

        $duplicateStatement->execute(array(
            'email' => $email,
            'user_id' => $userId
        ));

        if ($duplicateStatement->fetch()) {
            throw new RuntimeException(
                'This email address is already used by another account.'
            );
        }

        $changePassword =
            $currentPassword !== '' ||
            $newPassword !== '' ||
            $confirmPassword !== '';

        if ($changePassword) {
            if (
                $currentPassword === '' ||
                $newPassword === '' ||
                $confirmPassword === ''
            ) {
                throw new RuntimeException(
                    'Complete all three password fields.'
                );
            }

            if (!password_verify(
                $currentPassword,
                (string) $vendor['user_password']
            )) {
                throw new RuntimeException(
                    'Your current password is incorrect.'
                );
            }

            if (strlen($newPassword) < 8) {
                throw new RuntimeException(
                    'The new password must contain at least 8 characters.'
                );
            }

            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException(
                    'New password and confirmation password do not match.'
                );
            }

            if (password_verify(
                $newPassword,
                (string) $vendor['user_password']
            )) {
                throw new RuntimeException(
                    'The new password must be different from the current password.'
                );
            }
        }

        $newImagePath = vendor_profile_upload_image(
            isset($_FILES['profile_image'])
                ? $_FILES['profile_image']
                : null,
            $vendorId
        );

        $oldImagePath =
            (string) $vendor['profile_image'];

        $finalImagePath = $newImagePath !== null
            ? $newImagePath
            : $oldImagePath;

        $pdo->beginTransaction();

        $userSql =
            "UPDATE users
             SET email = :email,
                 phone_number = :phone_number";

        if ($changePassword) {
            $userSql .=
                ", user_password = :user_password";
        }

        $userSql .=
            " WHERE user_id = :user_id";

        $userStatement = $pdo->prepare($userSql);

        $userParameters = array(
            'email' => $email,
            'phone_number' => $phoneNumber,
            'user_id' => $userId
        );

        if ($changePassword) {
            $userParameters['user_password'] =
                password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );
        }

        $userStatement->execute($userParameters);

        $vendorStatement = $pdo->prepare(
            "UPDATE vendors
             SET profile_image = :profile_image,
                 updated_at = CURRENT_TIMESTAMP
             WHERE vendor_id = :vendor_id
               AND user_id = :user_id"
        );

        $vendorStatement->execute(array(
            'profile_image' => $finalImagePath !== ''
                ? $finalImagePath
                : null,
            'vendor_id' => $vendorId,
            'user_id' => $userId
        ));

        $pdo->commit();

        if (
            $newImagePath !== null &&
            $oldImagePath !== '' &&
            $oldImagePath !== $newImagePath
        ) {
            vendor_profile_delete_image(
                $oldImagePath
            );
        }

        $_SESSION['vendor_profile_image'] =
            $finalImagePath;

        vendor_profile_flash(
            'success',
            $changePassword
                ? 'Your profile and password were updated successfully.'
                : 'Your profile was updated successfully.'
        );

        vendor_profile_redirect('profile.php');
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (
            isset($newImagePath) &&
            $newImagePath !== null
        ) {
            vendor_profile_delete_image(
                $newImagePath
            );
        }

        vendor_profile_flash(
            'error',
            $exception->getMessage()
        );

        vendor_profile_redirect('profile.php');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (
            isset($newImagePath) &&
            $newImagePath !== null
        ) {
            vendor_profile_delete_image(
                $newImagePath
            );
        }

        vendor_profile_flash(
            'error',
            'The database could not complete the request.'
        );

        vendor_profile_redirect('profile.php');
    }
}

/*
|--------------------------------------------------------------------------
| Page Data
|--------------------------------------------------------------------------
*/

$vendor = vendor_profile_load_vendor(
    $pdo,
    $userId,
    $vendorId
);

$_SESSION['vendor_profile_image'] =
    (string) $vendor['profile_image'];

$profileImageUrl = vendor_profile_image_url(
    $vendor['profile_image']
);

$flash = isset($_SESSION['vendor_profile_flash'])
    ? $_SESSION['vendor_profile_flash']
    : null;

unset($_SESSION['vendor_profile_flash']);

$csrfToken = vendor_profile_csrf_token();

$pageTitle = 'My Profile';
$pageSubtitle = 'Manage contact, password and profile image';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>My Profile | Farmers Market Vendor</title>

    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css">

    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">

    
    <link rel="stylesheet" href="../assets/css/responsive.css?v=20260813-responsive-v4">
</head>

<body class="min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">

<div class="min-h-screen">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="min-h-screen lg:ml-64">

        <?php require __DIR__ . '/header.php'; ?>

        <main class="px-4 pb-10 pt-5 sm:px-6 xl:px-7">

            <?php if ($flash): ?>

                <div class="mb-5 flex items-start gap-3 rounded-2xl border px-4 py-3.5 shadow-sm
                    <?php echo $flash['type'] === 'success'
                        ? 'border-green-200 bg-green-50 text-green-800'
                        : 'border-red-200 bg-red-50 text-red-800'; ?>">

                    <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full
                        <?php echo $flash['type'] === 'success'
                            ? 'bg-green-100'
                            : 'bg-red-100'; ?>">

                        <i class="fa-solid <?php echo $flash['type'] === 'success'
                            ? 'fa-check'
                            : 'fa-triangle-exclamation'; ?> text-xs"></i>
                    </span>

                    <div>
                        <p class="text-sm font-bold">

                            <?php echo $flash['type'] === 'success'
                                ? 'Success'
                                : 'Unable to continue'; ?>
                        </p>

                        <p class="mt-1 text-xs leading-5">

                            <?php echo vendor_profile_e(
                                $flash['message']
                            ); ?>
                        </p>
                    </div>
                </div>

            <?php endif; ?>

            <section class="mx-auto max-w-5xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

                <div class="border-b border-slate-100 px-5 py-4 sm:px-6">

                    <h2 class="text-base font-extrabold text-slate-950">
                        Profile Settings
                    </h2>

                    <p class="mt-1 text-xs text-slate-400">
                        Update your profile image, contact details and password.
                    </p>
                </div>

                <form method="post"
                      action="profile.php"
                      enctype="multipart/form-data"
                      class="p-5 sm:p-6">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?php echo vendor_profile_e($csrfToken); ?>">

                    <input type="hidden"
                           name="action"
                           value="save_profile">

                    <div class="grid gap-8 lg:grid-cols-[240px_1fr]">

                        <!-- Profile Image -->
                        <div>

                            <p class="mb-3 text-xs font-bold text-slate-700">
                                Profile Image
                            </p>

                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-center">

                                <div class="relative mx-auto h-32 w-32">

                                    <div class="h-32 w-32 overflow-hidden rounded-full border-4 border-white bg-green-100 shadow-lg">

                                        <?php if ($profileImageUrl !== ''): ?>

                                            <img id="profileImagePreview"
                                                 src="<?php echo vendor_profile_e($profileImageUrl); ?>"
                                                 alt="Vendor profile"
                                                 class="h-full w-full object-cover">

                                        <?php else: ?>

                                            <div id="profileImagePlaceholder"
                                                 class="grid h-full w-full place-items-center text-green-600">

                                                <i class="fa-solid fa-camera text-3xl"></i>
                                            </div>

                                            <img id="profileImagePreview"
                                                 src=""
                                                 alt="Vendor profile preview"
                                                 class="hidden h-full w-full object-cover">

                                        <?php endif; ?>
                                    </div>

                                    <label for="profileImage"
                                           title="Choose profile image"
                                           class="absolute bottom-0 right-0 grid h-10 w-10 cursor-pointer place-items-center rounded-full border-4 border-white bg-green-600 text-white shadow-lg transition hover:bg-green-700">

                                        <i class="fa-solid fa-camera text-xs"></i>
                                    </label>
                                </div>

                                <input id="profileImage"
                                       type="file"
                                       name="profile_image"
                                       accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                       class="hidden">

                                <p class="mt-4 text-sm font-extrabold text-slate-800">

                                    <?php echo vendor_profile_e(
                                        $vendor['vendor_name']
                                    ); ?>
                                </p>

                                <p class="mt-1 text-xs text-slate-400">
                                    JPG, PNG or WEBP. Maximum 3 MB.
                                </p>

                                <label for="profileImage"
                                       class="mt-4 inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-xs font-bold text-green-700 transition hover:bg-green-100">

                                    <i class="fa-solid fa-upload"></i>

                                    Choose Image
                                </label>
                            </div>
                        </div>

                        <!-- Fields -->
                        <div>

                            <div class="grid gap-5 sm:grid-cols-2">

                                <div>
                                    <label for="email"
                                           class="mb-2 block text-xs font-bold text-slate-700">
                                        Email Address
                                        <span class="text-red-500">*</span>
                                    </label>

                                    <div class="relative">

                                        <i class="fa-regular fa-envelope pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                                        <input id="email"
                                               type="email"
                                               name="email"
                                               maxlength="100"
                                               required
                                               autocomplete="email"
                                               value="<?php echo vendor_profile_e($vendor['email']); ?>"
                                               class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3.5 pl-11 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                                    </div>
                                </div>

                                <div>
                                    <label for="phoneNumber"
                                           class="mb-2 block text-xs font-bold text-slate-700">
                                        Phone Number
                                        <span class="text-red-500">*</span>
                                    </label>

                                    <div class="relative">

                                        <i class="fa-solid fa-phone pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                                        <input id="phoneNumber"
                                               type="text"
                                               name="phone_number"
                                               maxlength="20"
                                               required
                                               autocomplete="tel"
                                               value="<?php echo vendor_profile_e($vendor['phone_number']); ?>"
                                               class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3.5 pl-11 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                                    </div>
                                </div>
                            </div>

                            <div class="my-6 border-t border-slate-100"></div>

                            <div>

                                <div class="mb-5">

                                    <h3 class="text-sm font-extrabold text-slate-900">
                                        Change Password
                                    </h3>

                                    <p class="mt-1 text-xs text-slate-400">
                                        Leave all password fields empty to keep your current password.
                                    </p>
                                </div>

                                <div class="grid gap-5">

                                    <div>
                                        <label for="currentPassword"
                                               class="mb-2 block text-xs font-bold text-slate-700">
                                            Current Password
                                        </label>

                                        <div class="relative">

                                            <input id="currentPassword"
                                                   type="password"
                                                   name="current_password"
                                                   autocomplete="current-password"
                                                   placeholder="Enter current password"
                                                   class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 pr-12 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                                            <button type="button"
                                                    data-password-toggle="currentPassword"
                                                    class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">

                                                <i class="fa-regular fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="grid gap-5 sm:grid-cols-2">

                                        <div>
                                            <label for="newPassword"
                                                   class="mb-2 block text-xs font-bold text-slate-700">
                                                New Password
                                            </label>

                                            <div class="relative">

                                                <input id="newPassword"
                                                       type="password"
                                                       name="new_password"
                                                       minlength="8"
                                                       autocomplete="new-password"
                                                       placeholder="Minimum 8 characters"
                                                       class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 pr-12 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                                                <button type="button"
                                                        data-password-toggle="newPassword"
                                                        class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">

                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div>
                                            <label for="confirmPassword"
                                                   class="mb-2 block text-xs font-bold text-slate-700">
                                                Confirm Password
                                            </label>

                                            <div class="relative">

                                                <input id="confirmPassword"
                                                       type="password"
                                                       name="confirm_password"
                                                       minlength="8"
                                                       autocomplete="new-password"
                                                       placeholder="Repeat new password"
                                                       class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 pr-12 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                                                <button type="button"
                                                        data-password-toggle="confirmPassword"
                                                        class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">

                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-7 flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:items-center sm:justify-between">

                                <?php if ($profileImageUrl !== ''): ?>

                                    <button type="submit"
                                            name="action"
                                            value="remove_profile_image"
                                            formnovalidate
                                            onclick="return confirm('Remove your profile image?');"
                                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-bold text-red-600 transition hover:bg-red-100">

                                        <i class="fa-solid fa-trash"></i>

                                        Remove Image
                                    </button>

                                <?php else: ?>

                                    <span></span>

                                <?php endif; ?>

                                <button type="submit"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-green-600 px-6 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-green-700">

                                    <i class="fa-solid fa-floppy-disk"></i>

                                    Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </section>
        </main>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var passwordButtons = document.querySelectorAll(
            '[data-password-toggle]'
        );

        for (
            var index = 0;
            index < passwordButtons.length;
            index++
        ) {
            passwordButtons[index].addEventListener(
                'click',
                function () {
                    var inputId = this.getAttribute(
                        'data-password-toggle'
                    );

                    var input = document.getElementById(
                        inputId
                    );

                    if (!input) {
                        return;
                    }

                    var isVisible = input.type === 'text';

                    input.type = isVisible
                        ? 'password'
                        : 'text';

                    this.innerHTML = isVisible
                        ? '<i class="fa-regular fa-eye"></i>'
                        : '<i class="fa-regular fa-eye-slash"></i>';
                }
            );
        }

        var imageInput = document.getElementById(
            'profileImage'
        );

        var imagePreview = document.getElementById(
            'profileImagePreview'
        );

        var imagePlaceholder = document.getElementById(
            'profileImagePlaceholder'
        );

        if (imageInput && imagePreview) {
            imageInput.addEventListener(
                'change',
                function () {
                    if (
                        !this.files ||
                        !this.files[0]
                    ) {
                        return;
                    }

                    var selectedFile = this.files[0];

                    if (
                        selectedFile.size >
                        3 * 1024 * 1024
                    ) {
                        alert(
                            'The profile image must not exceed 3 MB.'
                        );

                        this.value = '';

                        return;
                    }

                    var reader = new FileReader();

                    reader.onload = function (event) {
                        imagePreview.src =
                            event.target.result;

                        imagePreview.classList.remove(
                            'hidden'
                        );

                        if (imagePlaceholder) {
                            imagePlaceholder.classList.add(
                                'hidden'
                            );
                        }
                    };

                    reader.readAsDataURL(selectedFile);
                }
            );
        }
    });
</script>

</body>
</html>