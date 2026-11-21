<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();
$uid = (int) $user['user_id'];
$role = $user['user_role'];
$fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$fileId || $fileId < 1) {
    http_response_code(404);
    exit;
}

// Allowed: the owner of the QR code, an admin, or the junkshop that accepted
// one of that owner's active requests (so it can pay by e-wallet).
$st = db()->prepare(
    "SELECT f.stored_path, f.mime_type
       FROM file_uploads f
       JOIN users owner ON owner.ewallet_qr_file_id = f.file_id
      WHERE f.file_id = ?
        AND f.purpose = 'ewallet_qr'
        AND (
            owner.user_id = ?
            OR ? = 'admin'
            OR EXISTS (
                SELECT 1 FROM collection_requests r
                 WHERE r.seller_id = owner.user_id
                   AND r.accepted_buyer_id = ?
                   AND r.request_status IN ('accepted','completed')
            )
        )
      LIMIT 1"
);
$st->execute([$fileId, $uid, $role, $uid]);
$file = $st->fetch();
if (!$file) {
    http_response_code(404);
    exit;
}

$relativePath = (string) $file['stored_path'];
$prefix = 'uploads/ewallet_qr/';
if (!in_array($file['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)
    || strpos($relativePath, $prefix) !== 0
    || basename($relativePath) !== substr($relativePath, strlen($prefix))) {
    http_response_code(404);
    exit;
}
$path = __DIR__ . '/' . $relativePath;
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
