<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();
$isBuyer = $user && $user['user_role'] === 'buyer';
$isSeller = $user && $user['user_role'] === 'seller';
$pdo = db();
$units = [
    'kg' => 'Per kilogram',
    'piece' => 'Per piece',
    'small' => 'Per small item',
    'medium' => 'Per medium item',
    'large' => 'Per large item',
];
$categories = $pdo->query(
    'SELECT category_id, category_name FROM material_categories WHERE is_active = 1 ORDER BY category_name'
)->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['buyer']);
    csrf_check();
    $values = is_array($_POST['prices'] ?? null) ? $_POST['prices'] : [];
    $validPrices = [];

    foreach ($categories as $category) {
        $categoryId = (int) $category['category_id'];
        $categoryValues = is_array($values[$categoryId] ?? null) ? $values[$categoryId] : [];
        foreach ($units as $unit => $_label) {
            $rawPrice = trim((string) ($categoryValues[$unit] ?? ''));
            if ($rawPrice === '') {
                $validPrices[$categoryId][$unit] = null;
            } elseif (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $rawPrice)) {
                $errors[] = 'Enter a valid price for ' . $category['category_name'] . ' (' . $units[$unit] . ').';
                break 2;
            } else {
                $validPrices[$categoryId][$unit] = $rawPrice;
            }
        }
    }

    if (!$errors) {
        try {
            $find = $pdo->prepare(
                'SELECT buying_price FROM junkshop_material_prices
                  WHERE buyer_id = ? AND category_id = ? AND price_unit = ? AND effective_until IS NULL'
            );
            $setPrice = $pdo->prepare('CALL sp_set_junkshop_price(?, ?, ?, ?)');

            foreach ($validPrices as $categoryId => $categoryValues) {
                foreach ($categoryValues as $unit => $price) {
                    if ($price === null) {
                        continue;
                    }
                    $find->execute([$user['user_id'], $categoryId, $unit]);
                    $current = $find->fetchColumn();
                    $find->closeCursor();
                    if ($current !== false && (int) round((float) $current * 100) === (int) round((float) $price * 100)) {
                        continue;
                    }
                    $setPrice->execute([$user['user_id'], $categoryId, $unit, $price]);
                    $setPrice->closeCursor();
                }
            }
            flash('success', 'Your junkshop prices have been updated.');
            redirect('prices.php');
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'Could not update your prices. Please try again.';
        }
    }
}

