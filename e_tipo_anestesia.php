<?php
// /public_html/evoprx/programacion/reagendar_cirugia.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();
$rol = (string)($u['rol'] ?? 'viewer');

// Permisos
if (!in_array($rol, ['admin', 'editor'], true)) {
  echo "<script>alert('No tienes permiso para realizar esta acción.'); window.location.href='ver_programacion.php';</script>";
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  echo "<div class='alert alert-danger m-3'>No se proporcionó un ID válido.</div>";
  exit;
}

try {
  // 1) Cirugía eliminada
  $stmt = $pdo->prepare("SELECT * FROM hosp_registro_cirugias_eliminadas WHERE id = ? LIMIT 1");
  $stmt->execute([$id]);
  $cirugia = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$cirugia) {
    echo "<div class='alert alert-danger m-3'>Cirugía no encontrada.</div>";
    exit;
  }

  // 2) Cirujanos
  $stmtCirujanos = $pdo->query("SELECT id, nombre, especialidad FROM hosp_cirujanos ORDER BY nombre ASC");
  $cirujanos = $stmtCirujanos->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  echo "<div class='alert alert-danger m-3'>Error: " . h($e->getMessage()) . "</div>";
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reagendar Cirugía</title>
  <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
  <h2 class="text-center mb-4">Reagendar Cirugía Eliminada</h2>

  <form action="guardar_reagendada.php" method="POST" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id_eliminada" value="<?= (int)$cirugia['id'] ?>">

    <div class="form-group">
      <label for="fecha">Fecha</label>
      <input type="date" class="form-control" id="fecha" name="fecha" value="<?= h($cirugia['fecha']) ?>" required>
    </div>

    <div class="form-group">
      <label for="dia">Día</label>
      <input type="text" class="form-control" id="dia" name="dia" value="<?= h($cirugia['dia']) ?>" readonly>
    </div>

    <div class="form-group">
      <label for="paciente">Paciente</label>
      <input type="text" class="form-control" id="paciente" name="paciente" value="<?= h($cirugia['paciente']) ?>" required>
    </div>

    <div class="form-group">
      <label for="edad">Edad</label>
      <input type="number" class="form-control" id="edad" name="edad" value="<?= h($cirugia['edad']) ?>">
    </div>

    <div class="form-group">
      <label for="h_ingreso">Hora de Ingreso</label>
      <input type="time" class="form-control" id="h_ingreso" name="h_ingreso" value="<?= h($cirugia['h_ingreso']) ?>">
    </div>

    <div class="form-group">
      <label for="h_cirugia">Hora de Cirugía</label>
      <input type="time" class="form-control" id="h_cirugia" name="h_cirugia" value="<?= h($cirugia['h_cirugia']) ?>">
    </div>

    <div class="form-group">
      <label for="procedimiento">Procedimiento</label>
      <input type="text" class="form-control" id="procedimiento" name="procedimiento" value="<?= h($cirugia['procedimiento']) ?>">
    </div>

    <div class="form-group">
      <label>Quirófano</label><br>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="radio" name="quirofano" value="Q1" <?= ($cirugia['Q1'] === 'X') ? 'checked' : '' ?>>
        <label class="form-check-label">Q1</label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="radio" name="quirofano" value="Q2" <?= ($cirugia['Q2'] === 'X') ? 'checked' : '' ?>>
        <label class="form-check-label">Q2</label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="radio" name="quirofano" value="" <?= (empty($cirugia['Q1']) && empty($cirugia['Q2'])) ? 'checked' : '' ?>>
        <label class="form-check-label">Sin asignar</label>
      </div>
    </div>

    <div class="form-group">
      <label for="cirujano_id">Cirujano</label>
      <select class="form-control" id="cirujano_id" name="cirujano_id" required>
        <?php foreach ($cirujanos as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ((int)$cirugia['cirujano_id'] === (int)$c['id']) ? 'selected' : '' ?>>
            <?= h($c['nombre']) ?><?= !empty($c['especialidad']) ? ' (' . h($c['especialidad']) . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label for="anestesiologo">Anestesiólogo</label>
      <input type="text" class="form-control" id="anestesiologo" name="anestesiologo" value="<?= h($cirugia['anestesiologo']) ?>">
    </div>

    <div class="form-group">
      <label for="habitacion">Habitación</label>
      <input type="text" class="form-control" id="habitacion" name="habitacion" value="<?= h($cirugia['habitacion']) ?>">
    </div>

    <div class="form-group">
      <label for="casa_comercial">Casa Comercial</label>
      <input type="text" class="form-control" id="casa_comercial" name="casa_comercial" value="<?= h($cirugia['casa_comercial']) ?>">
    </div>

    <div class="form-group">
      <label for="mesa_traccion">Mesa de Tracción</label>
      <input type="text" class="form-control" id="mesa_traccion" name="mesa_traccion" value="<?= h($cirugia['mesa_traccion']) ?>">
    </div>

    <div class="form-group">
      <label for="laboratorio">Laboratorio</label>
      <textarea class="form-control" id="laboratorio" name="laboratorio"><?= h($cirugia['laboratorio']) ?></textarea>
    </div>

    <div class="form-group">
      <label for="arco_en_c">Arco en C</label>
      <input type="text" class="form-control" id="arco_en_c" name="arco_en_c" value="<?= h($cirugia['arco_en_c']) ?>">
    </div>

    <div class="form-group form-check">
      <input type="checkbox" class="form-check-input" id="es_protesis" name="es_protesis" value="1" <?= ((int)$cirugia['es_protesis'] === 1) ? 'checked' : '' ?>>
      <label class="form-check-label" for="es_protesis">¿Es Prótesis?</label>
    </div>

    <button type="submit" class="btn btn-primary">Guardar y Reagendar</button>
    <a href="cirugias_eliminadas.php" class="btn btn-secondary">Cancelar</a>
  </form>
</div>
</body>
</html>