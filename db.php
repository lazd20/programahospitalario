<?php
// /public_html/evoprx/residentes/guardar_evolucion.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

header('Content-Type: text/html; charset=UTF-8');
date_default_timezone_set('America/Guayaquil');

function is_ajax_request(): bool {
  return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function hasColumn(PDO $pdo, string $table, string $column): bool {
  $sql = "SELECT COUNT(*)
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $column]);
  return (int)$st->fetchColumn() > 0;
}

// Ajusta si tu tabla real tiene otro nombre
$T_EVOL = 'hosp_evoluciones';

// Usuario actual
$u = function_exists('current_user') ? current_user() : [];
$usuario_id = (int)($u['id'] ?? 0);
$username   = (string)($u['username'] ?? '');
$nombreUser = (string)($u['nombre'] ?? $u['name'] ?? $username);

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  if (is_ajax_request()) {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']);
    exit;
  }
  redirect(base_url('/residentes/panel_ingresos.php'));
}

// Validaciones
$ingreso_id  = isset($_POST['ingreso_id']) ? (int)$_POST['ingreso_id'] : 0;
$observacion = trim((string)($_POST['observacion'] ?? ''));

if ($usuario_id <= 0) {
  if (is_ajax_request()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Sesión inválida.']);
    exit;
  }
  flash_set('error', 'Sesión inválida.');
  redirect(base_url('/residentes/panel_ingresos.php'));
}

if ($ingreso_id <= 0 || $observacion === '') {
  if (is_ajax_request()) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Datos incompletos (ingreso u observación).']);
    exit;
  }
  flash_set('error', 'Datos incompletos: ingreso u observación.');
  redirect(base_url('/residentes/panel_ingresos.php'));
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  try { $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $e) {}

  // Insert dinámico (usa columnas extra si existen)
  $cols = ['ingreso_id', 'usuario_id', 'observacion'];
  $vals = [$ingreso_id, $usuario_id, $observacion];

  // Si tienes columnas “snapshot” en hosp_evoluciones, las guardamos también
  if (hasColumn($pdo, $T_EVOL, 'usuario_nombre')) {
    $cols[] = 'usuario_nombre';
    $vals[] = ($nombreUser !== '' ? $nombreUser : $username);
  }
  if (hasColumn($pdo, $T_EVOL, 'username')) {
    $cols[] = 'username';
    $vals[] = ($username !== '' ? $username : null);
  }

  // created_at
  if (hasColumn($pdo, $T_EVOL, 'created_at')) {
    $cols[] = 'created_at';
    $vals[] = date('Y-m-d H:i:s');
  }

  $sql = "INSERT INTO {$T_EVOL} (" . implode(',', $cols) . ")
          VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($vals);

  if (is_ajax_request()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => true,
      'msg' => 'Evolución guardada',
      'data' => [
        'ingreso_id' => $ingreso_id,
        'usuario_id' => $usuario_id,
        'usuario' => ($nombreUser !== '' ? $nombreUser : $username),
        'created_at' => date('Y-m-d H:i:s'),
      ]
    ]);
    exit;
  }

  flash_set('success', 'Evolución guardada correctamente.');
  redirect(base_url('/residentes/panel_ingresos.php'));

} catch (Throwable $e) {
  if (is_ajax_request()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Error al guardar', 'error' => $e->getMessage()]);
    exit;
  }

  flash_set('error', 'Error al guardar evolución: ' . $e->getMessage());
  redirect(base_url('/residentes/panel_ingresos.php'));
}