<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$databaseName = getenv('KALAKAL_DB_NAME') ?: 'kalakal';
if (getenv('KALAKAL_TEST_DB') !== '1'
    || !preg_match('/_test(?:_|$)/', $databaseName)) {
    fwrite(STDERR, "Refusing to run: set KALAKAL_TEST_DB=1 and use a database name containing _test.\n");
    exit(2);
}

require_once __DIR__ . '/../config/config.php';
date_default_timezone_set(APP_TIMEZONE);
$pdo = db();
$pdo->exec("SET time_zone = '" . DB_TIME_OFFSET . "'");

$createUser = $pdo->prepare(
    "INSERT INTO users (first_name, last_name, email, password_hash, user_role, seller_type, email_verified_at)
     VALUES (?, 'PaymentTest', ?, 'not-a-login-hash', ?, 'resident', NOW())"
);
$createUser->execute(['Seller', 'seller-payment-' . bin2hex(random_bytes(8)) . '@example.invalid', 'seller']);
$sellerId = (int) $pdo->lastInsertId();
$createUser->execute(['Buyer', 'buyer-payment-' . bin2hex(random_bytes(8)) . '@example.invalid', 'buyer']);
$buyerId = (int) $pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO buyer_profiles (user_id, company_name, barangay, city)
     VALUES (?, 'Payment Test Junkshop', 'Test Barangay', 'Test City')"
)->execute([$buyerId]);
$pdo->prepare('UPDATE buyer_profiles SET city = ? WHERE user_id = ?')->execute(['Other City', $buyerId]);

$categoryId = (int) $pdo->query(
    'SELECT category_id FROM material_categories WHERE is_active = 1 ORDER BY category_id LIMIT 1'
)->fetchColumn();
if ($categoryId < 1) {
    throw new RuntimeException('The test database needs an active material category.');
}

$submissionToken = bin2hex(random_bytes(32));
$createRequest = $pdo->prepare(
    "INSERT INTO collection_requests
        (seller_id, submission_token, street_address, barangay, city, landmark, contact_number,
         consent_to_share, preferred_schedule)
     VALUES (?, ?, 'Test Street', 'Test Barangay', 'Test City', 'Test Landmark',
             '09123456789', 1, DATE_ADD(NOW(), INTERVAL 1 DAY))"
);
$createRequest->execute([$sellerId, $submissionToken]);
$requestId = (int) $pdo->lastInsertId();
$availableRequest = $pdo->prepare(
    "SELECT COUNT(*) FROM collection_requests r
      JOIN buyer_profiles bp ON bp.user_id = ?
      WHERE r.request_id = ? AND r.request_status = 'pending'
        AND (r.preferred_buyer_id IS NULL OR r.preferred_buyer_id = ?)
        AND LOWER(TRIM(r.city)) <> LOWER(TRIM(bp.city))"
);
$availableRequest->execute([$buyerId, $requestId, $buyerId]);
if ((int) $availableRequest->fetchColumn() !== 1) {
    throw new RuntimeException('An open request from another city was not visible to the buyer.');
}
$newRequestNotification = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications
      WHERE user_id = ? AND notification_type = 'new_pickup_request'
        AND related_table = 'collection_requests' AND related_id = ?"
);
$newRequestNotification->execute([$buyerId, $requestId]);
if ((int) $newRequestNotification->fetchColumn() !== 1) {
    throw new RuntimeException('The buyer was not notified about an open request from another city.');
}
try {
    $createRequest->execute([$sellerId, $submissionToken]);
    throw new RuntimeException('A repeated collection request submission should be blocked.');
} catch (PDOException $exception) {
    if ((string) $exception->getCode() !== '23000') {
        throw $exception;
    }
}
$requestCount = $pdo->prepare(
    'SELECT COUNT(*) FROM collection_requests WHERE seller_id = ? AND submission_token = ?'
);
$requestCount->execute([$sellerId, $submissionToken]);
if ((int) $requestCount->fetchColumn() !== 1) {
    throw new RuntimeException('The idempotency key did not resolve to exactly one request.');
}

