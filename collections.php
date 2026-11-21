<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(['buyer']);

$user = current_user();
$uid = (int) $user['user_id'];
$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'record_payment') {
        $collectionId = filter_var($_POST['collection_id'] ?? null, FILTER_VALIDATE_INT);
        $amountInput = $_POST['amount_paid'] ?? '';
        $methodInput = $_POST['payment_method'] ?? '';
        $referenceInput = $_POST['reference_number'] ?? '';
        $idempotencyInput = $_POST['idempotency_key'] ?? '';
        $amount = is_string($amountInput) ? trim($amountInput) : '';
        $method = is_string($methodInput) ? $methodInput : '';
        $reference = is_string($referenceInput) ? trim($referenceInput) : '';
        $idempotencyKey = is_string($idempotencyInput)
            ? strtolower(trim($idempotencyInput)) : '';
        if (!$collectionId || $collectionId < 1
            || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount)
            || (float) $amount <= 0
            || !preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)
            || mb_strlen($reference) > 100) {
            flash('error', 'Enter a valid payment amount and reference.');
            redirect('collections.php');
        }

        $paymentContext = $pdo->prepare(
            'SELECT c.collection_id, r.seller_id
               FROM collections c
               JOIN collection_requests r ON r.request_id = c.request_id
              WHERE c.collection_id = ? AND r.accepted_buyer_id = ?'
        );
        $paymentContext->execute([$collectionId, $uid]);
        $paymentContext = $paymentContext->fetch();
        if (!$paymentContext) {
            flash('error', 'This payment does not belong to one of your collections.');
            redirect('collections.php');
        }

        $duplicate = $pdo->prepare(
            'SELECT payment_id FROM collection_payments
              WHERE idempotency_key = ? AND collection_id = ? AND reported_by = ?'
        );
        $duplicate->execute([$idempotencyKey, $collectionId, $uid]);
        $alreadySubmitted = $duplicate->fetchColumn() !== false;
        if (!$alreadySubmitted) {
            try {
                $call = $pdo->prepare('CALL sp_record_collection_payment(?, ?, ?, ?, ?, ?)');
                $call->execute([
                    $collectionId,
                    $uid,
                    $amount,
                    $method,
                    $reference,
                    $idempotencyKey,
                ]);
                $call->closeCursor();
            } catch (PDOException $exception) {
                if (in_array((string) $exception->getCode(), ['23000', '45000'], true)) {
                    $duplicate->execute([$idempotencyKey, $collectionId, $uid]);
                    $alreadySubmitted = $duplicate->fetchColumn() !== false;
                }
                if (!$alreadySubmitted && (string) $exception->getCode() !== '45000') {
                    error_log($exception->getMessage());
                }
                if (!$alreadySubmitted) {
                    $message = (string) $exception->getCode() === '45000'
                        ? (string) ($exception->errorInfo[2] ?? 'Payment could not be recorded.')
                        : 'Payment could not be recorded. Please review the collection and try again.';
                    flash('error', $message);
                    redirect('collections.php');
                }
            }
        }

        if (!$alreadySubmitted) {
            try {
                notify(
                    (int) $paymentContext['seller_id'],
                    'collection_payment_reported',
                    'Payment reported',
                    'The junkshop reported a payment for completed collection #' . (int) $collectionId . '. Confirm receipt in your requests.',
                    'collections',
                    (int) $collectionId
                );
            } catch (PDOException $exception) {
                error_log('Payment notification failed: ' . $exception->getMessage());
            }
        }
        flash('success', $alreadySubmitted
            ? 'This payment report was already submitted; no duplicate was created.'
            : 'Payment reported. It remains pending until the waste owner confirms receipt.');
        redirect('collections.php');
    }

    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $postedActuals = is_array($_POST['actuals'] ?? null) ? $_POST['actuals'] : [];

    $st = $pdo->prepare(
        "SELECT c.collection_id, c.collection_status
           FROM collections c
           JOIN collection_requests r ON r.request_id = c.request_id
          WHERE c.request_id = ? AND c.collector_id = ?
            AND r.accepted_buyer_id = ?
            AND c.collection_status IN ('pending', 'in_progress', 'disputed')"
    );
    $st->execute([$requestId, $uid, $uid]);
    $collection = $st->fetch();
    if (!$requestId || !$collection) {
        flash('error', 'This pickup is not assigned to your junkshop or can no longer be updated.');
        redirect('collections.php');
    }

    $itemQuery = $pdo->prepare(
        'SELECT request_item_id, category_id, estimate_method, estimated_size
           FROM collection_request_items WHERE request_id = ?'
    );
    $itemQuery->execute([$requestId]);
    $requestItems = $itemQuery->fetchAll();
    $actualRows = [];
    foreach ($requestItems as $item) {
        $itemId = (int) $item['request_item_id'];
        $actual = is_array($postedActuals[$itemId] ?? null) ? $postedActuals[$itemId] : [];
        $quantity = trim((string) ($actual['quantity'] ?? ''));
        $price = trim((string) ($actual['price'] ?? ''));
        $quantityUnit = $item['estimate_method'] === 'weight'
            ? 'kg'
            : ($item['estimate_method'] === 'pieces' ? 'piece' : $item['estimated_size']);
        if ($quantity === '' || !is_numeric($quantity) || (float) $quantity < 0 || (float) $quantity > 100000) {
            $errors[] = 'Enter a valid actual quantity for every requested material.';
            break;
        }
        if ($price === '' || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price)) {
            $errors[] = 'Enter a valid final unit price for every requested material.';
            break;
        }
        $actualRows[] = [
            'request_item_id' => $itemId,
            'category_id' => (int) $item['category_id'],
            'quantity_unit' => $quantityUnit,
            'quantity' => round((float) $quantity, 3),
            'price' => $price,
        ];
    }
    if (!$requestItems) {
        $errors[] = 'This request has no materials to record.';
    }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
        redirect('collections.php');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('SET @app_user_id = ?')->execute([$uid]);
        $lockedCollection = $pdo->prepare(
            "SELECT c.collection_id, c.collection_status
               FROM collection_requests r
               JOIN collections c ON c.request_id = r.request_id
              WHERE r.request_id = ? AND r.accepted_buyer_id = ?
                AND c.collector_id = ?
                AND c.collection_status IN ('pending', 'in_progress', 'disputed')
              FOR UPDATE"
        );
        $lockedCollection->execute([$requestId, $uid, $uid]);
        $lockedCollection = $lockedCollection->fetch();
        if (!$lockedCollection || (int) $lockedCollection['collection_id'] !== (int) $collection['collection_id']) {
            $pdo->rollBack();
            flash('error', 'This pickup changed before your values could be saved. Review its current status and try again.');
            redirect('collections.php');
        }
        $save = $pdo->prepare(
            'INSERT INTO collection_items
                (collection_id, request_item_id, category_id, quantity_unit,
                 actual_quantity, unit_price, price_id)
             VALUES (?, ?, ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
                actual_quantity = VALUES(actual_quantity),
                unit_price = VALUES(unit_price),
                quantity_unit = VALUES(quantity_unit),
                price_id = NULL'
        );
        foreach ($actualRows as $actual) {
            $save->execute([
                $collection['collection_id'],
                $actual['request_item_id'],
                $actual['category_id'],
                $actual['quantity_unit'],
                $actual['quantity'],
                $actual['price'],
            ]);
        }
        $pdo->commit();

        $submit = $pdo->prepare('CALL sp_submit_actuals(?, ?)');
        $submit->execute([$requestId, $uid]);
        $submit->closeCursor();
        flash('success', "Final quantities and prices for request #$requestId were sent to the seller for approval.");
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($ex->getMessage());
        flash('error', 'Could not submit the final values. Review the request status and try again.');
    }
    redirect('collections.php');
}

