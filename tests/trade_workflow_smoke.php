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
     VALUES (?, 'TradeTest', ?, 'not-a-login-hash', ?, 'resident', NOW())"
);
$createUser->execute(['Seller', 'seller-trade-' . bin2hex(random_bytes(8)) . '@example.invalid', 'seller']);
$sellerId = (int) $pdo->lastInsertId();
$createUser->execute(['Buyer', 'buyer-trade-' . bin2hex(random_bytes(8)) . '@example.invalid', 'buyer']);
$buyerId = (int) $pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO buyer_profiles (user_id, company_name, barangay, city)
     VALUES (?, 'Trade Test Junkshop', 'Test Barangay', 'Test City')"
)->execute([$buyerId]);

$categoryId = (int) $pdo->query(
    'SELECT category_id FROM material_categories WHERE is_active = 1 ORDER BY category_id LIMIT 1'
)->fetchColumn();
if ($categoryId < 1) {
    throw new RuntimeException('The test database needs an active material category.');
}

$token = bin2hex(random_bytes(32));
$pdo->prepare(
    "INSERT INTO collection_requests
        (seller_id, submission_token, street_address, barangay, city, landmark, contact_number,
         consent_to_share, preferred_schedule)
     VALUES (?, ?, 'Test Street', 'Test Barangay', 'Test City', 'Test Landmark',
             '09123456789', 1, DATE_ADD(NOW(), INTERVAL 1 DAY))"
)->execute([$sellerId, $token]);
$requestId = (int) $pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO collection_request_items
        (request_id, category_id, estimated_weight_kg, estimate_method, estimated_quantity, estimated_size)
     VALUES (?, ?, 10, 'weight', 10, NULL)"
)->execute([$requestId, $categoryId]);
$requestItemId = (int) $pdo->lastInsertId();

$accept = $pdo->prepare('CALL sp_buyer_accept_request(?, ?, ?, ?)');
$accept->execute([$requestId, $buyerId, date('Y-m-d H:i:s', time() + 3600), 'cash']);
$accept->closeCursor();
$approveProposal = $pdo->prepare('CALL sp_seller_buyer_proposal_decision(?, ?, 1)');
$approveProposal->execute([$requestId, $sellerId]);
$approveProposal->closeCursor();
$collectionId = (int) $pdo->query(
    'SELECT collection_id FROM collections WHERE request_id = ' . $requestId
)->fetchColumn();
$pdo->prepare(
    "INSERT INTO collection_items
        (collection_id, request_item_id, category_id, quantity_unit, actual_quantity, unit_price)
     VALUES (?, ?, ?, 'kg', 10, 10)"
)->execute([$collectionId, $requestItemId, $categoryId]);
$submitActuals = $pdo->prepare('CALL sp_submit_actuals(?, ?)');
$submitActuals->execute([$requestId, $buyerId]);
$submitActuals->closeCursor();
$approveCollection = $pdo->prepare('CALL sp_seller_decision(?, ?, 1, NULL)');
$approveCollection->execute([$requestId, $sellerId]);
$approveCollection->closeCursor();
$stock = $pdo->prepare('SELECT remaining_quantity, collection_item_id FROM collection_items WHERE collection_id = ?');
$stock->execute([$collectionId]);
$stockRow = $stock->fetch();
$collectionItemId = (int) $stockRow['collection_item_id'];

$pdo->prepare('SET @app_user_id = ?')->execute([$sellerId]);
$createTrade = $pdo->prepare('INSERT INTO trades (seller_id, buyer_id) VALUES (?, ?)');
$addTradeItem = $pdo->prepare(
    'INSERT INTO trade_items (trade_id, collection_item_id, quantity, agreed_unit_price)
     VALUES (?, ?, ?, ?)'
);
$createTrade->execute([$sellerId, $buyerId]);
$rejectedTradeId = (int) $pdo->lastInsertId();
$addTradeItem->execute([$rejectedTradeId, $collectionItemId, 3, 10]);
$stock->execute([$collectionId]);
if ((float) $stock->fetchColumn() !== 7.0) {
    throw new RuntimeException('Creating a pending trade did not reserve the requested inventory.');
}
$pdo->prepare('UPDATE trades SET trade_status = \'rejected\' WHERE trade_id = ?')->execute([$rejectedTradeId]);
$stock->execute([$collectionId]);
if ((float) $stock->fetchColumn() !== 10.0) {
    throw new RuntimeException('Rejecting a trade did not restore its reserved stock.');
}

