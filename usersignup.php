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
    'email' => ''
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
        $errorMessage = 'Full name is required.';
    } elseif (strlen($form['user_name']) > 100) {
        $errorMessage = 'Full name must not exceed 100 characters.';
    } elseif ($form['phone_number'] === '') {
        $errorMessage = 'Phone number is required.';
    } elseif (!preg_match('/^09\d{7,9}$/', $form['phone_number'])) {
        $errorMessage = 'Enter a valid Myanmar mobile number starting with 09.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Enter a valid email address.';
    } elseif (strlen($form['email']) > 100) {
        $errorMessage = 'Email must not exceed 100 characters.';
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
                $statement = $pdo->prepare(
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
                        'user',
                        'active'
                     )"
                );

                $statement->execute(array(
                    'user_name' => $form['user_name'],
                    'phone_number' => $form['phone_number'],
                    'email' => $form['email'],
                    'user_password' => password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    )
                ));

                $_SESSION['registration_success'] =
                    'User account created successfully. You can now Sign In.';

                register_redirect('signin.php?notice=registered');
            }
        } catch (PDOException $exception) {
            $errorMessage = 'The database could not create your User account.';
        }
    }
}

$csrfToken = register_csrf_token();
$pageTitle = 'User Sign Up | Local Farmers Marketplace';

require __DIR__ . '/header.php';
?>

<style>
    /*
    |--------------------------------------------------------------------------
    | User Sign Up page only
    |--------------------------------------------------------------------------
    | Compact centered form. Shared Admin/Vendor layouts are not affected.
    |--------------------------------------------------------------------------
    */
    html {
        overflow-y: auto;
    }

    body.market-public-view.market-page-usersignup main {
        width: 100% !important;
        max-width: 100% !important;
    }

    body.market-public-view.market-page-usersignup .user-signup-shell {
        width: calc(100% - 32px) !important;
        max-width: 680px !important;
        margin-left: auto !important;
        margin-right: auto !important;
    }

    @media (min-width: 1150px) {
        body.market-public-view.market-page-usersignup .user-signup-shell {
            padding-top: 36px !important;
            padding-bottom: 52px !important;
        }
    }

    @media (max-width: 640px) {
        body.market-public-view.market-page-usersignup .user-signup-shell {
            width: calc(100% - 24px) !important;
        }
    }
</style>

<main class="relative min-h-screen overflow-hidden bg-[#f7f8f3]">

    <div class="pointer-events-none absolute -left-40 top-24 h-96 w-96 rounded-full bg-green-200/30 blur-3xl"></div>
    <div class="pointer-events-none absolute -right-40 bottom-10 h-96 w-96 rounded-full bg-blue-200/25 blur-3xl"></div>

    <section class="user-signup-shell relative mx-auto w-full max-w-[680px] px-4 py-8 sm:px-5 lg:py-10">

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

            <section class="p-5 sm:p-7">

                <div class="mx-auto max-w-3xl text-center">

                    <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-green-100 text-green-600">
                        <i class="fa-solid fa-user-plus text-lg"></i>
                    </span>

                    <p class="mt-5 text-xs font-black uppercase tracking-[0.22em] text-green-600">
                        User Sign Up
                    </p>

                    <h2 class="mt-2 text-2xl font-black tracking-tight text-[#0f2414]">
                        Create customer account
                    </h2>

                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        Your new User account will be active immediately.
                    </p>
                </div>

                <?php if ($errorMessage !== ''): ?>

                    <div class="mx-auto mt-5 flex max-w-3xl gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">

                        <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>

                        <p class="text-xs leading-5">
                            <?php echo register_e($errorMessage); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <form method="post"
                      action="usersignup.php"
                      class="mx-auto mt-5 max-w-[600px] space-y-4">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?php echo register_e($csrfToken); ?>">

                    <div>
                        <label for="userName"
                               class="mb-2 block text-xs font-bold text-slate-700">
                            Full Name
                            <span class="text-red-500">*</span>
                        </label>

                        <div class="relative">
                            <i class="fa-regular fa-user pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>

                            <input id="userName"
                                   type="text"
                                   name="user_name"
                                   maxlength="100"
                                   required
                                   autocomplete="name"
                                   value="<?php echo register_e($form['user_name']); ?>"
                                   placeholder="Enter your full name"
                                   class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        </div>
                    </div>

                    <div>
                        <label for="phoneNumber"
                               class="mb-2 block text-xs font-bold text-slate-700">
                            Phone Number
                            <span class="text-red-500">*</span>
                        </label>

                        <div class="relative">
                            <i class="fa-solid fa-phone pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>

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
                                   title="Enter a Myanmar mobile number starting with 09 (9 to 11 digits)."
                                   class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        </div>
                        <p class="mt-1.5 text-[11px] text-slate-400">
                            Myanmar mobile format: starts with 09 and contains digits only.
                        </p>
                    </div>

                    <div>
                        <label for="email"
                               class="mb-2 block text-xs font-bold text-slate-700">
                            Email Address
                            <span class="text-red-500">*</span>
                        </label>

                        <div class="relative">
                            <i class="fa-regular fa-envelope pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>

                            <input id="email"
                                   type="email"
                                   name="email"
                                   maxlength="100"
                                   required
                                   autocomplete="email"
                                   value="<?php echo register_e($form['email']); ?>"
                                   placeholder="name@example.com"
                                   class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">

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
                                       required
                                       autocomplete="new-password"
                                       pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
                                       title="At least 8 characters with uppercase, lowercase, number and special character."
                                       placeholder="Strong password"
                                       class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 pr-12 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                                <button type="button"
                                        data-password-toggle="password"
                                        class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                            </div>
                            <p class="mt-1.5 text-[11px] leading-4 text-slate-400">
                                Use 8+ characters with uppercase, lowercase, number and special character.
                            </p>
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
                                       class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 pr-12 text-sm outline-none transition focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                                <button type="button"
                                        data-password-toggle="confirmPassword"
                                        class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <button type="submit"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-5 py-3 text-sm font-black text-white shadow-lg shadow-green-900/15 transition hover:bg-green-700">

                        Create User Account

                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                </form>

                <p class="mt-5 text-center text-sm text-slate-500">
                    Already have an account?

                    <a href="signin.php"
                       class="font-black text-green-700 hover:text-green-800">
                        Sign In
                    </a>
                </p>
            </section>
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
                input.type = visible ? 'password' : 'text';

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