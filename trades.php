<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(['seller', 'buyer']);

$user = current_user();
$uid = (int) $user['user_id'];
$role = (string) $user['user_role'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    try {
        if ($action === 'create_trade' && $role === 'seller') {
            $buyerId = filter_var($_POST['buyer_id'] ?? null, FILTER_VALIDATE_INT);
            $remarksInput = $_POST['remarks'] ?? '';
            if (!is_string($remarksInput)) {
                throw new InvalidArgumentException('Enter a valid optional note.');
            }
            $remarks = trim($remarksInput);
            $selected = is_array($_POST['selected_items'] ?? null)
                ? array_values(array_unique(array_filter(
                    $_POST['selected_items'],
                    static fn($value) => is_string($value) && ctype_digit($value)
                )))
                : [];
            $postedItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];

            if (!$buyerId || $buyerId < 1 || !$selected || count($selected) > 25
                || mb_strlen($remarks) > 500) {
                throw new InvalidArgumentException('Choose a buyer and between 1 and 25 inventory items.');
            }

            $offerItems = [];
            foreach ($selected as $itemIdText) {
                $itemId = (int) $itemIdText;
                $input = is_array($postedItems[$itemIdText] ?? null) ? $postedItems[$itemIdText] : [];
                $quantityInput = $input['quantity'] ?? '';
                $priceInput = $input['price'] ?? '';
                $quantity = is_string($quantityInput) ? trim($quantityInput) : '';
                $price = is_string($priceInput) ? trim($priceInput) : '';
                if ($itemId < 1
                    || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $quantity)
                    || (float) $quantity <= 0
                    || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price)
                    || (float) $price <= 0) {
                    throw new InvalidArgumentException('Enter a positive quantity and price for each selected item.');
                }
                $offerItems[$itemId] = [
                    'quantity' => round((float) $quantity, 3),
                    'price' => round((float) $price, 2),
                ];
            }

            $pdo->beginTransaction();
            $pdo->prepare('SET @app_user_id = ?')->execute([$uid]);
            $buyerQuery = $pdo->prepare(
                "SELECT user_id FROM users
                  WHERE user_id = ? AND user_role = 'buyer'
                    AND is_active = 1 AND deleted_at IS NULL
                  FOR UPDATE"
            );
            $buyerQuery->execute([$buyerId]);
            if (!$buyerQuery->fetchColumn()) {
                throw new InvalidArgumentException('Choose an active junkshop account.');
            }

            $placeholders = implode(',', array_fill(0, count($offerItems), '?'));
            $stockQuery = $pdo->prepare(
                "SELECT ci.collection_item_id, ci.remaining_quantity, r.seller_id
                   FROM collection_items ci
                   JOIN collections c ON c.collection_id = ci.collection_id
                   JOIN collection_requests r ON r.request_id = c.request_id
                  WHERE ci.collection_item_id IN ($placeholders)
                    AND r.seller_id = ? AND c.collection_status = 'completed'
                  ORDER BY ci.collection_item_id
                  FOR UPDATE"
            );
            $stockQuery->execute(array_merge(array_keys($offerItems), [$uid]));
            $stockRows = $stockQuery->fetchAll();
            if (count($stockRows) !== count($offerItems)) {
                throw new InvalidArgumentException('One or more selected items are not in your completed collections.');
            }
            foreach ($stockRows as $stock) {
                $itemId = (int) $stock['collection_item_id'];
                if ((float) $stock['remaining_quantity'] < $offerItems[$itemId]['quantity']) {
                    throw new InvalidArgumentException('An item no longer has enough available stock.');
                }
            }

            $pdo->prepare(
                'INSERT INTO trades (seller_id, buyer_id, remarks) VALUES (?, ?, ?)'
            )->execute([$uid, $buyerId, $remarks !== '' ? $remarks : null]);
            $tradeId = (int) $pdo->lastInsertId();
            $insertItem = $pdo->prepare(
                'INSERT INTO trade_items (trade_id, collection_item_id, quantity, agreed_unit_price)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($offerItems as $itemId => $item) {
                $insertItem->execute([$tradeId, $itemId, $item['quantity'], $item['price']]);
            }
            $pdo->commit();
            flash('success', "Trade offer #$tradeId sent. The selected stock is reserved until the offer is declined or cancelled.");
        } elseif (in_array($action, ['accept_trade', 'reject_trade', 'cancel_trade'], true)) {
            $tradeId = filter_var($_POST['trade_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$tradeId || $tradeId < 1) {
                throw new InvalidArgumentException('Choose a valid trade offer.');
            }
            if (($action === 'cancel_trade' && !in_array($role, ['seller', 'buyer'], true))
                || ($action !== 'cancel_trade' && $role !== 'buyer')) {
                throw new InvalidArgumentException('That trade action is not allowed.');
            }

            $pdo->beginTransaction();
            $pdo->prepare('SET @app_user_id = ?')->execute([$uid]);
            $tradeQuery = $pdo->prepare(
                'SELECT trade_id, trade_status FROM trades
                  WHERE trade_id = ? AND ' . ($role === 'seller' ? 'seller_id' : 'buyer_id') . ' = ?
                  FOR UPDATE'
            );
            $tradeQuery->execute([$tradeId, $uid]);
            $trade = $tradeQuery->fetch();
            if (!$trade) {
                throw new InvalidArgumentException('That offer does not belong to your account.');
            }
            if ($action === 'accept_trade') {
                $itemCount = $pdo->prepare('SELECT COUNT(*) FROM trade_items WHERE trade_id = ?');
                $itemCount->execute([$tradeId]);
                if ((int) $itemCount->fetchColumn() < 1) {
                    throw new InvalidArgumentException('An empty trade offer cannot be accepted.');
                }
            }
            if ($action === 'cancel_trade' && $trade['trade_status'] === 'accepted') {
                $paymentCheck = $pdo->prepare(
                    "SELECT COUNT(*) FROM transaction_payments
                      WHERE trade_id = ? AND payment_status IN ('pending', 'paid')"
                );
                $paymentCheck->execute([$tradeId]);
                if ((int) $paymentCheck->fetchColumn() > 0) {
                    throw new InvalidArgumentException('An accepted trade with a pending or confirmed payment cannot be cancelled.');
                }
            } elseif ($trade['trade_status'] !== 'pending') {
                throw new InvalidArgumentException('That offer is no longer waiting for a decision.');
            }
            $newStatus = $action === 'accept_trade'
                ? 'accepted'
                : ($action === 'reject_trade' ? 'rejected' : 'cancelled');
            $updateTrade = $pdo->prepare(
                'UPDATE trades SET trade_status = ? WHERE trade_id = ? AND trade_status = ?'
            );
            $updateTrade->execute([$newStatus, $tradeId, $trade['trade_status']]);
            if ($updateTrade->rowCount() !== 1) {
                throw new InvalidArgumentException('That offer changed before your decision was saved.');
            }
            $pdo->commit();
            flash('success', 'Trade offer #' . $tradeId . ' ' . $newStatus . '.');
        } elseif ($action === 'report_payment' && $role === 'buyer') {
            $tradeId = filter_var($_POST['trade_id'] ?? null, FILTER_VALIDATE_INT);
            $amountInput = $_POST['amount_paid'] ?? '';
            $amount = is_string($amountInput) ? trim($amountInput) : '';
            $method = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '';
            $referenceInput = $_POST['reference_number'] ?? '';
            $reference = is_string($referenceInput) ? trim($referenceInput) : '';
            if (!$tradeId || $tradeId < 1
                || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount)
                || (float) $amount <= 0
                || !in_array($method, ['cash', 'gcash', 'maya', 'bank_transfer', 'other'], true)
                || mb_strlen($reference) > 100) {
                throw new InvalidArgumentException('Enter a valid payment amount, method, and optional reference.');
            }

            $pdo->beginTransaction();
            $tradeLock = $pdo->prepare(
                "SELECT trade_id, seller_id FROM trades
                  WHERE trade_id = ? AND buyer_id = ? AND trade_status = 'accepted'
                  FOR UPDATE"
            );
            $tradeLock->execute([$tradeId, $uid]);
            $trade = $tradeLock->fetch();
            if (!$trade) {
                throw new InvalidArgumentException('Payment can only be reported for an accepted offer belonging to your account.');
            }
            $totalsQuery = $pdo->prepare('SELECT total_amount, total_paid FROM v_trade_totals WHERE trade_id = ?');
            $totalsQuery->execute([$tradeId]);
            $totals = $totalsQuery->fetch();
            $pendingQuery = $pdo->prepare(
                "SELECT COALESCE(SUM(amount_paid), 0)
                   FROM transaction_payments
                  WHERE trade_id = ? AND payment_status = 'pending'"
            );
            $pendingQuery->execute([$tradeId]);
            $remaining = round(
                (float) $totals['total_amount'] - (float) $totals['total_paid'] - (float) $pendingQuery->fetchColumn(),
                2
            );
            if ((float) $amount > $remaining + 0.001) {
                throw new InvalidArgumentException('The reported amount is greater than the outstanding trade balance.');
            }
            $pdo->prepare(
                'INSERT INTO transaction_payments
                    (trade_id, amount_paid, payment_method, payment_status, reference_number)
                 VALUES (?, ?, ?, \'pending\', ?)'
            )->execute([$tradeId, $amount, $method, $reference !== '' ? $reference : null]);
            $pdo->commit();

            try {
                notify(
                    (int) $trade['seller_id'],
                    'trade_payment_reported',
                    'Trade payment reported',
                    'The buyer reported a payment for trade #' . $tradeId . '. Confirm receipt in your trade history.',
                    'trades',
                    $tradeId
                );
                flash('success', 'Payment reported. It remains pending until the seller confirms receipt.');
            } catch (PDOException $exception) {
                error_log('Trade payment notification failed: ' . $exception->getMessage());
                flash('error', 'Payment was recorded, but the seller could not be notified.');
            }
        } elseif (in_array($action, ['confirm_payment', 'reject_payment'], true) && $role === 'seller') {
            $tradeId = filter_var($_POST['trade_id'] ?? null, FILTER_VALIDATE_INT);
            $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$tradeId || $tradeId < 1 || !$paymentId || $paymentId < 1) {
                throw new InvalidArgumentException('Choose a valid trade payment.');
            }

            $pdo->beginTransaction();
            $pdo->prepare('SET @app_user_id = ?')->execute([$uid]);
            $tradeLock = $pdo->prepare(
                "SELECT trade_id, buyer_id FROM trades
                  WHERE trade_id = ? AND seller_id = ? AND trade_status = 'accepted'
                  FOR UPDATE"
            );
            $tradeLock->execute([$tradeId, $uid]);
            $trade = $tradeLock->fetch();
            if (!$trade) {
                throw new InvalidArgumentException('That trade is not awaiting payment confirmation for your account.');
            }
            $paymentUpdate = $pdo->prepare(
                "UPDATE transaction_payments
                    SET payment_status = ?, received_by = ?
                  WHERE payment_id = ? AND trade_id = ? AND payment_status = 'pending'"
            );
            $decision = $action === 'confirm_payment' ? 'paid' : 'failed';
            $paymentUpdate->execute([$decision, $uid, $paymentId, $tradeId]);
            if ($paymentUpdate->rowCount() !== 1) {
                throw new InvalidArgumentException('That payment is no longer awaiting confirmation.');
            }
            $tradeComplete = false;
            if ($decision === 'paid') {
                $totalsQuery = $pdo->prepare('SELECT total_amount, total_paid FROM v_trade_totals WHERE trade_id = ?');
                $totalsQuery->execute([$tradeId]);
                $totals = $totalsQuery->fetch();
                if ((float) $totals['total_paid'] + 0.001 >= (float) $totals['total_amount']) {
                    $pdo->prepare(
                        "UPDATE trades SET trade_status = 'completed'
                          WHERE trade_id = ? AND seller_id = ? AND trade_status = 'accepted'"
                    )->execute([$tradeId, $uid]);
                    $tradeComplete = true;
                }
            }
            $pdo->commit();

            try {
                if ($decision === 'paid') {
                    notify(
                        (int) $trade['buyer_id'],
                        'trade_payment_paid',
                        'Trade payment confirmed',
                        'The seller confirmed your payment for trade #' . $tradeId . '.',
                        'trades',
                        $tradeId
                    );
                }
                flash('success', $decision === 'paid'
                    ? ($tradeComplete ? 'Payment confirmed and the trade is complete.' : 'Payment confirmed.')
                    : 'Payment marked as not received. The buyer can submit a corrected report.');
            } catch (PDOException $exception) {
                error_log('Trade payment confirmation notification failed: ' . $exception->getMessage());
                flash('error', 'Payment status was updated, but the buyer could not be notified.');
            }
        } else {
            throw new InvalidArgumentException('That action is not allowed.');
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string) $exception->getCode() === '45000') {
            flash('error', (string) ($exception->errorInfo[2] ?? 'The trade could not be updated.'));
        } else {
            error_log('Trade workflow failed: ' . $exception->getMessage());
            flash('error', 'The trade could not be updated. Review its current status and try again.');
        }
    }
    redirect('trades.php');
}

