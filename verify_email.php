<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

flash('info', 'Email verification is disabled. You can sign in with your account password.');
redirect('login.php');