$st = $pdo->prepare(
    "SELECT r.request_id, r.request_status, r.confirmed_schedule, s.first_name AS seller_first_name,
            s.last_name AS seller_last_name, r.contact_number,
            CONCAT_WS(', ', r.street_address, r.barangay, r.city, r.landmark) AS full_address,
            r.pickup_notes, r.buyer_payment_method,
            c.collection_id, c.collection_status, c.dispute_reason
       FROM collection_requests r
       JOIN users s ON s.user_id = r.seller_id
       JOIN collections c ON c.request_id = r.request_id
      WHERE r.accepted_buyer_id = ? AND c.collector_id = ?
        AND c.collection_status IN ('pending','in_progress','disputed')
      ORDER BY r.confirmed_schedule"
);
$st->execute([$uid, $uid]);
$open = $st->fetchAll();

$requestIds = array_column($open, 'request_id');
$itemsByRequest = [];
$existingActuals = [];
$photos = [];
$offers = [];
if ($requestIds) {
    $in = implode(',', array_fill(0, count($requestIds), '?'));
    $itemQuery = $pdo->prepare(
        "SELECT ri.request_id, ri.request_item_id, ri.category_id, mc.category_name,
                ri.estimate_method, ri.estimated_quantity, ri.estimated_size, ri.photo_file_id,
                ci.actual_quantity, ci.unit_price
           FROM collection_request_items ri
           JOIN material_categories mc ON mc.category_id = ri.category_id
           JOIN collections c ON c.request_id = ri.request_id
           LEFT JOIN collection_items ci
             ON ci.request_item_id = ri.request_item_id AND ci.collection_id = c.collection_id
          WHERE ri.request_id IN ($in)
          ORDER BY ri.request_id, mc.category_name, ri.estimated_size"
    );
    $itemQuery->execute($requestIds);
    foreach ($itemQuery->fetchAll() as $item) {
        $rid = (int) $item['request_id'];
        $unit = $item['estimate_method'] === 'weight'
            ? 'kg'
            : ($item['estimate_method'] === 'pieces' ? 'piece' : $item['estimated_size']);
        $item['quantity_unit'] = $unit;
        $itemsByRequest[$rid][] = $item;
        if ($item['photo_file_id'] !== null) {
            $photos[$rid][] = (int) $item['photo_file_id'];
        }
    }

    $offerQuery = $pdo->prepare(
        "SELECT category_id, price_unit, buying_price, junkshop_name
           FROM v_current_junkshop_prices
          WHERE category_id IN (
              SELECT DISTINCT category_id FROM collection_request_items
               WHERE request_id IN ($in)
          )
          ORDER BY category_id, price_unit, buying_price DESC"
    );
    $offerQuery->execute($requestIds);
    foreach ($offerQuery->fetchAll() as $offer) {
        $offers[(int) $offer['category_id']][$offer['price_unit']][] = $offer;
    }
}

