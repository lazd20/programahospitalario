<?php
// /public_html/evoprx/programacion/modificar_cirugia.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

date_default_timezone_set('America/Guayaquil');
header('Content-Type: text/html; charset=UTF-8');

global $pdo;

// ==============================
// Helpers
// ==============================
function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ==============================
// Nuevo esquema de roles (TU estándar)
// ==============================
$u = function_exists('current_user') ? current_user() : null;
$role = (string)($_SESSION['role'] ?? ($_SESSION['rol'] ?? ($u['rol'] ?? ($u['role'] ?? ''))));

// Permisos: admin o editor
if (!in_array($role, ['admin', 'editor'], true)) {
  $to = function_exists('base_url') ? base_url('/programacion/ver_programacion.php') : 'ver_programacion.php';
  echo "<script>alert('No tienes permiso para realizar esta acción.'); window.location.href=" . json_encode($to) . ";</script>";
  exit;
}

// ==============================
// Tablas (prefijo hosp_)
// ==============================
$T_PQ = 'hosp_programacion_quirofano';
$T_C  = 'hosp_cirujanos';

// ==============================
// Validar ID
// ==============================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  echo "<div class='alert alert-danger m-3'>No se proporcionó un ID válido.</div>";
  exit;
}

try {
  // Asegurar UTF8
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

  // ==============================
  // Cargar cirugía
  // ==============================
  $stmt = $pdo->prepare("SELECT * FROM {$T_PQ} WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $id]);
  $cirugia = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$cirugia) {
    echo "<div class='alert alert-danger m-3'>Cirugía no encontrada.</div>";
    exit;
  }

  // ==============================
  // Cargar cirujanos
  // ==============================
  $cirujanos = $pdo->query("SELECT id, nombre, especialidad FROM {$T_C} ORDER BY nombre ASC")
                   ->fetchAll(PDO::FETCH_ASSOC);

  // CSRF (si existe en tu helpers)
  $csrf = function_exists('csrf_token') ? csrf_token() : '';

  // URL volver
  $urlVolver = function_exists('base_url')
    ? base_url('/programacion/ver_programacion.php')
    : 'ver_programacion.php';

  // Action actualizar (tu archivo existente)
  $actionUpdate = 'actualizar_cirugia.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Modificar Cirugía Programada</title>
  <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-4 mb-5">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Modificar Cirugía Programada</h3>
    <a href="<?= h($urlVolver) ?>" class="btn btn-outline-secondary btn-sm">← Volver</a>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">

      <form action="<?= h($actionUpdate) ?>" method="POST" autocomplete="off">
        <?php if ($csrf !== ''): ?>
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <?php endif; ?>
        <input type="hidden" name="id" value="<?= (int)$cirugia['id'] ?>">

        <div class="form-group">
          <label for="fecha">Fecha</label>
          <input type="date" class="form-control" id="fecha" name="fecha"
                 value="<?= h($cirugia['fecha'] ?? '') ?>" onchange="actualizarDia()" required>
        </div>

        <div class="form-group">
          <label for="dia">Día</label>
          <input type="text" class="form-control" id="dia" name="dia"
                 value="<?= h($cirugia['dia'] ?? '') ?>" readonly>
        </div>

        <div class="form-group">
          <label for="paciente">Paciente (texto)</label>
          <input type="text" class="form-control" id="paciente" name="paciente"
                 value="<?= h($cirugia['paciente'] ?? '') ?>" required>
          <small class="text-muted">Ej: POR ENVIAR / “Juan Pérez”</small>
        </div>

        <!-- CÉDULA -->
        <div class="form-group">
          <label for="cedula">Cédula</label>
          <input type="text" class="form-control" id="cedula" name="cedula"
                 value="<?= h($cirugia['cedula'] ?? '') ?>" maxlength="20">
          <small class="text-muted">Opcional en programación (el residente suele completarla).</small>
        </div>

        <!-- Nombres estructurados -->
        <div class="form-row">
          <div class="form-group col-md-3">
            <label>Nombre 1</label>
            <input type="text" class="form-control" name="nombre1" value="<?= h($cirugia['nombre1'] ?? '') ?>">
          </div>
          <div class="form-group col-md-3">
            <label>Nombre 2</label>
            <input type="text" class="form-control" name="nombre2" value="<?= h($cirugia['nombre2'] ?? '') ?>">
          </div>
          <div class="form-group col-md-3">
            <label>Apellido 1</label>
            <input type="text" class="form-control" name="apellido1" value="<?= h($cirugia['apellido1'] ?? '') ?>">
          </div>
          <div class="form-group col-md-3">
            <label>Apellido 2</label>
            <input type="text" class="form-control" name="apellido2" value="<?= h($cirugia['apellido2'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label for="edad">Edad</label>
          <input type="number" class="form-control" id="edad" name="edad" value="<?= h($cirugia['edad'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="h_ingreso">Hora de Ingreso</label>
          <input type="time" class="form-control" id="h_ingreso" name="h_ingreso"
                 value="<?= h($cirugia['h_ingreso'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label for="h_cirugia">Hora de Cirugía</label>
          <input type="time" class="form-control" id="h_cirugia" name="h_cirugia"
                 value="<?= h($cirugia['h_cirugia'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="procedimiento">Procedimiento</label>
          <input type="text" class="form-control" id="procedimiento" name="procedimiento"
                 value="<?= h($cirugia['procedimiento'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label>Quirófano</label><br>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="quirofano" id="q1" value="Q1"
              <?= (($cirugia['Q1'] ?? '') === 'X') ? 'checked' : '' ?>>
            <label class="form-check-label" for="q1">Q1</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="quirofano" id="q2" value="Q2"
              <?= (($cirugia['Q2'] ?? '') === 'X') ? 'checked' : '' ?>>
            <label class="form-check-label" for="q2">Q2</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="quirofano" id="sin_asignar" value=""
              <?= (empty($cirugia['Q1']) && empty($cirugia['Q2'])) ? 'checked' : '' ?>>
            <label class="form-check-label" for="sin_asignar">Sin asignar</label>
          </div>
        </div>

        <div class="form-group">
          <label for="cirujano_id">Cirujano</label>
          <select class="form-control" id="cirujano_id" name="cirujano_id" required>
            <option value="">Seleccione</option>
            <?php foreach ($cirujanos as $c): ?>
              <option value="<?= (int)$c['id'] ?>"
                <?= ((int)($cirugia['cirujano_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                <?= h($c['nombre']) ?><?= ($c['especialidad'] ?? '') !== '' ? ' (' . h($c['especialidad']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="anestesiologo">Anestesiólogo</label>
          <input type="text" class="form-control" id="anestesiologo" name="anestesiologo"
                 value="<?= h($cirugia['anestesiologo'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label for="habitacion">Habitación</label>
          <select class="form-control" id="habitacion" name="habitacion" required>
            <?php
              $hab = (string)($cirugia['habitacion'] ?? '');
            ?>
            <option value="Ambulatorio" <?= ($hab === 'Ambulatorio') ? 'selected' : '' ?>>Ambulatorio</option>
            <option value="Internado"    <?= ($hab === 'Internado') ? 'selected' : '' ?>>Internado</option>
            <option value="InternadoP"   <?= ($hab === 'InternadoP') ? 'selected' : '' ?>>Internado PREMIUM</option>
          </select>
        </div>

        <div class="form-group">
          <label for="casa_comercial">Casa Comercial</label>
          <?php $cc = (string)($cirugia['casa_comercial'] ?? ''); ?>
          <select class="form-control" id="casa_comercial" name="casa_comercial" required>
            <option value="SI" <?= ($cc === 'SI') ? 'selected' : '' ?>>SI</option>
            <option value="NO" <?= ($cc === 'NO') ? 'selected' : '' ?>>NO</option>
          </select>
        </div>

        <div class="form-group">
          <label for="mesa_traccion">Mesa de Tracción</label>
          <?php $mt = (string)($cirugia['mesa_traccion'] ?? ''); ?>
          <select class="form-control" id="mesa_traccion" name="mesa_traccion" required>
            <option value="SI" <?= ($mt === 'SI') ? 'selected' : '' ?>>SI</option>
            <option value="NO" <?= ($mt === 'NO') ? 'selected' : '' ?>>NO</option>
          </select>
        </div>

        <div class="form-group">
          <label for="laboratorio">Laboratorio</label>
          <textarea class="form-control" id="laboratorio" name="laboratorio" rows="3"><?= h($cirugia['laboratorio'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
          <label for="arco_en_c">Arco en C</label>
          <?php $ac = (string)($cirugia['arco_en_c'] ?? ''); ?>
          <select class="form-control" id="arco_en_c" name="arco_en_c" required>
            <option value="SI" <?= ($ac === 'SI') ? 'selected' : '' ?>>SI</option>
            <option value="NO" <?= ($ac === 'NO') ? 'selected' : '' ?>>NO</option>
          </select>
        </div>

        <div class="form-group form-check">
          <input type="checkbox" class="form-check-input" id="es_protesis" name="es_protesis" value="1"
            <?= ((int)($cirugia['es_protesis'] ?? 0) === 1) ? 'checked' : '' ?>>
          <label class="form-check-label" for="es_protesis">Es prótesis</label>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary">Actualizar Cirugía</button>
          <a href="<?= h($urlVolver) ?>" class="btn btn-secondary">Cancelar</a>
        </div>

      </form>

    </div>
  </div>
</div>

<script>
function actualizarDia() {
  const fecha = document.getElementById('fecha').value;
  if (!fecha) return;

  const dias = ['DOMINGO','LUNES','MARTES','MIÉRCOLES','JUEVES','VIERNES','SÁBADO'];
  const [y,m,d] = fecha.split('-');
  const dt = new Date(parseInt(y,10), parseInt(m,10)-1, parseInt(d,10));
  document.getElementById('dia').value = dias[dt.getDay()];
}
</script>

</body>
</html>
<?php
} catch (Throwable $e) {
  echo "<div class='alert alert-danger m-3'>Error: " . h($e->getMessage()) . "</div>";
}