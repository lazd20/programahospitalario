<?php
// estable/parametros.php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();

// Solo admin (ajusta si deseas permitir especialista)
if (($u['rol'] ?? '') !== 'admin') {
  flash_set('error', 'No tienes permisos para parametrizar el establecimiento.');
  redirect($BASE . '/patients/list.php');
}

// Traer el establecimiento activo (si hay varios, toma el más reciente)
$stGet = $pdo->prepare("
  SELECT *
  FROM establishments
  WHERE activo = 1
  ORDER BY id DESC
  LIMIT 1
");
$stGet->execute();
$est = $stGet->fetch(PDO::FETCH_ASSOC);

$fields = [
  'institucion_sistema',
  'unicodigo',
  'establecimiento_salud',
  'distrito',
  'zona',
  'provincia',
  'canton',
  'parroquia',
  'direccion',
  'telefono',
  'ruc',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = [];
  foreach ($fields as $f) {
    $data[$f] = trim((string)($_POST[$f] ?? ''));
    if ($data[$f] === '') $data[$f] = null;
  }

  try {
    if ($est && !empty($est['id'])) {
      // UPDATE
      $sql = "
        UPDATE establishments SET
          institucion_sistema = :institucion_sistema,
          unicodigo = :unicodigo,
          establecimiento_salud = :establecimiento_salud,
          distrito = :distrito,
          zona = :zona,
          provincia = :provincia,
          canton = :canton,
          parroquia = :parroquia,
          direccion = :direccion,
          telefono = :telefono,
          ruc = :ruc,
          activo = 1
        WHERE id = :id
        LIMIT 1
      ";
      $st = $pdo->prepare($sql);
      $data['id'] = (int)$est['id'];
      $st->execute($data);
      flash_set('success', 'Parámetros del establecimiento actualizados.');
    } else {
      // INSERT (y lo deja activo)
      $sql = "
        INSERT INTO establishments (
          institucion_sistema, unicodigo, establecimiento_salud,
          distrito, zona, provincia, canton, parroquia,
          direccion, telefono, ruc, activo
        ) VALUES (
          :institucion_sistema, :unicodigo, :establecimiento_salud,
          :distrito, :zona, :provincia, :canton, :parroquia,
          :direccion, :telefono, :ruc, 1
        )
      ";
      $st = $pdo->prepare($sql);
      $st->execute($data);
      flash_set('success', 'Establecimiento creado y guardado.');
    }

    redirect($BASE . '/estable/parametros.php');
  } catch (Throwable $e) {
    flash_set('error', 'Error al guardar: ' . $e->getMessage());
  }
}

// Re-cargar para mostrar valores actuales
$stGet->execute();
$est = $stGet->fetch(PDO::FETCH_ASSOC) ?: [];

function val($arr, $k) {
  return (string)($arr[$k] ?? '');
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Parámetros del Establecimiento</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="container py-4">
  <?php include __DIR__ . '/../partials/flash.php'; ?>

  <div class="d-flex align-items-center gap-2 mb-3">
    <h3 class="mb-0">Parámetros del Establecimiento</h3>
    <span class="badge text-bg-secondary">Se usa para llenar encabezados (MSP)</span>
  </div>

  <form method="post" class="card shadow-sm">
    <div class="card-body">
      <div class="row g-3">

        <div class="col-md-6">
          <label class="form-label">Institución del Sistema</label>
          <input type="text" name="institucion_sistema" class="form-control"
                 value="<?= e(val($est,'institucion_sistema')) ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Unicódigo</label>
          <input type="text" name="unicodigo" class="form-control"
                 value="<?= e(val($est,'unicodigo')) ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Establecimiento de Salud</label>
          <input type="text" name="establecimiento_salud" class="form-control"
                 value="<?= e(val($est,'establecimiento_salud')) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Distrito</label>
          <input type="text" name="distrito" class="form-control"
                 value="<?= e(val($est,'distrito')) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Zona</label>
          <input type="text" name="zona" class="form-control"
                 value="<?= e(val($est,'zona')) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Provincia</label>
          <input type="text" name="provincia" class="form-control"
                 value="<?= e(val($est,'provincia')) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Cantón</label>
          <input type="text" name="canton" class="form-control"
                 value="<?= e(val($est,'canton')) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Parroquia</label>
          <input type="text" name="parroquia" class="form-control"
                 value="<?= e(val($est,'parroquia')) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Teléfono</label>
          <input type="text" name="telefono" class="form-control"
                 value="<?= e(val($est,'telefono')) ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Dirección</label>
          <input type="text" name="direccion" class="form-control"
                 value="<?= e(val($est,'direccion')) ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">RUC</label>
          <input type="text" name="ruc" class="form-control"
                 value="<?= e(val($est,'ruc')) ?>">
        </div>

        <div class="col-md-6 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit">Guardar parámetros</button>
        </div>

      </div>
    </div>
  </form>
</div>

</body>
</html>
