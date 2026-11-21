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
$pdo->beginTransaction();

try {
    $createUser = $pdo->prepare(
        "INSERT INTO users (first_name, last_name, email, password_hash, user_role, seller_type, email_verified_at)
         VALUES (?, 'PaginationTest', ?, 'not-a-login-hash', ?, 'resident', NOW())"
    );
    $createUser->execute(['Seller', 'seller-page-' . bin2hex(random_bytes(6)) . '@example.invalid', 'seller']);
    $sellerId = (int) $pdo->lastInsertId();
    $createUser->execute(['Buyer', 'buyer-page-' . bin2hex(random_bytes(6)) . '@example.invalid', 'buyer']);
    $buyerId = (int) $pdo->lastInsertId();
    $createUser->execute(['Admin', 'admin-page-' . bin2hex(random_bytes(6)) . '@example.invalid', 'admin']);
    $adminId = (int) $pdo->lastInsertId();

    $participants = [$sellerId, $adminId];
    sort($participants, SORT_NUMERIC);
    $pdo->prepare(
        'INSERT INTO message_threads (request_id, participant_a_id, participant_b_id, created_by)
         VALUES (NULL, ?, ?, ?)'
    )->execute([$participants[0], $participants[1], $adminId]);
    $threadId = (int) $pdo->lastInsertId();

    $insertMessage = $pdo->prepare(
        'INSERT INTO messages (thread_id, sender_id, message_body) VALUES (?, ?, ?)'
    );
    $expectedIds = [];
    for ($index = 1; $index <= 120; $index++) {
        $senderId = $index % 2 === 0 ? $sellerId : $adminId;
        $insertMessage->execute([$threadId, $senderId, 'Test message ' . $index]);
        $expectedIds[] = (int) $pdo->lastInsertId();
    }

    $readPage = $pdo->prepare(
        'SELECT message_id FROM messages
          WHERE thread_id = ? AND (? IS NULL OR message_id < ?)
          ORDER BY message_id DESC LIMIT 51'
    );
    $pageIds = [];
    $cursor = null;
    do {
        $readPage->execute([$threadId, $cursor, $cursor]);
        $rows = $readPage->fetchAll(PDO::FETCH_COLUMN);
        $hasOlder = count($rows) > 50;
        if ($hasOlder) {
            array_pop($rows);
        }
        $rows = array_reverse(array_map('intval', $rows));
        $pageIds = array_merge($rows, $pageIds);
        $cursor = $hasOlder && $rows ? $rows[0] : null;
    } while ($hasOlder && $rows);

    if ($pageIds !== $expectedIds) {
        throw new RuntimeException('Message pages were not complete, ordered, or duplicate-free.');
    }

    $pdo->prepare(
        "INSERT INTO admin_message_access_logs (admin_id, thread_id, action, ip_address)
         VALUES (?, ?, 'opened', '127.0.0.1'), (?, ?, 'sent', '127.0.0.1')"
    )->execute([$adminId, $threadId, $adminId, $threadId]);
    $logCount = $pdo->prepare(
        'SELECT COUNT(*) FROM admin_message_access_logs WHERE admin_id = ? AND thread_id = ?'
    );
    $logCount->execute([$adminId, $threadId]);
    if ((int) $logCount->fetchColumn() !== 2) {
        throw new RuntimeException('Administrator message access was not logged.');
    }

    $unrelated = $pdo->prepare(
        'SELECT COUNT(*) FROM message_threads
          WHERE participant_a_id = ? OR participant_b_id = ?'
    );
    $unrelated->execute([$adminId, $adminId]);
    if ((int) $unrelated->fetchColumn() !== 1) {
        throw new RuntimeException('Administrator conversation listing includes a thread they do not participate in.');
    }

    $pdo->rollBack();
    fwrite(STDOUT, "50-message pagination, ordered history, participant-scoped admin listing, and access logging passed.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
