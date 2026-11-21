<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
$limit = 100;
foreach ($argv as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
        $limit = (int) $matches[1];
    }
}
if ($limit < 1 || $limit > 1000) {
    fwrite(STDERR, "Limit must be between 1 and 1000.\n");
    exit(2);
}

$pdo = db();
$eligible = $pdo->query(
    'SELECT COUNT(*) FROM users
      WHERE is_active = 0 AND deleted_at IS NULL
        AND deletion_requested_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
)->fetchColumn();

if ($dryRun) {
    fwrite(STDOUT, 'Eligible accounts: ' . (int) $eligible . PHP_EOL);
    exit(0);
}

$pdo->beginTransaction();
$qrPaths = [];
try {
    $select = $pdo->query(
        'SELECT user_id, email, ewallet_qr_file_id
           FROM users
          WHERE is_active = 0 AND deleted_at IS NULL
            AND deletion_requested_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          ORDER BY deletion_requested_at, user_id
          LIMIT ' . $limit . ' FOR UPDATE'
    );
    $accounts = $select->fetchAll();

    $updateUser = $pdo->prepare(
        'UPDATE users
            SET first_name = ?, middle_name = NULL, last_name = ?, suffix = NULL,
                email = ?, phone_number = NULL,
                password_hash = ?, email_verified_at = NULL,
                email_notifications = 0, login_alert_emails = 0,
                ewallet_provider = NULL, ewallet_qr_file_id = NULL,
                is_active = 0, deleted_at = NOW()
          WHERE user_id = ? AND is_active = 0 AND deleted_at IS NULL
            AND deletion_requested_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
    $updateBuyer = $pdo->prepare(
        "UPDATE buyer_profiles
            SET company_name = ?, business_permit_no = NULL, tin = NULL,
                business_address = NULL, barangay = NULL, city = NULL,
                latitude = NULL, longitude = NULL
          WHERE user_id = ?"
    );
    $updateEstablishment = $pdo->prepare(
        'UPDATE establishment_profiles
            SET business_name = ?, business_type = NULL, permit_number = NULL,
                tin = NULL, contact_person = NULL
          WHERE user_id = ?'
    );
    $updateCollector = $pdo->prepare(
        'UPDATE collector_profiles
            SET vehicle_type = NULL, plate_number = NULL, service_area = NULL,
                is_available = 0
          WHERE user_id = ?'
    );
    $deleteQr = $pdo->prepare(
        "DELETE FROM file_uploads WHERE file_id = ? AND purpose = 'ewallet_qr'"
    );
    $processed = 0;

    foreach ($accounts as $account) {
        $userId = (int) $account['user_id'];
        $originalEmail = (string) $account['email'];
        $anonymousEmail = 'deleted+' . $userId . '+' . bin2hex(random_bytes(16)) . '@deleted.invalid';
        $qrFileId = $account['ewallet_qr_file_id'] !== null
            ? (int) $account['ewallet_qr_file_id'] : 0;
        if ($qrFileId > 0) {
            $qr = $pdo->prepare(
                "SELECT stored_path FROM file_uploads
                  WHERE file_id = ? AND purpose = 'ewallet_qr'"
            );
            $qr->execute([$qrFileId]);
            $path = $qr->fetchColumn();
            if (is_string($path)) {
                $qrPaths[] = $path;
            }
        }

        $randomPassword = bin2hex(random_bytes(32));
        $updateUser->execute([
            'Deleted',
            'User',
            $anonymousEmail,
            password_hash($randomPassword, PASSWORD_DEFAULT),
            $userId,
        ]);
        if ($updateUser->rowCount() !== 1) {
            continue;
        }

        $updateBuyer->execute(['Deleted junkshop #' . $userId, $userId]);
        $updateEstablishment->execute(['Deleted establishment #' . $userId, $userId]);
        $updateCollector->execute([$userId]);
        $pdo->prepare('DELETE FROM addresses WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE file_uploads SET uploaded_by = NULL WHERE uploaded_by = ?')->execute([$userId]);
        $pdo->prepare(
            'UPDATE user_sessions
                SET revoked_at = IFNULL(revoked_at, NOW()), ip_address = NULL, user_agent = NULL
              WHERE user_id = ?'
        )->execute([$userId]);
        $pdo->prepare(
            "UPDATE login_attempts
                SET email = ?, user_id = NULL, ip_address = '0.0.0.0'
              WHERE user_id = ? OR email = ?"
        )->execute([$anonymousEmail, $userId, $originalEmail]);
        $pdo->prepare(
            'UPDATE password_resets
                SET used_at = IFNULL(used_at, NOW()), requested_ip = NULL
              WHERE user_id = ?'
        )->execute([$userId]);
        $pdo->prepare(
            'UPDATE email_verification_tokens
                SET used_at = IFNULL(used_at, NOW()), requested_ip = NULL
              WHERE user_id = ?'
        )->execute([$userId]);
        $pdo->prepare(
            'UPDATE admin_mfa_challenges
                SET consumed_at = IFNULL(consumed_at, NOW()), requested_ip = NULL
              WHERE user_id = ?'
        )->execute([$userId]);
        $pdo->prepare(
            'DELETE FROM auth_email_attempts WHERE email_hash = ?'
        )->execute([hash('sha256', strtolower(trim($originalEmail)))]);
        if ($qrFileId > 0) {
            $deleteQr->execute([$qrFileId]);
        }
        $processed++;
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Account deletion finalizer failed: ' . $exception->getMessage());
    fwrite(STDERR, "Account deletion finalization failed; the database transaction was rolled back.\n");
    exit(1);
}

foreach ($qrPaths as $relativePath) {
    $prefix = 'uploads/ewallet_qr/';
    if (strpos($relativePath, $prefix) !== 0
        || basename($relativePath) !== substr($relativePath, strlen($prefix))) {
        error_log('Skipped unsafe e-wallet QR path during account finalization: ' . $relativePath);
        continue;
    }
    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($absolutePath) && !unlink($absolutePath)) {
        error_log('Could not remove deleted account e-wallet QR: ' . $relativePath);
    }
}

fwrite(STDOUT, 'Accounts anonymized: ' . $processed . PHP_EOL);
