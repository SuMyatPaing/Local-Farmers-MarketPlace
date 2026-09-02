<?php
require_once __DIR__ . '/security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('register_e')) {
    function register_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('register_redirect')) {
    function register_redirect($location)
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('register_csrf_token')) {
    function register_csrf_token()
    {
        if (
            !isset($_SESSION['register_csrf_token']) ||
            !is_string($_SESSION['register_csrf_token']) ||
            $_SESSION['register_csrf_token'] === ''
        ) {
            $_SESSION['register_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['register_csrf_token'];
    }
}

if (!function_exists('register_verify_csrf')) {
    function register_verify_csrf($token)
    {
        return isset($_SESSION['register_csrf_token']) &&
            is_string($token) &&
            hash_equals($_SESSION['register_csrf_token'], $token);
    }
}

if (
    isset($_SESSION['user_id']) &&
    (int) $_SESSION['user_id'] > 0 &&
    isset($_SESSION['role'])
) {
    $loggedInRole = strtolower((string) $_SESSION['role']);

    if ($loggedInRole === 'admin') {
        register_redirect('admin/dashboard.php');
    }

    if ($loggedInRole === 'vendor') {
        register_redirect('vendor/dashboard.php');
    }

    if ($loggedInRole === 'user') {
        register_redirect('index.php');
    }
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

$form = array(
    'user_name' => '',
    'phone_number' => '',
    'email' => '',
    'vendor_name' => '',
    'address' => ''
);

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['user_name'] = isset($_POST['user_name'])
        ? trim((string) $_POST['user_name'])
        : '';

    $form['phone_number'] = isset($_POST['phone_number'])
        ? trim((string) $_POST['phone_number'])
        : '';

    $form['email'] = isset($_POST['email'])
        ? strtolower(trim((string) $_POST['email']))
        : '';

    $form['vendor_name'] = isset($_POST['vendor_name'])
        ? trim((string) $_POST['vendor_name'])
        : '';

    $form['address'] = isset($_POST['address'])
        ? trim((string) $_POST['address'])
        : '';

    $password = isset($_POST['password'])
        ? (string) $_POST['password']
        : '';

    $confirmPassword = isset($_POST['confirm_password'])
        ? (string) $_POST['confirm_password']
        : '';

    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!register_verify_csrf($csrfToken)) {
        $errorMessage = 'Your form session expired. Refresh the page and try again.';
    } elseif ($form['user_name'] === '') {
        $errorMessage = 'Owner full name is required.';
    } elseif (strlen($form['user_name']) > 100) {
        $errorMessage = 'Owner name must not exceed 100 characters.';
    } elseif ($form['phone_number'] === '') {
        $errorMessage = 'Phone number is required.';
    } elseif (!preg_match('/^09\d{7,9}$/', $form['phone_number'])) {
        $errorMessage = 'Enter a valid Myanmar mobile number starting with 09.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Enter a valid email address.';
    } elseif (strlen($form['email']) > 100) {
        $errorMessage = 'Email must not exceed 100 characters.';
    } elseif ($form['vendor_name'] === '') {
        $errorMessage = 'Vendor or business name is required.';
    } elseif (strlen($form['vendor_name']) > 100) {
        $errorMessage = 'Vendor name must not exceed 100 characters.';
    } elseif ($form['address'] === '') {
        $errorMessage = 'Vendor address is required.';
    } elseif (strlen($form['address']) > 255) {
        $errorMessage = 'Vendor address must not exceed 255 characters.';
    } elseif (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        $errorMessage = 'Password must be at least 8 characters and include uppercase, lowercase, number and special character.';
    } elseif ($password !== $confirmPassword) {
        $errorMessage = 'Password and confirmation password do not match.';
    } else {
        try {
            $duplicateStatement = $pdo->prepare(
                "SELECT user_id
                 FROM users
                 WHERE email = :email
                 LIMIT 1"
            );

            $duplicateStatement->execute(array(
                'email' => $form['email']
            ));

            if ($duplicateStatement->fetch()) {
                $errorMessage = 'This email address is already registered.';
            } else {
                $pdo->beginTransaction();

                $userStatement = $pdo->prepare(
                    "INSERT INTO users (
                        user_name,
                        phone_number,
                        email,
                        user_password,
                        role,
                        status
                     ) VALUES (
                        :user_name,
                        :phone_number,
                        :email,
                        :user_password,
                        'vendor',
                        'active'
                     )"
                );

                $userStatement->execute(array(
                    'user_name' => $form['user_name'],
                    'phone_number' => $form['phone_number'],
                    'email' => $form['email'],
                    'user_password' => password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    )
                ));

                $newUserId = (int) $pdo->lastInsertId();

                $vendorStatement = $pdo->prepare(
                    "INSERT INTO vendors (
                        user_id,
                        vendor_name,
                        address,
                        status
                     ) VALUES (
                        :user_id,
                        :vendor_name,
                        :address,
                        'pending'
                     )"
                );

                $vendorStatement->execute(array(
                    'user_id' => $newUserId,
                    'vendor_name' => $form['vendor_name'],
                    'address' => $form['address']
                ));

                $newVendorId = (int) $pdo->lastInsertId();

                $permissionStatement = $pdo->prepare(
                    "INSERT INTO permission (
                        vendor_id,
                        upload_limit,
                        can_delete
                     ) VALUES (
                        :vendor_id,
                        10,
                        1
                     )"
                );

                $permissionStatement->execute(array(
                    'vendor_id' => $newVendorId
                ));

                $pdo->commit();

                $_SESSION['registration_success'] =
                    'Vendor account created. Your application is pending Admin approval.';

                register_redirect('signin.php?notice=registered');
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errorMessage = 'The database could not create your Vendor account.';
        }
    }
}

