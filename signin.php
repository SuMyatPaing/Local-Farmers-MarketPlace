<?php
require_once __DIR__ . '/security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/auth.php';

if (fm_is_logged_in()) {
    $loggedInRole = isset($_SESSION['role'])
        ? strtolower((string) $_SESSION['role'])
        : '';

    if ($loggedInRole === 'user') {
        fm_redirect(fm_url('index.php'));
    }

    fm_redirect(fm_dashboard_url());
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

$errorMessage = '';
$email = '';
$notice = isset($_GET['notice'])
    ? strtolower(trim((string) $_GET['notice']))
    : '';

$nextUrl = '';
if (isset($_GET['next'])) {
    $nextUrl = fm_safe_next_url((string) $_GET['next']);
}
if (isset($_POST['next'])) {
    $postedNext = fm_safe_next_url((string) $_POST['next']);
    if ($postedNext !== '') {
        $nextUrl = $postedNext;
    }
}

/*
|--------------------------------------------------------------------------
| Login throttling
|--------------------------------------------------------------------------
| Five failed attempts from the same email + IP within 15 minutes are
| temporarily blocked. Successful login clears those failures.
*/
$clientIp = isset($_SERVER['REMOTE_ADDR'])
    ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45)
    : 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email'])
        ? strtolower(trim((string) $_POST['email']))
        : '';

    $password = isset($_POST['password'])
        ? (string) $_POST['password']
        : '';

    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!fm_verify_csrf($csrfToken)) {
        $errorMessage = 'Your form session expired. Refresh the page and try again.';
    } elseif (strlen($email) > 100 || strlen($password) > 255) {
        $errorMessage = 'The Sign In details are too long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Enter a valid email address.';
    } elseif ($password === '') {
        $errorMessage = 'Enter your password.';
    } else {
        try {
            /* Remove stale login-attempt rows during normal traffic. */
            $pdo->exec(
                "DELETE FROM login_attempts
                 WHERE attempted_at < (NOW() - INTERVAL 1 DAY)"
            );

            $attemptStatement = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM login_attempts
                 WHERE email = :email
                   AND ip_address = :ip_address
                   AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)"
            );
            $attemptStatement->execute(array(
                'email' => $email,
                'ip_address' => $clientIp
            ));

            if ((int) $attemptStatement->fetchColumn() >= 5) {
                $errorMessage =
                    'Too many failed Sign In attempts. Please wait 15 minutes and try again.';
            } else {
                $statement = $pdo->prepare(
                    "SELECT
                        user_id,
                        user_name,
                        phone_number,
                        email,
                        user_password,
                        role,
                        status,
                        must_change_password
                     FROM users
                     WHERE email = :email
                     LIMIT 1"
                );
                $statement->execute(array('email' => $email));
                $user = $statement->fetch(PDO::FETCH_ASSOC);

                if (
                    !$user ||
                    !password_verify($password, (string) $user['user_password'])
                ) {
                    $recordAttempt = $pdo->prepare(
                        "INSERT INTO login_attempts (email, ip_address)
                         VALUES (:email, :ip_address)"
                    );
                    $recordAttempt->execute(array(
                        'email' => $email,
                        'ip_address' => $clientIp
                    ));

                    $errorMessage = 'The email address or password is incorrect.';
                } elseif (strtolower((string) $user['status']) !== 'active') {
                    $errorMessage = 'This account is suspended.';
                } else {
                    $vendor = null;
                    $role = strtolower((string) $user['role']);

                    if ($role === 'vendor') {
                        $vendorStatement = $pdo->prepare(
                            "SELECT
                                vendor_id,
                                vendor_name,
                                address,
                                status,
                                rejection_reason
                             FROM vendors
                             WHERE user_id = :user_id
                             LIMIT 1"
                        );
                        $vendorStatement->execute(array(
                            'user_id' => (int) $user['user_id']
                        ));
                        $vendor = $vendorStatement->fetch(PDO::FETCH_ASSOC);

                        if (!$vendor) {
                            $errorMessage =
                                'The Vendor profile connected to this account was not found.';
                        }
                    }

                    if ($errorMessage === '') {
                        $clearAttempts = $pdo->prepare(
                            "DELETE FROM login_attempts
                             WHERE email = :email
                               AND ip_address = :ip_address"
                        );
                        $clearAttempts->execute(array(
                            'email' => $email,
                            'ip_address' => $clientIp
                        ));

                        fm_login_user($user, $vendor);

                        $updateStatement = $pdo->prepare(
                            "UPDATE users
                             SET last_login = CURRENT_TIMESTAMP
                             WHERE user_id = :user_id"
                        );
                        $updateStatement->execute(array(
                            'user_id' => (int) $user['user_id']
                        ));

                        if (
                            $role === 'admin' &&
                            !empty($user['must_change_password'])
                        ) {
                            fm_redirect(
                                fm_url('admin/profile.php?notice=password_required')
                            );
                        }

                        if ($nextUrl !== '') {
                            fm_redirect($nextUrl);
                        }

                        if ($role === 'user') {
                            fm_redirect(fm_url('index.php'));
                        }

                        fm_redirect(fm_url(fm_role_home_path($role)));
                    }
                }
            }
        } catch (PDOException $exception) {
            error_log('Sign In database error: ' . $exception->getMessage());
            $errorMessage = 'The database could not complete the Sign In request.';
        }
    }
}

$csrfToken = fm_csrf_token();
$pageTitle = 'Sign In | Local Farmers Marketplace';