$createTrade->execute([$sellerId, $buyerId]);
$overstockTradeId = (int) $pdo->lastInsertId();
try {
    $addTradeItem->execute([$overstockTradeId, $collectionItemId, 11, 10]);
    throw new RuntimeException('A trade was allowed to reserve more than the available stock.');
} catch (PDOException $exception) {
    if ((string) $exception->getCode() !== '45000') {
        throw $exception;
    }
}
$pdo->prepare('UPDATE trades SET trade_status = \'rejected\' WHERE trade_id = ?')->execute([$overstockTradeId]);

$pdo->prepare('SET @app_user_id = ?')->execute([$sellerId]);
$createTrade->execute([$sellerId, $buyerId]);
$tradeId = (int) $pdo->lastInsertId();
$addTradeItem->execute([$tradeId, $collectionItemId, 2, 10]);
$pdo->prepare('SET @app_user_id = ?')->execute([$buyerId]);
$pdo->prepare("UPDATE trades SET trade_status = 'accepted' WHERE trade_id = ?")->execute([$tradeId]);
$payment = $pdo->prepare(
    "INSERT INTO transaction_payments (trade_id, amount_paid, payment_method, payment_status)
     VALUES (?, ?, 'cash', 'pending')"
);
$payment->execute([$tradeId, 10]);
$paymentId = (int) $pdo->lastInsertId();
$totals = $pdo->prepare('SELECT total_amount, total_paid, balance_due FROM v_trade_totals WHERE trade_id = ?');
$totals->execute([$tradeId]);
$tradeTotals = $totals->fetch();
if ((float) $tradeTotals['total_amount'] !== 20.0
    || (float) $tradeTotals['total_paid'] !== 0.0
    || (float) $tradeTotals['balance_due'] !== 20.0) {
    throw new RuntimeException('A pending trade payment was incorrectly counted as confirmed earnings.');
}
$pdo->prepare('SET @app_user_id = ?')->execute([$sellerId]);
$pdo->prepare(
    "UPDATE transaction_payments
        SET payment_status = 'paid', received_by = ?
      WHERE payment_id = ?"
)->execute([$sellerId, $paymentId]);
$totals->execute([$tradeId]);
$tradeTotals = $totals->fetch();
if ((float) $tradeTotals['total_paid'] !== 10.0
    || (float) $tradeTotals['balance_due'] !== 10.0) {
    throw new RuntimeException('Confirming a partial trade payment did not update the paid total and balance.');
}
$payment->execute([$tradeId, 10]);
$paymentId = (int) $pdo->lastInsertId();
$pdo->prepare(
    "UPDATE transaction_payments
        SET payment_status = 'paid', received_by = ?
      WHERE payment_id = ?"
)->execute([$sellerId, $paymentId]);
$pdo->prepare("UPDATE trades SET trade_status = 'completed' WHERE trade_id = ?")->execute([$tradeId]);
$totals->execute([$tradeId]);
$tradeTotals = $totals->fetch();
$tradeStatus = $pdo->prepare('SELECT trade_status FROM trades WHERE trade_id = ?');
$tradeStatus->execute([$tradeId]);
if ($tradeStatus->fetchColumn() !== 'completed'
    || (float) $tradeTotals['total_paid'] !== 20.0
    || (float) $tradeTotals['balance_due'] !== 0.0) {
    throw new RuntimeException('Fully confirmed payments did not close the trade balance.');
}
$stock->execute([$collectionId]);
if ((float) $stock->fetchColumn() !== 8.0) {
    throw new RuntimeException('Completing a paid trade did not keep sold stock deducted.');
}

$recordCollectionPayment = $pdo->prepare(
    "CALL sp_record_collection_payment(?, ?, ?, 'cash', ?, ?)"
);
$recordCollectionPayment->execute([
    $collectionId,
    $buyerId,
    10.00,
    'trade-report-confirmed-' . bin2hex(random_bytes(5)),
    bin2hex(random_bytes(32)),
]);
$recordCollectionPayment->closeCursor();
$collectionPaymentId = (int) $pdo->query(
    'SELECT payment_id FROM collection_payments WHERE collection_id = ' . $collectionId
    . " AND payment_status = 'pending' ORDER BY payment_id DESC LIMIT 1"
)->fetchColumn();
$pdo->prepare('SET @app_user_id = ?')->execute([$sellerId]);
$pdo->prepare(
    "UPDATE collection_payments
        SET payment_status = 'paid', confirmed_by = ?, confirmed_at = NOW()
      WHERE payment_id = ?"
)->execute([$sellerId, $collectionPaymentId]);
$recordCollectionPayment->execute([
    $collectionId,
    $buyerId,
    5.00,
    'trade-report-pending-' . bin2hex(random_bytes(5)),
    bin2hex(random_bytes(32)),
]);
$recordCollectionPayment->closeCursor();

