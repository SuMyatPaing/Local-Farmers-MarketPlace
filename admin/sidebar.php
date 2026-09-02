<?php
require_once __DIR__ . '/../security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$adminName = (string) (
    isset($_SESSION['name']) ? $_SESSION['name'] :
    (isset($_SESSION['user_name']) ? $_SESSION['user_name'] :
    (isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Admin'))
);

if (trim($adminName) === '') {
    $adminName = 'Admin';
}

$currentPage = basename(
    isset($_SERVER['PHP_SELF'])
        ? $_SERVER['PHP_SELF']
        : 'dashboard.php'
);

$pendingVendorCount = 0;
$newContactMessageCount = 0;

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $pendingVendorCount = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM vendors
             WHERE status = 'pending'"
        )->fetchColumn();
    } catch (Exception $exception) {
        $pendingVendorCount = 0;
    }

    try {
        $newContactMessageCount = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM contact_messages
             WHERE status = 'new'"
        )->fetchColumn();
    } catch (Exception $exception) {
        $newContactMessageCount = 0;
    }
}

$navGroups = array(
    array(
        'label' => 'Overview',
        'items' => array(
            array(
                'label' => 'Dashboard',
                'href' => 'dashboard.php',
                'icon' => 'fa-chart-pie'
            ),
        )
    ),
    array(
        'label' => 'Management',
        'items' => array(
            array('label' => 'Users', 'href' => 'users.php', 'icon' => 'fa-users'),
            array('label' => 'Vendors', 'href' => 'vendors.php', 'icon' => 'fa-store'),
            array('label' => 'Cities', 'href' => 'cities.php', 'icon' => 'fa-city'),
            array('label' => 'Markets', 'href' => 'markets.php', 'icon' => 'fa-shop'),
            array('label' => 'Categories', 'href' => 'categories.php', 'icon' => 'fa-layer-group'),
            array('label' => 'Events', 'href' => 'events.php', 'icon' => 'fa-calendar-days'),
            array('label' => 'Contact Messages', 'href' => 'contact_messages.php', 'icon' => 'fa-envelope'),
        )
    ),
    array(
        'label' => 'Commerce',
        'items' => array(
            array('label' => 'Products', 'href' => 'products.php', 'icon' => 'fa-basket-shopping'),
            array('label' => 'Orders', 'href' => 'orders.php', 'icon' => 'fa-receipt'),
            array('label' => 'Reviews', 'href' => 'reviews.php', 'icon' => 'fa-star'),
        )
    ),
);

$adminInitial = function_exists('mb_substr')
    ? mb_substr(trim($adminName), 0, 1, 'UTF-8')
    : substr(trim($adminName), 0, 1);
?>

<style>
    .sidebar-scroll {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .sidebar-scroll::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    @media (min-width: 1024px) {
        #adminSidebar {
            width: 176px !important;
        }

        #adminSidebar .admin-sidebar-brand {
            height: 44px !important;
            padding-left: 10px !important;
            padding-right: 10px !important;
            gap: 8px !important;
        }

        #adminSidebar .admin-sidebar-logo {
            width: 30px !important;
            height: 30px !important;
            border-radius: 9px !important;
            font-size: 12px !important;
        }

        #adminSidebar .admin-sidebar-title {
            font-size: 10px !important;
        }

        #adminSidebar .admin-sidebar-subtitle {
            font-size: 7px !important;
            letter-spacing: .14em !important;
        }

        #adminSidebar .admin-sidebar-nav {
            padding: 10px 7px !important;
        }

        #adminSidebar .admin-sidebar-group {
            margin-top: 0 !important;
            margin-bottom: 13px !important;
        }

        #adminSidebar .admin-sidebar-group-title {
            margin-bottom: 5px !important;
            padding-left: 7px !important;
            padding-right: 7px !important;
            font-size: 7px !important;
            letter-spacing: .13em !important;
        }

        #adminSidebar .admin-sidebar-list {
            gap: 2px !important;
        }

        #adminSidebar .admin-sidebar-link {
            gap: 8px !important;
            border-radius: 8px !important;
            padding: 6px 8px !important;
            font-size: 10px !important;
            line-height: 1.2 !important;
        }

        #adminSidebar .admin-sidebar-link i {
            width: 14px !important;
            font-size: 11px !important;
        }

        #adminSidebar .admin-sidebar-footer {
            padding: 7px !important;
        }

        #adminSidebar .admin-sidebar-user {
            gap: 8px !important;
            border-radius: 8px !important;
            padding: 6px 7px !important;
        }

        #adminSidebar .admin-sidebar-avatar {
            width: 28px !important;
            height: 28px !important;
            font-size: 9px !important;
        }

        #adminSidebar .admin-sidebar-user-name {
            font-size: 10px !important;
        }

        #adminSidebar .admin-sidebar-role {
            font-size: 8px !important;
        }
    }
