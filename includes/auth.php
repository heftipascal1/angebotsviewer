<?php

function requireAdmin(): void {
    secureSession();
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: ' . BASE_URL);
        exit;
    }
}

function isAdminLoggedIn(): bool {
    secureSession();
    return !empty($_SESSION['admin_logged_in']);
}

function csrfToken(): string {
    secureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    secureSession();
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Ungueltige Anfrage.');
    }
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}
