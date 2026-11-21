<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(['buyer']);

$user = current_user();
$uid = (int) $user['user_id'];
$pdo = db();
$needsOnboarding = needs_onboarding($user);

$st = $pdo->prepare('SELECT barangay, city, payment_method FROM buyer_profiles WHERE user_id = ?');
$st->execute([$uid]);
$shop = $st->fetch() ?: [];
$shopCity = trim((string) ($shop['city'] ?? ''));
$shopBarangay = trim((string) ($shop['barangay'] ?? ''));
$shopPaymentMethod = (string) ($shop['payment_method'] ?? 'cash');

/* ---------- Propose a collection request ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    if (!$requestId || $requestId < 1) {
        flash('error', 'Choose a valid collection request.');
        redirect('buyer_requests.php');
    }
    try {
        if ($action === 'report_missed_pickup') {
            $details = trim((string) ($_POST['details'] ?? ''));
            if ($details === '' || mb_strlen($details) > 500) {
                throw new InvalidArgumentException('Enter a missed-pickup note of up to 500 characters.');
            }
            $call = $pdo->prepare('CALL sp_report_missed_pickup(?, ?, ?)');
            $call->execute([$requestId, $uid, $details]);
            $call->closeCursor();
            flash('success', "Missed pickup for collection #$requestId was reported to the waste owner.");
        } elseif ($action === 'acknowledge_missed_pickup') {
            $incidentId = filter_var($_POST['incident_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$incidentId || $incidentId < 1) {
                throw new InvalidArgumentException('Choose a valid missed-pickup report.');
            }
            $call = $pdo->prepare('CALL sp_acknowledge_missed_pickup(?, ?)');
            $call->execute([$incidentId, $uid]);
            $call->closeCursor();
            flash('success', "Missed-pickup report for collection #$requestId acknowledged.");
        } elseif ($action === 'reschedule') {
            $scheduleInput = trim((string) ($_POST['reschedule_schedule'] ?? ''));
            $newSchedule = DateTime::createFromFormat('Y-m-d\TH:i', $scheduleInput);
            if (!$newSchedule || $newSchedule <= new DateTime()
                || $newSchedule->format('Y-m-d\TH:i') !== $scheduleInput) {
                throw new InvalidArgumentException('Choose a valid future pickup time.');
            }
            $call = $pdo->prepare('CALL sp_buyer_request_reschedule(?, ?, ?)');
            $call->execute([$requestId, $uid, $newSchedule->format('Y-m-d H:i:s')]);
            $call->closeCursor();
            flash('success', "Your reschedule request for collection #$requestId was sent to the waste owner.");
        } elseif ($action === 'propose') {
            if ($needsOnboarding) {
                throw new InvalidArgumentException('Complete your account details before proposing a collection.');
            }
            $scheduleInput = trim((string) ($_POST['suggested_schedule'] ?? ''));
            $suggestedSchedule = DateTime::createFromFormat('Y-m-d\TH:i', $scheduleInput);
            $paymentMethod = (string) ($_POST['payment_method'] ?? '');
            if (!$suggestedSchedule || $suggestedSchedule <= new DateTime()
                || $suggestedSchedule->format('Y-m-d\TH:i') !== $scheduleInput) {
                throw new InvalidArgumentException('Choose a valid future collection time.');
            }
            if (!in_array($paymentMethod, ['cash', 'gcash', 'maya'], true)) {
                throw new InvalidArgumentException('Choose a supported payment method.');
            }
            $call = $pdo->prepare('CALL sp_buyer_accept_request(?, ?, ?, ?)');
            $call->execute([$requestId, $uid, $suggestedSchedule->format('Y-m-d H:i:s'), $paymentMethod]);
            $call->closeCursor();
            flash('success', "Your collection proposal for request #$requestId was sent to the seller.");
        } else {
            throw new InvalidArgumentException('That collection action is not allowed.');
        }
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    } catch (PDOException $ex) {
        // The procedure raises readable messages (SQLSTATE 45000); anything else is logged.
        if ((string) $ex->getCode() === '45000') {
            flash('error', (string) ($ex->errorInfo[2] ?? 'That request could not be accepted.'));
        } else {
            error_log($ex->getMessage());
            flash('error', 'The request could not be accepted. Please try again.');
        }
    }
    redirect('buyer_requests.php');
}

/* ---------- Open requests available to this junkshop ---------- */
$open = [];
$q = $pdo->prepare(
    "SELECT r.request_id, r.barangay, r.city, r.preferred_schedule, r.requested_date,
            r.preferred_buyer_id, r.distance_km, r.pickup_charge
       FROM collection_requests r
      WHERE r.request_status = 'pending'
        AND (r.preferred_buyer_id IS NULL OR r.preferred_buyer_id = ?)
      ORDER BY (r.preferred_buyer_id = ?) DESC,
                (LOWER(TRIM(r.city)) = LOWER(?)) DESC,
                (LOWER(TRIM(r.barangay)) = LOWER(?)) DESC,
                r.preferred_schedule, r.request_id"
);
$q->execute([$uid, $uid, $shopCity, $shopBarangay]);
$open = $q->fetchAll();

