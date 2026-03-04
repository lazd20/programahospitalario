<?php
// /public_html/evoprx/residentes/ingresar_desde_programacion.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

date_default_timezone_set('America/Guayaquil');
header('Content-Type: text/html; charset=utf-8');

global $pdo;

/* =========================================================
   ✅ Nuevo esquema de usuario / rol (role o rol)
   ========================================================= */
$u = function_exists('current_user') ? current_user() : null;

// id usuario (preferimos current_user, luego session)
$usuario_id = (int)(
  $_SESSION['usuario_id']
  ?? $_SESSION['user_id']
  ?? ($u['id'] ?? 0)
);

// rol (role o rol)
$role = (string)(
  $_SESSION['role']
  ?? $_SESSION['rol']
  ?? ($u['rol'] ?? ($u['role'] ?? ''))
);

// Si por algo no hay usuario, fuera
if ($usuario_id <= 0) {
  $to = function_exists('base_url') ? base_url('/residentes/login.php?e=sesion') : 'login.php?e=sesion';
  header("Location: {$to}");
  exit;
}

/* =========================================================
   Helpers locales
   ========================================================= */
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

function clean($v): string { return trim((string)$v); }

function buildPaciente(string $n1, string $n2, string $a1, string $a2, string $fallback = ''): string {
  $partes = [$n1, $n2, $a1, $a2];
  $partes = array_filter($partes, function($x){
    return $x !== null && trim($x) !== '';
  });
  $full = trim(preg_replace('/\s+/', ' ', implode(' ', $partes)));
  return $full !== '' ? $full : trim($fallback);
}

function hh($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* =========================================================
   Validar que el usuario exista en hosp_usuarios
   ========================================================= */
try {
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

  $chk = $pdo->prepare("SELECT 1 FROM hosp_usuarios WHERE id = ? LIMIT 1");
  $chk->execute([$usuario_id]);

  if (!$chk->fetchColumn()) {
    session_unset();
    session_destroy();
    $to = function_exists('base_url') ? base_url('/residentes/login.php?e=usuario_invalido') : 'login.php?e=usuario_invalido';
    header("Location: {$to}");
    exit;
  }
} catch (Throwable $e) {
  die("Error validando usuario: " . hh($e->getMessage()));
}

/* =========================================================
   Obtener ID programación (GET o POST)
   ========================================================= */
$programacion_id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $programacion_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
} else {
  $programacion_id = isset($_POST['programacion_id']) ? (int)$_POST['programacion_id'] : 0;
}
if ($programacion_id <= 0) die("ID de programación inválido.");

/* =========================================================
   Cargar programación
   ========================================================= */
try {
  $stmt = $pdo->prepare("SELECT * FROM hosp_programacion_quirofano WHERE id = ? LIMIT 1");
  $stmt->execute([$programacion_id]);
  $prog = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$prog) die("No existe la programación indicada.");
} catch (Throwable $e) {
  die("Error leyendo programación: " . hh($e->getMessage()));
}

/* =========================================================
   Si ya existe ingreso enlazado, redirigir
   ========================================================= */
try {
  if (!empty($prog['ingreso_id']) && (int)$prog['ingreso_id'] > 0) {
    $to = function_exists('base_url') ? base_url('/residentes/panel_ingresos.php') : 'panel_ingresos.php';
    header("Location: {$to}");
    exit;
  }

  $chkIng = $pdo->prepare("SELECT id FROM hosp_ingresos WHERE programacion_id = ? LIMIT 1");
  $chkIng->execute([$programacion_id]);
  $ex = $chkIng->fetch(PDO::FETCH_ASSOC);

  if ($ex && !empty($ex['id'])) {
    // Enlazar en programación (best effort)
    try {
      $upd = $pdo->prepare("UPDATE hosp_programacion_quirofano SET ingreso_id = ? WHERE id = ?");
      $upd->execute([(int)$ex['id'], $programacion_id]);
    } catch (Throwable $t) { /* ignore */ }

    $to = function_exists('base_url') ? base_url('/residentes/panel_ingresos.php') : 'panel_ingresos.php';
    header("Location: {$to}");
    exit;
  }
} catch (Throwable $e) {
  // ignore
}

/* =========================================================
   Obtener cirujanos + tipos ingreso
   ========================================================= */
