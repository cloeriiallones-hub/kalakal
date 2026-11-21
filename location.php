<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role(['seller', 'buyer']);

$user = current_user();
$userId = (int) $user['user_id'];
$role = $user['user_role'];
$hasFullAddress = $role === 'seller';
$pdo = db();
$errors = [];
$location = [
    'address_line' => '',
    'barangay' => '',
    'city' => '',
    'province' => '',
    'postal_code' => '',
];

if ($hasFullAddress) {
    $st = $pdo->prepare(
        'SELECT address_line, barangay, city, province, postal_code
           FROM addresses
          WHERE user_id = ?
          ORDER BY is_default DESC, address_id
          LIMIT 1'
    );
    $st->execute([$userId]);
    $location = array_merge($location, $st->fetch() ?: []);
} elseif ($role === 'buyer') {
    $st = $pdo->prepare('SELECT barangay, city FROM buyer_profiles WHERE user_id = ?');
    $st->execute([$userId]);
    $location = array_merge($location, $st->fetch() ?: []);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($location as $field => $_) {
        $location[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    foreach (['barangay' => 'Barangay', 'city' => 'City or municipality'] as $field => $label) {
        if ($location[$field] === '' || mb_strlen($location[$field]) > 100) {
            $errors[] = $label . ' is required and must be 100 characters or fewer.';
        }
    }
    if ($hasFullAddress) {
        if ($location['address_line'] === '' || mb_strlen($location['address_line']) > 255) {
            $errors[] = 'Street or house number is required and must be 255 characters or fewer.';
        }
        if (mb_strlen($location['province']) > 100) {
            $errors[] = 'Province must be 100 characters or fewer.';
        }
        if (!preg_match('/^[0-9]{4}$/', $location['postal_code'])) {
            $errors[] = 'ZIP code must contain exactly 4 digits.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            if ($hasFullAddress) {
                $st = $pdo->prepare(
                    'SELECT address_id FROM addresses
                      WHERE user_id = ?
                      ORDER BY is_default DESC, address_id
                      LIMIT 1'
                );
                $st->execute([$userId]);
                $addressId = $st->fetchColumn();
                if ($addressId !== false) {
                    $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')
                        ->execute([$userId]);
                    $update = $pdo->prepare(
                        'UPDATE addresses
                            SET address_line = ?, barangay = ?, city = ?, province = ?, postal_code = ?, is_default = 1
                          WHERE address_id = ? AND user_id = ?'
                    );
                    $update->execute([
                        $location['address_line'],
                        $location['barangay'],
                        $location['city'],
                        $location['province'] !== '' ? $location['province'] : null,
                        $location['postal_code'],
                        $addressId,
                        $userId,
                    ]);
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO addresses (user_id, address_line, barangay, city, province, postal_code, is_default)
                         VALUES (?, ?, ?, ?, ?, ?, 1)'
                    );
                    $insert->execute([
                        $userId,
                        $location['address_line'],
                        $location['barangay'],
                        $location['city'],
                        $location['province'] !== '' ? $location['province'] : null,
                        $location['postal_code'],
                    ]);
                }
            } elseif ($role === 'buyer') {
                $update = $pdo->prepare(
                    'UPDATE buyer_profiles SET barangay = ?, city = ? WHERE user_id = ?'
                );
                $update->execute([$location['barangay'], $location['city'], $userId]);
                if ($update->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM buyer_profiles WHERE user_id = ?');
                    $exists->execute([$userId]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('Junkshop profile is missing.');
                    }
                }
            }
            $pdo->commit();
            flash('success', 'Your location has been updated.');
            redirect('location.php');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($ex->getMessage());
            $errors[] = 'Could not update your location. Please try again.';
        }
    }
}

$pageTitle = 'My Location';
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <div><span class="eyebrow">Account settings</span><h1>My location</h1></div>
</div>
<section class="card location-card">
    <p class="muted">
        <?= $hasFullAddress
            ? 'Update your address whenever you move. New pickup requests will use this address; existing requests keep their original pickup details.'
            : 'Update the barangay and city or municipality where your junkshop is located.' ?>
    </p>
    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>
    <form method="post">
        <?= csrf_field() ?>
        <?php if ($hasFullAddress): ?>
            <label>Street / house number
                <input type="text" name="address_line" value="<?= e($location['address_line']) ?>" required maxlength="255" autocomplete="street-address">
            </label>
        <?php endif; ?>
        <div class="grid-2 location-fields">
            <label>Barangay
                <input type="text" name="barangay" value="<?= e($location['barangay']) ?>" required maxlength="100">
            </label>
            <label>City / municipality
                <input type="text" name="city" value="<?= e($location['city']) ?>" required maxlength="100">
            </label>
        </div>
        <?php if ($hasFullAddress): ?>
            <div class="grid-2 location-fields">
                <label>Province <span class="muted">(optional)</span>
                    <input type="text" name="province" value="<?= e((string) $location['province']) ?>" maxlength="100">
                </label>
                <label>ZIP code
                    <input type="text" name="postal_code" value="<?= e((string) $location['postal_code']) ?>" required inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" title="Enter a 4-digit ZIP code">
                </label>
            </div>
        <?php endif; ?>
        <button class="btn" type="submit">Save location</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
