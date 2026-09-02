<?php
require_once __DIR__ . '/security.php';

/*
|--------------------------------------------------------------------------
| Shared Authentication Helpers
|--------------------------------------------------------------------------
| PHP 7.1 compatible. Include this file before protected page output.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('fm_e')) {
    function fm_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('fm_base_url')) {
    function fm_base_url()
    {
        static $baseUrl = null;

        if ($baseUrl !== null) {
            return $baseUrl;
        }

        $scriptName = isset($_SERVER['SCRIPT_NAME'])
            ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME'])
            : '/index.php';

        $directory = str_replace('\\', '/', dirname($scriptName));
        $directory = rtrim($directory, '/.');

        $oneLevelFolders = array(
            'auth',
            'admin',
            'vendor',
            'user',
            'public',
            'includes'
        );

        if (
            $directory !== '' &&
            $directory !== '/' &&
            in_array(basename($directory), $oneLevelFolders, true)
        ) {
            $directory = str_replace('\\', '/', dirname($directory));
            $directory = rtrim($directory, '/.');
        }

        if ($directory === '/' || $directory === '.') {
            $directory = '';
        }

        $baseUrl = $directory;

        return $baseUrl;
    }
}

if (!function_exists('fm_url')) {
    function fm_url($path)
    {
        $path = ltrim((string) $path, '/');
        $baseUrl = fm_base_url();

        if ($path === '') {
            return $baseUrl !== '' ? $baseUrl . '/' : '/';
        }

        return ($baseUrl !== '' ? $baseUrl : '') . '/' . $path;
    }
}

if (!function_exists('fm_redirect')) {
    function fm_redirect($location)
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('fm_is_logged_in')) {
    function fm_is_logged_in()
    {
        return isset($_SESSION['user_id']) &&
            (int) $_SESSION['user_id'] > 0 &&
            isset($_SESSION['role']) &&
            in_array(
                strtolower((string) $_SESSION['role']),
                array('admin', 'vendor', 'user'),
                true
            );
    }
}

if (!function_exists('fm_current_user')) {
    function fm_current_user()
    {
        if (!fm_is_logged_in()) {
            return null;
        }

        return array(
            'user_id' => (int) $_SESSION['user_id'],
            'user_name' => isset($_SESSION['user_name'])
                ? (string) $_SESSION['user_name']
                : 'Account',
            'email' => isset($_SESSION['email'])
                ? (string) $_SESSION['email']
                : '',
            'role' => strtolower((string) $_SESSION['role']),
            'vendor_id' => isset($_SESSION['vendor_id'])
                ? (int) $_SESSION['vendor_id']
                : 0,
            'vendor_status' => isset($_SESSION['vendor_status'])
                ? strtolower((string) $_SESSION['vendor_status'])
                : '',
            'must_change_password' => !empty($_SESSION['must_change_password'])
        );
    }
}

if (!function_exists('fm_role_home_path')) {
    function fm_role_home_path($role)
    {
        $role = strtolower((string) $role);

        if ($role === 'admin') {
            return 'admin/dashboard.php';
        }

        if ($role === 'vendor') {
            return 'vendor/dashboard.php';
        }

        if ($role === 'user') {
            return 'userdashboard.php';
        }

        return 'index.php';
    }
}

if (!function_exists('fm_dashboard_url')) {
    function fm_dashboard_url()
    {
        $user = fm_current_user();

        if (!$user) {
            return fm_url('signin.php');
        }

        return fm_url(fm_role_home_path($user['role']));
    }
}

if (!function_exists('fm_login_user')) {
    function fm_login_user($user, $vendor)
    {
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['user_id'];
        $_SESSION['user_name'] = (string) $user['user_name'];
        $_SESSION['name'] = (string) $user['user_name'];
        $_SESSION['email'] = (string) $user['email'];
        $_SESSION['role'] = strtolower((string) $user['role']);
        $_SESSION['must_change_password'] = !empty($user['must_change_password']) ? 1 : 0;

        unset($_SESSION['vendor_id']);
        unset($_SESSION['vendor_name']);
        unset($_SESSION['vendor_status']);

        if (is_array($vendor)) {
            $_SESSION['vendor_id'] = (int) $vendor['vendor_id'];
            $_SESSION['vendor_name'] = (string) $vendor['vendor_name'];
            $_SESSION['vendor_status'] = strtolower((string) $vendor['status']);
        }
    }
}

if (!function_exists('fm_logout_user')) {
    function fm_logout_user()
    {
        $_SESSION = array();

        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $parameters['path'],
                $parameters['domain'],
                $parameters['secure'],
                $parameters['httponly']
            );
        }

        session_destroy();
    }
}

if (!function_exists('fm_csrf_token')) {
    function fm_csrf_token()
    {
        if (
            !isset($_SESSION['fm_csrf_token']) ||
            !is_string($_SESSION['fm_csrf_token']) ||
            $_SESSION['fm_csrf_token'] === ''
        ) {
            $_SESSION['fm_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['fm_csrf_token'];
    }
}

if (!function_exists('fm_verify_csrf')) {
    function fm_verify_csrf($token)
    {
        return isset($_SESSION['fm_csrf_token']) &&
            is_string($token) &&
            hash_equals($_SESSION['fm_csrf_token'], $token);
    }
}

if (!function_exists('fm_flash')) {
    function fm_flash($type, $message)
    {
        $_SESSION['fm_flash'] = array(
            'type' => (string) $type,
            'message' => (string) $message
        );
    }
}

if (!function_exists('fm_pull_flash')) {
    function fm_pull_flash()
    {
        $flash = isset($_SESSION['fm_flash'])
            ? $_SESSION['fm_flash']
            : null;

        unset($_SESSION['fm_flash']);

        return $flash;
    }
}

if (!function_exists('fm_current_request_url')) {
    function fm_current_request_url()
    {
        return isset($_SERVER['REQUEST_URI'])
            ? (string) $_SERVER['REQUEST_URI']
            : fm_url('');
    }
}

if (!function_exists('fm_safe_next_url')) {
    function fm_safe_next_url($next)
    {
        $next = trim((string) $next);

        if ($next === '') {
            return '';
        }

        $parts = parse_url($next);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return '';
        }

        if (strpos($next, '//') === 0) {
            return '';
        }

        $baseUrl = fm_base_url();
        $allowedPrefix = ($baseUrl !== '' ? $baseUrl : '') . '/';

        /*
        | Accept project-relative destinations such as cart.php or
        | products.php?view=1, but never allow directory traversal.
        */
        if (substr($next, 0, 1) !== '/') {
            if (
                strpos($next, '../') !== false ||
                strpos($next, '..\\') !== false ||
                strpos($next, '\\') !== false
            ) {
                return '';
            }

            return fm_url($next);
        }

        if (strpos($next, $allowedPrefix) !== 0) {
            return '';
        }

        return $next;
    }
}

