# Final Completion Checklist

This file maps the requested final improvements to the implementation in the project.

1. **Admin real password hash**
   - Fresh-install SQL contains a real bcrypt hash for the temporary Admin password.
   - `must_change_password=1` forces the Admin to replace it immediately after first Sign In.
   - `tools/set_admin_password.php` is a CLI-only alternative.

2. **Admin / Vendor / User authorization guards**
   - `auth.php` contains the shared login and role guards.
   - Protected requests refresh the current account from the database.
   - Suspended/deleted accounts lose access on the next protected request.
   - Pending/rejected Vendors cannot access selling/order management pages.

3. **Public reset script removed**
   - `reset.php` is intentionally absent.
   - Admin password maintenance is CLI-only.

4. **COD removed**
   - New orders support only `kbzpay` and `wave_money`.
   - Fresh SQL contains no COD payment enum and no empty payment-method seed values.
   - The migration cleans older COD/empty payment values before changing the enum.

5. **`vendor_orders` UTF-8**
   - Fresh SQL creates `vendor_orders` with `utf8mb4`.
   - Migration converts an existing table to `utf8mb4`.

6. **QR image paths fixed**
   - Code and SQL use `uploads/payment_qr/kbzpay.png` and `uploads/payment_qr/wave_money.png`.
   - Both files are bundled. Replace the Wave Money placeholder with the real account QR before a real payment demo.

7. **Duplicate/stale files and routes cleaned**
   - Old duplicate auth pages, `login.php`, role-local logout files, old payment page and obsolete reset script are removed.
   - Shared authentication is handled by root `signin.php`, `logout.php`, `auth.php` and `security.php`.

8. **README and internal documentation updated**
   - `README.md` documents installation, migration, offline assets and Admin setup.
   - `docs/PROJECT_STRUCTURE.md`, `docs/SECURITY.md` and `docs/ORDER_WORKFLOW.md` describe the code clearly.

9. **User Product Reviews work end-to-end**
   - `review_submit.php` checks CSRF, user role and verified purchase eligibility.
   - Review is allowed only after the product's Vendor order is completed and the master payment is paid.
   - One review per user/product can be created and later updated.

10. **Product unit + Pickup / Delivery**
    - Products store a validated selling unit such as kg, viss, piece, pack, bunch, bag or basket.
    - Vendor Product CRUD supports the unit and public/order pages display it.
    - Checkout supports Market Pickup or Delivery; Delivery validates recipient, phone and address.
    - Admin/Vendor/Customer order details display the selected fulfillment information.

## Offline UI

Tailwind utilities, the shared green theme, Font Awesome fonts/icons and dashboard chart code are bundled under `assets/`. Runtime pages do not require a CDN.
