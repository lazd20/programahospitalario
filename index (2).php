<?php
// /public_html/evoprx/programacion/programar.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();
$rol = (string)($u['rol'] ?? 'viewer');

if ($rol === 'viewer') {
  echo "<script>alert('No tienes permiso para acceder a esta página.'); window.location.href='index.php';</script>";
  exit;
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
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
  <style>
    .hint { font-size: .85rem; color: #6c757d; }
  </style>
</head>

<body>
<div class="container mt-5">
  <h2 class="text-center mb-4">Programación de Quirófano</h2>

  <div class="alert alert-info">
    <b>Tip:</b> Puedes programar rápido con <b>Paciente</b> = “POR ENVIAR”.
    Si tienes los datos, llena <b>Nombre/Apellidos</b> y el campo Paciente se autocompleta.
  </div>

  <!-- Selector de ingresos -->
  <button type="button" class="btn btn-warning mb-3" data-toggle="modal" data-target="#modalIngresados">
    🧾 Elegir paciente ingresado
  </button>

  <span class="text-muted ml-2" id="ingresoBadge" style="display:none;">
    Vinculado a ingreso #<span id="ingresoBadgeId"></span>
    <button type="button" class="btn btn-sm btn-link" id="btnClearIngreso">Quitar</button>
  </span>

  <form action="guardar_programacion.php" method="POST" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="ingreso_id" id="ingreso_id" value="">

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

    <div class="form-row">
      <div class="form-group col-md-6">
        <label for="h_ingreso">Hora de Ingreso:</label>
        <input type="time" id="h_ingreso" name="h_ingreso" class="form-control" required>
      </div>
      <div class="form-group col-md-6">
        <label for="h_cirugia">Hora de Cirugía:</label>
        <input type="time" id="h_cirugia" name="h_cirugia" class="form-control">
      </div>
    </div>

    <div class="form-group">
      <label for="quirofano">Quirófano:</label>
      <select id="quirofano" name="quirofano" class="form-control" required>
        <option value="">-- Selecciona el quirófano --</option>
        <option value="Q1">Quirófano 1</option>
        <option value="Q2">Quirófano 2</option>
      </select>
    </div>

    <div class="card mb-3">
      <div class="card-header"><b>Datos del paciente (opcionales)</b></div>
      <div class="card-body">

        <div class="form-row">
          <div class="form-group col-md-3">
            <label for="nombre1">Nombre 1</label>
            <input type="text" id="nombre1" name="nombre1" class="form-control" maxlength="60">
          </div>
          <div class="form-group col-md-3">
            <label for="nombre2">Nombre 2</label>
            <input type="text" id="nombre2" name="nombre2" class="form-control" maxlength="60">
          </div>
          <div class="form-group col-md-3">
            <label for="apellido1">Apellido 1</label>
            <input type="text" id="apellido1" name="apellido1" class="form-control" maxlength="60">
          </div>
          <div class="form-group col-md-3">
            <label for="apellido2">Apellido 2</label>
            <input type="text" id="apellido2" name="apellido2" class="form-control" maxlength="60">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-4">
            <label for="cedula">Cédula (opcional)</label>
            <input type="text" id="cedula" name="cedula" class="form-control" maxlength="20">
            <div class="hint">Si no la tienes, no pasa nada.</div>
          </div>

          <div class="form-group col-md-4">
            <label for="edad">Edad:</label>
            <input type="number" id="edad" name="edad" class="form-control">
          </div>

          <div class="form-group col-md-4">
            <label for="paciente">Paciente (texto libre):</label>
            <input type="text" id="paciente" name="paciente" class="form-control" required placeholder="Ej: POR ENVIAR / Nombre Apellido">
            <div class="hint">Se autocompleta si llenas Nombre/Apellidos (excepto POR ENVIAR).</div>
          </div>
        </div>

      </div>
    </div>

    <div class="form-group">
      <label for="procedimiento">Procedimiento:</label>
      <input type="text" id="procedimiento" name="procedimiento" class="form-control" required>
    </div>

    <div class="form-group">
      <label for="tipo_cirugia">Tipo de Cirugía:</label>
      <select id="tipo_cirugia" name="tipo_cirugia" class="form-control" required>
        <option value="">-- Selecciona el tipo de cirugía --</option>
        <?php foreach ($tiposCirugia as $tipo): ?>
          <option value="<?= (int)$tipo['id'] ?>"><?= e($tipo['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label for="busqueda_cirujano">Buscar Cirujano (seleccione o escriba uno nuevo):</label>
      <input type="text" id="busqueda_cirujano" class="form-control" placeholder="Escriba el nombre del cirujano">
      <div id="sugerencias_cirujano" class="list-group mt-1"></div>

      <input type="hidden" id="cirujano" name="cirujano">
      <small class="form-text text-muted mt-2">Si no aparece, puede escribirlo abajo para registrarlo como nuevo:</small>
      <input type="text" id="nuevo_cirujano" name="nuevo_cirujano" placeholder="Nuevo cirujano" class="form-control mt-1">
    </div>

    <div class="form-group">
      <label for="anestesiologo">Anestesiólogo:</label>
      <input type="text" id="anestesiologo" name="anestesiologo" class="form-control" required>
    </div>

    <div class="form-group">
      <label for="laboratorio">Laboratorio/Notas:</label>
      <textarea id="laboratorio" name="laboratorio" class="form-control"></textarea>
    </div>

    <div class="form-row">
      <div class="form-group col-md-3">
        <label for="arco_en_c">Arco en C:</label>
        <select id="arco_en_c" name="arco_en_c" class="form-control">
          <option value="SI">SI</option>
          <option value="NO">NO</option>
        </select>
      </div>
      <div class="form-group col-md-3">
        <label for="habitacion">Habitación:</label>
        <select id="habitacion" name="habitacion" class="form-control">
          <option value="Ambulatorio">Ambulatorio</option>
          <option value="Internado">Internado</option>
          <option value="InternadoP">Internado PREMIUM</option>
        </select>
      </div>
      <div class="form-group col-md-3">
        <label for="casa_comercial">Casa Comercial:</label>
        <select id="casa_comercial" name="casa_comercial" class="form-control">
          <option value="SI">SI</option>
          <option value="NO">NO</option>
        </select>
      </div>
      <div class="form-group col-md-3">
        <label for="mesa_traccion">Mesa de Tracción:</label>
        <select id="mesa_traccion" name="mesa_traccion" class="form-control">
          <option value="SI">SI</option>
          <option value="NO">NO</option>
        </select>
      </div>
    </div>

    <div class="form-group form-check">
      <input type="checkbox" class="form-check-input" id="protesis" name="protesis" value="1">
      <label class="form-check-label" for="protesis">¿Es cirugía de prótesis?</label>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Guardar Programación</button>
  </form>
</div>

<!-- Modal Ingresados -->
<div class="modal fade" id="modalIngresados" tabindex="-1" role="dialog" aria-labelledby="modalIngresadosLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalIngresadosLabel">Seleccionar paciente ingresado</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <div class="form-group">
          <input type="text" id="buscIngreso" class="form-control" placeholder="Buscar por cédula, nombre o #id...">
          <small class="text-muted">Ej: 0912345678 / Pérez / 123</small>
        </div>

        <div class="table-responsive" style="max-height:420px; overflow:auto;">
          <table class="table table-sm table-hover">
            <thead class="thead-light">
              <tr>
                <th>ID</th>
                <th>Paciente</th>
                <th>Cédula</th>
                <th>Entrada</th>
                <th>Hab.</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="tbodyIngresados">
              <tr><td colspan="6" class="text-muted">Cargando...</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
function actualizarDia() {
  const fecha = document.getElementById('fecha').value;
  if (fecha) {
    const diasSemana = ['DOMINGO', 'LUNES', 'MARTES', 'MIÉRCOLES', 'JUEVES', 'VIERNES', 'SÁBADO'];
    const parts = fecha.split('-');
    const dateObj = new Date(parts[0], parts[1] - 1, parts[2]);
    document.getElementById('dia').value = diasSemana[dateObj.getDay()];
  }
}

// Autocompletar "paciente" si se llenan nombres/apellidos (excepto POR ENVIAR)
function buildFullName(){
  const n1 = ($('#nombre1').val() || '').trim();
  const n2 = ($('#nombre2').val() || '').trim();
  const a1 = ($('#apellido1').val() || '').trim();
  const a2 = ($('#apellido2').val() || '').trim();
  return [n1,n2,a1,a2].filter(Boolean).join(' ').replace(/\s+/g,' ').trim();
}
function shouldAutoFillPaciente(){
  const p = ($('#paciente').val() || '').trim().toUpperCase();
  if (p === 'POR ENVIAR') return false;
  return true;
}
function autoFillPaciente(){
  const full = buildFullName();
  if (!full) return;
  if (!shouldAutoFillPaciente()) return;
  $('#paciente').val(full);
}

function esc(s){
  return (s || '').toString()
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

let ingresoTimer = null;

function renderIngresados(rows){
  const $tb = $('#tbodyIngresados');
  if (!rows || !rows.length){
    $tb.html('<tr><td colspan="6" class="text-muted">No hay pacientes ingresados.</td></tr>');
    return;
  }
  let html = '';
  for (let i=0;i<rows.length;i++){
    const r = rows[i];
    const hab = r.habitacion_numero ? ('Hab ' + esc(r.habitacion_numero) + ' ' + esc(r.habitacion_desc||'')) : '';
    html += `
      <tr>
        <td>${esc(r.id)}</td>
        <td>${esc(r.full_name || r.paciente || '')}</td>
        <td>${esc(r.cedula||'')}</td>
        <td>${esc(r.fecha_entrada||'')}</td>
        <td>${hab}</td>
        <td class="text-right">
          <button type="button" class="btn btn-sm btn-primary btnPickIngreso"
            data-json="${esc(JSON.stringify(r))}">
            Usar
          </button>
        </td>
      </tr>
    `;
  }
  $tb.html(html);
}

function loadIngresados(q){
  $('#tbodyIngresados').html('<tr><td colspan="6" class="text-muted">Cargando...</td></tr>');
  $.getJSON('ajax_ingresados.php', { q: q || '', limit: 120 })
    .done(function(resp){
      if (!resp || !resp.ok) {
        $('#tbodyIngresados').html('<tr><td colspan="6" class="text-danger">Error: ' + esc((resp && resp.msg) ? resp.msg : 'no ok') + '</td></tr>');
        return;
      }
      renderIngresados(resp.data || []);
    })
    .fail(function(xhr){
      $('#tbodyIngresados').html('<tr><td colspan="6" class="text-danger">Error de red/servidor (' + xhr.status + ').</td></tr>');
    });
}

$(document).ready(function() {

  // autocompleta paciente
  $('#nombre1,#nombre2,#apellido1,#apellido2').on('keyup change', function(){
    autoFillPaciente();
  });

  // cirujano search
  $('#busqueda_cirujano').on('keyup', function() {
    const query = $(this).val();
    if (query.length >= 2) {
      $.ajax({
        url: 'buscar_cirujano.php',
        method: 'GET',
        data: { q: query },
        success: function(data) {
          $('#sugerencias_cirujano').html(data).fadeIn();
        }
      });
    } else {
      $('#sugerencias_cirujano').fadeOut();
    }
  });

  $(document).on('click', '.item-cirujano', function() {
    const nombre = $(this).text();
    const id = $(this).data('id');
    $('#busqueda_cirujano').val(nombre);
    $('#cirujano').val(id);
    $('#sugerencias_cirujano').fadeOut();
  });

  // modal open -> cargar
  $('#modalIngresados').on('shown.bs.modal', function(){
    $('#buscIngreso').val('');
    loadIngresados('');
    setTimeout(function(){ $('#buscIngreso').focus(); }, 150);
  });

  // buscar (debounce)
  $('#buscIngreso').on('keyup', function(){
    const q = $(this).val();
    clearTimeout(ingresoTimer);
    ingresoTimer = setTimeout(function(){ loadIngresados(q); }, 250);
  });

  // click usar
  $(document).on('click', '.btnPickIngreso', function(){
    const raw = $(this).attr('data-json') || '{}';
    let r = {};
    try { r = JSON.parse(raw); } catch(e){ r = {}; }

    $('#ingreso_id').val(r.id || '');
    $('#ingresoBadgeId').text(r.id || '');
    $('#ingresoBadge').show();

    // llenar campos
    $('#nombre1').val(r.nombre1 || '');
    $('#nombre2').val(r.nombre2 || '');
    $('#apellido1').val(r.apellido1 || '');
    $('#apellido2').val(r.apellido2 || '');
    $('#cedula').val(r.cedula || '');
    if (r.edad !== null && r.edad !== undefined) $('#edad').val(r.edad);

    // paciente: si viene full_name úsalo, si no usa paciente
    const fn = (r.full_name || r.paciente || '').trim();
    if (fn) $('#paciente').val(fn);

    // sugerir habitacion
    if (r.habitacion_id && parseInt(r.habitacion_id,10) > 0) {
      $('#habitacion').val('Internado');
    }

    $('#modalIngresados').modal('hide');
  });

  // quitar vínculo
  $('#btnClearIngreso').on('click', function(){
    $('#ingreso_id').val('');
    $('#ingresoBadge').hide();
    $('#ingresoBadgeId').text('');
  });

});
</script>
</body>
</html>