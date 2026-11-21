<?php
declare(strict_types=1);

function absolute_url(string $path): string
{
    return APP_ORIGIN . url($path);
}

function start_user_session(int $userId): void
{
    $pdo = db();
    $token = bin2hex(random_bytes(32));
    $minutes = session_lifetime_minutes();
    $insert = $pdo->prepare(
        'INSERT INTO user_sessions
            (user_id, token_hash, ip_address, user_agent, last_used_at, expires_at)
         VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ' . $minutes . ' MINUTE))'
    );
    $insert->execute([
        $userId,
        hash('sha256', $token),
        client_ip(),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    if (!session_regenerate_id(true)) {
        $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);
        throw new RuntimeException('Could not establish a secure login session.');
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['session_token'] = $token;
    $_SESSION['last_activity'] = time();
}

function revoke_current_user_session(): void
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $token = $_SESSION['session_token'] ?? '';
    if ($userId > 0 && is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
        $st = db()->prepare(
            'UPDATE user_sessions SET revoked_at = NOW()
              WHERE user_id = ? AND token_hash = ? AND revoked_at IS NULL'
        );
        $st->execute([$userId, hash('sha256', $token)]);
    }
}

function revoke_user_sessions(int $userId, ?string $exceptTokenHash = null): void
{
    if ($exceptTokenHash !== null) {
        $st = db()->prepare(
            'UPDATE user_sessions SET revoked_at = NOW()
              WHERE user_id = ? AND revoked_at IS NULL AND token_hash <> ?'
        );
        $st->execute([$userId, $exceptTokenHash]);
        return;
    }

    $st = db()->prepare(
        'UPDATE user_sessions SET revoked_at = NOW()
          WHERE user_id = ? AND revoked_at IS NULL'
    );
    $st->execute([$userId]);
}

function auth_minutes_setting(string $key, int $default, int $maximum): int
{
    $st = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $st->execute([$key]);
    $value = $st->fetchColumn();
    $minutes = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => $maximum],
    ]);
    if ($minutes === false) {
        error_log('Invalid or missing ' . $key . ' setting; using ' . $default . ' minutes.');
        return $default;
    }
    return (int) $minutes;
}

function allow_auth_email_request(string $event, string $email): bool
{
    if (!in_array($event, ['password_reset', 'verification'], true)) {
        throw new InvalidArgumentException('Unsupported account email event.');
    }

    $pdo = db();
    $emailHash = hash('sha256', strtolower(trim($email)));
    $ipLimit = $pdo->prepare(
        'SELECT COUNT(*) FROM auth_email_attempts
          WHERE event_type = ? AND ip_address = ?
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $ipLimit->execute([$event, client_ip()]);
    $emailLimit = $pdo->prepare(
        'SELECT COUNT(*) FROM auth_email_attempts
          WHERE event_type = ? AND email_hash = ?
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $emailLimit->execute([$event, $emailHash]);
    if ((int) $ipLimit->fetchColumn() >= 10 || (int) $emailLimit->fetchColumn() >= 3) {
        return false;
    }

    $insert = $pdo->prepare(
        'INSERT INTO auth_email_attempts (event_type, email_hash, ip_address) VALUES (?, ?, ?)'
    );
    $insert->execute([$event, $emailHash, client_ip()]);
    return true;
}

function create_password_reset(int $userId): void
{
    require_once __DIR__ . '/smtp.php';
    smtp_validate_configuration();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT email, first_name
               FROM users
              WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL
              FOR UPDATE'
        );
        $st->execute([$userId]);
        $user = $st->fetch();
        if (!$user) {
            $pdo->rollBack();
            return;
        }

        $recent = $pdo->prepare(
            'SELECT COUNT(*) FROM password_resets
              WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
        );
        $recent->execute([$userId]);
        if ((int) $recent->fetchColumn() > 0) {
            throw new RuntimeException('Please wait a minute before requesting another reset email.');
        }

        $pdo->prepare(
            'UPDATE password_resets SET used_at = NOW()
              WHERE user_id = ? AND used_at IS NULL'
        )->execute([$userId]);

        $token = bin2hex(random_bytes(32));
        $minutes = auth_minutes_setting('password_reset_minutes', 30, 1440);
        $insert = $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, requested_ip, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $minutes . ' MINUTE))'
        );
        $insert->execute([$userId, hash('sha256', $token), client_ip()]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $link = absolute_url('reset_password.php?token=' . rawurlencode($token));
    try {
        smtp_send_message(
            (string) $user['email'],
            'Reset your KALAKAL password',
            'Hello ' . (string) $user['first_name'] . ",\r\n\r\n"
                . "Set a new password by opening this link:\r\n$link\r\n\r\n"
                . 'This link expires in ' . $minutes . ' minutes. If you did not request a reset, ignore this message.'
        );
    } catch (RuntimeException $exception) {
        $pdo->prepare(
            'UPDATE password_resets SET used_at = NOW()
              WHERE token_hash = ? AND used_at IS NULL'
        )->execute([hash('sha256', $token)]);
        throw $exception;
    }
}

function user_by_email(string $email): ?array
{
    $st = db()->prepare(
        'SELECT user_id, email, first_name, user_role, is_active, deleted_at
           FROM users WHERE email = ? LIMIT 1'
    );
    $st->execute([$email]);
    $user = $st->fetch();
    return $user ?: null;
}

function complete_user_login(int $userId): void
{
    unset($_SESSION['pending_admin_mfa']);
    $login = db()->prepare('CALL sp_record_login(?, ?, ?)');
    $login->execute([
        $userId,
        client_ip(),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    $login->closeCursor();
    start_user_session($userId);
}
