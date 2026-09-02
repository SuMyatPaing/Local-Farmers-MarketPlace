<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
fm_require_role('user');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function up_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function up_redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function up_flash($type, $message)
{
    $_SESSION['user_profile_flash'] = array(
        'type' => $type,
        'message' => $message
    );
}

function up_csrf_token()
{
    if (
        !isset($_SESSION['user_profile_csrf']) ||
        !is_string($_SESSION['user_profile_csrf']) ||
        $_SESSION['user_profile_csrf'] === ''
    ) {
        $_SESSION['user_profile_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['user_profile_csrf'];
}

function up_verify_csrf($token)
{
    return isset($_SESSION['user_profile_csrf']) &&
        is_string($token) &&
        hash_equals($_SESSION['user_profile_csrf'], $token);
}

function up_image_url($path)
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path) || strpos($path, 'data:') === 0) {
        return $path;
    }

    return ltrim(str_replace('\\', '/', $path), '/');
}

function up_delete_image($path)
{
    $path = trim((string) $path);

    if ($path === '' || preg_match('/^https?:\/\//i', $path)) {
        return;
    }

    $projectRoot = realpath(__DIR__);
    $uploadRoot = realpath(__DIR__ . '/uploads/profiles');
    $absolutePath = realpath(__DIR__ . '/' . ltrim(str_replace('\\', '/', $path), '/'));

    if (
        $projectRoot !== false &&
        $uploadRoot !== false &&
        $absolutePath !== false &&
        strpos($absolutePath, $uploadRoot) === 0 &&
        is_file($absolutePath)
    ) {
        @unlink($absolutePath);
    }
}