/* ---------- Requests this junkshop accepted ---------- */
$q = $pdo->prepare(
    "SELECT r.request_id, r.request_status, r.confirmed_schedule, r.preferred_schedule,
            r.buyer_suggested_schedule, r.buyer_payment_method,
            r.street_address, r.barangay, r.city, r.landmark, r.pickup_notes, r.contact_number,
            r.distance_km, r.pickup_charge,
            c.collection_status, COALESCE(ci.item_count, 0) AS collection_item_count,
            CONCAT_WS(' ', s.first_name, s.last_name) AS seller_name,
            s.ewallet_provider, s.ewallet_qr_file_id, r.updated_at
       FROM collection_requests r
       JOIN users s ON s.user_id = r.seller_id
       LEFT JOIN collections c ON c.request_id = r.request_id
       LEFT JOIN (
            SELECT collection_id, COUNT(*) AS item_count
              FROM collection_items
             GROUP BY collection_id
       ) ci ON ci.collection_id = c.collection_id
      WHERE r.accepted_buyer_id = ?
      ORDER BY (r.request_status = 'accepted') DESC, r.request_id DESC
      LIMIT 100"
);
$q->execute([$uid]);
$mine = $q->fetchAll();

/* ---------- Materials for every listed request ---------- */
$ids = array_merge(array_column($open, 'request_id'), array_column($mine, 'request_id'));
$items = [];
$photos = [];
$incidentsByRequest = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare(
        "SELECT ri.request_id, mc.category_name, ri.estimate_method, ri.estimated_quantity,
                ri.estimated_weight_kg, ri.estimated_size, ri.photo_file_id
           FROM collection_request_items ri
           JOIN material_categories mc ON mc.category_id = ri.category_id
          WHERE ri.request_id IN ($in)
          ORDER BY mc.category_name"
    );
    $q->execute($ids);
    $trim = static fn($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
    foreach ($q->fetchAll() as $item) {
        $rid = (int) $item['request_id'];
        if ($item['estimate_method'] === 'detailed') {
            $line = $item['category_name'] . ': ' . $trim($item['estimated_quantity']) . ' pcs, '
                . $item['estimated_size'] . ', ' . $trim($item['estimated_weight_kg']) . ' kg';
        } elseif ($item['estimate_method'] === 'weight') {
            $line = $item['category_name'] . ': ' . $trim($item['estimated_quantity']) . ' kg';
        } elseif ($item['estimate_method'] === 'pieces') {
            $line = $item['category_name'] . ': ' . $trim($item['estimated_quantity']) . ' pcs';
        } else {
            $line = $item['category_name'] . ': ' . $trim($item['estimated_quantity']) . ' ' . $item['estimated_size'] . ' items';
        }
        $items[$rid][] = $line;
        if ($item['photo_file_id'] !== null) {
            $photos[$rid][] = (int) $item['photo_file_id'];
        }
    }
    $mineIds = array_map('intval', array_column($mine, 'request_id'));
    if ($mineIds) {
        $inMine = implode(',', array_fill(0, count($mineIds), '?'));
        $incidentQuery = $pdo->prepare(
            "SELECT i.incident_id, i.request_id, i.reported_by, i.details,
                    i.incident_status, i.created_at,
                    CONCAT_WS(' ', u.first_name, u.last_name) AS reporter_name
               FROM collection_incidents i
               JOIN users u ON u.user_id = i.reported_by
              WHERE i.request_id IN ($inMine)
              ORDER BY i.created_at"
        );
        $incidentQuery->execute($mineIds);
        foreach ($incidentQuery->fetchAll() as $incident) {
            $incidentsByRequest[(int) $incident['request_id']][] = $incident;
        }
    }
}

