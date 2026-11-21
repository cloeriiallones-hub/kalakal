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

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$pdo->beginTransaction();
try {
    $categories = $pdo->query(
        'SELECT category_id FROM material_categories WHERE is_active = 1 ORDER BY category_id LIMIT 3'
    )->fetchAll(PDO::FETCH_COLUMN);
    if (count($categories) < 3) {
        throw new RuntimeException('The test database needs at least three active material categories.');
    }
    [$firstCategory, $secondCategory, $unselectedCategory] = array_map('intval', $categories);

    $createUser = $pdo->prepare(
        "INSERT INTO users (first_name, last_name, email, password_hash, user_role, seller_type, email_verified_at)
         VALUES ('Compare', 'Test', ?, 'not-a-login-hash', ?, 'resident', NOW())"
    );
    $newUser = static function (string $role) use ($createUser, $pdo): int {
        $createUser->execute([
            $role . '-' . bin2hex(random_bytes(8)) . '@example.invalid',
            $role,
        ]);
        return (int) $pdo->lastInsertId();
    };

    $ownerId = $newUser('seller');
    $request = $pdo->prepare(
        "INSERT INTO collection_requests
            (seller_id, street_address, barangay, city, landmark, contact_number,
             latitude, longitude, consent_to_share, preferred_schedule)
         VALUES (?, 'Test Street', 'Test Barangay', 'Test City', 'Test Landmark',
                 '09123456789', 10.00000000, 120.00000000, 1, DATE_ADD(NOW(), INTERVAL 1 DAY))"
    );
    $request->execute([$ownerId]);
    $requestId = (int) $pdo->lastInsertId();

    $item = $pdo->prepare(
        "INSERT INTO collection_request_items
            (request_id, category_id, estimated_weight_kg, estimate_method,
             estimated_quantity, estimated_size)
         VALUES (?, ?, ?, 'detailed', ?, ?)"
    );
    $item->execute([$requestId, $firstCategory, 2, 10, 'small']);
    $item->execute([$requestId, $secondCategory, 1, 5, 'medium']);

    $createShop = $pdo->prepare(
        "INSERT INTO buyer_profiles (user_id, company_name, barangay, city, latitude, longitude)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $addShop = static function (
        string $name,
        string $city,
        ?float $latitude,
        ?float $longitude
    ) use ($newUser, $createShop, $pdo): int {
        $buyerId = $newUser('buyer');
        $createShop->execute([$buyerId, $name, 'Shop Barangay', $city, $latitude, $longitude]);
        $pdo->prepare('SET @app_user_id = ?')->execute([$buyerId]);
        return $buyerId;
    };
    $addPrice = $pdo->prepare(
        'INSERT INTO junkshop_material_prices (buyer_id, category_id, price_unit, buying_price)
         VALUES (?, ?, ?, ?)'
    );

    $shopA = $addShop('Local A', 'Test City', 10, 120);
    $addPrice->execute([$shopA, $firstCategory, 'kg', 10]);
    $addPrice->execute([$shopA, $secondCategory, 'kg', 10]);
    $addPrice->execute([$shopA, $unselectedCategory, 'kg', 10000]);

    $shopB = $addShop('Local B', 'Test City', 10, 120);
    $addPrice->execute([$shopB, $firstCategory, 'piece', 4]);
    $addPrice->execute([$shopB, $secondCategory, 'piece', 3]);

    $shopMissing = $addShop('Missing Price', 'Test City', 10, 120);
    $addPrice->execute([$shopMissing, $firstCategory, 'kg', 100]);

    $shopOutside = $addShop('Outside', 'Other City', 10, 120.1);
    $addPrice->execute([$shopOutside, $firstCategory, 'kg', 100]);
    $addPrice->execute([$shopOutside, $secondCategory, 'kg', 100]);

    $shopUnpinned = $addShop('Unpinned', 'Other City', null, null);
    $addPrice->execute([$shopUnpinned, $firstCategory, 'kg', 100]);
    $addPrice->execute([$shopUnpinned, $secondCategory, 'kg', 100]);

    $result = compare_junkshops($ownerId, $requestId);
    if (count($result['inCity']) !== 2
        || $result['inCity'][0]['id'] !== $shopB
        || abs($result['inCity'][0]['gross'] - 55.0) > 0.01) {
        throw new RuntimeException('Selected quantities and compatible units did not rank the local offers correctly.');
    }
    $localA = array_values(array_filter(
        $result['inCity'],
        static fn(array $shop): bool => $shop['id'] === $shopA
    ))[0] ?? null;
    if (!$localA || abs($localA['gross'] - 30.0) > 0.01) {
        throw new RuntimeException('An unrelated material price affected the estimate.');
    }
    if (count($result['outside']) !== 1
        || $result['outside'][0]['id'] !== $shopOutside
        || $result['outside'][0]['charge'] <= 0
        || $result['unknownDistance'] !== 1
        || $result['missingPrices'] !== 1) {
        throw new RuntimeException('Pickup charges or shop eligibility were not applied correctly.');
    }

    fwrite(STDOUT, "Selected-material, unit-aware net-payout comparison passed.\n");
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
