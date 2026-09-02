# v4 database connection scope fix

The v3 authentication guard loaded `config/database.php` from inside `fm_refresh_session_account()`.
Because protected pages later used `require_once`, PHP considered the database file already loaded even though some pages did not have a usable page-scope `$pdo` variable. This could produce:

`Database connection is not available.`

v4 fixes this by:

- providing a shared `getPDO()` singleton in `config/database.php`;
- keeping the authentication connection in global scope;
- adding defensive `getPDO()` fallback checks to Admin pages.

No database schema/data change is required for this fix.