$renderMaterials = static function (int $rid) use ($items, $photos): void {
    if (empty($items[$rid])) {
        echo '—';
        return;
    }
    echo '<ul class="item-detail-list">';
    foreach ($items[$rid] as $line) {
        echo '<li>' . e($line) . '</li>';
    }
    echo '</ul>';
};
$renderPhotos = static function (int $rid) use ($photos): void {
    foreach ($photos[$rid] ?? [] as $photoId) {
        $src = e(url('waste_photo.php?id=' . $photoId));
        echo '<a class="scrap-photo-link" href="' . $src . '" target="_blank" rel="noopener">'
            . '<img class="scrap-photo" src="' . $src . '" alt="Waste material photo" loading="lazy"></a>';
    }
};

$pageTitle = 'Collection Requests';
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <div><span class="eyebrow">Junkshop workspace</span><h1>Collection requests</h1></div>
    <a class="btn btn-outline" href="<?= e(url('prices.php')) ?>">Update buying prices</a>
</div>

<?php if ($needsOnboarding): ?>
    <div class="alert alert-info" role="status">
        You can review collection requests now. <a href="<?= e(url('profile.php')) ?>">Complete your account details</a> before sending a proposal.
    </div>
<?php endif; ?>

<section class="card">
    <h2>Open requests</h2>
    <?php if (!$open): ?>
        <p class="muted">No open requests right now. New requests from any city will appear here.</p>
    <?php else: ?>
        <p class="muted">Requests from every city are available to junkshops unless the seller chose a specific junkshop. Propose a pickup time and payment method; the seller must approve before pickup details are shared.</p>
        <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Requested pickup</th><th>Area</th><th>Waste materials (quantity, size, weight)</th><th>Photos</th><th>Your proposal</th></tr></thead>
            <tbody>
            <?php foreach ($open as $row): $rid = (int) $row['request_id']; ?>
                <tr>
                    <td><?= $rid ?></td>
                    <td><?= e(fmt_date($row['preferred_schedule'])) ?></td>
                    <td><?= e($row['barangay'] . ', ' . $row['city']) ?>
                        <?php if ($row['preferred_buyer_id'] !== null): ?>
                            <br><span class="badge badge-accepted">Chose your junkshop</span>
                            <?php if ((float) $row['pickup_charge'] > 0): ?>
                                <br><small>Outside your city · <?= e(number_format((float) $row['distance_km'], 1)) ?> km<br>Pickup charge <strong><?= e(peso($row['pickup_charge'])) ?></strong> (paid by the owner)</small>
                            <?php endif; ?>
                        <?php endif; ?></td>
                    <td><?php $renderMaterials($rid); ?></td>
                    <td><?php $renderPhotos($rid); ?></td>
                    <td class="actions">
                        <?php if ($needsOnboarding): ?>
                            <a class="btn btn-small" href="<?= e(url('profile.php')) ?>">Complete profile to propose</a>
                        <?php else: ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="propose">
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <label>Proposed pickup time
                                <input type="datetime-local" name="suggested_schedule" required
                                       value="<?= e(date('Y-m-d\TH:i', strtotime($row['preferred_schedule']))) ?>">
                            </label>
                            <label>Payment method
                                <select name="payment_method" required>
                                    <?php foreach (['cash' => 'Physical cash', 'gcash' => 'GCash', 'maya' => 'Maya'] as $method => $label): ?>
                                        <option value="<?= e($method) ?>" <?= $shopPaymentMethod === $method ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button class="btn btn-small" type="submit">Send proposal</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Your proposals and approved collections</h2>
    <?php if (!$mine): ?>
        <p class="muted">Your proposals and approved pickups will appear here. Seller contact details are available after approval.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Pickup schedule</th><th>Waste owner &amp; pickup details</th><th>Waste materials</th><th>Photos</th><th>E-wallet</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($mine as $row):
                $rid = (int) $row['request_id'];
                $cancelled = $row['request_status'] === 'cancelled';
                $requestIncidents = $incidentsByRequest[$rid] ?? [];
                $buyerAlreadyReported = false;
                foreach ($requestIncidents as $incident) {
                    if ((int) $incident['reported_by'] === $uid) {
                        $buyerAlreadyReported = true;
                    }
                }
            ?>
                <tr>
                    <td><?= $rid ?></td>
                    <td><?= e(fmt_date($row['confirmed_schedule'] ?? $row['buyer_suggested_schedule'] ?? $row['preferred_schedule'])) ?>
                        <?php if ($row['buyer_payment_method']): ?><br><small><?= e(ucfirst($row['buyer_payment_method'])) ?> payment</small><?php endif; ?></td>
                    <td>
                        <?php if ($cancelled): ?>
                            <span class="muted">Cancelled by the waste owner. Details are no longer shown.</span>
                        <?php elseif ($row['request_status'] === 'buyer_proposed'): ?>
                            <span class="muted">Waiting for the seller to review your schedule and payment proposal before sharing pickup details.</span>
                            <br><a class="btn btn-small btn-outline" href="<?= e(url('messages.php?request_id=' . $rid)) ?>">Message seller</a>
                        <?php else: ?>
                            <strong><?= e($row['seller_name']) ?></strong><br>
                            <?= e(implode(', ', array_filter([$row['street_address'], $row['barangay'], $row['city']]))) ?><br>
                            <small class="muted">Landmark: <?= e($row['landmark']) ?></small><br>
                            <a href="tel:<?= e($row['contact_number']) ?>"><?= e($row['contact_number']) ?></a>
                            <?php if ((float) $row['pickup_charge'] > 0): ?><br><small>Pickup charge: <strong><?= e(peso($row['pickup_charge'])) ?></strong> (<?= e(number_format((float) $row['distance_km'], 1)) ?> km)</small><?php endif; ?>
                            <?php if (!empty($row['pickup_notes'])): ?><br><small class="muted"><?= e($row['pickup_notes']) ?></small><?php endif; ?>
                            <?php if ($row['request_status'] === 'accepted'
                                && !empty($row['buyer_suggested_schedule'])
                                && $row['buyer_suggested_schedule'] !== $row['confirmed_schedule']): ?>
                                <br><strong>Reschedule pending seller approval:</strong>
                                <?= e(fmt_date($row['buyer_suggested_schedule'])) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php $renderMaterials($rid); ?></td>
                    <td><?php $renderPhotos($rid); ?></td>
                    <td>
                        <?php if (!$cancelled && !empty($row['ewallet_qr_file_id'])): $qrUrl = e(url('ewallet_qr.php?id=' . (int) $row['ewallet_qr_file_id'])); ?>
                            <a href="<?= $qrUrl ?>" target="_blank" rel="noopener">
                                <img class="scrap-photo" src="<?= $qrUrl ?>" alt="E-wallet QR code" loading="lazy">
                            </a>
                            <br><small class="muted"><?= e($row['ewallet_provider'] ?: 'E-wallet') ?> QR</small>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= e($row['request_status']) ?>"><?= e(ucwords(str_replace('_', ' ', $row['request_status']))) ?></span>
                        <?php if (!$cancelled && $row['request_status'] !== 'buyer_proposed'): ?>
                            <br><a class="btn btn-small btn-outline" href="<?= e(url('messages.php?request_id=' . $rid)) ?>">Message seller</a>
                        <?php endif; ?>
                        <?php if (!$cancelled && $row['request_status'] === 'accepted'
                            && in_array($row['collection_status'], ['pending', 'in_progress'], true)
                            && (int) $row['collection_item_count'] === 0
                            && (empty($row['buyer_suggested_schedule'])
                                || $row['buyer_suggested_schedule'] === $row['confirmed_schedule'])): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reschedule">
                                <input type="hidden" name="request_id" value="<?= $rid ?>">
                                <label>Request a new pickup time
                                    <input type="datetime-local" name="reschedule_schedule" required
                                        value="<?= e(date('Y-m-d\TH:i', max(time() + 3600, strtotime((string) $row['confirmed_schedule']) + 3600))) ?>">
                                </label>
                                <button class="btn btn-small btn-outline" type="submit">Request reschedule</button>
                            </form>
                        <?php endif; ?>
                        <?php foreach ($requestIncidents as $incident): ?>
                            <p class="muted">
                                <strong>Missed pickup (<?= e($incident['incident_status']) ?>)</strong>
                                · <?= e($incident['reporter_name']) ?> · <?= e(fmt_date($incident['created_at'])) ?><br>
                                <?= e($incident['details']) ?>
                            </p>
                            <?php if ($incident['incident_status'] === 'open' && (int) $incident['reported_by'] !== $uid): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="acknowledge_missed_pickup">
                                    <input type="hidden" name="request_id" value="<?= $rid ?>">
                                    <input type="hidden" name="incident_id" value="<?= (int) $incident['incident_id'] ?>">
                                    <button class="btn btn-small btn-outline" type="submit">Acknowledge missed pickup</button>
                                </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (!$cancelled && $row['request_status'] === 'accepted'
                            && in_array($row['collection_status'], ['pending', 'in_progress'], true)
                            && (int) $row['collection_item_count'] === 0
                            && !empty($row['confirmed_schedule'])
                            && strtotime((string) $row['confirmed_schedule']) < time()
                            && (empty($row['buyer_suggested_schedule'])
                                || $row['buyer_suggested_schedule'] === $row['confirmed_schedule'])
                            && !$buyerAlreadyReported): ?>
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
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
