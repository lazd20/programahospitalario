<?php
// /public_html/evoprx/programacion/multiples.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();
$rol = (string)($u['rol'] ?? 'viewer');

// Permiso: no viewer (ajusta si quieres solo admin/editor)
if ($rol === 'viewer') {
  echo "<script>alert('No tienes permiso para acceder a esta página.'); window.location.href='index.php';</script>";
  exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
  // Cirujanos
  $stmtCirujanos = $pdo->query("SELECT id, nombre FROM hosp_cirujanos ORDER BY nombre ASC");
  $cirujanos = $stmtCirujanos->fetchAll(PDO::FETCH_ASSOC);

  // Tipos de cirugía
  $stmtTiposCirugia = $pdo->query("SELECT id, nombre FROM hosp_tipos_cirugia ORDER BY nombre ASC");
  $tiposCirugia = $stmtTiposCirugia->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  echo "<div class='alert alert-danger m-3'>Error: " . e($e->getMessage()) . "</div>";
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Programación de Quirófanos</title>
  <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">

  <script>
    function actualizarDia() {
      const fecha = document.getElementById('fecha').value;
      if (fecha) {
        const diasSemana = ['DOMINGO', 'LUNES', 'MARTES', 'MIÉRCOLES', 'JUEVES', 'VIERNES', 'SÁBADO'];
        const [anio, mes, dia] = fecha.split('-');
        const dateObj = new Date(anio, mes - 1, dia);
        const diaSemana = diasSemana[dateObj.getDay()];
        document.getElementById('dia').value = diaSemana;
      }
    }
  </script>
</head>

<body>
<div class="container mt-5">
  <h2 class="text-center mb-4">Programación de Quirófano (Múltiples)</h2>

  <form action="guardar_programacionm.php" method="POST">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="form-row">
      <div class="form-group col-md-6">
        <label for="fecha">Fecha:</label>
        <input type="date" id="fecha" name="fecha" class="form-control" onchange="actualizarDia()" required>
      </div>
      <div class="form-group col-md-6">
        <label for="dia">Día:</label>
        <input type="text" id="dia" name="dia" class="form-control" readonly>
      </div>
    </div>

    <div class="form-group">
      <label for="cirujano">Cirujano:</label>
      <select id="cirujano" name="cirujano" class="form-control">
        <option value="">-- Selecciona un cirujano existente --</option>
        <?php foreach ($cirujanos as $c): ?>
          <option value="<?= (int)$c['id']; ?>"><?= e($c['nombre']); ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" id="nuevo_cirujano" name="nuevo_cirujano" placeholder="Nuevo cirujano" class="form-control mt-2">
    </div>

    <div class="form-group">
      <label for="anestesiologo">Anestesiólogo:</label>
      <input type="text" id="anestesiologo" name="anestesiologo" class="form-control" required>
    </div>

    <hr>
    <h4>Cirugías</h4>
    <div id="cirugias-container"></div>
    <button type="button" class="btn btn-success mb-4" onclick="agregarCirugia()">Agregar otra cirugía</button>

    <button type="submit" class="btn btn-primary btn-block">Guardar Programación</button>
  </form>
</div>

<script>
let index = 0;

function agregarCirugia(data = null) {
  const container = document.getElementById('cirugias-container');

  const h_ingreso     = data?.h_ingreso || '';
  const h_cirugia     = data?.h_cirugia || '';
  const paciente      = data?.paciente || '';
  const edad          = data?.edad || '';
  const procedimiento = data?.procedimiento || '';
  const tipo_cirugia  = data?.tipo_cirugia || '';
  const quirofano     = data?.quirofano || '';
  const habitacion    = data?.habitacion || '';
  const arco_en_c     = data?.arco_en_c || '';
  const mesa_traccion = data?.mesa_traccion || '';
  const casa_comercial= data?.casa_comercial || '';
  const laboratorio   = data?.laboratorio || '';
  const protesis      = data?.protesis ? 'checked' : '';

  const template = `
  <fieldset class="border p-3 mb-4" data-index="${index}">
    <legend>Cirugía #${index + 1}</legend>

    <div class="form-row">
      <div class="form-group col-md-6">
        <label>Hora de Ingreso:</label>
        <input type="time" name="h_ingreso[]" class="form-control" value="${h_ingreso}" required>
      </div>
      <div class="form-group col-md-6">
        <label>Hora de Cirugía:</label>
        <input type="time" name="h_cirugia[]" class="form-control" value="${h_cirugia}">
      </div>
    </div>

    <div class="form-group">
      <label>Paciente:</label>
      <input type="text" name="paciente[]" class="form-control" value="${paciente}" required>
    </div>

    <div class="form-group">
      <label>Edad:</label>
      <input type="number" name="edad[]" class="form-control" value="${edad}">
    </div>

    <div class="form-group">
      <label>Procedimiento:</label>
      <input type="text" name="procedimiento[]" class="form-control" value="${procedimiento}" required>
    </div>

    <div class="form-group">
      <label>Tipo de Cirugía:</label>
      <select name="tipo_cirugia[]" class="form-control" required>
        <option value="">-- Selecciona el tipo de cirugía --</option>
        <?php foreach ($tiposCirugia as $tipo): ?>
          <option value="<?= (int)$tipo['id']; ?>"><?= e($tipo['nombre']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-row">
      <div class="form-group col-md-6">
        <label>Quirófano:</label>
        <select name="quirofano[]" class="form-control" required>
          <option value="">-- Selecciona el quirófano --</option>
          <option value="Q1" ${quirofano === 'Q1' ? 'selected' : ''}>Quirófano 1</option>
          <option value="Q2" ${quirofano === 'Q2' ? 'selected' : ''}>Quirófano 2</option>
        </select>
      </div>

      <div class="form-group col-md-6">
        <label>Habitación:</label>
        <select name="habitacion[]" class="form-control">
          <option value="Ambulatorio" ${habitacion === 'Ambulatorio' ? 'selected' : ''}>Ambulatorio</option>
          <option value="Internado" ${habitacion === 'Internado' ? 'selected' : ''}>Internado</option>
          <option value="InternadoP" ${habitacion === 'InternadoP' ? 'selected' : ''}>Internado PREMIUM</option>
        </select>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group col-md-4">
        <label>Arco en C:</label>
        <select name="arco_en_c[]" class="form-control">
          <option value="SI" ${arco_en_c === 'SI' ? 'selected' : ''}>SI</option>
          <option value="NO" ${arco_en_c === 'NO' ? 'selected' : ''}>NO</option>
        </select>
      </div>

      <div class="form-group col-md-4">
        <label>Mesa de Tracción:</label>
        <select name="mesa_traccion[]" class="form-control">
          <option value="SI" ${mesa_traccion === 'SI' ? 'selected' : ''}>SI</option>
          <option value="NO" ${mesa_traccion === 'NO' ? 'selected' : ''}>NO</option>
        </select>
      </div>

      <div class="form-group col-md-4">
        <label>Casa Comercial:</label>
        <select name="casa_comercial[]" class="form-control">
          <option value="SI" ${casa_comercial === 'SI' ? 'selected' : ''}>SI</option>
          <option value="NO" ${casa_comercial === 'NO' ? 'selected' : ''}>NO</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label>Laboratorio/Notas:</label>
      <textarea name="laboratorio[]" class="form-control">${laboratorio}</textarea>
    </div>

    <div class="form-group form-check">
      <input type="checkbox" class="form-check-input" name="protesis[${index}]" value="1" ${protesis}>
      <label class="form-check-label">¿Es cirugía de prótesis?</label>
    </div>

    <button type="button" class="btn btn-warning btn-sm" onclick="duplicarCirugia(this)">Duplicar esta cirugía</button>
  </fieldset>
  `;

  container.insertAdjacentHTML('beforeend', template);
  index++;
}

function duplicarCirugia(btn) {
  const fieldset = btn.closest('fieldset');
  const inputs = fieldset.querySelectorAll('input, select, textarea');

  const data = {};
  inputs.forEach(input => {
    const name = input.name.replace(/\[\]|\[\d+\]/, '');
    if (input.type === 'checkbox') {
      data[name] = input.checked;
    } else {
      data[name] = input.value;
    }
  });

  agregarCirugia(data);
}

document.addEventListener('DOMContentLoaded', () => {
  agregarCirugia();
});
</script>

</body>
</html>