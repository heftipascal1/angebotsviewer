<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
secureSession();
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL);
exit;