if (!function_exists('fm_refresh_session_account')) {
    function fm_refresh_session_account()
    {
        if (!fm_is_logged_in()) {
            return null;
        }

        /*
        | Load the account again on protected requests. This means an account
        | suspended by Admin loses access immediately instead of remaining
        | trusted until its old browser session expires.
        */
        /*
        | Keep the PDO object in global scope. config/database.php may be
        | loaded here before the protected page loads it with require_once.
        | Without this, the include can be marked as already loaded while
        | the page itself has no $pdo variable.
        */
        global $pdo;

        if (!isset($pdo) || !($pdo instanceof PDO)) {
            require_once __DIR__ . '/config/database.php';
        }

        if ((!isset($pdo) || !($pdo instanceof PDO)) && function_exists('getPDO')) {
            $pdo = getPDO();
        }

        if (!($pdo instanceof PDO)) {
            return null;
        }

        $statement = $pdo->prepare(
            "SELECT
                user_id,
                user_name,
                email,
                role,
                status,
                must_change_password
             FROM users
             WHERE user_id = :user_id
             LIMIT 1"
        );
        $statement->execute(array(
            'user_id' => (int) $_SESSION['user_id']
        ));

        $account = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$account || strtolower((string) $account['status']) !== 'active') {
            fm_logout_user();
            return null;
        }

        $_SESSION['user_name'] = (string) $account['user_name'];
        $_SESSION['name'] = (string) $account['user_name'];
        $_SESSION['email'] = (string) $account['email'];
        $_SESSION['role'] = strtolower((string) $account['role']);
        $_SESSION['must_change_password'] = !empty($account['must_change_password']) ? 1 : 0;

        if ($_SESSION['role'] === 'vendor') {
            $vendorStatement = $pdo->prepare(
                "SELECT vendor_id, vendor_name, status
                 FROM vendors
                 WHERE user_id = :user_id
                 LIMIT 1"
            );
            $vendorStatement->execute(array(
                'user_id' => (int) $account['user_id']
            ));
            $vendor = $vendorStatement->fetch(PDO::FETCH_ASSOC);

            if (!$vendor) {
                fm_logout_user();
                return null;
            }

            $_SESSION['vendor_id'] = (int) $vendor['vendor_id'];
            $_SESSION['vendor_name'] = (string) $vendor['vendor_name'];
            $_SESSION['vendor_status'] = strtolower((string) $vendor['status']);
        } else {
            unset($_SESSION['vendor_id'], $_SESSION['vendor_name'], $_SESSION['vendor_status']);
        }

        return $account;
    }
}

