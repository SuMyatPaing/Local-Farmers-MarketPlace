<?php
require_once __DIR__ . '/../security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('vendor_sidebar_e')) {
    function vendor_sidebar_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| Vendor URLs
|--------------------------------------------------------------------------
| SCRIPT_NAME example:
| /farmer_marketplace/vendor/products.php
|
| The generated base URL becomes:
| /farmer_marketplace/vendor
|--------------------------------------------------------------------------
*/

$scriptName = isset($_SERVER['SCRIPT_NAME'])
    ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME'])
    : '/vendor/dashboard.php';

$vendorBaseUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');

if ($vendorBaseUrl === '') {
    $vendorBaseUrl = '/vendor';
}

$projectBaseUrl = rtrim(str_replace('\\', '/', dirname($vendorBaseUrl)), '/.');

if ($projectBaseUrl === '') {
    $projectBaseUrl = '/';
}

$currentPage = basename(
    isset($_SERVER['PHP_SELF'])
        ? $_SERVER['PHP_SELF']
        : 'dashboard.php'
);

$vendorName = isset($_SESSION['name'])
    ? $_SESSION['name']
    : (
        isset($_SESSION['vendor_name'])
            ? $_SESSION['vendor_name']
            : (
                isset($_SESSION['user_name'])
                    ? $_SESSION['user_name']
                    : 'Vendor'
            )
    );


/*
|--------------------------------------------------------------------------
| Pending Order Notification Count
|--------------------------------------------------------------------------
| Shows the number of vendor orders that still need Confirm / Reject.
| No notification table is required; existing vendor_orders data is used.
|--------------------------------------------------------------------------
*/

$vendorOrderNotificationCount = 0;

$sidebarVendorId = isset($_SESSION['vendor_id'])
    ? (int) $_SESSION['vendor_id']
    : 0;

if ($sidebarVendorId > 0) {
    try {
        $sidebarPdo = (
            isset($pdo) &&
            $pdo instanceof PDO
        )
            ? $pdo
            : null;

        if (!($sidebarPdo instanceof PDO)) {
            $sidebarDatabaseFile =
                __DIR__ . '/../config/database.php';

            if (file_exists($sidebarDatabaseFile)) {
                require_once $sidebarDatabaseFile;
            }

            if (isset($pdo) && $pdo instanceof PDO) {
                $sidebarPdo = $pdo;
            } elseif (function_exists('getPDO')) {
                $sidebarPdo = getPDO();
            }
        }

        if ($sidebarPdo instanceof PDO) {
            $vendorNotificationStatement =
                $sidebarPdo->prepare(
                    "SELECT COUNT(*)
                     FROM vendor_orders
                     WHERE vendor_id = :vendor_id
                       AND order_status = 'pending'"
                );

            $vendorNotificationStatement->execute(array(
                'vendor_id' => $sidebarVendorId
            ));

            $vendorOrderNotificationCount =
                (int) $vendorNotificationStatement->fetchColumn();
        }
    } catch (PDOException $exception) {
        error_log(
            'Vendor pending order notification error: ' .
            $exception->getMessage()
        );

        $vendorOrderNotificationCount = 0;
    }
}

$navGroups = array(
    array(
        'label' => 'Overview',
        'items' => array(
            array(
                'href' => $vendorBaseUrl . '/dashboard.php',
                'file' => 'dashboard.php',
                'label' => 'Dashboard',
                'icon' => 'fa-gauge-high'
            )
        )
    ),
    array(
        'label' => 'Store Management',
        'items' => array(
            array(
                'href' => $vendorBaseUrl . '/products.php',
                'file' => 'products.php',
                'label' => 'My Products',
                'icon' => 'fa-basket-shopping'
            ),
            array(
                'href' => $vendorBaseUrl . '/markets.php',
                'file' => 'markets.php',
                'label' => 'Assigned Markets',
                'icon' => 'fa-store'
            ),
            array(
                'href' => $vendorBaseUrl . '/events.php',
                'file' => 'events.php',
                'label' => 'Events',
                'icon' => 'fa-calendar-days'
            )
        )
    ),
    array(
        'label' => 'Commerce',
        'items' => array(
            array(
                'href' => $vendorBaseUrl . '/orders.php',
                'file' => 'orders.php',
                'label' => 'Orders',
                'icon' => 'fa-clipboard-list'
            ),
            array(
                'href' => $vendorBaseUrl . '/reviews.php',
                'file' => 'reviews.php',
                'label' => 'Reviews',
                'icon' => 'fa-star'
            )
        )
    )
);
?>

