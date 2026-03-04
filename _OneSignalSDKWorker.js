<?php
// /public_html/evoprx/programacion/actualizar_cirugia.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('America/Guayaquil');

global $pdo;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function trimv($v): string { return trim((string)$v); }

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

function hasTable(PDO $pdo, string $table): bool {
  $sql = "SELECT COUNT(*)
          FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?";
  $st = $pdo->prepare($sql);
  $st->execute([$table]);
  return (int)$st->fetchColumn() > 0;
}

// =========================
// Permisos por rol (admin/editor)
// =========================
$u = current_user();
$rol = (string)($u['rol'] ?? ($_SESSION['role'] ?? '')); // compat
if (!in_array($rol, ['admin','editor'], true)) {
  echo "<script>alert('No tienes permiso para realizar esta acción.'); window.location.href='ver_programacion.php';</script>";
  exit;
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<div class='alert alert-danger m-3'>Método no válido.</div>";
    exit;
  }

  // =========================
  // CSRF
  // =========================
  $token = (string)($_POST['_csrf'] ?? '');
  if ($token === '') {
    echo "<div class='alert alert-danger m-3'>Token CSRF faltante.</div>";
    exit;
  }

  // Si existe csrf_verify() úsala; si no, compara con csrf_token()
  if (function_exists('csrf_verify')) {
    if (!csrf_verify($token)) {
      echo "<div class='alert alert-danger m-3'>Token CSRF inválido.</div>";
      exit;
    }
  } else {
    if (!function_exists('csrf_token') || !hash_equals((string)csrf_token(), $token)) {
      echo "<div class='alert alert-danger m-3'>Token CSRF inválido.</div>";
      exit;
    }
  }

  // =========================
  // Tablas (prefijo hosp_)
  // =========================
  $tProg = 'hosp_programacion_quirofano';
  $tIng  = 'hosp_ingresos';
  $tCir  = hasTable($pdo, 'hosp_cirujanos') ? 'hosp_cirujanos' : 'cirujanos';

  // =========================
  // Datos base
  // =========================
  $id            = (int)($_POST['id'] ?? 0);
  $dia           = trimv($_POST['dia'] ?? '');
  $fecha         = trimv($_POST['fecha'] ?? '');
  $pacienteLibre = trimv($_POST['paciente'] ?? '');
  $edad          = $_POST['edad'] ?? null;
  $h_ingreso     = trimv($_POST['h_ingreso'] ?? '');
  $h_cirugia     = trimv($_POST['h_cirugia'] ?? '');
  $h_cirugia     = ($h_cirugia === '') ? null : $h_cirugia;

  $procedimiento = trimv($_POST['procedimiento'] ?? '');

  // OJO: en el formulario el name es "quirofano"
  $quirofano     = trimv($_POST['quirofano'] ?? '');

  $cirujano_id   = (int)($_POST['cirujano_id'] ?? 0);
  $anestesiologo = trimv($_POST['anestesiologo'] ?? '');
  $habitacion    = trimv($_POST['habitacion'] ?? '');
  $casa_comercial= trimv($_POST['casa_comercial'] ?? '');
  $mesa_traccion = trimv($_POST['mesa_traccion'] ?? '');
  $laboratorio   = (string)($_POST['laboratorio'] ?? '');
  $arco_en_c     = trimv($_POST['arco_en_c'] ?? '');
  $es_protesis   = isset($_POST['es_protesis']) ? 1 : 0;

  if ($id <= 0) {
    echo "<div class='alert alert-danger m-3'>ID inválido.</div>";
    exit;
  }
  if ($fecha === '' || $dia === '' || $h_ingreso === '' || $procedimiento === '') {
    echo "<div class='alert alert-danger m-3'>Faltan campos obligatorios (fecha, día, hora ingreso, procedimiento).</div>";
    exit;
  }
  if ($anestesiologo === '') {
    echo "<div class='alert alert-danger m-3'>El campo Anestesiólogo no puede estar vacío.</div>";
    exit;
  }

  // Quirófano Q1/Q2
  $Q1 = ($quirofano === 'Q1') ? 'X' : '';
  $Q2 = ($quirofano === 'Q2') ? 'X' : '';

  // =========================
  // Campos normalizados (si existen en programación)
  // =========================
  $tiene_nombre1_p   = hasColumn($pdo, $tProg, 'nombre1');
  $tiene_nombre2_p   = hasColumn($pdo, $tProg, 'nombre2');
  $tiene_apellido1_p = hasColumn($pdo, $tProg, 'apellido1');
  $tiene_apellido2_p = hasColumn($pdo, $tProg, 'apellido2');
  $tiene_cedula_p    = hasColumn($pdo, $tProg, 'cedula');

  $nombre1   = $tiene_nombre1_p   ? trimv($_POST['nombre1'] ?? '')   : '';
  $nombre2   = $tiene_nombre2_p   ? trimv($_POST['nombre2'] ?? '')   : '';
  $apellido1 = $tiene_apellido1_p ? trimv($_POST['apellido1'] ?? '') : '';
  $apellido2 = $tiene_apellido2_p ? trimv($_POST['apellido2'] ?? '') : '';
  $cedula    = $tiene_cedula_p    ? trimv($_POST['cedula'] ?? '')    : '';

  // Construir paciente visible
  $partes = array_filter([$nombre1, $nombre2, $apellido1, $apellido2], fn($x) => trim((string)$x) !== '');
  $pacienteConstruido = trim(preg_replace('/\s+/', ' ', implode(' ', $partes)));
  $pacienteFinal = ($pacienteConstruido !== '') ? $pacienteConstruido : $pacienteLibre;

  // =========================
  // Detectar ingreso vinculado
  // =========================
  $tiene_ingreso_id_prog      = hasColumn($pdo, $tProg, 'ingreso_id');
  $tiene_programacion_id_ing  = hasColumn($pdo, $tIng, 'programacion_id');

  $ingreso_id = 0;

  $sqlRead = "SELECT id" . ($tiene_ingreso_id_prog ? ", ingreso_id" : "") . " FROM {$tProg} WHERE id = ? LIMIT 1";
  $stRead = $pdo->prepare($sqlRead);
  $stRead->execute([$id]);
  $rowProg = $stRead->fetch(PDO::FETCH_ASSOC);

  if (!$rowProg) {
    echo "<div class='alert alert-danger m-3'>No existe la programación.</div>";
    exit;
  }

  if ($tiene_ingreso_id_prog && !empty($rowProg['ingreso_id'])) {
    $ingreso_id = (int)$rowProg['ingreso_id'];
  }

  if ($ingreso_id <= 0 && $tiene_programacion_id_ing) {
    $chk = $pdo->prepare("SELECT id FROM {$tIng} WHERE programacion_id = ? LIMIT 1");
    $chk->execute([$id]);
    $r = $chk->fetch(PDO::FETCH_ASSOC);

    if ($r && !empty($r['id'])) {
      $ingreso_id = (int)$r['id'];

      if ($tiene_ingreso_id_prog) {
        try {
          $up = $pdo->prepare("UPDATE {$tProg} SET ingreso_id = ? WHERE id = ?");
          $up->execute([$ingreso_id, $id]);
        } catch (Throwable $t) { /* ignore */ }
      }
    }
  }

  // =========================
  // Tratante desde cirujanos (si ingreso tiene campo tratante)
  // =========================
  $tratante = '';
  if ($cirujano_id > 0 && hasColumn($pdo, $tIng, 'tratante')) {
    $st = $pdo->prepare("SELECT nombre FROM {$tCir} WHERE id = ? LIMIT 1");
    $st->execute([$cirujano_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $tratante = $r ? (string)$r['nombre'] : '';
  }

  // =========================
  // UPDATE PROGRAMACIÓN (dinámico)
  // =========================
  $setP = [];
  $paramsP = [];

  $setP[] = "dia = ?";             $paramsP[] = $dia;
  $setP[] = "fecha = ?";           $paramsP[] = $fecha;
  $setP[] = "paciente = ?";        $paramsP[] = $pacienteFinal;
  $setP[] = "edad = ?";            $paramsP[] = ($edad === '' ? null : $edad);
  $setP[] = "h_ingreso = ?";       $paramsP[] = $h_ingreso;
  $setP[] = "h_cirugia = ?";       $paramsP[] = $h_cirugia;
  $setP[] = "procedimiento = ?";   $paramsP[] = $procedimiento;
  $setP[] = "Q1 = ?";              $paramsP[] = $Q1;
  $setP[] = "Q2 = ?";              $paramsP[] = $Q2;
  $setP[] = "cirujano_id = ?";     $paramsP[] = ($cirujano_id > 0 ? $cirujano_id : null);
  $setP[] = "anestesiologo = ?";   $paramsP[] = $anestesiologo;
  $setP[] = "habitacion = ?";      $paramsP[] = $habitacion;
  $setP[] = "casa_comercial = ?";  $paramsP[] = $casa_comercial;
  $setP[] = "mesa_traccion = ?";   $paramsP[] = $mesa_traccion;
  $setP[] = "laboratorio = ?";     $paramsP[] = $laboratorio;
  $setP[] = "arco_en_c = ?";       $paramsP[] = $arco_en_c;
  $setP[] = "es_protesis = ?";     $paramsP[] = $es_protesis;

  if ($tiene_nombre1_p)   { $setP[] = "nombre1 = ?";   $paramsP[] = ($nombre1   !== '' ? $nombre1   : null); }
  if ($tiene_nombre2_p)   { $setP[] = "nombre2 = ?";   $paramsP[] = ($nombre2   !== '' ? $nombre2   : null); }
  if ($tiene_apellido1_p) { $setP[] = "apellido1 = ?"; $paramsP[] = ($apellido1 !== '' ? $apellido1 : null); }
  if ($tiene_apellido2_p) { $setP[] = "apellido2 = ?"; $paramsP[] = ($apellido2 !== '' ? $apellido2 : null); }
  if ($tiene_cedula_p)    { $setP[] = "cedula = ?";    $paramsP[] = ($cedula    !== '' ? $cedula    : null); }

  $paramsP[] = $id;

  // =========================
  // UPDATE INGRESO (sincronización)
  // =========================
  $tiene_nombre1_i   = hasColumn($pdo, $tIng, 'nombre1');
  $tiene_nombre2_i   = hasColumn($pdo, $tIng, 'nombre2');
  $tiene_apellido1_i = hasColumn($pdo, $tIng, 'apellido1');
  $tiene_apellido2_i = hasColumn($pdo, $tIng, 'apellido2');
  $tiene_cedula_i    = hasColumn($pdo, $tIng, 'cedula');
  $tiene_cirujano_i  = hasColumn($pdo, $tIng, 'cirujano_id');
  $tiene_tratante_i  = hasColumn($pdo, $tIng, 'tratante');

  $setI = [];
  $paramsI = [];

  $hareSync = ($ingreso_id > 0);

  if ($hareSync) {
    if ($tiene_nombre1_i)   { $setI[] = "nombre1 = ?";   $paramsI[] = ($nombre1   !== '' ? $nombre1   : null); }
    if ($tiene_nombre2_i)   { $setI[] = "nombre2 = ?";   $paramsI[] = ($nombre2   !== '' ? $nombre2   : null); }
    if ($tiene_apellido1_i) { $setI[] = "apellido1 = ?"; $paramsI[] = ($apellido1 !== '' ? $apellido1 : null); }
    if ($tiene_apellido2_i) { $setI[] = "apellido2 = ?"; $paramsI[] = ($apellido2 !== '' ? $apellido2 : null); }
    if ($tiene_cedula_i)    { $setI[] = "cedula = ?";    $paramsI[] = ($cedula    !== '' ? $cedula    : null); }

    if ($tiene_cirujano_i)  { $setI[] = "cirujano_id = ?"; $paramsI[] = ($cirujano_id > 0 ? $cirujano_id : null); }
    if ($tiene_tratante_i)  { $setI[] = "tratante = ?";    $paramsI[] = ($tratante !== '' ? $tratante : null); }

    $paramsI[] = $ingreso_id;
  }

  // =========================
  // Ejecutar en transacción
  // =========================
  $pdo->beginTransaction();

  $sqlP = "UPDATE {$tProg} SET " . implode(", ", $setP) . " WHERE id = ?";
  $stP = $pdo->prepare($sqlP);
  $stP->execute($paramsP);

  if ($hareSync && !empty($setI)) {
    $sqlI = "UPDATE {$tIng} SET " . implode(", ", $setI) . " WHERE id = ?";
    $stI = $pdo->prepare($sqlI);
    $stI->execute($paramsI);
  }

  $pdo->commit();

  $msg = $hareSync ? "Cirugía actualizada y sincronizada con ingreso" : "Cirugía actualizada";
  header("Location: ver_programacion.php?mensaje=" . urlencode($msg));
  exit;

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  echo "<div class='alert alert-danger m-3'>Error: " . h($e->getMessage()) . "</div>";
}