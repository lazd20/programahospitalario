<?php
// index.php (nuevo dashboard)
// Mantén tu index anterior como index_old.php

require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();

$totalPatients = 0;
$totalEvol = 0;
$totalPresc = 0;

// ====== Contadores Evo/Prx (DB sitiosnuevos_evoprx) ======
try {
  $totalPatients = (int)$pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
  $totalEvol     = (int)$pdo->query("SELECT COUNT(*) FROM evolutions")->fetchColumn();
  $totalPresc    = (int)$pdo->query("SELECT COUNT(*) FROM prescriptions")->fetchColumn();
} catch (Throwable $e) {
  // No crítico
}

// ====== Helpers para tabla con/sin prefijo (hosp_) ======
function tableExists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
      AND table_name = ?
  ");
  $st->execute([$table]);
  return (int)$st->fetchColumn() > 0;
}

function pickTable(PDO $pdo, array $candidates): ?string {
  foreach ($candidates as $t) {
    if (tableExists($pdo, $t)) return $t;
  }
  return null;
}

// ====== Contadores Hospital (DB sitiosnuevos_hospital) ======
$hosp_ok = false;

$progHoy = 0;
$progFuturas = 0;

$ingresadosActivos = 0;
$altasHoy = 0;

try {
  // usamos la config REAL de tu módulo programación (en tu ZIP existe)
  require_once __DIR__ . '/programacion/config.php';

  $pdoHosp = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ]
  );

  // Detectar tablas (con/sin hosp_)
  $tblProg = pickTable($pdoHosp, ['hosp_programacion_quirofano', 'programacion_quirofano']);
  $tblIng  = pickTable($pdoHosp, ['hosp_ingresos', 'ingresos']);

  // Programación
  if ($tblProg) {
    $progHoy = (int)$pdoHosp->query("SELECT COUNT(*) FROM {$tblProg} WHERE fecha = CURDATE()")->fetchColumn();
    $progFuturas = (int)$pdoHosp->query("SELECT COUNT(*) FROM {$tblProg} WHERE fecha >= CURDATE()")->fetchColumn();
  }

  // Residentes / ingresos
  if ($tblIng) {
    $ingresadosActivos = (int)$pdoHosp->query("
      SELECT COUNT(*)
      FROM {$tblIng}
      WHERE estado IS NULL OR estado = 'ingresado'
    ")->fetchColumn();

    // altas hoy (fecha_salida puede ser DATE o DATETIME; cubrimos ambos)
    $altasHoy = (int)$pdoHosp->query("
      SELECT COUNT(*)
      FROM {$tblIng}
      WHERE (estado = 'alta' OR estado = 'alta a petición')
        AND (
          DATE(fecha_salida) = CURDATE()
        )
    ")->fetchColumn();
  }

  $hosp_ok = true;

} catch (Throwable $e) {
  // No crítico: si falla, igual carga Evo/Prx
  $hosp_ok = false;
}

$welcome = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''));
if ($welcome === '') $welcome = $u['username'] ?? 'Usuario';

$rol = (string)($u['rol'] ?? 'viewer');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Inicio | Evo/Prx</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/partials/navbar.php'; ?>

