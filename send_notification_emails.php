<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/smtp.php';

try {
    smtp_validate_configuration();
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . " Configure SMTP_USERNAME and "
        . "SMTP_PASSWORD; MAIL_FROM must be the authenticated Gmail address or an allowed alias.\n");
    exit(1);
}

$batch = sprintf(
    '%s-%s-%s-%s-%s',
    bin2hex(random_bytes(4)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(6))
);

$pdo = db();
$claim = $pdo->prepare('CALL sp_claim_pending_emails(20, ?)');
$claim->execute([$batch]);
$claim->fetch();
$claim->closeCursor();

$outbox = $pdo->prepare(
    'SELECT notification_id, title, message, to_email, to_first_name
       FROM v_email_outbox
      WHERE email_batch = ?
      ORDER BY notification_id'
);
$outbox->execute([$batch]);
$sent = 0;
$failed = 0;

foreach ($outbox->fetchAll() as $notification) {
    $notificationId = (int) $notification['notification_id'];
    $to = (string) $notification['to_email'];
    $title = trim(preg_replace('/[\r\n]+/', ' ', (string) $notification['title']) ?? '');
    $firstName = trim(preg_replace('/[\r\n]+/', ' ', (string) $notification['to_first_name']) ?? '');
    $message = (string) $notification['message'];
    $body = "Hello $firstName,\r\n\r\n$message\r\n\r\n"
        . 'Log in to your KALAKAL account to view the related update.';
    try {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Recipient address is invalid.');
        }
        smtp_send_message($to, $title, $body);
    } catch (Throwable $exception) {
        $markFailed = $pdo->prepare('CALL sp_mark_email_failed(?, ?, ?)');
        $markFailed->execute([$notificationId, $batch, $exception->getMessage()]);
        $markFailed->closeCursor();
        error_log("Could not send notification email #$notificationId: " . $exception->getMessage());
        $failed++;
        continue;
    }

    $markSent = $pdo->prepare('CALL sp_mark_email_sent(?, ?)');
    $markSent->execute([$notificationId, $batch]);
    $markSent->closeCursor();
    $sent++;
}

fwrite(STDOUT, "Notification emails sent: $sent; failed: $failed.\n");
exit($failed > 0 ? 1 : 0);
