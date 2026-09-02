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
    $_SESSION['name']
    ?? $_SESSION['user_name']
    ?? $_SESSION['admin_name']
    ?? 'Admin'
);

$adminEmail = (string) (
    $_SESSION['email']
    ?? $_SESSION['admin_email']
    ?? ''
);

$currentPage = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');

$pageTitles = [
    'dashboard.php'  => 'Dashboard',
    'users.php'      => 'Users',
    'cities.php'     => 'Cities',
    'markets.php'    => 'Markets',
    'categories.php' => 'Categories',
    'events.php'     => 'Events',
    'vendors.php'    => 'Vendors',
    'products.php'   => 'Products',
    'orders.php'     => 'Orders',
    'reviews.php'    => 'Reviews',
    'profile.php'    => 'My Profile',
];

$pageTitle = $pageTitles[$currentPage] ?? 'Admin';

$trimmedAdminName = trim($adminName);

if ($trimmedAdminName === '') {
    $trimmedAdminName = 'Admin';
}

$adminInitial = function_exists('mb_substr')
    ? mb_substr($trimmedAdminName, 0, 1, 'UTF-8')
    : substr($trimmedAdminName, 0, 1);
?>

<script src="../assets/js/responsive.js?v=20260813-v4"></script>

<style>
    @media (min-width: 1024px) {
        .admin-compact-header {
            height: 44px !important;
            padding-left: 14px !important;
            padding-right: 14px !important;
            gap: 8px !important;
        }

        .admin-compact-header .admin-page-title {
            font-size: 12px !important;
        }

        .admin-compact-header .admin-site-link {
            width: 30px !important;
            height: 30px !important;
            border-radius: 8px !important;
        }

        .admin-compact-header .admin-profile-wrap {
            padding-left: 10px !important;
        }

        .admin-compact-header .admin-profile-button {
            gap: 7px !important;
            border-radius: 8px !important;
            padding: 4px 5px !important;
        }

        .admin-compact-header .admin-profile-avatar {
            width: 28px !important;
            height: 28px !important;
            font-size: 9px !important;
        }

        .admin-compact-header .admin-profile-name {
            max-width: 110px !important;
            font-size: 10px !important;
        }

        .admin-compact-header .admin-profile-role {
            font-size: 8px !important;
        }

        .admin-compact-header #adminProfileChevron {
            font-size: 8px !important;
        }
    }
</style>

<header class="admin-compact-header sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6 xl:px-7">

    <div class="flex min-w-0 flex-1 items-center gap-3">
        <button
            id="adminHeaderMenuButton"
            type="button"
            aria-label="Open Admin navigation"
            class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 lg:hidden"
        >
            <i class="fa-solid fa-bars"></i>
        </button>

        <h1 class="admin-page-title truncate text-base font-extrabold tracking-tight text-slate-950 sm:text-lg">
            <?= e($pageTitle) ?>
        </h1>
    </div>

    <a
        href="../index.php"
        title="View site"
        class="admin-site-link grid h-10 w-10 shrink-0 place-items-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-800"
    >
        <i class="fa-solid fa-globe text-base"></i>
    </a>

    <div id="adminProfileMenu"
         class="admin-profile-wrap relative shrink-0 border-l border-slate-200 pl-3 sm:pl-4">

        <button
            id="adminProfileMenuButton"
            type="button"
            aria-expanded="false"
            aria-controls="adminProfileDropdown"
            class="admin-profile-button flex items-center gap-2 rounded-xl p-1.5 text-left transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-green-200"
        >
            <span class="admin-profile-avatar grid h-9 w-9 shrink-0 place-items-center rounded-full bg-green-600 text-xs font-extrabold uppercase text-white">
                <?= e($adminInitial) ?>
            </span>

            <span class="hidden min-w-0 leading-tight lg:block">
                <span class="admin-profile-name block max-w-36 truncate text-sm font-bold text-slate-800">
                    <?= e($trimmedAdminName) ?>
                </span>

                <span class="admin-profile-role block text-[11px] text-slate-400">
                    Administrator
                </span>
            </span>

            <i
                id="adminProfileChevron"
                class="fa-solid fa-chevron-down hidden text-xs text-slate-400 transition-transform duration-200 lg:block"
            ></i>
        </button>

        <div
            id="adminProfileDropdown"
            class="absolute right-0 top-full z-50 mt-2 hidden w-64 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/60"
        >
            <div class="border-b border-slate-100 px-4 py-3">
                <p class="truncate text-sm font-bold text-slate-900">
                    <?= e($trimmedAdminName) ?>
                </p>

                <?php if ($adminEmail !== ''): ?>
                    <p class="mt-0.5 truncate text-xs text-slate-500">
                        <?= e($adminEmail) ?>
                    </p>
                <?php else: ?>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Administrator account
                    </p>
                <?php endif; ?>
            </div>

            <div class="p-2">
                <a
                    href="profile.php"
                    class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-green-50 hover:text-green-700"
                >
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-slate-100 text-slate-500">
                        <i class="fa-regular fa-user"></i>
                    </span>
                    <span>My Profile</span>
                </a>

                <a
                    href="../logout.php"
                    class="mt-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-red-600 transition hover:bg-red-50"
                    onclick="return confirm('Are you sure you want to log out?');"
                >
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-red-50 text-red-500">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    </span>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>
</header>

<script>
(function () {
    'use strict';

    var menu = document.getElementById('adminProfileMenu');
    var button = document.getElementById('adminProfileMenuButton');
    var dropdown = document.getElementById('adminProfileDropdown');
    var chevron = document.getElementById('adminProfileChevron');

    if (!menu || !button || !dropdown) {
        return;
    }

    function openDropdown() {
        dropdown.classList.remove('hidden');
        button.setAttribute('aria-expanded', 'true');

        if (chevron) {
            chevron.classList.add('rotate-180');
        }
    }

    function closeDropdown() {
        dropdown.classList.add('hidden');
        button.setAttribute('aria-expanded', 'false');

        if (chevron) {
            chevron.classList.remove('rotate-180');
        }
    }

    function toggleDropdown() {
        if (dropdown.classList.contains('hidden')) {
            openDropdown();
        } else {
            closeDropdown();
        }
    }

    button.addEventListener('click', function (event) {
        event.stopPropagation();
        toggleDropdown();
    });

    dropdown.addEventListener('click', function (event) {
        event.stopPropagation();
    });

    document.addEventListener('click', function (event) {
        if (!menu.contains(event.target)) {
            closeDropdown();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeDropdown();
            button.focus();
        }
    });
})();
</script>