<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$databaseName = getenv('KALAKAL_DB_NAME') ?: 'kalakal';
if (getenv('KALAKAL_TEST_DB') !== '1'
    || !preg_match('/_test(?:_|$)/', $databaseName)) {
    fwrite(STDERR, "Refusing to run: set KALAKAL_TEST_DB=1 and use a database name containing _test.\n");
    exit(2);
}

$role = $argv[1] ?? '';
if (!in_array($role, ['seller', 'buyer', 'admin'], true)) {
    fwrite(STDERR, "Usage: php tests/auth_email_policy_smoke.php seller|buyer|admin\n");
    exit(2);
}

ob_start();
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$email = 'email-policy-' . bin2hex(random_bytes(8)) . '@example.invalid';
$password = 'Policy-Test-Password-27!';
$insert = $pdo->prepare(
    'INSERT INTO users
        (first_name, last_name, email, password_hash, user_role, seller_type, email_verified_at)
     VALUES (?, ?, ?, ?, ?, ?, NULL)'
);
$insert->execute([
    'Policy',
    'Test',
    $email,
    password_hash($password, PASSWORD_DEFAULT),
    $role,
    $role === 'seller' ? 'resident' : null,
]);
$userId = (int) $pdo->lastInsertId();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$csrf = bin2hex(random_bytes(32));
$_SESSION['csrf'] = $csrf;
$_SESSION['pending_admin_mfa'] = ['obsolete' => true];
$_POST = [
    'csrf' => $csrf,
    'email' => $email,
    'password' => $password,
];

register_shutdown_function(static function () use ($pdo, $userId, $role): void {
    $passed = isset($_SESSION['user_id'])
        && (int) $_SESSION['user_id'] === $userId
        && !isset($_SESSION['pending_admin_mfa']);

    $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!$passed) {
        fwrite(STDERR, "Password login failed for unverified $role account.\n");
        exit(1);
    }
    fwrite(STDOUT, "Password login without email verification passed for $role.\n");
});

require __DIR__ . '/../login.php';
