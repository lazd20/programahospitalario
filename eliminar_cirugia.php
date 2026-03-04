<?php
// /public_html/evoprx/programacion/programar_desde_ingreso.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('America/Guayaquil');

$u = function_exists('current_user') ? current_user() : [];
$rol = (string)($u['rol'] ?? ($_SESSION['role'] ?? ''));

if (!in_array($rol, ['admin', 'editor'], true)) {
  echo "<script>alert('No tienes permiso.'); window.location.href='" . base_url('/residentes/panel_ingresos.php') . "';</script>";
  exit;
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

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

function clean($v): string {
  $s = trim((string)$v);
  $s = preg_replace('/\s+/', ' ', $s);
  return trim($s);
}

function buildPaciente(string $n1, string $n2, string $a1, string $a2, string $fallback = 'POR ENVIAR'): string {
  $parts = array_filter([clean($n1), clean($n2), clean($a1), clean($a2)], function($x){
    return $x !== '';
});
  $full = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
  return $full !== '' ? $full : clean($fallback);
}

// ----------- ID ingreso -----------
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) die("ID de ingreso inválido.");

// ----------- PDO central -----------
if (!isset($pdo) || !($pdo instanceof PDO)) {
  if (function_exists('getPDO')) {
    $pdo = getPDO();
  } else {
    die("No hay conexión PDO disponible. Revisa helpers.php (debe definir \$pdo o getPDO()).");
  }
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try { $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $e) { /* ignore */ }

// ----------- Tablas -----------
$T_ING   = 'hosp_ingresos';
$T_PROG  = 'hosp_programacion_quirofano';
$T_CIR   = 'hosp_cirujanos';
$T_LINK  = 'hosp_ingreso_programaciones';

// Detectar columnas “legacy”
$tiene_prog_en_ing = hasColumn($pdo, $T_ING, 'programacion_id');
$tiene_ing_en_prog = hasColumn($pdo, $T_PROG, 'ingreso_id');

// ----------- Traer ingreso -----------
$st = $pdo->prepare("SELECT * FROM {$T_ING} WHERE id = ? LIMIT 1");
$st->execute([$id]);
$ing = $st->fetch(PDO::FETCH_ASSOC);
if (!$ing) die("Ingreso no encontrado.");

// ----------- Traer programaciones ya existentes (tabla puente) -----------
$programacionesExistentes = [];
try {
  $sql = "
    SELECT p.id, p.fecha, p.dia, p.h_ingreso, p.h_cirugia, p.procedimiento, p.paciente
    FROM {$T_LINK} ip
    JOIN {$T_PROG} p ON p.id = ip.programacion_id
    WHERE ip.ingreso_id = ?
    ORDER BY p.fecha DESC, p.h_ingreso DESC, p.id DESC
  ";
  $q = $pdo->prepare($sql);
  $q->execute([$id]);
  $programacionesExistentes = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $programacionesExistentes = [];
}

// ----------- Cirujanos -----------
$cirujanos = $pdo->query("SELECT id, nombre, especialidad FROM {$T_CIR} ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// ----------- Defaults desde ingreso -----------
$fecha = !empty($ing['fecha_entrada']) ? (string)$ing['fecha_entrada'] : date('Y-m-d');

// día auto
$dias = ['DOMINGO','LUNES','MARTES','MIÉRCOLES','JUEVES','VIERNES','SÁBADO'];
try {
  $dt = new DateTime($fecha);
  $dia = $dias[(int)$dt->format('w')];
} catch (Throwable $e) {
  $dia = '';
}

$h_ingreso = '07:00';
$h_cirugia = '';

$nombre1   = clean($ing['nombre1'] ?? '');
$nombre2   = clean($ing['nombre2'] ?? '');
$apellido1 = clean($ing['apellido1'] ?? '');
$apellido2 = clean($ing['apellido2'] ?? '');
$cedula    = clean($ing['cedula'] ?? '');

$paciente  = buildPaciente($nombre1, $nombre2, $apellido1, $apellido2, ($ing['paciente'] ?? 'POR ENVIAR'));
$edad      = $ing['edad'] ?? '';

$procedimiento = '';
$quirofano = '';

$cirujano_id   = (int)($ing['cirujano_id'] ?? 0);
$anestesiologo = 'POR DEFINIR';

// En programación es texto: Ambulatorio / Internado / InternadoP
$habitacion = !empty($ing['habitacion_id']) ? 'Internado' : 'Ambulatorio';

// Extras
$casa_comercial = 'NO';
$mesa_traccion  = 'NO';
$laboratorio    = '';
$arco_en_c      = 'NO';
$es_protesis    = 0;

$errorMsg = '';

/* ==========================
   POST -> crear programación
   ========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $fecha         = clean($_POST['fecha'] ?? '');
  $dia           = clean($_POST['dia'] ?? '');
  $paciente      = clean($_POST['paciente'] ?? '');
  $cedula        = clean($_POST['cedula'] ?? '');

  $nombre1       = clean($_POST['nombre1'] ?? '');
  $nombre2       = clean($_POST['nombre2'] ?? '');
  $apellido1     = clean($_POST['apellido1'] ?? '');
  $apellido2     = clean($_POST['apellido2'] ?? '');

  $edad          = $_POST['edad'] ?? '';
  $edad          = ($edad === '' ? null : (int)$edad);

  $h_ingreso     = clean($_POST['h_ingreso'] ?? '');
  $h_cirugia     = clean($_POST['h_cirugia'] ?? '');
  $h_cirugia     = ($h_cirugia === '' ? null : $h_cirugia);

  $procedimiento = clean($_POST['procedimiento'] ?? '');
  $quirofano     = clean($_POST['quirofano'] ?? '');
  $cirujano_id   = (int)($_POST['cirujano_id'] ?? 0);
  $anestesiologo = clean($_POST['anestesiologo'] ?? '');

  $habitacion    = clean($_POST['habitacion'] ?? 'Ambulatorio');

  $casa_comercial = clean($_POST['casa_comercial'] ?? 'NO');
  $mesa_traccion  = clean($_POST['mesa_traccion'] ?? 'NO');
  $laboratorio    = (string)($_POST['laboratorio'] ?? '');
  $arco_en_c      = clean($_POST['arco_en_c'] ?? 'NO');
  $es_protesis    = isset($_POST['es_protesis']) ? 1 : 0;

  if ($paciente === '') {
    $paciente = buildPaciente($nombre1, $nombre2, $apellido1, $apellido2, 'POR ENVIAR');
  }

  if ($fecha === '' || $dia === '' || $h_ingreso === '' || $procedimiento === '' || $cirujano_id <= 0 || $anestesiologo === '') {
    $errorMsg = "Faltan datos obligatorios (Fecha, Día, Hora ingreso, Procedimiento, Cirujano, Anestesiólogo).";
  }

  $Q1 = ($quirofano === 'Q1') ? 'X' : '';
  $Q2 = ($quirofano === 'Q2') ? 'X' : '';

  if ($errorMsg === '') {
    try {
      $pdo->beginTransaction();

      // Insert en programación (dinámico)
      $cols = [
        "dia","fecha","paciente","cedula",
        "nombre1","nombre2","apellido1","apellido2",
        "edad","h_ingreso","h_cirugia",
        "procedimiento","Q1","Q2",
        "cirujano_id","anestesiologo",
        "habitacion","casa_comercial","mesa_traccion",
        "laboratorio","arco_en_c","es_protesis"
      ];

      $map = [
        "dia" => $dia,
        "fecha" => $fecha,
        "paciente" => $paciente,
        "cedula" => ($cedula === '' ? null : $cedula),

        "nombre1" => ($nombre1 === '' ? null : $nombre1),
        "nombre2" => ($nombre2 === '' ? null : $nombre2),
        "apellido1" => ($apellido1 === '' ? null : $apellido1),
        "apellido2" => ($apellido2 === '' ? null : $apellido2),

        "edad" => $edad,
        "h_ingreso" => $h_ingreso,
        "h_cirugia" => $h_cirugia,

        "procedimiento" => $procedimiento,
        "Q1" => $Q1,
        "Q2" => $Q2,

        "cirujano_id" => $cirujano_id,
        "anestesiologo" => $anestesiologo,

        "habitacion" => $habitacion,
        "casa_comercial" => $casa_comercial,
        "mesa_traccion" => $mesa_traccion,
        "laboratorio" => $laboratorio,
        "arco_en_c" => $arco_en_c,
        "es_protesis" => $es_protesis,
      ];

      $finalCols = [];
      $finalParams = [];

      foreach ($cols as $c) {
        if (hasColumn($pdo, $T_PROG, $c)) {
          $finalCols[] = $c;
          $finalParams[] = $map[$c];
        }
      }

      // opcional: guardar ingreso_id en programación (legacy / compat)
      if ($tiene_ing_en_prog) {
        $finalCols[] = "ingreso_id";
        $finalParams[] = $id;
      }

      $sql = "INSERT INTO {$T_PROG} (" . implode(",", $finalCols) . ")
              VALUES (" . implode(",", array_fill(0, count($finalCols), "?")) . ")";
      $ins = $pdo->prepare($sql);
      $ins->execute($finalParams);

      $newProgId = (int)$pdo->lastInsertId();

      // Insert en tabla puente (lo real)
      $lk = $pdo->prepare("INSERT IGNORE INTO {$T_LINK} (ingreso_id, programacion_id) VALUES (?, ?)");
      $lk->execute([$id, $newProgId]);

      // opcional: guardar última programación en ingresos.programacion_id (legacy)
      if ($tiene_prog_en_ing) {
        $up = $pdo->prepare("UPDATE {$T_ING} SET programacion_id = ? WHERE id = ?");
        $up->execute([$newProgId, $id]);
      }

      $pdo->commit();

      header("Location: " . base_url("/programacion/ver_programacion.php?mensaje=" . urlencode("Programación creada desde ingreso #{$id}")));
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errorMsg = "Error creando programación: " . $e->getMessage();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Programar desde ingreso</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-4 mb-5">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">Programar desde ingreso #<?= (int)$id ?></h4>
      <div class="text-muted small">Aquí puedes crear <strong>una o varias</strong> programaciones para este mismo ingreso.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= base_url('/residentes/panel_ingresos.php') ?>">← Volver</a>
  </div>

  <?php if (!empty($programacionesExistentes)): ?>
    <div class="alert alert-info">
      <strong>Este ingreso ya tiene <?= count($programacionesExistentes) ?> programación(es):</strong>
      <ul class="mb-0 mt-2">
        <?php foreach ($programacionesExistentes as $p): ?>
          <li>
            <a href="<?= base_url('/programacion/modificar_cirugia.php?id=' . (int)$p['id']) ?>">
              #<?= (int)$p['id'] ?> — <?= h($p['fecha'] ?? '') ?> <?= h($p['h_ingreso'] ?? '') ?> — <?= h($p['procedimiento'] ?? '') ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="small text-muted mt-2">Puedes crear otra programación abajo.</div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger"><?= h($errorMsg) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header bg-warning">
      <strong>Crear programación quirúrgica</strong>
    </div>
    <div class="card-body">
      <form method="post" autocomplete="off">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="row g-2">
          <div class="col-md-3">
            <label class="form-label">Fecha *</label>
            <input type="date" class="form-control" name="fecha" value="<?= h($fecha) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Día *</label>
            <input type="text" class="form-control" name="dia" value="<?= h($dia) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Hora ingreso *</label>
            <input type="time" class="form-control" name="h_ingreso" value="<?= h($h_ingreso) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Hora cirugía</label>
            <input type="time" class="form-control" name="h_cirugia" value="<?= h($h_cirugia) ?>">
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Paciente (texto) *</label>
            <input type="text" class="form-control" name="paciente" value="<?= h($paciente) ?>" required>
            <small class="text-muted">Ej: POR ENVIAR / “Juan Pérez”</small>
          </div>
          <div class="col-md-6">
            <label class="form-label">Cédula</label>
            <input type="text" class="form-control" name="cedula" value="<?= h($cedula) ?>">
          </div>
        </div>

        <div class="row g-2 mt-2">
          <div class="col-md-3">
            <label class="form-label">Nombre 1</label>
            <input type="text" class="form-control" name="nombre1" value="<?= h($nombre1) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Nombre 2</label>
            <input type="text" class="form-control" name="nombre2" value="<?= h($nombre2) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Apellido 1</label>
            <input type="text" class="form-control" name="apellido1" value="<?= h($apellido1) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Apellido 2</label>
            <input type="text" class="form-control" name="apellido2" value="<?= h($apellido2) ?>">
          </div>
        </div>

        <div class="row g-2 mt-2">
          <div class="col-md-3">
            <label class="form-label">Edad</label>
            <input type="number" class="form-control" name="edad" value="<?= h((string)$edad) ?>" min="0" max="120">
          </div>
          <div class="col-md-9">
            <label class="form-label">Procedimiento *</label>
            <input type="text" class="form-control" name="procedimiento" value="<?= h($procedimiento) ?>" required>
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label">Quirófano</label><br>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="quirofano" value="Q1" id="q1" <?= ($quirofano==='Q1')?'checked':''; ?>>
            <label class="form-check-label" for="q1">Q1</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="quirofano" value="Q2" id="q2" <?= ($quirofano==='Q2')?'checked':''; ?>>
            <label class="form-check-label" for="q2">Q2</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="quirofano" value="" id="qs" <?= ($quirofano!=='Q1' && $quirofano!=='Q2')?'checked':''; ?>>
            <label class="form-check-label" for="qs">Sin asignar</label>
          </div>
        </div>

        <div class="row g-2 mt-3">
          <div class="col-md-6">
            <label class="form-label">Cirujano *</label>
            <select class="form-select" name="cirujano_id" required>
              <option value="">Seleccione</option>
              <?php foreach ($cirujanos as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ($cirujano_id === (int)$c['id']) ? 'selected' : '' ?>>
                  <?= h($c['nombre']) ?> (<?= h($c['especialidad'] ?? '') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Anestesiólogo *</label>
            <input type="text" class="form-control" name="anestesiologo" value="<?= h($anestesiologo) ?>" required>
          </div>
        </div>

        <div class="row g-2 mt-3">
          <div class="col-md-4">
            <label class="form-label">Habitación</label>
            <select class="form-select" name="habitacion">
              <option value="Ambulatorio" <?= ($habitacion==='Ambulatorio')?'selected':''; ?>>Ambulatorio</option>
              <option value="Internado" <?= ($habitacion==='Internado')?'selected':''; ?>>Internado</option>
              <option value="InternadoP" <?= ($habitacion==='InternadoP')?'selected':''; ?>>Internado PREMIUM</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Casa Comercial</label>
            <select class="form-select" name="casa_comercial">
              <option value="SI" <?= ($casa_comercial==='SI')?'selected':''; ?>>SI</option>
              <option value="NO" <?= ($casa_comercial!=='SI')?'selected':''; ?>>NO</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Mesa de Tracción</label>
            <select class="form-select" name="mesa_traccion">
              <option value="SI" <?= ($mesa_traccion==='SI')?'selected':''; ?>>SI</option>
              <option value="NO" <?= ($mesa_traccion!=='SI')?'selected':''; ?>>NO</option>
            </select>
          </div>
        </div>

        <div class="row g-2 mt-3">
          <div class="col-md-8">
            <label class="form-label">Laboratorio</label>
            <textarea class="form-control" name="laboratorio" rows="2"><?= h($laboratorio) ?></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label">Arco en C</label>
            <select class="form-select" name="arco_en_c">
              <option value="SI" <?= ($arco_en_c==='SI')?'selected':''; ?>>SI</option>
              <option value="NO" <?= ($arco_en_c!=='SI')?'selected':''; ?>>NO</option>
            </select>

            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" id="es_protesis" name="es_protesis" value="1" <?= ((int)$es_protesis===1)?'checked':''; ?>>
              <label class="form-check-label" for="es_protesis">Es prótesis</label>
            </div>
          </div>
        </div>

        <button class="btn btn-warning w-100 mt-4">Crear programación</button>
      </form>
    </div>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>