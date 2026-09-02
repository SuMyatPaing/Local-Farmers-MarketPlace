<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/database.php';

$email = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? strtolower(trim((string) $argv[1]))
    : 'admin@gmail.com';

$password = isset($argv[2]) ? (string) $argv[2] : '';

if ($password === '') {
    echo "New Admin password (minimum 8 characters): ";
    $password = trim((string) fgets(STDIN));
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must contain at least 8 characters.\n");
    exit(1);
}

$statement = $pdo->prepare(
    "UPDATE users
     SET user_password = :user_password,
         must_change_password = 0
     WHERE email = :email
       AND role = 'admin'"
);

$statement->execute(array(
    'user_password' => password_hash($password, PASSWORD_DEFAULT),
    'email' => $email
));

if ($statement->rowCount() !== 1) {
    fwrite(STDERR, "Admin row was not updated. Check the Admin email.\n");
    exit(1);
}

echo "Admin password updated for {$email}.\n";
