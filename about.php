<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';

$pageTitle = 'About Us | Local Farmers Marketplace';
require __DIR__ . '/header.php';
?>

<style>
    /*
    |--------------------------------------------------------------------------
    | About Us - How It Works
    |--------------------------------------------------------------------------
    | The connector line is custom CSS so the layout remains reliable even
    | if the compiled Tailwind CSS does not include a special utility.
    |--------------------------------------------------------------------------
    */

    .market-flow {
        position: relative;
    }

    .market-flow-line {
        display: none;
    }

    .market-flow-icon {
        position: relative;
        z-index: 2;
    }

    @media (min-width: 768px) {
        .market-flow-line {
            position: absolute;
            top: 54px;
            left: 16.666%;
            right: 16.666%;
            display: block;
            border-top: 2px dashed #bbf7d0;
            z-index: 1;
        }
    }
</style>

<main class="mx-auto w-full max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <!-- Page Heading -->
    <section class="mx-auto max-w-4xl text-center">

        <p class="text-xs font-black uppercase tracking-[0.18em] text-green-600">
            About Us
        </p>

        <h1 class="mt-3 text-3xl font-black text-slate-950 sm:text-4xl">
            Connecting local farmers, Vendors and customers.
        </h1>

        <p class="mx-auto mt-4 max-w-2xl text-sm leading-7 text-slate-500 sm:text-base">
            Farmers Market is a local marketplace designed to make it easier
            for customers to discover nearby markets and products while helping
            Vendors manage their products and orders in one place.
        </p>

    </section>

    <!-- Main Purpose -->
    <section class="mt-10 grid gap-5 md:grid-cols-3">

        <!-- Customers -->
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">

            <span class="grid h-11 w-11 place-items-center rounded-xl bg-green-50 text-green-600">
                <i class="fa-solid fa-basket-shopping"></i>
            </span>

            <h2 class="mt-4 text-lg font-black text-slate-900">
                For Customers
            </h2>

            <p class="mt-2 text-sm leading-6 text-slate-500">
                Browse local markets, discover products, place orders and
                follow payment and order status from one account.
            </p>

        </article>

        <!-- Vendors -->
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">

            <span class="grid h-11 w-11 place-items-center rounded-xl bg-green-50 text-green-600">
                <i class="fa-solid fa-store"></i>
            </span>

            <h2 class="mt-4 text-lg font-black text-slate-900">
                For Vendors
            </h2>

            <p class="mt-2 text-sm leading-6 text-slate-500">
                Manage assigned markets, products and customer orders while
                keeping product availability clear for customers.
            </p>

        </article>

        <!-- Administration -->
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">

            <span class="grid h-11 w-11 place-items-center rounded-xl bg-green-50 text-green-600">
                <i class="fa-solid fa-shield-halved"></i>
            </span>

            <h2 class="mt-4 text-lg font-black text-slate-900">
                Trusted Management
            </h2>

            <p class="mt-2 text-sm leading-6 text-slate-500">
                Administrators review Vendors, manage marketplace information
                and verify submitted payments before they are marked as paid.
            </p>

        </article>

    </section>

    <!-- How the Marketplace Works -->
    <section class="mt-14 py-4 sm:py-6">

        <div class="text-center">

            <p class="text-xs font-black uppercase tracking-[0.22em] text-green-600">
                Simple and Trusted
            </p>

            <h2 class="mt-3 text-3xl font-black text-slate-950 sm:text-4xl">
                How the marketplace works
            </h2>

        </div>

        <div class="market-flow relative mt-12">

            <!-- Connector line for tablet / desktop -->
            <div class="market-flow-line" aria-hidden="true"></div>

            <div class="grid gap-10 md:grid-cols-3 md:gap-8">

                <!-- Step 01 -->
                <article class="text-center">

                    <div class="market-flow-icon mx-auto grid h-24 w-24 place-items-center rounded-full border-[10px] border-white bg-green-100 text-green-600 shadow-sm">
                        <i class="fa-solid fa-location-dot text-3xl"></i>
                    </div>

                    <p class="mt-6 text-xs font-black uppercase tracking-[0.16em] text-green-600">
                        Step 01
                    </p>

                    <h3 class="mt-3 text-xl font-black text-slate-950">
                        Find a nearby market
                    </h3>

                    <p class="mx-auto mt-3 max-w-sm text-sm leading-7 text-slate-500">
                        Search local markets, products and upcoming events.
                    </p>

                </article>

                <!-- Step 02 -->
                <article class="text-center">

                    <div class="market-flow-icon mx-auto grid h-24 w-24 place-items-center rounded-full border-[10px] border-white bg-amber-100 text-amber-600 shadow-sm">
                        <i class="fa-solid fa-basket-shopping text-3xl"></i>
                    </div>

                    <p class="mt-6 text-xs font-black uppercase tracking-[0.16em] text-green-600">
                        Step 02
                    </p>

                    <h3 class="mt-3 text-xl font-black text-slate-950">
                        Choose local products
                    </h3>

                    <p class="mx-auto mt-3 max-w-sm text-sm leading-7 text-slate-500">
                        Browse products from accepted Vendors in assigned markets.
                    </p>

                </article>

                <!-- Step 03 -->
                <article class="text-center">

                    <div class="market-flow-icon mx-auto grid h-24 w-24 place-items-center rounded-full border-[10px] border-white bg-blue-100 text-blue-600 shadow-sm">
                        <i class="fa-solid fa-credit-card text-3xl"></i>
                    </div>

                    <p class="mt-6 text-xs font-black uppercase tracking-[0.16em] text-green-600">
                        Step 03
                    </p>

                    <h3 class="mt-3 text-xl font-black text-slate-950">
                        Order, confirm &amp; pay
                    </h3>

                    <p class="mx-auto mt-3 max-w-sm text-sm leading-7 text-slate-500">
                        Place your order, wait for Vendor confirmation,
                        then pay using KBZPay or Wave Money.
                    </p>

                </article>

            </div>
        </div>

    </section>

    <!-- Contact CTA -->
    <section class="mt-12 flex flex-col gap-4 rounded-2xl border border-green-200 bg-green-50 p-6 sm:flex-row sm:items-center sm:justify-between">

        <div>

            <h2 class="text-lg font-black text-slate-900">
                Need help or have a question?
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Send us a message from the Contact Us page.
            </p>

        </div>

        <a href="<?php echo fm_e(fm_url('contact.php')); ?>"
           class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-black text-white transition hover:bg-green-700">

            <i class="fa-solid fa-envelope"></i>
            Contact Us
        </a>

    </section>

</main>

<?php require __DIR__ . '/footer.php'; ?>