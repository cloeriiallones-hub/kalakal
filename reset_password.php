<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    redirect('dashboard.php');
}

$pageTitle = 'Choose a new password';
$token = strtolower(trim((string) ($_POST['token'] ?? $_GET['token'] ?? '')));
$validReset = false;
$error = '';

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = db()->prepare(
        'SELECT 1 FROM password_resets pr
          JOIN users u ON u.user_id = pr.user_id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
           AND u.is_active = 1 AND u.deleted_at IS NULL'
    );
    $st->execute([hash('sha256', $token)]);
    $validReset = (bool) $st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if (!$validReset) {
        $error = 'This reset link is invalid, expired, or already used. Request a new link.';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $error = 'Password must be between 8 and 72 bytes.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                'SELECT pr.reset_id, pr.user_id
                   FROM password_resets pr
                   JOIN users u ON u.user_id = pr.user_id
                  WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
                    AND u.is_active = 1 AND u.deleted_at IS NULL
                  FOR UPDATE'
            );
            $st->execute([hash('sha256', $token)]);
            $reset = $st->fetch();
            if (!$reset) {
                throw new RuntimeException('This reset link is invalid, expired, or already used. Request a new link.');
            }
            $userId = (int) $reset['user_id'];
            $pdo->prepare(
                'UPDATE users
                    SET password_hash = ?, email_verified_at = IFNULL(email_verified_at, NOW()),
                        failed_login_attempts = 0, locked_until = NULL
                  WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL'
            )->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            $pdo->prepare(
                'UPDATE password_resets SET used_at = NOW()
                  WHERE user_id = ? AND used_at IS NULL'
            )->execute([$userId]);
            revoke_user_sessions($userId);
            $pdo->commit();
            flash('success', 'Your password has been changed. Please log in with your new password.');
            redirect('login.php');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $error = 'Could not reset the password. Please try again.';
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<section class="card narrow auth-card">
    <h1>Choose a new password</h1>
    <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($validReset): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label>New password
            <input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required>
        </label>
        <label>Confirm new password
            <input type="password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required>
        </label>
        <button class="btn" type="submit">Change password</button>
    </form>
    <?php else: ?>
        <p class="muted">This reset link is invalid or expired.</p>
        <a class="btn" href="<?= e(url('forgot_password.php')) ?>">Request another reset link</a>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
