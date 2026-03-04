<?php
// /public_html/evoprx/residentes/tabla_ingresos.php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Usuario actual (para rol)
$u = function_exists('current_user') ? current_user() : [];
$rol = (string)($u['rol'] ?? ($_SESSION['role'] ?? ''));
$can_edit = in_array($rol, ['admin', 'editor'], true);

function hh($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function joinParts($a, $b) {
    $a = trim((string)$a);
    $b = trim((string)$b);
    $out = trim($a . ' ' . $b);
    return trim(preg_replace('/\s+/', ' ', $out));
}

function normalizadosDesdeIngreso(array $row): array {
    $n1  = $row['nombre1']   ?? '';
    $n2  = $row['nombre2']   ?? '';
    $a1  = $row['apellido1'] ?? '';
    $a2  = $row['apellido2'] ?? '';
    $ced = $row['cedula']    ?? '';

    $nombre   = joinParts($n1, $n2);
    $apellido = joinParts($a1, $a2);
    $cedula   = trim((string)$ced);

    if ($nombre === '' && isset($row['nombre']))     $nombre = trim((string)$row['nombre']);
    if ($apellido === '' && isset($row['apellido'])) $apellido = trim((string)$row['apellido']);

    return [$nombre, $apellido, $cedula];
}

// Tipos ingreso map
$TIPOS_MAP = [];
try {
    $stTipos = $pdo->query("SELECT id, nombre FROM " . t('tipos_ingreso') . " ORDER BY nombre");
    while ($r = $stTipos->fetch(PDO::FETCH_ASSOC)) {
        $TIPOS_MAP[(int)$r['id']] = (string)$r['nombre'];
    }
} catch (Throwable $e) {
    $TIPOS_MAP = [];
}

// Fallback si panel no pasó $ingresos
if (!isset($ingresos) || !is_array($ingresos)) {
    try {
        $sql = "
            SELECT
                i.*,
                u.username AS usuario_registro,
                h.numero AS habitacion_numero,
                h.descripcion AS habitacion_descripcion,
                ti.nombre AS tipo_ingreso_nombre
            FROM " . t('ingresos') . " i
            JOIN " . t('usuarios') . " u ON i.usuario_id = u.id
            LEFT JOIN " . t('habitaciones') . " h ON i.habitacion_id = h.id
            LEFT JOIN " . t('tipos_ingreso') . " ti ON i.tipo_ingreso_id = ti.id
            WHERE (i.estado IS NULL OR i.estado = 'ingresado')
            ORDER BY i.fecha_entrada DESC
        ";
        $stmt = $pdo->query($sql);
        $ingresos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        die("❌ Error cargando ingresos: " . hh($e->getMessage()));
    }
}

/**
 * ====== NUEVO: contar programaciones por ingreso ======
 * Usamos: hosp_programacion_quirofano.ingreso_id
 */
$progCount = [];
$lastProgId = [];

try {
    $ids = array_values(array_unique(array_filter(array_map(function($r){
        return (int)($r['id'] ?? 0);
    }, $ingresos))));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sqlProg = "
            SELECT ingreso_id,
                   COUNT(*) AS total,
                   MAX(id) AS last_id
            FROM hosp_programacion_quirofano
            WHERE ingreso_id IN ($placeholders)
            GROUP BY ingreso_id
        ";
        $stProg = $pdo->prepare($sqlProg);
        $stProg->execute($ids);

        while ($r = $stProg->fetch(PDO::FETCH_ASSOC)) {
            $iid = (int)$r['ingreso_id'];
            $progCount[$iid] = (int)$r['total'];
            $lastProgId[$iid] = (int)$r['last_id'];
        }
    }
} catch (Throwable $e) {
    // si falla, no rompemos la tabla
    $progCount = [];
    $lastProgId = [];
}
?>

