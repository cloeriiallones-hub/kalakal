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

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$pdo->beginTransaction();
try {
    $email = 'session-test-' . bin2hex(random_bytes(8)) . '@example.invalid';
    $insert = $pdo->prepare(
        "INSERT INTO users
            (first_name, last_name, email, password_hash, user_role, seller_type, email_verified_at)
         VALUES ('Session', 'Test', ?, 'not-a-login-hash', 'seller', 'resident', NOW())"
    );
    $insert->execute([$email]);
    $userId = (int) $pdo->lastInsertId();

    start_user_session($userId);
    $user = current_user();
    if (!$user || (int) $user['user_id'] !== $userId) {
        throw new RuntimeException('The registered session could not load its user.');
    }

    revoke_current_user_session();
    $check = $pdo->prepare(
        'SELECT revoked_at FROM user_sessions WHERE user_id = ? ORDER BY session_id DESC LIMIT 1'
    );
    $check->execute([$userId]);
    if (!$check->fetchColumn()) {
        throw new RuntimeException('The current session was not marked revoked.');
    }

    fwrite(STDOUT, "Session creation, lookup, and revocation passed.\n");
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
