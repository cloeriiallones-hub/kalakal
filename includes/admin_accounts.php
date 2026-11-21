<?php
declare(strict_types=1);

function admin_account_status_counts(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT
            SUM(CASE WHEN u.is_active = 1 AND u.deletion_requested_at IS NULL THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN u.is_active = 0 AND u.deletion_requested_at IS NULL
                          AND latest.action = 'SUSPEND_ACCOUNT' THEN 1 ELSE 0 END) AS suspended_count,
            SUM(CASE WHEN u.is_active = 0 AND u.deletion_requested_at IS NULL
                          AND COALESCE(latest.action, '') <> 'SUSPEND_ACCOUNT' THEN 1 ELSE 0 END) AS inactive_count,
            SUM(CASE WHEN u.deletion_requested_at IS NOT NULL THEN 1 ELSE 0 END) AS deletion_count
           FROM users u
           LEFT JOIN (
                SELECT log.record_id, log.action
                  FROM audit_logs log
                  JOIN (
                        SELECT record_id, MAX(audit_id) AS audit_id
                          FROM audit_logs
                         WHERE table_name = 'users'
                           AND action IN ('SUSPEND_ACCOUNT', 'REACTIVATE_ACCOUNT')
                         GROUP BY record_id
                  ) latest_log ON latest_log.audit_id = log.audit_id
           ) latest ON latest.record_id = u.user_id
          WHERE u.deleted_at IS NULL"
    );
    $counts = $statement->fetch() ?: [];
    return [
        'active' => (int) ($counts['active_count'] ?? 0),
        'suspended' => (int) ($counts['suspended_count'] ?? 0),
        'inactive' => (int) ($counts['inactive_count'] ?? 0),
        'deletion_requested' => (int) ($counts['deletion_count'] ?? 0),
    ];
}

function admin_change_account_suspension(
    int $adminId,
    int $targetUserId,
    bool $suspend,
    ?string $reason = null
): void {
    if ($adminId < 1 || $targetUserId < 1) {
        throw new InvalidArgumentException('Choose a valid user account.');
    }

    $reason = $reason !== null ? trim($reason) : null;
    if ($suspend && ($reason === null || $reason === '' || mb_strlen($reason) > 500)) {
        throw new InvalidArgumentException('Enter a suspension reason of up to 500 characters.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $activeAdminIds = $pdo->query(
            "SELECT user_id FROM users
              WHERE user_role = 'admin' AND is_active = 1
                AND deleted_at IS NULL AND deletion_requested_at IS NULL
              ORDER BY user_id
              FOR UPDATE"
        )->fetchAll(PDO::FETCH_COLUMN);

        $actorQuery = $pdo->prepare(
            'SELECT user_role, is_active, deleted_at, deletion_requested_at
               FROM users WHERE user_id = ? FOR UPDATE'
        );
        $actorQuery->execute([$adminId]);
        $actor = $actorQuery->fetch();
        if (!$actor || $actor['user_role'] !== 'admin'
            || (int) $actor['is_active'] !== 1
            || $actor['deleted_at'] !== null
            || $actor['deletion_requested_at'] !== null) {
            throw new InvalidArgumentException('An active administrator account is required.');
        }
        if ($targetUserId === $adminId) {
            throw new InvalidArgumentException('You cannot suspend or reactivate your own account.');
        }

        $targetQuery = $pdo->prepare(
            'SELECT user_role, is_active, deleted_at, deletion_requested_at
               FROM users WHERE user_id = ? FOR UPDATE'
        );
        $targetQuery->execute([$targetUserId]);
        $target = $targetQuery->fetch();
        if (!$target || $target['deleted_at'] !== null) {
            throw new InvalidArgumentException('That user account is not available.');
        }
        if ($target['deletion_requested_at'] !== null) {
            throw new InvalidArgumentException('Accounts with a pending deletion request cannot be changed here.');
        }

        $oldActive = (int) $target['is_active'];
        if ($suspend) {
            if ($oldActive !== 1) {
                throw new InvalidArgumentException('That account is not currently active.');
            }
            if ($target['user_role'] === 'admin' && count($activeAdminIds) <= 1) {
                throw new InvalidArgumentException('The last active administrator cannot be suspended.');
            }

            $update = $pdo->prepare(
                'UPDATE users SET is_active = 0
                  WHERE user_id = ? AND is_active = 1
                    AND deleted_at IS NULL AND deletion_requested_at IS NULL'
            );
            $update->execute([$targetUserId]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('That account changed before it could be suspended.');
            }
            revoke_user_sessions($targetUserId);
            $action = 'SUSPEND_ACCOUNT';
            $newValues = [
                'is_active' => 0,
                'reason' => $reason,
            ];
        } else {
            if ($oldActive !== 0) {
                throw new InvalidArgumentException('That account is already active.');
            }
            $latestActionQuery = $pdo->prepare(
                "SELECT action FROM audit_logs
                  WHERE table_name = 'users' AND record_id = ?
                    AND action IN ('SUSPEND_ACCOUNT', 'REACTIVATE_ACCOUNT')
                  ORDER BY audit_id DESC LIMIT 1"
            );
            $latestActionQuery->execute([$targetUserId]);
            if ($latestActionQuery->fetchColumn() !== 'SUSPEND_ACCOUNT') {
                throw new InvalidArgumentException('This account was not suspended through the admin user-management page.');
            }

            $update = $pdo->prepare(
                'UPDATE users SET is_active = 1
                  WHERE user_id = ? AND is_active = 0
                    AND deleted_at IS NULL AND deletion_requested_at IS NULL'
            );
            $update->execute([$targetUserId]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('That account changed before it could be reactivated.');
            }
            $action = 'REACTIVATE_ACCOUNT';
            $newValues = ['is_active' => 1];
        }

        $oldJson = json_encode(['is_active' => $oldActive], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $newJson = json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($oldJson) || !is_string($newJson)) {
            throw new RuntimeException('The account status change could not be recorded.');
        }
        $pdo->prepare('SET @app_user_id = ?')->execute([$adminId]);
        $pdo->prepare(
            'INSERT INTO audit_logs
                (user_id, action, table_name, record_id, old_values, new_values, ip_address)
             VALUES (?, ?, \'users\', ?, ?, ?, ?)'
        )->execute([$adminId, $action, $targetUserId, $oldJson, $newJson, client_ip()]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
