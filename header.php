<?php
// /public_html/evoprx/programacion/guardar_programacion.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Guayaquil');

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

function hasTable(PDO $pdo, string $table): bool {
    $sql = "SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

// ===============================
// PDO (soporta $pdo global o getPDO())
// ===============================
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (function_exists('getPDO')) {
        $pdo = getPDO();
    } elseif (function_exists('pdo')) {
        $pdo = pdo();
    } else {
        die("<div class='alert alert-danger m-3'>No hay conexión PDO disponible (helpers.php).</div>");
    }
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try { $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $e) {}

// ===============================
// Tablas
// ===============================
$T_PROG = 'hosp_programacion_quirofano';
$T_CIR  = 'hosp_cirujanos';

// (Opcional) tabla pivote si luego la creas
// ejemplo: hosp_ingreso_programaciones (ingreso_id, programacion_id, usuario_id, created_at)
$T_PIVOT = 'hosp_ingreso_programaciones';
$PIVOT_EXISTE = hasTable($pdo, $T_PIVOT);

// ===============================
// Datos del formulario
// ===============================
$u = function_exists('current_user') ? current_user() : [];
$usuario_id = (int)($u['id'] ?? 0);

// Si viene desde ingreso:
$ingreso_id = (int)($_POST['ingreso_id'] ?? 0);

$dia         = trim((string)($_POST['dia'] ?? ''));
$fecha       = trim((string)($_POST['fecha'] ?? ''));
$paciente    = trim((string)($_POST['paciente'] ?? ''));
$edad        = $_POST['edad'] ?? null;
$h_ingreso   = trim((string)($_POST['h_ingreso'] ?? ''));
$h_cirugia   = trim((string)($_POST['h_cirugia'] ?? ''));
$h_cirugia   = ($h_cirugia === '') ? null : $h_cirugia;

$procedimiento = trim((string)($_POST['procedimiento'] ?? ''));
$tipo_cirugia  = $_POST['tipo_cirugia'] ?? null;

$anestesiologo  = trim((string)($_POST['anestesiologo'] ?? ''));
$habitacion     = trim((string)($_POST['habitacion'] ?? ''));
$casa_comercial = trim((string)($_POST['casa_comercial'] ?? ''));
$mesa_traccion  = trim((string)($_POST['mesa_traccion'] ?? ''));
$laboratorio    = (string)($_POST['laboratorio'] ?? '');
$arco_en_c      = trim((string)($_POST['arco_en_c'] ?? 'NO'));
$es_protesis    = isset($_POST['protesis']) ? 1 : 0;

// Normalizados
$nombre1   = trim((string)($_POST['nombre1'] ?? ''));
$nombre2   = trim((string)($_POST['nombre2'] ?? ''));
$apellido1 = trim((string)($_POST['apellido1'] ?? ''));
$apellido2 = trim((string)($_POST['apellido2'] ?? ''));
$cedula    = trim((string)($_POST['cedula'] ?? ''));

$nombre1   = ($nombre1 === '') ? null : $nombre1;
$nombre2   = ($nombre2 === '') ? null : $nombre2;
$apellido1 = ($apellido1 === '') ? null : $apellido1;
$apellido2 = ($apellido2 === '') ? null : $apellido2;
$cedula    = ($cedula === '') ? null : $cedula;

// Validación mínima
if ($fecha === '' || $dia === '' || $paciente === '' || $h_ingreso === '' || $procedimiento === '' || empty($tipo_cirugia)) {
    echo "<div class='alert alert-danger m-3'>Error: faltan campos obligatorios.</div>";
    exit;
}
if ($anestesiologo === '') {
    echo "<div class='alert alert-danger m-3'>Error: anestesiólogo es obligatorio.</div>";
    exit;
}

// Quirófano (tu form usa name='quirofano', pero tu código viejo usaba 'quirófano' con tilde)
$quirofano = (string)($_POST['quirofano'] ?? ($_POST['quirófano'] ?? ''));
$quirofano = trim($quirofano);
$Q1 = ($quirofano === 'Q1') ? 'X' : '';
$Q2 = ($quirofano === 'Q2') ? 'X' : '';

// Cirujano: o escogido o nuevo
$cirujanoId = null;

try {
    $pdo->beginTransaction();

    if (!empty($_POST['nuevo_cirujano'])) {
        $cirujano = trim((string)$_POST['nuevo_cirujano']);
        if ($cirujano === '') {
            throw new Exception("El nombre del nuevo cirujano está vacío.");
        }

        // Evitar duplicados por mayúsculas/espacios
        $cirujano_norm = mb_strtoupper(preg_replace('/\s+/', ' ', $cirujano), 'UTF-8');

        $chk = $pdo->prepare("SELECT id FROM {$T_CIR} WHERE UPPER(TRIM(nombre)) = ? LIMIT 1");
        $chk->execute([$cirujano_norm]);
        $ex = $chk->fetch(PDO::FETCH_ASSOC);

        if ($ex) {
            $cirujanoId = (int)$ex['id'];
        } else {
            $stmtInsert = $pdo->prepare("INSERT INTO {$T_CIR} (nombre, especialidad) VALUES (?, 'General')");
            $stmtInsert->execute([$cirujano]);
            $cirujanoId = (int)$pdo->lastInsertId();
        }
    } else {
        $cirujanoId = !empty($_POST['cirujano']) ? (int)$_POST['cirujano'] : null;
    }

    if (empty($cirujanoId)) {
        throw new Exception("Debe seleccionar un cirujano o registrar uno nuevo.");
    }

    // ===============================
    // INSERT programación (dinámico según columnas)
    // ===============================
    $data = [
        'dia'            => $dia,
        'fecha'          => $fecha,
        'paciente'       => $paciente,
        'edad'           => ($edad === '' ? null : $edad),
        'h_ingreso'      => $h_ingreso,
        'h_cirugia'      => $h_cirugia,
        'procedimiento'  => $procedimiento,
        'tipo_cirugia_id'=> $tipo_cirugia,
        'Q1'             => $Q1,
        'Q2'             => $Q2,
        'cirujano_id'    => $cirujanoId,
        'anestesiologo'  => $anestesiologo,
        'habitacion'     => $habitacion,
        'casa_comercial' => $casa_comercial,
        'mesa_traccion'  => $mesa_traccion,
        'laboratorio'    => $laboratorio,
        'arco_en_c'      => $arco_en_c,
        'es_protesis'    => $es_protesis,
        'nombre1'        => $nombre1,
        'nombre2'        => $nombre2,
        'apellido1'      => $apellido1,
        'apellido2'      => $apellido2,
        'cedula'         => $cedula,
    ];

    // Si existe ingreso_id en programación, lo ponemos (esto habilita 1 ingreso -> N cirugías)
    if ($ingreso_id > 0 && hasColumn($pdo, $T_PROG, 'ingreso_id')) {
        $data['ingreso_id'] = $ingreso_id;
    }

    $cols = [];
    $ph   = [];
    $vals = [];

    foreach ($data as $k => $v) {
        if (hasColumn($pdo, $T_PROG, $k)) {
            $cols[] = $k;
            $ph[]   = ':' . $k;
            $vals[':' . $k] = $v;
        }
    }

    if (empty($cols)) {
        throw new Exception("No se detectaron columnas válidas para insertar en {$T_PROG}.");
    }

    $sql = "INSERT INTO {$T_PROG} (" . implode(',', $cols) . ")
            VALUES (" . implode(',', $ph) . ")";
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute($vals);

    if (!$ok) {
        throw new Exception("No se pudo guardar la programación.");
    }

    $newId = (int)$pdo->lastInsertId();

    // ===============================
    // (Opcional) Pivot: ingreso -> programaciones
    // Si existe tabla pivote, guardamos relación aquí (sirve para historial/consultas)
    // ===============================
    if ($ingreso_id > 0 && $PIVOT_EXISTE) {
        // Ajusta nombres de columnas si tu pivote usa otros
        $colsPivot = [];
        $valsPivot = [];
        if (hasColumn($pdo, $T_PIVOT, 'ingreso_id')) {
            $colsPivot[] = 'ingreso_id';
            $valsPivot[] = $ingreso_id;
        }
        if (hasColumn($pdo, $T_PIVOT, 'programacion_id')) {
            $colsPivot[] = 'programacion_id';
            $valsPivot[] = $newId;
        }
        if (hasColumn($pdo, $T_PIVOT, 'usuario_id')) {
            $colsPivot[] = 'usuario_id';
            $valsPivot[] = $usuario_id;
        }
        if (hasColumn($pdo, $T_PIVOT, 'created_at')) {
            $colsPivot[] = 'created_at';
            $valsPivot[] = date('Y-m-d H:i:s');
        }

        if (count($colsPivot) >= 2) {
            $sqlP = "INSERT INTO {$T_PIVOT} (" . implode(',', $colsPivot) . ")
                     VALUES (" . implode(',', array_fill(0, count($colsPivot), '?')) . ")";
            $pdo->prepare($sqlP)->execute($valsPivot);
        }
    }

    // Notificación por correo
    require_once __DIR__ . '/mailer_notify.php';
    try {
        sendSurgeryNotification($pdo, $newId);
    } catch (Throwable $e) {
        error_log('[Email notify error] ' . $e->getMessage());
    }

    $pdo->commit();

    echo "<script>
        alert('Cirugía agendada exitosamente.');
        window.location.href='ver_programacion.php';
    </script>";
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<div class='alert alert-danger m-3'>Error: " . h($e->getMessage()) . "</div>";
}