$ownPrices = [];
if ($isBuyer) {
    $ownQuery = $pdo->prepare(
        'SELECT category_id, price_unit, buying_price
           FROM junkshop_material_prices
          WHERE buyer_id = ? AND effective_until IS NULL'
    );
    $ownQuery->execute([$user['user_id']]);
    foreach ($ownQuery->fetchAll() as $price) {
        $ownPrices[(int) $price['category_id']][$price['price_unit']] = $price['buying_price'];
    }
}
$offers = $pdo->query(
    'SELECT p.offer_id, p.buyer_id, p.category_id, p.price_unit, p.buying_price,
            p.price_updated_at AS effective_from, p.junkshop_name, p.category_name,
            bp.barangay AS shop_barangay, bp.city AS shop_city
       FROM v_current_junkshop_prices p
       LEFT JOIN buyer_profiles bp ON bp.user_id = p.buyer_id
      ORDER BY p.junkshop_name, p.category_name, p.price_unit'
)->fetchAll();
$offerUnits = [
    'kg' => 'kg',
    'piece' => 'piece',
    'small' => 'small item',
    'medium' => 'medium item',
    'large' => 'large item',
];
$shops = [];
$residentArea = ['barangay' => '', 'city' => ''];
$requestId = null;
$comparison = null;
if ($isSeller) {
    if (isset($_GET['request_id'])) {
        $requestId = filter_var($_GET['request_id'], FILTER_VALIDATE_INT);
        if ($requestId === false || $requestId < 1) {
            http_response_code(404);
            exit;
        }
        $requestArea = $pdo->prepare(
            'SELECT barangay, city FROM collection_requests WHERE request_id = ? AND seller_id = ?'
        );
        $requestArea->execute([$requestId, $user['user_id']]);
        $residentArea = $requestArea->fetch() ?: [];
        if (!$residentArea) {
            http_response_code(404);
            exit;
        }
    } else {
        $addressQuery = $pdo->prepare(
            'SELECT barangay, city FROM addresses
              WHERE user_id = ? ORDER BY is_default DESC, address_id LIMIT 1'
        );
        $addressQuery->execute([$user['user_id']]);
        $residentArea = $addressQuery->fetch() ?: $residentArea;
    }

    foreach ($offers as $offer) {
        $buyerId = (int) $offer['buyer_id'];
        if (!isset($shops[$buyerId])) {
            $shops[$buyerId] = [
                'name' => $offer['junkshop_name'],
                'barangay' => (string) ($offer['shop_barangay'] ?? ''),
                'city' => (string) ($offer['shop_city'] ?? ''),
                'offers' => [],
                'top_offer' => $offer,
            ];
        }
        $shops[$buyerId]['offers'][] = $offer;
        if ((float) $offer['buying_price'] > (float) $shops[$buyerId]['top_offer']['buying_price']) {
            $shops[$buyerId]['top_offer'] = $offer;
        }
    }

    $areaRank = static function (array $shop) use ($residentArea): int {
        $sameBarangay = $residentArea['barangay'] !== ''
            && strcasecmp(trim($residentArea['barangay']), trim($shop['barangay'])) === 0;
        $sameCity = $residentArea['city'] !== ''
            && strcasecmp(trim($residentArea['city']), trim($shop['city'])) === 0;
        if ($sameBarangay && $sameCity) {
            return 0;
        }
        if ($sameCity) {
            return 1;
        }
        return 2;
    };
    uasort($shops, static function (array $a, array $b) use ($areaRank): int {
        $areaOrder = $areaRank($a) <=> $areaRank($b);
        $priceOrder = (float) $b['top_offer']['buying_price'] <=> (float) $a['top_offer']['buying_price'];
        return $areaOrder !== 0
            ? $areaOrder
            : ($priceOrder !== 0 ? $priceOrder : strcasecmp($a['name'], $b['name']));
    });
    if ($requestId !== null) {
        $comparison = compare_junkshops((int) $user['user_id'], $requestId);
    }
}
$pageTitle = 'Junkshop Buying Prices';
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <div><span class="eyebrow">Junkshop offers</span><h1>Compare current buying prices.</h1></div>
    <?php if ($user && $user['user_role'] === 'seller'): ?>
        <a class="btn" href="<?= e(url('request_new.php')) ?>">Request collection</a>
    <?php endif; ?>
</div>
<p class="muted">Prices are set by each junkshop. Size-based rates are per item; final quantities and prices are confirmed at pickup and approved by the seller.</p>
<?php if ($isSeller && $requestId !== null): ?>
    <p class="muted">The request-specific estimate uses materials and pickup location from request #<?= (int) $requestId ?>.</p>
<?php endif; ?>

