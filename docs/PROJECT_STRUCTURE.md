# Project Structure

This project uses a simple role-based PHP structure so the request flow is easy to trace without a framework.

## Shared root files
- `security.php` - session cookie settings and HTTP security headers.
- `auth.php` - login/session helpers, role guards, Vendor approval guard and CSRF helpers.
- `lib/marketplace.php` - shared Product unit, payment and fulfillment display helpers.
- `header.php` / `footer.php` - public/customer layout.
- `signin.php`, `usersignup.php`, `vendorsignup.php`, `logout.php` - authentication.
- `products.php`, `markets.php`, `events.php` - public catalog.
- `cart.php`, `checkout.php`, `my_orders.php`, `pay_order.php` - customer order/payment workflow.
- `review_submit.php` - verified-purchase Product Review handler.
- `payment_slip.php` - authorized payment-slip streaming endpoint.

## Admin
`admin/` contains database-backed management pages. Actual pages call `fm_require_role('admin')`. `header.php` and `sidebar.php` are include fragments and direct HTTP access to them is blocked.

## Vendor
`vendor/` contains Vendor pages. Vendor status is refreshed from the database on protected requests. Pending/rejected Vendors may see only Dashboard/Profile; selling/order pages require an accepted Vendor account.

## Database
- `config/database.php` - PDO connection.
- `config/database.sql` - complete fresh-install schema and sample data.
- `config/migrate_existing_database.sql` - upgrades an existing older database without replacing passwords.

## Assets
Runtime UI assets are local under `assets/`, including compiled Tailwind CSS, Font Awesome and dashboard charts.

## Uploads
Product, Market, Profile and QR images are under `uploads/`. `uploads/payment_slips/` denies direct HTTP access; slips are served through `payment_slip.php` after authorization.
