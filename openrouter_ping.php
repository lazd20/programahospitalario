<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/ai_helper.php';

// Forzar heurística con ?noai=1 para evitar llamadas a OpenAI si hay dudas
$forceNoAI = isset($_GET['noai']) && $_GET['noai'] === '1';

// Conexión DB
$host = 'localhost';
$dbname = 'sitiosnuevos_hospital';
$username = 'sitiosnuevos_cirtugia';
$password = 'Realmedic2020';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'stage'=>'db_connect','detail'=>$e->getMessage()]); exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$texto = trim($input['texto'] ?? '');
$fecha = trim($input['fecha'] ?? '');
$hab   = trim($input['habitacion'] ?? ($input['habitación'] ?? ''));
$ini   = trim($input['hora_inicio'] ?? '');
$fin   = trim($input['hora_fin'] ?? '');

$out = ['ok'=>true, 'via'=>'safe', 'campos'=>[], 'mensajes'=>[]];

try {
    // IA JSON (solo si habilitada y no forzado noai)
    if (!$forceNoAI && ai_is_enabled()) {
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
        if (is_array($obj) && empty($obj['error'])) {
            $out['via'] = 'openai_json';
            $out['campos'] = array_merge($out['campos'], $obj);
        } else {
            $why = is_array($obj) && !empty($obj['error']) ? $obj['error'] : 'sin_detalle';
            $out['mensajes'][] = 'OpenAI no respondió; usando heurística. Motivo: ' . $why;
        }
    }

    // Heurística: cirujano y procedimiento
    $cirTxt  = $input['cirujano'] ?? ($out['campos']['cirujano'] ?? '');
    $procTxt = $input['procedimiento'] ?? ($out['campos']['procedimiento'] ?? '');

    $cirOpts = [];
    try { $cirOpts = ai_fetch_options($pdo, "SELECT id, nombre FROM cirujanos WHERE 1=1", 'nombre'); } catch (Throwable $e) {}
    if ($cirTxt && $cirOpts) {
        $bestCir = ai_best_match($cirTxt, array_map(function($o){ return ['id'=>$o['id'], 'text'=>$o['text']]; }, $cirOpts));
        if ($bestCir) $out['campos']['cirujano_id'] = $bestCir['id'];
    }

    $procOpts = [];
    try { $procOpts = ai_fetch_options($pdo, "SELECT MIN(id) AS id, procedimiento AS nombre FROM programacion_quirofano GROUP BY procedimiento ORDER BY COUNT(*) DESC LIMIT 200", 'nombre'); } catch (Throwable $e) {}
    if ($procTxt && $procOpts) {
        $bestProc = ai_best_match($procTxt, array_map(function($o){ return ['id'=>$o['id'], 'text'=>$o['text']]; }, $procOpts));
        if ($bestProc) $out['campos']['procedimiento'] = $bestProc['text'];
    }

    if (!empty($out['campos']['procedimiento'])) {
        $med = ai_estimar_duracion($pdo, $out['campos']['procedimiento']);
        if ($med) $out['campos']['duracion_min_estimada'] = $med;
    }
    if ($texto) $out['campos']['riesgo_estimado'] = ai_riesgo_texto($texto);

    if ($fecha && $hab && $ini) {
        $conf = ai_detect_overlap($pdo, $fecha, $hab, $ini, $fin ?: date('H:i', strtotime($ini.' + 2 hours')));
        $out['conflictos'] = $conf;
        if ($conf['count'] > 0) $out['mensajes'][] = "⚠️ " . implode(' | ', $conf['messages']);
    }

    ai_log('ai_safe', ['input'=>$input, 'out'=>$out]);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    ai_log('ai_safe_error', ['err'=>$e->getMessage()]);
    echo json_encode(['ok'=>false,'stage'=>'catch_all','detail'=>$e->getMessage()]);
}
