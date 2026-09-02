<?php

/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
| One shared PDO factory is used by the whole project. The getPDO() helper
| makes the connection available even when this file was first loaded from
| inside an authentication function and a later page uses require_once.
|
| Defaults match the supplied XAMPP/MariaDB setup. Environment variables can
| override them without editing source code.
|--------------------------------------------------------------------------
*/

if (!function_exists('getPDO')) {
    function getPDO()
    {
        static $connection = null;

        if ($connection instanceof PDO) {
            return $connection;
        }

        $host = getenv('FM_DB_HOST') !== false ? getenv('FM_DB_HOST') : '127.0.0.1';
        $port = getenv('FM_DB_PORT') !== false ? getenv('FM_DB_PORT') : '3307';
        $dbname = getenv('FM_DB_NAME') !== false ? getenv('FM_DB_NAME') : 'farmer_marketplace_db';
        $username = getenv('FM_DB_USER') !== false ? getenv('FM_DB_USER') : 'root';
        $password = getenv('FM_DB_PASS') !== false ? getenv('FM_DB_PASS') : '';

        try {
            $connection = new PDO(
                "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
                $username,
                $password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                )
            );
        } catch (PDOException $exception) {
            error_log('Farmers Marketplace DB connection failed: ' . $exception->getMessage());
            http_response_code(500);
            exit('Database connection failed. Check config/database.php and make sure MySQL is running.');
        }

        return $connection;
    }
}

$pdo = getPDO();
