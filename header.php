<?php
require_once __DIR__ . '/security.php';

/*
|--------------------------------------------------------------------------
| Shared Marketplace Header
|--------------------------------------------------------------------------
| Theme: Full-width #00A63E green header + white text.
| Header uses internal padding only; no outer margin and no rounded corners.
| PHP 7.1 compatible.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/auth.php';

$pageTitle = isset($pageTitle) && trim((string) $pageTitle) !== ''
    ? (string) $pageTitle
    : 'Local Farmers Marketplace';

$loggedInUser = fm_current_user();
$isLoggedIn = $loggedInUser !== null;

$userRole = $isLoggedIn
    ? strtolower((string) $loggedInUser['role'])
    : '';

$userName = $isLoggedIn
    ? (string) $loggedInUser['user_name']
    : '';

$userEmail = $isLoggedIn
    ? (string) $loggedInUser['email']
    : '';

$headerProfileImage = '';

if ($isLoggedIn && $userRole === 'user') {
    /*
    |--------------------------------------------------------------------------
    | User profile image
    |--------------------------------------------------------------------------
    | First use the session/current-user value. If an older login session does
    | not contain the image path, read it directly from users.profile_image.
    |--------------------------------------------------------------------------
    */

    if (
        isset($_SESSION['user_profile_image']) &&
        trim((string) $_SESSION['user_profile_image']) !== ''
    ) {
        $headerProfileImage = trim(
            (string) $_SESSION['user_profile_image']
        );
    } elseif (
        isset($loggedInUser['profile_image']) &&
        trim((string) $loggedInUser['profile_image']) !== ''
    ) {
        $headerProfileImage = trim(
            (string) $loggedInUser['profile_image']
        );
    }

    if (
        $headerProfileImage === '' &&
        isset($loggedInUser['user_id']) &&
        (int) $loggedInUser['user_id'] > 0
    ) {
        try {
            $headerPdo = (
                isset($pdo) &&
                $pdo instanceof PDO
            )
                ? $pdo
                : null;

            if (!($headerPdo instanceof PDO)) {
                $headerDatabaseFile = __DIR__ . '/config/database.php';

                if (file_exists($headerDatabaseFile)) {
                    require_once $headerDatabaseFile;
                }

                if (isset($pdo) && $pdo instanceof PDO) {
                    $headerPdo = $pdo;
                } elseif (function_exists('getPDO')) {
                    $headerPdo = getPDO();
                }
            }

            if ($headerPdo instanceof PDO) {
                $headerProfileStatement = $headerPdo->prepare(
                    "SELECT profile_image
                     FROM users
                     WHERE user_id = :user_id
                       AND role = 'user'
                     LIMIT 1"
                );

                $headerProfileStatement->execute(array(
                    'user_id' => (int) $loggedInUser['user_id']
                ));

                $databaseProfileImage =
                    $headerProfileStatement->fetchColumn();

                if (
                    $databaseProfileImage !== false &&
                    trim((string) $databaseProfileImage) !== ''
                ) {
                    $headerProfileImage = trim(
                        (string) $databaseProfileImage
                    );

                    $_SESSION['user_profile_image'] =
                        $headerProfileImage;
                }
            }
        } catch (PDOException $exception) {
            error_log(
                'Header profile image lookup error: ' .
                $exception->getMessage()
            );
        }
    }
}

$headerProfileImageUrl = '';

if ($headerProfileImage !== '') {
    if (
        preg_match('/^https?:\/\//i', $headerProfileImage) ||
        strpos($headerProfileImage, 'data:') === 0
    ) {
        $headerProfileImageUrl = $headerProfileImage;
    } else {
        $headerProfileImageUrl = fm_url(
            ltrim(
                str_replace('\\', '/', $headerProfileImage),
                '/'
            )
        );
    }
}

$profileInitial = 'U';

if ($userName !== '') {
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $profileInitial = mb_strtoupper(
            mb_substr($userName, 0, 1, 'UTF-8'),
            'UTF-8'
        );
    } else {
        $profileInitial = strtoupper(substr($userName, 0, 1));
    }
}

$currentPage = basename(
    isset($_SERVER['PHP_SELF'])
        ? (string) $_SERVER['PHP_SELF']
        : 'index.php'
);

