<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pageTitle = 'Collect, Valuate, Trade';
$prices = db()->query(
    "SELECT category_name, buying_price, price_unit, price_updated_at AS effective_from, junkshop_name
       FROM v_current_junkshop_prices
      ORDER BY price_updated_at DESC, category_name
      LIMIT 6"
)->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <div class="hero-content">
        <h1>Turn your recyclables into value.</h1>
        <p>Recycle locally. See current prices. Request a pickup.</p>
        <div class="hero-actions">
            <?php if (!current_user()): ?>
                <a class="btn" href="<?= e(url('register.php')) ?>">Get started</a>
                <a class="btn btn-outline" href="<?= e(url('prices.php')) ?>">Explore materials</a>
            <?php else: ?>
                <a class="btn" href="<?= e(url('dashboard.php')) ?>">Open dashboard</a>
                <?php if (current_user()['user_role'] === 'seller'): ?>
                    <a class="btn btn-outline" href="<?= e(url('request_new.php')) ?>">Request pickup</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="hero-art">
        <img class="hero-logo" src="<?= e(url('assets/images/home-hero.png')) ?>" alt="Recycling, a community trade, and payment">
    </div>
</section>

<section class="section" id="materials">
    <div class="section-heading"><h2>Current junkshop offers</h2></div>
    <?php if ($prices): ?>
        <div class="material-grid">
            <?php foreach ($prices as $p): ?>
                <article class="material-card">
                    <div class="material-top">
                        <span class="material-icon"><?= e($p['category_name']) ?></span>
                    </div>
                    <div class="material-price"><?= e(peso($p['buying_price'])) ?><span class="material-unit"> / <?= e($p['price_unit'] === 'kg' ? 'kg' : ($p['price_unit'] === 'piece' ? 'piece' : $p['price_unit'] . ' item')) ?></span></div>
                    <div class="material-updated"><?= e($p['junkshop_name']) ?> · Updated <?= e(fmt_date($p['effective_from'])) ?></div>
                </article>
            <?php endforeach; ?>
        </div>
        <a class="section-link" href="<?= e(url('prices.php')) ?>">View all prices and estimate value <span aria-hidden="true">→</span></a>
    <?php else: ?>
        <div class="card empty-state"><p>No junkshop offers have been posted yet.</p></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
