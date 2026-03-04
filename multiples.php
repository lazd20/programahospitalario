<?php
// /public_html/evoprx/programacion/analisis_backend.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("No existe \$pdo. Revisa helpers.php / conexión.");
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->query("SET lc_time_names = 'es_ES'");

} catch (Throwable $e) {
    echo json_encode(["error" => "Error de conexión/config: " . $e->getMessage()]);
    exit;
}

$tipo        = $_GET['tipo'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin    = $_GET['fecha_fin'] ?? '';
$mes          = $_GET['mes'] ?? '';
$anio         = $_GET['anio'] ?? '';

/* ======================================================
   HELPERS: TABLAS / COLUMNAS (prefijo hosp_)
   ====================================================== */
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

function columnsOf(PDO $pdo, string $table): array {
    $st = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $st->execute([$table]);
    return array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
}

function pickCol(array $realColsLower, array $cands): ?string {
    foreach ($cands as $c) {
        if (in_array(strtolower($c), $realColsLower, true)) return $c;
    }
    return null;
}

// Detectar tablas (con/sin hosp_)
$T_PROG = pickTable($pdo, ['hosp_programacion_quirofano', 'programacion_quirofano']);
$T_CIR  = pickTable($pdo, ['hosp_cirujanos', 'cirujanos']);

if (!$T_PROG) {
    echo json_encode(["error" => "No se encontró tabla de programación (hosp_programacion_quirofano / programacion_quirofano)."]);
    exit;
}
if (!$T_CIR) {
    echo json_encode(["error" => "No se encontró tabla de cirujanos (hosp_cirujanos / cirujanos)."]);
    exit;
}

// Detectar columnas clave
$progCols = columnsOf($pdo, $T_PROG);
$cirCols  = columnsOf($pdo, $T_CIR);

$COL_PROG_ID      = pickCol($progCols, ['id', 'ID']);
$COL_PROG_FECHA   = pickCol($progCols, ['fecha', 'FECHA']);
$COL_PROG_HORA    = pickCol($progCols, ['h_cirugia', 'hora', 'hora_cirugia', 'H_CIRUGIA']);
$COL_PROG_PROC    = pickCol($progCols, ['procedimiento', 'PROCEDIMIENTO', 'proced', 'proc']);
$COL_PROG_CIRID   = pickCol($progCols, ['cirujano_id', 'id_cirujano', 'cirujano', 'CIRUJANO_ID']);

$COL_CIR_ID       = pickCol($cirCols, ['id', 'ID']);
$COL_CIR_NOMBRE   = pickCol($cirCols, ['nombre', 'NOMBRE', 'name']);

$missing = [];
if (!$COL_PROG_ID)    $missing[] = "programación.id";
if (!$COL_PROG_FECHA) $missing[] = "programación.fecha";
if (!$COL_PROG_PROC)  $missing[] = "programación.procedimiento";
if (!$COL_PROG_CIRID) $missing[] = "programación.cirujano_id";
if (!$COL_CIR_ID)     $missing[] = "cirujanos.id";
if (!$COL_CIR_NOMBRE) $missing[] = "cirujanos.nombre";

if ($missing) {
    echo json_encode([
        "error" => "Faltan columnas esperadas en BD: " . implode(', ', $missing)
    ]);
    exit;
}

// Para query de horarios: si no existe columna de hora, devolvemos error SOLO cuando se pida ese tipo
$HAS_HORA = (bool)$COL_PROG_HORA;

/* ======================================================
   HELPERS: NORMALIZACIÓN (para unificar doctores/procedimientos)
   ====================================================== */
function normalize_text($str) {
    $str = (string)$str;
    $str = trim($str);
    if ($str === '') return '';

    if (function_exists('iconv')) {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        if ($tmp !== false) $str = $tmp;
    }

    $str = strtoupper($str);
    $str = preg_replace('/[^A-Z0-9\s]/', ' ', $str);
    $str = preg_replace('/\s+/', ' ', $str);
    return trim($str);
}

function normalize_doctor($name) {
    $n = normalize_text($name);
    $n = preg_replace('/^(DR|DRA|DOCTOR|DOCTORA)\s+/', '', $n);
    $n = preg_replace('/\s+/', ' ', $n);
    return trim($n);
}

function normalize_procedure($proc) {
    $p = normalize_text($proc);
    $p = preg_replace('/\s+/', ' ', $p);
    return trim($p);
}

function classify_procedure($p_norm) {
    $p = " " . $p_norm . " ";

    $sub = 'Otros';
    $esp = 'Otros';

    // Cirugía General
    if (preg_match('/\bCOLE\b|\bCOLECIST\b|\bCOLELAP\b|\bCOLELAPAR\b/', $p)) return ['Cirugía General','Colelap / Colecistectomía'];
    if (preg_match('/\bAPENDI\b|\bAPENDIC\b|\bAPENDILAP\b|\bAPENDILAPAR\b/', $p)) return ['Cirugía General','Apendilap / Apendicectomía'];
    if (preg_match('/\bHERNIA\b|\bHERNI\b/', $p)) return ['Cirugía General','Hernias'];
    if (preg_match('/\bLAPAR\b|\bLAPAROS\b|\bLAPAROSCOP\b/', $p)) return ['Cirugía General','Laparoscopía (otros)'];

    // Plástica
    if (preg_match('/\bABDOMINOPLAST\b|\bABDOMINO\b/', $p)) return ['Cirugía Plástica','Abdominoplastia'];
    if (preg_match('/\bLIPO\b|\bLIPOSUCC\b|\bLIPOESC\b/', $p)) return ['Cirugía Plástica','Liposucción / Lipoescultura'];
    if (preg_match('/\bMAMOPLAST\b|\bMASTOPEX\b|\bAUMENTO MAM\b|\bREDUCCION MAM\b/', $p)) return ['Cirugía Plástica','Cirugía mamaria'];
    if (preg_match('/\bRINOPLAST\b|\bRINO\b/', $p)) return ['Cirugía Plástica','Rinoplastia'];

    // Trauma
    if (preg_match('/\bFRACTUR\b|\bOSTEOSINT\b|\bPLACAS\b|\bTORNILLOS\b/', $p)) return ['Traumatología','Fracturas / Osteosíntesis'];
    if (preg_match('/\bCOLUMNA\b|\bCERVICAL\b|\bLUMBAR\b|\bDORSAL\b|\bDISCO\b/', $p)) return ['Traumatología','Columna'];
    if (preg_match('/\bRODILLA\b|\bMENISCO\b|\bLCA\b|\bLCP\b|\bARTROSCOP\b/', $p)) return ['Traumatología','Rodilla / Artroscopia'];
    if (preg_match('/\bCADERA\b|\bCOTILO\b|\bPROTESIS\b/', $p)) return ['Traumatología','Cadera / Prótesis'];

    return [$esp, $sub];
}

/* ======================================================
   COMPARADOR DE CIRUJANOS
   ====================================================== */
if ($tipo === 'comparar_cirujanos') {
    $mes_a  = $_GET['mes_a'] ?? '';
    $anio_a = $_GET['anio_a'] ?? '';
    $mes_b  = $_GET['mes_b'] ?? '';
    $anio_b = $_GET['anio_b'] ?? '';

    if (!$mes_a || !$anio_a || !$mes_b || !$anio_b) {
        echo json_encode(["error" => "Faltan parámetros para la comparación"]);
        exit;
    }

    $sqlA = "SELECT DISTINCT c.`{$COL_CIR_NOMBRE}` AS nombre
             FROM `{$T_PROG}` pq
             JOIN `{$T_CIR}` c ON pq.`{$COL_PROG_CIRID}` = c.`{$COL_CIR_ID}`
             WHERE MONTH(pq.`{$COL_PROG_FECHA}`) = ? AND YEAR(pq.`{$COL_PROG_FECHA}`) = ?";
    $stA = $pdo->prepare($sqlA);
    $stA->execute([$mes_a, $anio_a]);
    $cirujanosA = $stA->fetchAll(PDO::FETCH_COLUMN);

    $sqlB = "SELECT DISTINCT c.`{$COL_CIR_NOMBRE}` AS nombre
             FROM `{$T_PROG}` pq
             JOIN `{$T_CIR}` c ON pq.`{$COL_PROG_CIRID}` = c.`{$COL_CIR_ID}`
             WHERE MONTH(pq.`{$COL_PROG_FECHA}`) = ? AND YEAR(pq.`{$COL_PROG_FECHA}`) = ?";
    $stB = $pdo->prepare($sqlB);
    $stB->execute([$mes_b, $anio_b]);
    $cirujanosB = $stB->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        "solo_a" => array_values(array_diff($cirujanosA, $cirujanosB)),
        "solo_b" => array_values(array_diff($cirujanosB, $cirujanosA)),
    ]);
    exit;
}

