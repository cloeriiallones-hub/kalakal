<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(['seller', 'admin']);

$user = current_user();
$uid = (int) $user['user_id'];
$role = $user['user_role'];
$pdo = db();
$requests = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$requestId || $requestId < 1) {
        flash('error', 'Choose a valid collection request.');
        redirect('requests.php');
    }

    try {
        if ($action === 'cancel' && $role === 'seller') {
            $pdo->beginTransaction();
            $pdo->prepare('SET @app_user_id = ?')->execute([$uid]);
            $st = $pdo->prepare(
                "UPDATE collection_requests
                    SET request_status = 'cancelled'
                  WHERE request_id = ? AND seller_id = ?
                    AND (request_status = 'pending'
                         OR (request_status IN ('buyer_proposed','accepted') AND accepted_buyer_id IS NOT NULL))"
            );
            $st->execute([$requestId, $uid]);
            if ($st->rowCount() === 1) {
                $pdo->prepare(
                    "UPDATE collections
                        SET collection_status = 'cancelled'
                      WHERE request_id = ?
                        AND collection_status IN ('pending','in_progress','disputed')"
                )->execute([$requestId]);
            }
            $pdo->commit();
            if ($st->rowCount() !== 1) {
                flash('error', 'Only pending requests or requests with a buyer proposal can be cancelled.');
            } else {
                flash('success', "Request #$requestId cancelled.");
            }
        } elseif ($role === 'seller' && $action === 'report_missed_pickup') {
            $details = trim((string) ($_POST['details'] ?? ''));
            if ($details === '' || mb_strlen($details) > 500) {
                throw new InvalidArgumentException('Enter a missed-pickup note of up to 500 characters.');
            }
            $st = $pdo->prepare('CALL sp_report_missed_pickup(?, ?, ?)');
            $st->execute([$requestId, $uid, $details]);
            $st->closeCursor();
            flash('success', "Missed pickup for request #$requestId was reported to the junkshop.");
        } elseif ($role === 'seller' && $action === 'acknowledge_missed_pickup') {
            $incidentId = filter_var($_POST['incident_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$incidentId || $incidentId < 1) {
                throw new InvalidArgumentException('Choose a valid missed-pickup report.');
            }
            $st = $pdo->prepare('CALL sp_acknowledge_missed_pickup(?, ?)');
            $st->execute([$incidentId, $uid]);
            $st->closeCursor();
            flash('success', "Missed-pickup report for request #$requestId acknowledged.");
        } elseif ($action === 'confirm_payment' && $role === 'seller') {
            $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
            $decision = (string) ($_POST['decision'] ?? '');
            if (!$paymentId || $paymentId < 1 || !in_array($decision, ['paid', 'failed'], true)) {
                throw new InvalidArgumentException('Choose a valid payment decision.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('SET @app_user_id = ?')->execute([$uid]);
            $paymentQuery = $pdo->prepare(
                "SELECT cp.payment_id, cp.reported_by, c.collection_id
                   FROM collection_payments cp
                   JOIN collections c ON c.collection_id = cp.collection_id
                   JOIN collection_requests r ON r.request_id = c.request_id
                  WHERE cp.payment_id = ? AND r.request_id = ? AND r.seller_id = ?
                    AND r.request_status = 'completed' AND c.collection_status = 'completed'
                    AND cp.payment_status = 'pending'
                  FOR UPDATE"
            );
            $paymentQuery->execute([$paymentId, $requestId, $uid]);
            $payment = $paymentQuery->fetch();
            if (!$payment) {
                throw new InvalidArgumentException('That payment is no longer awaiting your confirmation.');
            }
            $update = $pdo->prepare(
                'UPDATE collection_payments
                    SET payment_status = ?, confirmed_by = ?, confirmed_at = NOW()
                  WHERE payment_id = ? AND payment_status = \'pending\''
            );
            $update->execute([$decision, $uid, $paymentId]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('That payment changed before your confirmation.');
            }
            $pdo->commit();
            try {
                notify(
                    (int) $payment['reported_by'],
                    'collection_payment_' . $decision,
                    $decision === 'paid' ? 'Payment confirmed' : 'Payment not confirmed',
                    $decision === 'paid'
                        ? 'The waste owner confirmed receipt of your payment for collection #' . (int) $payment['collection_id'] . '.'
                        : 'The waste owner could not confirm the reported payment for collection #' . (int) $payment['collection_id'] . '. Contact them to resolve it.',
                    'collections',
                    (int) $payment['collection_id']
                );
                flash('success', $decision === 'paid'
                    ? 'Payment marked as received.'
                    : 'Payment report marked as not received. The junkshop can submit a corrected report.');
            } catch (PDOException $exception) {
                error_log('Payment confirmation notification failed: ' . $exception->getMessage());
                flash('error', 'Payment status was updated, but the other party could not be notified.');
            }
        } elseif ($role === 'seller' && in_array($action, ['approve_proposal', 'reject_proposal'], true)) {
            $st = $pdo->prepare('CALL sp_seller_buyer_proposal_decision(?, ?, ?)');
            $st->execute([$requestId, $uid, $action === 'approve_proposal' ? 1 : 0]);
            $st->closeCursor();
            flash('success', $action === 'approve_proposal'
                ? "Junkshop proposal for request #$requestId approved."
                : "Junkshop proposal for request #$requestId declined.");
        } elseif ($role === 'seller' && in_array($action, ['approve_reschedule', 'decline_reschedule'], true)) {
            $st = $pdo->prepare('CALL sp_seller_reschedule_decision(?, ?, ?)');
            $st->execute([$requestId, $uid, $action === 'approve_reschedule' ? 1 : 0]);
            $st->closeCursor();
            flash('success', $action === 'approve_reschedule'
                ? "New pickup time for request #$requestId approved."
                : "Reschedule proposal for request #$requestId declined. The existing time remains.");
        } elseif ($role === 'seller'
            && in_array($action, ['approve', 'dispute'], true)) {
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($action === 'dispute' && ($reason === '' || mb_strlen($reason) > 500)) {
                throw new InvalidArgumentException('Enter a dispute reason of up to 500 characters.');
            }
            $st = $pdo->prepare('CALL sp_seller_decision(?, ?, ?, ?)');
            $st->execute([$requestId, $uid, $action === 'approve' ? 1 : 0, $reason !== '' ? $reason : null]);
            $st->closeCursor();
            flash('success', $action === 'approve'
                ? "Final values for request #$requestId approved."
                : "Request #$requestId disputed. The buyer can review and resubmit.");
        } else {
            flash('error', 'That action is not allowed.');
        }
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $ex->getMessage());
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string) $ex->getCode() === '45000') {
            flash('error', (string) ($ex->errorInfo[2] ?? 'That request could not be updated.'));
        } else {
            error_log($ex->getMessage());
            flash('error', 'The request could not be updated. It may no longer be available, or your account may not be eligible for this action.');
        }
    }
    redirect('requests.php');
}

