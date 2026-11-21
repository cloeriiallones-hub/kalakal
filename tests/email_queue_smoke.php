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
$pdo->beginTransaction();

try {
    $insertUser = $pdo->prepare(
        "INSERT INTO users
            (first_name, last_name, email, password_hash, user_role, seller_type,
             email_verified_at, email_notifications)
         VALUES ('Email', 'QueueTest', ?, 'not-a-login-hash', 'seller', 'resident', NOW(), 1)"
    );
    $insertUser->execute(['email-queue-' . bin2hex(random_bytes(6)) . '@example.invalid']);
    $userId = (int) $pdo->lastInsertId();
    $insertNotification = $pdo->prepare(
        "INSERT INTO notifications (user_id, notification_type, title, message)
         VALUES (?, 'email_queue_test', 'Queue test', 'SMTP queue test')"
    );
    $insertNotification->execute([$userId]);
    $sentId = (int) $pdo->lastInsertId();
    $insertNotification->execute([$userId]);
    $failedId = (int) $pdo->lastInsertId();

    $hideOtherPending = $pdo->prepare(
        "UPDATE notifications
            SET email_status = 'skipped', email_batch = NULL, email_claimed_at = NULL
          WHERE email_status = 'pending' AND notification_id NOT IN (?, ?)"
    );
    $hideOtherPending->execute([$sentId, $failedId]);

    $claim = $pdo->prepare('CALL sp_claim_pending_emails(100, ?)');
    $batch = 'email-test-' . bin2hex(random_bytes(10));
    $claim->execute([$batch]);
    $claim->fetch();
    $claim->closeCursor();
    $claimed = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications
          WHERE notification_id IN (?, ?) AND email_status = 'sending' AND email_batch = ?"
    );
    $claimed->execute([$sentId, $failedId, $batch]);
    if ((int) $claimed->fetchColumn() !== 2) {
        throw new RuntimeException('The test notifications were not claimed by their batch.');
    }

    $markSent = $pdo->prepare('CALL sp_mark_email_sent(?, ?)');
    try {
        $markSent->execute([$sentId, 'wrong-batch']);
        $markSent->closeCursor();
        throw new RuntimeException('An unrelated batch was able to mark an email sent.');
    } catch (PDOException $exception) {
        $markSent->closeCursor();
        if ((string) $exception->getCode() !== '45000') {
            throw $exception;
        }
    }
    $markSent->execute([$sentId, $batch]);
    $markSent->closeCursor();

    $markFailed = $pdo->prepare('CALL sp_mark_email_failed(?, ?, ?)');
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $attemptBatch = $attempt === 1 ? $batch : 'email-test-' . bin2hex(random_bytes(10));
        if ($attempt > 1) {
            $claim->execute([$attemptBatch]);
            $claim->fetch();
            $claim->closeCursor();
        }
        $markFailed->execute([$failedId, $attemptBatch, 'Simulated SMTP failure']);
        $markFailed->closeCursor();
        $check = $pdo->prepare(
            'SELECT email_status, email_attempts, email_error FROM notifications WHERE notification_id = ?'
        );
        $check->execute([$failedId]);
        $state = $check->fetch();
        $check->closeCursor();
        $expectedStatus = $attempt === 3 ? 'failed' : 'pending';
        if ($state['email_status'] !== $expectedStatus
            || (int) $state['email_attempts'] !== $attempt
            || $state['email_error'] !== 'Simulated SMTP failure') {
            throw new RuntimeException('Email retry state did not match its configured attempt count.');
        }
    }

    $pdo->rollBack();
    fwrite(STDOUT, "Email batch ownership, successful marking, and bounded failure retries passed; SMTP delivery was not attempted.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