<?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($isSeller && $comparison !== null): ?>
<section class="card">
    <h2>Estimated net payout for request #<?= (int) $comparison['requestId'] ?></h2>
    <p class="muted">Only selected materials with a compatible current offer for every item are ranked. For detailed estimates, the best compatible unit basis per item is used. Final quantities and prices may differ after inspection and negotiation.</p>
    <?php if ($comparison['missingPrices'] > 0): ?>
        <p class="muted"><?= (int) $comparison['missingPrices'] ?> junkshop(s) were excluded because they do not have a price for every selected material.</p>
    <?php endif; ?>
    <?php if (!$comparison['materials']): ?>
        <p class="muted">This request has no active material estimates.</p>
    <?php else: ?>
        <?php foreach (['inCity' => 'Same municipality', 'outside' => 'Outside municipality'] as $group => $heading): ?>
            <?php if ($comparison[$group]): ?>
                <h3><?= e($heading) ?></h3>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Junkshop</th><th>Per-material estimate</th><th>Gross</th><th>Pickup charge</th><th>Estimated net</th></tr></thead>
                        <tbody>
                        <?php foreach ($comparison[$group] as $shop): ?>
                            <tr>
                                <td><strong><?= e($shop['name']) ?></strong><br><small class="muted"><?= e(trim($shop['barangay'] . ', ' . $shop['city'], ', ')) ?></small></td>
                                <td><ul class="item-detail-list"><?php foreach ($shop['lines'] as $line): ?>
                                    <li><?= e($line['material']) ?>: <?= e(number_format($line['quantity'], 3)) ?> <?= e($line['unit']) ?> × <?= e(peso($line['unit_price'])) ?> = <?= e(peso($line['value'])) ?></li>
                                <?php endforeach; ?></ul></td>
                                <td><?= e(peso($shop['gross'])) ?></td>
                                <td><?= e(peso($shop['charge'])) ?><?php if ($shop['km'] !== null): ?><br><small class="muted">Straight-line estimate: <?= e(number_format($shop['km'], 1)) ?> km (not road distance)</small><?php endif; ?></td>
                                <td><strong><?= e(peso($shop['net'])) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$comparison['inCity'] && !$comparison['outside']): ?>
            <p class="muted">No junkshop can currently be ranked for all selected materials.</p>
        <?php endif; ?>
        <?php if ($comparison['unknownDistance'] > 0): ?>
            <p class="muted"><?= (int) $comparison['unknownDistance'] ?> outside junkshop(s) were not ranked because one or both map pins are missing.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($isBuyer): ?>
<section class="card">
    <h2>Manage your junkshop prices</h2>
    <p class="muted">Enter a price for each material and unit you buy. Leave a field blank to keep its current price. Updates keep your price history.</p>
    <form method="post">
        <?= csrf_field() ?>
        <div class="table-wrap">
            <table class="price-editor">
                <thead><tr><th>Material</th><?php foreach ($units as $label): ?><th><?= e($label) ?> (₱)</th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($categories as $category): $cid = (int) $category['category_id']; ?>
                    <tr>
                        <th scope="row"><?= e($category['category_name']) ?></th>
                        <?php foreach ($units as $unit => $label): ?>
                            <td><label class="price-input-label">
                                <span class="sr-only"><?= e($category['category_name'] . ' ' . $label) ?></span>
                                <input type="number" name="prices[<?= $cid ?>][<?= e($unit) ?>]" min="0" max="99999999.99" step="0.01"
                                       value="<?= e((string) ($ownPrices[$cid][$unit] ?? '')) ?>" placeholder="—">
                            </label></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button class="btn" type="submit">Save my prices</button>
    </form>
</section>
<?php endif; ?>

<?php if (!$offers): ?>
    <div class="card empty-state">
        <h2>No junkshop prices have been posted yet</h2>
        <p>Junkshops can publish their buying prices from this page after signing in.</p>
    </div>
