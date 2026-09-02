# Security Notes

The project includes practical security controls appropriate for a PHP/XAMPP school project.

1. **Central authorization** - `fm_require_role()` re-checks the current account from the database on protected requests. Suspended accounts and changed roles lose access on the next request.
2. **Vendor approval control** - Pending/rejected Vendors cannot open Products, Markets, Events, Orders or Reviews pages.
3. **Forced first Admin password change** - Fresh-install Admin uses a real bcrypt hash and `must_change_password=1`.
4. **CSRF protection** - State-changing forms use session CSRF tokens.
5. **Password hashing** - Passwords use PHP `password_hash()` and `password_verify()`.
6. **Login throttling** - Failed email + IP attempts are stored in `login_attempts`; five failures within 15 minutes temporarily block Sign In.
7. **Prepared statements** - Dynamic database input uses PDO prepared statements.
8. **Secure sessions** - Strict mode, HttpOnly, SameSite=Lax, Secure when HTTPS, and session ID regeneration on login.
9. **Security headers** - CSP, frame protection, MIME-sniffing protection, referrer and permissions policies.
10. **Upload validation** - Image type/size validation is performed and PHP execution is blocked in upload directories.
11. **Private payment slips** - Direct web access to payment-slip files is denied. `payment_slip.php` checks Admin or order-owner permission before streaming an image.
12. **No public password reset utility** - `reset.php` is removed. Admin password maintenance is CLI-only in `tools/`.
13. **Sensitive file blocking** - SQL, Markdown documentation, config/helper fragments and tools are blocked from direct HTTP access by Apache `.htaccess` rules.

For a real public deployment, also use HTTPS, environment-managed secrets, server backups, monitoring/log rotation and production Apache/Nginx configuration.
