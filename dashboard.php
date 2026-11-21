<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin_accounts.php';
require_login();

$user = current_user();
$role = $user['user_role'];
$pdo  = db();
$adminAccountCounts = $role === 'admin' ? admin_account_status_counts($pdo) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo->prepare(
        "UPDATE notifications
            SET is_read = 1, read_at = NOW()
          WHERE user_id = ? AND is_read = 0
            AND notification_type <> 'login_alert'"
    )
        ->execute([$user['user_id']]);
    redirect('dashboard.php');
}

// Request counts by status, depending on role
$counts = [];
$params = [];
if ($role === 'admin') {
    $where = '1 = 1';
} elseif ($role === 'buyer') {
    $where = 'accepted_buyer_id = ?';
    $params[] = $user['user_id'];
} else {
    $where = 'seller_id = ?';
    $params[] = $user['user_id'];
}
$st = $pdo->prepare("SELECT request_status, COUNT(*) AS c FROM collection_requests WHERE $where GROUP BY request_status");
$st->execute($params);
foreach ($st->fetchAll() as $row) {
    $counts[$row['request_status']] = (int) $row['c'];
}

$st = $pdo->prepare(
    "SELECT * FROM notifications
      WHERE user_id = ? AND notification_type <> 'login_alert'
      ORDER BY notification_id DESC LIMIT 8"
);
$st->execute([$user['user_id']]);
$notes = $st->fetchAll();

$pageTitle = 'Dashboard';
$roleLabels = ['seller' => 'Seller', 'buyer' => 'Junkshop / Buyer', 'admin' => 'Administrator'];
$summaryStatuses = $role === 'admin'
    ? ['pending' => 'Waiting for a buyer', 'buyer_proposed' => 'Proposals to review', 'awaiting_approval' => 'Seller review', 'completed' => 'Completed']
    : ($role === 'buyer'
        ? ['buyer_proposed' => 'Proposals awaiting seller', 'accepted' => 'Accepted pickups', 'awaiting_approval' => 'Seller review', 'completed' => 'Completed pickups']
        : ['pending' => 'Pending requests', 'buyer_proposed' => 'Buyer proposals', 'awaiting_approval' => 'Seller review', 'completed' => 'Completed pickups']);
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <div><span class="eyebrow"><?= e($roleLabels[$role] ?? ucfirst($role)) ?> workspace</span><h1>Welcome, <?= e($user['display_name']) ?></h1><p class="muted">Here’s an overview of your KALAKAL activity.</p></div>
    <?php if ($role === 'seller'): ?>
        <div class="actions">
            <a class="btn" href="<?= e(url('request_new.php')) ?>">＋ Request a collection</a>
            <a class="btn btn-outline" href="<?= e(url('trades.php')) ?>">Inventory trades</a>
            <a class="btn btn-outline" href="<?= e(url('earnings.php')) ?>">Earnings</a>
        </div>
    <?php elseif ($role === 'buyer'): ?>
        <div class="actions">
            <a class="btn" href="<?= e(url('collections.php')) ?>">View my collections</a>
            <a class="btn btn-outline" href="<?= e(url('trades.php')) ?>">Trade offers</a>
        </div>
    <?php elseif ($role === 'admin'): ?>
        <div class="actions">
            <a class="btn" href="<?= e(url('requests.php')) ?>">Review collection requests</a>
            <a class="btn btn-outline" href="<?= e(url('admin_reports.php')) ?>">View reports</a>
        </div>
    <?php endif; ?>
</div>

<section class="stats" aria-label="Activity summary">
    <?php foreach ($summaryStatuses as $s => $label): ?>
        <div class="stat">
            <div class="stat-num"><?= (int) ($counts[$s] ?? 0) ?></div>
            <div class="stat-label"><?= e($label) ?></div>
        </div>
    <?php endforeach; ?>
    <div class="stat">
        <div class="stat-num"><?= count(array_filter($notes, static fn($n) => !(int) $n['is_read'])) ?></div>
        <div class="stat-label">Unread notifications</div>
    </div>
</section>

<?php if ($role === 'admin'): ?>
<section class="card">
    <div class="row-between">
        <h2>User accounts</h2>
        <a class="btn btn-small" href="<?= e(url('admin_users.php')) ?>">Manage accounts</a>
    </div>
    <p class="muted">
        <?= (int) $adminAccountCounts['active'] ?> active ·
        <?= (int) $adminAccountCounts['suspended'] ?> suspended ·
        <?= (int) $adminAccountCounts['deletion_requested'] ?> pending deletion
    </p>
</section>
<?php endif; ?>

<?php if ($role === 'buyer'): ?>
    <div class="card empty-state">
        <span class="eyebrow">Junkshop workspace</span>
        <h2>Welcome to your buyer account</h2>
        <p>Review collection requests from waste owners in any city and keep your buying price for each type of waste material up to date.</p>
        <p class="dashboard-action">
            <a class="btn" href="<?= e(url('buyer_requests.php')) ?>">View collection requests</a>
            <a class="btn btn-outline" href="<?= e(url('prices.php')) ?>">Manage my buying prices</a>
        </p>
    </div>