if (!function_exists('fm_admin_password_change_required')) {
    function fm_admin_password_change_required()
    {
        return isset($_SESSION['role']) &&
            strtolower((string) $_SESSION['role']) === 'admin' &&
            !empty($_SESSION['must_change_password']);
    }
}

if (!function_exists('fm_require_login')) {
    function fm_require_login()
    {
        /*
        | Do not trust an old browser session by itself. Re-read the account
        | on every protected request so suspension/deletion takes effect
        | immediately, including pages that only require "logged in" access.
        */
        if (fm_is_logged_in()) {
            $account = fm_refresh_session_account();

            if ($account) {
                return $account;
            }
        }

        $next = rawurlencode(fm_current_request_url());

        fm_redirect(
            fm_url('signin.php?notice=login_required&next=' . $next)
        );
    }
}

if (!function_exists('fm_require_role')) {
    function fm_require_role($requiredRole)
    {
        $account = fm_require_login();
        $requiredRole = strtolower((string) $requiredRole);

        if (strtolower((string) $account['role']) !== $requiredRole) {
            fm_logout_user();
            fm_redirect(fm_url('signin.php?notice=role_changed'));
        }

        /*
        | The seeded Admin account starts with a temporary password. Keep the
        | Admin on the profile page until that password has been replaced.
        */
        if ($requiredRole === 'admin' && fm_admin_password_change_required()) {
            $script = isset($_SERVER['SCRIPT_NAME'])
                ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME'])
                : '';

            if (substr($script, -strlen('/admin/profile.php')) !== '/admin/profile.php') {
                fm_redirect(fm_url('admin/profile.php?notice=password_required'));
            }
        }


        /*
        | Pending/rejected Vendors may see only their status Dashboard/Profile.
        | Selling, market, event, order and review pages require acceptance.
        */
        if ($requiredRole === 'vendor') {
            $vendorStatus = isset($_SESSION['vendor_status'])
                ? strtolower((string) $_SESSION['vendor_status'])
                : '';

            if ($vendorStatus !== 'accepted') {
                $script = isset($_SERVER['SCRIPT_NAME'])
                    ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME'])
                    : '';
                $allowedPendingPages = array(
                    '/vendor/dashboard.php',
                    '/vendor/profile.php'
                );
                $allowed = false;

                foreach ($allowedPendingPages as $allowedPage) {
                    if (substr($script, -strlen($allowedPage)) === $allowedPage) {
                        $allowed = true;
                        break;
                    }
                }

                if (!$allowed) {
                    fm_redirect(fm_url('vendor/dashboard.php?notice=approval_required'));
                }
            }
        }
    }
}

if (!function_exists('fm_guest_only')) {
    function fm_guest_only()
    {
        if (fm_is_logged_in()) {
            fm_redirect(fm_dashboard_url());
        }
    }
}

if (!function_exists('fm_protected_url')) {
    function fm_protected_url($path)
    {
        if (fm_is_logged_in()) {
            return fm_url($path);
        }

        $next = rawurlencode(fm_url($path));

        return fm_url(
            'signin.php?notice=login_required&next=' . $next
        );
    }
}

if (!function_exists('fm_vendor_profile')) {
    function fm_vendor_profile($pdo)
    {
        if (!($pdo instanceof PDO)) {
            return null;
        }

        $user = fm_current_user();

        if (!$user || $user['role'] !== 'vendor') {
            return null;
        }

        $statement = $pdo->prepare(
            "SELECT
                v.vendor_id,
                v.user_id,
                v.vendor_name,
                v.address,
                v.status,
                v.rejection_reason,
                v.reviewed_at
             FROM vendors v
             WHERE v.user_id = :user_id
             LIMIT 1"
        );

        $statement->execute(array(
            'user_id' => $user['user_id']
        ));

        $vendor = $statement->fetch(PDO::FETCH_ASSOC);

        if ($vendor) {
            $_SESSION['vendor_id'] = (int) $vendor['vendor_id'];
            $_SESSION['vendor_name'] = (string) $vendor['vendor_name'];
            $_SESSION['vendor_status'] = strtolower((string) $vendor['status']);
        }

        return $vendor ?: null;
    }
}

if (!function_exists('fm_require_vendor_accepted')) {
    function fm_require_vendor_accepted($pdo)
    {
        fm_require_role('vendor');

        $vendor = fm_vendor_profile($pdo);

        if (!$vendor) {
            fm_logout_user();
            fm_redirect(fm_url('signin.php'));
        }

        if (strtolower((string) $vendor['status']) !== 'accepted') {
            fm_redirect(fm_url('vendor/dashboard.php'));
        }

        return $vendor;
    }
}