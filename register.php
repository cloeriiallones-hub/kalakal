<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    redirect('dashboard.php');
}

$pageTitle = 'Register';
$errors = [];
$old = ['last_name' => '', 'first_name' => '', 'email' => '', 'phone_number' => '', 'user_role' => 'seller',
        'seller_type' => 'resident',
        'junkshop_name' => '', 'address_line' => '', 'barangay' => '', 'city' => '', 'province' => '', 'postal_code' => '',
        'region' => ''];
$allowedRoles = ['seller', 'buyer'];   // admin is NEVER self-registered
$activeStep = 1;
$regions = [
    ['code' => '010000000', 'name' => 'Region I - Ilocos Region'],
    ['code' => '020000000', 'name' => 'Region II - Cagayan Valley'],
    ['code' => '030000000', 'name' => 'Region III - Central Luzon'],
    ['code' => '040000000', 'name' => 'Region IV-A - CALABARZON'],
    ['code' => '170000000', 'name' => 'MIMAROPA Region'],
    ['code' => '050000000', 'name' => 'Region V - Bicol Region'],
    ['code' => '060000000', 'name' => 'Region VI - Western Visayas'],
    ['code' => '070000000', 'name' => 'Region VII - Central Visayas'],
    ['code' => '080000000', 'name' => 'Region VIII - Eastern Visayas'],
    ['code' => '090000000', 'name' => 'Region IX - Zamboanga Peninsula'],
    ['code' => '100000000', 'name' => 'Region X - Northern Mindanao'],
    ['code' => '110000000', 'name' => 'Region XI - Davao Region'],
    ['code' => '120000000', 'name' => 'Region XII - SOCCSKSARGEN'],
    ['code' => '130000000', 'name' => 'National Capital Region (NCR)'],
    ['code' => '140000000', 'name' => 'Cordillera Administrative Region (CAR)'],
    ['code' => '160000000', 'name' => 'Region XIII - Caraga'],
    ['code' => '180000000', 'name' => 'Negros Island Region (NIR)'],
    ['code' => '150000000', 'name' => 'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $_) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $old['seller_type'] = in_array($old['seller_type'], ['resident', 'establishment'], true)
        ? $old['seller_type'] : '';
    $old['email'] = strtolower($old['email']);
    $requiresAddress = $old['user_role'] === 'seller';
    $requiresLocation = in_array($old['user_role'], $allowedRoles, true);
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    foreach (['last_name' => 'last', 'first_name' => 'first'] as $field => $label) {
        if (mb_strlen($old[$field]) < 1 || mb_strlen($old[$field]) > 50) {
            $errors[] = 'Please enter your ' . $label . ' name (up to 50 characters).';
        }
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 150) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!preg_match('/^9[0-9]{9}$/', $old['phone_number'])) {
        $errors[] = 'Contact number must contain exactly 10 digits after +63 and start with 9.';
    }
    if (!in_array($old['user_role'], $allowedRoles, true)) {
        $errors[] = 'Please choose a valid account type.';
    }
    if ($old['user_role'] === 'seller' && !in_array($old['seller_type'], ['resident', 'establishment'], true)) {
        $errors[] = 'Please choose whether you are registering as a resident or establishment.';
    }
    if ($old['user_role'] === 'buyer' && ($old['junkshop_name'] === '' || mb_strlen($old['junkshop_name']) > 150)) {
        $errors[] = 'Please enter your junkshop name (up to 150 characters).';
    }
    if ($requiresLocation) {
        if (!in_array($old['region'], array_column($regions, 'name'), true)) {
            $errors[] = 'Please select your region.';
        }
        if ($old['barangay'] === '' || mb_strlen($old['barangay']) > 100) {
            $errors[] = 'Please select your barangay.';
        }
        if ($old['city'] === '' || mb_strlen($old['city']) > 100) {
            $errors[] = 'Please select your city or municipality.';
        }
    }
    if ($requiresAddress) {
        if ($old['address_line'] === '') {
            $errors[] = 'Street or house number is required.';
        }
        if (!preg_match('/^[0-9]{4}$/', $old['postal_code'])) {
            $errors[] = 'ZIP code must contain exactly 4 digits.';
        }
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        $errors[] = 'Password must be between 8 and 72 bytes.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if ($errors) {
        $hasPersonalError = (
            mb_strlen($old['last_name']) < 1 || mb_strlen($old['last_name']) > 50
            || mb_strlen($old['first_name']) < 1 || mb_strlen($old['first_name']) > 50
            || !filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 150
            || !preg_match('/^9[0-9]{9}$/', $old['phone_number'])
            || !in_array($old['user_role'], $allowedRoles, true)
            || ($old['user_role'] === 'seller' && !in_array($old['seller_type'], ['resident', 'establishment'], true))
            || ($old['user_role'] === 'buyer' && ($old['junkshop_name'] === '' || mb_strlen($old['junkshop_name']) > 150))
        );
        $activeStep = $hasPersonalError ? 1 : 2;
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            $firstName = $old['first_name'];
            $lastName = $old['last_name'];
            $st = $pdo->prepare(
                'INSERT INTO users (first_name, middle_name, last_name, suffix, email, phone_number, password_hash, user_role, seller_type, privacy_consent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $st->execute([
                $firstName,
                null,
                $lastName,
                null,
                $old['email'],
                '+63' . $old['phone_number'],
                password_hash($password, PASSWORD_DEFAULT),
                $old['user_role'],
                $old['user_role'] === 'seller' ? $old['seller_type'] : null,
            ]);
            $newId = (int) $pdo->lastInsertId();

            if ($old['user_role'] === 'buyer') {
                $pdo->prepare(
                    'INSERT INTO buyer_profiles (user_id, company_name, business_address, barangay, city)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $newId,
                    $old['junkshop_name'],
                    null,
                    $old['barangay'],
                    $old['city'],
                ]);
            }
            if ($requiresAddress) {
                $st = $pdo->prepare(
                    'INSERT INTO addresses (user_id, address_line, barangay, city, province, postal_code, is_default)
                     VALUES (?, ?, ?, ?, ?, ?, 1)'
                );
                $st->execute([
                    $newId,
                    $old['address_line'],
                    $old['barangay'],
                    $old['city'],
                    $old['province'] !== '' ? $old['province'] : null,
                    $old['postal_code'],
                ]);
            }
            $pdo->commit();

            flash('success', 'Your account was created. You can now log in.');
            redirect('login.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000'
                && strpos((string) ($ex->errorInfo[2] ?? ''), 'uq_users_email') !== false) {
                $errors[] = 'That email is already registered.';
                $activeStep = 1;
            } else {
                error_log($ex->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        } catch (InvalidArgumentException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getMessage();
            $activeStep = 1;
        }
    }
}
require __DIR__ . '/includes/header.php';
?>
<section class="auth-layout register-layout">
    <div class="auth-visual">
        <img src="<?= e(url('assets/images/register-illustration.png')) ?>" alt="Recyclable bottles, cans, cardboard, paper, and recycling symbol">
    </div>
    <div class="card auth-card register-card">
        <div class="auth-intro"><span class="eyebrow">KALAKAL · Kawayan, Biliran</span><h1>Create your account</h1><p>Join your local recycling network.</p></div>
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" autocomplete="off" class="register-form" data-active-step="<?= $activeStep ?>" <?= $errors ? 'data-validated="true"' : '' ?>>
            <?= csrf_field() ?>
            <div class="register-step" data-step="1">
            <div class="name-fields">
                <label>Last name
                    <input type="text" name="last_name" value="<?= e($old['last_name']) ?>" required maxlength="50" autocomplete="family-name">
                </label>
                <label>First name
                    <input type="text" name="first_name" value="<?= e($old['first_name']) ?>" required maxlength="50" autocomplete="given-name">
                </label>
            </div>
            <label>Email address
                <input type="email" name="email" value="<?= e($old['email']) ?>" required maxlength="150" autocomplete="email">
            </label>
            <label>Contact number
                <span class="phone-field"><span class="phone-prefix" aria-hidden="true">+63</span><input type="tel" name="phone_number" value="<?= e($old['phone_number']) ?>" required inputmode="numeric" pattern="9[0-9]{9}" minlength="10" maxlength="10" autocomplete="tel-national" title="Enter 10 digits starting with 9" placeholder="9xxxxxxxxx"></span>
            </label>
            <label>Account type
                <select name="user_role">
                    <?php foreach ($allowedRoles as $r): ?>
                        <?php $roleLabel = $r === 'buyer' ? 'Buyer (Junkshop)' : 'Seller'; ?>
                        <option value="<?= e($r) ?>" <?= $old['user_role'] === $r ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label data-seller-type-wrap <?= $old['user_role'] === 'seller' ? '' : 'hidden' ?>>Seller type
                <select name="seller_type" <?= $old['user_role'] === 'seller' ? 'required' : 'disabled' ?>>
                    <option value="resident" <?= $old['seller_type'] === 'resident' ? 'selected' : '' ?>>Resident</option>
                    <option value="establishment" <?= $old['seller_type'] === 'establishment' ? 'selected' : '' ?>>Establishment</option>
                </select>
            </label>
            <label data-junkshop-name-wrap <?= $old['user_role'] === 'buyer' ? '' : 'hidden' ?>>Junkshop name
                <input type="text" name="junkshop_name" value="<?= e($old['junkshop_name']) ?>" maxlength="150" <?= $old['user_role'] === 'buyer' ? 'required' : 'disabled' ?>>
            </label>
            <button class="btn form-submit" type="button" data-next-step>Continue</button>
            </div>
            <div class="register-step" data-step="2" hidden>
                <input type="hidden" name="region" value="<?= e($old['region']) ?>">
                <input type="hidden" name="address_line" value="<?= e($old['address_line']) ?>">
                <input type="hidden" name="barangay" value="<?= e($old['barangay']) ?>">
                <input type="hidden" name="city" value="<?= e($old['city']) ?>">
                <input type="hidden" name="province" value="<?= e($old['province']) ?>">
                <input type="hidden" name="postal_code" value="<?= e($old['postal_code']) ?>">
                <div data-registration-address>
                    <label class="address-label" data-address-label><?= $old['user_role'] === 'seller' ? 'Address' : 'Location' ?></label>
                    <button class="address-picker" type="button" aria-required="true" aria-haspopup="dialog" aria-controls="address-dialog"
                        <?= $errors && (empty($old['region']) || empty($old['barangay']) || empty($old['city']) || ($old['user_role'] === 'seller' && (empty($old['address_line']) || !preg_match('/^[0-9]{4}$/', $old['postal_code'])))) ? 'data-invalid="true"' : '' ?>>
                        <span class="address-picker-copy">
                            <small class="address-required-note"><?= $old['user_role'] === 'seller' ? 'Required for pickups' : 'Required for nearby matches' ?></small>
                            <?php if ($old['barangay'] !== '' && $old['city'] !== ''): ?>
                                <span class="address-summary"><?= e(implode(', ', array_filter($old['user_role'] === 'seller' ? [$old['address_line'], $old['barangay'], $old['city'], $old['postal_code']] : [$old['barangay'], $old['city']]))) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="address-picker-action" data-address-action><?= $old['user_role'] === 'seller' ? 'Add address' : 'Add location' ?> <span aria-hidden="true">→</span></span>
                    </button>
                </div>
                <label>Password
                <span class="password-field">
                    <input id="register-password" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="8 to 72 bytes">
                    <button class="password-toggle" type="button" aria-controls="register-password" aria-label="Show password" aria-pressed="false">
                        <svg class="eye-icon eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-icon eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15.8 15.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1 0 2-.2 2.8-.5"/></svg>
                    </button>
                </span>
            </label>
                <label>Confirm password
                    <span class="password-field confirm-password-field">
                        <input id="confirm-password" type="password" name="confirm_password" required minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="confirm-password-feedback">
                        <button class="password-toggle" type="button" aria-controls="confirm-password" aria-label="Show password" aria-pressed="false">
                            <svg class="eye-icon eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-icon eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15.8 15.8 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1 0 2-.2 2.8-.5"/></svg>
                        </button>
                    </span>
                    <small id="confirm-password-feedback" class="password-match-feedback" aria-live="polite" hidden></small>
                </label>
                <div class="register-step-actions">
                    <button class="btn btn-outline" type="button" data-previous-step>Previous</button>
                    <button class="btn" type="submit">Create account</button>
                </div>
            </div>
        </form>
        <p class="muted auth-footnote">Already have an account? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
    </div>
    <dialog class="address-dialog" id="address-dialog" aria-labelledby="address-dialog-title">
        <div class="address-dialog-content">
            <div class="row-between">
                <div><h2 id="address-dialog-title">Pickup address</h2><p class="muted" data-address-dialog-copy>Required for collection pickups.</p></div>
                <button class="dialog-close" type="button" aria-label="Close address form" data-close-address>×</button>
            </div>
            <div class="location-load-error alert alert-error" role="alert" hidden></div>
            <label>Region
                <select data-address-field="region" required>
                    <option value="" selected disabled>Select your region</option>
                    <?php foreach ($regions as $region): ?>
                        <option value="<?= e($region['name']) ?>" data-region-code="<?= e($region['code']) ?>" <?= $old['region'] === $region['name'] ? 'selected' : '' ?>><?= e($region['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>City / Municipality
                <select data-address-field="city" required disabled>
                    <option value="" selected disabled>Select a region first</option>
                </select>
            </label>
            <label>Barangay
                <select data-address-field="barangay" required disabled>
                    <option value="" selected disabled>Select a city / municipality first</option>
                </select>
            </label>
            <label data-full-address-field>Street/ House Number
                <input type="text" data-address-field="address_line" value="<?= e($old['address_line']) ?>" required maxlength="255" autocomplete="street-address">
            </label>
            <label data-full-address-field>ZIP code
                <input type="text" data-address-field="postal_code" value="<?= e($old['postal_code']) ?>" required inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" title="Enter a 4-digit ZIP code">
            </label>
            <div class="register-step-actions">
                <button class="btn btn-outline" type="button" data-close-address>Cancel</button>
                <button class="btn" type="button" data-save-address>Save address</button>
            </div>
        </div>
    </dialog>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