<div class="container py-4">
  <?php include __DIR__ . '/partials/flash.php'; ?>

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
    <div>
      <h4 class="mb-0">Hola, <?= e($welcome) ?></h4>
      <div class="text-muted small">
        Rol: <b><?= e($rol) ?></b>
        <?php if (!empty($u['sello_path'])): ?>
          · <span class="badge text-bg-success">Sello cargado</span>
        <?php else: ?>
          · <span class="badge text-bg-warning">Falta sello</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (($u['rol'] ?? '') === 'admin'): ?>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-primary" href="<?= e($BASE) ?>/patients/list.php">Pacientes</a>
    <a class="btn btn-outline-dark" href="<?= e($BASE) ?>/users/signature.php">Mi sello</a>
    <a class="btn btn-outline-secondary" href="<?= e($BASE) ?>/index_old.php" title="Tu index anterior">Index anterior</a>
  </div>
  </div>

  <!-- ====== MÓDULO EVO/PRX ====== -->
  <div class="d-flex align-items-center justify-content-between mt-3 mb-2">
    <h5 class="mb-0">Evo/Prx</h5>
    <a class="small" href="<?= e($BASE) ?>/patients/list.php">Abrir módulo</a>
  </div>

  <div class="row g-3">
    <div class="col-md-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="fw-semibold">Pacientes</div>
          <div class="display-6"><?= (int)$totalPatients ?></div>
          <a class="btn btn-sm btn-outline-primary mt-2" href="<?= e($BASE) ?>/patients/list.php">Ver listado</a>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="fw-semibold">Evoluciones</div>
          <div class="display-6"><?= (int)$totalEvol ?></div>
          <div class="text-muted small">Histórico global</div>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="fw-semibold">Prescripciones</div>
          <div class="display-6"><?= (int)$totalPresc ?></div>
          <div class="text-muted small">Histórico global</div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

  <!-- ====== PROGRAMACIÓN / RESIDENTES ====== -->
  <div class="d-flex align-items-center justify-content-between mt-4 mb-2">
    <h5 class="mb-0">Clínica Realmedic</h5>
    <div class="text-muted small">
      <?= $hosp_ok ? 'Datos sincronizados' : 'Sin conexión a Hospital (solo enlaces)' ?>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
              <div class="fw-semibold">Programación de quirófano</div>
              <div class="text-muted small">Hoy y próximas cirugías</div>
            </div>
            <span class="badge text-bg-dark">Módulo</span>
          </div>

          <div class="mt-3 d-flex gap-4 flex-wrap">
            <div>
              <div class="text-muted small">Hoy</div>
              <div class="fs-2"><?= (int)$progHoy ?></div>
            </div>
            <div>
              <div class="text-muted small">Desde hoy</div>
              <div class="fs-2"><?= (int)$progFuturas ?></div>
            </div>
          </div>

          <div class="mt-3 d-flex gap-2 flex-wrap">
            <a class="btn btn-sm btn-outline-dark" href="<?= e($BASE) ?>/programacion/index.php">Abrir programación</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e($BASE) ?>/programacion/ver_programacion.php">Ver / Modificar</a>
            <?php if ($rol !== 'viewer'): ?>
              <a class="btn btn-sm btn-primary" href="<?= e($BASE) ?>/programacion/programar.php">Programar cirugía</a>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
              <div class="fw-semibold">Residentes / Ingresos</div>
              <div class="text-muted small">Ingresados activos y altas del día</div>
            </div>
            <span class="badge text-bg-secondary">Módulo</span>
          </div>

          <div class="mt-3 d-flex gap-4 flex-wrap">
            <div>
              <div class="text-muted small">Ingresados</div>
              <div class="fs-2"><?= (int)$ingresadosActivos ?></div>
            </div>
            <div>
              <div class="text-muted small">Altas hoy</div>
              <div class="fs-2"><?= (int)$altasHoy ?></div>
            </div>
          </div>

          <div class="mt-3 d-flex gap-2 flex-wrap">
            <a class="btn btn-sm btn-outline-dark" href="<?= e($BASE) ?>/residentes/panel_ingresos.php">Abrir panel</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e($BASE) ?>/residentes/reporte_altas.php">Reporte de altas</a>
            <?php if ($rol !== 'viewer'): ?>
              <a class="btn btn-sm btn-primary" href="<?= e($BASE) ?>/residentes/registrar.php">+ Nuevo ingreso</a>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- ====== Flujo recomendado ====== -->
  <div class="card shadow-sm mt-4">
    <div class="card-body">
      <div class="fw-semibold mb-2">Flujo recomendado (Evo/Prx)</div>
      <ol class="mb-0">
        <li>Ir a <b>Pacientes</b> y abrir la ficha del paciente.</li>
        <li>Usar <b>+ Evolución + Prescripción</b> (una sola pantalla).</li>
        <li>Imprimir desde <b>Hoja</b> o <b>Imprimir</b> cuando lo necesites.</li>
      </ol>
      <div class="alert alert-info mt-3 mb-0">
        Regla: no existe prescripción sin evolución. Todo se registra en el módulo <b>Evolución + Prescripción</b>.
      </div>
    </div>
  </div>

</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>