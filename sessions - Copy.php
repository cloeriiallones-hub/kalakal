<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();
$userId = (int) $user['user_id'];
$currentToken = (string) ($_SESSION['session_token'] ?? '');
$currentHash = hash('sha256', $currentToken);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$sessionId || $sessionId < 1) {
        $error = 'Choose a valid session.';
    } else {
        $pdo = db();
        $st = $pdo->prepare(
            'SELECT token_hash FROM user_sessions
              WHERE session_id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > NOW()'
        );
        $st->execute([$sessionId, $userId]);
        $tokenHash = $st->fetchColumn();
        if (!is_string($tokenHash)) {
            $error = 'That session is no longer active.';
        } else {
            $revoke = $pdo->prepare(
                'UPDATE user_sessions SET revoked_at = NOW()
                  WHERE session_id = ? AND user_id = ? AND revoked_at IS NULL'
            );
            $revoke->execute([$sessionId, $userId]);
            if ($revoke->rowCount() !== 1) {
                $error = 'That session could not be revoked because it changed. Refresh and try again.';
            } elseif (hash_equals($currentHash, $tokenHash)) {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                session_destroy();
                session_start();
                flash('success', 'This session was revoked.');
                redirect('login.php');
            } else {
                flash('success', 'The selected session was revoked.');
                redirect('sessions.php');
            }
        }
    }
}

$st = db()->prepare(
    'SELECT session_id, ip_address, user_agent, created_at, last_used_at, expires_at, token_hash
       FROM user_sessions
      WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
      ORDER BY last_used_at DESC, session_id DESC'
);
$st->execute([$userId]);
$sessions = $st->fetchAll();

$pageTitle = 'Active sessions';
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <div><span class="eyebrow">Account security</span><h1>Active sessions</h1></div>
</div>
<section class="card">
    <p class="muted">Sign out sessions you no longer recognize. Revocation takes effect on the session's next request.</p>
    <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$sessions): ?>
        <div class="empty-state"><p>No active sessions were found.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Session</th><th>Started</th><th>Last active</th><th>Expires</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($sessions as $session): ?>
                    <tr>
                        <td>
                            <strong><?= hash_equals($currentHash, (string) $session['token_hash']) ? 'This session' : 'Other session' ?></strong><br>
                            <small class="muted"><?= e((string) ($session['user_agent'] ?: 'Unknown device')) ?><br>IP <?= e((string) ($session['ip_address'] ?: 'unknown')) ?></small>
                        </td>
                        <td><?= e(fmt_date($session['created_at'])) ?></td>
                        <td><?= e(fmt_date($session['last_used_at'])) ?></td>
                        <td><?= e(fmt_date($session['expires_at'])) ?></td>
                        <td>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="session_id" value="<?= (int) $session['session_id'] ?>">
                                <button class="btn btn-small btn-outline" type="submit"
                                    onclick="return confirm('Revoke this session?')">Revoke</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