$csrfToken = register_csrf_token();
$pageTitle = 'Vendor Sign Up | Local Farmers Marketplace';

require __DIR__ . '/header.php';
?>

<style>
    html {
        overflow-y: auto;
    }

    body.market-public-view.market-page-vendorsignup main {
        width: 100% !important;
        max-width: 100% !important;
    }

    body.market-public-view.market-page-vendorsignup .vendor-signup-shell {
        width: calc(100% - 32px) !important;
        max-width: 860px !important;
        margin-left: auto !important;
        margin-right: auto !important;
    }

    @media (min-width: 1150px) {
        body.market-public-view.market-page-vendorsignup .vendor-signup-shell {
            padding-top: 34px !important;
            padding-bottom: 48px !important;
        }
    }

    @media (max-width: 640px) {
        body.market-public-view.market-page-vendorsignup .vendor-signup-shell {
            width: calc(100% - 24px) !important;
        }
    }
</style>

<main class="relative min-h-screen overflow-hidden bg-[#f7f8f3]">

    <div class="pointer-events-none absolute -left-40 top-20 h-96 w-96 rounded-full bg-amber-200/25 blur-3xl"></div>
    <div class="pointer-events-none absolute -right-40 bottom-10 h-96 w-96 rounded-full bg-green-200/25 blur-3xl"></div>

    <section class="vendor-signup-shell relative mx-auto w-full max-w-[860px] px-4 py-8 sm:px-5 lg:py-10">

        <div class="mx-auto w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

            <!-- Compact Form Header -->
            <div class="relative overflow-hidden bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-6 text-white sm:px-7">

                <div class="pointer-events-none absolute -right-12 -top-20 h-60 w-60 rounded-full border-[38px] border-white/10"></div>

                <div class="relative">

                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.22em] text-amber-50">
                            Vendor Registration
                        </p>

                        <h1 class="mt-2 text-2xl font-black tracking-tight">
                            Create Vendor Account
                        </h1>

                        <p class="mt-2 max-w-2xl text-xs leading-5 text-white/80 sm:text-sm">
                            Submit your business information. Your Vendor account will remain pending until Admin approval.
                        </p>
                    </div>

                </div>
            </div>

            <div class="p-5 sm:p-6 lg:p-7">

                <?php if ($errorMessage !== ''): ?>

                    <div class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">

                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-red-100">
                            <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                        </span>

                        <div>
                            <p class="text-sm font-bold">
                                Registration failed
                            </p>

                            <p class="mt-1 text-xs leading-5">
                                <?php echo register_e($errorMessage); ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post"
                      action="vendorsignup.php">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?php echo register_e($csrfToken); ?>">

                    <!-- Account Information -->
                    <section>

                        <div class="flex items-center gap-3 border-b border-slate-100 pb-4">

                            <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-100 text-blue-600">
                                <i class="fa-regular fa-user"></i>
                            </span>

                            <div>
                                <h2 class="text-sm font-black text-slate-900">
                                    Account Information
                                </h2>

                                <p class="mt-1 text-[10px] text-slate-400">
                                    Enter the Vendor owner account details.
                                </p>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-5 sm:grid-cols-2">

                            <div>
                                <label for="userName"
                                       class="mb-2 block text-xs font-bold text-slate-700">
                                    Owner Full Name
                                    <span class="text-red-500">*</span>
                                </label>

                                <div class="relative">

                                    <i class="fa-regular fa-user pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>

                                    <input id="userName"
                                           type="text"
                                           name="user_name"
                                           maxlength="100"
                                           required
                                           autocomplete="name"
                                           value="<?php echo register_e($form['user_name']); ?>"
                                           placeholder="Enter owner name"
                                           class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm outline-none transition focus:border-amber-500 focus:bg-white focus:ring-2 focus:ring-amber-100">
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
                                           type="tel"
                                           name="phone_number"
                                           minlength="9"
                                           maxlength="11"
                                           pattern="09[0-9]{7,9}"
                                           inputmode="numeric"
                                           required
                                           autocomplete="tel"
                                           value="<?php echo register_e($form['phone_number']); ?>"
                                           placeholder="09xxxxxxxxx"
                                           class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm outline-none transition focus:border-amber-500 focus:bg-white focus:ring-2 focus:ring-amber-100">
                                </div>
                            </div>

                            <div class="sm:col-span-2">

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
                                           value="<?php echo register_e($form['email']); ?>"
                                           placeholder="business@example.com"
                                           class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm outline-none transition focus:border-amber-500 focus:bg-white focus:ring-2 focus:ring-amber-100">
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Business Information -->
                    <section class="mt-7 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 sm:p-6">

                        <div class="flex items-center gap-3 border-b border-amber-200 pb-4">

                            <span class="grid h-10 w-10 place-items-center rounded-xl bg-amber-500 text-white">
                                <i class="fa-solid fa-store"></i>
                            </span>

                            <div>
                                <h2 class="text-sm font-black text-amber-950">
                                    Business Information
                                </h2>

                                <p class="mt-1 text-[10px] text-amber-700/70">
                                    Tell us about your business.
                                </p>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-5 sm:grid-cols-2">

                            <div>
                                <label for="vendorName"
                                       class="mb-2 block text-xs font-bold text-slate-700">
                                    Vendor Name
                                    <span class="text-red-500">*</span>
                                </label>

                                <div class="relative">

                                    <i class="fa-solid fa-store pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-amber-500"></i>

                                    <input id="vendorName"
                                           type="text"
                                           name="vendor_name"
                                           maxlength="100"
                                           required
                                           value="<?php echo register_e($form['vendor_name']); ?>"
                                           placeholder="Example: Green Valley Farm"
                                           class="w-full rounded-xl border border-amber-200 bg-white py-3 pl-11 pr-4 text-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100">
                                </div>
                            </div>

                            <div>
                                <label for="vendorAddress"
                                       class="mb-2 block text-xs font-bold text-slate-700">
                                    Vendor Address
                                    <span class="text-red-500">*</span>
                                </label>

                                <div class="relative">

                                    <i class="fa-solid fa-location-dot pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-amber-500"></i>

                                    <input id="vendorAddress"
                                           type="text"
                                           name="address"
                                           maxlength="255"
                                           required
                                           value="<?php echo register_e($form['address']); ?>"
                                           placeholder="Township, city or full address"
                                           class="w-full rounded-xl border border-amber-200 bg-white py-3 pl-11 pr-4 text-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100">
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Password -->
                    <section class="mt-6">

                        <div class="flex items-center gap-3 border-b border-slate-100 pb-4">

                            <span class="grid h-10 w-10 place-items-center rounded-xl bg-violet-100 text-violet-600">
                                <i class="fa-solid fa-lock"></i>
                            </span>

                            <div>
                                <h2 class="text-sm font-black text-slate-900">
                                    Security
                                </h2>

                            </div>
                        </div>

                        <div class="mt-5 grid gap-5 sm:grid-cols-2">

                            <div>
                                <label for="password"
                                       class="mb-2 block text-xs font-bold text-slate-700">
                                    Password
                                    <span class="text-red-500">*</span>
                                </label>

                                <div class="relative">

                                    <input id="password"
                                           type="password"
                                           name="password"
                                           minlength="8"
                                           pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
                                           title="At least 8 characters with uppercase, lowercase, number and special character."
                                           required
                                           autocomplete="new-password"
                                           placeholder="Strong password"
                                           class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 pr-12 text-sm outline-none transition focus:border-amber-500 focus:bg-white focus:ring-2 focus:ring-amber-100">

                                    <button type="button"
                                            data-password-toggle="password"
                                            aria-label="Show password"
                                            class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">

                                        <i class="fa-regular fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label for="confirmPassword"
                                       class="mb-2 block text-xs font-bold text-slate-700">
                                    Confirm Password
                                    <span class="text-red-500">*</span>
                                </label>

                                <div class="relative">

                                    <input id="confirmPassword"
                                           type="password"
                                           name="confirm_password"
                                           minlength="8"
                                           required
                                           autocomplete="new-password"
                                           placeholder="Repeat password"
                                           class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 pr-12 text-sm outline-none transition focus:border-amber-500 focus:bg-white focus:ring-2 focus:ring-amber-100">

                                    <button type="button"
                                            data-password-toggle="confirmPassword"
                                            aria-label="Show confirmation password"
                                            class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700">

                                        <i class="fa-regular fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Submit -->
                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">

                        <p class="text-center text-sm text-slate-500 sm:text-left">
                            Already have an account?

                            <a href="signin.php"
                               class="font-black text-amber-700 hover:text-amber-800">
                                Sign In
                            </a>
                        </p>

                        <button type="submit"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-500 px-7 py-3.5 text-sm font-black text-white shadow-lg shadow-amber-900/15 transition hover:bg-amber-600">

                            Submit Vendor Application

                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('[data-password-toggle]');

        for (var index = 0; index < buttons.length; index++) {
            buttons[index].addEventListener('click', function () {
                var inputId = this.getAttribute('data-password-toggle');
                var input = document.getElementById(inputId);

                if (!input) {
                    return;
                }

                var visible = input.type === 'text';

                input.type = visible
                    ? 'password'
                    : 'text';

                this.innerHTML = visible
                    ? '<i class="fa-regular fa-eye"></i>'
                    : '<i class="fa-regular fa-eye-slash"></i>';
            });
        }

        var phoneInput = document.getElementById('phoneNumber');
        if (phoneInput) {
            phoneInput.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 11);
            });
        }

        var passwordInput = document.getElementById('password');
        var confirmInput = document.getElementById('confirmPassword');

        function validatePasswordMatch() {
            if (!passwordInput || !confirmInput) {
                return;
            }

            if (
                confirmInput.value !== '' &&
                passwordInput.value !== confirmInput.value
            ) {
                confirmInput.setCustomValidity('Passwords do not match.');
            } else {
                confirmInput.setCustomValidity('');
            }
        }

        if (passwordInput && confirmInput) {
            passwordInput.addEventListener('input', validatePasswordMatch);
            confirmInput.addEventListener('input', validatePasswordMatch);
        }
    });
</script>

<?php require __DIR__ . '/footer.php'; ?>