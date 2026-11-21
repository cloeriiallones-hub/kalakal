<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    redirect('dashboard.php');
}

$pageTitle = 'Forgot password';
$email = '';
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $error = 'Enter a valid email address.';
    } else {
        require_once __DIR__ . '/includes/smtp.php';
        try {
            smtp_validate_configuration();
            if (!allow_auth_email_request('password_reset', $email)) {
                $error = 'Too many recovery requests. Please wait before trying again.';
            } else {
                $user = user_by_email($email);
                if ($user && (int) $user['is_active'] === 1 && $user['deleted_at'] === null) {
                    try {
                        create_password_reset((int) $user['user_id']);
                    } catch (PDOException $exception) {
                        error_log('Password reset request failed: ' . $exception->getMessage());
                    } catch (RuntimeException $exception) {
                        error_log('Password reset email failed: ' . $exception->getMessage());
                    }
                }
                $message = 'If an active account uses that email address, a password reset link will be sent. Check your inbox and spam folder.';
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'Could not process the recovery request. Please try again later.';
        } catch (RuntimeException $exception) {
            error_log('Password reset service unavailable: ' . $exception->getMessage());
            $error = 'Password recovery email is currently unavailable. Please contact the system administrator.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<section class="card narrow auth-card">
    <h1>Reset your password</h1>
    <p class="muted">Enter the email address on your account. If it is eligible, we will send a time-limited reset link.</p>
    <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="alert alert-info" role="status"><?= e($message) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Email address
            <input type="email" name="email" value="<?= e($email) ?>" maxlength="150" autocomplete="email" required>
        </label>
        <button class="btn" type="submit">Send reset link</button>
    </form>
    <p class="muted"><a href="<?= e(url('login.php')) ?>">Return to login</a></p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
