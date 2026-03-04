<?php
// /public_html/evoprx/residentes/reporte_altas.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

date_default_timezone_set('America/Guayaquil');

// === Nuevo esquema de rol (TU estándar) ===
$u = function_exists('current_user') ? current_user() : null;
$role = (string)($_SESSION['role'] ?? ($_SESSION['rol'] ?? ($u['rol'] ?? ($u['role'] ?? ''))));
$userId = (int)($_SESSION['usuario_id'] ?? ($_SESSION['user_id'] ?? ($u['id'] ?? 0)));
$currentUsername = (string)($_SESSION['usuario'] ?? ($_SESSION['username'] ?? ($u['username'] ?? ($u['usuario'] ?? ''))));

// Fuerza UTF-8 en todo
ini_set('default_charset', 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');

$fechaDesde = $_GET['desde'] ?? date('Y-m-d');
$fechaHasta = $_GET['hasta'] ?? date('Y-m-d');
$filtroUsuarioAlta = $_GET['usuario_alta'] ?? '';

$altas = [];
$usuariosAlta = [];
$usuariosTodos = [];

/* ==========================
   Tipos y mapeos (UI -> DB)
   ========================== */
// Claves UI (sin tildes) -> Valor EXACTO en DB
$TIPOS_MAP = [
    'ingresado'       => 'ingresado',
    'alta'            => 'alta',
    'alta_a_peticion' => 'alta a petición',
];

// Etiquetas para UI (sin tildes)
$TIPOS_LABELS = [
    'ingresado'       => 'ingresado',
    'alta'            => 'alta',
    'alta_a_peticion' => 'alta a peticion',
];

function sinTildes($s) {
    if ($s === null) return '';
    $from = ['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ñ','Ñ','ü','Ü'];
    $to   = ['a','e','i','o','u','A','E','I','O','U','n','N','u','U'];
    return str_replace($from, $to, (string)$s);
}

function normalizeFechaDate($input) {
    $input = trim((string)$input);
    if ($input === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) return $input;
    $ts = strtotime($input);
    return $ts ? date('Y-m-d', $ts) : null;
}

function dateToDateTimeOrNull($date) {
    if ($date === null || $date === '' || $date === '0000-00-00') return null;
    return $date . ' 00:00:00';
}

// Devuelve clave UI (sin tildes) desde valor DB
function claveDesdeDB($dbValue) {
    $v = trim((string)$dbValue);
    if ($v === '') return 'alta';
    $vSin = sinTildes(mb_strtolower($v, 'UTF-8'));
    if ($vSin === 'ingresado') return 'ingresado';
    if ($vSin === 'alta') return 'alta';
    if ($vSin === 'alta a peticion') return 'alta_a_peticion';
    return 'alta';
}

try {
    // En tu base nueva, $pdo normalmente viene de helpers.php
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException("No hay conexión PDO disponible. Verifica helpers.php");
    }

    // Asegurar UTF8 en la conexión
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $canEdit = in_array($role, ['admin', 'editor'], true);
    $isAdmin = ($role === 'admin');

    /* =============== ELIMINAR ALTA (solo admin) =============== */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_alta'])) {
        if (!$isAdmin) {
            die("No tiene permisos para eliminar altas.");
        }

        $ingresoIdEliminar = (int)($_POST['ingreso_id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');

        try {
            $pdo->beginTransaction();

            // Verificar que exista el alta en auditoría
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM hosp_altas_auditoria WHERE ingreso_id = :id");
            $checkStmt->execute([':id' => $ingresoIdEliminar]);

            if ((int)$checkStmt->fetchColumn() === 0) {
                $pdo->rollBack();
                die("No se encontro el alta a eliminar.");
            }

            // Guardar auditoría de eliminación
            $stmtAuditoria = $pdo->prepare("
                INSERT INTO hosp_auditoria_eliminacion_altas (ingreso_id, usuario_id, motivo)
                VALUES (:ingreso_id, :usuario_id, :motivo)
            ");
            $stmtAuditoria->execute([
                ':ingreso_id' => $ingresoIdEliminar,
                ':usuario_id' => $userId,
                ':motivo'     => $motivo,
            ]);

            // Eliminar registro de auditoría del alta
            $stmtDelete = $pdo->prepare("DELETE FROM hosp_altas_auditoria WHERE ingreso_id = :ingreso_id");
            $stmtDelete->execute([':ingreso_id' => $ingresoIdEliminar]);

            $pdo->commit();

            header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($_GET));
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            die("Error al eliminar: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }

    /* =============== MODIFICAR ALTA (admin o editor) =============== */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modificar_alta'])) {
        if (!$canEdit) {
            die("No tiene permisos para modificar altas.");
        }

        $ingresoId = (int)($_POST['ingreso_id'] ?? 0);
        $nuevoUsuarioAlta = ($_POST['nuevo_usuario_alta'] === '' ? null : (int)$_POST['nuevo_usuario_alta']);

        $nuevoTipoAltaKey = trim($_POST['nuevo_tipo_alta'] ?? '');
        if (!array_key_exists($nuevoTipoAltaKey, $TIPOS_MAP)) {
            die("Tipo de alta invalido. Use: ingresado, alta o alta_a_peticion.");
        }
        $nuevoTipoAltaDB = $TIPOS_MAP[$nuevoTipoAltaKey];

        $nuevaFechaSalidaDate = normalizeFechaDate($_POST['nueva_fecha_salida'] ?? '');
        $motivoMod = trim($_POST['motivo_mod'] ?? '');
        $usuarioEditorId = $userId;

        try {
            $pdo->beginTransaction();

            $stmtSel = $pdo->prepare("
                SELECT 
                    i.estado AS estado_actual,
                    i.fecha_salida AS fecha_salida_actual,
                    aa.usuario_id AS usuario_alta_actual,
                    aa.tipo_alta AS tipo_alta_actual
                FROM hosp_ingresos i
                LEFT JOIN hosp_altas_auditoria aa ON i.id = aa.ingreso_id
                WHERE i.id = :id
                LIMIT 1
            ");
            $stmtSel->execute([':id' => $ingresoId]);
            $prev = $stmtSel->fetch(PDO::FETCH_ASSOC);

            if (!$prev) {
                $pdo->rollBack();
                die("No se encontro el registro para modificar.");
            }

            // Reversa a ingresado
            if ($nuevoTipoAltaKey === 'ingresado') {

                $stmtAudMod = $pdo->prepare("
                    INSERT INTO hosp_auditoria_modificacion_altas
                        (ingreso_id, usuario_editor_id,
                         usuario_alta_anterior, tipo_alta_anterior,
                         usuario_alta_nuevo, tipo_alta_nuevo,
                         fecha_salida_anterior, fecha_salida_nueva,
                         motivo)
                    VALUES
                        (:ingreso_id, :usuario_editor_id,
                         :usuario_alta_anterior, :tipo_alta_anterior,
                         :usuario_alta_nuevo, :tipo_alta_nuevo,
                         :fecha_salida_anterior, :fecha_salida_nueva,
                         :motivo)
                ");
                $stmtAudMod->execute([
                    ':ingreso_id'            => $ingresoId,
                    ':usuario_editor_id'     => $usuarioEditorId,
                    ':usuario_alta_anterior' => $prev['usuario_alta_actual'],
                    ':tipo_alta_anterior'    => sinTildes($prev['tipo_alta_actual'] ?? ''),
                    ':usuario_alta_nuevo'    => null,
                    ':tipo_alta_nuevo'       => 'ingresado',
                    ':fecha_salida_anterior' => dateToDateTimeOrNull($prev['fecha_salida_actual']),
                    ':fecha_salida_nueva'    => null,
                    ':motivo'                => $motivoMod
                ]);

                $pdo->prepare("UPDATE hosp_ingresos SET estado = 'ingresado', fecha_salida = NULL WHERE id = :id")
                    ->execute([':id' => $ingresoId]);

                $pdo->prepare("DELETE FROM hosp_altas_auditoria WHERE ingreso_id = :id")
                    ->execute([':id' => $ingresoId]);

                $pdo->commit();

                header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($_GET));
                exit;
            }

            // Asegurar registro en altas_auditoria
            $stmtCheckAA = $pdo->prepare("SELECT COUNT(*) FROM hosp_altas_auditoria WHERE ingreso_id = :id");
            $stmtCheckAA->execute([':id' => $ingresoId]);
            $existsAA = ((int)$stmtCheckAA->fetchColumn() > 0);

            if ($existsAA) {
                $stmtUpdAA = $pdo->prepare("
                    UPDATE hosp_altas_auditoria
                    SET usuario_id = :usuario_id, tipo_alta = :tipo_alta
                    WHERE ingreso_id = :ingreso_id
                ");
                $stmtUpdAA->execute([
                    ':usuario_id' => $nuevoUsuarioAlta,
                    ':tipo_alta'  => $nuevoTipoAltaDB,
                    ':ingreso_id' => $ingresoId
                ]);
            } else {
                $stmtInsAA = $pdo->prepare("
                    INSERT INTO hosp_altas_auditoria (ingreso_id, usuario_id, tipo_alta)
                    VALUES (:ingreso_id, :usuario_id, :tipo_alta)
                ");
                $stmtInsAA->execute([
                    ':ingreso_id' => $ingresoId,
                    ':usuario_id' => $nuevoUsuarioAlta,
                    ':tipo_alta'  => $nuevoTipoAltaDB
                ]);
            }

            // Actualizar ingreso
            $stmtUpdIng = $pdo->prepare("
                UPDATE hosp_ingresos
                SET estado = :estado, fecha_salida = :fecha_salida
                WHERE id = :id
            ");
            $stmtUpdIng->execute([
                ':estado'       => $nuevoTipoAltaDB,
                ':fecha_salida' => $nuevaFechaSalidaDate,
                ':id'           => $ingresoId
            ]);

            // Auditoría de modificación
            $stmtAudMod = $pdo->prepare("
                INSERT INTO hosp_auditoria_modificacion_altas
                    (ingreso_id, usuario_editor_id,
                     usuario_alta_anterior, tipo_alta_anterior,
                     usuario_alta_nuevo, tipo_alta_nuevo,
                     fecha_salida_anterior, fecha_salida_nueva,
                     motivo)
                VALUES
                    (:ingreso_id, :usuario_editor_id,
                     :usuario_alta_anterior, :tipo_alta_anterior,
                     :usuario_alta_nuevo, :tipo_alta_nuevo,
                     :fecha_salida_anterior, :fecha_salida_nueva,
                     :motivo)
            ");
            $stmtAudMod->execute([
                ':ingreso_id'            => $ingresoId,
                ':usuario_editor_id'     => $usuarioEditorId,
                ':usuario_alta_anterior' => $prev['usuario_alta_actual'],
                ':tipo_alta_anterior'    => sinTildes($prev['tipo_alta_actual'] ?? ''),
                ':usuario_alta_nuevo'    => $nuevoUsuarioAlta,
                ':tipo_alta_nuevo'       => $TIPOS_LABELS[$nuevoTipoAltaKey] ?? $nuevoTipoAltaKey,
                ':fecha_salida_anterior' => dateToDateTimeOrNull($prev['fecha_salida_actual']),
                ':fecha_salida_nueva'    => dateToDateTimeOrNull($nuevaFechaSalidaDate),
                ':motivo'                => $motivoMod
            ]);

            $pdo->commit();

            header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($_GET));
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            die("Error al modificar: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }

    /* ========== Usuarios para filtros y edición ========== */
    $stmtUsuarios = $pdo->query("
        SELECT DISTINCT ua.id, ua.username
        FROM hosp_altas_auditoria aa
        JOIN hosp_usuarios ua ON aa.usuario_id = ua.id
        ORDER BY ua.username
    ");
    $usuariosAlta = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);

    $stmtTodos = $pdo->query("SELECT id, username FROM hosp_usuarios ORDER BY username");
    $usuariosTodos = $stmtTodos->fetchAll(PDO::FETCH_ASSOC);

    /* ========== Consulta principal ========== */
    $query = "
        SELECT 
            i.*,
            u.username AS usuario_registro,
            ti.nombre AS tipo_ingreso_nombre,
            ua.username AS usuario_alta,
            ua.id AS usuario_alta_id,
            aa.tipo_alta,

            TRIM(CONCAT(
                COALESCE(i.nombre1,''), 
                CASE WHEN i.nombre2 IS NULL OR i.nombre2 = '' THEN '' ELSE CONCAT(' ', i.nombre2) END
            )) AS nombre_completo,

            TRIM(CONCAT(
                COALESCE(i.apellido1,''), 
                CASE WHEN i.apellido2 IS NULL OR i.apellido2 = '' THEN '' ELSE CONCAT(' ', i.apellido2) END
            )) AS apellidos_completos

        FROM hosp_ingresos i
        JOIN hosp_usuarios u ON i.usuario_id = u.id
        LEFT JOIN hosp_tipos_ingreso ti ON i.tipo_ingreso_id = ti.id
        LEFT JOIN hosp_altas_auditoria aa ON i.id = aa.ingreso_id
        LEFT JOIN hosp_usuarios ua ON aa.usuario_id = ua.id
        WHERE i.fecha_salida BETWEEN :desde AND :hasta
          AND i.estado <> 'ingresado'
          AND aa.ingreso_id IS NOT NULL
    ";

    if ($filtroUsuarioAlta !== '') {
        $query .= " AND aa.usuario_id = :usuario_alta ";
    }
    $query .= " ORDER BY i.fecha_salida DESC";

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':desde', $fechaDesde);
    $stmt->bindParam(':hasta', $fechaHasta);
    if ($filtroUsuarioAlta !== '') {
        $stmt->bindParam(':usuario_alta', $filtroUsuarioAlta);
    }
    $stmt->execute();
    $altas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    die("Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Altas - Realmedic</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print { .no-print { display:none !important; } }
    </style>
</head>
<body class="bg-light">
<div class="container mt-5">
    <h2 class="mb-4 text-primary">Reporte de Altas</h2>

    <form class="row g-3 mb-4 no-print" method="GET">
        <div class="col-auto">
            <label for="desde" class="col-form-label">Desde:</label>
        </div>
        <div class="col-auto">
            <input type="date" name="desde" id="desde" class="form-control" value="<?= htmlspecialchars($fechaDesde, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-auto">
            <label for="hasta" class="col-form-label">Hasta:</label>
        </div>
        <div class="col-auto">
            <input type="date" name="hasta" id="hasta" class="form-control" value="<?= htmlspecialchars($fechaHasta, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-auto">
            <label for="usuario_alta" class="col-form-label">Alta por:</label>
        </div>
        <div class="col-auto">
            <select name="usuario_alta" id="usuario_alta" class="form-select">
                <option value="">Todos</option>
                <?php foreach ($usuariosAlta as $usuario): ?>
                    <option value="<?= (int)$usuario['id'] ?>" <?= ((string)$usuario['id'] === (string)$filtroUsuarioAlta) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($usuario['username'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <button onclick="window.print()" type="button" class="btn btn-secondary">Imprimir</button>
        </div>
    </form>

    <?php if (count($altas) > 0): ?>
        <p class="text-end text-muted fst-italic mb-2 d-print-block">
            Generado por: <?= htmlspecialchars($currentUsername, ENT_QUOTES, 'UTF-8') ?> el <?= date('d/m/Y H:i:s') ?>
        </p>

        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover align-middle">
                <thead class="table-dark text-center">
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Apellido</th>
                        <th>Cedula</th>
                        <th>Fecha de entrada</th>
                        <th>Fecha de salida</th>
                        <th>Tipo ingreso</th>
                        <th>Tratante</th>
                        <th>Registrado por</th>
                        <th>Alta por</th>
                        <th>Tipo alta</th>
                        <th class="no-print">Accion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($altas as $alta): ?>
                        <?php
                        $tipoAltaKey = claveDesdeDB($alta['tipo_alta'] ?? '');

                        $nombreMostrar = trim((string)($alta['nombre_completo'] ?? ''));
                        $apellidoMostrar = trim((string)($alta['apellidos_completos'] ?? ''));

                        if ($nombreMostrar === '' && isset($alta['nombre'])) $nombreMostrar = (string)$alta['nombre'];
                        if ($apellidoMostrar === '' && isset($alta['apellido'])) $apellidoMostrar = (string)$alta['apellido'];

                        $tieneAlta = !empty($alta['usuario_alta']);
                        ?>
                        <tr>
                            <td class="text-center"><?= (int)$alta['id'] ?></td>
                            <td><?= htmlspecialchars($nombreMostrar, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($apellidoMostrar, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($alta['cedula'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= htmlspecialchars($alta['fecha_entrada'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= htmlspecialchars($alta['fecha_salida'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= htmlspecialchars($alta['tipo_ingreso_nombre'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($alta['tratante'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= htmlspecialchars($alta['usuario_registro'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= htmlspecialchars($alta['usuario_alta'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= htmlspecialchars($TIPOS_LABELS[$tipoAltaKey] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>

                            <td class="text-center no-print" style="min-width:240px;">
                                <div class="d-flex flex-column gap-1">
                                    <?php if ($canEdit && $tieneAlta): ?>
                                        <button type="button"
                                                class="btn btn-warning btn-sm"
                                                onclick="document.getElementById('edit-<?= (int)$alta['id'] ?>').classList.toggle('d-none')">
                                            Editar
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($isAdmin && $tieneAlta): ?>
                                        <form method="POST" class="d-flex flex-column" onsubmit="return confirm('¿Esta seguro de eliminar esta alta?');">
                                            <input type="hidden" name="ingreso_id" value="<?= (int)$alta['id'] ?>">
                                            <textarea name="motivo" class="form-control mb-1" rows="1" placeholder="Motivo (opcional)"></textarea>
                                            <button type="submit" name="eliminar_alta" class="btn btn-danger btn-sm">Eliminar alta</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <?php if ($canEdit && $tieneAlta): ?>
                            <tr id="edit-<?= (int)$alta['id'] ?>" class="d-none no-print">
                                <td colspan="12">
                                    <form method="POST" class="row g-2 align-items-end">
                                        <input type="hidden" name="ingreso_id" value="<?= (int)$alta['id'] ?>">

                                        <div class="col-md-3">
                                            <label class="form-label">Nuevo "Alta por"</label>
                                            <select name="nuevo_usuario_alta" class="form-select">
                                                <option value="">(sin usuario)</option>
                                                <?php foreach ($usuariosTodos as $uu): ?>
                                                    <option value="<?= (int)$uu['id'] ?>" <?= ((int)($alta['usuario_alta_id'] ?? 0) === (int)$uu['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($uu['username'], ENT_QUOTES, 'UTF-8') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label">Nuevo "Tipo de alta"</label>
                                            <select name="nuevo_tipo_alta" class="form-select">
                                                <?php foreach ($TIPOS_LABELS as $key => $label): ?>
                                                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= ($tipoAltaKey === $key ? 'selected' : '') ?>>
                                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted">Si selecciona "ingresado" se revierte el alta.</small>
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label">Nueva fecha de alta</label>
                                            <input type="date" name="nueva_fecha_salida" class="form-control"
                                                   value="<?= htmlspecialchars($alta['fecha_salida'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        </div>

                                        <div class="col-md-2">
                                            <label class="form-label">Motivo (auditoria)</label>
                                            <input type="text" name="motivo_mod" class="form-control" placeholder="Motivo del cambio">
                                        </div>

                                        <div class="col-md-1">
                                            <button type="submit" name="modificar_alta" class="btn btn-success w-100">Guardar</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-info text-center">
            No se encontraron altas para los filtros seleccionados.
        </div>
    <?php endif; ?>
</div>
</body>
</html>