try {
  $cirujanos = $pdo->query("SELECT id, nombre FROM hosp_cirujanos ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  die("Error al obtener cirujanos: " . hh($e->getMessage()));
}

try {
  $tipos = $pdo->query("SELECT id, nombre FROM hosp_tipos_ingreso ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  die("Error al obtener tipos de ingreso: " . hh($e->getMessage()));
}

/* =========================================================
   Defaults desde programación
   ========================================================= */
$default_fecha_entrada     = !empty($prog['fecha']) ? $prog['fecha'] : date('Y-m-d');
$default_cirujano_id       = !empty($prog['cirujano_id']) ? (int)$prog['cirujano_id'] : '';
$default_tipo_ingreso_id   = '';
$default_habitacion_id     = '';

$default_nombre1   = $prog['nombre1']   ?? '';
$default_nombre2   = $prog['nombre2']   ?? '';
$default_apellido1 = $prog['apellido1'] ?? '';
$default_apellido2 = $prog['apellido2'] ?? '';
$default_cedula    = $prog['cedula']    ?? '';

/* =========================================================
   Procesar envío
   ========================================================= */
$errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $nombre1   = clean($_POST['nombre1'] ?? '');
  $nombre2   = clean($_POST['nombre2'] ?? '');
  $apellido1 = clean($_POST['apellido1'] ?? '');
  $apellido2 = clean($_POST['apellido2'] ?? '');
  $cedula    = clean($_POST['cedula'] ?? '');

  $fecha_entrada   = $_POST['fecha_entrada'] ?? $default_fecha_entrada;

  $cirujano_id     = !empty($_POST['cirujano_id']) ? (int)$_POST['cirujano_id'] : null;
  $tipo_ingreso_id = !empty($_POST['tipo_ingreso_id']) ? (int)$_POST['tipo_ingreso_id'] : null;
  $habitacion_id   = !empty($_POST['habitacion_id']) ? (int)$_POST['habitacion_id'] : null;

  if ($nombre1 === '' || empty($tipo_ingreso_id) || empty($cirujano_id)) {
    $errorMsg = "Faltan datos obligatorios (Nombre 1, Médico tratante y Tipo de ingreso).";
  }

  if ($errorMsg === '') {

    // Nombre médico (tratante)
    $tratante = '';
    if ($cirujano_id) {
      $st = $pdo->prepare("SELECT nombre FROM hosp_cirujanos WHERE id = ? LIMIT 1");
      $st->execute([$cirujano_id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      $tratante = $row ? (string)$row['nombre'] : '';
    }

    $pacienteFinal = buildPaciente($nombre1, $nombre2, $apellido1, $apellido2, ($prog['paciente'] ?? ''));

    try {
      $pdo->beginTransaction();

      // Lock habitación si aplica
      if ($habitacion_id) {
        $chkHab = $pdo->prepare("SELECT estado FROM hosp_habitaciones WHERE id = ? FOR UPDATE");
        $chkHab->execute([$habitacion_id]);
        $hab = $chkHab->fetch(PDO::FETCH_ASSOC);

        if (!$hab) throw new Exception("La habitación seleccionada no existe.");
        if (strtolower(trim((string)$hab['estado'])) !== 'libre') {
          throw new Exception("La habitación ya no está disponible.");
        }
      }

      // Insert ingreso
      $sql = "INSERT INTO hosp_ingresos (
                nombre1, nombre2, apellido1, apellido2, cedula,
                fecha_entrada, tratante,
                cirujano_id, tipo_ingreso_id, estado,
                residente_id, usuario_id, habitacion_id,
                programacion_id
              ) VALUES (
                :nombre1, :nombre2, :apellido1, :apellido2, :cedula,
                :fecha_entrada, :tratante,
                :cirujano_id, :tipo_ingreso_id, 'ingresado',
                0, :usuario_id, :habitacion_id,
                :programacion_id
              )";

      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        ':nombre1'         => ($nombre1 === '' ? null : $nombre1),
        ':nombre2'         => ($nombre2 === '' ? null : $nombre2),
        ':apellido1'       => ($apellido1 === '' ? null : $apellido1),
        ':apellido2'       => ($apellido2 === '' ? null : $apellido2),
        ':cedula'          => ($cedula === '' ? null : $cedula),
        ':fecha_entrada'   => $fecha_entrada,
        ':tratante'        => $tratante,
        ':cirujano_id'     => $cirujano_id,
        ':tipo_ingreso_id' => $tipo_ingreso_id,
        ':usuario_id'      => $usuario_id,
        ':habitacion_id'   => $habitacion_id,
        ':programacion_id' => $programacion_id
      ]);

      $ingreso_id = (int)$pdo->lastInsertId();

      // Ocupar habitación
      if ($habitacion_id) {
        $pdo->prepare("UPDATE hosp_habitaciones SET estado = 'ocupada' WHERE id = ?")->execute([$habitacion_id]);
      }

      // Enlazar ingreso_id en programación
      $pdo->prepare("UPDATE hosp_programacion_quirofano SET ingreso_id = ? WHERE id = ?")
          ->execute([$ingreso_id, $programacion_id]);

      // Sincronizar datos hacia programación (si existen columnas)
      $table = 'hosp_programacion_quirofano';
      $set   = [];
      $par   = [];

      if (hasColumn($pdo, $table, 'nombre1'))   { $set[] = "nombre1 = ?";   $par[] = ($nombre1 === '' ? null : $nombre1); }
      if (hasColumn($pdo, $table, 'nombre2'))   { $set[] = "nombre2 = ?";   $par[] = ($nombre2 === '' ? null : $nombre2); }
      if (hasColumn($pdo, $table, 'apellido1')) { $set[] = "apellido1 = ?"; $par[] = ($apellido1 === '' ? null : $apellido1); }
      if (hasColumn($pdo, $table, 'apellido2')) { $set[] = "apellido2 = ?"; $par[] = ($apellido2 === '' ? null : $apellido2); }
      if (hasColumn($pdo, $table, 'cedula'))    { $set[] = "cedula = ?";    $par[] = ($cedula === '' ? null : $cedula); }
      if (hasColumn($pdo, $table, 'paciente'))  { $set[] = "paciente = ?";  $par[] = $pacienteFinal; }

      if ($set) {
        $par[] = $programacion_id;
        $pdo->prepare("UPDATE hosp_programacion_quirofano SET " . implode(", ", $set) . " WHERE id = ?")
            ->execute($par);
      }

      $pdo->commit();

      $to = function_exists('base_url') ? base_url('/residentes/panel_ingresos.php?ok=1') : '/evoprx/residentes/panel_ingresos.php?ok=1';
      header("Location: {$to}");
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errorMsg = "Error al registrar ingreso: " . $e->getMessage();
    }
  }

  // mantener valores
  $default_fecha_entrada   = $fecha_entrada ?? $default_fecha_entrada;
  $default_cirujano_id     = $cirujano_id ?? $default_cirujano_id;
  $default_tipo_ingreso_id = $tipo_ingreso_id ?? $default_tipo_ingreso_id;
  $default_habitacion_id   = $habitacion_id ?? $default_habitacion_id;

  $default_nombre1   = $nombre1;
  $default_nombre2   = $nombre2;
  $default_apellido1 = $apellido1;
  $default_apellido2 = $apellido2;
  $default_cedula    = $cedula;
}

