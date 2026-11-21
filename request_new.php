<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(['seller']);

$user = current_user();
$uid = (int) $user['user_id'];
$pdo  = db();

if (needs_onboarding($user)) {
    flash('info', 'Please complete your account details first. They are used for your pickup address and contact number.');
    redirect('profile.php');
}
$errors = [];
$submissionToken = bin2hex(random_bytes(32));
$submittedToken = $_POST['submission_token'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && is_string($submittedToken)
    && preg_match('/^[a-f0-9]{64}$/i', $submittedToken)) {
    $submissionToken = strtolower($submittedToken);
}

$cats = $pdo->query(
    'SELECT category_id, category_name FROM material_categories WHERE is_active = 1 ORDER BY category_name'
)->fetchAll();

// Prefill the pickup snapshot from the user's default address.
$st = $pdo->prepare(
    "SELECT address_line, barangay, city, landmark, latitude, longitude
       FROM addresses WHERE user_id = ? ORDER BY is_default DESC, address_id LIMIT 1"
);
$st->execute([$user['user_id']]);
$defaultAddress = $st->fetch() ?: [];
$streetAddress = (string) ($defaultAddress['address_line'] ?? '');
$barangay = (string) ($defaultAddress['barangay'] ?? '');
$city = (string) ($defaultAddress['city'] ?? '');
$landmark = (string) ($defaultAddress['landmark'] ?? '');
$contactNumber = (string) ($user['phone_number'] ?? '');
$schedule = '';
$pickupNotes = '';
$consent = false;
$estimates = [];

