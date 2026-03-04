<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/ai_helper.php';

// Conexión (usa mismas credenciales que el sistema actual)
$host = 'localhost';
$dbname = 'sitiosnuevos_hospital';
$username = 'sitiosnuevos_cirtugia';
$password = 'Realmedic2020';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'DB','detail'=>$e->getMessage()]); exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$texto = trim($input['texto'] ?? '');
$fecha = trim($input['fecha'] ?? '');
$hab   = trim($input['habitacion'] ?? ($input['habitación'] ?? ''));
$ini   = trim($input['hora_inicio'] ?? '');
$fin   = trim($input['hora_fin'] ?? '');

$sugerencias = [
    'ok' => true,
    'via' => 'heuristica',
    'campos' => [],
    'mensajes' => []
];

// 1) Intentar con LLM si está habilitado (opcional)
if (ai_is_enabled()) {
    $prompt = "Extrae JSON con posibles campos para programar una cirugía:\n\nTexto:\n\"$texto\"\n\nDevuelve un objeto con claves posibles: paciente, edad, procedimiento, cirujano, habitación, fecha, hora_inicio, hora_fin.";
    
// 1) Intentar con LLM JSON si está habilitado (opcional, robusto)
if (ai_is_enabled()) {
    $schema = [
        "type"=>"object",
        "properties"=>[
            "paciente"=>["type"=>"string"],
            "edad"=>["type"=>"integer"],
            "procedimiento"=>["type"=>"string"],
            "cirujano"=>["type"=>"string"],
            "cirujano_id"=>["type"=>"integer"],
            "habitación"=>["type"=>"string"],
            "fecha"=>["type"=>"string"],
            "hora_inicio"=>["type"=>"string"],
            "hora_fin"=>["type"=>"string"]
        ]
    ];
    $system = "Eres un asistente clínico. Devuelve SOLO un objeto JSON válido, sin texto adicional, con las claves pedidas.";
    $user   = "A partir del siguiente texto, devuelve SOLO JSON con claves {paciente, edad, procedimiento, cirujano, cirujano_id, habitación, fecha, hora_inicio, hora_fin}. No incluyas explicaciones. Texto: <<".$texto.">>";
    $obj = ai_call_llm_json($system, $user, $schema);
    if (is_array($obj)) {
        $sugerencias['via'] = 'openai_json';
        $sugerencias['campos'] = array_merge($sugerencias['campos'], $obj);
    }
}

}

// 2) Fallback heurístico: similitud de cirujano y procedimiento con catálogos
$procTxt = $input['procedimiento'] ?? ($sugerencias['campos']['procedimiento'] ?? '');
$cirTxt  = $input['cirujano'] ?? ($sugerencias['campos']['cirujano'] ?? '');

try {
    // Cirujanos
    $cirOpts = [];
try { $cirOpts = ai_fetch_options($pdo, "SELECT id, nombre FROM cirujanos WHERE 1=1", 'nombre'); } catch (Throwable $e) { $cirOpts = []; }
if ($cirTxt && $cirOpts) {
    $bestCir = ai_best_match($cirTxt, array_map(function($o){ return ['id'=>$o['id'], 'text'=>$o['text']]; }, $cirOpts));
    if ($bestCir) $sugerencias['campos']['cirujano_id'] = $bestCir['id'];
}, $cirOpts));
        if ($bestCir) $sugerencias['campos']['cirujano_id'] = $bestCir['id'];
    }

    // Procedimientos (si hay tabla). Si no, buscar últimos distintos
    $procOpts = [];
try { $procOpts = ai_fetch_options($pdo, "SELECT MIN(id) AS id, procedimiento AS nombre FROM programacion_quirofano GROUP BY procedimiento ORDER BY COUNT(*) DESC LIMIT 200", 'nombre'); } catch (Throwable $e) { $procOpts = []; }
if ($procTxt && $procOpts) {
    $bestProc = ai_best_match($procTxt, array_map(function($o){ return ['id'=>$o['id'], 'text'=>$o['text']]; }, $procOpts));
    if ($bestProc) $sugerencias['campos']['procedimiento'] = $bestProc['text'];
}, $procOpts));
        if ($bestProc) $sugerencias['campos']['procedimiento'] = $bestProc['text'];
    }

    // Duración sugerida
    if (!empty($sugerencias['campos']['procedimiento'])) {
        $mediana = ai_estimar_duracion($pdo, $sugerencias['campos']['procedimiento']);
        if ($mediana) $sugerencias['campos']['duracion_min_estimada'] = $mediana;
    }

    // Riesgo
    if ($texto) {
        $sugerencias['campos']['riesgo_estimado'] = ai_riesgo_texto($texto);
    }

    // Conflictos
    if ($fecha && $hab && $ini && $fin) {
        $conf = ai_detect_overlap($pdo, $fecha, $hab, $ini, $fin);
        $sugerencias['conflictos'] = $conf;
        if ($conf['count'] > 0) $sugerencias['mensajes'][] = "⚠️ " . implode(' | ', $conf['messages']);
    }

    ai_log('ai_suggest', ['input'=>$input, 'sugerencias'=>$sugerencias]);
    echo json_encode($sugerencias, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    ai_log('ai_error', ['err'=>$e->getMessage()]);
    echo json_encode(['ok'=>false,'error'=>'AI','detail'=>$e->getMessage()]);
}
