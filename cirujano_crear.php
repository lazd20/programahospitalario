<?php
// /public_html/evoprx/residentes/panel_ingresos.php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // no crítico
}

function build_url(array $params): string {
    $base = strtok($_SERVER["REQUEST_URI"], '?');
    return $base . '?' . http_build_query($params);
}

try {
    $por_pagina = 10;

    // Tab activa
    $tab = $_GET['tab'] ?? 'ingresados';
    if (!in_array($tab, ['ingresados', 'altas'], true)) $tab = 'ingresados';

    // =========================
    // INGRESADOS (paginación)
    // =========================
    $pagina_ingresados = isset($_GET['pagina_ingresados']) ? max(1, (int)$_GET['pagina_ingresados']) : 1;
    $inicio_ingresados = ($pagina_ingresados - 1) * $por_pagina;

    $sqlIng = "
        SELECT
            i.*,
            u.username AS usuario_registro,
            h.numero AS habitacion_numero,
            h.descripcion AS habitacion_descripcion,
            ti.nombre AS tipo_ingreso_nombre
        FROM hosp_ingresos i
        JOIN hosp_usuarios u ON i.usuario_id = u.id
        LEFT JOIN hosp_habitaciones h ON i.habitacion_id = h.id
        LEFT JOIN hosp_tipos_ingreso ti ON i.tipo_ingreso_id = ti.id
        WHERE (i.estado IS NULL OR i.estado = 'ingresado')
        ORDER BY i.fecha_entrada DESC
        LIMIT :ini, :pp
    ";
    $stmt_ingresados = $pdo->prepare($sqlIng);
    $stmt_ingresados->bindValue(':ini', $inicio_ingresados, PDO::PARAM_INT);
    $stmt_ingresados->bindValue(':pp', $por_pagina, PDO::PARAM_INT);
    $stmt_ingresados->execute();
    $ingresos = $stmt_ingresados->fetchAll(PDO::FETCH_ASSOC);

    $total_ingresados = (int)$pdo->query("SELECT COUNT(*) FROM hosp_ingresos WHERE (estado IS NULL OR estado = 'ingresado')")->fetchColumn();
    $total_paginas_ingresados = max(1, (int)ceil($total_ingresados / $por_pagina));

    // =========================
    // ALTAS (paginación)
    // =========================
    $pagina_altas = isset($_GET['pagina_altas']) ? max(1, (int)$_GET['pagina_altas']) : 1;
    $inicio_altas = ($pagina_altas - 1) * $por_pagina;

    $sqlAlt = "
        SELECT
            i.*,
            u.username AS usuario_registro,
            h.numero AS habitacion_numero,
            h.descripcion AS habitacion_descripcion,
            ti.nombre AS tipo_ingreso_nombre
        FROM hosp_ingresos i
        JOIN hosp_usuarios u ON i.usuario_id = u.id
        LEFT JOIN hosp_habitaciones h ON i.habitacion_id = h.id
        LEFT JOIN hosp_tipos_ingreso ti ON i.tipo_ingreso_id = ti.id
        WHERE (i.estado = 'alta' OR i.estado = 'alta a petición')
        ORDER BY i.fecha_salida DESC
        LIMIT :ini, :pp
    ";
    $stmt_altas = $pdo->prepare($sqlAlt);
    $stmt_altas->bindValue(':ini', $inicio_altas, PDO::PARAM_INT);
    $stmt_altas->bindValue(':pp', $por_pagina, PDO::PARAM_INT);
    $stmt_altas->execute();
    $altas = $stmt_altas->fetchAll(PDO::FETCH_ASSOC);

    $total_altas = (int)$pdo->query("SELECT COUNT(*) FROM hosp_ingresos WHERE (estado = 'alta' OR estado = 'alta a petición')")->fetchColumn();
    $total_paginas_altas = max(1, (int)ceil($total_altas / $por_pagina));

    // =========================
    // HABITACIONES LIBRES
    // =========================
    $habitaciones_libres = $pdo->query("SELECT * FROM hosp_habitaciones WHERE estado = 'libre' ORDER BY numero")
        ->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    die("❌ Error: " . $e->getMessage());
}

$u = current_user();
$rol = $u['rol'] ?? '';
$username = $u['username'] ?? '';