$doneQuery = $pdo->prepare(
    "SELECT c.collection_id, c.request_id, c.collected_at,
            CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name, u.suffix) AS seller_name,
            cps.total_due AS total_value, cps.paid_total, cps.pending_total,
            cps.payment_status, pending.pending_payment_id, r.buyer_payment_method
       FROM collections c
       JOIN collection_requests r ON r.request_id = c.request_id
       JOIN users u ON u.user_id = r.seller_id
       JOIN v_collection_payment_status cps ON cps.collection_id = c.collection_id
       LEFT JOIN (
           SELECT collection_id,
                  MAX(CASE WHEN payment_status = 'pending' THEN payment_id END) AS pending_payment_id
             FROM collection_payments
            GROUP BY collection_id
       ) pending ON pending.collection_id = c.collection_id
      WHERE r.accepted_buyer_id = ? AND c.collection_status = 'completed'
      ORDER BY c.collected_at DESC LIMIT 10"
);
$doneQuery->execute([$uid]);
$done = $doneQuery->fetchAll();

$pageTitle = 'My Collections';
require __DIR__ . '/includes/header.php';
?>
<h1>My collections</h1>
<p class="muted">After the seller approves your pickup proposal, record the actual quantities and prices here.</p>
<p class="muted">Payment is tracked separately from collection completion. After paying, report the amount below; it stays pending until the seller confirms receipt.</p>

<?php if (!$open): ?>
    <div class="card"><p class="muted">No pickups need actual quantities right now.</p></div>
<?php endif; ?>

<?php foreach ($open as $collection):
    $rid = (int) $collection['request_id'];
    $collectionId = (int) $collection['collection_id'];