$homeUrl = fm_url('index.php');
$marketsUrl = fm_url('markets.php');
$productsUrl = fm_url('products.php');
$eventsUrl = fm_url('events.php');
$aboutUrl = fm_url('about.php');
$contactUrl = fm_url('contact.php');
$cartUrl = fm_url('cart.php');
$ordersUrl = fm_url('my_orders.php');
$profileUrl = fm_url('profile.php');
$logoutUrl = fm_url('logout.php');
$signinUrl = fm_url('signin.php');
$userSignupUrl = fm_url('usersignup.php');
$vendorSignupUrl = fm_url('vendorsignup.php');
$dashboardUrl = fm_dashboard_url();

$headerCartCount = 0;

if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $headerCart = $_SESSION['cart'];

    if (isset($headerCart['items']) && is_array($headerCart['items'])) {
        $headerCart = $headerCart['items'];
    }

    foreach ($headerCart as $productId => $quantity) {
        if (is_numeric($productId) && is_numeric($quantity)) {
            $headerCartCount += max(0, (int) $quantity);
        }
    }
}

if (!function_exists('marketplace_nav_class')) {
    function marketplace_nav_class($page, $currentPage)
    {
        if ($page === $currentPage) {
            return 'is-active';
        }

        return '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo fm_e($pageTitle); ?></title>

    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="assets/css/responsive.css?v=20260813-responsive-v4">
    <script src="assets/js/responsive.js?v=20260813-v4" defer></script>
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">

    

    <style>
        /*
        |--------------------------------------------------------------------------
        | Shared Marketplace Header
        |--------------------------------------------------------------------------
        | Reference green: #00A63E
        |--------------------------------------------------------------------------
        */

        .market-header-shell {
            width: 100%;
            background: #00A63E;
            border-color: #07933A;
            border-radius: 0;

            /* Fixed is more reliable than sticky because shared responsive
               CSS uses overflow rules on html/body. */
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 120 !important;
        }

        /* Reserve the header's space so page content does not move under it. */
        body.market-public-view {
            padding-top: 72px !important;
        }

        body.market-public-view.fm-compact-nav {
            padding-top: 58px !important;
        }

        .market-brand-icon {
            background: rgba(255, 255, 255, 0.16);
        }

        .market-nav-link {
            color: rgba(255, 255, 255, 0.92);
        }

        .market-nav-link:hover {
            background: rgba(255, 255, 255, 0.13);
            color: #ffffff;
        }

        .market-nav-link.is-active {
            background: rgba(255, 255, 255, 0.20);
            color: #ffffff;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.22);
        }

        .market-header-action {
            color: #ffffff;
        }

        .market-header-action:hover {
            background: rgba(255, 255, 255, 0.13);
        }

        .market-signup-button {
            background: rgba(0, 84, 31, 0.34);
            color: #ffffff;
        }

        .market-signup-button:hover {
            background: rgba(0, 72, 27, 0.48);
        }

        .market-mobile-menu {
            background: #00A63E;
            border-color: rgba(255, 255, 255, 0.18);
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile / 175% hamburger drawer readability fix
        |--------------------------------------------------------------------------
        | Keep these selectors local to the public/user menu so Admin/Vendor
        | responsive styles are not affected.
        |--------------------------------------------------------------------------
        */

        #marketplaceMobileMenu {
            background: #064e2b !important;
            color: #ffffff !important;
        }

        #marketplaceMobileMenu nav,
        #marketplaceMobileMenu nav a,
        #marketplaceMobileMenu .market-nav-link {
            color: rgba(255, 255, 255, 0.94) !important;
        }

        #marketplaceMobileMenu nav a:hover,
        #marketplaceMobileMenu .market-nav-link:hover {
            background: rgba(255, 255, 255, 0.12) !important;
            color: #ffffff !important;
        }

        #marketplaceMobileMenu nav a.is-active,
        #marketplaceMobileMenu .market-nav-link.is-active {
            background: rgba(255, 255, 255, 0.18) !important;
            color: #ffffff !important;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.18) !important;
        }

        #marketplaceMobileMenu p,
        #marketplaceMobileMenu span:not(.bg-red-500),
        #marketplaceMobileMenu i {
            color: inherit;
        }

        #marketplaceMobileMenu .text-white,
        #marketplaceMobileMenu .text-white\/70,
        #marketplaceMobileMenu .text-white\/75,
        #marketplaceMobileMenu .text-white\/80 {
            color: #ffffff !important;
        }

        #marketplaceMobileMenu .market-signup-button {
            background: rgba(255, 255, 255, 0.14) !important;
            color: #ffffff !important;
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        #marketplaceMobileMenu .market-signup-button:hover {
            background: rgba(255, 255, 255, 0.22) !important;
            color: #ffffff !important;
        }

        #marketplaceMobileOverlay {
            background: rgba(15, 23, 42, 0.50) !important;
        }

        body.fm-compact-nav #marketplaceMobileMenu {
            background: #064e2b !important;
            color: #ffffff !important;
        }
    

        /* Final public drawer stacking fallback:
           overlay is a sibling AFTER header, so header must sit above it. */
        body.market-public-view.fm-compact-nav .market-header-shell {
            z-index: 110 !important;
        }

        body.market-public-view.fm-compact-nav #marketplaceMobileMenu {
            z-index: 130 !important;
            background: #064e2b !important;
            color: #ffffff !important;
            visibility: visible !important;
            opacity: 1 !important;
        }

        body.market-public-view.fm-compact-nav #marketplaceMobileMenu nav a {
            color: #ffffff !important;
            visibility: visible !important;
            opacity: 1 !important;
        }

        body.market-public-view.fm-compact-nav #marketplaceMobileOverlay {
            z-index: 100 !important;
        }

    </style>

