<?php
declare(strict_types=1);

/* ---------- Output / navigation ---------- */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function fmt_date(?string $value): string
{
    return $value ? date('M d, Y h:i A', strtotime($value)) : '—';
}

function peso($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* ---------- Flash messages ---------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* ---------- CSRF protection ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Invalid or expired form. Please go back, refresh the page and try again.');
    }
}

/* ---------- Authentication / authorization ---------- */

function split_person_name(string $name): array
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts || count($parts) < 2) {
        throw new InvalidArgumentException('Please enter both your first and last name.');
    }

    $suffix = null;
    if (in_array(strtolower(end($parts)), ['jr.', 'sr.', 'ii', 'iii', 'iv', 'v'], true)) {
        $suffix = array_pop($parts);
    }
    if (count($parts) < 2) {
        throw new InvalidArgumentException('Please enter both your first and last name.');
    }

    $firstName = array_shift($parts);
    $lastName = array_pop($parts);
    $middleName = $parts ? implode(' ', $parts) : null;
    if (mb_strlen($firstName) > 50 || mb_strlen((string) $middleName) > 50
        || mb_strlen($lastName) > 50 || mb_strlen((string) $suffix) > 10) {
        throw new InvalidArgumentException('Name parts must each fit the allowed length.');
    }

    return [$firstName, $middleName, $lastName, $suffix];
}

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }

    $cache = null;
    if (!empty($_SESSION['user_id'])) {
        $now = time();
        $lastActivity = $_SESSION['last_activity'] ?? null;
        $sessionToken = $_SESSION['session_token'] ?? '';
        if (!is_string($sessionToken) || !preg_match('/^[a-f0-9]{64}$/', $sessionToken)) {
            unset($_SESSION['user_id'], $_SESSION['last_activity'], $_SESSION['session_token']);
            flash('error', 'Your session is no longer valid. Please log in again.');
            return $cache;
        }
        if (is_int($lastActivity) && $now - $lastActivity >= session_lifetime_minutes() * 60) {
            revoke_current_user_session();
            unset($_SESSION['user_id'], $_SESSION['last_activity'], $_SESSION['session_token']);
            flash('error', 'Your session expired due to inactivity. Please log in again.');
            return $cache;
        }
        $_SESSION['last_activity'] = $now;

        $st = db()->prepare(
            "SELECT u.user_id, u.first_name, u.last_name, CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name, u.suffix) AS full_name,
                    bp.company_name AS junkshop_name,
                    u.email, u.phone_number, u.user_role, u.seller_type, u.profile_completed_at,
                    u.ewallet_provider, u.ewallet_qr_file_id
               FROM users u
               LEFT JOIN buyer_profiles bp ON bp.user_id = u.user_id
               JOIN user_sessions us
                 ON us.user_id = u.user_id
                AND us.token_hash = ?
                AND us.revoked_at IS NULL
                AND us.expires_at > NOW()
              WHERE u.user_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL"
        );
        $st->execute([hash('sha256', $sessionToken), $_SESSION['user_id']]);
        $row = $st->fetch();
        if ($row) {
            $touch = db()->prepare(
                'UPDATE user_sessions
                    SET last_used_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ' . session_lifetime_minutes() . ' MINUTE)
                  WHERE user_id = ? AND token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()'
            );
            $touch->execute([(int) $row['user_id'], hash('sha256', $sessionToken)]);
            $nameParts = preg_split('/\s+/', trim($row['first_name']), 2, PREG_SPLIT_NO_EMPTY);
            $row['display_name'] = $nameParts[0] ?? $row['first_name'];
            $cache = $row;
        } else {
            unset($_SESSION['user_id'], $_SESSION['last_activity'], $_SESSION['session_token']);
            flash('error', 'Your session has expired or was revoked. Please log in again.');
        }
    }
    return $cache;
}

function session_lifetime_minutes(): int
{
    static $minutes = null;
    if ($minutes === null) {
        $st = db()->prepare(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'session_lifetime_minutes'"
        );
        $st->execute();
        $value = $st->fetchColumn();
        $validatedMinutes = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 43200],
        ]);
        if ($validatedMinutes === false) {
            error_log('Invalid or missing session_lifetime_minutes setting; using 120 minutes.');
            $minutes = 120;
        } else {
            $minutes = $validatedMinutes;
        }
    }
    return $minutes;
}

/**
 * Sellers and junkshops must complete their
 * profile once after creating an account. Until then the home page shows the
 * "Complete your account details" card and highlights the profile icon.
 */
function needs_onboarding(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user !== null
        && in_array($user['user_role'], ['seller', 'buyer'], true)
        && empty($user['profile_completed_at']);
}

/* ---------- Distance and pickup charge (junkshops outside the owner's city) ---------- */

