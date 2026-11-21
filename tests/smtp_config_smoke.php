<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

putenv('SMTP_HOST=smtp.gmail.com');
putenv('SMTP_PORT=587');
putenv('SMTP_ENCRYPTION=tls');
putenv('SMTP_USERNAME=smtp-test@example.invalid');
$testPassword = bin2hex(random_bytes(16));
putenv('SMTP_PASSWORD=' . $testPassword);
putenv('MAIL_FROM=smtp-test@example.invalid');
putenv('MAIL_FROM_NAME=KALAKAL Test');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/smtp.php';

smtp_validate_configuration();
if (MAIL_SMTP_HOST !== 'smtp.gmail.com'
    || MAIL_SMTP_PORT !== 587
    || MAIL_SMTP_ENCRYPTION !== 'tls'
    || !class_exists(PHPMailer\PHPMailer\PHPMailer::class)) {
    throw new RuntimeException('Gmail SMTP configuration or PHPMailer autoload did not initialize.');
}

$redacted = smtp_redact_error(
    'user=' . MAIL_SMTP_USERNAME . ' pass=' . MAIL_SMTP_PASSWORD
        . ' encoded=' . base64_encode(MAIL_SMTP_PASSWORD)
);
if (strpos($redacted, MAIL_SMTP_USERNAME) !== false
    || strpos($redacted, MAIL_SMTP_PASSWORD) !== false
    || strpos($redacted, base64_encode(MAIL_SMTP_PASSWORD)) !== false) {
    throw new RuntimeException('SMTP error redaction exposed a credential.');
}

fwrite(STDOUT, "SMTP environment mapping, PHPMailer autoload, and credential redaction passed; no email was sent.\n");