<?php endif; ?>

<?php if ($role === 'seller'):
    $latestRequestQuery = $pdo->prepare(
        "SELECT request_id, request_status FROM collection_requests
          WHERE seller_id = ? AND request_status NOT IN ('cancelled', 'rejected')
          ORDER BY request_id DESC LIMIT 1"
    );
    $latestRequestQuery->execute([(int) $user['user_id']]);
    $latestRequest = $latestRequestQuery->fetch();
    $cmp = $latestRequest
        ? compare_junkshops((int) $user['user_id'], (int) $latestRequest['request_id'])
        : null;
?>
<section class="card">
    <h2>Estimated junkshop payouts</h2>
    <?php if (!$cmp): ?>
        <p class="muted">Create a pickup request to compare offers for the materials and location you selected.</p>
        <a class="btn" href="<?= e(url('request_new.php')) ?>">Request a collection</a>
    <?php elseif (!$cmp['materials']): ?>
        <p class="muted">Request #<?= (int) $cmp['requestId'] ?> has no active material estimates to compare.</p>
    <?php else: ?>
        <p class="muted">Current-price estimates for the selected materials in request #<?= (int) $cmp['requestId'] ?>. Each shop must have a compatible price for every selected material. Final amounts are negotiated and confirmed at pickup.</p>
        <p class="muted">For each material, the estimate uses the compatible unit price that gives the highest estimated line value; it does not add prices for unrelated materials.</p>
        <p><a class="btn btn-outline" href="<?= e(url('prices.php?request_id=' . (int) $cmp['requestId'])) ?>">View material-by-material comparison</a></p>
        <?php if ($cmp['missingPrices'] > 0): ?>
            <p class="muted"><?= (int) $cmp['missingPrices'] ?> junkshop(s) were excluded because they do not have a price for every selected material.</p>
        <?php endif; ?>
        <?php if (!$cmp['inCity']): ?>
            <p class="muted">No nearby junkshop currently has compatible prices for every selected material.</p>
        <?php endif; ?>
        <?php foreach (['inCity' => 'Same municipality', 'outside' => 'Outside municipality'] as $group => $heading): ?>
            <?php if ($cmp[$group]): ?>
                <h3><?= e($heading) ?></h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>Junkshop</th><th>Estimated materials</th><th>Gross estimate</th><th>Pickup charge</th><th>Estimated net</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($cmp[$group], 0, 6) as $shop): ?>
                        <tr>
                            <td><strong><?= e($shop['name']) ?></strong><br><small class="muted"><?= e(trim($shop['barangay'] . ', ' . $shop['city'], ', ')) ?><?= !empty($shop['same_barangay']) ? ' · your barangay' : '' ?></small></td>
                            <td><small><?php foreach ($shop['lines'] as $i => $line): ?><?= $i ? '<br>' : '' ?><?= e($line['material']) ?>: <?= e(number_format($line['quantity'], 3)) ?> <?= e($line['unit']) ?> × <?= e(peso($line['unit_price'])) ?> = <?= e(peso($line['value'])) ?><?php endforeach; ?></small>
                                <?php if ($shop['km'] !== null): ?><br><small class="muted">Distance is straight-line (Haversine), not road distance.</small><?php endif; ?>
                            </td>
                            <td><?= e(peso($shop['gross'])) ?></td>
                            <td><?= e(peso($shop['charge'])) ?><?php if ($shop['km'] !== null): ?><br><small class="muted"><?= e(number_format($shop['km'], 1)) ?> km estimate</small><?php endif; ?></td>
                            <td><strong><?= e(peso($shop['net'])) ?></strong></td>
                            <td><a class="btn btn-small" href="<?= e(url('request_new.php?buyer_id=' . (int) $shop['id'])) ?>">Request pickup</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($cmp['unknownDistance'] > 0): ?>
            <p class="muted"><?= (int) $cmp['unknownDistance'] ?> outside junkshop(s) were not ranked because pickup coordinates are missing.</p>
        <?php endif; ?>
    <?php endif; ?>
    <p><a href="<?= e(url('prices.php' . ($cmp ? '?request_id=' . (int) $cmp['requestId'] : ''))) ?>">See current junkshop price lists</a></p>
</section>
<?php endif; ?>

<section class="card" id="notifications">
    <div class="row-between">
        <h2>Notifications</h2>
        <?php if ($notes): ?>
        <form method="post" class="inline"><?= csrf_field() ?>
            <button class="btn btn-small btn-outline" type="submit">Mark all as read</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if (!$notes): ?>
        <div class="empty-state"><p>No notifications yet. Updates about your KALAKAL activity will appear here.</p></div>
    <?php else: ?>
        <ul class="notes">
        <?php foreach ($notes as $n): ?>
            <li class="<?= $n['is_read'] ? '' : 'unread' ?>">
                <strong><?= e($n['title']) ?></strong>
                <span><?= e($n['message']) ?></span>
                <small><?= e(fmt_date($n['created_at'])) ?></small>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
