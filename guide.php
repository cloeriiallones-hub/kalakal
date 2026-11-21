<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Guide';
require __DIR__ . '/includes/header.php';
?>
<section class="section guide-page">
    <div class="section-heading">
        <span class="eyebrow">KALAKAL guide</span>
        <h1>How it works</h1>
        <p>Turn recyclable materials into value with these three simple steps.</p>
    </div>
    <div class="step-grid">
        <article class="step-card">
            <span class="step-number">01</span>
            <h3>Check prices</h3>
            <p>Compare current junkshop buying prices to see what your recyclables may be worth.</p>
            <a href="<?= e(url('prices.php')) ?>">View material prices</a>
        </article>
        <article class="step-card">
            <span class="step-number">02</span>
            <h3>Request a pickup</h3>
            <p>Choose your recyclable materials, enter estimated quantities, and provide pickup details.</p>
            <?php if (current_user() && current_user()['user_role'] === 'seller'): ?>
                <a href="<?= e(url('request_new.php')) ?>">Request collection</a>
            <?php else: ?>
                <a href="<?= e(url('register.php')) ?>">Create an account</a>
            <?php endif; ?>
        </article>
        <article class="step-card">
            <span class="step-number">03</span>
            <h3>Get weighed and paid</h3>
            <p>A junkshop proposes a pickup time and payment method. After you approve, the buyer records final quantities and prices for your review. Use Messages to discuss the collection.</p>
        </article>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