$pdo->prepare(
    "INSERT INTO collection_request_items
        (request_id, category_id, estimated_weight_kg, estimate_method, estimated_quantity, estimated_size)
     VALUES (?, ?, 2, 'detailed', 10, 'small')"
)->execute([$requestId, $categoryId]);
$requestItemId = (int) $pdo->lastInsertId();

$accept = $pdo->prepare('CALL sp_buyer_accept_request(?, ?, ?, ?)');
$accept->execute([$requestId, $buyerId, date('Y-m-d H:i:s', time() + 3600), 'cash']);
$accept->closeCursor();
$decision = $pdo->prepare('CALL sp_seller_buyer_proposal_decision(?, ?, 1)');
$decision->execute([$requestId, $sellerId]);
$decision->closeCursor();

$confirmedSchedule = $pdo->prepare(
    'SELECT confirmed_schedule FROM collection_requests WHERE request_id = ?'
);
$newSchedule = date('Y-m-d H:i:s', time() + 7200);
$reschedule = $pdo->prepare('CALL sp_buyer_request_reschedule(?, ?, ?)');
$reschedule->execute([$requestId, $buyerId, $newSchedule]);
$reschedule->closeCursor();
try {
    $reschedule->execute([$requestId, $buyerId, date('Y-m-d H:i:s', time() + 10800)]);
    $reschedule->closeCursor();
    throw new RuntimeException('A second pending reschedule should be blocked.');
} catch (PDOException $exception) {
    $reschedule->closeCursor();
    if ((string) $exception->getCode() !== '45000') {
        throw $exception;
    }
}
$rescheduleDecision = $pdo->prepare('CALL sp_seller_reschedule_decision(?, ?, 1)');
$rescheduleDecision->execute([$requestId, $sellerId]);
$rescheduleDecision->closeCursor();
$confirmedSchedule->execute([$requestId]);
if ($confirmedSchedule->fetchColumn() !== $newSchedule) {
    throw new RuntimeException('Seller approval did not update the confirmed pickup schedule.');
}

$declinedSchedule = date('Y-m-d H:i:s', time() + 10800);
$reschedule->execute([$requestId, $buyerId, $declinedSchedule]);
$reschedule->closeCursor();
$declineReschedule = $pdo->prepare('CALL sp_seller_reschedule_decision(?, ?, 0)');
$declineReschedule->execute([$requestId, $sellerId]);
$declineReschedule->closeCursor();
$confirmedSchedule->execute([$requestId]);
if ($confirmedSchedule->fetchColumn() !== $newSchedule) {
    throw new RuntimeException('Declining a reschedule changed the confirmed pickup time.');
}

