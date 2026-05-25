<?php
require_once __DIR__ . '/includes/auth.php';
startSecureSession();

// Nur POST mit gültigem CSRF-Token akzeptieren → kein Force-Logout per Drittseite.
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    header('Location: /index.php');
    exit;
}

logoutUser();
header('Location: /index.php');
exit;