/** Straight-line distance in kilometres between two latitude/longitude points. */
function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371.0088;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
}

/** Pickup charge settings (admin can change them in system_settings). */
function pickup_charge_settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = ['base' => 30.0, 'per_km' => 10.0];
        $rows = db()->query(
            "SELECT setting_key, setting_value FROM system_settings
              WHERE setting_key IN ('pickup_charge_base', 'pickup_charge_per_km')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if (isset($rows['pickup_charge_base']) && is_numeric($rows['pickup_charge_base'])) {
            $cache['base'] = (float) $rows['pickup_charge_base'];
        }
        if (isset($rows['pickup_charge_per_km']) && is_numeric($rows['pickup_charge_per_km'])) {
            $cache['per_km'] = (float) $rows['pickup_charge_per_km'];
        }
    }
    return $cache;
}

function pickup_charge_for(float $km): float
{
    $s = pickup_charge_settings();
    return round($s['base'] + $s['per_km'] * $km, 2);
}

function same_city(?string $a, ?string $b): bool
{
    $a = mb_strtolower(trim((string) $a));
    return $a !== '' && $a === mb_strtolower(trim((string) $b));
}

/** Compare shops for the actual materials and pickup location on one request. */
function compare_junkshops(int $ownerId, int $requestId): array
{
    $pdo = db();
    $requestQuery = $pdo->prepare(
        'SELECT request_id, seller_id, barangay, city, latitude, longitude
           FROM collection_requests WHERE request_id = ? AND seller_id = ?'
    );
    $requestQuery->execute([$requestId, $ownerId]);
    $owner = $requestQuery->fetch();
    if (!$owner) {
        throw new InvalidArgumentException('The pickup request was not found for this seller.');
    }

    $itemQuery = $pdo->prepare(
        'SELECT ri.category_id, mc.category_name, ri.estimate_method,
                ri.estimated_quantity, ri.estimated_weight_kg, ri.estimated_size
           FROM collection_request_items ri
           JOIN material_categories mc ON mc.category_id = ri.category_id
          WHERE ri.request_id = ? AND mc.is_active = 1
          ORDER BY mc.category_name, ri.estimated_size'
    );
    $itemQuery->execute([$requestId]);
    $materials = [];
    foreach ($itemQuery->fetchAll() as $item) {
        $quantities = [];
        if ($item['estimate_method'] === 'detailed') {
            $quantities = [
                'kg' => (float) $item['estimated_weight_kg'],
                'piece' => (float) $item['estimated_quantity'],
                (string) $item['estimated_size'] => (float) $item['estimated_quantity'],
            ];
        } elseif ($item['estimate_method'] === 'weight') {
            $quantities['kg'] = (float) $item['estimated_quantity'];
        } elseif ($item['estimate_method'] === 'pieces') {
            $quantities['piece'] = (float) $item['estimated_quantity'];
        } elseif (in_array($item['estimated_size'], ['small', 'medium', 'large'], true)) {
            $quantities[(string) $item['estimated_size']] = (float) $item['estimated_quantity'];
        }
        $materials[] = [
            'category_id' => (int) $item['category_id'],
            'name' => (string) $item['category_name'],
            'quantities' => $quantities,
        ];
    }

    $rows = $pdo->query(
        "SELECT jp.buyer_id, bp.company_name, bp.barangay, bp.city, bp.latitude, bp.longitude,
                jp.category_id, jp.price_unit, jp.buying_price
           FROM junkshop_material_prices jp
           JOIN users u ON u.user_id = jp.buyer_id AND u.is_active = 1 AND u.deleted_at IS NULL
           JOIN buyer_profiles bp ON bp.user_id = jp.buyer_id
           JOIN material_categories mc ON mc.category_id = jp.category_id AND mc.is_active = 1
          WHERE jp.effective_until IS NULL AND jp.buying_price > 0
            AND bp.city IS NOT NULL AND TRIM(bp.city) <> ''"
    )->fetchAll();

    $shops = [];
    foreach ($rows as $row) {
        $id = (int) $row['buyer_id'];
        if (!isset($shops[$id])) {
            $shops[$id] = [
                'info' => $row,
                'prices' => [],
            ];
        }
        $shops[$id]['prices'][(int) $row['category_id']][(string) $row['price_unit']]
            = (float) $row['buying_price'];
    }

    $inCity = [];
    $outside = [];
    $missingPrices = 0;
    $unknownDistance = 0;
    $ownerPinned = $owner['latitude'] !== null && $owner['longitude'] !== null;
    foreach ($shops as $id => $shop) {
        $gross = 0.0;
        $lines = [];
        $complete = true;
        foreach ($materials as $material) {
            $categoryId = $material['category_id'];
            $best = null;
            foreach ($material['quantities'] as $unit => $quantity) {
                if (!isset($shop['prices'][$categoryId][$unit])) {
                    continue;
                }
                $unitPrice = $shop['prices'][$categoryId][$unit];
                $value = round($quantity * $unitPrice, 2);
                if ($best === null || $value > $best['value']) {
                    $best = [
                        'unit' => $unit,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'value' => $value,
                    ];
                }
            }
            if ($best === null) {
                $complete = false;
                break;
            }
            $gross += $best['value'];
            $lines[] = ['material' => $material['name']] + $best;
        }
        if (!$complete || !$materials) {
            if (!$complete && $materials) {
                $missingPrices++;
            }
            continue;
        }

        $info = $shop['info'];
        $sameCity = same_city((string) $owner['city'], (string) $info['city']);
        $distance = null;
        $charge = 0.0;
        if (!$sameCity) {
            if (!$ownerPinned || $info['latitude'] === null || $info['longitude'] === null) {
                $unknownDistance++;
                continue;
            }
            $distance = haversine_km(
                (float) $owner['latitude'],
                (float) $owner['longitude'],
                (float) $info['latitude'],
                (float) $info['longitude']
            );
            $charge = pickup_charge_for($distance);
        }
        $entry = [
            'id' => (int) $id,
            'name' => (string) $info['company_name'],
            'barangay' => (string) ($info['barangay'] ?? ''),
            'city' => (string) $info['city'],
            'lines' => $lines,
            'gross' => round($gross, 2),
            'charge' => round($charge, 2),
            'net' => round($gross - $charge, 2),
            'km' => $distance,
        ];
        if ($sameCity) {
            $entry['same_barangay'] = same_city((string) ($info['barangay'] ?? ''), (string) $owner['barangay']);
            $inCity[] = $entry;
        } else {
            $outside[] = $entry;
        }
    }

    $rankByNet = static function (array $a, array $b): int {
        return ($b['net'] <=> $a['net']) ?: strcasecmp($a['name'], $b['name']);
    };
    usort($inCity, $rankByNet);
    usort($outside, $rankByNet);

    return [
        'owner' => $owner,
        'ownerPinned' => $ownerPinned,
        'requestId' => $requestId,
        'materials' => $materials,
        'inCity' => $inCity,
        'outside' => $outside,
        'missingPrices' => $missingPrices,
        'unknownDistance' => $unknownDistance,
        'settings' => pickup_charge_settings(),
    ];
}