function up_upload_image($file, $userId)
{
    if (
        !is_array($file) ||
        !isset($file['error']) ||
        (int) $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The profile image could not be uploaded.');
    }

    if ((int) $file['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException('The profile image must not exceed 3 MB.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The uploaded profile image is invalid.');
    }

    $imageInfo = @getimagesize($file['tmp_name']);

    if (!is_array($imageInfo) || !isset($imageInfo['mime'])) {
        throw new RuntimeException('The selected file is not a valid image.');
    }

    $allowedTypes = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    );

    $mimeType = (string) $imageInfo['mime'];

    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Only JPG, PNG and WEBP images are allowed.');
    }

    $uploadDirectory = __DIR__ . '/uploads/profiles';

    if (
        !is_dir($uploadDirectory) &&
        !mkdir($uploadDirectory, 0775, true) &&
        !is_dir($uploadDirectory)
    ) {
        throw new RuntimeException('The profile image folder could not be created.');
    }

    $fileName =
        'user_' .
        (int) $userId .
        '_' .
        date('Ymd_His') .
        '_' .
        bin2hex(random_bytes(5)) .
        '.' .
        $allowedTypes[$mimeType];

    $absolutePath = $uploadDirectory . '/' . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('The profile image could not be saved.');
    }

    return 'uploads/profiles/' . $fileName;
}

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
    exit('Database connection is not available. Check config/database.php.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);


$userId = (int) $_SESSION['user_id'];

function up_load_user(PDO $pdo, $userId)
{
    $statement = $pdo->prepare(
        "SELECT
            user_id,
            user_name,
            phone_number,
            email,
            profile_image,
            user_password,
            role,
            status,
            created_at,
            last_login
         FROM users
         WHERE user_id = :user_id
           AND role = 'user'
         LIMIT 1"
    );

    $statement->execute(array('user_id' => (int) $userId));

    return $statement->fetch(PDO::FETCH_ASSOC);
}

$user = up_load_user($pdo, $userId);

if (!$user) {
    exit('User profile was not found.');
}

if (strtolower((string) $user['status']) !== 'active') {
    up_redirect('logout.php');
}

$_SESSION['user_name'] = (string) $user['user_name'];
$_SESSION['user_profile_image'] = (string) $user['profile_image'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

    if (!up_verify_csrf($csrfToken)) {
        up_flash('error', 'Your form session expired. Please try again.');
        up_redirect('profile.php');
    }

    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
    $newImagePath = null;

    try {
        if ($action === 'remove_profile_image') {
            $oldImagePath = (string) $user['profile_image'];

            $statement = $pdo->prepare(
                "UPDATE users
                 SET profile_image = NULL
                 WHERE user_id = :user_id
                   AND role = 'user'"
            );

            $statement->execute(array('user_id' => $userId));
            up_delete_image($oldImagePath);

            $_SESSION['user_profile_image'] = '';
            up_flash('success', 'Profile image removed successfully.');
            up_redirect('profile.php');
        }

        if ($action !== 'update_profile') {
            throw new RuntimeException('Unknown profile action.');
        }

        $userName = isset($_POST['user_name']) ? trim((string) $_POST['user_name']) : '';
        $phoneNumber = isset($_POST['phone_number']) ? trim((string) $_POST['phone_number']) : '';
        $email = isset($_POST['email']) ? strtolower(trim((string) $_POST['email'])) : '';
        $currentPassword = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
        $newPassword = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $confirmPassword = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

        if ($userName === '') {
            throw new RuntimeException('Full name is required.');
        }

        if (strlen($userName) > 100) {
            throw new RuntimeException('Full name must not exceed 100 characters.');
        }

        if ($phoneNumber === '') {
            throw new RuntimeException('Phone number is required.');
        }

        if (strlen($phoneNumber) > 20) {
            throw new RuntimeException('Phone number must not exceed 20 characters.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }

        if (strlen($email) > 100) {
            throw new RuntimeException('Email address must not exceed 100 characters.');
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
            throw new RuntimeException('This email address is already used by another account.');
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
                throw new RuntimeException('Complete all three password fields.');
            }

            if (!password_verify($currentPassword, (string) $user['user_password'])) {
                throw new RuntimeException('Your current password is incorrect.');
            }

            if (strlen($newPassword) < 8) {
                throw new RuntimeException('The new password must contain at least 8 characters.');
            }

            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('New password and confirmation password do not match.');
            }

            if (password_verify($newPassword, (string) $user['user_password'])) {
                throw new RuntimeException('The new password must be different from the current password.');
            }
        }

        $newImagePath = up_upload_image(
            isset($_FILES['profile_image']) ? $_FILES['profile_image'] : null,
            $userId
        );

        $oldImagePath = (string) $user['profile_image'];
        $finalImagePath = $newImagePath !== null ? $newImagePath : $oldImagePath;

        $updateSql =
            "UPDATE users
             SET user_name = :user_name,
                 phone_number = :phone_number,
                 email = :email,
                 profile_image = :profile_image";

        if ($changePassword) {
            $updateSql .= ", user_password = :user_password";
        }

        $updateSql .= " WHERE user_id = :user_id AND role = 'user'";

        $parameters = array(
            'user_name' => $userName,
            'phone_number' => $phoneNumber,
            'email' => $email,
            'profile_image' => $finalImagePath !== '' ? $finalImagePath : null,
            'user_id' => $userId
        );

        if ($changePassword) {
            $parameters['user_password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $updateStatement = $pdo->prepare($updateSql);
        $updateStatement->execute($parameters);

        if (
            $newImagePath !== null &&
            $oldImagePath !== '' &&
            $oldImagePath !== $newImagePath
        ) {
            up_delete_image($oldImagePath);
        }

        $_SESSION['user_name'] = $userName;
        $_SESSION['user_profile_image'] = $finalImagePath;

        up_flash(
            'success',
            $changePassword
                ? 'Profile and password updated successfully.'
                : 'Profile updated successfully.'
        );

        up_redirect('profile.php');
    } catch (RuntimeException $exception) {
        if ($newImagePath !== null) {
            up_delete_image($newImagePath);
        }

        up_flash('error', $exception->getMessage());
        up_redirect('profile.php');
    } catch (PDOException $exception) {
        if ($newImagePath !== null) {
            up_delete_image($newImagePath);
        }

        up_flash('error', 'The database could not update your profile.');
        up_redirect('profile.php');
    }
}

$user = up_load_user($pdo, $userId);
$_SESSION['user_name'] = (string) $user['user_name'];
$_SESSION['user_profile_image'] = (string) $user['profile_image'];

$profileImageUrl = up_image_url($user['profile_image']);
$userInitial = trim((string) $user['user_name']) !== ''
    ? strtoupper(substr(trim((string) $user['user_name']), 0, 1))
    : 'U';

$flash = isset($_SESSION['user_profile_flash'])
    ? $_SESSION['user_profile_flash']
    : null;

unset($_SESSION['user_profile_flash']);

$csrfToken = up_csrf_token();
?>
<?php
$pageTitle = 'My Profile | Local Farmers Marketplace';
require __DIR__ . '/header.php';
?>

<main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
    <?php if ($flash): ?>
        <div class="mb-5 flex items-start gap-3 rounded-2xl border px-4 py-3.5 shadow-sm <?php echo $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800'; ?>">
            <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full <?php echo $flash['type'] === 'success' ? 'bg-green-100' : 'bg-red-100'; ?>">
                <i class="fa-solid <?php echo $flash['type'] === 'success' ? 'fa-check' : 'fa-triangle-exclamation'; ?> text-xs"></i>
            </span>

            <div>
                <p class="text-sm font-bold"><?php echo $flash['type'] === 'success' ? 'Success' : 'Unable to continue'; ?></p>
                <p class="mt-1 text-xs leading-5"><?php echo up_e($flash['message']); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
        <div class="border-b border-slate-100 px-5 py-5 sm:px-7">
            <p class="text-xs font-black uppercase tracking-[0.2em] text-green-600">Customer Account</p>
            <h1 class="mt-1 text-2xl font-black text-slate-950">My Profile</h1>
            <p class="mt-1 text-sm text-slate-400">Edit your profile image, personal details and password.</p>
        </div>

        <form method="post" action="profile.php" enctype="multipart/form-data" class="p-5 sm:p-7">
            <input type="hidden" name="csrf_token" value="<?php echo up_e($csrfToken); ?>">
            <input type="hidden" name="action" value="update_profile">

            <div class="grid gap-8 lg:grid-cols-[240px_1fr]">
                <aside>
                    <p class="mb-3 text-xs font-bold text-slate-700">Profile Image</p>

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-center">
                        <div class="relative mx-auto h-32 w-32">
                            <div class="h-32 w-32 overflow-hidden rounded-full border-4 border-white bg-green-100 shadow-lg">
                                <?php if ($profileImageUrl !== ''): ?>
                                    <img id="profileImagePreview" src="<?php echo up_e($profileImageUrl); ?>" alt="<?php echo up_e($user['user_name']); ?>" class="h-full w-full object-cover">
                                    <div id="profileImagePlaceholder" class="hidden h-full w-full place-items-center text-3xl text-green-600"><i class="fa-solid fa-camera"></i></div>
                                <?php else: ?>
                                    <div id="profileImagePlaceholder" class="grid h-full w-full place-items-center text-3xl text-green-600"><i class="fa-solid fa-camera"></i></div>
                                    <img id="profileImagePreview" src="" alt="Profile image preview" class="hidden h-full w-full object-cover">
                                <?php endif; ?>
                            </div>

                            <label for="profileImage" title="Choose profile image" class="absolute bottom-0 right-0 grid h-10 w-10 cursor-pointer place-items-center rounded-full border-4 border-white bg-green-600 text-white shadow-lg hover:bg-green-700">
                                <i class="fa-solid fa-camera text-xs"></i>
                            </label>
                        </div>

                        <input id="profileImage" type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="hidden">

                        <p class="mt-4 text-sm font-extrabold text-slate-800"><?php echo up_e($user['user_name']); ?></p>
                        <p class="mt-1 text-xs text-slate-400">JPG, PNG or WEBP. Maximum 3 MB.</p>

                        <label for="profileImage" class="mt-4 inline-flex cursor-pointer items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-xs font-bold text-green-700 hover:bg-green-100">
                            <i class="fa-solid fa-upload"></i>
                            Choose Image
                        </label>
                    </div>
                </aside>

                <div class="space-y-7">
                    <section>
                        <div class="mb-5 border-b border-slate-100 pb-3">
                            <h2 class="text-sm font-extrabold text-slate-900">Personal Information</h2>
                            <p class="mt-1 text-xs text-slate-400">Update your customer account details.</p>
                        </div>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="userName" class="mb-2 block text-xs font-bold text-slate-700">Full Name <span class="text-red-500">*</span></label>
                                <input id="userName" type="text" name="user_name" maxlength="100" required autocomplete="name" value="<?php echo up_e($user['user_name']); ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                            </div>

                            <div>
                                <label for="phoneNumber" class="mb-2 block text-xs font-bold text-slate-700">Phone Number <span class="text-red-500">*</span></label>
                                <input id="phoneNumber" type="text" name="phone_number" maxlength="20" required autocomplete="tel" value="<?php echo up_e($user['phone_number']); ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                            </div>

                            <div class="sm:col-span-2">
                                <label for="email" class="mb-2 block text-xs font-bold text-slate-700">Email Address <span class="text-red-500">*</span></label>
                                <input id="email" type="email" name="email" maxlength="100" required autocomplete="email" value="<?php echo up_e($user['email']); ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                            </div>
                        </div>
                    </section>

                    <section>
                        <div class="mb-5 border-b border-slate-100 pb-3">
                            <h2 class="text-sm font-extrabold text-slate-900">Change Password</h2>
                            <p class="mt-1 text-xs text-slate-400">Leave all password fields empty to keep your current password.</p>
                        </div>

                        <div class="grid gap-5">
                            <div>
                                <label for="currentPassword" class="mb-2 block text-xs font-bold text-slate-700">Current Password</label>
                                <div class="relative">
                                    <input id="currentPassword" type="password" name="current_password" autocomplete="current-password" placeholder="Enter current password" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 pr-12 text-sm outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                                    <button type="button" data-password-toggle="currentPassword" class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100"><i class="fa-regular fa-eye"></i></button>
                                </div>
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="newPassword" class="mb-2 block text-xs font-bold text-slate-700">New Password</label>
                                    <div class="relative">
                                        <input id="newPassword" type="password" name="new_password" minlength="8" autocomplete="new-password" placeholder="Minimum 8 characters" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 pr-12 text-sm outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                                        <button type="button" data-password-toggle="newPassword" class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100"><i class="fa-regular fa-eye"></i></button>
                                    </div>
                                </div>

                                <div>
                                    <label for="confirmPassword" class="mb-2 block text-xs font-bold text-slate-700">Confirm New Password</label>
                                    <div class="relative">
                                        <input id="confirmPassword" type="password" name="confirm_password" minlength="8" autocomplete="new-password" placeholder="Repeat new password" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 pr-12 text-sm outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                                        <button type="button" data-password-toggle="confirmPassword" class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100"><i class="fa-regular fa-eye"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="flex justify-end border-t border-slate-100 pt-6">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-green-600 px-7 py-3.5 text-sm font-bold text-white shadow-sm hover:bg-green-700">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Update Profile
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($profileImageUrl !== ''): ?>
            <div class="border-t border-slate-100 px-5 py-4 sm:px-7">
                <form method="post" action="profile.php" onsubmit="return confirm('Remove your profile image?');">
                    <input type="hidden" name="csrf_token" value="<?php echo up_e($csrfToken); ?>">
                    <input type="hidden" name="action" value="remove_profile_image">

                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-xs font-bold text-red-600 hover:bg-red-100">
                        <i class="fa-solid fa-trash"></i>
                        Remove Profile Image
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var profileMenuButton = document.getElementById('profileMenuButton');
        var profileMenu = document.getElementById('profileMenu');
        var profileMenuChevron = document.getElementById('profileMenuChevron');

        function closeProfileMenu() {
            if (!profileMenuButton || !profileMenu) {
                return;
            }

            profileMenu.classList.add('hidden');

            if (profileMenuChevron) {
                profileMenuChevron.classList.remove('rotate-180');
            }
        }

        if (profileMenuButton && profileMenu) {
            profileMenuButton.addEventListener('click', function (event) {
                event.stopPropagation();

                var hidden = profileMenu.classList.contains('hidden');
                profileMenu.classList.toggle('hidden');

                if (profileMenuChevron) {
                    profileMenuChevron.classList.toggle('rotate-180', hidden);
                }
            });

            profileMenu.addEventListener('click', function (event) {
                event.stopPropagation();
            });

            document.addEventListener('click', closeProfileMenu);
        }

        var passwordButtons = document.querySelectorAll('[data-password-toggle]');

        for (var index = 0; index < passwordButtons.length; index++) {
            passwordButtons[index].addEventListener('click', function () {
                var input = document.getElementById(this.getAttribute('data-password-toggle'));

                if (!input) {
                    return;
                }

                var visible = input.type === 'text';
                input.type = visible ? 'password' : 'text';
                this.innerHTML = visible
                    ? '<i class="fa-regular fa-eye"></i>'
                    : '<i class="fa-regular fa-eye-slash"></i>';
            });
        }

        var imageInput = document.getElementById('profileImage');
        var imagePreview = document.getElementById('profileImagePreview');
        var imagePlaceholder = document.getElementById('profileImagePlaceholder');

        if (imageInput && imagePreview) {
            imageInput.addEventListener('change', function () {
                if (!this.files || !this.files[0]) {
                    return;
                }

                var selectedFile = this.files[0];
                var allowedTypes = arrayContains(
                    ['image/jpeg', 'image/png', 'image/webp'],
                    selectedFile.type
                );

                if (selectedFile.size > 3 * 1024 * 1024) {
                    alert('The profile image must not exceed 3 MB.');
                    this.value = '';
                    return;
                }

                if (!allowedTypes) {
                    alert('Only JPG, PNG and WEBP images are allowed.');
                    this.value = '';
                    return;
                }

                var reader = new FileReader();

                reader.onload = function (event) {
                    imagePreview.src = event.target.result;
                    imagePreview.classList.remove('hidden');

                    if (imagePlaceholder) {
                        imagePlaceholder.classList.add('hidden');
                    }
                };

                reader.readAsDataURL(selectedFile);
            });
        }

        function arrayContains(items, value) {
            for (var index = 0; index < items.length; index++) {
                if (items[index] === value) {
                    return true;
                }
            }

            return false;
        }
    });
</script>

<?php require __DIR__ . '/footer.php'; ?>