require __DIR__ . '/header.php';
?>

<main class="relative min-h-screen overflow-hidden bg-[#f7f8f3]">
<style>
    /* Hide Microsoft Edge / browser default password reveal icon */
    #password::-ms-reveal,
    #password::-ms-clear {
        display: none;
    }

    /* Sign In page only — compact centered card */
    body.market-public-view.market-page-signin main > section {
        width: calc(100% - 32px) !important;
        max-width: 540px !important;
        margin-left: auto !important;
        margin-right: auto !important;
    }

    body.market-public-view.market-page-signin main > section > div {
        width: 100% !important;
        max-width: 540px !important;
    }

    @media (min-width: 1150px) {
        body.market-public-view.market-page-signin main > section {
            padding-top: 44px !important;
            padding-bottom: 56px !important;
        }
    }

    @media (max-width: 640px) {
        body.market-public-view.market-page-signin main > section {
            width: calc(100% - 24px) !important;
        }
    }
</style>

    <div class="pointer-events-none absolute -left-40 top-20 h-96 w-96 rounded-full bg-green-200/30 blur-3xl"></div>

    <section class="relative mx-auto w-full max-w-[540px] px-4 py-10 sm:px-5 lg:py-12">

        <div class="w-full rounded-2xl border border-slate-200 bg-white p-6 shadow-card sm:p-7">

            <div class="text-center">

                <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-green-600 text-white shadow-lg shadow-green-900/15">
                    <i class="fa-solid fa-right-to-bracket text-lg"></i>
                </span>

                <p class="mt-5 text-xs font-black uppercase tracking-[0.22em] text-green-600">
                    Account Access
                </p>

                <h1 class="mt-2 text-2xl font-black text-[#0f2414]">
                    Sign In
                </h1>

                <p class="mt-2 text-sm text-slate-500">
                    Admin, Vendor and User use the same Sign In form.
                </p>
            </div>

            <?php if ($notice === 'registered'): ?>

                <div class="mt-6 rounded-xl border border-green-200 bg-green-50 p-4 text-xs leading-6 text-green-700">
                    <i class="fa-solid fa-circle-check mr-1"></i>
                    Account created successfully. You can now Sign In.
                </div>

            <?php elseif ($notice === 'login_required'): ?>

                <div class="mt-6 rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs leading-6 text-blue-700">
                    <i class="fa-solid fa-lock mr-1"></i>
                    Please Sign In before using marketplace functions.
                </div>

            <?php elseif ($notice === 'account_unavailable'): ?>

                <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-xs leading-6 text-red-700">
                    <i class="fa-solid fa-user-lock mr-1"></i>
                    This account is no longer active. Please contact the administrator.
                </div>

            <?php elseif ($notice === 'role_changed'): ?>

                <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs leading-6 text-amber-700">
                    <i class="fa-solid fa-shield-halved mr-1"></i>
                    Your account permissions changed. Please Sign In again.
                </div>

            <?php elseif ($notice === 'logged_out'): ?>

                <div class="mt-6 rounded-xl border border-green-200 bg-green-50 p-4 text-xs leading-6 text-green-700">
                    <i class="fa-solid fa-circle-check mr-1"></i>
                    You have signed out successfully.
                </div>

            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>

                <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-xs leading-6 text-red-700">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                    <?php echo fm_e($errorMessage); ?>
                </div>

            <?php endif; ?>

            <form method="post"
                  action="signin.php"
                  class="mt-5 space-y-4">

                <input type="hidden"
                       name="csrf_token"
                       value="<?php echo fm_e($csrfToken); ?>">
                    <input type="hidden" name="next" value="<?php echo fm_e($nextUrl); ?>">

                <div>
                    <label for="email"
                           class="mb-2 block text-xs font-bold text-slate-700">
                        Email Address
                    </label>

                    <input id="email"
                           type="email"
                           name="email"
                           required
                           maxlength="100"
                           autocomplete="email"
                           value="<?php echo fm_e($email); ?>"
                           placeholder="name@example.com"
                           class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
                </div>

                <div>
                    <label for="password"
                           class="mb-2 block text-xs font-bold text-slate-700">
                        Password
                    </label>

                    <div class="relative">

                        <input id="password"
                               type="password"
                               name="password"
                               required
                               maxlength="255"
                               autocomplete="current-password"
                               placeholder="Enter your password"
                               class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 pr-12 text-sm outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">

                        <button id="togglePassword"
                                type="button"
                                class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-slate-100">

                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-5 py-3 text-sm font-black text-white hover:bg-green-700">

                    Sign In

                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </button>
            </form>

            <div class="mt-5 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2">

                <a href="usersignup.php"
                   class="rounded-xl bg-green-50 px-4 py-3 text-center text-xs font-bold text-green-700">
                    Create User Account
                </a>

                <a href="vendorsignup.php"
                   class="rounded-xl bg-amber-50 px-4 py-3 text-center text-xs font-bold text-amber-700">
                    Create Vendor Account
                </a>
            </div>
        </div>
    </section>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var button = document.getElementById('togglePassword');
        var input = document.getElementById('password');

        if (button && input) {
            button.addEventListener('click', function () {
                var visible = input.type === 'text';

                input.type = visible ? 'password' : 'text';

                button.innerHTML = visible
                    ? '<i class="fa-regular fa-eye"></i>'
                    : '<i class="fa-regular fa-eye-slash"></i>';
            });
        }
    });
</script>

<?php require __DIR__ . '/footer.php'; ?>