<?php
require_once __DIR__ . '/../auth.php';

session_start();

// Vaciar sesiиоn
$_SESSION = [];

// Borrar cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

redirect(($GLOBALS['BASE_URL'] ?? '') . '/auth/login.php');