/** Absolute-safe delete of a stored upload (relative path under the app root). */
function delete_stored_upload(string $relativePath): void
{
    $full = __DIR__ . '/../' . ltrim($relativePath, '/');
    if (is_file($full) && !@unlink($full)) {
        error_log('Could not remove stored upload: ' . $relativePath);
    }
}

/**
 * Validates one uploaded image ($_FILES entry). Returns mime/extension info or
 * throws InvalidArgumentException with a message that is safe to show.
 */
function validate_image_upload(array $file, int $maxBytes = 2097152): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('The image must be ' . round($maxBytes / 1048576) . ' MB or smaller.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The image upload failed. Please choose the file again.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('The image upload could not be verified. Please choose the file again.');
    }
    if ($size < 1 || $size > $maxBytes) {
        throw new InvalidArgumentException('The image must be ' . round($maxBytes / 1048576) . ' MB or smaller.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info = @getimagesize($tmp);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($types[$mime]) || !$info || ($info['mime'] ?? '') !== $mime
        || (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] * (int) $info[1] > 25000000) {
        throw new InvalidArgumentException('The image must be a valid JPEG, PNG, or WebP file.');
    }
    return ['tmp_name' => $tmp, 'size' => $size, 'mime' => $mime, 'extension' => $types[$mime],
            'original_name' => substr(preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) ($file['name'] ?? 'image'))) ?: 'image', 0, 255)];
}

function require_login(): void
{
    if (!current_user()) {
        flash('error', 'Please log in first.');
        redirect('login.php');
    }
}

function require_role(array $roles): void
{
    require_login();
    if (!in_array(current_user()['user_role'], $roles, true)) {
        http_response_code(403);
        exit('403 - You are not allowed to open this page.');
    }
}

function log_login_attempt(string $email, ?int $userId, bool $success): void
{
    $st = db()->prepare(
        'INSERT INTO login_attempts (email, user_id, ip_address, was_successful)
         VALUES (?, ?, ?, ?)'
    );
    $st->execute([$email, $userId, client_ip(), $success ? 1 : 0]);
}

/* ---------- Notifications ---------- */

function notify(int $userId, string $type, string $title, string $message,
                ?string $table = null, ?int $recordId = null): void
{
    $st = db()->prepare(
        'INSERT INTO notifications
            (user_id, notification_type, title, message, related_table, related_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$userId, $type, $title, $message, $table, $recordId]);
}
