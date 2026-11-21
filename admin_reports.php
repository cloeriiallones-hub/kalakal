<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(['admin']);

$pdo = db();
$requestStatusCounts = [];
foreach ($pdo->query(
 'SELECT request_status, COUNT(*) AS status_count
 FROM collection_requests
GROUP BY request_status'
)->fetchAll() as $row) {
 $requestStatusCounts[$row['request_status']] = (int) $row['status_count'];
}

$collectionSummary = $pdo->query(
 "SELECT COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount_paid ELSE 0 END), 0) AS confirmed_total,
COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN amount_paid ELSE 0 END), 0) AS pending_total
 FROM collection_payments"
)->fetch();
$tradeSummary = $pdo->query(
 "SELECT COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount_paid ELSE 0 END), 0) AS confirmed_total,
COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN amount_paid ELSE 0 END), 0) AS pending_total
 FROM transaction_payments"
)->fetch();

$requestQuery = $pdo->query(
 "SELECT r.request_id, r.seller_id, r.request_status, r.requested_date,
r.barangay, r.city, r.preferred_schedule, r.confirmed_schedule,
CONCAT_WS(' ', s.first_name, s.last_name) AS seller_name,
COALESCE(NULLIF(TRIM(bp.company_name), ''),
CONCAT_WS(' ', b.first_name, b.last_name)) AS buyer_name,
c.collection_id, c.collection_status,
ct.final_total, cps.total_due, cps.paid_total, cps.pending_total, cps.payment_status
 FROM collection_requests r
 JOIN users s ON s.user_id = r.seller_id
 LEFT JOIN users b ON b.user_id = r.accepted_buyer_id
 LEFT JOIN buyer_profiles bp ON bp.user_id = r.accepted_buyer_id
 LEFT JOIN collections c ON c.request_id = r.request_id
 LEFT JOIN v_collection_totals ct ON ct.collection_id = c.collection_id
 LEFT JOIN v_collection_payment_status cps ON cps.collection_id = c.collection_id
ORDER BY r.request_id DESC
LIMIT 30"
);
$requests = $requestQuery->fetchAll();
foreach ($requests as &$request) {
 $request['best_estimate'] = null;
 if (!in_array($request['request_status'], ['cancelled', 'rejected'], true)) {
  $comparison = compare_junkshops((int) $request['seller_id'], (int) $request['request_id']);
  foreach (array_merge($comparison['inCity'], $comparison['outside']) as $offer) {
if ($request['best_estimate'] === null
 || (float) $offer['net'] > (float) $request['best_estimate']['net']) {
 $request['best_estimate'] = $offer;
}
  }
 }
}
unset($request);

$tradeQuery = $pdo->query(
 "SELECT t.trade_id, t.trade_date, t.trade_status,
CONCAT_WS(' ', s.first_name, s.last_name) AS seller_name,
COALESCE(NULLIF(TRIM(bp.company_name), ''),
CONCAT_WS(' ', b.first_name, b.last_name)) AS buyer_name,
COALESCE(v.total_amount, 0) AS total_amount,
COALESCE(v.total_paid, 0) AS total_paid,
COALESCE(p.pending_total, 0) AS pending_total
 FROM trades t
 JOIN users s ON s.user_id = t.seller_id
 JOIN users b ON b.user_id = t.buyer_id
 LEFT JOIN buyer_profiles bp ON bp.user_id = b.user_id
 LEFT JOIN v_trade_totals v ON v.trade_id = t.trade_id
 LEFT JOIN (
SELECT trade_id, SUM(amount_paid) AS pending_total
  FROM transaction_payments
 WHERE payment_status = 'pending'
 GROUP BY trade_id
 ) p ON p.trade_id = t.trade_id
ORDER BY t.trade_id DESC
LIMIT 20"
);
$trades = $tradeQuery->fetchAll();

$pageTitle = 'Reports';
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
 <div>
  <span class="eyebrow">Administration</span>
  <h1>Collection & earnings reports</h1>
  <p class="muted">Confirmed receipts are separated from pending payment reports. Estimates use current compatible junkshop prices and are not final offers.</p>
 </div>
 <a class="btn btn-outline" href="<?= e(url('requests.php')) ?>">Open all requests</a>
</div>

<section class="stats" aria-label="Collection request status counts">
 <?php foreach ([
  'pending' => 'Waiting for a buyer',
  'buyer_proposed' => 'Proposals to review',
  'accepted' => 'Accepted pickups',
  'awaiting_approval' => 'Seller review',
  'disputed' => 'Disputed',
  'completed' => 'Completed',
 ] as $status => $label): ?>
  <div class="stat">
<div class="stat-num"><?= (int) ($requestStatusCounts[$status] ?? 0) ?></div>
<div class="stat-label"><?= e($label) ?></div>
  </div>
 <?php endforeach; ?>
</section>