/* ======================================================
   VALIDACIONES
   ====================================================== */
if ($tipo === 'cirugias_por_mes' && !$anio) {
    echo json_encode(["error" => "Debe seleccionar un año para ver Cirugías por Mes"]);
    exit;
} elseif ($tipo !== 'cirugias_por_mes' && $tipo !== 'analisis_procedimientos_categorizados'
          && (!$fecha_inicio || !$fecha_fin) && (!$mes || !$anio)) {
    echo json_encode(["error" => "Debe seleccionar un rango de fechas o un mes y año"]);
    exit;
}

/* ======================================================
   WHERE DINÁMICO (usa columna fecha detectada)
   ====================================================== */
$whereClause = "1";
$params = [];

if ($tipo === 'cirugias_por_mes') {
    $anio_int = (int)$anio;
    $anio_actual = (int)date('Y');
    $mes_actual  = (int)date('n');

    if ($anio_int < $anio_actual) {
        $whereClause = "YEAR(`{$COL_PROG_FECHA}`) = ?";
        $params = [$anio_int];
    } else {
        $whereClause = "YEAR(`{$COL_PROG_FECHA}`) = ? AND MONTH(`{$COL_PROG_FECHA}`) <= ?";
        $params = [$anio_int, $mes_actual];
    }
} else {
    if ($fecha_inicio && $fecha_fin) {
        $whereClause = "`{$COL_PROG_FECHA}` BETWEEN ? AND ?";
        $params = [$fecha_inicio, $fecha_fin];
    } elseif ($mes && $anio) {
        $whereClause = "MONTH(`{$COL_PROG_FECHA}`) = ? AND YEAR(`{$COL_PROG_FECHA}`) = ?";
        $params = [$mes, $anio];
    }
}