{
    $where = $role === 'admin' ? '1 = 1' : 'r.seller_id = ?';
    $st = $pdo->prepare(
        "SELECT r.request_id, r.requested_date, r.preferred_schedule, r.confirmed_schedule,
            r.buyer_suggested_schedule,
                r.buyer_payment_method, r.request_status,
                c.collection_status, COALESCE(ci.item_count, 0) AS collection_item_count,
                r.street_address, r.barangay, r.city, r.landmark, r.pickup_notes,
                r.contact_number, r.seller_id, r.assigned_collector_id, r.accepted_buyer_id,
                COALESCE(NULLIF(TRIM(bp.company_name), ''), 'Junkshop') AS junkshop_name,
                b.phone_number AS junkshop_phone,
                CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name, s.suffix) AS seller_name,
                CONCAT_WS(' ', legacy.first_name, legacy.middle_name, legacy.last_name, legacy.suffix) AS legacy_assignee_name,
                legacy.phone_number AS legacy_assignee_phone,
                r.preferred_buyer_id, r.distance_km, r.pickup_charge,
                pb.company_name AS preferred_name
           FROM collection_requests r
           LEFT JOIN buyer_profiles pb ON pb.user_id = r.preferred_buyer_id
           JOIN users s ON s.user_id = r.seller_id
           LEFT JOIN users legacy ON legacy.user_id = r.assigned_collector_id
           LEFT JOIN users b ON b.user_id = r.accepted_buyer_id
           LEFT JOIN buyer_profiles bp ON bp.user_id = r.accepted_buyer_id
           LEFT JOIN collections c ON c.request_id = r.request_id
           LEFT JOIN (
                SELECT collection_id, COUNT(*) AS item_count
                  FROM collection_items
                 GROUP BY collection_id
           ) ci ON ci.collection_id = c.collection_id
          WHERE $where
          ORDER BY r.request_id DESC
          LIMIT 100"
    );
    $st->execute($role === 'admin' ? [] : [$uid]);
    foreach ($st->fetchAll() as $row) {
        $requests[] = [
            'request_id' => (int) $row['request_id'],
            'request_status' => $row['request_status'],
            'requested_date' => $row['requested_date'],
            'preferred_schedule' => $row['preferred_schedule'],
            'confirmed_schedule' => $row['confirmed_schedule'],
            'buyer_suggested_schedule' => $row['buyer_suggested_schedule'],
            'buyer_payment_method' => $row['buyer_payment_method'],
            'collection_status' => $row['collection_status'],
            'collection_item_count' => (int) $row['collection_item_count'],
            'seller_name' => $row['seller_name'],
            'buyer_name' => $row['accepted_buyer_id'] !== null ? $row['junkshop_name'] : $row['legacy_assignee_name'],
            'buyer_phone' => $row['accepted_buyer_id'] !== null ? $row['junkshop_phone'] : $row['legacy_assignee_phone'],
            'accepted_by_junkshop' => $row['accepted_buyer_id'] !== null,
            'preferred_name' => $row['preferred_name'],
            'distance_km' => $row['distance_km'],
            'pickup_charge' => (float) $row['pickup_charge'],
            'pickup_address' => implode(', ', array_filter([
                $row['street_address'], $row['barangay'], $row['city'], $row['landmark'],
            ])),
            'contact_number' => $row['contact_number'],
            'pickup_notes' => $row['pickup_notes'],
            'seller_id' => (int) $row['seller_id'],
            'private_details' => true,
            'can_view_photos' => true,
        ];
    }
}