$inventory = [];
$buyers = [];
if ($role === 'seller') {
    $inventoryQuery = $pdo->prepare(
        "SELECT ci.collection_item_id, ci.remaining_quantity, ci.quantity_unit, ci.unit_price,
                mc.category_name, r.request_id, c.collection_id
           FROM collection_items ci
           JOIN collections c ON c.collection_id = ci.collection_id
           JOIN collection_requests r ON r.request_id = c.request_id
           JOIN material_categories mc ON mc.category_id = ci.category_id
          WHERE r.seller_id = ? AND c.collection_status = 'completed'
            AND ci.remaining_quantity > 0
          ORDER BY c.collection_id DESC, mc.category_name"
    );
    $inventoryQuery->execute([$uid]);
    $inventory = $inventoryQuery->fetchAll();

    $buyerQuery = $pdo->query(
        "SELECT u.user_id,
                COALESCE(NULLIF(TRIM(bp.company_name), ''),
                         CONCAT_WS(' ', u.first_name, u.last_name)) AS buyer_name
           FROM users u
           LEFT JOIN buyer_profiles bp ON bp.user_id = u.user_id
          WHERE u.user_role = 'buyer' AND u.is_active = 1 AND u.deleted_at IS NULL
          ORDER BY buyer_name"
    );
    $buyers = $buyerQuery->fetchAll();
}