// A junkshop picked from the dashboard comparison (optional).
$chosen = null;
$chosenId = filter_var($_POST['buyer_id'] ?? ($_GET['buyer_id'] ?? null), FILTER_VALIDATE_INT);
if ($chosenId) {
    $cq = $pdo->prepare(
        "SELECT bp.user_id, bp.company_name, bp.barangay, bp.city, bp.latitude, bp.longitude
           FROM buyer_profiles bp JOIN users u ON u.user_id = bp.user_id
          WHERE bp.user_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL"
    );
    $cq->execute([$chosenId]);
    $chosen = $cq->fetch() ?: null;
    if (!$chosen) {
        flash('error', 'That junkshop is no longer available. Choose another one from your dashboard.');
        redirect('dashboard.php');
    }
}
$ownerLat = $defaultAddress['latitude'] ?? null;
$ownerLon = $defaultAddress['longitude'] ?? null;
// distance + charge are always worked out here on the server, never taken from the form
$distanceKm = null;
$pickupCharge = 0.0;
$chargeProblem = null;
$computeCharge = static function (string $requestCity) use ($chosen, $ownerLat, $ownerLon, &$distanceKm, &$pickupCharge, &$chargeProblem): void {
    $distanceKm = null;
    $pickupCharge = 0.0;
    $chargeProblem = null;
    if (!$chosen) {
        return;
    }
    if ($ownerLat !== null && $ownerLon !== null && $chosen['latitude'] !== null && $chosen['longitude'] !== null) {
        $distanceKm = haversine_km((float) $ownerLat, (float) $ownerLon, (float) $chosen['latitude'], (float) $chosen['longitude']);
    }
    if (!same_city($requestCity, $chosen['city'])) {
        if ($distanceKm === null) {
            $chargeProblem = 'This junkshop is outside your municipality, so the pickup charge depends on distance. '
                . 'Pin your location in your profile (and ask the junkshop to pin theirs) first.';
            return;
        }
        $pickupCharge = pickup_charge_for($distanceKm);
    }
};
$computeCharge($city);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_string($submittedToken) || !preg_match('/^[a-f0-9]{64}$/i', $submittedToken)) {
        $errors[] = 'This request form has expired. Review the details and submit it again.';
    } else {
        $existingRequest = $pdo->prepare(
            'SELECT request_id FROM collection_requests
              WHERE seller_id = ? AND submission_token = ?'
        );
        $existingRequest->execute([$uid, $submissionToken]);
        $existingRequestId = $existingRequest->fetchColumn();
        if ($existingRequestId !== false) {
            flash('info', 'This pickup request was already submitted.');
            redirect('requests.php#request-' . (int) $existingRequestId);
        }
    }
    $streetAddress = trim((string) ($_POST['street_address'] ?? ''));
    $barangay = trim((string) ($_POST['barangay'] ?? ''));
    $city = trim((string) ($_POST['city'] ?? ''));
    $landmark = trim((string) ($_POST['landmark'] ?? ''));
    $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
    $schedule = trim((string) ($_POST['preferred_schedule'] ?? ''));
    $pickupNotes = trim((string) ($_POST['pickup_notes'] ?? ''));
    $consent = isset($_POST['consent_to_share']) && $_POST['consent_to_share'] === '1';
    $estimates = is_array($_POST['estimates'] ?? null) ? $_POST['estimates'] : [];
    $uploads  = $_FILES['photos'] ?? [];

    if ($streetAddress === '' || mb_strlen($streetAddress) > 255) {
        $errors[] = 'Enter a street or house number (up to 255 characters).';
    }
    if ($barangay === '' || mb_strlen($barangay) > 100) {
        $errors[] = 'Enter a barangay (up to 100 characters).';
    }
    if ($city === '' || mb_strlen($city) > 100) {
        $errors[] = 'Enter a city or municipality (up to 100 characters).';
    }
    if ($landmark === '' || mb_strlen($landmark) > 150) {
        $errors[] = 'Enter a landmark (up to 150 characters).';
    }
    if (!preg_match('/^(09[0-9]{9}|[+]639[0-9]{9})$/', $contactNumber)) {
        $errors[] = 'Enter a valid Philippine contact number.';
    }
    if (mb_strlen($pickupNotes) > 255) {
        $errors[] = 'Pickup notes must be 255 characters or fewer.';
    }
    if (!$consent) {
        $errors[] = 'Please consent to share your pickup details with the junkshop that accepts this request.';
    }
    $computeCharge($city);
    if ($chargeProblem) {
        $errors[] = $chargeProblem;
    }
    if ($pickupCharge > 0 && ($_POST['accept_charge'] ?? '') !== '1') {
        $errors[] = 'Please agree to the pickup charge of ' . peso($pickupCharge) . ' for this junkshop, or choose a junkshop in your municipality.';
    }

    $scheduleSql = null;
    if ($schedule !== '') {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $schedule);
        if (!$dt) {
            $errors[] = 'Preferred schedule is invalid.';
        } elseif ($dt < new DateTime()) {
            $errors[] = 'Preferred schedule must be in the future.';
        } else {
            $scheduleSql = $dt->format('Y-m-d H:i:s');
        }
    } else {
        $errors[] = 'Preferred pickup schedule is required.';
    }

    $items = [];
    $photos = [];
    $totalPhotoBytes = 0;
    $sizes = ['small', 'medium', 'large'];
    foreach ($cats as $c) {
        $cid = (int) $c['category_id'];
        $estimate = is_array($estimates[$cid] ?? null) ? $estimates[$cid] : [];
        if (($estimate['selected'] ?? '') !== '1') {
            continue;                       // this waste type was not chosen
        }
        $label = $c['category_name'];
        $quantityValue = trim((string) ($estimate['quantity'] ?? ''));
        $weightValue = trim((string) ($estimate['weight'] ?? ''));
        $size = (string) ($estimate['size'] ?? '');

        $valid = true;
        if (!ctype_digit($quantityValue) || (int) $quantityValue < 1 || (int) $quantityValue > 100000) {
            $errors[] = $label . ': enter the estimated quantity as a whole number of pieces (1 to 100,000).';
            $valid = false;
        }
        if (!in_array($size, $sizes, true)) {
            $errors[] = $label . ': choose a size (small, medium, or large).';
            $valid = false;
        }
        if (!is_numeric($weightValue) || (float) $weightValue <= 0 || (float) $weightValue > 100000) {
            $errors[] = $label . ': enter an estimated weight in kg greater than 0 and no more than 100,000.';
            $valid = false;
        }

        $file = [
            'name' => $uploads['name'][$cid] ?? '',
            'tmp_name' => $uploads['tmp_name'][$cid] ?? '',
            'error' => $uploads['error'][$cid] ?? UPLOAD_ERR_NO_FILE,
            'size' => $uploads['size'][$cid] ?? 0,
        ];
        if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = $label . ': upload a photo of this waste material.';
            $valid = false;
        } else {
            try {
                $photo = validate_image_upload($file, 2 * 1024 * 1024);
                $totalPhotoBytes += $photo['size'];
                if ($totalPhotoBytes > 5 * 1024 * 1024) {
                    $errors[] = 'The combined size of all photos must be 5 MB or smaller.';
                    $valid = false;
                } elseif ($valid) {
                    $photos[$cid] = $photo + ['name' => $photo['original_name']];
                }
            } catch (InvalidArgumentException $ex) {
                $errors[] = $label . ': ' . $ex->getMessage();
                $valid = false;
            }
        }
        if ($valid) {
            $items[$cid] = [
                'quantity' => (int) $quantityValue,
                'size' => $size,
                'weight' => round((float) $weightValue, 3),
            ];
        }
    }
    if (!$items && !$errors) {
        $errors[] = 'Choose at least one type of waste material to sell.';
    }
    if (!$errors) {
        $storedPaths = [];
        try {
            $uploadDir = __DIR__ . '/uploads/waste_photos';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Could not create the waste photo upload directory.');
            }

            foreach ($photos as $cid => &$photo) {
                $filename = bin2hex(random_bytes(16)) . '.' . $photo['extension'];
                $destination = $uploadDir . '/' . $filename;
                if (!move_uploaded_file($photo['tmp_name'], $destination)) {
                    throw new RuntimeException('Could not store an uploaded waste photo.');
                }
                $photo['stored_path'] = 'uploads/waste_photos/' . $filename;
                $storedPaths[] = $destination;
                $photo['original_name'] = substr(
                    preg_replace('/[^A-Za-z0-9._-]/', '_', basename($photo['name'])) ?: 'photo',
                    0,
                    255
                );
            }
            unset($photo);

            $pdo->beginTransaction();
            $pdo->prepare('SET @app_user_id = ?')->execute([$uid]);
            $pdo->prepare(
                'INSERT INTO collection_requests
                    (seller_id, submission_token, street_address, barangay, city, landmark, pickup_notes,
                     contact_number, latitude, longitude, consent_to_share, preferred_schedule,
                     preferred_buyer_id, distance_km, pickup_charge)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
            )->execute([
                $user['user_id'],
                $submissionToken,
                $streetAddress,
                $barangay,
                $city,
                $landmark,
                $pickupNotes !== '' ? $pickupNotes : null,
                $contactNumber,
                $ownerLat,
                $ownerLon,
                $scheduleSql,
                $chosen ? (int) $chosen['user_id'] : null,
                $distanceKm,
                $pickupCharge,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $insertPhoto = $pdo->prepare(
                'INSERT INTO file_uploads (uploaded_by, purpose, original_name, stored_path, mime_type, file_size_bytes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins = $pdo->prepare(
                'INSERT INTO collection_request_items
                    (request_id, category_id, estimated_weight_kg, estimate_method,
                     estimated_quantity, estimated_size, photo_file_id)
                 VALUES (?, ?, ?, \'detailed\', ?, ?, ?)'
            );
            foreach ($items as $cid => $estimate) {
                $photoId = null;
                if (isset($photos[$cid])) {
                    $photo = $photos[$cid];
                    $insertPhoto->execute([
                        $user['user_id'],
                        'waste_photo',
                        $photo['original_name'],
                        $photo['stored_path'],
                        $photo['mime'],
                        (int) $photo['size'],
                    ]);
                    $photoId = (int) $pdo->lastInsertId();
                }
                $ins->execute([
                    $requestId,
                    $cid,
                    $estimate['weight'],
                    $estimate['quantity'],
                    $estimate['size'],
                    $photoId,
                ]);
            }
            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($storedPaths as $path) {
                if (is_file($path) && !unlink($path)) {
                    error_log('Could not remove unreferenced waste photo: ' . $path);
                }
            }
            if ($ex->getCode() === '23000'
                && strpos((string) ($ex->errorInfo[2] ?? ''), 'uq_collection_requests_submission_token') !== false) {
                $existingRequest = $pdo->prepare(
                    'SELECT request_id FROM collection_requests
                      WHERE seller_id = ? AND submission_token = ?'
                );
                $existingRequest->execute([$uid, $submissionToken]);
                $existingRequestId = $existingRequest->fetchColumn();
                if ($existingRequestId !== false) {
                    flash('info', 'This pickup request was already submitted.');
                    redirect('requests.php#request-' . (int) $existingRequestId);
                }
            }
            error_log($ex->getMessage());
            $errors[] = 'Could not save your request. Please try again.';
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($storedPaths as $path) {
                if (is_file($path) && !unlink($path)) {
                    error_log('Could not remove unreferenced waste photo: ' . $path);
                }
            }
            error_log($ex->getMessage());
            $errors[] = 'Could not store your photos. Please try again.';
        }

        if (!$errors) {
            try {
                foreach ($pdo->query("SELECT user_id FROM users WHERE user_role = 'admin' AND is_active = 1")->fetchAll() as $a) {
                    notify((int) $a['user_id'], 'request_new', 'New pickup request',
                           $user['full_name'] . ' submitted request #' . $requestId . '.',
                           'collection_requests', $requestId);
                }
            } catch (PDOException $ex) {
                error_log($ex->getMessage());
                flash('error', 'Request #' . $requestId . ' was submitted, but we could not notify all administrators.');
                redirect('requests.php#request-' . $requestId);
            }
            flash('success', 'Request #' . $requestId . ' submitted. We will notify you once a junkshop accepts it.');
            redirect('requests.php#request-' . $requestId);
        }
    }
}

$pageTitle = 'New Pickup Request';
require __DIR__ . '/includes/header.php';
?>
<div class="card narrow-lg">
    <h1>Request a pickup</h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <?php if ($chosen): ?>
        <div class="alert alert-info">
            <strong>Sending this request to <?= e($chosen['company_name']) ?></strong>
            (<?= e($chosen['barangay']) ?>, <?= e($chosen['city']) ?>). Only this junkshop will see it.
            <?php if ($chargeProblem): ?>
                <br><?= e($chargeProblem) ?>
            <?php elseif ($pickupCharge > 0): ?>
                <br>This junkshop is outside your municipality (<?= e(number_format((float) $distanceKm, 1)) ?> km away).
                <strong>Pickup charge: <?= e(peso($pickupCharge)) ?></strong>
                (<?= e(peso(pickup_charge_settings()['base'])) ?> + <?= e(peso(pickup_charge_settings()['per_km'])) ?> per km).
            <?php else: ?>
                <br>Within your municipality: no pickup charge.
            <?php endif; ?>
            <a href="<?= e(url('dashboard.php')) ?>">Choose a different junkshop</a>
        </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" data-request-form>
        <?= csrf_field() ?>
        <input type="hidden" name="submission_token" value="<?= e($submissionToken) ?>">
        <?php if ($chosen): ?><input type="hidden" name="buyer_id" value="<?= (int) $chosen['user_id'] ?>"><?php endif; ?>
        <label>Street / House Number
            <input type="text" name="street_address" maxlength="255" value="<?= e($streetAddress) ?>" required>
        </label>
        <div class="grid-2">
            <label>Barangay
                <input type="text" name="barangay" maxlength="100" value="<?= e($barangay) ?>" required>
            </label>
            <label>City / Municipality
                <input type="text" name="city" maxlength="100" value="<?= e($city) ?>" required>
            </label>
        </div>
        <label>Landmark
            <input type="text" name="landmark" maxlength="150" value="<?= e($landmark) ?>" required>
        </label>
        <label>Contact number
            <input type="tel" name="contact_number" inputmode="tel" maxlength="13" value="<?= e($contactNumber) ?>" required>
        </label>
        <label>Preferred schedule
            <input type="datetime-local" name="preferred_schedule" value="<?= e($schedule) ?>" required>
        </label>
        <label>Pickup notes (optional)
            <textarea name="pickup_notes" rows="2" maxlength="255"><?= e($pickupNotes) ?></textarea>
        </label>
        <?php if ($pickupCharge > 0): ?>
        <label class="checkbox-label">
            <input type="checkbox" name="accept_charge" value="1" required>
            I agree to pay a pickup charge of <?= e(peso($pickupCharge)) ?> to <?= e($chosen['company_name']) ?>.
        </label>
        <?php endif; ?>
        <label class="checkbox-label">
            <input type="checkbox" name="consent_to_share" value="1" <?= $consent ? 'checked' : '' ?> required>
            I agree to share my pickup address and contact number with the junkshop that proposes this collection.
        </label>

        <fieldset>
            <legend>Waste materials you want to sell</legend>
            <p class="muted">Tick each type of waste material you want collected. For every type, give the estimated quantity, size, and weight, and upload a photo of it. JPEG, PNG, or WebP; up to 2 MB per photo and 5 MB total.</p>
            <div class="grid-2">
            <?php foreach ($cats as $c):
                $cid = (int) $c['category_id'];
                $oldEstimate = is_array($estimates[$cid] ?? null) ? $estimates[$cid] : [];
                $isPicked = ($oldEstimate['selected'] ?? '') === '1';
            ?>
                <div class="material-estimate<?= $isPicked ? ' is-selected' : '' ?>" data-material-estimate>
                    <label class="estimate-pick">
                        <input type="checkbox" name="estimates[<?= $cid ?>][selected]" value="1" data-estimate-select <?= $isPicked ? 'checked' : '' ?>>
                        <?= e($c['category_name']) ?>
                    </label>
                    <div class="estimate-fields" data-estimate-fields <?= $isPicked ? '' : 'hidden' ?>>
                        <label>Estimated quantity (pieces)
                            <input type="number" name="estimates[<?= $cid ?>][quantity]" min="1" max="100000" step="1" inputmode="numeric"
                                   value="<?= e((string) ($oldEstimate['quantity'] ?? '')) ?>" placeholder="e.g. 10" <?= $isPicked ? 'required' : 'disabled' ?>>
                        </label>
                        <label>Size
                            <select name="estimates[<?= $cid ?>][size]" <?= $isPicked ? 'required' : 'disabled' ?>>
                                <option value="">Choose a size</option>
                                <?php foreach (['small', 'medium', 'large'] as $size): ?>
                                    <option value="<?= e($size) ?>" <?= ($oldEstimate['size'] ?? '') === $size ? 'selected' : '' ?>><?= e(ucfirst($size)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Estimated weight (kg)
                            <input type="number" name="estimates[<?= $cid ?>][weight]" min="0.001" max="100000" step="0.001"
                                   value="<?= e((string) ($oldEstimate['weight'] ?? '')) ?>" placeholder="e.g. 5" <?= $isPicked ? 'required' : 'disabled' ?>>
                        </label>
                        <label>Photo of this material
                            <input type="file" name="photos[<?= $cid ?>]" accept="image/jpeg,image/png,image/webp" <?= $isPicked ? 'required' : 'disabled' ?>>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </fieldset>

        <button class="btn" type="submit">Submit request</button>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