</head>

<?php
$marketBodyRole = $isLoggedIn ? $userRole : 'guest';
$marketBodyPage = preg_replace('/[^a-z0-9_-]/i', '-', pathinfo($currentPage, PATHINFO_FILENAME));
?>
<body class="market-public-view market-role-<?php echo fm_e($marketBodyRole); ?> market-page-<?php echo fm_e($marketBodyPage); ?> min-h-screen bg-[#f6faf5] font-sans text-slate-800 antialiased">

<header class="market-header-shell w-full border-b shadow-sm">
    <div class="market-header-inner mx-auto flex min-h-[72px] max-w-7xl items-center justify-between gap-5 px-4 py-3 sm:px-6 lg:px-8">

        <a href="<?php echo fm_e($homeUrl); ?>" class="flex shrink-0 items-center gap-3">
            <span class="market-brand-icon grid h-10 w-10 place-items-center rounded-xl text-white shadow-sm">
                <i class="fa-solid fa-seedling"></i>
            </span>

            <span class="market-brand-copy hidden sm:block">
                <span class="block text-sm font-black tracking-tight text-white">
                    Farmers Market
                </span>
                <span class="block text-[9px] font-bold uppercase tracking-[0.2em] text-white/80">
                    Local Marketplace
                </span>
            </span>
        </a>

        <nav class="market-desktop-nav hidden items-center gap-0.5 lg:flex">
            <a href="<?php echo fm_e($homeUrl); ?>"
               class="market-nav-link rounded-lg px-2.5 py-2 text-sm font-semibold transition <?php echo marketplace_nav_class('index.php', $currentPage); ?>">
                Home
            </a>

            <a href="<?php echo fm_e($marketsUrl); ?>"
               class="market-nav-link rounded-lg px-2.5 py-2 text-sm font-semibold transition <?php echo marketplace_nav_class('markets.php', $currentPage); ?>">
                Markets
            </a>

            <a href="<?php echo fm_e($productsUrl); ?>"
               class="market-nav-link rounded-lg px-2.5 py-2 text-sm font-semibold transition <?php echo marketplace_nav_class('products.php', $currentPage); ?>">
                Products
            </a>

            <a href="<?php echo fm_e($eventsUrl); ?>"
               class="market-nav-link rounded-lg px-2.5 py-2 text-sm font-semibold transition <?php echo marketplace_nav_class('events.php', $currentPage); ?>">
                Events
            </a>

            <a href="<?php echo fm_e($aboutUrl); ?>"
               class="market-nav-link rounded-lg px-2.5 py-2 text-sm font-semibold transition <?php echo marketplace_nav_class('about.php', $currentPage); ?>">
                About Us
            </a>

            <a href="<?php echo fm_e($contactUrl); ?>"
               class="market-nav-link rounded-lg px-2.5 py-2 text-sm font-semibold transition <?php echo marketplace_nav_class('contact.php', $currentPage); ?>">
                Contact Us
            </a>
        </nav>

        <div class="market-desktop-actions hidden items-center gap-2 lg:flex">
            <?php if (!$isLoggedIn): ?>

                <a href="<?php echo fm_e($signinUrl); ?>"
                   class="market-header-action rounded-lg px-4 py-2.5 text-sm font-black transition">
                    Sign In
                </a>

                <div id="signupDropdown" class="relative">
                    <button id="signupDropdownButton"
                            type="button"
                            class="market-signup-button inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-black shadow-sm transition">
                        Sign Up
                        <i class="fa-solid fa-chevron-down text-[9px]"></i>
                    </button>

                    <div id="signupDropdownMenu"
                         class="absolute right-0 mt-3 hidden w-56 overflow-hidden rounded-xl border border-slate-200 bg-white p-2 text-slate-700 shadow-xl">
                        <a href="<?php echo fm_e($userSignupUrl); ?>"
                           class="flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-semibold hover:bg-green-50 hover:text-green-700">
                            <i class="fa-solid fa-user w-5 text-center"></i>
                            User Account
                        </a>

                        <a href="<?php echo fm_e($vendorSignupUrl); ?>"
                           class="mt-1 flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-semibold hover:bg-green-50 hover:text-green-700">
                            <i class="fa-solid fa-store w-5 text-center"></i>
                            Vendor Account
                        </a>
                    </div>
                </div>

            <?php else: ?>

                <?php if ($userRole === 'user'): ?>
                    <a href="<?php echo fm_e($cartUrl); ?>"
                       title="Shopping Cart"
                       class="market-header-action relative grid h-10 w-10 place-items-center rounded-lg transition">
                        <i class="fa-solid fa-cart-shopping"></i>

                        <?php if ($headerCartCount > 0): ?>
                            <span class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-red-500 px-1 text-[9px] font-black text-white">
                                <?php echo number_format($headerCartCount); ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <div id="marketplaceAccountMenu" class="relative">
                    <button id="marketplaceAccountButton"
                            type="button"
                            aria-expanded="false"
                            class="market-header-action flex items-center gap-2 rounded-lg px-2 py-1.5 transition">
                        <span class="market-brand-icon grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-full text-white shadow-sm"
                              title="Profile">
                            <?php if ($headerProfileImageUrl !== ''): ?>
                                <img src="<?php echo fm_e($headerProfileImageUrl); ?>"
                                     alt="<?php echo fm_e($userName); ?>"
                                     class="h-full w-full object-cover">
                            <?php else: ?>
                                <i class="fa-solid fa-camera text-xs"></i>
                            <?php endif; ?>
                        </span>

                        <span class="hidden min-w-0 text-left xl:block">
                            <span class="block max-w-32 truncate text-xs font-black text-white">
                                <?php echo fm_e($userName); ?>
                            </span>
                            <span class="block text-[9px] capitalize text-white/75">
                                <?php echo fm_e($userRole); ?>
                            </span>
                        </span>

                        <i class="fa-solid fa-chevron-down text-[9px] text-white/75"></i>
                    </button>

                    <div id="marketplaceAccountDropdown"
                         class="absolute right-0 mt-3 hidden w-60 overflow-hidden rounded-xl border border-slate-200 bg-white text-slate-700 shadow-xl">
                        <div class="border-b border-slate-100 px-4 py-4">
                            <p class="truncate text-sm font-black text-slate-900">
                                <?php echo fm_e($userName); ?>
                            </p>
                            <p class="mt-1 truncate text-xs text-slate-400">
                                <?php echo fm_e($userEmail); ?>
                            </p>
                        </div>

                        <div class="p-2">
                            <a href="<?php echo fm_e($dashboardUrl); ?>"
                               class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold hover:bg-green-50 hover:text-green-700">
                                <i class="fa-solid fa-gauge-high w-5 text-center"></i>
                                Dashboard
                            </a>

                            <?php if ($userRole === 'user'): ?>
                                <a href="<?php echo fm_e($ordersUrl); ?>"
                                   class="mt-1 flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold hover:bg-green-50 hover:text-green-700">
                                    <i class="fa-solid fa-receipt w-5 text-center"></i>
                                    My Orders
                                </a>

                                <a href="<?php echo fm_e($profileUrl); ?>"
                                   class="mt-1 flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold hover:bg-green-50 hover:text-green-700">
                                    <i class="fa-solid fa-user-pen w-5 text-center"></i>
                                    My Profile
                                </a>
                            <?php endif; ?>

                            <a href="<?php echo fm_e($logoutUrl); ?>"
                               class="mt-1 flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold text-red-600 hover:bg-red-50">
                                <i class="fa-solid fa-right-from-bracket w-5 text-center"></i>
                                Sign Out
                            </a>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </div>

        <button id="marketplaceMobileMenuButton"
                type="button"
                aria-label="Open navigation"
                aria-controls="marketplaceMobileMenu"
                aria-expanded="false"
                class="market-header-action grid h-10 w-10 place-items-center rounded-lg transition lg:hidden">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>

    <div id="marketplaceMobileMenu"
         class="market-mobile-menu hidden border-t px-4 py-4 lg:hidden"
         aria-hidden="true">
        <div class="mb-4 flex items-center justify-between border-b border-white/20 pb-4">
            <div class="flex items-center gap-3 text-white">
                <span class="market-brand-icon grid h-9 w-9 place-items-center rounded-xl">
                    <i class="fa-solid fa-seedling"></i>
                </span>
                <div>
                    <p class="text-sm font-black">Farmers Market</p>
                    <p class="text-[9px] uppercase tracking-[0.18em] text-white/70">Navigation</p>
                </div>
            </div>
            <button id="marketplaceMobileMenuClose"
                    type="button"
                    aria-label="Close navigation"
                    class="grid h-9 w-9 place-items-center rounded-lg bg-white/10 text-white transition hover:bg-white/20">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <nav class="space-y-1">
            <a href="<?php echo fm_e($homeUrl); ?>"
               class="market-nav-link block rounded-lg px-4 py-3 text-sm font-semibold text-white <?php echo marketplace_nav_class('index.php', $currentPage); ?>">
                Home
            </a>
            <a href="<?php echo fm_e($marketsUrl); ?>"
               class="market-nav-link block rounded-lg px-4 py-3 text-sm font-semibold text-white <?php echo marketplace_nav_class('markets.php', $currentPage); ?>">
                Markets
            </a>
            <a href="<?php echo fm_e($productsUrl); ?>"
               class="market-nav-link block rounded-lg px-4 py-3 text-sm font-semibold text-white <?php echo marketplace_nav_class('products.php', $currentPage); ?>">
                Products
            </a>
            <a href="<?php echo fm_e($eventsUrl); ?>"
               class="market-nav-link block rounded-lg px-4 py-3 text-sm font-semibold text-white <?php echo marketplace_nav_class('events.php', $currentPage); ?>">
                Events
            </a>

            <a href="<?php echo fm_e($aboutUrl); ?>"
               class="market-nav-link block rounded-lg px-4 py-3 text-sm font-semibold text-white <?php echo marketplace_nav_class('about.php', $currentPage); ?>">
                About Us
            </a>

            <a href="<?php echo fm_e($contactUrl); ?>"
               class="market-nav-link block rounded-lg px-4 py-3 text-sm font-semibold text-white <?php echo marketplace_nav_class('contact.php', $currentPage); ?>">
                Contact Us
            </a>
            

            <?php if ($isLoggedIn && $userRole === 'user'): ?>
                <a href="<?php echo fm_e($cartUrl); ?>"
                   class="market-nav-link flex items-center justify-between rounded-lg px-4 py-3 text-sm font-semibold text-white">
                    Shopping Cart
                    <?php if ($headerCartCount > 0): ?>
                        <span class="rounded-full bg-red-500 px-2 py-0.5 text-[9px] font-black text-white">
                            <?php echo number_format($headerCartCount); ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        </nav>

        <?php if ($isLoggedIn): ?>
            <div class="mt-4 border-t border-white/20 pt-4">
                <div class="flex items-center gap-3 rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
                    <span class="market-brand-icon grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full text-white shadow-sm"
                          title="Profile">
                        <?php if ($headerProfileImageUrl !== ''): ?>
                            <img src="<?php echo fm_e($headerProfileImageUrl); ?>"
                                 alt="<?php echo fm_e($userName); ?>"
                                 class="h-full w-full object-cover">
                        <?php else: ?>
                            <i class="fa-solid fa-camera text-sm"></i>
                        <?php endif; ?>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-black text-white">
                            <?php echo fm_e($userName); ?>
                        </p>
                        <p class="truncate text-[10px] text-white/70">
                            <?php echo fm_e($userEmail); ?>
                        </p>
                    </div>
                </div>

                <div class="mt-2 grid grid-cols-2 gap-2">
                    <a href="<?php echo fm_e($dashboardUrl); ?>"
                       class="rounded-lg bg-white/15 px-4 py-3 text-center text-sm font-black text-white ring-1 ring-white/20">
                        Dashboard
                    </a>
                    <a href="<?php echo fm_e($logoutUrl); ?>"
                       class="rounded-lg bg-red-50 px-4 py-3 text-center text-sm font-black text-red-600 ring-1 ring-red-200">
                        Sign Out
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="mt-4 grid grid-cols-2 gap-2 border-t border-white/20 pt-4">
                <a href="<?php echo fm_e($signinUrl); ?>"
                   class="rounded-lg border border-white/30 bg-white/10 px-4 py-3 text-center text-sm font-bold text-white">
                    Sign In
                </a>
                <a href="<?php echo fm_e($userSignupUrl); ?>"
                   class="market-signup-button rounded-lg px-4 py-3 text-center text-sm font-bold">
                    Sign Up
                </a>
            </div>
        <?php endif; ?>
    </div>