$tradeQuery = $pdo->prepare(
    "SELECT t.trade_id, t.seller_id, t.buyer_id, t.trade_date, t.trade_status, t.remarks,
            COALESCE(NULLIF(TRIM(bp.company_name), ''),
                     CONCAT_WS(' ', buyer.first_name, buyer.last_name)) AS buyer_name,
            CONCAT_WS(' ', seller.first_name, seller.last_name) AS seller_name,
            COALESCE(v.total_amount, 0) AS total_amount,
            COALESCE(v.total_paid, 0) AS total_paid,
            COALESCE(p.pending_total, 0) AS pending_total
       FROM trades t
       JOIN users seller ON seller.user_id = t.seller_id
       JOIN users buyer ON buyer.user_id = t.buyer_id
       LEFT JOIN buyer_profiles bp ON bp.user_id = t.buyer_id
       LEFT JOIN v_trade_totals v ON v.trade_id = t.trade_id
       LEFT JOIN (
            SELECT trade_id, SUM(amount_paid) AS pending_total
              FROM transaction_payments
             WHERE payment_status = 'pending'
             GROUP BY trade_id
       ) p ON p.trade_id = t.trade_id
      WHERE t." . ($role === 'seller' ? 'seller_id' : 'buyer_id') . " = ?
      ORDER BY t.trade_id DESC
      LIMIT 100"
);
$tradeQuery->execute([$uid]);
$trades = $tradeQuery->fetchAll();
$tradeIds = array_map(static fn($trade) => (int) $trade['trade_id'], $trades);
$itemsByTrade = [];
$paymentsByTrade = [];
if ($tradeIds) {
    $in = implode(',', array_fill(0, count($tradeIds), '?'));
    $itemQuery = $pdo->prepare(
        "SELECT ti.trade_id, ti.quantity, ti.agreed_unit_price, ti.line_total,
                ci.quantity_unit, mc.category_name
           FROM trade_items ti
           JOIN collection_items ci ON ci.collection_item_id = ti.collection_item_id
           JOIN material_categories mc ON mc.category_id = ci.category_id
          WHERE ti.trade_id IN ($in)
          ORDER BY ti.trade_id, mc.category_name"
    );
    $itemQuery->execute($tradeIds);
    foreach ($itemQuery->fetchAll() as $item) {
        $itemsByTrade[(int) $item['trade_id']][] = $item;
    }
    $paymentQuery = $pdo->prepare(
        "SELECT payment_id, trade_id, amount_paid, payment_method, payment_status,
                reference_number, created_at
           FROM transaction_payments
          WHERE trade_id IN ($in)
          ORDER BY payment_id DESC"
    );
    $paymentQuery->execute($tradeIds);
    foreach ($paymentQuery->fetchAll() as $payment) {
        $paymentsByTrade[(int) $payment['trade_id']][] = $payment;
    }
}