$adminRequestCheck = $pdo->prepare(
    "SELECT r.request_id, c.collection_status, ct.final_total,
            cps.total_due, cps.paid_total, cps.pending_total, cps.payment_status
       FROM collection_requests r
       LEFT JOIN collections c ON c.request_id = r.request_id
       LEFT JOIN v_collection_totals ct ON ct.collection_id = c.collection_id
       LEFT JOIN v_collection_payment_status cps ON cps.collection_id = c.collection_id
      WHERE r.request_id = ?"
);
$adminRequestCheck->execute([$requestId]);
$adminRequest = $adminRequestCheck->fetch();
if (!$adminRequest
    || $adminRequest['collection_status'] !== 'completed'
    || (float) $adminRequest['final_total'] !== 100.0
    || (float) $adminRequest['paid_total'] !== 10.0
    || (float) $adminRequest['pending_total'] !== 5.0) {
    throw new RuntimeException('The administrator collection report did not expose final, confirmed, and pending values separately.');
}

$adminTradeCheck = $pdo->prepare(
    "SELECT t.trade_id, t.trade_status,
            COALESCE(v.total_amount, 0) AS total_amount,
            COALESCE(v.total_paid, 0) AS total_paid,
            COALESCE(p.pending_total, 0) AS pending_total
       FROM trades t
       LEFT JOIN v_trade_totals v ON v.trade_id = t.trade_id
       LEFT JOIN (
            SELECT trade_id, SUM(amount_paid) AS pending_total
              FROM transaction_payments
             WHERE payment_status = 'pending'
             GROUP BY trade_id
       ) p ON p.trade_id = t.trade_id
      WHERE t.trade_id = ?"
);
$adminTradeCheck->execute([$tradeId]);
$adminTrade = $adminTradeCheck->fetch();
if (!$adminTrade
    || $adminTrade['trade_status'] !== 'completed'
    || (float) $adminTrade['total_amount'] !== 20.0
    || (float) $adminTrade['total_paid'] !== 20.0
    || (float) $adminTrade['pending_total'] !== 0.0) {
    throw new RuntimeException('The administrator trade report did not expose the final transaction totals.');
}

$collectionEarningsQuery = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN cp.payment_status = 'paid' THEN cp.amount_paid ELSE 0 END), 0) AS confirmed_total,
            COALESCE(SUM(CASE WHEN cp.payment_status = 'pending' THEN cp.amount_paid ELSE 0 END), 0) AS pending_total
       FROM collection_payments cp
       JOIN collections c ON c.collection_id = cp.collection_id
       JOIN collection_requests r ON r.request_id = c.request_id
      WHERE r.seller_id = ? AND r.request_status = 'completed'
        AND c.collection_status = 'completed'"
);
$collectionEarningsQuery->execute([$sellerId]);
$collectionEarnings = $collectionEarningsQuery->fetch();
if ((float) $collectionEarnings['confirmed_total'] !== 10.0
    || (float) $collectionEarnings['pending_total'] !== 5.0) {
    throw new RuntimeException('The seller collection earnings summary did not separate confirmed and pending payments.');
}
$tradeEarningsQuery = $pdo->prepare(
    "SELECT COALESCE(SUM(v.total_paid), 0) AS confirmed_total,
            COALESCE(SUM(p.pending_total), 0) AS pending_total
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
$tradeEarningsQuery->execute([$sellerId]);
$tradeEarnings = $tradeEarningsQuery->fetch();
if ((float) $tradeEarnings['confirmed_total'] !== 20.0
    || (float) $tradeEarnings['pending_total'] !== 0.0) {
    throw new RuntimeException('The seller trade earnings summary included an incorrect confirmed or pending amount.');
}

fwrite(STDOUT, "Trade stock reservation/restoration, overstock protection, partial-payment totals, and seller earnings summaries passed.\n");