// Para que los includes vean estas variables
// $ingresos, $altas ya están listos
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <title>Panel de Ingresos</title>
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-primary">🯪 Panel de Ingresos de Pacientes - Realmedic</h2>
        <div class="text-end">
            <div class="me-3 d-inline-block">👤 <?= e($username) ?> (<?= e($rol) ?>)</div>
            <a href="<?= base_url('/auth/logout.php') ?>" class="btn btn-outline-danger btn-sm">Cerrar sesión</a>
        </div>
    </div>

    <?php if ($rol !== 'viewer'): ?>
        <div class="mb-3 d-flex flex-wrap gap-2">
            <a href="<?= base_url('/residentes/registrar.php') ?>" class="btn btn-success">+ Nuevo ingreso</a>
            <a href="<?= base_url('/programacion/ver_programacion.php') ?>" class="btn btn-success">VER PROGRAMACION</a>
            <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modalHabitaciones">🏨 Habitaciones libres</button>
            <a href="<?= base_url('/residentes/reporte_altas.php') ?>" class="btn btn-warning">📄 Ver reporte de altas</a>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs" id="tabIngresos" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $tab === 'ingresados' ? 'active' : '' ?>"
                    id="ingresados-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#ingresados"
                    type="button"
                    role="tab">
                Ingresados
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $tab === 'altas' ? 'active' : '' ?>"
                    id="altas-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#altas"
                    type="button"
                    role="tab">
                Altas
            </button>
        </li>
    </ul>

    <div class="tab-content mt-3" id="tabContenido">
        <div class="tab-pane fade <?= $tab === 'ingresados' ? 'show active' : '' ?>" id="ingresados" role="tabpanel">
            <?php include __DIR__ . '/tabla_ingresos.php'; ?>

            <nav class="mt-3">
              <ul class="pagination flex-wrap">
                <?php for ($i = 1; $i <= $total_paginas_ingresados; $i++): ?>
                  <?php
                    $url = build_url([
                      'tab' => 'ingresados',
                      'pagina_ingresados' => $i,
                      // conserva la pagina_altas actual por si vuelves a esa pestaña
                      'pagina_altas' => $pagina_altas,
                    ]);
                  ?>
                  <li class="page-item <?= $i == $pagina_ingresados ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e($url) ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
              </ul>
            </nav>
        </div>

        <div class="tab-pane fade <?= $tab === 'altas' ? 'show active' : '' ?>" id="altas" role="tabpanel">
            <?php include __DIR__ . '/tabla_altas.php'; ?>

            <nav class="mt-3">
              <ul class="pagination flex-wrap">
                <?php for ($i = 1; $i <= $total_paginas_altas; $i++): ?>
                  <?php
                    $url = build_url([
                      'tab' => 'altas',
                      'pagina_altas' => $i,
                      // conserva la pagina_ingresados actual por si vuelves
                      'pagina_ingresados' => $pagina_ingresados,
                    ]);
                  ?>
                  <li class="page-item <?= $i == $pagina_altas ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e($url) ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
              </ul>
            </nav>
        </div>
    </div>
</div>

<!-- Modal Habitaciones -->
<div class="modal fade" id="modalHabitaciones" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">🛎️ Habitaciones Disponibles</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if (!empty($habitaciones_libres)): ?>
          <table class="table table-bordered text-center align-middle">
            <thead class="table-light"><tr><th>Número</th><th>Descripción</th></tr></thead>
            <tbody>
              <?php foreach ($habitaciones_libres as $hab): ?>
                <tr>
                  <td><?= e($hab['numero']) ?></td>
                  <td><?= e($hab['descripcion']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="alert alert-warning text-center">No hay habitaciones disponibles en este momento.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Mantener ?tab=... cuando cambias de pestaña (sin recargar raro)
document.addEventListener('DOMContentLoaded', () => {
  const tabButtons = document.querySelectorAll('button[data-bs-toggle="tab"]');
  tabButtons.forEach(btn => {
    btn.addEventListener('shown.bs.tab', (e) => {
      const target = e.target.getAttribute('data-bs-target'); // #ingresados o #altas
      const tab = (target === '#altas') ? 'altas' : 'ingresados';

      const url = new URL(window.location.href);
      url.searchParams.set('tab', tab);
      history.replaceState({}, '', url.toString());
    });
  });
});
</script>

</body>
</html>