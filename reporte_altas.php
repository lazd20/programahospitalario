<?php
// /public_html/evoprx/residentes/modificar_ingreso.php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

date_default_timezone_set('America/Guayaquil');

$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

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
function clean($v): string { return trim((string)$v); }
function hh($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function buildPaciente($n1, $n2, $a1, $a2, $fallback = ''): string {
    $partes = [$n1, $n2, $a1, $a2];
    $partes = array_filter($partes, function($x){
        return $x !== null && trim($x) !== '';
    });
    $full = trim(preg_replace('/\s+/', ' ', implode(' ', $partes)));
    return $full !== '' ? $full : trim((string)$fallback);
}

if (empty($_GET['id'])) die("ID no especificado.");
$id = (int)$_GET['id'];

// ingreso
$stmt = $pdo->prepare("SELECT * FROM hosp_ingresos WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$ingreso = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ingreso) die("Ingreso no encontrado.");

// habitaciones libres o actual
$habitacionesSt = $pdo->prepare("
    SELECT * 
    FROM hosp_habitaciones 
    WHERE estado = 'libre' OR id = ?
    ORDER BY numero
");
$habitacionesSt->execute([(int)($ingreso['habitacion_id'] ?? 0)]);
$habitaciones = $habitacionesSt->fetchAll(PDO::FETCH_ASSOC);

// tipos ingreso
$tipos = $pdo->query("SELECT id, nombre FROM hosp_tipos_ingreso ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// cirujanos
$cirujanos = $pdo->query("SELECT id, nombre FROM hosp_cirujanos ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nombre1    = clean($_POST['nombre1'] ?? '');
    $nombre2    = clean($_POST['nombre2'] ?? '');
    $apellido1  = clean($_POST['apellido1'] ?? '');
    $apellido2  = clean($_POST['apellido2'] ?? '');
    $cedula     = clean($_POST['cedula'] ?? '');

    $fecha_entrada   = $_POST['fecha_entrada'] ?? date('Y-m-d');
    $cirujano_id     = !empty($_POST['cirujano_id']) ? (int)$_POST['cirujano_id'] : null;
    $habitacion_id   = !empty($_POST['habitacion_id']) ? (int)$_POST['habitacion_id'] : null;
    $tipo_ingreso_id = !empty($_POST['tipo_ingreso_id']) ? (int)$_POST['tipo_ingreso_id'] : null;

    if ($nombre1 === '' || empty($tipo_ingreso_id)) {
        $errorMsg = "Faltan datos obligatorios (Nombre 1 y Tipo de ingreso).";
    }

    // tratante
    $tratante = '';
    if ($cirujano_id) {
        $st = $pdo->prepare("SELECT nombre FROM hosp_cirujanos WHERE id = ? LIMIT 1");
        $st->execute([$cirujano_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $tratante = $row ? $row['nombre'] : '';
    }

    if ($errorMsg === '') {
        try {
            $pdo->beginTransaction();

            $habitacion_anterior = !empty($ingreso['habitacion_id']) ? (int)$ingreso['habitacion_id'] : null;

            if ($habitacion_id && $habitacion_id !== $habitacion_anterior) {
                $chkHab = $pdo->prepare("SELECT estado FROM hosp_habitaciones WHERE id = ? FOR UPDATE");
                $chkHab->execute([$habitacion_id]);
                $hab = $chkHab->fetch(PDO::FETCH_ASSOC);
                if (!$hab) throw new Exception("La habitación seleccionada no existe.");
                if (strtolower(trim($hab['estado'])) !== 'libre') throw new Exception("La habitación ya no está disponible.");
            }

            // actualizar ingreso
            $sqlUp = "UPDATE hosp_ingresos SET
                        nombre1 = ?, nombre2 = ?, apellido1 = ?, apellido2 = ?,
                        cedula = ?, fecha_entrada = ?, tratante = ?,
                        cirujano_id = ?, tipo_ingreso_id = ?, habitacion_id = ?
                      WHERE id = ?";
            $stUp = $pdo->prepare($sqlUp);
            $stUp->execute([
                ($nombre1 === '' ? null : $nombre1),
                ($nombre2 === '' ? null : $nombre2),
                ($apellido1 === '' ? null : $apellido1),
                ($apellido2 === '' ? null : $apellido2),
                ($cedula === '' ? null : $cedula),
                $fecha_entrada,
                $tratante,
                $cirujano_id,
                $tipo_ingreso_id,
                $habitacion_id,
                $id
            ]);

            // liberar anterior si cambió
            if ($habitacion_anterior && $habitacion_anterior !== $habitacion_id) {
                $pdo->prepare("UPDATE hosp_habitaciones SET estado = 'libre' WHERE id = ?")
                    ->execute([$habitacion_anterior]);
            }
            // ocupar nueva
            if ($habitacion_id) {
                $pdo->prepare("UPDATE hosp_habitaciones SET estado = 'ocupada' WHERE id = ?")
                    ->execute([$habitacion_id]);
            }

            // ✅ SINCRONIZAR A PROGRAMACION (si existe programacion_id)
            $programacion_id = !empty($ingreso['programacion_id']) ? (int)$ingreso['programacion_id'] : 0;
            if ($programacion_id > 0) {

                $stP = $pdo->prepare("SELECT paciente FROM hosp_programacion_quirofano WHERE id = ? LIMIT 1");
                $stP->execute([$programacion_id]);
                $rowP = $stP->fetch(PDO::FETCH_ASSOC);
                $fallbackPaciente = $rowP ? ($rowP['paciente'] ?? '') : '';

                $pacienteFinal = buildPaciente($nombre1, $nombre2, $apellido1, $apellido2, $fallbackPaciente);

                $table = 'hosp_programacion_quirofano'; // OJO: aquí va con prefijo
                $set = [];
                $params = [];

                if (hasColumn($pdo, $table, 'nombre1'))   { $set[] = "nombre1 = ?";   $params[] = ($nombre1 === '' ? null : $nombre1); }
                if (hasColumn($pdo, $table, 'nombre2'))   { $set[] = "nombre2 = ?";   $params[] = ($nombre2 === '' ? null : $nombre2); }
                if (hasColumn($pdo, $table, 'apellido1')) { $set[] = "apellido1 = ?"; $params[] = ($apellido1 === '' ? null : $apellido1); }
                if (hasColumn($pdo, $table, 'apellido2')) { $set[] = "apellido2 = ?"; $params[] = ($apellido2 === '' ? null : $apellido2); }
                if (hasColumn($pdo, $table, 'cedula'))    { $set[] = "cedula = ?";    $params[] = ($cedula === '' ? null : $cedula); }
                if (hasColumn($pdo, $table, 'paciente'))  { $set[] = "paciente = ?";  $params[] = $pacienteFinal; }

                if (!empty($set)) {
                    $params[] = $programacion_id;
                    $sqlSync = "UPDATE hosp_programacion_quirofano SET " . implode(", ", $set) . " WHERE id = ?";
                    $stSync = $pdo->prepare($sqlSync);
                    $stSync->execute($params);
                }
            }

            $pdo->commit();

            header("Location: panel_ingresos.php?ok=1");
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorMsg = "Error al modificar ingreso: " . $e->getMessage();
        }
    }

    // refrescar ingreso
    $stmt = $pdo->prepare("SELECT * FROM hosp_ingresos WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $ingreso = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Modificar Ingreso</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">

    <h3 class="mb-3">✏️ Modificar Ingreso</h3>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?= hh($errorMsg) ?></div>
    <?php endif; ?>

    <form method="POST" class="card p-3 shadow-sm">

        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label">Nombre 1 *</label>
                <input type="text" name="nombre1" class="form-control" required value="<?= hh($ingreso['nombre1'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Nombre 2</label>
                <input type="text" name="nombre2" class="form-control" value="<?= hh($ingreso['nombre2'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Apellido 1</label>
                <input type="text" name="apellido1" class="form-control" value="<?= hh($ingreso['apellido1'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Apellido 2</label>
                <input type="text" name="apellido2" class="form-control" value="<?= hh($ingreso['apellido2'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Cédula</label>
                <input type="text" name="cedula" class="form-control" value="<?= hh($ingreso['cedula'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Fecha de Entrada</label>
                <input type="date" name="fecha_entrada" class="form-control" required value="<?= hh($ingreso['fecha_entrada'] ?? date('Y-m-d')) ?>">
            </div>
        </div>

        <div class="mt-3">
            <label class="form-label">Médico Tratante</label>
            <select name="cirujano_id" class="form-select" required>
                <option value="">Seleccione</option>
                <?php foreach ($cirujanos as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)($ingreso['cirujano_id'] ?? 0)) ? 'selected' : '' ?>>
                        <?= hh($c['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mt-3">
            <label class="form-label">Tipo de Ingreso *</label>
            <select name="tipo_ingreso_id" class="form-select" required>
                <option value="">Seleccione</option>
                <?php foreach ($tipos as $tipo): ?>
                    <option value="<?= (int)$tipo['id'] ?>" <?= ((int)$tipo['id'] === (int)($ingreso['tipo_ingreso_id'] ?? 0)) ? 'selected' : '' ?>>
                        <?= hh($tipo['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mt-3">
            <label class="form-label">Habitación</label>
            <select name="habitacion_id" class="form-select">
                <option value="">Emergencia (sin habitación)</option>
                <?php foreach ($habitaciones as $hab): ?>
                    <option value="<?= (int)$hab['id'] ?>" <?= ((int)$hab['id'] === (int)($ingreso['habitacion_id'] ?? 0)) ? 'selected' : '' ?>>
                        Hab. <?= hh($hab['numero']) ?> - <?= hh($hab['descripcion']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            <a href="panel_ingresos.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>

</div>
</body>
</html>