$reportMissed = $pdo->prepare('CALL sp_report_missed_pickup(?, ?, ?)');
try {
    $reportMissed->execute([$requestId, $buyerId, 'This pickup is not overdue.']);
    $reportMissed->closeCursor();
    throw new RuntimeException('A future pickup was incorrectly accepted as missed.');
} catch (PDOException $exception) {
    $reportMissed->closeCursor();
    if ((string) $exception->getCode() !== '45000') {
        throw $exception;
    }
}
$pdo->prepare(
    'UPDATE collection_requests SET confirmed_schedule = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE request_id = ?'
)->execute([$requestId]);
$reportMissed->execute([$requestId, $buyerId, 'The scheduled pickup did not take place.']);
$reportMissed->closeCursor();
$reportNotification = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications
      WHERE user_id = ? AND notification_type = 'missed_pickup_reported'
        AND related_table = 'collection_requests' AND related_id = ?"
);
$reportNotification->execute([$sellerId, $requestId]);
if ((int) $reportNotification->fetchColumn() !== 1) {
    throw new RuntimeException('The other participant was not notified of the missed-pickup report.');
}
try {
    $reportMissed->execute([$requestId, $buyerId, 'Duplicate missed pickup report.']);
    $reportMissed->closeCursor();
    throw new RuntimeException('The same participant submitted a duplicate missed-pickup report.');
} catch (PDOException $exception) {
    $reportMissed->closeCursor();
    if ((string) $exception->getCode() !== '45000') {
        throw $exception;
    }
}
$incidentId = (int) $pdo->query(
    'SELECT incident_id FROM collection_incidents WHERE request_id = ' . $requestId
)->fetchColumn();
$acknowledgeIncident = $pdo->prepare('CALL sp_acknowledge_missed_pickup(?, ?)');
try {
    $acknowledgeIncident->execute([$incidentId, $buyerId]);
    $acknowledgeIncident->closeCursor();
    throw new RuntimeException('The reporter acknowledged their own missed-pickup report.');
} catch (PDOException $exception) {
    $acknowledgeIncident->closeCursor();
    if ((string) $exception->getCode() !== '45000') {
        throw $exception;
    }
}
$acknowledgeIncident->execute([$incidentId, $sellerId]);
$acknowledgeIncident->closeCursor();
$incidentStatus = $pdo->prepare(
    'SELECT incident_status FROM collection_incidents WHERE incident_id = ?'
);
$incidentStatus->execute([$incidentId]);
if ($incidentStatus->fetchColumn() !== 'resolved') {
    throw new RuntimeException('The other participant could not acknowledge the missed-pickup report.');
}
$ackNotification = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications
      WHERE user_id = ? AND notification_type = 'missed_pickup_acknowledged'
        AND related_table = 'collection_requests' AND related_id = ?"
);
$ackNotification->execute([$buyerId, $requestId]);
if ((int) $ackNotification->fetchColumn() !== 1) {
    throw new RuntimeException('The reporter was not notified when the report was acknowledged.');
}
$requestStatusCheck = $pdo->prepare(
    'SELECT r.request_status, c.collection_status
       FROM collection_requests r JOIN collections c ON c.request_id = r.request_id
      WHERE r.request_id = ?'
);
$requestStatusCheck->execute([$requestId]);
$workflowState = $requestStatusCheck->fetch();
if ($workflowState['request_status'] !== 'accepted'
    || $workflowState['collection_status'] !== 'pending') {
    throw new RuntimeException('A missed-pickup report changed collection or payment workflow status.');
}
$pdo->prepare(
    'UPDATE collection_requests SET confirmed_schedule = ? WHERE request_id = ?'
)->execute([$newSchedule, $requestId]);