/* =========================================================
   UI
   ========================================================= */
$quirofanoTxt = (($prog['Q1'] ?? '') === 'X') ? 'Q1' : (((($prog['Q2'] ?? '') === 'X') ? 'Q2' : 'Sin asignar'));
$horaIngreso  = !empty($prog['h_ingreso']) ? date('H:i', strtotime($prog['h_ingreso'])) : '—';
$horaCirugia  = !empty($prog['h_cirugia']) ? date('H:i', strtotime($prog['h_cirugia'])) : '—';
$fechaProg    = !empty($prog['fecha']) ? date('d-m-Y', strtotime($prog['fecha'])) : '—';
$nombreCompleto = trim(
  ($prog['nombre1'] ?? '') . ' ' . ($prog['nombre2'] ?? '') . ' ' .
  ($prog['apellido1'] ?? '') . ' ' . ($prog['apellido2'] ?? '')
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Ingresar desde programación</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <style>
    .select2-container .select2-selection--single { height: 38px; padding: 6px 12px; }
    .muted { color:#64748b; }
  </style>
</head>
<body class="bg-light">
<div class="container mt-4 mb-5">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Ingreso desde programación #<?= (int)$programacion_id ?></h4>
    <a href="<?= function_exists('base_url') ? base_url('/programacion/ver_programacion.php') : '/evoprx/programacion/ver_programacion.php' ?>"
       class="btn btn-outline-secondary btn-sm">← Volver a programación</a>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header bg-dark text-white">
      <div class="d-flex justify-content-between align-items-center">
        <span>Datos de la programación</span>
        <span class="badge bg-info text-dark">Quirófano: <?= hh($quirofanoTxt) ?></span>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-2">
        <div class="col-md-3"><strong>Fecha:</strong> <?= hh($fechaProg) ?></div>
        <div class="col-md-3"><strong>Hora ingreso:</strong> <?= hh($horaIngreso) ?></div>
        <div class="col-md-3"><strong>Hora cirugía:</strong> <?= hh($horaCirugia) ?></div>
        <div class="col-md-3"><strong>Prótesis:</strong> <?= !empty($prog['es_protesis']) ? 'SI' : 'NO' ?></div>
      </div>
      <hr>
      <div class="row g-2">
        <div class="col-md-6"><strong>Paciente (texto):</strong> <?= hh($prog['paciente'] ?? '') ?></div>
        <div class="col-md-6">
          <strong>Nombre normalizado:</strong>
          <?= $nombreCompleto !== '' ? hh($nombreCompleto) : '<span class="muted">— (aún no registrado)</span>' ?>
        </div>
      </div>
      <div class="row g-2 mt-2">
        <div class="col-md-6"><strong>Procedimiento:</strong> <?= hh($prog['procedimiento'] ?? '') ?></div>
        <div class="col-md-6"><strong>Cédula:</strong> <?= !empty($prog['cedula']) ? hh($prog['cedula']) : '<span class="muted">—</span>' ?></div>
      </div>
    </div>
  </div>

  <?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger"><?= hh($errorMsg) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
      <h5 class="mb-0">Completar ingreso</h5>
    </div>
    <div class="card-body">
      <form method="post" autocomplete="off">
        <input type="hidden" name="programacion_id" value="<?= (int)$programacion_id ?>">

        <div class="row g-2">
          <div class="col-md-6 mb-3">
            <label class="form-label">Nombre 1 *</label>
            <input type="text" name="nombre1" class="form-control" required value="<?= hh($default_nombre1) ?>">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Nombre 2</label>
            <input type="text" name="nombre2" class="form-control" value="<?= hh($default_nombre2) ?>">
          </div>

          <div class="col-md-6 mb-3">
            <label class="form-label">Apellido 1</label>
            <input type="text" name="apellido1" class="form-control" value="<?= hh($default_apellido1) ?>">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Apellido 2</label>
            <input type="text" name="apellido2" class="form-control" value="<?= hh($default_apellido2) ?>">
          </div>

          <div class="col-md-6 mb-3">
            <label class="form-label">Cédula</label>
            <input type="text" name="cedula" class="form-control" value="<?= hh($default_cedula) ?>">
            <small class="text-muted">Si no la tiene en programación, el residente la completa aquí.</small>
          </div>

          <div class="col-md-6 mb-3">
            <label class="form-label">Fecha de ingreso *</label>
            <input type="date" name="fecha_entrada" value="<?= hh($default_fecha_entrada) ?>" class="form-control" required>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">Médico tratante *</label>
          <select name="cirujano_id" id="cirujano_id" class="form-select" required>
            <option value="">Seleccione un médico</option>
            <?php foreach ($cirujanos as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((string)$default_cirujano_id === (string)$c['id']) ? 'selected' : '' ?>>
                <?= hh($c['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Tipo de ingreso *</label>
          <select name="tipo_ingreso_id" class="form-select" required>
            <option value="">Seleccione</option>
            <?php foreach ($tipos as $tipo): ?>
              <option value="<?= (int)$tipo['id'] ?>" <?= ((string)$default_tipo_ingreso_id === (string)$tipo['id']) ? 'selected' : '' ?>>
                <?= hh($tipo['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Habitación (opcional)</label>
          <select name="habitacion_id" class="form-select">
            <option value="">-- No asignar --</option>
            <?php
            $stHab = $pdo->query("SELECT id, numero, descripcion FROM hosp_habitaciones WHERE estado = 'libre' ORDER BY numero ASC");
            while ($row = $stHab->fetch(PDO::FETCH_ASSOC)) {
              $desc = $row['descripcion'] ? " - {$row['descripcion']}" : '';
              $sel  = ((string)$default_habitacion_id === (string)$row['id']) ? 'selected' : '';
              echo "<option {$sel} value=\"".(int)$row['id']."\">".hh($row['numero'].$desc)."</option>";
            }
            ?>
          </select>
        </div>

        <button type="submit" class="btn btn-primary w-100">Registrar ingreso</button>
      </form>
    </div>
  </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(function() {
  $('#cirujano_id').select2({ placeholder: "Seleccione un médico", allowClear: true, width: '100%' });
});
</script>
</body>
</html>