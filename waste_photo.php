<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(['seller', 'buyer', 'admin']);

$user = current_user();
$uid = (int) $user['user_id'];
$role = $user['user_role'];
$fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$fileId || $fileId < 1) {
    http_response_code(404);
    exit;
}

$st = db()->prepare(
    "SELECT f.stored_path, f.mime_type
       FROM file_uploads f
       JOIN collection_request_items i ON i.photo_file_id = f.file_id
       JOIN collection_requests r ON r.request_id = i.request_id
      WHERE f.file_id = ?
        AND f.purpose = 'waste_photo'
        AND (r.seller_id = ? OR r.accepted_buyer_id = ? OR ? = 'admin'
             OR (? = 'buyer' AND r.request_status = 'pending' AND EXISTS (
                    SELECT 1 FROM buyer_profiles bp
                     WHERE bp.user_id = ? AND bp.city IS NOT NULL
                       AND LOWER(TRIM(bp.city)) = LOWER(TRIM(r.city)))))
      LIMIT 1"
);
$st->execute([$fileId, $uid, $uid, $role, $role, $uid]);
$file = $st->fetch();
if (!$file) {
    http_response_code(404);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
$relativePath = (string) $file['stored_path'];
$expectedPrefix = 'uploads/waste_photos/';
if (
    !in_array($file['mime_type'], $allowedTypes, true)
    || strpos($relativePath, $expectedPrefix) !== 0
    || basename($relativePath) !== substr($relativePath, strlen($expectedPrefix))
) {
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
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
