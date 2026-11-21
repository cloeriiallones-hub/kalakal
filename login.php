<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    redirect('dashboard.php');
}

$pageTitle = 'Login';
$errors = [];
$email = '';
// A valid-looking bcrypt hash used so unknown emails take the same time as known ones
const DUMMY_HASH = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $pdo      = db();

    // 1) Per-IP throttle
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip_address = ? AND was_successful = 0
            AND attempted_at > (NOW() - INTERVAL ' . (int) LOCKOUT_MINUTES . ' MINUTE)'
    );
    $st->execute([client_ip()]);

    if ((int) $st->fetchColumn() >= 20) {
        $errors[] = 'Too many failed attempts from your network. Please wait a few minutes.';
    } else {
        // 2) Look the user up
        $st = $pdo->prepare(
            'SELECT *, (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
               FROM users WHERE email = ? AND deleted_at IS NULL'
        );
        $st->execute([$email]);
        $user = $st->fetch();

        $passwordOk = password_verify($password, $user['password_hash'] ?? DUMMY_HASH);

        if ($user && (int) $user['is_locked'] === 1) {
            $errors[] = 'This account is temporarily locked. Please try again in ' . LOCKOUT_MINUTES . ' minutes.';
        } elseif ($user && !empty($user['deletion_requested_at']) && $passwordOk) {
            $restore = $pdo->prepare(
                'UPDATE users
                    SET is_active = 1, deletion_requested_at = NULL,
                        failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW()
                  WHERE user_id = ? AND is_active = 0 AND deleted_at IS NULL
                    AND deletion_requested_at > (NOW() - INTERVAL 24 HOUR)'
            );
            $restore->execute([$user['user_id']]);
            if ($restore->rowCount() === 1) {
                revoke_user_sessions((int) $user['user_id']);
                $user['is_active'] = 1;
                $user['deletion_requested_at'] = null;
                try {
                    complete_user_login((int) $user['user_id']);
                    flash('success', 'Your account deletion was cancelled and your account has been restored.');
                    redirect('dashboard.php');
                } catch (RuntimeException $ex) {
                    error_log($ex->getMessage());
                    $errors[] = 'Your account was restored, but a secure session could not be started. Please log in again.';
                }
            }
            if (!$errors) {
                log_login_attempt($email, (int) $user['user_id'], false);
                $errors[] = 'The 24-hour recovery window has expired. This account is inactive and cannot be restored through login. Contact your system administrator.';
            }
        } elseif ($user && (int) $user['is_active'] === 1 && $passwordOk) {
            // ---- SUCCESS ----
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
            }

            try {
                complete_user_login((int) $user['user_id']);
                redirect('dashboard.php');
            } catch (RuntimeException $ex) {
                error_log($ex->getMessage());
                $errors[] = 'Could not establish a secure login session. Please try again.';
            }
        } else {
            // ---- FAILURE (same message for every cause) ----
            $failure = $pdo->prepare('CALL sp_record_failed_login(?, ?)');
            $failure->execute([$email, client_ip()]);
            $failure->closeCursor();
            $errors[] = 'Invalid email or password.';
        }
    }
}
require __DIR__ . '/includes/header.php';
?>
<section class="auth-layout">
    <div class="auth-visual">
        <img src="<?= e(url('assets/images/register-illustration.png')) ?>" alt="Recyclable bottles, cans, cardboard, paper, and recycling symbol">
    </div>
    <div class="card auth-card">
        <div class="auth-intro"><span class="eyebrow">Welcome back</span><h1>Log in to KALAKAL</h1><p>Manage your collection requests and recyclable materials.</p></div>
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" class="login-form">
            <?= csrf_field() ?>
            <label>Email address
                <input type="email" name="email" value="<?= e($email) ?>" required autofocus>
            </label>
            <label>Password
                <span class="password-field">
                    <input id="login-password" type="password" name="password" required autocomplete="current-password">
                    <button class="password-toggle" type="button" aria-controls="login-password" aria-label="Show password" aria-pressed="false">
                        <svg class="eye-icon eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-icon eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15.8 15.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1 0 2-.2 2.8-.5"/></svg>
                    </button>
                </span>
            </label>
            <button class="btn form-submit" type="submit">Log in</button>
        </form>
        <p class="muted auth-footnote"><a href="<?= e(url('forgot_password.php')) ?>">Forgot your password?</a></p>
        <p class="muted auth-footnote">New to KALAKAL? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
