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
require_once __DIR__ . '/../includes/admin_accounts.php';

$pdo = db();
$userIds = [];
$createUser = static function (string $role, bool $active = true, ?string $deletionRequestedAt = null) use ($pdo, &$userIds): int {
    $email = 'suspension-' . bin2hex(random_bytes(8)) . '@example.invalid';
    $insert = $pdo->prepare(
        'INSERT INTO users
            (first_name, last_name, email, password_hash, user_role, seller_type, is_active, deletion_requested_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        'Suspension',
        'SmokeTest',
        $email,
        'not-a-login-hash',
        $role,
        $role === 'seller' ? 'resident' : null,
        $active ? 1 : 0,
        $deletionRequestedAt,
    ]);
    $id = (int) $pdo->lastInsertId();
    $userIds[] = $id;
    return $id;
};

try {
    $adminId = $createUser('admin');
    $sellerId = $createUser('seller');
    $secondAdminId = $createUser('admin');
    $legacyInactiveId = $createUser('seller', false);
    $deletionPendingId = $createUser('seller', false, date('Y-m-d H:i:s'));

    $sessionTokenHash = hash('sha256', random_bytes(32));
    $pdo->prepare(
        'INSERT INTO user_sessions (user_id, token_hash, ip_address, user_agent, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))'
    )->execute([$sellerId, $sessionTokenHash, '127.0.0.1', 'suspension smoke test']);

    $beforeCounts = admin_account_status_counts($pdo);
    admin_change_account_suspension($adminId, $sellerId, true, 'Smoke test suspension reason');

    $account = $pdo->prepare('SELECT is_active FROM users WHERE user_id = ?');
    $account->execute([$sellerId]);
    if ((int) $account->fetchColumn() !== 0) {
        throw new RuntimeException('The target account was not suspended.');
    }
    $session = $pdo->prepare('SELECT revoked_at FROM user_sessions WHERE token_hash = ?');
    $session->execute([$sessionTokenHash]);
    if (!$session->fetchColumn()) {
        throw new RuntimeException('Suspending the account did not revoke its active session.');
    }

    $audit = $pdo->prepare(
        "SELECT action, new_values FROM audit_logs
          WHERE table_name = 'users' AND record_id = ?
          ORDER BY audit_id DESC LIMIT 1"
    );
    $audit->execute([$sellerId]);
    $suspensionLog = $audit->fetch();
    $suspensionValues = $suspensionLog ? json_decode($suspensionLog['new_values'], true) : null;
    if (!$suspensionLog || $suspensionLog['action'] !== 'SUSPEND_ACCOUNT'
        || ($suspensionValues['reason'] ?? null) !== 'Smoke test suspension reason') {
        throw new RuntimeException('Suspension audit history did not preserve the actor action and reason.');
    }
    if (admin_account_status_counts($pdo)['suspended'] !== $beforeCounts['suspended'] + 1) {
        throw new RuntimeException('The suspended-account summary count did not update.');
    }

    admin_change_account_suspension($adminId, $sellerId, false);
    $account->execute([$sellerId]);
    if ((int) $account->fetchColumn() !== 1) {
        throw new RuntimeException('The suspended account was not reactivated.');
    }
    if (admin_account_status_counts($pdo)['suspended'] !== $beforeCounts['suspended']) {
        throw new RuntimeException('The suspended-account summary count did not return to its original value.');
    }

    $countActiveAdmins = static function () use ($pdo): int {
        return (int) $pdo->query(
        "SELECT COUNT(*) FROM users
          WHERE user_role = 'admin' AND is_active = 1
            AND deleted_at IS NULL AND deletion_requested_at IS NULL"
        )->fetchColumn();
    };
    $activeAdminsBefore = $countActiveAdmins();
    admin_change_account_suspension($adminId, $secondAdminId, true, 'Administrator access test');
    if ($countActiveAdmins() !== $activeAdminsBefore - 1) {
        throw new RuntimeException('Suspending an administrator did not leave other active administrators unchanged.');
    }
    admin_change_account_suspension($adminId, $secondAdminId, false);
    if ($countActiveAdmins() !== $activeAdminsBefore) {
        throw new RuntimeException('Reactivating an administrator did not restore the active administrator count.');
    }

    start_user_session($adminId);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    require __DIR__ . '/../admin_users.php';
    $adminPage = ob_get_clean();
    if (strpos($adminPage, 'Manage user accounts') === false
        || strpos($adminPage, 'Apply filters') === false) {
        throw new RuntimeException('The admin user-management page did not render its account controls.');
    }
    revoke_current_user_session();

    foreach ([
        [$adminId, $adminId, true, 'Self suspension should be rejected.'],
        [$adminId, $legacyInactiveId, false, 'A legacy inactive account should not be reactivated here.'],
        [$adminId, $deletionPendingId, true, 'An account pending deletion should not be suspended here.'],
    ] as [$actorId, $targetId, $suspend, $failureMessage]) {
        try {
            admin_change_account_suspension($actorId, $targetId, $suspend, $suspend ? 'Guard test' : null);
            throw new RuntimeException($failureMessage);
        } catch (InvalidArgumentException $expected) {
        }
    }

    fwrite(STDOUT, "Admin suspension, session revocation, guarded reactivation, and audit history passed.\n");
} finally {
    if ($userIds) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $pdo->prepare("DELETE FROM user_sessions WHERE user_id IN ($placeholders)")->execute($userIds);
        $pdo->prepare(
            "DELETE FROM audit_logs
              WHERE (table_name = 'users' AND record_id IN ($placeholders))
                 OR user_id IN ($placeholders)"
        )->execute(array_merge($userIds, $userIds));
        $pdo->prepare("DELETE FROM users WHERE user_id IN ($placeholders)")->execute($userIds);
    }
}