</style>

<aside id="adminSidebar"
       class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col fm-sidebar-bg text-white transition-transform duration-300 lg:translate-x-0">

    <div class="admin-sidebar-brand flex h-16 items-center gap-3 border-b border-white/10 px-5">
        <div class="admin-sidebar-logo grid h-10 w-10 place-items-center rounded-xl bg-green-600 text-white shadow-lg shadow-green-950/30">
            <i class="fa-solid fa-seedling"></i>
        </div>

        <div class="min-w-0 flex-1">
            <p class="admin-sidebar-title truncate text-sm font-extrabold">
                Farmers Market
            </p>

            <p class="admin-sidebar-subtitle text-[9px] font-bold uppercase tracking-[0.18em] text-green-300/70">
                Admin Panel
            </p>
        </div>

        <button id="closeAdminSidebar"
                type="button"
                aria-label="Close Admin navigation"
                class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-green-200/70 transition hover:bg-white/10 hover:text-white lg:hidden">
            <i class="fa-solid fa-xmark text-sm"></i>
        </button>
    </div>

    <nav class="admin-sidebar-nav sidebar-scroll flex-1 space-y-6 overflow-y-auto px-3 py-5">
        <?php foreach ($navGroups as $group): ?>
            <div class="admin-sidebar-group">
                <p class="admin-sidebar-group-title mb-2 px-3 text-[10px] font-bold uppercase tracking-widest text-green-400/60">
                    <?= e($group['label']) ?>
                </p>

                <ul class="admin-sidebar-list space-y-1">
                    <?php foreach ($group['items'] as $item): ?>
                        <?php
                        $isActive = ($currentPage === $item['href']);
                        $itemBadge = 0;

                        if ($item['href'] === 'vendors.php') {
                            $itemBadge = $pendingVendorCount;
                        } elseif ($item['href'] === 'contact_messages.php') {
                            $itemBadge = $newContactMessageCount;
                        }
                        ?>

                        <li>
                            <a
                                href="<?= e($item['href']) ?>"
                                class="admin-sidebar-link group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition
                                    <?= $isActive
                                        ? 'bg-green-600 text-white shadow-lg shadow-green-900/30'
                                        : 'text-green-100/80 hover:bg-white/10 hover:text-white' ?>"
                            >
                                <i class="fa-solid <?= e($item['icon']) ?> w-5 text-center text-base
                                    <?= $isActive ? '' : 'text-green-400/80 group-hover:text-green-300' ?>"></i>

                                <span class="flex-1">
                                    <?= e($item['label']) ?>
                                </span>

                                <?php if ($itemBadge > 0): ?>
                                    <span class="grid h-5 min-w-5 place-items-center rounded-full bg-amber-400 px-1.5 text-[10px] font-extrabold text-slate-900">
                                        <?= e(number_format($itemBadge)) ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="admin-sidebar-footer border-t border-white/10 p-3">
        <div class="admin-sidebar-user flex items-center gap-3 rounded-xl px-3 py-2.5">
            <div class="admin-sidebar-avatar grid h-9 w-9 shrink-0 place-items-center rounded-full bg-green-600 text-xs font-extrabold uppercase">
                <?= e($adminInitial) ?>
            </div>

            <div class="min-w-0 flex-1 leading-tight">
                <p class="admin-sidebar-user-name truncate text-sm font-bold">
                    <?= e($adminName) ?>
                </p>

                <p class="admin-sidebar-role text-[11px] text-green-300/70">
                    Administrator
                </p>
            </div>

            <a
                href="../logout.php"
                title="Logout"
                onclick="return confirm('Are you sure you want to log out?');"
                class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-green-200/70 transition hover:bg-white/10 hover:text-white"
            >
                <i class="fa-solid fa-right-from-bracket text-sm"></i>
            </a>
        </div>
    </div>
</aside>

<div id="adminSidebarOverlay" class="fixed inset-0 z-30 hidden bg-slate-950/50 backdrop-blur-sm lg:hidden"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.getElementById('adminSidebar');
    var overlay = document.getElementById('adminSidebarOverlay');
    var openButton = document.getElementById('adminHeaderMenuButton');
    var closeButton = document.getElementById('closeAdminSidebar');

    function openSidebar() {
        if (!sidebar || !overlay) { return; }
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closeSidebar() {
        if (!sidebar || !overlay) { return; }
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    if (openButton) { openButton.addEventListener('click', openSidebar); }
    if (closeButton) { closeButton.addEventListener('click', closeSidebar); }
    if (overlay) { overlay.addEventListener('click', closeSidebar); }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeSidebar(); }
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