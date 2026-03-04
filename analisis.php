<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ✅ Tu config.php ya crea $pdo en global. Solo validamos que exista.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("<div class='alert alert-danger'>No se detectó la conexión PDO. Revisa config.php</div>");
}

function hasTable(PDO $pdo, string $table): bool {
    $sql = "SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

$diasSemana = [
    'Monday' => 'Lunes',
    'Tuesday' => 'Martes',
    'Wednesday' => 'Miércoles',
    'Thursday' => 'Jueves',
    'Friday' => 'Viernes',
    'Saturday' => 'Sábado',
    'Sunday' => 'Domingo'
];

// ✅ Tablas con prefijo hosp_
$tProg = 'hosp_programacion_quirofano';

// Cirujanos: si existe hosp_cirujanos úsala, si no, usa cirujanos
$tCir  = hasTable($pdo, 'hosp_cirujanos') ? 'hosp_cirujanos' : 'cirujanos';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programaciones de Quirófano</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .fecha-header { background-color: #0010ff36; font-weight: bold; text-align: center; font-size: 1.2em; }
        .table { border: 2px solid #000; }
        .table th, .table td { border: 2px solid #000; font-weight: bold; font-size: 1.1em; }
        th { background-color: #343a40; color: #fff; }
        .protesis { color: red; }
    </style>
</head>
<body>
<div class="container-fluid">
    <h2 class="text-center mb-4">Programaciones de Quirófano</h2>

    <div class="mb-4">
        <a href="programar_cirugia.php" class="btn btn-success">Programar Cirugía</a>
    </div>

    <form method="GET" class="mb-4">
        <div class="form-row">
            <div class="col-md-3">
                <label for="fecha_inicio">Fecha de inicio:</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio" class="form-control"
                       value="<?php echo isset($_GET['fecha_inicio']) ? htmlspecialchars($_GET['fecha_inicio']) : ''; ?>">
            </div>
            <div class="col-md-3">
                <label for="fecha_fin">Fecha de fin:</label>
                <input type="date" id="fecha_fin" name="fecha_fin" class="form-control"
                       value="<?php echo isset($_GET['fecha_fin']) ? htmlspecialchars($_GET['fecha_fin']) : ''; ?>">
            </div>
            <div class="col-md-3">
                <label for="cirujano">Filtrar por Cirujano:</label>
                <select id="cirujano" name="cirujano" class="form-control">
                    <option value="">Todos</option>
                    <?php
                    $stmt = $pdo->query("SELECT id, nombre FROM {$tCir} ORDER BY nombre");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $selected = (isset($_GET['cirujano']) && $_GET['cirujano'] == $row['id']) ? 'selected' : '';
                        $idOpt = (int)$row['id'];
                        $nomOpt = htmlspecialchars($row['nombre'] ?? '');
                        echo "<option value='{$idOpt}' {$selected}>{$nomOpt}</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="col-md-3 align-self-end">
                <button type="submit" class="btn btn-primary">Filtrar</button>
                <a href="ver_programacion.php" class="btn btn-secondary">Limpiar Filtro</a>
            </div>
        </div>
    </form>

    <table class="table table-striped table-bordered">
        <thead class="thead-dark">
        <tr>
            <th>Paciente</th><th>Edad</th><th>Ingreso</th><th>Cirugía</th><th>Procedimiento</th>
            <th>Q1</th><th>Q2</th><th>Cirujano</th><th>Anestesiólogo</th><th>Habitación</th>
            <th>CC</th><th>MT</th><th>Laboratorio</th><th>AC</th><th>Acciones</th>
        </tr>
        </thead>
        <tbody>
        <?php
        try {
            $sql = "SELECT pq.*, c.nombre AS nombre_cirujano
                    FROM {$tProg} pq
                    LEFT JOIN {$tCir} c ON pq.cirujano_id = c.id";

            $conditions = [];

            if (empty($_GET['fecha_inicio']) && empty($_GET['fecha_fin']) && empty($_GET['cirujano'])) {
                $conditions[] = "pq.fecha >= CURDATE()";
            }
            if (!empty($_GET['fecha_inicio'])) $conditions[] = "pq.fecha >= :fecha_inicio";
            if (!empty($_GET['fecha_fin']))    $conditions[] = "pq.fecha <= :fecha_fin";
            if (!empty($_GET['cirujano']))     $conditions[] = "pq.cirujano_id = :cirujano";

            if (!empty($conditions)) $sql .= " WHERE " . implode(' AND ', $conditions);

            $sql .= " ORDER BY pq.fecha, pq.h_cirugia";
            $stmt = $pdo->prepare($sql);

            if (!empty($_GET['fecha_inicio'])) $stmt->bindValue(':fecha_inicio', $_GET['fecha_inicio']);
            if (!empty($_GET['fecha_fin']))    $stmt->bindValue(':fecha_fin', $_GET['fecha_fin']);
            if (!empty($_GET['cirujano']))     $stmt->bindValue(':cirujano', (int)$_GET['cirujano'], PDO::PARAM_INT);

            $stmt->execute();
            $currentDate = null;

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $isProtesis = ((int)($row['es_protesis'] ?? 0) === 1) ? 'protesis' : '';

                if ($currentDate !== ($row['fecha'] ?? null)) {
                    $currentDate = $row['fecha'];
                    $diaSemana = $diasSemana[date('l', strtotime($currentDate))] ?? '';
                    echo "<tr><td colspan='15' class='fecha-header'>Fecha: "
                        . date('d-m-Y', strtotime($currentDate))
                        . " - {$diaSemana}</td></tr>";
                }

                $idRow = (int)($row['id'] ?? 0);

                echo "<tr class='{$isProtesis}'>
                        <td>".htmlspecialchars($row['paciente'] ?? '')."</td>
                        <td>".htmlspecialchars((string)($row['edad'] ?? ''))."</td>
                        <td>".(!empty($row['h_ingreso']) ? date('H:i', strtotime($row['h_ingreso'])) : '')."</td>
                        <td>".(!empty($row['h_cirugia']) ? date('H:i', strtotime($row['h_cirugia'])) : '')."</td>
                        <td>".htmlspecialchars($row['procedimiento'] ?? '')."</td>
                        <td>".htmlspecialchars($row['Q1'] ?? '')."</td>
                        <td>".htmlspecialchars($row['Q2'] ?? '')."</td>
                        <td>".htmlspecialchars($row['nombre_cirujano'] ?? '')."</td>
                        <td>".htmlspecialchars($row['anestesiologo'] ?? '')."</td>
                        <td>".htmlspecialchars($row['habitacion'] ?? '')."</td>
                        <td>".htmlspecialchars($row['casa_comercial'] ?? '')."</td>
                        <td>".htmlspecialchars($row['mesa_traccion'] ?? '')."</td>
                        <td>".htmlspecialchars($row['laboratorio'] ?? '')."</td>
                        <td>".htmlspecialchars($row['arco_en_c'] ?? '')."</td>
                        <td>
                            <a href='modificar_cirugia.php?id={$idRow}' class='btn btn-warning btn-sm'>Modificar</a>
                            <a href='eliminar_cirugia.php?id={$idRow}' class='btn btn-danger btn-sm'
                               onclick='return confirm(\"¿Eliminar esta cirugía?\")'>Eliminar</a>
                        </td>
                      </tr>";
            }
        } catch (PDOException $e) {
            echo "<tr><td colspan='15'><div class='alert alert-danger'>Error: ".htmlspecialchars($e->getMessage())."</div></td></tr>";
        }
        ?>
        </tbody>
    </table>
</div>
</body>
</html>