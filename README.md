# Local Farmers Marketplace - Final Project

A PHP + MySQL/MariaDB marketplace for local farmers with **Admin**, **Vendor**, and **User/Customer** roles. It is designed for **PHP 7.1+ / XAMPP** and the UI works without internet access.

## Implemented workflow

- Public visitors browse Markets, Products and Events.
- Customers register, manage a Profile, use Cart and Checkout.
- Products use a selling unit: `kg`, `viss`, `piece`, `pack`, `bunch`, `bag` or `basket`.
- Checkout supports **Market Pickup** or **Delivery** with recipient contact/address.
- A checkout may contain Products from multiple Vendors.
- One `purchase_process` master order is split into separate `vendor_orders`.
- Vendors Confirm/Reject their own portions; rejected stock is restored.
- Payment methods are **KBZPay** and **Wave Money** only.
- Customer submits transaction ID and payment slip; Admin verifies/rejects payment.
- Vendor can mark a confirmed portion Completed only after payment is `paid`.
- Customer may write/update one Review only after a **paid + completed** purchase of that Product.

## Fresh installation

1. Copy the project to:
   `C:\xampp\htdocs\farmer_marketplace`
2. Start Apache and MySQL/MariaDB in XAMPP.
3. Import **`config/database.sql`** using phpMyAdmin. **Fresh-install SQL rebuilds the project tables**, so use the migration file instead if you need to keep existing data.
4. Check `config/database.php`. Package defaults:
   - Host: `127.0.0.1`
   - Port: `3307`
   - Database: `farmer_marketplace_db`
   - User: `root`
   - Password: empty
5. Open `http://localhost/farmer_marketplace/` or use your configured Apache port.

### Fresh-install Admin

- Email: `admin@gmail.com`
- Temporary password: `Admin@12345`

The SQL stores a **real bcrypt hash**, not the plain password. On first Admin Sign In, the Admin is forced to **Profile > Change Password** before other Admin pages can be used.

Admin password can also be set from CMD only:

`php tools/set_admin_password.php`

If PHP is not on PATH:

`C:\xampp\php\php.exe tools\set_admin_password.php`

## Upgrading your existing real database

If you want to keep existing users/orders/passwords, **do not overwrite it with `database.sql`**. Back up the database, then import:

`config/migrate_existing_database.sql`

It adds Product units, Pickup/Delivery fields and login throttling; removes old COD/empty payment values; converts `vendor_orders` to UTF-8; fixes QR paths; and preserves existing password hashes.

## Offline UI

Runtime assets are local:
- `assets/css/app.css` - compiled Tailwind utilities.
- `assets/css/theme.css` - shared green Farmers Marketplace theme.
- `assets/vendor/fontawesome/` - local Font Awesome icons/fonts.
- `assets/js/chart-lite.js` - local dashboard chart renderer.

No Tailwind CDN, Google Fonts, Font Awesome CDN or remote Chart.js is required at runtime.

## Payment QR

Expected paths:
- `uploads/payment_qr/kbzpay.png`
- `uploads/payment_qr/wave_money.png`

A Wave Money placeholder is included if the original project did not contain the real QR. Replace it with the real Wave Money QR before a real payment demo.

## Security included

- Database-backed Admin/Vendor/User authorization refresh.
- Pending/rejected Vendor access restriction.
- Forced first Admin password change.
- CSRF tokens on state-changing forms.
- `password_hash()` / `password_verify()`.
- Persistent email+IP Sign In throttling.
- Strict/HttpOnly/SameSite cookies and session ID regeneration.
- CSP and other HTTP security headers.
- PDO prepared statements and safe post-login redirects.
- Image type/size validation and blocked PHP execution in upload folders.
- Payment slips are private and served only by an authorized endpoint.
- Public `reset.php` removed; Admin reset utility is CLI-only.
- Directory listing and sensitive SQL/Markdown/tool access blocked with `.htaccess`.

See `docs/SECURITY.md`.

## Code map

See:
- `docs/PROJECT_STRUCTURE.md`
- `docs/ORDER_WORKFLOW.md`
- `docs/FINAL_CHECKLIST.md`

Important shared files:
- `security.php` - session/HTTP security.
- `auth.php` - sessions, role guards, CSRF and Vendor approval rules.
- `lib/marketplace.php` - Product unit/payment/fulfillment helpers.
- `config/database.php` - PDO connection.

## Project self-check

Run from CMD inside the project folder:

`php tools/project_check.php`

It checks required files/assets, removed stale files, database tables/columns, payment enum, Vendor charset and QR files.