<section class="stats" aria-label="Payment summaries">
 <div class="stat">
  <div class="stat-num"><?= e(peso($collectionSummary['confirmed_total'])) ?></div>
  <div class="stat-label">Confirmed collection payments</div>
 </div>
 <div class="stat">
  <div class="stat-num"><?= e(peso($collectionSummary['pending_total'])) ?></div>
  <div class="stat-label">Collection payments pending confirmation</div>
 </div>
 <div class="stat">
  <div class="stat-num"><?= e(peso($tradeSummary['confirmed_total'])) ?></div>
  <div class="stat-label">Confirmed inventory trade payments</div>
 </div>
 <div class="stat">
  <div class="stat-num"><?= e(peso($tradeSummary['pending_total'])) ?></div>
  <div class="stat-label">Trade payments pending confirmation</div>
 </div>
</section>

<section class="card">
 <h2>Recent collection requests & estimates</h2>
 <?php if (!$requests): ?>
  <div class="empty-state"><p>No collection requests have been submitted.</p></div>
 <?php else: ?>
  <div class="table-wrap">
<table>
 <thead>
  <tr><th>Request</th><th>Seller / junkshop</th><th>Location / schedule</th><th>Status</th><th>Current estimate</th><th>Final value / payments</th></tr>
 </thead>
 <tbody>
 <?php foreach ($requests as $request): ?>
  <tr>
<td>
 <a href="<?= e(url('requests.php')) ?>">#<?= (int) $request['request_id'] ?></a><br>
 <small class="muted">Submitted <?= e(fmt_date($request['requested_date'])) ?></small>
</td>
<td>
 <?= e($request['seller_name']) ?><br>
 <small class="muted"><?= e($request['buyer_name'] ?: 'No junkshop assigned') ?></small>
</td>
<td>
 <?= e(trim($request['barangay'] . ', ' . $request['city'], ', ')) ?><br>
 <small class="muted">Pickup <?= e(fmt_date($request['confirmed_schedule'] ?: $request['preferred_schedule'])) ?></small>
</td>
<td>
 <span class="badge badge-<?= e($request['request_status']) ?>"><?= e(ucwords(str_replace('_', ' ', $request['request_status']))) ?></span>
 <?php if ($request['collection_status']): ?><br><small class="muted">Collection: <?= e(ucwords(str_replace('_', ' ', $request['collection_status']))) ?></small><?php endif; ?>
</td>
<td>
 <?php if ($request['best_estimate'] !== null): ?>
  <strong><?= e(peso($request['best_estimate']['net'])) ?></strong><br>
  <small class="muted">Best current net estimate · <?= e($request['best_estimate']['name']) ?></small>
 <?php else: ?>
  <span class="muted">No compatible current estimate</span>
 <?php endif; ?>
</td>
<td>
 <?php if ($request['collection_id'] !== null): ?>
  <?= $request['collection_status'] === 'completed' ? 'Final' : 'Entered so far' ?>:
  <strong><?= e(peso($request['final_total'])) ?></strong><br>
  <?php if ($request['collection_status'] !== 'completed'): ?><small class="muted">Not yet approved as final</small><br><?php endif; ?>
  <small class="muted">
<?= e(peso($request['paid_total'])) ?> confirmed of <?= e(peso($request['total_due'])) ?>
<?php if ((float) $request['pending_total'] > 0): ?><br><?= e(peso($request['pending_total'])) ?> pending confirmation<?php endif; ?>
  </small>
 <?php else: ?>—<?php endif; ?>
</td>
  </tr>
 <?php endforeach; ?>
 </tbody>
</table>
  </div>
  <p class="muted">Showing the latest 30 requests. Estimates are recalculated from current prices; collected values and payment totals are historical records.</p>
 <?php endif; ?>
</section>

<section class="card">
 <h2>Recent inventory trades</h2>
 <?php if (!$trades): ?>
  <div class="empty-state"><p>No inventory trades have been created.</p></div>
 <?php else: ?>
  <div class="table-wrap">
<table>
 <thead><tr><th>Trade</th><th>Seller</th><th>Junkshop</th><th>Status</th><th>Total</th><th>Paid / pending</th></tr></thead>
 <tbody>
 <?php foreach ($trades as $trade): ?>
  <tr>
<td>#<?= (int) $trade['trade_id'] ?><br><small class="muted"><?= e(fmt_date($trade['trade_date'])) ?></small></td>
<td><?= e($trade['seller_name']) ?></td>
<td><?= e($trade['buyer_name']) ?></td>
<td><span class="badge badge-<?= e($trade['trade_status']) ?>"><?= e(ucfirst($trade['trade_status'])) ?></span></td>
<td><?= e(peso($trade['total_amount'])) ?></td>
<td><?= e(peso($trade['total_paid'])) ?> confirmed<?php if ((float) $trade['pending_total'] > 0): ?><br><small class="muted"><?= e(peso($trade['pending_total'])) ?> pending</small><?php endif; ?></td>
  </tr>
 <?php endforeach; ?>
 </tbody>
</table>
  </div>
 <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
