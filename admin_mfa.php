<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

unset($_SESSION['pending_admin_mfa']);
flash('info', 'Administrator email-code verification has been disabled. Sign in with your password.');
redirect('login.php');