?>
<section class="card">
    <h2>Request #<?= $rid ?> <small class="muted">(collection #<?= $collectionId ?>)</small></h2>
    <p>
        <strong><?= e(trim($collection['seller_first_name'] . ' ' . $collection['seller_last_name'])) ?></strong>
        · <a href="tel:<?= e($collection['contact_number']) ?>"><?= e($collection['contact_number']) ?></a><br>
        <?= e($collection['full_address']) ?><br>
        <?php if ($collection['pickup_notes']): ?><small><?= e($collection['pickup_notes']) ?></small><br><?php endif; ?>
        <small class="muted">Confirmed pickup: <?= e(fmt_date($collection['confirmed_schedule'])) ?></small>
    </p>
    <?php if ($collection['collection_status'] === 'disputed' && $collection['dispute_reason']): ?>
        <div class="alert alert-error">Seller's reason: <?= e($collection['dispute_reason']) ?></div>
    <?php endif; ?>
    <?php foreach ($photos[$rid] ?? [] as $photoId): ?>
        <a class="scrap-photo-link" href="<?= e(url('waste_photo.php?id=' . $photoId)) ?>" target="_blank" rel="noopener">
            <img class="scrap-photo" src="<?= e(url('waste_photo.php?id=' . $photoId)) ?>" alt="Scrap photo for request #<?= $rid ?>" loading="lazy">
        </a>
    <?php endforeach; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" value="<?= $rid ?>">
        <fieldset>
            <legend>Record actual quantities and final prices</legend>
            <p class="muted">Enter what was actually collected. Current junkshop offers are suggestions; confirm the agreed price with the seller.</p>
            <div class="grid-2">
            <?php foreach ($itemsByRequest[$rid] ?? [] as $item):
                $itemId = (int) $item['request_item_id'];
                $categoryId = (int) $item['category_id'];
                $unit = $item['quantity_unit'];
                $offerListId = 'offers-' . $categoryId . '-' . $unit;
            ?>
                <div class="material-estimate">
                    <strong><?= e($item['category_name']) ?> <small class="muted">(estimate: <?= e($item['estimated_quantity']) ?> <?= e($unit) ?>)</small></strong>
                    <label>Actual quantity (<?= e($unit) ?>)
                        <input type="number" name="actuals[<?= $itemId ?>][quantity]" min="0" max="100000" step="0.001"
                               value="<?= $item['actual_quantity'] !== null ? e((string) $item['actual_quantity']) : '' ?>" required>
                    </label>
                    <label>Final price per <?= e($unit) ?> (₱)
                        <input type="number" name="actuals[<?= $itemId ?>][price]" min="0" max="99999999.99" step="0.01"
                               list="<?= e($offerListId) ?>"
                               value="<?= $item['unit_price'] !== null ? e((string) $item['unit_price']) : '' ?>" required>
                    </label>
                    <?php if (!empty($offers[$categoryId][$unit])): ?>
                        <datalist id="<?= e($offerListId) ?>">
                            <?php foreach ($offers[$categoryId][$unit] as $offer): ?>
                                <option value="<?= e((string) $offer['buying_price']) ?>"><?= e($offer['junkshop_name']) ?></option>
                            <?php endforeach; ?>
                        </datalist>
                        <small class="muted">Current offers are available as price suggestions.</small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </fieldset>
        <button class="btn" type="submit" onclick="return confirm('Send these actual quantities and prices to the seller for approval?');">Submit for seller approval</button>
    </form>
</section>
<?php endforeach; ?>

<?php if ($done): ?>
<section class="card table-wrap">
    <h2>Recently completed</h2>
    <table>
        <thead><tr><th>Request</th><th>Seller</th><th>Collected</th><th>Total value</th></tr></thead>
        <tbody>
        <?php foreach ($done as $collection): ?>
            <tr>
                <td><?= (int) $collection['request_id'] ?></td>
                <td>
                    <?= e($collection['seller_name']) ?><br>
                    <a class="btn btn-small btn-outline"
                       href="<?= e(url('messages.php?request_id=' . (int) $collection['request_id'])) ?>">Message seller</a>
                </td>
                <td><?= e(fmt_date($collection['collected_at'])) ?></td>
                <td>
                    <?= e(peso($collection['total_value'])) ?>
                    <p class="muted">Payment: <?= e(ucfirst((string) $collection['payment_status'])) ?> ·
                        <?= e(peso($collection['paid_total'])) ?> paid</p>
                    <?php if ($collection['pending_payment_id'] !== null): ?>
                        <small class="muted">Waiting for the waste owner to confirm receipt.</small>
                    <?php elseif ($collection['payment_status'] !== 'paid' && (float) $collection['total_value'] > (float) $collection['paid_total']): ?>
                        <?php $paymentKey = bin2hex(random_bytes(32)); ?>
                        <form method="post" class="payment-report-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="record_payment">
                            <input type="hidden" name="collection_id" value="<?= (int) $collection['collection_id'] ?>">
                            <input type="hidden" name="payment_method" value="<?= e((string) $collection['buyer_payment_method']) ?>">
                            <input type="hidden" name="idempotency_key" value="<?= e($paymentKey) ?>">
                            <label>Report amount paid
                                <input type="number" name="amount_paid" min="0.01"
                                       max="<?= e(number_format((float) $collection['total_value'] - (float) $collection['paid_total'], 2, '.', '')) ?>"
                                       step="0.01" value="<?= e(number_format((float) $collection['total_value'] - (float) $collection['paid_total'], 2, '.', '')) ?>" required>
                            </label>
                            <small class="muted">Agreed method: <?= e(ucfirst((string) $collection['buyer_payment_method'])) ?>. Seller confirmation is required.</small>
                            <label>Reference number (optional)
                                <input type="text" name="reference_number" maxlength="100">
                            </label>
                            <button class="btn btn-small" type="submit"
                                onclick="return confirm('Report this payment for seller confirmation?');">Report payment</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
