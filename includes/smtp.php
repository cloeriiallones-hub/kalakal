<?php
declare(strict_types=1);

function smtp_validate_configuration(): void
{
    if (!filter_var(MAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL)
        || !filter_var(MAIL_SMTP_USERNAME, FILTER_VALIDATE_EMAIL)
        || MAIL_SMTP_PASSWORD === ''
        || MAIL_SMTP_HOST !== 'smtp.gmail.com'
        || preg_match('/[\r\n]/', MAIL_SMTP_HOST)
        || MAIL_SMTP_ENCRYPTION !== 'tls'
        || MAIL_SMTP_PORT !== 587) {
        throw new RuntimeException('SMTP settings are incomplete or invalid.');
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PHPMailer is missing. Run Composer install in the project directory.');
    }

    require_once $autoload;
}

function smtp_redact_error(string $message): string
{
    $credentials = [
        MAIL_SMTP_USERNAME,
        MAIL_SMTP_PASSWORD,
        base64_encode(MAIL_SMTP_USERNAME),
        base64_encode(MAIL_SMTP_PASSWORD),
        preg_replace('/\s+/', '', MAIL_SMTP_PASSWORD) ?? '',
    ];
    foreach (array_filter($credentials, static function (string $value): bool {
        return $value !== '';
    }) as $credential) {
        $message = str_replace($credential, '[redacted]', $message);
    }

    $message = preg_replace('/[\r\n\x00-\x1f]+/', ' ', $message) ?? 'SMTP delivery failed.';
    return substr($message, 0, 500);
}

function smtp_send_message(string $to, string $subject, string $body): void
{
    smtp_validate_configuration();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Recipient address is invalid.');
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = MAIL_SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_SMTP_USERNAME;
        $mail->Password = MAIL_SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = MAIL_SMTP_PORT;
        $mail->Timeout = 15;
        $mail->SMTPDebug = 0;
        $mail->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
    } catch (PHPMailer\PHPMailer\Exception $exception) {
        $detail = smtp_redact_error($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $exception->getMessage());
        throw new RuntimeException('Could not send email using configured SMTP. ' . $detail, 0, $exception);
    }
}