/* ======================================================
   CONSULTAS SEGÚN TIPO (tablas con prefijo hosp_)
   ====================================================== */
switch ($tipo) {

    case 'top_cirujanos':
        $sql = "SELECT c.`{$COL_CIR_NOMBRE}` AS cirujano, COUNT(pq.`{$COL_PROG_ID}`) AS total
                FROM `{$T_PROG}` pq
                JOIN `{$T_CIR}` c ON pq.`{$COL_PROG_CIRID}` = c.`{$COL_CIR_ID}`
                WHERE $whereClause
                GROUP BY c.`{$COL_CIR_NOMBRE}`
                ORDER BY total DESC";
        break;

    case 'horarios':
        if (!$HAS_HORA) {
            echo json_encode(["error" => "La tabla de programación no tiene columna de hora (h_cirugia/hora)."]);
            exit;
        }
        $sql = "SELECT DATE_FORMAT(`{$COL_PROG_HORA}`, '%h:%i %p') AS hora, COUNT(*) AS total
                FROM `{$T_PROG}`
                WHERE $whereClause
                GROUP BY HOUR(`{$COL_PROG_HORA}`)
                ORDER BY HOUR(`{$COL_PROG_HORA}`)";
        break;

    case 'dias_movidos':
        $sql = "SELECT CASE 
                    WHEN DAYOFWEEK(`{$COL_PROG_FECHA}`) = 1 THEN 'Domingo'
                    WHEN DAYOFWEEK(`{$COL_PROG_FECHA}`) = 2 THEN 'Lunes'
                    WHEN DAYOFWEEK(`{$COL_PROG_FECHA}`) = 3 THEN 'Martes'
                    WHEN DAYOFWEEK(`{$COL_PROG_FECHA}`) = 4 THEN 'Miércoles'
                    WHEN DAYOFWEEK(`{$COL_PROG_FECHA}`) = 5 THEN 'Jueves'
                    WHEN DAYOFWEEK(`{$COL_PROG_FECHA}`) = 6 THEN 'Viernes'
                    WHEN DAYOFWEEK(`{$COL_PROG_FECHA}`) = 7 THEN 'Sábado'
                END AS dia,
                COUNT(*) AS total
                FROM `{$T_PROG}`
                WHERE $whereClause
                GROUP BY DAYOFWEEK(`{$COL_PROG_FECHA}`)
                ORDER BY total DESC";
        break;

    case 'cirujanos_mes':
    case 'listado_cirujanos':
        $sql = "SELECT c.`{$COL_CIR_NOMBRE}` AS cirujano, COUNT(pq.`{$COL_PROG_ID}`) AS total
                FROM `{$T_PROG}` pq
                JOIN `{$T_CIR}` c ON pq.`{$COL_PROG_CIRID}` = c.`{$COL_CIR_ID}`
                WHERE $whereClause
                GROUP BY c.`{$COL_CIR_NOMBRE}`
                ORDER BY total DESC";
        break;

    case 'analisis_procedimientos':
        $sql = "SELECT LOWER(TRIM(`{$COL_PROG_PROC}`)) AS procedimiento, COUNT(*) AS total
                FROM `{$T_PROG}`
                WHERE $whereClause
                GROUP BY LOWER(TRIM(`{$COL_PROG_PROC}`))
                ORDER BY total DESC";
        break;

    case 'analisis_procedimientos_categorizados':
        $sql = "SELECT LOWER(TRIM(`{$COL_PROG_PROC}`)) AS procedimiento, COUNT(*) AS total
                FROM `{$T_PROG}`
                WHERE $whereClause
                GROUP BY LOWER(TRIM(`{$COL_PROG_PROC}`))
                ORDER BY total DESC";
        break;

    case 'cirugias_por_mes':
        $sql = "SELECT 
                    CASE MONTH(`{$COL_PROG_FECHA}`)
                        WHEN 1 THEN 'Enero'
                        WHEN 2 THEN 'Febrero'
                        WHEN 3 THEN 'Marzo'
                        WHEN 4 THEN 'Abril'
                        WHEN 5 THEN 'Mayo'
                        WHEN 6 THEN 'Junio'
                        WHEN 7 THEN 'Julio'
                        WHEN 8 THEN 'Agosto'
                        WHEN 9 THEN 'Septiembre'
                        WHEN 10 THEN 'Octubre'
                        WHEN 11 THEN 'Noviembre'
                        WHEN 12 THEN 'Diciembre'
                    END AS mes,
                    COUNT(*) AS total
                FROM `{$T_PROG}`
                WHERE $whereClause
                GROUP BY MONTH(`{$COL_PROG_FECHA}`)
                ORDER BY MONTH(`{$COL_PROG_FECHA}`)";
        break;

    case 'cirugias_diarias':
        $sql = "SELECT DATE_FORMAT(`{$COL_PROG_FECHA}`, '%a %d-%b') AS dia, COUNT(*) AS total
                FROM `{$T_PROG}`
                WHERE $whereClause
                GROUP BY DATE(`{$COL_PROG_FECHA}`)
                ORDER BY DATE(`{$COL_PROG_FECHA}`)";
        break;

    case 'cirugias_por_semana':
        $sql = "SELECT CONCAT('Semana ', WEEK(`{$COL_PROG_FECHA}`)) AS semana, COUNT(*) AS total
                FROM `{$T_PROG}`
                WHERE $whereClause
                GROUP BY WEEK(`{$COL_PROG_FECHA}`)
                ORDER BY WEEK(`{$COL_PROG_FECHA}`)";
        break;

    default:
        echo json_encode(["error" => "Tipo de análisis no válido"]);
        exit;
}

