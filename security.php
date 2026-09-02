<?php
/*
|--------------------------------------------------------------------------
| Application Security Bootstrap
|--------------------------------------------------------------------------
| PHP 7.1 compatible. Include this file before page output.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');

    $https =
        isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== '' &&
        strtolower((string) $_SERVER['HTTPS']) !== 'off';

    if ($https) {
        ini_set('session.cookie_secure', '1');
    }

    /*
    | PHP 7.3 added session.cookie_samesite. For PHP 7.1/7.2 we use the
    | compatible cookie-path technique so SameSite=Lax is still sent.
    */
    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        ini_set('session.cookie_samesite', 'Lax');
    } else {
        $cookieParams = session_get_cookie_params();
        session_set_cookie_params(
            0,
            $cookieParams['path'] . '; SameSite=Lax',
            $cookieParams['domain'],
            $https,
            true
        );
    }

    session_start();
}

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "img-src 'self' data:; " .
        "style-src 'self' 'unsafe-inline'; " .
        "script-src 'self' 'unsafe-inline'; " .
        "font-src 'self' data:; " .
        "object-src 'none'; base-uri 'self'; form-action 'self'"
    );
}

date_default_timezone_set('Asia/Yangon');