$pageTitle = 'Inventory trades';
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <div>
        <span class="eyebrow"><?= $role === 'seller' ? 'Seller tools' : 'Junkshop tools' ?></span>
        <h1>Inventory trades</h1>
        <p class="muted">Track material offers, reserved stock, payment reports, and completed sales.</p>
    </div>
    <?php if ($role === 'seller'): ?>
        <a class="btn btn-outline" href="<?= e(url('earnings.php')) ?>">View earnings</a>
    <?php endif; ?>
</div>

<?php if ($role === 'seller'): ?>
<section class="card">
    <h2>Create a trade offer</h2>
    <p class="muted">Only material from your completed collections is available. Stock is reserved when the offer is sent and restored if the offer is declined or cancelled.</p>
    <?php if (!$buyers): ?>
        <div class="empty-state"><p>No active junkshop accounts are available yet.</p></div>
    <?php elseif (!$inventory): ?>
        <div class="empty-state"><p>You have no available inventory from completed collections.</p></div>
    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_trade">
            <div class="form-group">
                <label for="trade-buyer">Junkshop</label>
                <select id="trade-buyer" name="buyer_id" required>
                    <option value="">Choose a junkshop</option>
                    <?php foreach ($buyers as $buyer): ?>
                        <option value="<?= (int) $buyer['user_id'] ?>"><?= e($buyer['buyer_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Offer</th><th>Material / collection</th><th>Available</th><th>Quantity</th><th>Price per unit</th></tr></thead>
                    <tbody>
                    <?php foreach ($inventory as $item): $itemId = (int) $item['collection_item_id']; ?>
                        <tr>
                            <td><input type="checkbox" name="selected_items[]" value="<?= $itemId ?>" aria-label="Include <?= e($item['category_name']) ?> from collection #<?= (int) $item['collection_id'] ?>"></td>
                            <td><?= e($item['category_name']) ?><br><small class="muted">Collection #<?= (int) $item['collection_id'] ?></small></td>
                            <td><?= e(number_format((float) $item['remaining_quantity'], 3)) ?> <?= e($item['quantity_unit']) ?></td>
                            <td><input type="number" name="items[<?= $itemId ?>][quantity]" min="0.001" max="<?= e((string) $item['remaining_quantity']) ?>" step="0.001" inputmode="decimal" aria-label="Quantity for <?= e($item['category_name']) ?>"></td>
                            <td><input type="number" name="items[<?= $itemId ?>][price]" min="0.01" step="0.01" value="<?= e(number_format((float) $item['unit_price'], 2, '.', '')) ?>" inputmode="decimal" aria-label="Price per unit for <?= e($item['category_name']) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-group">
                <label for="trade-remarks">Note <span class="muted">(optional)</span></label>
                <textarea id="trade-remarks" name="remarks" maxlength="500" rows="3"></textarea>
            </div>
            <button class="btn" type="submit">Send trade offer</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= $role === 'seller' ? 'My trade offers and sales' : 'Trade offers and purchases' ?></h2>
    <?php if (!$trades): ?>
        <div class="empty-state"><p>No trade activity yet.</p></div>
    <?php else: ?>
        <?php foreach ($trades as $trade):
            $tradeId = (int) $trade['trade_id'];
            $balance = max(0, round((float) $trade['total_amount'] - (float) $trade['total_paid'], 2));
            $reportable = max(0, round($balance - (float) $trade['pending_total'], 2));
            $counterparty = $role === 'seller' ? $trade['buyer_name'] : $trade['seller_name'];
        ?>
            <article class="card trade-card">
                <div class="row-between">
                    <div>
                        <h3>Trade #<?= $tradeId ?> · <?= e($counterparty) ?></h3>
                        <small class="muted"><?= e(fmt_date($trade['trade_date'])) ?></small>
                    </div>
                    <span class="badge badge-<?= e($trade['trade_status']) ?>"><?= e(ucfirst($trade['trade_status'])) ?></span>
                </div>
                <?php if ($trade['remarks']): ?><p><?= e($trade['remarks']) ?></p><?php endif; ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Material</th><th>Quantity</th><th>Unit price</th><th>Line total</th></tr></thead>
                        <tbody>
                        <?php foreach ($itemsByTrade[$tradeId] ?? [] as $item): ?>
                            <tr>
                                <td><?= e($item['category_name']) ?></td>
                                <td><?= e(number_format((float) $item['quantity'], 3)) ?> <?= e($item['quantity_unit']) ?></td>
                                <td><?= e(peso($item['agreed_unit_price'])) ?></td>
                                <td><?= e(peso($item['line_total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p><strong>Total:</strong> <?= e(peso($trade['total_amount'])) ?>
                    · <strong>Confirmed paid:</strong> <?= e(peso($trade['total_paid'])) ?>
                    · <strong>Balance:</strong> <?= e(peso($balance)) ?>
                    <?php if ((float) $trade['pending_total'] > 0): ?> · <strong>Awaiting confirmation:</strong> <?= e(peso($trade['pending_total'])) ?><?php endif; ?>
                </p>

                <?php if ($paymentsByTrade[$tradeId] ?? []): ?>
                    <details>
                        <summary>Payment history (<?= count($paymentsByTrade[$tradeId]) ?>)</summary>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><?php if ($role === 'seller'): ?><th>Action</th><?php endif; ?></tr></thead>
                                <tbody>
                                <?php foreach ($paymentsByTrade[$tradeId] as $payment): ?>
                                    <tr>
                                        <td><?= e(fmt_date($payment['created_at'])) ?></td>
                                        <td><?= e(peso($payment['amount_paid'])) ?></td>
                                        <td><?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></td>
                                        <td><?= e($payment['reference_number'] ?: '—') ?></td>
                                        <td><span class="badge badge-<?= e($payment['payment_status']) ?>"><?= e(ucfirst($payment['payment_status'])) ?></span></td>
                                        <?php if ($role === 'seller'): ?>
                                            <td class="actions">
                                                <?php if ($payment['payment_status'] === 'pending' && $trade['trade_status'] === 'accepted'): ?>
                                                    <?php foreach (['confirm_payment' => 'Confirm received', 'reject_payment' => 'Not received'] as $paymentAction => $label): ?>
                                                        <form method="post" class="inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="<?= e($paymentAction) ?>">
                                                            <input type="hidden" name="trade_id" value="<?= $tradeId ?>">
                                                            <input type="hidden" name="payment_id" value="<?= (int) $payment['payment_id'] ?>">
                                                            <button class="btn btn-small<?= $paymentAction === 'reject_payment' ? ' btn-outline' : '' ?>" type="submit"><?= e($label) ?></button>
                                                        </form>
                                                    <?php endforeach; ?>
                                                <?php else: ?>—<?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if ($role === 'buyer' && $trade['trade_status'] === 'pending'): ?>
                    <div class="actions">
                        <?php foreach (['accept_trade' => 'Accept offer', 'reject_trade' => 'Decline offer'] as $tradeAction => $label): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="<?= e($tradeAction) ?>">
                                <input type="hidden" name="trade_id" value="<?= $tradeId ?>">
                                <button class="btn<?= $tradeAction === 'reject_trade' ? ' btn-outline' : '' ?>" type="submit"><?= e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($role === 'seller' && $trade['trade_status'] === 'pending'): ?>
                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_trade">
                        <input type="hidden" name="trade_id" value="<?= $tradeId ?>">
                        <button class="btn btn-small btn-outline" type="submit">Cancel offer</button>
                    </form>
                <?php elseif ($trade['trade_status'] === 'accepted'
                    && (float) $trade['total_paid'] <= 0
                    && (float) $trade['pending_total'] <= 0): ?>
                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_trade">
                        <input type="hidden" name="trade_id" value="<?= $tradeId ?>">
                        <button class="btn btn-small btn-outline" type="submit">Cancel unpaid trade</button>
                    </form>
                <?php elseif ($role === 'buyer' && $trade['trade_status'] === 'accepted' && $reportable > 0): ?>
                    <form method="post" class="trade-payment-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="report_payment">
                        <input type="hidden" name="trade_id" value="<?= $tradeId ?>">
                        <h4>Report a payment</h4>
                        <p class="muted">Payment is recorded manually and must be confirmed by the seller. Outstanding amount not already reported: <?= e(peso($reportable)) ?>.</p>
                        <div class="form-group">
                            <label for="payment-amount-<?= $tradeId ?>">Amount</label>
                            <input id="payment-amount-<?= $tradeId ?>" type="number" name="amount_paid" min="0.01" max="<?= e(number_format($reportable, 2, '.', '')) ?>" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label for="payment-method-<?= $tradeId ?>">Payment method</label>
                            <select id="payment-method-<?= $tradeId ?>" name="payment_method" required>
                                <option value="cash">Cash</option><option value="gcash">GCash</option>
                                <option value="maya">Maya</option><option value="bank_transfer">Bank transfer</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="payment-reference-<?= $tradeId ?>">Reference <span class="muted">(optional)</span></label>
                            <input id="payment-reference-<?= $tradeId ?>" type="text" name="reference_number" maxlength="100">
                        </div>
                        <button class="btn btn-small" type="submit">Report payment</button>
                    </form>
                    <?php if ((float) $trade['total_paid'] <= 0 && (float) $trade['pending_total'] <= 0): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel_trade">
                            <input type="hidden" name="trade_id" value="<?= $tradeId ?>">
                            <button class="btn btn-small btn-outline" type="submit">Cancel unpaid trade</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