/* ======================================================
   EJECUTAR CONSULTA
   ====================================================== */
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ======================================================
   UNIFICACIÓN DE DOCTORES (SIN ADIVINAR)
   ====================================================== */
if (in_array($tipo, ['top_cirujanos', 'cirujanos_mes', 'listado_cirujanos'], true)) {

    $map = [];
    $displayCount = [];

    foreach ($resultados as $row) {
        $raw = (string)($row['cirujano'] ?? '');
        $key = normalize_doctor($raw);
        if ($key === '') $key = 'SIN NOMBRE';

        $cnt = (int)($row['total'] ?? 0);
        $map[$key] = ($map[$key] ?? 0) + $cnt;

        $d = trim($raw) !== '' ? trim($raw) : 'SIN NOMBRE';
        $displayCount[$key][$d] = ($displayCount[$key][$d] ?? 0) + $cnt;
    }

    $display = [];
    foreach ($displayCount as $key => $options) {
        arsort($options);
        $display[$key] = array_key_first($options);
    }

    $out = [];
    foreach ($map as $key => $total) {
        $out[] = ['cirujano' => $display[$key] ?? $key, 'total' => (int)$total];
    }

    usort($out, fn($a,$b) => $b['total'] <=> $a['total']);

    if ($tipo === 'top_cirujanos') $out = array_slice($out, 0, 5);

    $labels = [];
    $data = [];
    $totalAll = 0;

    foreach ($out as $r) {
        $labels[] = $r['cirujano'];
        $data[] = (int)$r['total'];
        $totalAll += (int)$r['total'];
    }

    echo json_encode(["labels"=>$labels,"data"=>$data,"total"=>$totalAll]);
    exit;
}