$collectionId = (int) $pdo->query(
    'SELECT collection_id FROM collections WHERE request_id = ' . $requestId
)->fetchColumn();
$pdo->prepare(
    'INSERT INTO collection_items
        (collection_id, request_item_id, category_id, quantity_unit, actual_quantity, unit_price)
     VALUES (?, ?, ?, \'kg\', 2, 10)'
)->execute([$collectionId, $requestItemId, $categoryId]);
try {
    $reschedule->execute([$requestId, $buyerId, date('Y-m-d H:i:s', time() + 14400)]);
    $reschedule->closeCursor();
    throw new RuntimeException('Rescheduling after collection items were recorded should be blocked.');
} catch (PDOException $exception) {
    if ((string) $exception->getCode() !== '45000') {
        throw $exception;
    }
}

$submit = $pdo->prepare('CALL sp_submit_actuals(?, ?)');
$submit->execute([$requestId, $buyerId]);
$submit->closeCursor();
$approve = $pdo->prepare('CALL sp_seller_decision(?, ?, 1, NULL)');
$approve->execute([$requestId, $sellerId]);
$approve->closeCursor();

$status = $pdo->prepare('SELECT * FROM v_collection_payment_status WHERE collection_id = ?');
$status->execute([$collectionId]);
$paymentStatus = $status->fetch();
if (!$paymentStatus || $paymentStatus['payment_status'] !== 'unpaid'
    || (float) $paymentStatus['total_due'] !== 20.0) {
    throw new RuntimeException('Collection completion incorrectly changed the separate payment status.');
}

$record = $pdo->prepare('CALL sp_record_collection_payment(?, ?, 10.00, \'cash\', ?, ?)');
$firstKey = bin2hex(random_bytes(32));
$record->execute([$collectionId, $buyerId, 'payment-ref-1', $firstKey]);
$record->closeCursor();

try {
    $record->execute([$collectionId, $buyerId, 'payment-ref-1', $firstKey]);
    $record->closeCursor();
    throw new RuntimeException('A repeated payment report should not create a second pending payment.');
} catch (PDOException $exception) {
    if ((string) $exception->getCode() !== '45000') {
        throw $exception;
    }
}

$status->execute([$collectionId]);
$paymentStatus = $status->fetch();
if ($paymentStatus['payment_status'] !== 'pending'
    || (float) $paymentStatus['paid_total'] !== 0.0
    || (float) $paymentStatus['pending_total'] !== 10.0) {
    throw new RuntimeException('Pending payment was not separated from confirmed payment.');
}

$paymentId = (int) $pdo->query(
    'SELECT payment_id FROM collection_payments WHERE collection_id = ' . $collectionId
)->fetchColumn();
$pdo->prepare('SET @app_user_id = ?')->execute([$sellerId]);
$confirm = $pdo->prepare(
    "UPDATE collection_payments
        SET payment_status = 'paid', confirmed_by = ?, confirmed_at = NOW()
      WHERE payment_id = ?"
);
$confirm->execute([$sellerId, $paymentId]);

$status->execute([$collectionId]);
$paymentStatus = $status->fetch();
if ($paymentStatus['payment_status'] !== 'partial'
    || (float) $paymentStatus['paid_total'] !== 10.0) {
    throw new RuntimeException('Seller confirmation did not update the partial-payment status.');
}

$secondKey = bin2hex(random_bytes(32));
$record->execute([$collectionId, $buyerId, 'payment-ref-2', $secondKey]);
$record->closeCursor();
$paymentId = (int) $pdo->query(
    'SELECT payment_id FROM collection_payments WHERE collection_id = ' . $collectionId . ' AND payment_status = \'pending\''
)->fetchColumn();
$confirm->execute([$sellerId, $paymentId]);
$status->execute([$collectionId]);
$paymentStatus = $status->fetch();
if ($paymentStatus['payment_status'] !== 'paid'
    || (float) $paymentStatus['paid_total'] !== 20.0) {
    throw new RuntimeException('The final confirmed payment did not close the balance.');
}

$secondToken = bin2hex(random_bytes(32));
$pdo->prepare(
    "INSERT INTO collection_requests
        (seller_id, submission_token, street_address, barangay, city, landmark, contact_number,
         consent_to_share, preferred_schedule)
     VALUES (?, ?, 'Test Street', 'Test Barangay', 'Request City', 'Test Landmark',
             '09123456789', 1, DATE_ADD(NOW(), INTERVAL 1 DAY))"
)->execute([$sellerId, $secondToken]);
$disputeRequestId = (int) $pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO collection_request_items
        (request_id, category_id, estimated_weight_kg, estimate_method, estimated_quantity, estimated_size)
     VALUES (?, ?, 2, 'detailed', 10, 'small')"
)->execute([$disputeRequestId, $categoryId]);
$disputeItemId = (int) $pdo->lastInsertId();
$accept->execute([$disputeRequestId, $buyerId, date('Y-m-d H:i:s', time() + 3600), 'cash']);
$accept->closeCursor();
$decision->execute([$disputeRequestId, $sellerId]);
$decision->closeCursor();
$disputeCollectionId = (int) $pdo->query(
    'SELECT collection_id FROM collections WHERE request_id = ' . $disputeRequestId
)->fetchColumn();
$pdo->prepare(
    'INSERT INTO collection_items
        (collection_id, request_item_id, category_id, quantity_unit, actual_quantity, unit_price)
     VALUES (?, ?, ?, \'kg\', 2, 10)'
)->execute([$disputeCollectionId, $disputeItemId, $categoryId]);
$submit->execute([$disputeRequestId, $buyerId]);
$submit->closeCursor();
$dispute = $pdo->prepare('CALL sp_seller_decision(?, ?, 0, ?)');
$dispute->execute([$disputeRequestId, $sellerId, 'Quantity needs review']);
$dispute->closeCursor();
$disputeCheck = $pdo->prepare(
    'SELECT r.request_status, c.collection_status
       FROM collection_requests r JOIN collections c ON c.request_id = r.request_id
      WHERE r.request_id = ?'
);
$disputeCheck->execute([$disputeRequestId]);
$disputeStatus = $disputeCheck->fetch();
if ($disputeStatus['request_status'] !== 'disputed'
    || $disputeStatus['collection_status'] !== 'disputed') {
    throw new RuntimeException('Dispute status did not synchronize across request and collection.');
}
$pdo->prepare('UPDATE collection_items SET unit_price = 12 WHERE collection_id = ?')
    ->execute([$disputeCollectionId]);
$submit->execute([$disputeRequestId, $buyerId]);
$submit->closeCursor();
$approve->execute([$disputeRequestId, $sellerId]);
$approve->closeCursor();
$disputeCheck->execute([$disputeRequestId]);
$disputeStatus = $disputeCheck->fetch();
if ($disputeStatus['request_status'] !== 'completed'
    || $disputeStatus['collection_status'] !== 'completed') {
    throw new RuntimeException('A resolved dispute did not complete both workflow records.');
}

$cancelToken = bin2hex(random_bytes(32));
$pdo->prepare(
    "INSERT INTO collection_requests
        (seller_id, submission_token, street_address, barangay, city, landmark, contact_number,
         consent_to_share, preferred_schedule)
     VALUES (?, ?, 'Test Street', 'Test Barangay', 'Test City', 'Test Landmark',
             '09123456789', 1, DATE_ADD(NOW(), INTERVAL 1 DAY))"
)->execute([$sellerId, $cancelToken]);
$cancelRequestId = (int) $pdo->lastInsertId();
$accept->execute([$cancelRequestId, $buyerId, date('Y-m-d H:i:s', time() + 3600), 'cash']);
$accept->closeCursor();
$decision->execute([$cancelRequestId, $sellerId]);
$decision->closeCursor();
$pdo->beginTransaction();
$pdo->prepare('SET @app_user_id = ?')->execute([$sellerId]);
$pdo->prepare(
    "UPDATE collection_requests SET request_status = 'cancelled'
      WHERE request_id = ? AND seller_id = ?"
)->execute([$cancelRequestId, $sellerId]);
$pdo->prepare(
    "UPDATE collections SET collection_status = 'cancelled'
      WHERE request_id = ? AND collection_status = 'pending'"
)->execute([$cancelRequestId]);
$pdo->commit();
$cancelNotification = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications
      WHERE user_id = ? AND notification_type = 'request_cancelled'
        AND related_table = 'collection_requests' AND related_id = ?"
);
$cancelNotification->execute([$buyerId, $cancelRequestId]);
if ((int) $cancelNotification->fetchColumn() !== 1) {
    throw new RuntimeException('Cancelling an accepted collection did not notify its junkshop exactly once.');
}

foreach ([
    ['UPDATE collection_requests SET request_status = \'pending\' WHERE request_id = ?', $requestId],
    ['UPDATE collections SET collection_status = \'pending\' WHERE collection_id = ?', $collectionId],
] as [$sql, $id]) {
    try {
        $pdo->prepare($sql)->execute([$id]);
        throw new RuntimeException('A completed record was reopened.');
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() !== '45000') {
            throw $exception;
        }
    }
}

fwrite(STDOUT, "Request idempotency, rescheduling, missed-pickup reporting, cancellation notifications, collection/dispute transitions, and separate payment confirmation passed.\n");
