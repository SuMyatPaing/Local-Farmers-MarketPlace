<?php
require_once __DIR__ . '/security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/auth.php';

$footerUser = fm_current_user();
$footerLoggedIn = $footerUser !== null;

$footerRole = $footerLoggedIn
    ? strtolower((string) $footerUser['role'])
    : '';
?>

<style>
    /*
    |--------------------------------------------------------------------------
    | Shared Marketplace Footer
    |--------------------------------------------------------------------------
    | Main green: #00A63E
    | Left and right content edges are visually balanced.
    |--------------------------------------------------------------------------
    */

    .market-footer {
        background-color: #00A63E;
        border-color: #07933A;
        color: #ffffff;
    }

    .market-footer-logo {
        background-color: rgba(255, 255, 255, 0.16);
    }

    .market-footer-muted {
        color: rgba(255, 255, 255, 0.74);
    }

    .market-footer-heading {
        color: rgba(255, 255, 255, 0.92);
    }

    .market-footer-link {
        color: rgba(255, 255, 255, 0.80);
        transition:
            color 0.2s ease,
            opacity 0.2s ease;
    }

    .market-footer-link:hover {
        color: #ffffff;
    }

    .market-footer-divider {
        border-color: rgba(255, 255, 255, 0.18);
    }

    /*
    |--------------------------------------------------------------------------
    | Desktop alignment
    |--------------------------------------------------------------------------
    | Brand aligns to the left edge of the content container.
    | Account aligns to the right edge of the same content container.
    | Quick Links remain centered.
    |--------------------------------------------------------------------------
    */
    @media (min-width: 768px) {
        .market-footer-main {
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
        }

        .market-footer-brand {
            justify-self: start;
        }

        .market-footer-quick {
            justify-self: center;
        }

        .market-footer-account {
            justify-self: end;
            text-align: right;
        }

        .market-footer-account .market-footer-link {
            text-align: right;
        }
    }
</style>

<footer class="market-footer mt-12 border-t">

    <div class="mx-auto w-full max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

        <!-- Main Footer Content -->
        <div class="market-footer-main grid gap-10 md:items-start">

            <!-- Brand -->
            <div class="market-footer-brand w-full">

                <a href="<?php echo fm_e(fm_url('index.php')); ?>"
                   class="inline-flex items-center gap-3">

                    <span class="market-footer-logo grid h-10 w-10 place-items-center rounded-xl text-white">
                        <i class="fa-solid fa-seedling"></i>
                    </span>

                    <span>
                        <span class="block text-sm font-black text-white">
                            Farmers Market
                        </span>

                        <span class="block text-[9px] font-bold uppercase tracking-[0.18em] text-white/80">
                            Local Marketplace
                        </span>
                    </span>

                </a>

                <p class="market-footer-muted mt-4 max-w-sm text-sm leading-6">
                    A local marketplace connecting farmers,
                    Vendors and customers.
                </p>

            </div>

            <!-- Quick Links -->
            <div class="market-footer-quick w-full md:w-[360px]">

                <h3 class="market-footer-heading text-xs font-black uppercase tracking-[0.16em]">
                    Quick Links
                </h3>

                <div class="mt-4 grid grid-cols-2 gap-x-10 gap-y-3 text-sm">

                    <a href="<?php echo fm_e(fm_url('markets.php')); ?>"
                       class="market-footer-link">
                        Markets
                    </a>

                    <a href="<?php echo fm_e(fm_url('products.php')); ?>"
                       class="market-footer-link">
                        Products
                    </a>

                    <a href="<?php echo fm_e(fm_url('events.php')); ?>"
                       class="market-footer-link">
                        Events
                    </a>

                    <a href="<?php echo fm_e(fm_url('about.php')); ?>"
                       class="market-footer-link">
                        About Us
                    </a>

                    <a href="<?php echo fm_e(fm_url('contact.php')); ?>"
                       class="market-footer-link">
                        Contact Us
                    </a>

                </div>

            </div>

            <!-- Account -->
            <div class="market-footer-account w-full">

                <h3 class="market-footer-heading text-xs font-black uppercase tracking-[0.16em]">
                    Account
                </h3>

                <div class="mt-4 space-y-3 text-sm">

                    <?php if (!$footerLoggedIn): ?>

                        <a href="<?php echo fm_e(fm_url('signin.php')); ?>"
                           class="market-footer-link block">
                            Sign In
                        </a>

                        <a href="<?php echo fm_e(fm_url('usersignup.php')); ?>"
                           class="market-footer-link block">
                            Create Account
                        </a>

                        <a href="<?php echo fm_e(fm_url('vendorsignup.php')); ?>"
                           class="market-footer-link block">
                            Become a Vendor
                        </a>

                    <?php elseif ($footerRole === 'user'): ?>

                        <a href="<?php echo fm_e(fm_url('my_orders.php')); ?>"
                           class="market-footer-link block">
                            My Orders
                        </a>

                        <a href="<?php echo fm_e(fm_url('profile.php')); ?>"
                           class="market-footer-link block">
                            My Profile
                        </a>

                        <a href="<?php echo fm_e(fm_url('logout.php')); ?>"
                           class="market-footer-link block">
                            Sign Out
                        </a>

                    <?php else: ?>

                        <a href="<?php echo fm_e(fm_dashboard_url()); ?>"
                           class="market-footer-link block">
                            Dashboard
                        </a>

                        <a href="<?php echo fm_e(fm_url('logout.php')); ?>"
                           class="market-footer-link block">
                            Sign Out
                        </a>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <!-- Bottom -->
        <div class="market-footer-divider mt-9 border-t pt-5 text-center text-[11px]">

            <p class="market-footer-muted">
                © <?php echo date('Y'); ?> Local Farmers Marketplace.
                All rights reserved.
            </p>

        </div>

    </div>

</footer>

</body>
</html>