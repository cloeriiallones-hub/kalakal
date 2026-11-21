<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin_accounts.php';
require_role(['admin']);

$admin = current_user();
$pdo = db();
$pageTitle = 'Manage users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $targetValue = $_POST['target_user_id'] ?? null;
    $targetUserId = filter_var($targetValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    try {
        if (!is_string($action) || !in_array($action, ['suspend', 'reactivate'], true)) {
            throw new InvalidArgumentException('Choose a valid account action.');
        }
        if ($targetUserId === false) {
            throw new InvalidArgumentException('Choose a valid user account.');
        }
        $reason = $_POST['reason'] ?? null;
        if ($reason !== null && !is_string($reason)) {
            throw new InvalidArgumentException('Enter a valid suspension reason.');
        }

        admin_change_account_suspension(
            (int) $admin['user_id'],
            (int) $targetUserId,
            $action === 'suspend',
            $reason
        );
        flash('success', $action === 'suspend' ? 'The account was suspended and its sessions were revoked.' : 'The account was reactivated.');
    } catch (InvalidArgumentException $exception) {
        flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log('Admin account status change failed: ' . $exception->getMessage());
        flash('error', 'The account status could not be changed. Please try again or contact support.');
    } catch (RuntimeException $exception) {
        error_log('Admin account status change failed: ' . $exception->getMessage());
        flash('error', 'The account status could not be changed. Please try again or contact support.');
    }

    redirect('admin_users.php');
}

$search = $_GET['q'] ?? '';
$search = is_string($search) ? trim(mb_substr($search, 0, 100)) : '';
$roleFilter = $_GET['role'] ?? 'all';
$roleFilter = is_string($roleFilter) && in_array($roleFilter, ['seller', 'buyer', 'admin'], true) ? $roleFilter : 'all';
$statusFilter = $_GET['status'] ?? 'all';
$statusFilter = is_string($statusFilter)
    && in_array($statusFilter, ['all', 'active', 'suspended', 'inactive', 'deletion_requested'], true)
    ? $statusFilter
    : 'all';

$where = ['u.deleted_at IS NULL'];
$params = [];
if ($search !== '') {
    $where[] = "(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name, u.suffix) LIKE ?
                  OR u.email LIKE ? OR CAST(u.user_id AS CHAR) = ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = $search;
}
if ($roleFilter !== 'all') {
    $where[] = 'u.user_role = ?';
    $params[] = $roleFilter;
}
switch ($statusFilter) {
    case 'active':
        $where[] = 'u.is_active = 1 AND u.deletion_requested_at IS NULL';
        break;
    case 'suspended':
        $where[] = "u.is_active = 0 AND u.deletion_requested_at IS NULL AND latest.action = 'SUSPEND_ACCOUNT'";
        break;
    case 'inactive':
        $where[] = "u.is_active = 0 AND u.deletion_requested_at IS NULL AND COALESCE(latest.action, '') <> 'SUSPEND_ACCOUNT'";
        break;
    case 'deletion_requested':
        $where[] = 'u.deletion_requested_at IS NOT NULL';
        break;
}

$accountsQuery = $pdo->prepare(
    "SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.suffix,
            u.email, u.user_role, u.is_active, u.deletion_requested_at, u.created_at,
            latest.action AS account_action, latest.new_values AS account_values,
            latest.created_at AS action_at
       FROM users u
       LEFT JOIN (
            SELECT log.record_id, log.action, log.new_values, log.created_at
              FROM audit_logs log
              JOIN (
                    SELECT record_id, MAX(audit_id) AS audit_id
                      FROM audit_logs
                     WHERE table_name = 'users'
                       AND action IN ('SUSPEND_ACCOUNT', 'REACTIVATE_ACCOUNT')
                     GROUP BY record_id
              ) latest_log ON latest_log.audit_id = log.audit_id
       ) latest ON latest.record_id = u.user_id
      WHERE " . implode(' AND ', $where) . '
      ORDER BY u.user_id DESC
      LIMIT 100'
);
$accountsQuery->execute($params);
$accounts = $accountsQuery->fetchAll();
$accountCounts = admin_account_status_counts($pdo);
$activeAdminCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM users
      WHERE user_role = 'admin' AND is_active = 1
        AND deleted_at IS NULL AND deletion_requested_at IS NULL"
)->fetchColumn();

require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <div>
        <span class="eyebrow">Administration</span>
        <h1>Manage user accounts</h1>
        <p class="muted">Suspend access when needed, or reactivate accounts suspended here. Suspensions revoke all active sessions.</p>
    </div>
</div>

<section class="stats" aria-label="Account summary">
    <div class="stat"><div class="stat-num"><?= $accountCounts['active'] ?></div><div class="stat-label">Active accounts</div></div>
    <div class="stat"><div class="stat-num"><?= $accountCounts['suspended'] ?></div><div class="stat-label">Suspended accounts</div></div>
    <div class="stat"><div class="stat-num"><?= $accountCounts['inactive'] ?></div><div class="stat-label">Other inactive accounts</div></div>
    <div class="stat"><div class="stat-num"><?= $accountCounts['deletion_requested'] ?></div><div class="stat-label">Pending deletion</div></div>
