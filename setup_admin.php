<?php
/**
 * ONE-TIME SETUP: creates the first administrator account.
 * It refuses to run once an admin exists.
 * >>> DELETE THIS FILE after you have created your admin. <<<
 */
require_once __DIR__ . '/includes/bootstrap.php';

$pdo = db();
$exists = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE user_role = 'admin'")->fetchColumn() > 0;
$errors = [];
$done = false;

if (!$exists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name  = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');

    if ($name === '')                                $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Valid email is required.';
    if (strlen($pass) < 10)                          $errors[] = 'Admin password must be at least 10 characters.';

    if (!$errors) {
        try {
            [$firstName, $middleName, $lastName, $suffix] = split_person_name($name);
        } catch (InvalidArgumentException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    if (!$errors) {
        $pdo->prepare(
            "INSERT INTO users (first_name, middle_name, last_name, suffix, email, password_hash, user_role, email_verified_at, privacy_consent_at)
             VALUES (?, ?, ?, ?, ?, ?, 'admin', NOW(), NOW())"
        )->execute([$firstName, $middleName, $lastName, $suffix, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        $done = true;
        $exists = true;
    }
}
$pageTitle = 'Setup Admin';
require __DIR__ . '/includes/header.php';
?>
<div class="card narrow">
    <h1>Create first admin</h1>
    <?php if ($done): ?>
        <div class="alert alert-success">Admin created. <strong>Now delete setup_admin.php</strong> from your project folder, then <a href="<?= e(url('login.php')) ?>">log in</a>.</div>
    <?php elseif ($exists): ?>
        <div class="alert alert-error">An admin already exists. Delete this file (setup_admin.php).</div>
    <?php else: ?>
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post">
            <?= csrf_field() ?>
            <label>Full name <input type="text" name="full_name" required></label>
            <label>Email <input type="email" name="email" required></label>
            <label>Password (min. 10 chars)
                <span class="password-field">
                    <input id="admin-password" type="password" name="password" required minlength="10">
                    <button class="password-toggle" type="button" aria-controls="admin-password" aria-label="Show password" aria-pressed="false">
                        <svg class="eye-icon eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-icon eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15.8 15.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1 0 2-.2 2.8-.5"/></svg>
                    </button>
                </span>
            </label>
            <button class="btn" type="submit">Create admin</button>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