$requestIds = array_column($requests, 'request_id');
$items = [];
$photos = [];
$actuals = [];
$payments = [];
$incidents = [];
if ($requestIds) {
    $in = implode(',', array_fill(0, count($requestIds), '?'));
    $itemQuery = $pdo->prepare(
        "SELECT ri.request_id, ri.request_item_id, ri.category_id, mc.category_name,
                ri.estimate_method, ri.estimated_quantity, ri.estimated_weight_kg, ri.estimated_size, ri.photo_file_id
           FROM collection_request_items ri
           JOIN material_categories mc ON mc.category_id = ri.category_id
          WHERE ri.request_id IN ($in)
          ORDER BY mc.category_name, ri.estimated_size"
    );
    $itemQuery->execute($requestIds);
    foreach ($itemQuery->fetchAll() as $item) {
        $rid = (int) $item['request_id'];
        $quantity = rtrim(rtrim(number_format((float) $item['estimated_quantity'], 3, '.', ''), '0'), '.');
        if ($item['estimate_method'] === 'detailed') {
            $weight = rtrim(rtrim(number_format((float) $item['estimated_weight_kg'], 3, '.', ''), '0'), '.');
            $items[$rid][] = $item['category_name'] . ' (' . $quantity . ' pcs, '
                . $item['estimated_size'] . ', ' . $weight . ' kg)';
        } else {
            $unit = $item['estimate_method'] === 'weight'
                ? 'kg'
                : ($item['estimate_method'] === 'pieces' ? 'pieces' : $item['estimated_size'] . ' items');
            $items[$rid][] = $item['category_name'] . ' (' . $quantity . ' ' . $unit . ')';
        }
        if ($item['photo_file_id'] !== null) {
            $photos[$rid][] = (int) $item['photo_file_id'];
        }
    }

    $actualQuery = $pdo->prepare(
        "SELECT c.request_id, mc.category_name, ci.quantity_unit, ci.actual_quantity,
                ci.unit_price, ci.line_total
           FROM collections c
           JOIN collection_items ci ON ci.collection_id = c.collection_id
           JOIN material_categories mc ON mc.category_id = ci.category_id
          WHERE c.request_id IN ($in)
          ORDER BY c.request_id, mc.category_name, ci.quantity_unit"
    );
    $actualQuery->execute($requestIds);
    foreach ($actualQuery->fetchAll() as $actual) {
        $actuals[(int) $actual['request_id']][] = $actual;
    }

    $paymentQuery = $pdo->prepare(
        "SELECT c.request_id, c.collection_id, cps.total_due, cps.paid_total,
                cps.pending_total, cps.payment_status,
                cp.payment_id AS pending_payment_id, cp.amount_paid AS pending_amount
           FROM collections c
           JOIN v_collection_payment_status cps ON cps.collection_id = c.collection_id
           LEFT JOIN collection_payments cp
             ON cp.collection_id = c.collection_id AND cp.payment_status = 'pending'
          WHERE c.request_id IN ($in)"
    );
    $paymentQuery->execute($requestIds);
    foreach ($paymentQuery->fetchAll() as $payment) {
        $payments[(int) $payment['request_id']] = $payment;
    }

    $incidentQuery = $pdo->prepare(
        "SELECT i.incident_id, i.request_id, i.reported_by, i.details,
                i.incident_status, i.created_at,
                CONCAT_WS(' ', u.first_name, u.last_name) AS reporter_name
           FROM collection_incidents i
           JOIN users u ON u.user_id = i.reported_by
          WHERE i.request_id IN ($in)
          ORDER BY i.created_at"
    );
    $incidentQuery->execute($requestIds);
    foreach ($incidentQuery->fetchAll() as $incident) {
        $incidents[(int) $incident['request_id']][] = $incident;
    }
}

