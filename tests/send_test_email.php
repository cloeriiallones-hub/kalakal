<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/smtp.php';

$recipient = getenv('KALAKAL_SMTP_TEST_TO') ?: '';
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Set KALAKAL_SMTP_TEST_TO to a mailbox you control.\n");
    exit(2);
}

try {
    smtp_send_message(
        $recipient,
        'KALAKAL SMTP test',
        "This test message confirms that the configured KALAKAL SMTP transport was accepted by the mail server.\r\n"
    );
    fwrite(STDOUT, "SMTP accepted the test message. Check the recipient mailbox and spam folder.\n");
} catch (Throwable $exception) {
    error_log('SMTP test email failed: ' . $exception->getMessage());
    fwrite(STDERR, 'SMTP test failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
