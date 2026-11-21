<?php
$navUser = current_user();
$roleLabels = ['seller' => 'Seller', 'buyer' => 'Junkshop / Buyer', 'admin' => 'Administrator'];
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$isAuthPage = in_array($currentPage, ['login.php', 'register.php'], true);
$onboard = $navUser && needs_onboarding($navUser);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#14532d">
    <title><?= e(($pageTitle ?? 'Home') . ' | ' . APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= (int) filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="<?= $isAuthPage ? 'auth-page' : '' ?><?= $navUser ? ' workspace-body' : '' ?>">
<header class="topbar">
    <div class="container nav">
        <?php if ($navUser): ?>
        <button class="brand workspace-sidebar-open-toggle" type="button" aria-controls="workspace-sidebar" aria-expanded="false" aria-label="Show workspace menu" title="Show workspace menu" data-sidebar-toggle>
            <img src="<?= e(url('assets/images/final-logo.png')) ?>" alt="" class="brand-logo">
            <span class="brand-copy"><strong><?= e(APP_NAME) ?></strong><small>Recycling Marketplace</small></span>
        </button>
        <?php else: ?>
        <a class="brand" href="<?= e(url('index.php')) ?>" aria-label="KALAKAL home">
            <img src="<?= e(url('assets/images/final-logo.png')) ?>" alt="KALAKAL logo" class="brand-logo">
            <span class="brand-copy"><strong><?= e(APP_NAME) ?></strong><small>Recycling Marketplace</small></span>
        </a>
        <?php endif; ?>
        <?php if ($navUser): $r = $navUser['user_role']; ?>
            <div class="workspace-header">
                <div class="workspace-breadcrumb" aria-label="Breadcrumb">
                    <a href="<?= e(url('dashboard.php')) ?>">Workspace</a>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page"><?= e($pageTitle ?? 'Dashboard') ?></span>
                </div>
                <nav class="workspace-header-actions" aria-label="Quick links">
                    <a class="workspace-header-link" href="<?= e(url('messages.php')) ?>" aria-label="Messages" title="Messages">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16v12H4z"></path><path d="m4 7 8 6 8-6"></path></svg>
                    </a>
                    <a class="workspace-header-link" href="<?= e(url('dashboard.php#notifications')) ?>" aria-label="Notifications" title="Notifications">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                    </a>
                </nav>
            </div>
        <?php else: ?>
            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="public-nav" aria-label="Open navigation menu">Menu</button>
            <nav id="public-nav" class="public-nav">
                <a href="<?= e(url('index.php')) ?>" <?= $currentPage === 'index.php' ? 'class="active" aria-current="page"' : '' ?>>Home</a>
                <a href="<?= e(url('guide.php')) ?>" <?= $currentPage === 'guide.php' ? 'class="active" aria-current="page"' : '' ?>>Guide</a>
                <a href="<?= e(url('prices.php')) ?>" <?= $currentPage === 'prices.php' ? 'class="active" aria-current="page"' : '' ?>>Prices</a>
                <a class="nav-login<?= $currentPage === 'login.php' ? ' active' : '' ?>" href="<?= e(url('login.php')) ?>" <?= $currentPage === 'login.php' ? 'aria-current="page"' : '' ?>>Log in</a>
                <a class="btn btn-small nav-register<?= $currentPage === 'register.php' ? ' active' : '' ?>" href="<?= e(url('register.php')) ?>" <?= $currentPage === 'register.php' ? 'aria-current="page"' : '' ?>>Create account</a>
            </nav>
        <?php endif; ?>
    </div>
</header>
<?php if ($navUser): ?>
<div class="app-shell" id="workspace-layout">
    <aside class="sidebar" id="workspace-sidebar" aria-label="Main navigation">
        <div class="sidebar-heading">
            <div class="sidebar-brand">
                <img class="sidebar-brand-logo" src="<?= e(url('assets/images/final-logo.png')) ?>" alt="">
                <span><strong>KALAKAL</strong><small>Workspace</small></span>
            </div>
            <button class="workspace-sidebar-toggle sidebar-collapse-toggle" type="button" aria-controls="workspace-sidebar" aria-expanded="true" aria-label="Hide workspace menu" title="Hide workspace menu" data-sidebar-toggle>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.5" y="4" width="17" height="16" rx="2"></rect><path d="M9 4.5v15"></path></svg>
            </button>
        </div>
        <nav class="sidebar-nav" aria-label="Workspace navigation">
            <div class="sidebar-group">
                <span class="sidebar-label">Workspace</span>
                <a class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="<?= e(url('dashboard.php')) ?>" <?= $currentPage === 'dashboard.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">◫</span> Dashboard</a>
                <a class="<?= $currentPage === 'messages.php' ? 'active' : '' ?>" href="<?= e(url('messages.php')) ?>" <?= $currentPage === 'messages.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">✉</span> Messages</a>
            </div>
            <?php if ($r === 'seller'): ?>
                <div class="sidebar-group">
                    <span class="sidebar-label">Seller tools</span>
                    <a class="<?= $currentPage === 'prices.php' ? 'active' : '' ?>" href="<?= e(url('prices.php')) ?>" <?= $currentPage === 'prices.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">₱</span> Material valuation</a>
                    <a class="<?= $currentPage === 'request_new.php' ? 'active' : '' ?>" href="<?= e(url('request_new.php')) ?>" <?= $currentPage === 'request_new.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">＋</span> Request collection</a>
                    <a class="<?= $currentPage === 'requests.php' ? 'active' : '' ?>" href="<?= e(url('requests.php')) ?>" <?= $currentPage === 'requests.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">▤</span> My requests</a>
                    <a class="<?= $currentPage === 'trades.php' ? 'active' : '' ?>" href="<?= e(url('trades.php')) ?>" <?= $currentPage === 'trades.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">⇄</span> Inventory trades</a>
                    <a class="<?= $currentPage === 'earnings.php' ? 'active' : '' ?>" href="<?= e(url('earnings.php')) ?>" <?= $currentPage === 'earnings.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">₱</span> Earnings</a>
                </div>
            <?php elseif ($r === 'buyer'): ?>
                <div class="sidebar-group">
                    <span class="sidebar-label">Junkshop tools</span>
                    <a class="<?= $currentPage === 'buyer_requests.php' ? 'active' : '' ?>" href="<?= e(url('buyer_requests.php')) ?>" <?= $currentPage === 'buyer_requests.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">▤</span> Collection requests</a>
                    <a class="<?= $currentPage === 'collections.php' ? 'active' : '' ?>" href="<?= e(url('collections.php')) ?>" <?= $currentPage === 'collections.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">♧</span> My collections</a>
                    <a class="<?= $currentPage === 'prices.php' ? 'active' : '' ?>" href="<?= e(url('prices.php')) ?>" <?= $currentPage === 'prices.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">₱</span> Buying prices</a>
                    <a class="<?= $currentPage === 'trades.php' ? 'active' : '' ?>" href="<?= e(url('trades.php')) ?>" <?= $currentPage === 'trades.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">⇄</span> Trade offers</a>
                </div>
            <?php elseif ($r === 'admin'): ?>
                <div class="sidebar-group">
                    <span class="sidebar-label">Administration</span>
                    <a class="<?= $currentPage === 'admin_users.php' ? 'active' : '' ?>" href="<?= e(url('admin_users.php')) ?>" <?= $currentPage === 'admin_users.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">♙</span> Manage users</a>
                    <a class="<?= $currentPage === 'requests.php' ? 'active' : '' ?>" href="<?= e(url('requests.php')) ?>" <?= $currentPage === 'requests.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">▤</span> All requests</a>
                    <a class="<?= $currentPage === 'prices.php' ? 'active' : '' ?>" href="<?= e(url('prices.php')) ?>" <?= $currentPage === 'prices.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">₱</span> Material prices</a>
                    <a class="<?= $currentPage === 'admin_reports.php' ? 'active' : '' ?>" href="<?= e(url('admin_reports.php')) ?>" <?= $currentPage === 'admin_reports.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">▥</span> Reports</a>
                </div>
            <?php endif; ?>
            <div class="sidebar-group">
                <span class="sidebar-label">Account</span>
                <?php if (in_array($r, ['seller', 'buyer'], true)): ?>
                    <a class="<?= $currentPage === 'location.php' ? 'active' : '' ?>" href="<?= e(url('location.php')) ?>" <?= $currentPage === 'location.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">⌖</span> My location</a>
                <?php endif; ?>
                <a class="<?= $currentPage === 'sessions.php' ? 'active' : '' ?>" href="<?= e(url('sessions.php')) ?>" <?= $currentPage === 'sessions.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">⟳</span> Active sessions</a>
                <a href="<?= e(url('index.php')) ?>" <?= $currentPage === 'index.php' ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true">↗</span> Public home</a>
            </div>
        </nav>
        <details class="sidebar-account">
            <summary class="sidebar-user<?= $currentPage === 'profile.php' ? ' active' : '' ?><?= $onboard ? ' needs-attention' : '' ?>" aria-label="Account menu"<?= $onboard ? ' data-onboarding-target' : '' ?>>
                <span class="user-avatar"><?= e(strtoupper(substr($navUser['display_name'], 0, 1))) ?></span>
                <span class="sidebar-user-copy"><strong><?= e($navUser['display_name']) ?></strong><small><?= e($roleLabels[$r] ?? ucfirst($r)) ?></small></span>
                <span class="sidebar-user-chevron" aria-hidden="true">›</span>
            </summary>
            <div class="sidebar-account-menu">
                <a class="sidebar-account-action" href="<?= e(url('profile.php')) ?>" <?= $currentPage === 'profile.php' ? 'aria-current="page"' : '' ?>>My profile</a>
                <form method="post" action="<?= e(url('logout.php')) ?>" class="sidebar-account-logout">
                    <?= csrf_field() ?>
                    <button class="sidebar-account-action" type="submit">Log out</button>
                </form>
            </div>
        </details>
    </aside>
    <main class="container app-main">
<?php else: ?>
    <main class="container public-main">
<?php endif; ?>
<?php foreach (flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<?php if ($onboard && $currentPage !== 'profile.php'): ?>
    <div class="onboarding-popup" id="onboarding-popup" role="dialog" aria-labelledby="onboarding-title" data-user="<?= (int) $navUser['user_id'] ?>" hidden>
        <span class="onboarding-arrow" aria-hidden="true"></span>
        <span class="eyebrow">Welcome to KALAKAL</span>
        <h2 id="onboarding-title">Complete your account details</h2>
        <p>Click the highlighted profile icon to fill in the details we need<?= $r === 'buyer' ? ' before you accept collection requests' : ' before you request a pickup' ?>. You can edit them again any time.</p>
        <div class="onboarding-actions">
            <a class="btn btn-small" href="<?= e(url('profile.php')) ?>">Go to my profile</a>
            <button class="btn btn-small btn-outline" type="button" data-onboarding-later>Maybe later</button>
        </div>
    </div>
<?php endif; ?>