<div class="table-responsive">
  <table class="table table-striped table-hover table-bordered align-middle">
    <thead class="table-dark text-center">
      <tr>
        <th>ID</th>
        <th>Nombre</th>
        <th>Apellidos</th>
        <th>Cédula</th>
        <th>Fecha Entrada</th>
        <th>Tratante</th>
        <th>Tipo Ingreso</th>
        <th>Habitación</th>
        <th>Estado</th>
        <th>Registrado por</th>
        <?php if ($can_edit): ?>
          <th>Acciones</th>
        <?php endif; ?>
      </tr>
    </thead>

    <tbody>
    <?php
    $modales = [];

    foreach ($ingresos as $ingreso):
        [$nombre, $apellido, $cedula] = normalizadosDesdeIngreso((array)$ingreso);

        $tipoIngreso = trim((string)($ingreso['tipo_ingreso_nombre'] ?? ''));
        if ($tipoIngreso === '') {
            $tid = (int)($ingreso['tipo_ingreso_id'] ?? 0);
            if ($tid > 0 && isset($TIPOS_MAP[$tid])) {
                $tipoIngreso = $TIPOS_MAP[$tid];
            }
        }
        if ($tipoIngreso === '') $tipoIngreso = '—';

        $habitacionTxt = '<span class="text-muted">Emergencia</span>';
        if (!empty($ingreso['habitacion_numero'])) {
            $habitacionTxt = "Habitación " . hh($ingreso['habitacion_numero']);
            if (!empty($ingreso['habitacion_descripcion'])) {
                $habitacionTxt .= " - " . hh($ingreso['habitacion_descripcion']);
            }
        }

        $id = (int)($ingreso['id'] ?? 0);

        // NUEVO: programaciones
        $nProg = (int)($progCount[$id] ?? 0);
        $ultimoProg = (int)($lastProgId[$id] ?? 0);
    ?>
      <tr>
        <td class="text-center"><?= $id ?></td>
        <td><?= $nombre !== '' ? hh($nombre) : '<span class="text-muted">—</span>' ?></td>
        <td><?= $apellido !== '' ? hh($apellido) : '<span class="text-muted">—</span>' ?></td>
        <td><?= $cedula !== '' ? hh($cedula) : '<span class="text-muted">—</span>' ?></td>
        <td class="text-center"><?= !empty($ingreso['fecha_entrada']) ? hh($ingreso['fecha_entrada']) : '<span class="text-muted">—</span>' ?></td>
        <td><?= !empty($ingreso['tratante']) ? hh($ingreso['tratante']) : '<span class="text-muted">—</span>' ?></td>
        <td class="text-center"><?= hh($tipoIngreso) ?></td>
        <td class="text-center"><?= $habitacionTxt ?></td>
        <td class="text-center"><span class="badge bg-warning text-dark">Ingresado</span></td>
        <td class="text-center"><?= hh($ingreso['usuario_registro'] ?? '') ?></td>

        <?php if ($can_edit): ?>
          <td class="text-center" style="min-width: 420px;">
            <a href="modificar_ingreso.php?id=<?= $id ?>" class="btn btn-sm btn-primary">Modificar</a>

            <!-- NUEVO: Programar / Ver programaciones -->
            <a href="<?= base_url('/programacion/programar_desde_ingreso.php?id=' . $id) ?>"
               class="btn btn-sm btn-warning">
               Programar
            </a>

            <?php if ($nProg > 0 && $ultimoProg > 0): ?>
              <a href="<?= base_url('/programacion/modificar_cirugia.php?id=' . $ultimoProg) ?>"
                 class="btn btn-sm btn-outline-warning">
                 Ver última (<?= $nProg ?>)
              </a>
            <?php endif; ?>

            <button class="btn btn-sm btn-secondary"
                    data-bs-toggle="modal"
                    data-bs-target="#modalEvolucion<?= $id ?>">
              Evoluciones
            </button>

            <a href="dar_alta.php?id=<?= $id ?>" class="btn btn-sm btn-success">Dar de alta</a>

            <?php if (!empty($ingreso['habitacion_numero'])): ?>
              <a href="membrete.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-dark">🖨 Membrete</a>
            <?php endif; ?>
          </td>
        <?php endif; ?>
      </tr>

    <?php
      if (!$can_edit) continue;

      ob_start();
      try {
          $evoStmt = $pdo->prepare("
              SELECT e.*, u.username
              FROM " . t('evoluciones') . " e
              JOIN " . t('usuarios') . " u ON e.usuario_id = u.id
              WHERE e.ingreso_id = ?
              ORDER BY e.fecha DESC
          ");
          $evoStmt->execute([$id]);
          $evoluciones = $evoStmt->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {
          $evoluciones = [];
      }
    ?>
      <div class="modal fade" id="modalEvolucion<?= $id ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
          <form method="POST" action="guardar_evolucion.php">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">📝 Evoluciones de <?= hh($nombre) ?> <?= hh($apellido) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
              </div>
              <div class="modal-body">
                <input type="hidden" name="ingreso_id" value="<?= $id ?>">
                <textarea name="observacion" class="form-control mb-3" rows="4" required></textarea>

                <h6>📌 Historial</h6>
                <div class="list-group" style="max-height: 220px; overflow-y: auto;">
                  <?php if (count($evoluciones) > 0): ?>
                    <?php foreach ($evoluciones as $evo): ?>
                      <div class="list-group-item small">
                        <strong><?= hh($evo['username'] ?? '') ?>:</strong>
                        <span><?= nl2br(hh($evo['observacion'] ?? '')) ?></span>
                        <div class="text-muted text-end"><small><?= hh($evo['fecha'] ?? '') ?></small></div>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="text-muted">Sin evoluciones registradas.</div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Guardar evolución</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    <?php
      $modales[] = ob_get_clean();
    endforeach;
    ?>
    </tbody>
  </table>
</div>

<?php
if (!empty($modales)) echo implode("\n", $modales);
?>