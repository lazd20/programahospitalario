<?php
// /public_html/evoprx/programacion/detalle.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();

$diasSemana = [
  'Monday'    => 'Lunes',
  'Tuesday'   => 'Martes',
  'Wednesday' => 'Miércoles',
  'Thursday'  => 'Jueves',
  'Friday'    => 'Viernes',
  'Saturday'  => 'Sábado',
  'Sunday'    => 'Domingo'
];

$db_error = null;
$cirugiasPorDoctor = [];
$fechaObjetivo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ver_detalles_dia'])) {
  $token = (string)($_POST['_csrf'] ?? '');
  if (!csrf_check($token)) {
    $db_error = 'Sesión inválida. Recarga la página e intenta de nuevo.';
  } else {
    $fechaObjetivo = (string)($_POST['fecha_detalle'] ?? date('Y-m-d'));

    // Validación simple formato fecha
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaObjetivo)) {
      $fechaObjetivo = date('Y-m-d');
    }

    try {
      // Intento A: ambas tablas con prefijo
      $sqlA = "
        SELECT pq.*, c.nombre AS nombre_cirujano
        FROM hosp_programacion_quirofano pq
        LEFT JOIN hosp_cirujanos c ON pq.cirujano_id = c.id
        WHERE pq.fecha = :fecha
        ORDER BY c.nombre, pq.h_cirugia
      ";
      $stmt = $pdo->prepare($sqlA);
      $stmt->execute([':fecha' => $fechaObjetivo]);

      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $doctor = trim((string)($row['nombre_cirujano'] ?? '')) ?: 'Sin asignar';
        $cirugiasPorDoctor[$doctor][] = $row;
      }

    } catch (Throwable $eA) {
      try {
        // Intento B: programación con prefijo + cirujanos sin prefijo (por si aún existe así)
        $sqlB = "
          SELECT pq.*, c.nombre AS nombre_cirujano
          FROM hosp_programacion_quirofano pq
          LEFT JOIN cirujanos c ON pq.cirujano_id = c.id
          WHERE pq.fecha = :fecha
          ORDER BY c.nombre, pq.h_cirugia
        ";
        $stmt = $pdo->prepare($sqlB);
        $stmt->execute([':fecha' => $fechaObjetivo]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
          $doctor = trim((string)($row['nombre_cirujano'] ?? '')) ?: 'Sin asignar';
          $cirugiasPorDoctor[$doctor][] = $row;
        }

      } catch (Throwable $eB) {
        $db_error = 'Error al cargar los detalles. Verifica las tablas hosp_programacion_quirofano y hosp_cirujanos.';
      }
    }
  }
}

// Default date para el form: mañana
$defaultDate = date('Y-m-d', strtotime('+1 day'));
if ($fechaObjetivo !== '') $defaultDate = $fechaObjetivo;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Detalles del Día</title>
  <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container my-5">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="m-0">Detalles del Día</h3>
    <a class="btn btn-outline-secondary" href="ver_programacion.php">← Volver</a>
  </div>

  <?php if (!empty($db_error)): ?>
    <div class="alert alert-danger"><?= e($db_error) ?></div>
  <?php endif; ?>

  <?php if (!empty($cirugiasPorDoctor) && $fechaObjetivo): ?>
    <?php
      $diaEng = date('l', strtotime($fechaObjetivo));
      $dia = $diasSemana[$diaEng] ?? $diaEng;
    ?>
    <div class='alert alert-secondary'>
      <h4 class='mb-0'>🗓️ Detalles del Día: <?= e(date('d-m-Y', strtotime($fechaObjetivo))) ?> - <?= e($dia) ?></h4>
    </div>

    <?php
      $contador = 0;
      foreach ($cirugiasPorDoctor as $doctor => $cirugias):
    ?>
      <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white">Dr. <?= e($doctor) ?></div>
        <div class="card-body">

          <?php foreach ($cirugias as $cirugia): ?>
            <?php
              $contador++;
              $idTexto = "detalle_" . $contador;

              $horaCirugia = !empty($cirugia['h_cirugia']) ? date('H:i', strtotime($cirugia['h_cirugia'])) : 'Hora no asignada';
              $quirofano = (($cirugia['Q1'] ?? '') === 'X') ? 'Q1' : ((($cirugia['Q2'] ?? '') === 'X') ? 'Q2' : 'Sin asignar');

              $laboratorioPlano = (string)($cirugia['laboratorio'] ?? '');
              $laboratorioPlano = trim($laboratorioPlano);
              if ($laboratorioPlano === '') $laboratorioPlano = '—';

              $mensaje = "👨‍⚕️ Saludos Dr. {$doctor}:
Le recordamos que el día " . date('d-m-Y', strtotime($fechaObjetivo)) . " tiene una cirugía programada a las {$horaCirugia}.

🩺 Procedimiento: " . (string)($cirugia['procedimiento'] ?? '') . "
👤 Paciente: " . (string)($cirugia['paciente'] ?? '') . "
🏥 Habitación: " . (string)($cirugia['habitacion'] ?? '') . "
📍 Quirófano: {$quirofano}
🧪 Laboratorio: {$laboratorioPlano}
💉 Anestesiólogo: " . (string)($cirugia['anestesiologo'] ?? '') . "
🛏️ Mesa de Tracción: " . (string)($cirugia['mesa_traccion'] ?? '') . "
📸 Arco en C: " . (string)($cirugia['arco_en_c'] ?? '');
            ?>

            <div class="form-group">
              <label><strong>Cirugía:</strong></label>
              <textarea id="<?= e($idTexto) ?>" class="form-control mb-2" rows="9"><?= e($mensaje) ?></textarea>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="copiarTexto('<?= e($idTexto) ?>')">📋 Copiar</button>
            </div>

            <hr>
          <?php endforeach; ?>

        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Formulario -->
  <form method="POST" class="mb-5">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="form-row align-items-end">
      <div class="col-md-4">
        <label for="fecha_detalle"><strong>Selecciona la fecha:</strong></label>
        <input type="date" id="fecha_detalle" name="fecha_detalle" class="form-control" value="<?= e($defaultDate) ?>">
      </div>
      <div class="col-md-3">
        <button type="submit" name="ver_detalles_dia" class="btn btn-info btn-block">Ver Detalles</button>
      </div>
    </div>
  </form>

</div>

<script>
function copiarTexto(id) {
  const textarea = document.getElementById(id);
  textarea.focus();
  textarea.select();
  textarea.setSelectionRange(0, 99999);
  document.execCommand("copy");
  alert("Texto copiado al portapapeles");
}
</script>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>