<aside id="vendorSidebar"
       class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col fm-sidebar-bg text-white transition-transform duration-300 lg:translate-x-0">

    <!-- Logo -->
    <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-5">

        <a href="<?php echo vendor_sidebar_e($vendorBaseUrl . '/dashboard.php'); ?>"
           class="flex min-w-0 flex-1 items-center gap-3">

            <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-green-600 text-white shadow-lg shadow-green-900/30">
                <i class="fa-solid fa-seedling text-lg"></i>
            </div>

            <div class="min-w-0 flex-1 leading-tight">
                <p class="truncate text-sm font-extrabold tracking-tight">
                    Farmers Market
                </p>

                <p class="text-[11px] font-medium text-green-300/80">
                    Vendor Panel
                </p>
            </div>
        </a>

        <button id="closeVendorSidebar"
                type="button"
                title="Close sidebar"
                class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-green-200/70 transition hover:bg-white/10 hover:text-white lg:hidden">

            <i class="fa-solid fa-xmark text-sm"></i>
        </button>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-scroll flex-1 space-y-6 overflow-y-auto px-3 py-5">

        <?php foreach ($navGroups as $group): ?>
            <div>

                <p class="mb-2 px-3 text-[10px] font-bold uppercase tracking-widest text-green-400/60">
                    <?php echo vendor_sidebar_e($group['label']); ?>
                </p>

                <ul class="space-y-1">

                    <?php foreach ($group['items'] as $item): ?>
                        <?php $isActive = $currentPage === $item['file']; ?>

                        <li>
                            <a href="<?php echo vendor_sidebar_e($item['href']); ?>"
                               class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition
                               <?php echo $isActive
                                   ? 'bg-green-600 text-white shadow-lg shadow-green-900/30'
                                   : 'text-green-100/80 hover:bg-white/10 hover:text-white'; ?>">

                                <i class="fa-solid <?php echo vendor_sidebar_e($item['icon']); ?> w-5 text-center text-base
                                   <?php echo $isActive
                                       ? ''
                                       : 'text-green-400/80 group-hover:text-green-300'; ?>">
                                </i>

                                <span class="flex-1">
                                    <?php echo vendor_sidebar_e($item['label']); ?>
                                </span>

                                <?php if (
                                    $item['file'] === 'orders.php' &&
                                    $vendorOrderNotificationCount > 0
                                ): ?>
                                    <span title="<?php echo vendor_sidebar_e(
                                        $vendorOrderNotificationCount .
                                        ' pending order(s)'
                                    ); ?>"
                                          class="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-black leading-none text-white shadow-sm ring-2 ring-white/10">
                                        <?php echo $vendorOrderNotificationCount > 99
                                            ? '99+'
                                            : number_format($vendorOrderNotificationCount); ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>

                </ul>
            </div>
        <?php endforeach; ?>

    </nav>

    <!-- Vendor Information -->
    <div class="border-t border-white/10 p-3">

        <div class="flex items-center gap-3 rounded-xl px-3 py-2.5">

            <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-green-600 text-xs font-extrabold uppercase">
                <?php
                $vendorInitial = substr(trim($vendorName), 0, 1);
                echo vendor_sidebar_e($vendorInitial !== '' ? $vendorInitial : 'V');
                ?>
            </div>

            <div class="min-w-0 flex-1 leading-tight">
                <p class="truncate text-sm font-bold">
                    <?php echo vendor_sidebar_e($vendorName); ?>
                </p>

                <p class="text-[11px] text-green-300/70">
                    Vendor
                </p>
            </div>

            <a href="<?php echo vendor_sidebar_e($projectBaseUrl . '/logout.php'); ?>"
               title="Logout"
               onclick="return confirm('Are you sure you want to logout?');"
               class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-green-200/70 transition hover:bg-white/10 hover:text-white">

                <i class="fa-solid fa-right-from-bracket text-sm"></i>
            </a>
        </div>
    </div>
</aside>

<!-- Mobile Overlay -->
<div id="vendorSidebarOverlay"
     class="fixed inset-0 z-30 hidden bg-slate-950/50 backdrop-blur-sm lg:hidden">
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var sidebar = document.getElementById('vendorSidebar');
        var overlay = document.getElementById('vendorSidebarOverlay');
        var openButton = document.getElementById('vendorHeaderMenuButton') || document.getElementById('openVendorSidebar');
        var closeButton = document.getElementById('closeVendorSidebar');

        function openSidebar() {
            if (!sidebar || !overlay) {
                return;
            }

            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function closeSidebar() {
            if (!sidebar || !overlay) {
                return;
            }

            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        if (openButton) {
            openButton.addEventListener('click', openSidebar);
        }

        if (closeButton) {
            closeButton.addEventListener('click', closeSidebar);
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });

        document.addEventListener('vendor-sidebar-toggle', function () {
            if (!sidebar) { return; }
            if (sidebar.classList.contains('-translate-x-full')) {
                openSidebar();
            } else {
                closeSidebar();
            }
        });

window.addEventListener('resize', function () {
        var compact = document.body.classList.contains('fm-compact-nav');

        if (!compact) {
            if (overlay) { overlay.classList.add('hidden'); }
            document.body.classList.remove('overflow-hidden');
            if (sidebar) { sidebar.classList.remove('-translate-x-full'); }
        } else if (sidebar) {
            sidebar.classList.add('-translate-x-full');
        }
    });

    document.addEventListener('fm:responsivechange', function (event) {
        var compact = !!(event.detail && event.detail.compact);

        if (!compact) {
            if (overlay) { overlay.classList.add('hidden'); }
            document.body.classList.remove('overflow-hidden');
            if (sidebar) { sidebar.classList.remove('-translate-x-full'); }
        } else {
            if (overlay) { overlay.classList.add('hidden'); }
            document.body.classList.remove('overflow-hidden');
            if (sidebar) { sidebar.classList.add('-translate-x-full'); }
        }
    });
    });
</script>