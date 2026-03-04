<?php
// /public_html/evoprx/programacion/header.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();

// Nombre para mostrar
$displayName = trim((string)($u['nombre'] ?? '') . ' ' . (string)($u['apellido'] ?? ''));
if ($displayName === '') $displayName = (string)($u['username'] ?? 'Usuario');

// Ruta de logout (ajusta si tu logout está en otra ruta)
$logoutUrl = $BASE . '/auth/logout.php';
if (!file_exists(__DIR__ . '/../auth/logout.php')) {
  // fallback por si tu logout está al mismo nivel o en otra ruta
  $logoutUrl = $BASE . '/logout.php';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gestión de Programación de Quirófanos</title>

  <!-- Si tienes un CSS propio en /programacion/styles.css, mantenlo -->
  <link rel="stylesheet" href="styles.css">

  <!-- (Opcional) Si quieres que los botones se vean bien sin tu CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
  <div class="container">
    <div class="d-flex justify-content-end align-items-center mb-3 gap-2">
      <span class="text-muted small">Bienvenido, <b><?= e($displayName) ?></b></span>
      <a href="<?= e($logoutUrl) ?>" class="btn btn-sm btn-danger">Cerrar sesión</a>
    </div>