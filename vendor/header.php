<?php
require_once __DIR__ . '/../security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Vendor Header
|--------------------------------------------------------------------------
| File location:
| C:\xampp\htdocs\farmer_marketplace\vendor\header.php
|--------------------------------------------------------------------------
*/

if (!function_exists('vendor_header_e')) {
    function vendor_header_e($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

$currentPage = basename(
    isset($_SERVER['PHP_SELF'])
        ? (string) $_SERVER['PHP_SELF']
        : ''
);

$vendorHeaderPages = array(
    'dashboard.php' => array(
        'title' => 'Dashboard',
        'subtitle' => 'Vendor Management Panel',
        'search' => 'Search dashboard...'
    ),
    'products.php' => array(
        'title' => 'My Products',
        'subtitle' => 'Vendor Management Panel',
        'search' => 'Search products...'
    ),
    'markets.php' => array(
        'title' => 'Assigned Markets',
        'subtitle' => 'Vendor Management Panel',
        'search' => 'Search markets...'
    ),
    'events.php' => array(
        'title' => 'Market Events',
        'subtitle' => 'Vendor Management Panel',
        'search' => 'Search events...'
    ),
    'orders.php' => array(
        'title' => 'Orders',
        'subtitle' => 'Vendor Management Panel',
        'search' => 'Search orders...'
    ),
    'reviews.php' => array(
        'title' => 'Reviews',
        'subtitle' => 'Vendor Management Panel',
        'search' => 'Search reviews...'
    ),
    'profile.php' => array(
        'title' => 'My Profile',
        'subtitle' => 'Vendor Management Panel',
        'search' => 'Search...'
    )
);

$currentHeaderPage = isset($vendorHeaderPages[$currentPage])
    ? $vendorHeaderPages[$currentPage]
    : array(
        'title' => isset($pageTitle)
            ? (string) $pageTitle
            : 'Vendor Panel',
        'subtitle' => 'Vendor Management Panel',
        'search' => 'Search...'
    );

$headerTitle = isset($pageTitle) &&
    trim((string) $pageTitle) !== ''
        ? (string) $pageTitle
        : $currentHeaderPage['title'];

$headerSubtitle = isset($pageSubtitle) &&
    trim((string) $pageSubtitle) !== ''
        ? (string) $pageSubtitle
        : $currentHeaderPage['subtitle'];

$searchPlaceholder = isset($pageSearchPlaceholder) &&
    trim((string) $pageSearchPlaceholder) !== ''
        ? (string) $pageSearchPlaceholder
        : $currentHeaderPage['search'];

$vendorName = isset($_SESSION['vendor_name']) &&
    trim((string) $_SESSION['vendor_name']) !== ''
        ? (string) $_SESSION['vendor_name']
        : (
            isset($_SESSION['user_name']) &&
            trim((string) $_SESSION['user_name']) !== ''
                ? (string) $_SESSION['user_name']
                : 'Vendor'
        );

$vendorProfileImage = isset($_SESSION['vendor_profile_image'])
    ? trim((string) $_SESSION['vendor_profile_image'])
    : '';

if (
    $vendorProfileImage === '' &&
    isset($pdo) &&
    $pdo instanceof PDO &&
    isset($_SESSION['vendor_id']) &&
    (int) $_SESSION['vendor_id'] > 0
) {
    try {
        $profileImageStatement = $pdo->prepare(
            "SELECT profile_image
             FROM vendors
             WHERE vendor_id = :vendor_id
             LIMIT 1"
        );

        $profileImageStatement->execute(array(
            'vendor_id' => (int) $_SESSION['vendor_id']
        ));

        $vendorProfileImage =
            (string) $profileImageStatement->fetchColumn();

        $_SESSION['vendor_profile_image'] =
            $vendorProfileImage;
    } catch (PDOException $exception) {
        $vendorProfileImage = '';
    }
}

$vendorProfileImageUrl = '';

if ($vendorProfileImage !== '') {
    if (
        preg_match('/^https?:\/\//i', $vendorProfileImage) ||
        strpos($vendorProfileImage, 'data:') === 0
    ) {
        $vendorProfileImageUrl = $vendorProfileImage;
    } else {
        $vendorProfileImageUrl =
            '../' .
            ltrim(
                str_replace('\\', '/', $vendorProfileImage),
                '/'
            );
    }
}

$searchValue = isset($_GET['q'])
    ? trim((string) $_GET['q'])
    : '';

$searchPages = array(
    'products.php',
    'markets.php',
    'events.php',
    'orders.php',
    'reviews.php'
);

$searchEnabled = in_array(
    $currentPage,
    $searchPages,
    true
);

$searchAction = $searchEnabled
    ? $currentPage
    : 'products.php';
?>

<script src="../assets/js/responsive.js?v=20260813-v4"></script>

<header class="sticky top-0 z-40 border-b border-slate-200 bg-white">

    <div class="flex min-h-[76px] items-center justify-between gap-4 px-4 sm:px-6 xl:px-7">

        <!-- Left: Mobile button and page title -->
        <div class="flex min-w-0 items-center gap-3">

            <button id="vendorHeaderMenuButton"
                    type="button"
                    aria-label="Open sidebar"
                    aria-controls="vendorSidebar"
                    aria-expanded="false"
                    class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-800 lg:hidden">

                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="min-w-0">

                <h1 class="truncate text-xl font-extrabold tracking-tight text-slate-950 sm:text-2xl">

                    <?php echo vendor_header_e($headerTitle); ?>
                </h1>

                <p class="mt-0.5 truncate text-xs font-medium text-slate-400 sm:text-sm">

                    <?php echo vendor_header_e($headerSubtitle); ?>
                </p>
            </div>
        </div>

        <!-- Right side -->
        <div class="flex shrink-0 items-center gap-2 sm:gap-3">

            <!-- Search -->
            <?php if ($searchEnabled): ?>
            <form method="get"
                  action="<?php echo vendor_header_e($searchAction); ?>"
                  class="relative hidden md:block">

                <button type="submit"
                        aria-label="Search"
                        title="Search"
                        class="absolute left-1.5 top-1/2 z-10 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-xl text-slate-400 transition hover:bg-green-50 hover:text-green-600">
                    <i class="fa-solid fa-magnifying-glass text-sm"></i>
                </button>

                <input type="search"
                       name="q"
                       value="<?php echo vendor_header_e($searchValue); ?>"
                       placeholder="<?php echo vendor_header_e($searchPlaceholder); ?>"
                       autocomplete="off"
                       class="h-11 w-56 rounded-2xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100 xl:w-72">
            </form>
            <?php endif; ?>

            <!-- Website -->
            <a href="../index.php"
               title="Open marketplace"
               class="hidden h-11 w-11 place-items-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-green-600 sm:grid">

                <i class="fa-solid fa-globe"></i>
            </a>

            <div class="hidden h-10 w-px bg-slate-200 sm:block"></div>

            <!-- Profile -->
            <div class="relative">

                <button id="vendorProfileButton"
                        type="button"
                        aria-expanded="false"
                        aria-controls="vendorProfileMenu"
                        class="group flex items-center gap-3 rounded-2xl px-1.5 py-1.5 text-left transition hover:bg-slate-50 sm:px-2">

                    <!-- Vendor profile image -->
                    <span class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-full bg-green-600 text-white shadow-sm transition group-hover:ring-4 group-hover:ring-green-100">

                        <?php if ($vendorProfileImageUrl !== ''): ?>

                            <img src="<?php echo vendor_header_e($vendorProfileImageUrl); ?>"
                                 alt="<?php echo vendor_header_e($vendorName); ?>"
                                 class="h-full w-full object-cover">

                        <?php else: ?>

                            <i class="fa-solid fa-camera text-sm"></i>

                        <?php endif; ?>
                    </span>

                    <span class="hidden min-w-0 lg:block">

                        <span class="block max-w-36 truncate text-sm font-extrabold text-slate-800">

                            <?php echo vendor_header_e($vendorName); ?>
                        </span>

                        <span class="mt-0.5 block text-xs font-medium text-slate-400">
                            Vendor
                        </span>
                    </span>

                    <i class="fa-solid fa-chevron-down hidden text-[10px] text-slate-400 transition lg:block"
                       id="vendorProfileChevron"></i>
                </button>

                <!-- Profile dropdown -->
                <div id="vendorProfileMenu"
                     class="absolute right-0 top-full z-50 mt-2 hidden w-64 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">

                    <div class="border-b border-slate-100 px-4 py-4">

                        <div class="flex items-center gap-3">

                            <span class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-full bg-green-600 text-white">

                                <?php if ($vendorProfileImageUrl !== ''): ?>

                                    <img src="<?php echo vendor_header_e($vendorProfileImageUrl); ?>"
                                         alt="<?php echo vendor_header_e($vendorName); ?>"
                                         class="h-full w-full object-cover">

                                <?php else: ?>

                                    <i class="fa-solid fa-camera text-sm"></i>

                                <?php endif; ?>
                            </span>

                            <div class="min-w-0">

                                <p class="truncate text-sm font-extrabold text-slate-900">

                                    <?php echo vendor_header_e($vendorName); ?>
                                </p>

                                <p class="mt-0.5 text-xs text-slate-400">
                                    Vendor Account
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-2">

                        <a href="profile.php"
                           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-green-50 hover:text-green-700">

                            <span class="grid h-8 w-8 place-items-center rounded-lg bg-green-50 text-green-600">
                                <i class="fa-solid fa-user text-xs"></i>
                            </span>

                            My Profile
                        </a>

                        <a href="dashboard.php"
                           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-green-50 hover:text-green-700">

                            <span class="grid h-8 w-8 place-items-center rounded-lg bg-blue-50 text-blue-600">
                                <i class="fa-solid fa-chart-pie text-xs"></i>
                            </span>

                            Dashboard
                        </a>

                        <a href="../index.php"
                           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-green-50 hover:text-green-700">

                            <span class="grid h-8 w-8 place-items-center rounded-lg bg-violet-50 text-violet-600">
                                <i class="fa-solid fa-globe text-xs"></i>
                            </span>

                            View Marketplace
                        </a>
                    </div>

                    <div class="border-t border-slate-100 p-2">

                        <a href="../logout.php"
                           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-red-600 transition hover:bg-red-50">

                            <span class="grid h-8 w-8 place-items-center rounded-lg bg-red-50 text-red-600">
                                <i class="fa-solid fa-right-from-bracket text-xs"></i>
                            </span>

                            Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile search -->
    <?php if ($searchEnabled): ?>

        <div class="border-t border-slate-100 px-4 py-3 md:hidden">

            <form method="get"
                  action="<?php echo vendor_header_e($searchAction); ?>"
                  class="relative">

                <button type="submit"
                        aria-label="Search"
                        title="Search"
                        class="absolute left-1.5 top-1/2 z-10 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 transition hover:bg-green-50 hover:text-green-600">
                    <i class="fa-solid fa-magnifying-glass text-sm"></i>
                </button>

                <input type="search"
                       name="q"
                       value="<?php echo vendor_header_e($searchValue); ?>"
                       placeholder="<?php echo vendor_header_e($searchPlaceholder); ?>"
                       autocomplete="off"
                       class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm text-slate-700 outline-none focus:border-green-500 focus:bg-white focus:ring-2 focus:ring-green-100">
            </form>
        </div>

    <?php endif; ?>
</header>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var profileButton = document.getElementById(
            'vendorProfileButton'
        );

        var profileMenu = document.getElementById(
            'vendorProfileMenu'
        );

        var profileChevron = document.getElementById(
            'vendorProfileChevron'
        );

        function closeProfileMenu() {
            if (!profileMenu || !profileButton) {
                return;
            }

            profileMenu.classList.add('hidden');
            profileButton.setAttribute(
                'aria-expanded',
                'false'
            );

            if (profileChevron) {
                profileChevron.classList.remove(
                    'rotate-180'
                );
            }
        }

        if (profileButton && profileMenu) {
            profileButton.addEventListener(
                'click',
                function (event) {
                    event.stopPropagation();

                    var isHidden = profileMenu.classList.contains(
                        'hidden'
                    );

                    profileMenu.classList.toggle('hidden');

                    profileButton.setAttribute(
                        'aria-expanded',
                        isHidden ? 'true' : 'false'
                    );

                    if (profileChevron) {
                        profileChevron.classList.toggle(
                            'rotate-180',
                            isHidden
                        );
                    }
                }
            );

            profileMenu.addEventListener(
                'click',
                function (event) {
                    event.stopPropagation();
                }
            );

            document.addEventListener(
                'click',
                closeProfileMenu
            );

            document.addEventListener(
                'keydown',
                function (event) {
                    if (event.key === 'Escape') {
                        closeProfileMenu();
                    }
                }
            );
        }

        if (menuButton) {
            menuButton.addEventListener(
                'click',
                function () {
                    var sidebarToggle = document.getElementById(
                        'sidebarToggle'
                    );

                    var mobileSidebarButton =
                        document.getElementById(
                            'mobileSidebarButton'
                        );

                    if (sidebarToggle) {
                        sidebarToggle.click();
                    } else if (mobileSidebarButton) {
                        mobileSidebarButton.click();
                    } else {
                        document.dispatchEvent(
                            new CustomEvent(
                                'vendor-sidebar-toggle'
                            )
                        );
                    }
                }
            );
        }
    });
</script>