/* ======================================================
   PROCEDIMIENTOS CATEGORIZADOS
   ====================================================== */
if ($tipo === 'analisis_procedimientos_categorizados') {

    $especialidades = [];
    $subgrupos = [];

    foreach ($resultados as $row) {
        $rawProc = (string)($row['procedimiento'] ?? '');
        $pNorm = normalize_procedure($rawProc);
        $cnt = (int)($row['total'] ?? 0);

        [$esp, $sub] = classify_procedure($pNorm);

        $especialidades[$esp] = ($especialidades[$esp] ?? 0) + $cnt;
        $keySub = $esp . '||' . $sub;
        $subgrupos[$keySub] = ($subgrupos[$keySub] ?? 0) + $cnt;
    }

    arsort($especialidades);
    arsort($subgrupos);

    $rankingEspecialidades = [];
    foreach ($especialidades as $esp => $tot) {
        $rankingEspecialidades[] = ['especialidad' => $esp, 'total' => (int)$tot];
    }

    $rankingSubgrupos = [];
    foreach ($subgrupos as $k => $tot) {
        [$esp, $sub] = explode('||', $k, 2);
        $rankingSubgrupos[] = ['especialidad'=>$esp,'subgrupo'=>$sub,'total'=>(int)$tot];
    }

    echo json_encode([
        "ranking_especialidades" => $rankingEspecialidades,
        "ranking_subgrupos" => $rankingSubgrupos
    ]);
    exit;
}

/* ======================================================
   RESPUESTA ESTÁNDAR
   ====================================================== */
$labels = [];
$data = [];
$total = 0;

foreach ($resultados as $row) {
    $firstKey = array_keys($row)[0];
    $labels[] = $row[$firstKey];
    $data[] = (int)$row['total'];
    $total += (int)$row['total'];
}

$response = ["labels"=>$labels,"data"=>$data,"total"=>$total];

if ($tipo === 'cirugias_diarias') {
    $response["tabla"] = $resultados;
}

echo json_encode($response);