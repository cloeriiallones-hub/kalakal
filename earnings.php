<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(['seller']);

$user = current_user();
$uid = (int) $user['user_id'];
$pdo = db();

$collectionSummaryQuery = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN cp.payment_status = 'paid' THEN cp.amount_paid ELSE 0 END), 0) AS confirmed_total,
            COALESCE(SUM(CASE WHEN cp.payment_status = 'pending' THEN cp.amount_paid ELSE 0 END), 0) AS pending_total,
            SUM(CASE WHEN cp.payment_status = 'paid' THEN 1 ELSE 0 END) AS confirmed_count
       FROM collection_payments cp
       JOIN collections c ON c.collection_id = cp.collection_id
       JOIN collection_requests r ON r.request_id = c.request_id
      WHERE r.seller_id = ? AND r.request_status = 'completed'
        AND c.collection_status = 'completed'"
);
$collectionSummaryQuery->execute([$uid]);
$collectionSummary = $collectionSummaryQuery->fetch();

$tradeSummaryQuery = $pdo->prepare(
    "SELECT COALESCE(SUM(v.total_paid), 0) AS confirmed_total,
            COALESCE(SUM(p.pending_total), 0) AS pending_total,
            COUNT(DISTINCT CASE WHEN v.total_paid > 0 THEN t.trade_id END) AS confirmed_count
       FROM trades t
       JOIN v_trade_totals v ON v.trade_id = t.trade_id
       LEFT JOIN (
            SELECT trade_id, SUM(amount_paid) AS pending_total
              FROM transaction_payments
             WHERE payment_status = 'pending'
             GROUP BY trade_id
       ) p ON p.trade_id = t.trade_id
      WHERE t.seller_id = ?"
);
$tradeSummaryQuery->execute([$uid]);
$tradeSummary = $tradeSummaryQuery->fetch();

$collectionHistoryQuery = $pdo->prepare(
    "SELECT cp.payment_id, cp.collection_id, cp.amount_paid, cp.payment_method,
            cp.payment_status, cp.created_at,
            COALESCE(NULLIF(TRIM(bp.company_name), ''),
                     CONCAT_WS(' ', buyer.first_name, buyer.last_name)) AS buyer_name
       FROM collection_payments cp
       JOIN collections c ON c.collection_id = cp.collection_id
       JOIN collection_requests r ON r.request_id = c.request_id
       JOIN users buyer ON buyer.user_id = cp.reported_by
       LEFT JOIN buyer_profiles bp ON bp.user_id = buyer.user_id
      WHERE r.seller_id = ? AND r.request_status = 'completed'
        AND c.collection_status = 'completed'
      ORDER BY cp.payment_id DESC
      LIMIT 25"
);
$collectionHistoryQuery->execute([$uid]);
$collectionHistory = $collectionHistoryQuery->fetchAll();

$tradeHistoryQuery = $pdo->prepare(
    "SELECT p.payment_id, p.trade_id, p.amount_paid, p.payment_method,
            p.payment_status, p.reference_number, p.created_at,
            COALESCE(NULLIF(TRIM(bp.company_name), ''),
                     CONCAT_WS(' ', buyer.first_name, buyer.last_name)) AS buyer_name
       FROM transaction_payments p
       JOIN trades t ON t.trade_id = p.trade_id
       JOIN users buyer ON buyer.user_id = t.buyer_id
       LEFT JOIN buyer_profiles bp ON bp.user_id = buyer.user_id
      WHERE t.seller_id = ?
      ORDER BY p.payment_id DESC
      LIMIT 25"
);
$tradeHistoryQuery->execute([$uid]);
$tradeHistory = $tradeHistoryQuery->fetchAll();

$pageTitle = 'Earnings';
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <div>
        <span class="eyebrow">Seller reports</span>
        <h1>Earnings</h1>
        <p class="muted">Only payments confirmed as received count as earnings. Pending reports are shown separately.</p>
    </div>
    <a class="btn btn-outline" href="<?= e(url('trades.php')) ?>">Manage inventory trades</a>
</div>

<section class="stats" aria-label="Confirmed and pending earnings">
    <div class="stat">
        <div class="stat-num"><?= e(peso($collectionSummary['confirmed_total'])) ?></div>
        <div class="stat-label">Confirmed collection payments</div>
    </div>
    <div class="stat">
        <div class="stat-num"><?= e(peso($collectionSummary['pending_total'])) ?></div>
        <div class="stat-label">Collection payments awaiting confirmation</div>
    </div>
    <div class="stat">
        <div class="stat-num"><?= e(peso($tradeSummary['confirmed_total'])) ?></div>
        <div class="stat-label">Confirmed trade payments</div>
    </div>
    <div class="stat">
        <div class="stat-num"><?= e(peso($tradeSummary['pending_total'])) ?></div>
        <div class="stat-label">Trade payments awaiting confirmation</div>
    </div>
</section>

<section class="card">
    <h2>Collection payment history</h2>
    <?php if (!$collectionHistory): ?>
        <div class="empty-state"><p>No collection payments have been reported for completed pickups.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Payment</th><th>Collection</th><th>Junkshop</th><th>Date</th><th>Method</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($collectionHistory as $payment): ?>
                    <tr>
                        <td>#<?= (int) $payment['payment_id'] ?></td>
                        <td>#<?= (int) $payment['collection_id'] ?></td>
                        <td><?= e($payment['buyer_name']) ?></td>
                        <td><?= e(fmt_date($payment['created_at'])) ?></td>
                        <td><?= e(ucfirst($payment['payment_method'])) ?></td>
                        <td><?= e(peso($payment['amount_paid'])) ?></td>
                        <td><span class="badge badge-<?= e($payment['payment_status']) ?>"><?= e(ucfirst($payment['payment_status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">Showing the latest 25 reports. Confirmed total: <?= e(peso($collectionSummary['confirmed_total'])) ?> · Pending: <?= e(peso($collectionSummary['pending_total'])) ?>.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Inventory trade payment history</h2>
    <?php if (!$tradeHistory): ?>
        <div class="empty-state"><p>No inventory trade payments have been reported.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Payment</th><th>Trade</th><th>Junkshop</th><th>Date</th><th>Method</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($tradeHistory as $payment): ?>
                    <tr>
                        <td>#<?= (int) $payment['payment_id'] ?></td>
                        <td>#<?= (int) $payment['trade_id'] ?></td>
                        <td><?= e($payment['buyer_name']) ?></td>
                        <td><?= e(fmt_date($payment['created_at'])) ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></td>
                        <td><?= e(peso($payment['amount_paid'])) ?></td>
                        <td><span class="badge badge-<?= e($payment['payment_status']) ?>"><?= e(ucfirst($payment['payment_status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">Showing the latest 25 reports. Confirmed total: <?= e(peso($tradeSummary['confirmed_total'])) ?> · Pending: <?= e(peso($tradeSummary['pending_total'])) ?>.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
