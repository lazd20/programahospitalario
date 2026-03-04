<?php
// auth.php
require_once __DIR__ . '/helpers.php';

/**
 * Inicia sesión del usuario (guarda datos mínimos en sesión)
 */
function login_user(array $userRow): void {
  $_SESSION['user'] = [
    'id'         => (int)$userRow['id'],
    'username'   => (string)$userRow['username'],
    'rol'        => (string)$userRow['rol'],
    'nombre'     => (string)($userRow['nombre'] ?? ''),
    'apellido'   => (string)($userRow['apellido'] ?? ''),
    'sello_path' => (string)($userRow['sello_path'] ?? ''), // para mostrar "Mi sello"
  ];
}

/**
 * Cierra sesión
 */
function logout_user(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
}

/**
 * Requiere sesión iniciada
 */
function require_login(): void {
  if (!current_user()) {
    flash_set('error', 'Debes iniciar sesión.');
    redirect(base_url('/auth/login.php'));
  }
}

/**
 * Refrescar datos del usuario en sesión (por ejemplo al subir sello)
 */
function refresh_current_user_from_db(PDO $pdo): void {
  $u = current_user();
  if (!$u) return;

  $st = $pdo->prepare("SELECT id, username, rol, nombre, apellido, sello_path FROM users WHERE id=? LIMIT 1");
  $st->execute([(int)$u['id']]);
  $row = $st->fetch();
  if ($row) {
    login_user($row);
  }
}