</section>

<section class="card">
    <form method="get" class="admin-user-filters">
        <label for="user-search">Search users
            <input id="user-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Name, email, or user ID">
        </label>
        <label for="user-role">Role
            <select id="user-role" name="role">
                <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>All roles</option>
                <option value="seller" <?= $roleFilter === 'seller' ? 'selected' : '' ?>>Seller</option>
                <option value="buyer" <?= $roleFilter === 'buyer' ? 'selected' : '' ?>>Junkshop / buyer</option>
                <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Administrator</option>
            </select>
        </label>
        <label for="user-status">Account status
            <select id="user-status" name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Other inactive</option>
                <option value="deletion_requested" <?= $statusFilter === 'deletion_requested' ? 'selected' : '' ?>>Pending deletion</option>
            </select>
        </label>
        <div class="admin-user-filter-actions">
            <button class="btn btn-small" type="submit">Apply filters</button>
            <a class="btn btn-small btn-outline" href="<?= e(url('admin_users.php')) ?>">Clear</a>
        </div>
    </form>

    <p class="muted">Accounts pending deletion are not eligible for suspension or reactivation. Only the first 100 matching accounts are shown.</p>
    <?php if (!$accounts): ?>
        <div class="empty-state"><p>No matching user accounts were found.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>User</th><th>Role</th><th>Status</th><th>Joined</th><th>Account action</th></tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $account):
                $displayName = trim(implode(' ', array_filter([
                    $account['first_name'],
                    $account['middle_name'],
                    $account['last_name'],
                    $account['suffix'],
                ], static fn($part) => $part !== null && $part !== '')));
                if ($account['deletion_requested_at'] !== null) {
                    $status = 'Pending deletion';
                    $statusClass = 'deletion-pending';
                } elseif ((int) $account['is_active'] === 1) {
                    $status = 'Active';
                    $statusClass = 'active';
                } elseif ($account['account_action'] === 'SUSPEND_ACCOUNT') {
                    $status = 'Suspended';
                    $statusClass = 'suspended';
                } else {
                    $status = 'Inactive';
                    $statusClass = 'inactive';
                }
                $accountValues = json_decode((string) ($account['account_values'] ?? ''), true);
                $suspensionReason = is_array($accountValues) && is_string($accountValues['reason'] ?? null)
                    ? $accountValues['reason']
                    : '';
            ?>
                <tr>
                    <td>
                        <strong><?= e($displayName) ?></strong><br>
                        <a href="mailto:<?= e($account['email']) ?>"><?= e($account['email']) ?></a><br>
                        <small class="muted">User #<?= (int) $account['user_id'] ?></small>
                    </td>
                    <td><?= e($account['user_role'] === 'buyer' ? 'Junkshop / buyer' : ucfirst($account['user_role'])) ?></td>
                    <td>
                        <span class="badge badge-<?= e($statusClass) ?>"><?= e($status) ?></span>
                        <?php if ($status === 'Suspended' && $suspensionReason !== ''): ?>
                            <br><small class="muted">Reason: <?= e($suspensionReason) ?></small>
                            <?php if ($account['action_at']): ?><br><small class="muted"><?= e(fmt_date($account['action_at'])) ?></small><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e(fmt_date($account['created_at'])) ?></td>
                    <td class="admin-user-action">
                        <?php if ((int) $account['user_id'] === (int) $admin['user_id']): ?>
                            <span class="muted">Your account</span>
                        <?php elseif ($account['deletion_requested_at'] !== null): ?>
                            <span class="muted">Pending deletion</span>
                        <?php elseif ((int) $account['is_active'] === 1 && $account['user_role'] === 'admin' && $activeAdminCount <= 1): ?>
                            <span class="muted">Last active administrator</span>
                        <?php elseif ((int) $account['is_active'] === 1): ?>
                            <details>
                                <summary class="btn btn-small btn-danger">Suspend</summary>
                                <form method="post" data-confirm-account-suspension>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="suspend">
                                    <input type="hidden" name="target_user_id" value="<?= (int) $account['user_id'] ?>">
                                    <label>Reason
                                        <textarea name="reason" maxlength="500" required></textarea>
                                    </label>
                                    <button class="btn btn-small btn-danger" type="submit">Confirm suspension</button>
                                </form>
                            </details>
                        <?php elseif ($account['account_action'] === 'SUSPEND_ACCOUNT'): ?>
                            <form method="post" class="inline" data-confirm-account-reactivation>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reactivate">
                                <input type="hidden" name="target_user_id" value="<?= (int) $account['user_id'] ?>">
                                <button class="btn btn-small" type="submit">Reactivate</button>
                            </form>
                        <?php else: ?>
                            <span class="muted">Not suspended here</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