</header>
<div id="marketplaceMobileOverlay" class="hidden" aria-hidden="true"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var mobileButton = document.getElementById('marketplaceMobileMenuButton');
    var mobileMenu = document.getElementById('marketplaceMobileMenu');

    var mobileClose = document.getElementById('marketplaceMobileMenuClose');
    var mobileOverlay = document.getElementById('marketplaceMobileOverlay');

    function openMarketplaceMobileMenu() {
        if (!mobileMenu) { return; }
        mobileMenu.classList.remove('hidden');
        mobileMenu.setAttribute('aria-hidden', 'false');
        if (mobileOverlay) {
            mobileOverlay.classList.remove('hidden');
            mobileOverlay.setAttribute('aria-hidden', 'false');
        }
        if (mobileButton) {
            mobileButton.setAttribute('aria-expanded', 'true');
        }
        document.body.classList.add('overflow-hidden');
    }

    function closeMarketplaceMobileMenu() {
        if (!mobileMenu) { return; }
        mobileMenu.classList.add('hidden');
        mobileMenu.setAttribute('aria-hidden', 'true');
        if (mobileOverlay) {
            mobileOverlay.classList.add('hidden');
            mobileOverlay.setAttribute('aria-hidden', 'true');
        }
        if (mobileButton) {
            mobileButton.setAttribute('aria-expanded', 'false');
        }
        document.body.classList.remove('overflow-hidden');
    }

    if (mobileButton && mobileMenu) {
        mobileButton.setAttribute('aria-expanded', 'false');
        mobileButton.addEventListener('click', function () {
            if (mobileMenu.classList.contains('hidden')) {
                openMarketplaceMobileMenu();
            } else {
                closeMarketplaceMobileMenu();
            }
        });
    }

    if (mobileClose) {
        mobileClose.addEventListener('click', closeMarketplaceMobileMenu);
    }

    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', closeMarketplaceMobileMenu);
    }

    if (mobileMenu) {
        mobileMenu.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', closeMarketplaceMobileMenu);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMarketplaceMobileMenu();
        }
    });

    var accountButton = document.getElementById('marketplaceAccountButton');
    var accountDropdown = document.getElementById('marketplaceAccountDropdown');

    if (accountButton && accountDropdown) {
        accountButton.addEventListener('click', function (event) {
            event.stopPropagation();
            accountDropdown.classList.toggle('hidden');
        });

        accountDropdown.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        document.addEventListener('click', function () {
            accountDropdown.classList.add('hidden');
        });
    }

    var signupButton = document.getElementById('signupDropdownButton');
    var signupMenu = document.getElementById('signupDropdownMenu');

    if (signupButton && signupMenu) {
        signupButton.addEventListener('click', function (event) {
            event.stopPropagation();
            signupMenu.classList.toggle('hidden');
        });

        signupMenu.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        document.addEventListener('click', function () {
            signupMenu.classList.add('hidden');
        });
    }
});
</script>