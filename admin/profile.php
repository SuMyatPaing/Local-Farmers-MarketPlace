<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../auth.php';
fm_require_role('admin');
require_once __DIR__ . '/../config/database.php';

if ((!isset($pdo) || !($pdo instanceof PDO)) && function_exists('getPDO')) {
    $pdo = getPDO();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit('Database connection is not available.');
}

function ap_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ap_redirect($location)
{
    header('Location: ' . $location);
    exit;
}

function ap_flash($type, $message)
{
    $_SESSION['admin_profile_flash'] = array(
        'type' => (string) $type,
        'message' => (string) $message
    );
}

$adminId = (int) $_SESSION['user_id'];

function ap_load_admin(PDO $pdo, $adminId)
{
    $statement = $pdo->prepare(
        "SELECT
            user_id,
            user_name,
            phone_number,
            email,
            user_password,
            status,
            must_change_password
         FROM users
         WHERE user_id = :user_id
           AND role = 'admin'
         LIMIT 1"
    );
    $statement->execute(array('user_id' => (int) $adminId));
    return $statement->fetch(PDO::FETCH_ASSOC);
}

$admin = ap_load_admin($pdo, $adminId);

if (!$admin || strtolower((string) $admin['status']) !== 'active') {
    fm_logout_user();
    fm_redirect(fm_url('signin.php?notice=account_unavailable'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

    if (!fm_verify_csrf($token)) {
        ap_flash('error', 'Your form session expired. Please try again.');
        ap_redirect('profile.php');
    }

    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';

    try {
        if ($action === 'update_profile') {
            $name = trim(isset($_POST['user_name']) ? (string) $_POST['user_name'] : '');
            $phone = trim(isset($_POST['phone_number']) ? (string) $_POST['phone_number'] : '');
            $email = strtolower(trim(isset($_POST['email']) ? (string) $_POST['email'] : ''));

            if ($name === '' || strlen($name) > 100) {
                throw new RuntimeException('Full name is required and must not exceed 100 characters.');
            }

            if ($phone === '' || strlen($phone) > 20) {
                throw new RuntimeException('Phone number is required and must not exceed 20 characters.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                throw new RuntimeException('Enter a valid email address.');
            }

            $duplicate = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM users
                 WHERE email = :email
                   AND user_id <> :user_id"
            );
            $duplicate->execute(array(
                'email' => $email,
                'user_id' => $adminId
            ));

            if ((int) $duplicate->fetchColumn() > 0) {
                throw new RuntimeException('That email address is already used by another account.');
            }

            $statement = $pdo->prepare(
                "UPDATE users
                 SET user_name = :user_name,
                     phone_number = :phone_number,
                     email = :email
                 WHERE user_id = :user_id
                   AND role = 'admin'"
            );
            $statement->execute(array(
                'user_name' => $name,
                'phone_number' => $phone,
                'email' => $email,
                'user_id' => $adminId
            ));

            $_SESSION['user_name'] = $name;
            $_SESSION['name'] = $name;
            $_SESSION['email'] = $email;

            ap_flash('success', 'Personal information updated successfully.');
            ap_redirect('profile.php');
        }

        if ($action === 'change_password') {
            $currentPassword = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
            $newPassword = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
            $confirmPassword = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

            if (!password_verify($currentPassword, (string) $admin['user_password'])) {
                throw new RuntimeException('The current password is incorrect.');
            }

            if (strlen($newPassword) < 8) {
                throw new RuntimeException('The new password must contain at least 8 characters.');
            }

            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('The new password and confirmation do not match.');
            }

            if (password_verify($newPassword, (string) $admin['user_password'])) {
                throw new RuntimeException('Choose a password different from your current password.');
            }

            $statement = $pdo->prepare(
                "UPDATE users
                 SET user_password = :user_password,
                     must_change_password = 0
                 WHERE user_id = :user_id
                   AND role = 'admin'"
            );
            $statement->execute(array(
                'user_password' => password_hash($newPassword, PASSWORD_DEFAULT),
                'user_id' => $adminId
            ));

            $_SESSION['must_change_password'] = 0;
            ap_flash('success', 'Password updated successfully.');
            ap_redirect('profile.php');
        }

        throw new RuntimeException('Unknown profile action.');
    } catch (RuntimeException $exception) {
        ap_flash('error', $exception->getMessage());
        ap_redirect('profile.php');
    } catch (PDOException $exception) {
        error_log('Admin profile update failed: ' . $exception->getMessage());
        ap_flash('error', 'The database could not save your changes.');
        ap_redirect('profile.php');
    }
}

$admin = ap_load_admin($pdo, $adminId);
$csrfToken = fm_csrf_token();
$flash = isset($_SESSION['admin_profile_flash']) ? $_SESSION['admin_profile_flash'] : null;
unset($_SESSION['admin_profile_flash']);
$forcePasswordChange = !empty($admin['must_change_password']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | Local Farmers Marketplace</title>
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

        <main class="p-4 sm:p-6 xl:p-7">
            <div class="mx-auto max-w-7xl space-y-5">
                <?php if ($forcePasswordChange): ?>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        <div class="flex gap-3">
                            <i class="fa-solid fa-key mt-0.5"></i>
                            <div>
                                <p class="font-extrabold">Change the temporary Admin password</p>
                                <p class="mt-1 text-xs leading-5">For security, the rest of the Admin panel stays locked until you replace the temporary setup password.</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (is_array($flash)): ?>
                    <div class="rounded-2xl border p-4 text-sm <?php echo $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800'; ?>">
                        <i class="fa-solid <?php echo $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> mr-2"></i>
                        <?php echo ap_e($flash['message']); ?>
                    </div>
                <?php endif; ?>

                <section class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(330px,0.65fr)]">
                    <article class="rounded-2xl border border-slate-200 bg-white shadow-card">
                        <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                            <h1 class="text-lg font-extrabold text-slate-950">Personal Information</h1>
                            <p class="mt-1 text-xs text-slate-500">Update the information shown in the Admin panel.</p>
                        </div>

                        <form method="post" action="profile.php" class="space-y-5 p-5 sm:p-6">
                            <input type="hidden" name="csrf_token" value="<?php echo ap_e($csrfToken); ?>">
                            <input type="hidden" name="action" value="update_profile">

                            <label class="block">
                                <span class="mb-2 block text-xs font-bold text-slate-600">Full Name *</span>
                                <div class="relative">
                                    <i class="fa-regular fa-user pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                                    <input type="text" name="user_name" required maxlength="100" value="<?php echo ap_e($admin['user_name']); ?>" class="h-12 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-4 text-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-100">
                                </div>
                            </label>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <label class="block">
                                    <span class="mb-2 block text-xs font-bold text-slate-600">Phone Number *</span>
                                    <div class="relative">
                                        <i class="fa-solid fa-phone pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                                        <input type="text" name="phone_number" required maxlength="20" value="<?php echo ap_e($admin['phone_number']); ?>" class="h-12 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-4 text-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-100">
                                    </div>
                                </label>

                                <label class="block">
                                    <span class="mb-2 block text-xs font-bold text-slate-600">Email Address *</span>
                                    <div class="relative">
                                        <i class="fa-regular fa-envelope pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                                        <input type="email" name="email" required maxlength="100" value="<?php echo ap_e($admin['email']); ?>" class="h-12 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-4 text-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-100">
                                    </div>
                                </label>
                            </div>

                            <div class="flex justify-end border-t border-slate-100 pt-5">
                                <button type="submit" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-extrabold text-white shadow-sm transition hover:bg-green-700">
                                    <i class="fa-solid fa-floppy-disk text-xs"></i>
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </article>

                    <article class="rounded-2xl border border-slate-200 bg-white shadow-card">
                        <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                            <h2 class="text-lg font-extrabold text-slate-950">Change Password</h2>
                            <p class="mt-1 text-xs text-slate-500">Use at least 8 characters.</p>
                        </div>

                        <form method="post" action="profile.php" class="space-y-4 p-5 sm:p-6" id="passwordForm">
                            <input type="hidden" name="csrf_token" value="<?php echo ap_e($csrfToken); ?>">
                            <input type="hidden" name="action" value="change_password">

                            <?php foreach (array(
                                array('currentPassword', 'current_password', 'Current Password', 'current-password', 'fa-lock'),
                                array('newPassword', 'new_password', 'New Password', 'new-password', 'fa-key'),
                                array('confirmPassword', 'confirm_password', 'Confirm New Password', 'new-password', 'fa-key')
                            ) as $field): ?>
                                <label class="block">
                                    <span class="mb-2 block text-xs font-bold text-slate-600"><?php echo ap_e($field[2]); ?> *</span>
                                    <div class="relative">
                                        <i class="fa-solid <?php echo ap_e($field[4]); ?> pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                                        <input id="<?php echo ap_e($field[0]); ?>" type="password" name="<?php echo ap_e($field[1]); ?>" required <?php echo $field[1] !== 'current_password' ? 'minlength="8"' : ''; ?> autocomplete="<?php echo ap_e($field[3]); ?>" class="h-12 w-full rounded-xl border border-slate-200 pl-10 pr-11 text-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-100">
                                        <button type="button" data-password-toggle="<?php echo ap_e($field[0]); ?>" class="absolute right-2 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100">
                                            <i class="fa-regular fa-eye text-xs"></i>
                                        </button>
                                    </div>
                                </label>
                            <?php endforeach; ?>

                            <p id="passwordMatchMessage" class="hidden rounded-xl px-3 py-2 text-xs font-semibold"></p>

                            <button type="submit" class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-[#123b22] px-5 text-sm font-extrabold text-white transition hover:bg-green-900">
                                <i class="fa-solid fa-shield-halved text-xs"></i>
                                Update Password
                            </button>
                        </form>
                    </article>
                </section>
            </div>
        </main>
    </div>
</div>

<script>
(function () {
    var buttons = document.querySelectorAll('[data-password-toggle]');
    var newPassword = document.getElementById('newPassword');
    var confirmPassword = document.getElementById('confirmPassword');
    var message = document.getElementById('passwordMatchMessage');
    var form = document.getElementById('passwordForm');

    Array.prototype.forEach.call(buttons, function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));
            var icon = button.querySelector('i');
            if (!input) { return; }
            input.type = input.type === 'password' ? 'text' : 'password';
            if (icon) {
                icon.className = input.type === 'password' ? 'fa-regular fa-eye text-xs' : 'fa-regular fa-eye-slash text-xs';
            }
        });
    });

    function validateMatch() {
        if (!newPassword || !confirmPassword || !message || confirmPassword.value === '') {
            if (message) { message.classList.add('hidden'); }
            return true;
        }

        var matches = newPassword.value === confirmPassword.value;
        message.className = 'rounded-xl px-3 py-2 text-xs font-semibold ' +
            (matches ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700');
        message.textContent = matches ? 'Passwords match.' : 'Passwords do not match.';
        return matches;
    }

    if (newPassword) { newPassword.addEventListener('input', validateMatch); }
    if (confirmPassword) { confirmPassword.addEventListener('input', validateMatch); }
    if (form) {
        form.addEventListener('submit', function (event) {
            if (!validateMatch()) {
                event.preventDefault();
                confirmPassword.focus();
            }
        });
    }
}());
</script>
</body>
</html>
