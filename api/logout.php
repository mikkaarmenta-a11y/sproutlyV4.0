<?php
require __DIR__ . '/../lib/core.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_destroy();
    session_start();
    flash('success', 'You have been logged out.');
}
redirect('login.php');
