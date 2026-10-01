<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/version.php';

if (isAdminLoggedIn()) {
    header('Location: ' . BASE_URL . 'admin/');
    exit;
}

$db = getDB();
$ipHash = hashIP($_SERVER['REMOTE_ADDR'] ?? '');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lockRemaining = throttleStatus($db, $ipHash, 'admin');
    if ($lockRemaining > 0) {
        $error = 'Zu viele Fehlversuche. Bitte in ' . ceil($lockRemaining / 60) . ' Min. erneut versuchen.';
    } else {
        $pass = $_POST['password'] ?? '';
        if (password_verify($pass, ADMIN_PASSWORD_HASH)) {
            throttleReset($db, $ipHash, 'admin');
            secureSession();
            $_SESSION['admin_logged_in'] = true;
            session_regenerate_id(true);
            header('Location: ' . BASE_URL . 'admin/');
            exit;
        }
        throttleFail($db, $ipHash, 'admin');
        $error = 'Falsches Passwort.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(BRAND_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">
<div class="login-card">
    <div class="login-icon">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
    </div>
    <h1><?= e(BRAND_NAME) ?></h1>
    <p class="sub">Admin-Bereich</p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="password" name="password" placeholder="Admin-Passwort" autofocus required>
        <button type="submit" class="btn btn-primary btn-full">Anmelden</button>
    </form>
</div>
</body>
</html>