<?php elseif ($isSeller): ?>
    <section class="shop-shortlist">
        <h2>Nearby junkshops and current prices</h2>
        <p class="muted">Shops in the pickup barangay appear first, followed by shops in the same city or municipality. Listed prices may use different materials and units.</p>
        <?php foreach ($shops as $buyerId => $shop):
            $location = implode(', ', array_filter([$shop['barangay'], $shop['city']]));
            $topOffer = $shop['top_offer'];
        ?>
            <details class="shop-offer">
                <summary>
                    <span class="shop-offer-heading">
                        <strong><?= e($shop['name']) ?></strong>
                        <small><?= e($location !== '' ? $location : 'Location not provided') ?></small>
                    </span>
                    <span class="shop-top-price">
                        <strong><?= e(peso($topOffer['buying_price'])) ?></strong>
                        <small><?= e($topOffer['category_name'] . ' / ' . ($offerUnits[$topOffer['price_unit']] ?? $topOffer['price_unit'])) ?></small>
                    </span>
                </summary>
                <div class="shop-offer-details">
                    <h3>Current offers from <?= e($shop['name']) ?></h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Material</th><th>Buying price</th><th>Updated</th></tr></thead>
                            <tbody>
                            <?php foreach ($shop['offers'] as $offer): ?>
                                <tr>
                                    <td><?= e($offer['category_name']) ?></td>
                                    <td><?= e(peso($offer['buying_price'])) ?> / <?= e($offerUnits[$offer['price_unit']] ?? $offer['price_unit']) ?></td>
                                    <td><?= e(fmt_date($offer['effective_from'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <section class="card">
                        <h3>Estimate a material value</h3>
                        <p class="muted">Choose one of this junkshop's offers and enter the matching quantity.</p>
                        <div class="calculator shop-calculator">
                            <label>Junkshop price
                                <select class="shop-estimate-offer">
                                    <option value="" selected disabled>Select an offer</option>
                                    <?php foreach ($shop['offers'] as $offer): ?>
                                        <option value="<?= (int) $offer['offer_id'] ?>" data-price="<?= e((string) $offer['buying_price']) ?>" data-unit="<?= e($offerUnits[$offer['price_unit']] ?? $offer['price_unit']) ?>">
                                            <?= e($offer['category_name'] . ' — ' . peso($offer['buying_price']) . ' / ' . ($offerUnits[$offer['price_unit']] ?? $offer['price_unit'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Estimated quantity
                                <input class="shop-estimate-quantity" type="number" min="0" max="100000" step="0.001" placeholder="Select an offer first" disabled>
                                <small class="muted shop-estimate-unit"></small>
                            </label>
                            <div>
                                <span class="muted">Estimated value</span>
                                <output class="estimate-result shop-estimate-value" aria-live="polite">₱0.00</output>
                            </div>
                        </div>
                        <p class="estimate-disclaimer">This is an estimate based on the listed price. The final value depends on item quality and buyer confirmation.</p>
                    </section>
                </div>
            </details>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="card table-wrap">
        <h2>Current offers</h2>
        <table>
            <thead><tr><th>Junkshop</th><th>Material</th><th>Buying price</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($offers as $offer): ?>
                <tr>
                    <td><?= e($offer['junkshop_name']) ?></td>
                    <td><?= e($offer['category_name']) ?></td>
                    <td><?= e(peso($offer['buying_price'])) ?> / <?= e($offerUnits[$offer['price_unit']] ?? $offer['price_unit']) ?></td>
                    <td><?= e(fmt_date($offer['effective_from'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <div class="row-between"><div><h2>Estimate a material value</h2><p class="muted">Choose a junkshop offer and enter the matching quantity.</p></div></div>
        <div class="calculator">
            <label for="estimate-offer">Junkshop price
                <select id="estimate-offer">
                    <option value="" selected disabled>Select an offer</option>
                    <?php foreach ($offers as $offer): ?>
                        <option value="<?= (int) $offer['offer_id'] ?>" data-price="<?= e((string) $offer['buying_price']) ?>" data-unit="<?= e($offerUnits[$offer['price_unit']] ?? $offer['price_unit']) ?>">
                            <?= e($offer['junkshop_name'] . ' — ' . $offer['category_name'] . ' — ' . peso($offer['buying_price']) . ' / ' . ($offerUnits[$offer['price_unit']] ?? $offer['price_unit'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="estimate-quantity">Estimated quantity
                <input id="estimate-quantity" type="number" min="0" max="100000" step="0.001" placeholder="Enter quantity" disabled>
                <small class="muted" id="estimate-unit"></small>
            </label>
            <div>
                <span class="muted">Estimated value</span>
                <output class="estimate-result" id="estimate-value" aria-live="polite">₱0.00</output>
            </div>
        </div>
        <p class="estimate-disclaimer">This is an estimate based on a junkshop's listed price. The final value depends on item quality and buyer confirmation.</p>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