$pageTitle = $role === 'admin' ? 'All Requests' : 'My Requests';
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <h1><?= e($pageTitle) ?></h1>
    <?php if ($role === 'seller'): ?>
        <a class="btn" href="<?= e(url('request_new.php')) ?>">+ New request</a>
    <?php endif; ?>
</div>
<?php if (!$requests): ?>
    <div class="card"><p class="muted">Nothing here yet.</p></div>
<?php else: ?>
<div class="card table-wrap">
    <table>
        <thead><tr>
            <th>#</th><th>Requested / pickup</th>
            <?php if ($role === 'seller'): ?><th>Buyer / proposal</th><?php endif; ?>
            <?php if ($role !== 'seller'): ?><th>Seller</th><?php endif; ?>
            <th>Materials / final values</th><th>Scrap photos</th><th>Pickup details</th><th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($requests as $request):
            $rid = (int) $request['request_id'];
            $status = $request['request_status'];
            $requestIncidents = $incidents[$rid] ?? [];
            $sellerAlreadyReported = false;
            foreach ($requestIncidents as $incident) {
                if ((int) $incident['reported_by'] === $uid) {
                    $sellerAlreadyReported = true;
                }
            }
        ?>
            <tr id="request-<?= $rid ?>">
                <td><?= $rid ?></td>
                <td>
                    <?= e(fmt_date($request['requested_date'] ?? $request['preferred_schedule'])) ?><br>
                    <small class="muted">Pickup: <?= e(fmt_date($request['confirmed_schedule'] ?? $request['preferred_schedule'])) ?></small>
                </td>
                <?php if ($role === 'seller'): ?>
                    <td>
                        <?php if (!empty($request['buyer_name'])): ?>
                            <?= e($request['buyer_name']) ?>
                            <?php if (!empty($request['buyer_phone'])): ?><br><a href="tel:<?= e($request['buyer_phone']) ?>"><?= e($request['buyer_phone']) ?></a><?php endif; ?>
                        <?php elseif (!empty($request['preferred_name'])): ?><span class="muted">Waiting for <?= e($request['preferred_name']) ?> to propose</span>
                        <?php else: ?><span class="muted">Waiting for a junkshop proposal</span><?php endif; ?>
                        <?php if ($status === 'buyer_proposed'): ?>
                            <br><strong>Proposed pickup:</strong> <?= e(fmt_date($request['buyer_suggested_schedule'])) ?>
                            <br><strong>Payment:</strong> <?= e(ucfirst((string) $request['buyer_payment_method'])) ?>
                        <?php elseif ($status === 'accepted' && !empty($request['buyer_suggested_schedule'])
                            && $request['buyer_suggested_schedule'] !== $request['confirmed_schedule']): ?>
                            <br><strong>Reschedule requested:</strong> <?= e(fmt_date($request['buyer_suggested_schedule'])) ?>
                        <?php endif; ?>
                        <?php if ($request['accepted_by_junkshop'] && in_array($status, ['buyer_proposed','accepted','awaiting_approval','disputed','completed'], true)): ?>
                            <br><a class="btn btn-small btn-outline" href="<?= e(url('messages.php?request_id=' . $rid)) ?>">Message buyer</a>
                        <?php endif; ?>
                        <?php if (($request['pickup_charge'] ?? 0) > 0): ?><br><small>Pickup charge <strong><?= e(peso($request['pickup_charge'])) ?></strong> · <?= e(number_format((float) $request['distance_km'], 1)) ?> km</small><?php endif; ?>
                    </td>
                <?php endif; ?>
                <?php if ($role !== 'seller'): ?><td><?= e($request['seller_name'] ?? 'Available request') ?></td><?php endif; ?>
                <td>
                    <?php if (!empty($items[$rid])): ?>
                        <ul class="item-detail-list">
                            <?php foreach ($items[$rid] as $itemLine): ?><li><?= e($itemLine) ?></li><?php endforeach; ?>
                        </ul>
                    <?php else: ?>—<?php endif; ?>
                    <?php if (!empty($actuals[$rid])): ?>
                        <details><summary>Final collected values</summary>
                            <ul>
                                <?php foreach ($actuals[$rid] as $actual): ?>
                                    <li><?= e($actual['category_name']) ?>:
                                        <?= e($actual['actual_quantity']) ?> <?= e($actual['quantity_unit']) ?> ×
                                        <?= e(peso($actual['unit_price'])) ?> = <?= e(peso($actual['line_total'])) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($request['can_view_photos']): ?>
                        <?php foreach ($photos[$rid] ?? [] as $photoId): ?>
                            <a class="scrap-photo-link" href="<?= e(url('waste_photo.php?id=' . $photoId)) ?>" target="_blank" rel="noopener">
                                <img class="scrap-photo" src="<?= e(url('waste_photo.php?id=' . $photoId)) ?>" alt="Scrap photo for request #<?= $rid ?>" loading="lazy">
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?><span class="muted">Visible after accepting</span><?php endif; ?>
                </td>
                <td>
                    <?= e($request['pickup_address']) ?>
                    <?php if (!empty($request['contact_number'])): ?><br><a href="tel:<?= e($request['contact_number']) ?>"><?= e($request['contact_number']) ?></a><?php endif; ?>
                    <?php if (!empty($request['pickup_notes'])): ?><br><small class="muted"><?= e($request['pickup_notes']) ?></small><?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= e($status) ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></span>
                    <?php if ($status === 'completed' && isset($payments[$rid])): $payment = $payments[$rid]; ?>
                        <p class="muted">Payment: <?= e(ucfirst((string) $payment['payment_status'])) ?><br>
                            <?= e(peso($payment['paid_total'])) ?> paid of <?= e(peso($payment['total_due'])) ?>
                            <?php if ((float) $payment['pending_total'] > 0): ?><br><?= e(peso($payment['pending_total'])) ?> awaiting confirmation<?php endif; ?>
                            <?php if ($role === 'seller' && $payment['payment_status'] === 'unpaid'): ?>
                                <br>Collection completion does not confirm payment. Ask the junkshop to report payment in My Collections, then confirm receipt here.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($role === 'seller'): ?>
                        <?php foreach ($requestIncidents as $incident): ?>
                            <p class="muted">
                                <strong>Missed pickup (<?= e($incident['incident_status']) ?>)</strong>
                                · <?= e($incident['reporter_name']) ?> · <?= e(fmt_date($incident['created_at'])) ?><br>
                                <?= e($incident['details']) ?>
                            </p>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <?php if ($role === 'seller' && $status === 'completed'
                        && !empty($payments[$rid]['pending_payment_id'])): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="confirm_payment">
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <input type="hidden" name="payment_id" value="<?= (int) $payments[$rid]['pending_payment_id'] ?>">
                            <button class="btn btn-small" type="submit" name="decision" value="paid"
                                onclick="return confirm('Confirm that you received this payment?');">Confirm received</button>
                            <button class="btn btn-small btn-outline" type="submit" name="decision" value="failed"
                                onclick="return confirm('Mark this reported payment as not received?');">Not received</button>
                        </form>
                    <?php elseif ($role === 'seller' && $status === 'buyer_proposed'): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="approve_proposal">
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <button class="btn btn-small" type="submit">Approve proposal</button>
                        </form>
                        <form method="post" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="reject_proposal">
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <button class="btn btn-small btn-outline" type="submit">Decline proposal</button>
                        </form>
                    <?php elseif ($role === 'seller' && $status === 'accepted'
                        && !empty($request['buyer_suggested_schedule'])
                        && $request['buyer_suggested_schedule'] !== $request['confirmed_schedule']): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="approve_reschedule">
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <button class="btn btn-small" type="submit">Approve new time</button>
                        </form>
                        <form method="post" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="decline_reschedule">
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <button class="btn btn-small btn-outline" type="submit">Keep current time</button>
                        </form>
                    <?php elseif ($role === 'seller'
                        && ($status === 'pending' || ($status === 'accepted' && !empty($request['accepted_by_junkshop'])))): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Cancel this request? Any junkshop that accepted it will be notified.');">
                            <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <button class="btn btn-small btn-danger" type="submit">Cancel</button>
                        </form>
                    <?php elseif ($role === 'seller' && $status === 'awaiting_approval'): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="approve">
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <button class="btn btn-small" type="submit">Approve values</button>
                        </form>
                        <form method="post" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="dispute">
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <input type="text" name="reason" maxlength="500" placeholder="Reason for dispute" required>
                            <button class="btn btn-small btn-danger" type="submit">Dispute</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($role === 'seller'): ?>
                        <?php foreach ($requestIncidents as $incident): ?>
                            <?php if ($incident['incident_status'] === 'open' && (int) $incident['reported_by'] !== $uid): ?>
                                <form method="post" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="acknowledge_missed_pickup">
                                    <input type="hidden" name="request_id" value="<?= $rid ?>">
                                    <input type="hidden" name="incident_id" value="<?= (int) $incident['incident_id'] ?>">
                                    <button class="btn btn-small btn-outline" type="submit">Acknowledge missed pickup</button>
                                </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($status === 'accepted'
                            && in_array($request['collection_status'], ['pending', 'in_progress'], true)
                            && (int) $request['collection_item_count'] === 0
                            && !empty($request['confirmed_schedule'])
                            && strtotime((string) $request['confirmed_schedule']) < time()
                            && (empty($request['buyer_suggested_schedule'])
                                || $request['buyer_suggested_schedule'] === $request['confirmed_schedule'])
                            && !$sellerAlreadyReported): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="report_missed_pickup">
                                <input type="hidden" name="request_id" value="<?= $rid ?>">
                                <label>Report a missed pickup
                                    <textarea name="details" maxlength="500" rows="2" required></textarea>
                                </label>
                                <button class="btn btn-small btn-outline" type="submit">Send report</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php if ($role === 'seller' && $requests): ?>
    <div class="row-between">
        <p class="muted">Compare current junkshop offers for your pickup area.</p>
        <a class="btn btn-outline" href="<?= e(url('prices.php?request_id=' . (int) $requests[0]['request_id'])) ?>">Nearby junkshop prices</a>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
