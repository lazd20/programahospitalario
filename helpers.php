<?php
// helpers.php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/**
 * Escapar HTML
 */
function e($str): string {
  return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * URL base
 */
function base_url(string $path = ''): string {
  $base = $GLOBALS['BASE_URL'] ?? '';
  if ($path === '') return $base;
  if ($path[0] !== '/') $path = '/' . $path;
  return $base . $path;
}

/**
 * Redirección
 */
function redirect(string $to): void {
  header('Location: ' . $to);
  exit;
}

/**
 * Flash messages
 */
function flash_set(string $type, string $msg): void {
  $_SESSION['flash'] = $_SESSION['flash'] ?? [];
  $_SESSION['flash'][$type] = $msg;
}

function flash_get_all(): array {
  $out = $_SESSION['flash'] ?? [];
  unset($_SESSION['flash']);
  return $out;
}

/**
 * Usuario actual
 */
function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

/**
 * Requerir rol
 */
function require_role(array $roles): void {
  $u = current_user();
  if (!$u) {
    flash_set('error', 'Debes iniciar sesión.');
    redirect(base_url('/auth/login.php'));
  }
  if (!in_array(($u['rol'] ?? ''), $roles, true)) {
    flash_set('error', 'No tienes permisos para acceder.');
    redirect(base_url('/index.php'));
  }
}

/**
 * Subida segura de archivos (sello/rúbrica)
 * - Permite PNG/JPG/WEBP
 * - Máx 2MB por defecto
 * - Devuelve ruta relativa a la raíz del proyecto: uploads/signatures/xxx.png
 */
function upload_signature(string $inputName = 'signature', int $maxBytes = 2097152): array {
  $BASE = $GLOBALS['BASE_URL'] ?? '';

  if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
    return ['ok' => false, 'error' => 'No se recibió el archivo o hubo un error al subirlo.'];
  }

  $f = $_FILES[$inputName];

  if ($f['size'] > $maxBytes) {
    return ['ok' => false, 'error' => 'El archivo es muy grande. Máximo 2MB.'];
  }

  $tmp = $f['tmp_name'];
  $mime = mime_content_type($tmp);

  $allowed = [
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
  ];

  if (!isset($allowed[$mime])) {
    return ['ok' => false, 'error' => 'Formato no permitido. Usa PNG, JPG o WEBP.'];
  }

  $ext = $allowed[$mime];

  // Carpeta destino: /uploads/signatures/
  $root = dirname(__FILE__); // donde está config/helpers
  $uploadsDir = $root . '/uploads/signatures';
  if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0755, true);
  }

  // Nombre único
  $name = 'sig_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $dest = $uploadsDir . '/' . $name;

  if (!move_uploaded_file($tmp, $dest)) {
    return ['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.'];
  }

  // Ruta relativa para guardar en DB (sin BASE_URL)
  $relative = 'uploads/signatures/' . $name;

  return ['ok' => true, 'path' => $relative];
}

/**
 * CSRF (opcional, simple)
 */
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['_csrf'];
}

function csrf_check(?string $token): bool {
  return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], (string)$token);
}

function client_ip(): string {
  if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
  return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

// helpers.php
if (!defined('DB_TABLE_PREFIX')) {
  define('DB_TABLE_PREFIX', 'hosp_'); // <-- tu prefijo real
}

function t(string $table): string {
  return DB_TABLE_PREFIX . $table;
}
