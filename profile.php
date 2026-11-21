<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();
$userId = (int) $user['user_id'];
$role = $user['user_role'];
$isBuyer = $role === 'buyer';
$isOwner = $role === 'seller';
$emailChanged = false;
$hasOnboarding = $isBuyer || $isOwner;
$wasIncomplete = needs_onboarding($user);
$pdo = db();
$errors = [];
$ewalletProviders = ['GCash', 'Maya', 'Other'];

/* ---------- Current saved values ---------- */
$profile = [
    'first_name' => (string) $user['first_name'],
    'last_name' => (string) $user['last_name'],
    'junkshop_name' => (string) ($user['junkshop_name'] ?? ''),
    'email' => (string) $user['email'],
    'phone_number' => preg_replace('/^(?:\+63|0)/', '', (string) ($user['phone_number'] ?? '')),
    'address_line' => '',
    'barangay' => '',
    'city' => '',
    'postal_code' => '',
    'landmark' => '',
    'latitude' => '',
    'longitude' => '',
    'ewallet_provider' => (string) ($user['ewallet_provider'] ?? ''),
    'buyer_payment_method' => 'cash',
];
if ($isOwner) {
    $st = $pdo->prepare(
        'SELECT address_line, barangay, city, postal_code, landmark, latitude, longitude
           FROM addresses WHERE user_id = ? ORDER BY is_default DESC, address_id LIMIT 1'
    );
    $st->execute([$userId]);
    $profile = array_merge($profile, array_map(static fn($v) => (string) $v, $st->fetch() ?: []));
} elseif ($isBuyer) {
    $st = $pdo->prepare('SELECT business_address, barangay, city, latitude, longitude, payment_method FROM buyer_profiles WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch() ?: [];
    $profile['address_line'] = (string) ($row['business_address'] ?? '');
    $profile['barangay'] = (string) ($row['barangay'] ?? '');
    $profile['city'] = (string) ($row['city'] ?? '');
    $profile['latitude'] = (string) ($row['latitude'] ?? '');
    $profile['longitude'] = (string) ($row['longitude'] ?? '');
    $profile['buyer_payment_method'] = (string) ($row['payment_method'] ?? 'cash');
}

/* ---------- Account deletion (24-hour recovery window) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    csrf_check();
    $emailChanged = strcasecmp($profile['email'], (string) $user['email']) !== 0;
    try {
        $pdo->beginTransaction();
        $account = $pdo->prepare(
            'SELECT password_hash
               FROM users
              WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL
                AND deletion_requested_at IS NULL
              FOR UPDATE'
        );
        $account->execute([$userId]);
        $account = $account->fetch();
        $deletePassword = $_POST['delete_password'] ?? '';
        if (!$account || !is_string($deletePassword) || !password_verify($deletePassword, $account['password_hash'])) {
            throw new RuntimeException('Enter your current password to confirm account deletion.');
        }

        // Tells the notification triggers who is acting (so nobody is notified about their own action)
        $pdo->exec('SET @app_user_id = ' . $userId);

        // Every unfinished collection request is cancelled right away, even though the
        // account itself is only anonymized after the 24-hour recovery window.
        $requestQuery = $pdo->prepare(
            "SELECT request_id, seller_id, accepted_buyer_id
               FROM collection_requests
              WHERE (seller_id = ? OR accepted_buyer_id = ?)
                AND request_status IN ('pending','buyer_proposed','accepted','awaiting_approval','disputed')
              FOR UPDATE"
        );
        $requestQuery->execute([$userId, $userId]);
        $requests = $requestQuery->fetchAll();
        foreach ($requests as $request) {
            $requestId = (int) $request['request_id'];
            // The database trigger notifies the affected buyer or eligible junkshops.
            $pdo->prepare(
                "UPDATE collection_requests SET request_status = 'cancelled'
                  WHERE request_id = ?"
            )->execute([$requestId]);
            $pdo->prepare(
                "UPDATE collections SET collection_status = 'cancelled'
                  WHERE request_id = ?
                    AND collection_status IN ('pending','in_progress','awaiting_seller_approval','disputed')"
            )->execute([$requestId]);

            $buyerId = (int) ($request['accepted_buyer_id'] ?? 0);
            if ($buyerId === $userId) {
                notify(
                    (int) $request['seller_id'],
                    'request_cancelled',
                    'Collection request cancelled',
                    'The junkshop that accepted your request closed its account, so this collection request was cancelled.',
                    'collection_requests',
                    $requestId
                );
            } elseif ($buyerId > 0) {
                // seller is deleting: the trigger already notified the accepting junkshop
                continue;
            }
        }

        $tradeQuery = $pdo->prepare(
            "SELECT trade_id, seller_id, buyer_id
               FROM trades
              WHERE trade_status IN ('pending','accepted')
                AND (seller_id = ? OR buyer_id = ?)
                AND NOT EXISTS (
                    SELECT 1 FROM transaction_payments tp
                     WHERE tp.trade_id = trades.trade_id AND tp.payment_status = 'paid'
                )
              FOR UPDATE"
        );
        $tradeQuery->execute([$userId, $userId]);
        foreach ($tradeQuery->fetchAll() as $trade) {
            $tradeId = (int) $trade['trade_id'];
            $pdo->prepare(
                "UPDATE trades SET trade_status = 'cancelled'
                  WHERE trade_id = ? AND trade_status IN ('pending','accepted')"
            )->execute([$tradeId]);
            $pdo->prepare(
                "UPDATE transaction_payments SET payment_status = 'failed'
                  WHERE trade_id = ? AND payment_status = 'pending'"
            )->execute([$tradeId]);
            $counterpartyId = (int) ((int) $trade['seller_id'] === $userId ? $trade['buyer_id'] : $trade['seller_id']);
            notify(
                $counterpartyId,
                'trade_cancelled',
                'Trade cancelled',
                'An unfinished trade was cancelled because the other account owner requested account deletion.',
                'trades',
                $tradeId
            );
        }

        $deactivate = $pdo->prepare(
            'UPDATE users
                SET is_active = 0, deletion_requested_at = NOW(),
                    failed_login_attempts = 0, locked_until = NULL
              WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL'
        );
        $deactivate->execute([$userId]);
        if ($deactivate->rowCount() !== 1) {
            throw new RuntimeException('The account is no longer active.');
        }
        revoke_user_sessions($userId);
        $pdo->commit();
        unset($_SESSION['user_id']);
        unset($_SESSION['last_activity']);
        session_regenerate_id(true);
        flash('success', 'Your account has been deactivated and open collection requests cancelled. Log in within 24 hours to restore access. After that, personal details are anonymized when the system deletion task runs; transaction records are retained.');
        redirect('login.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($ex->getMessage());
        $errors[] = 'Could not schedule account deletion. Please try again.';
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $ex->getMessage();
    }
}

/* ---------- Save / edit profile details ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete_account') {
    csrf_check();
    foreach (array_keys($profile) as $field) {
        if (isset($_POST[$field])) {
            $profile[$field] = trim((string) $_POST[$field]);
        }
    }
    $profile['email'] = strtolower($profile['email']);
    $removeQr = $isOwner && isset($_POST['remove_ewallet_qr']) && $_POST['remove_ewallet_qr'] === '1';

    if ($isBuyer) {
        if ($profile['junkshop_name'] === '' || mb_strlen($profile['junkshop_name']) > 150) {
            $errors[] = 'Junkshop name is required and must be 150 characters or fewer.';
        }
        if (!in_array($profile['buyer_payment_method'], ['cash', 'gcash', 'maya'], true)) {
            $errors[] = 'Choose a valid payment method for your junkshop.';
        }
    } else {
        foreach (['first_name' => 'First', 'last_name' => 'Last'] as $field => $label) {
            if ($profile[$field] === '' || mb_strlen($profile[$field]) > 50) {
                $errors[] = $label . ' name is required and must be 50 characters or fewer.';
            }
        }
    }
    if (!filter_var($profile['email'], FILTER_VALIDATE_EMAIL) || strlen($profile['email']) > 150) {
        $errors[] = 'Enter a valid email address (up to 150 characters).';
    }
    if (!preg_match('/^9[0-9]{9}$/', $profile['phone_number'])) {
        $errors[] = 'Contact number must contain exactly 10 digits after +63 and start with 9.';
    }
    if ($hasOnboarding) {
        $addressLabel = $isBuyer ? 'Junkshop street address' : 'Street or house number';
        if ($profile['address_line'] === '' || mb_strlen($profile['address_line']) > 255) {
            $errors[] = $addressLabel . ' is required (up to 255 characters).';
        }
        foreach (['barangay' => 'Barangay', 'city' => 'City or municipality'] as $field => $label) {
            if ($profile[$field] === '' || mb_strlen($profile[$field]) > 100) {
                $errors[] = $label . ' is required and must be 100 characters or fewer.';
            }
        }
    }
    if ($hasOnboarding) {
        $hasLat = $profile['latitude'] !== '';
        $hasLon = $profile['longitude'] !== '';
        if ($hasLat !== $hasLon
            || ($hasLat && (!is_numeric($profile['latitude']) || !is_numeric($profile['longitude'])
                || abs((float) $profile['latitude']) > 90 || abs((float) $profile['longitude']) > 180))) {
            $errors[] = 'The map pin is invalid. Tap "Use my current location" again or clear it.';
        }
    }
    if ($isOwner) {
        if (!preg_match('/^[0-9]{4}$/', $profile['postal_code'])) {
            $errors[] = 'ZIP code must contain exactly 4 digits.';
        }
        if ($profile['landmark'] === '' || mb_strlen($profile['landmark']) > 150) {
            $errors[] = 'A landmark is required so the junkshop can find you (up to 150 characters).';
        }
        if ($profile['ewallet_provider'] !== '' && !in_array($profile['ewallet_provider'], $ewalletProviders, true)) {
            $errors[] = 'Choose a valid e-wallet.';
        }
    }

    // Optional e-wallet QR screenshot (waste owners only)
    $qr = null;
    if ($isOwner && !$removeQr && isset($_FILES['ewallet_qr'])
        && (int) ($_FILES['ewallet_qr']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $qr = validate_image_upload($_FILES['ewallet_qr'], 2 * 1024 * 1024);
        } catch (InvalidArgumentException $ex) {
            $errors[] = 'E-wallet QR: ' . $ex->getMessage();
        }
    }
    if ($isOwner && !$removeQr && $profile['ewallet_provider'] === ''
        && ($qr || !empty($user['ewallet_qr_file_id']))) {
        $errors[] = 'Choose which e-wallet the QR code belongs to.';
    }

    if (!$errors) {
        $newQrPath = null;
        $oldQrPath = null;
        try {
            if ($qr) {
                $dir = __DIR__ . '/uploads/ewallet_qr';
                if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new RuntimeException('Could not create the e-wallet upload directory.');
                }
                $filename = bin2hex(random_bytes(16)) . '.' . $qr['extension'];
                if (!move_uploaded_file($qr['tmp_name'], $dir . '/' . $filename)) {
                    throw new RuntimeException('Could not store the e-wallet QR image.');
                }
                $newQrPath = 'uploads/ewallet_qr/' . $filename;
            }

            $pdo->beginTransaction();

            if ($isBuyer) {
                $pdo->prepare(
                    'UPDATE buyer_profiles
                        SET company_name = ?, business_address = ?, barangay = ?, city = ?,
                            latitude = ?, longitude = ?, payment_method = ?
                      WHERE user_id = ?'
                )->execute([$profile['junkshop_name'], $profile['address_line'],
                            $profile['barangay'], $profile['city'],
                            $profile['latitude'] !== '' ? $profile['latitude'] : null,
                            $profile['longitude'] !== '' ? $profile['longitude'] : null,
                            $profile['buyer_payment_method'], $userId]);
                $check = $pdo->prepare('SELECT 1 FROM buyer_profiles WHERE user_id = ?');
                $check->execute([$userId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Junkshop profile is missing.');
                }
            }

            if ($isOwner) {
                $addr = $pdo->prepare('SELECT address_id FROM addresses WHERE user_id = ? ORDER BY is_default DESC, address_id LIMIT 1');
                $addr->execute([$userId]);
                $addressId = $addr->fetchColumn();
                if ($addressId) {
                    $pdo->prepare(
                        'UPDATE addresses SET address_line = ?, barangay = ?, city = ?, postal_code = ?, landmark = ?,
                                latitude = ?, longitude = ?
                          WHERE address_id = ? AND user_id = ?'
                    )->execute([$profile['address_line'], $profile['barangay'], $profile['city'],
                                $profile['postal_code'], $profile['landmark'],
                                $profile['latitude'] !== '' ? $profile['latitude'] : null,
                                $profile['longitude'] !== '' ? $profile['longitude'] : null, $addressId, $userId]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO addresses (user_id, address_line, barangay, city, postal_code, landmark, latitude, longitude, is_default)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
                    )->execute([$userId, $profile['address_line'], $profile['barangay'], $profile['city'],
                                $profile['postal_code'], $profile['landmark'],
                                $profile['latitude'] !== '' ? $profile['latitude'] : null,
                                $profile['longitude'] !== '' ? $profile['longitude'] : null]);
                }

                $current = $pdo->prepare(
                    'SELECT u.ewallet_qr_file_id, f.stored_path
                       FROM users u LEFT JOIN file_uploads f ON f.file_id = u.ewallet_qr_file_id
                      WHERE u.user_id = ? FOR UPDATE'
                );
                $current->execute([$userId]);
                $currentQr = $current->fetch() ?: [];
                $oldFileId = (int) ($currentQr['ewallet_qr_file_id'] ?? 0);
                $oldQrPath = $currentQr['stored_path'] ?? null;

                $newFileId = $oldFileId ?: null;
                if ($qr) {
                    $pdo->prepare(
                        "INSERT INTO file_uploads (uploaded_by, purpose, original_name, stored_path, mime_type, file_size_bytes)
                         VALUES (?, 'ewallet_qr', ?, ?, ?, ?)"
                    )->execute([$userId, $qr['original_name'], $newQrPath, $qr['mime'], $qr['size']]);
                    $newFileId = (int) $pdo->lastInsertId();
                } elseif ($removeQr) {
                    $newFileId = null;
                }
                $provider = $profile['ewallet_provider'] !== '' ? $profile['ewallet_provider'] : null;
                if ($removeQr && !$qr) {
                    $provider = null;
                }
                $pdo->prepare('UPDATE users SET ewallet_provider = ?, ewallet_qr_file_id = ? WHERE user_id = ?')
                    ->execute([$provider, $newFileId, $userId]);
                if ($oldFileId && $oldFileId !== $newFileId) {
                    $pdo->prepare("DELETE FROM file_uploads WHERE file_id = ? AND purpose = 'ewallet_qr'")->execute([$oldFileId]);
                } else {
                    $oldQrPath = null;
                }
            }

            $userUpdateSql = 'UPDATE users SET email = ?, phone_number = ?';
            $values = [$profile['email'], '+63' . $profile['phone_number']];
            if ($emailChanged) {
                $userUpdateSql .= ', email_verified_at = NULL';
            }
            if (!$isBuyer) {
                $userUpdateSql .= ', first_name = ?, last_name = ?';
                $values[] = $profile['first_name'];
                $values[] = $profile['last_name'];
            }
            if ($hasOnboarding) {
                $userUpdateSql .= ', profile_completed_at = IFNULL(profile_completed_at, NOW())';
            }
            $userUpdateSql .= ' WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL';
            $values[] = $userId;
            $pdo->prepare($userUpdateSql)->execute($values);

            $check = $pdo->prepare('SELECT 1 FROM users WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL');
            $check->execute([$userId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('The active user account could not be updated.');
            }
            if ($emailChanged) {
                revoke_user_sessions($userId);
            }
            $pdo->commit();

            if ($oldQrPath) {
                delete_stored_upload((string) $oldQrPath);
            }
            if ($emailChanged) {
                unset($_SESSION['user_id'], $_SESSION['last_activity'], $_SESSION['session_token']);
                session_regenerate_id(true);
                flash('success', 'Your email address was changed. Sign in again using the new address.');
                redirect('login.php');
            }
            if ($wasIncomplete) {
                flash('success', 'Your account details are complete. You are ready to use KALAKAL!');
                redirect('dashboard.php');
            }
            flash('success', 'Your account details have been updated.');
            redirect('profile.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newQrPath) {
                delete_stored_upload($newQrPath);
            }
            if ($ex->getCode() === '23000' && (int) ($ex->errorInfo[1] ?? 0) === 1062) {
                $errors[] = 'That email address is already associated with another account.';
            } else {
                error_log($ex->getMessage());
                $errors[] = 'Could not update your account details. Please try again.';
            }
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newQrPath) {
                delete_stored_upload($newQrPath);
            }
            error_log($ex->getMessage());
            $errors[] = 'Could not update your account details. Please try again.';
        }
    }
}

$deleting = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account';
$hasQr = !empty($user['ewallet_qr_file_id']);
$pageTitle = 'My Profile';
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <div><span class="eyebrow">Account settings</span><h1>My profile</h1></div>
</div>
<?php if ($wasIncomplete): ?>
    <div class="alert alert-info" role="status">
        <strong>Complete your account details.</strong>
        Fill in the required fields below and save. You can come back and edit them any time.
    </div>
<?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_profile">

    <section class="card profile-card">
        <h2>Account details</h2>
        <p class="muted">
            <?= $isBuyer
                ? 'Your junkshop name, email address, and contact number. Your email is also used to log in.'
                : 'Your name, email address, and contact number. Your email is also used to log in.' ?>
        </p>
        <?php if ($isBuyer): ?>
            <label>Junkshop's Name
                <input type="text" name="junkshop_name" value="<?= e($profile['junkshop_name']) ?>" required maxlength="150" autocomplete="organization">
            </label>
            <label>Payment method you offer
                <select name="buyer_payment_method" required>
                    <?php foreach (['cash' => 'Physical cash', 'gcash' => 'GCash', 'maya' => 'Maya'] as $method => $label): ?>
                        <option value="<?= e($method) ?>" <?= $profile['buyer_payment_method'] === $method ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="muted">Sellers will see this when reviewing your collection proposal.</small>
            </label>
        <?php else: ?>
            <div class="grid-2 profile-fields">
                <label>First name
                    <input type="text" name="first_name" value="<?= e($profile['first_name']) ?>" required maxlength="50" autocomplete="given-name">
                </label>
                <label>Last name
                    <input type="text" name="last_name" value="<?= e($profile['last_name']) ?>" required maxlength="50" autocomplete="family-name">
                </label>
            </div>
        <?php endif; ?>
        <label>Email address
            <input type="email" name="email" value="<?= e($profile['email']) ?>" required maxlength="150" autocomplete="email">
        </label>
        <label>Contact number
            <span class="phone-field">
                <span class="phone-prefix" aria-hidden="true">+63</span>
                <input type="tel" name="phone_number" value="<?= e($profile['phone_number']) ?>" required inputmode="numeric" pattern="9[0-9]{9}" minlength="10" maxlength="10" autocomplete="tel-national" title="Enter 10 digits starting with 9" placeholder="9xxxxxxxxx">
            </span>
        </label>
    </section>

    <?php if ($hasOnboarding): ?>
    <section class="card profile-card">
        <h2><?= $isBuyer ? 'Junkshop location' : 'Pickup address' ?></h2>
        <p class="muted"><?= $isBuyer
            ? 'Requests from waste owners in your city are shown to you, so keep this accurate.'
            : 'Junkshops use this address to collect your waste materials. It is pre-filled on every pickup request.' ?></p>
        <label><?= $isBuyer ? 'Street / building address' : 'Street / House Number' ?>
            <input type="text" name="address_line" value="<?= e($profile['address_line']) ?>" required maxlength="255" autocomplete="street-address">
        </label>
        <div class="grid-2">
            <label>Barangay
                <input type="text" name="barangay" value="<?= e($profile['barangay']) ?>" required maxlength="100">
            </label>
            <label>City / Municipality
                <input type="text" name="city" value="<?= e($profile['city']) ?>" required maxlength="100">
            </label>
        </div>
        <?php if ($isOwner): ?>
            <div class="grid-2">
                <label>ZIP code
                    <input type="text" name="postal_code" value="<?= e($profile['postal_code']) ?>" required inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" title="Enter a 4-digit ZIP code">
                </label>
                <label>Landmark
                    <input type="text" name="landmark" value="<?= e($profile['landmark']) ?>" required maxlength="150" placeholder="e.g. beside the blue sari-sari store">
                </label>
            </div>
        <?php endif; ?>
        <div class="map-pin-box">
            <input type="hidden" name="latitude" value="<?= e($profile['latitude']) ?>" data-pin-lat>
            <input type="hidden" name="longitude" value="<?= e($profile['longitude']) ?>" data-pin-lon>
            <strong>Map pin <span class="muted">(optional, but needed to compare junkshops by distance)</span></strong>
            <p class="muted" data-pin-status><?= $profile['latitude'] !== '' ? 'Pinned at ' . e(number_format((float) $profile['latitude'], 5)) . ', ' . e(number_format((float) $profile['longitude'], 5)) . '.' : 'Not pinned yet.' ?></p>
            <button class="btn btn-small btn-outline" type="button" data-pin-use>Use my current location</button>
            <button class="btn btn-small btn-outline" type="button" data-pin-clear>Clear pin</button>
            <small class="muted"><?= $isBuyer ? 'Stand at your junkshop when you tap this, so sellers outside your city get an accurate pickup charge.' : 'Stand at your pickup address when you tap this. Your browser will ask for permission.' ?></small>
        </div>
        <script>
        (function () {
            const lat = document.querySelector('[data-pin-lat]'), lon = document.querySelector('[data-pin-lon]');
            const status = document.querySelector('[data-pin-status]');
            document.querySelector('[data-pin-use]').addEventListener('click', () => {
                if (!navigator.geolocation) { status.textContent = 'Your browser cannot share its location.'; return; }
                status.textContent = 'Finding your location…';
                navigator.geolocation.getCurrentPosition((pos) => {
                    lat.value = pos.coords.latitude.toFixed(7);
                    lon.value = pos.coords.longitude.toFixed(7);
                    status.textContent = 'Pinned at ' + pos.coords.latitude.toFixed(5) + ', ' + pos.coords.longitude.toFixed(5) + '. Save the profile to keep it.';
                }, () => { status.textContent = 'Could not get your location. Allow location access and try again.'; },
                { enableHighAccuracy: true, timeout: 15000 });
            });
            document.querySelector('[data-pin-clear]').addEventListener('click', () => {
                lat.value = ''; lon.value = ''; status.textContent = 'Pin cleared. Save the profile to keep this change.';
            });
        })();
        </script>
    </section>
    <?php endif; ?>

    <?php if ($isOwner): ?>
    <section class="card profile-card">
        <h2>E-wallet payment <span class="muted">(optional)</span></h2>
        <p class="muted">Attach a screenshot of your e-wallet QR code in case the junkshop wants to pay you through e-wallet. Only you and the junkshop that accepted your request can see it. JPEG, PNG, or WebP, up to 2 MB.</p>
        <label>E-wallet
            <select name="ewallet_provider">
                <option value="">Select an e-wallet</option>
                <?php foreach ($ewalletProviders as $provider): ?>
                    <option value="<?= e($provider) ?>" <?= $profile['ewallet_provider'] === $provider ? 'selected' : '' ?>><?= e($provider) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($hasQr): ?>
            <div class="ewallet-current">
                <img class="ewallet-qr-preview" src="<?= e(url('ewallet_qr.php?id=' . (int) $user['ewallet_qr_file_id'])) ?>" alt="Your saved e-wallet QR code">
                <div>
                    <p class="muted">Your current QR code. Choose a new image below to replace it.</p>
                    <label class="checkbox-label"><input type="checkbox" name="remove_ewallet_qr" value="1"> Remove my saved QR code</label>
                </div>
            </div>
        <?php endif; ?>
        <label><?= $hasQr ? 'Replace QR screenshot' : 'QR code screenshot' ?>
            <input type="file" name="ewallet_qr" accept="image/jpeg,image/png,image/webp">
        </label>
    </section>
    <?php endif; ?>

    <button class="btn" type="submit"><?= $wasIncomplete ? 'Save and continue' : 'Save account details' ?></button>
</form>

<section class="card profile-card account-deletion-card">
    <h2>Account deletion</h2>
    <button class="btn btn-danger" type="button" aria-expanded="<?= $deleting ? 'true' : 'false' ?>"
        aria-controls="account-deletion-details" data-toggle-account-deletion>
        Delete account
    </button>
    <div id="account-deletion-details" <?= $deleting ? '' : 'hidden' ?>>
        <p class="muted">
            Your account is deactivated immediately and <strong>all of your existing collection requests are cancelled right away</strong>; the other party is notified. Unfinished trades are cancelled too. Log in within 24 hours to restore access. After that, personal details are anonymized when the system deletion task runs; transaction history is retained.
        </p>
        <form method="post" data-confirm-account-deletion>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_account">
            <label>Current password
                <input type="password" name="delete_password" required autocomplete="current-password">
            </label>
            <button class="btn btn-danger" type="submit">Schedule account deletion</button>
        </form>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
