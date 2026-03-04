<?php
/**
 * ai_helper.php
 * Utilidades "IA" no rompedoras con fallback seguro.
 * - No cambia la lógica de producción a menos que AI_ENABLED=true (env) o ?ai=1 en URL.
 * - Soporta sugerencias por similitud (Levenshtein/SOUNDEX) sin APIs externas.
 * - Si OPENAI_API_KEY está definido en el entorno, puede usar LLM (opcional).
 */


if (!function_exists('ai_getenv')) {
    function ai_getenv(string $key): ?string {
        // 1) getenv()
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        // 2) $_SERVER / $_ENV
        if (!empty($_SERVER[$key])) return $_SERVER[$key];
        if (!empty($_ENV[$key])) return $_ENV[$key];
        // 3) .env fallback (same dir)
        static $loaded = false;
        if (!$loaded) {
            $loaded = true;
            $envFile = __DIR__ . '/.env';
            if (is_file($envFile)) {
                $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    if ($line[0] === '#') continue;
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $k = trim($parts[0]); $val = trim($parts[1]);
                        if ($k !== '') { putenv("$k=$val"); $_SERVER[$k] = $val; $_ENV[$k] = $val; }
                    }
                }
                $v = getenv($key);
                if ($v !== false && $v !== '') return $v;
            }
        }
        return null;
    }
}
if (!function_exists('ai_is_enabled')) {
    function ai_is_enabled(): bool {
        if (isset($_GET['ai']) && $_GET['ai'] === '1') return true;
        $flag = ai_getenv('AI_ENABLED');
        return $flag && in_array(strtolower($flag), ['1','true','yes'], true);
    }
}

if (!function_exists('ai_log')) {
    function ai_log(string $event, array $payload = []): void {
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $line = date('c') . " | $event | " . json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        @file_put_contents($dir . '/ai_suggest.log', $line, FILE_APPEND);
    }
}

/**
 * Llamada opcional a OpenAI si hay API Key; devuelve string o null.
 */
if (!function_exists('ai_call_openai')) {
    function ai_call_openai(string $prompt, int $maxTokens = 256): ?string {
        $apiKey = ai_getenv('OPENAI_API_KEY');
        if (!$apiKey) return null; // Fallback silencioso

        // Llamada simple a Responses API (gpt-4o-mini / gpt-4.1). Evitar dependencias externas.
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        $payload = json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'Eres un asistente que sugiere campos para programar cirugías en una clínica. Devuelve JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
            'max_tokens' => $maxTokens
        ]);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $res = curl_exec($ch);
        if (curl_errno($ch)) { curl_close($ch); return null; }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && $res) {
            $data = json_decode($res, true);
            $txt  = $data['choices'][0]['message']['content'] ?? null;
            return $txt ?: null;
        }
        return null;
    }
}

/**
 * Sugerir por similitud desde tablas existentes (sin IA externa).
 */
if (!function_exists('ai_fetch_options')) {
    function ai_fetch_options(PDO $pdo, string $sql, string $termColumn = 'nombre'): array {
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => $r['id'] ?? null,
                'text' => $r[$termColumn] ?? null
            ];
        }
        return $out;
    }
}

if (!function_exists('ai_best_match')) {
    function ai_best_match(string $input, array $options, string $field = 'text'): ?array {
        $inputN = mb_strtolower(trim($input));
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($options as $opt) {
            $txt = mb_strtolower(trim($opt[$field] ?? ''));
            if ($txt === '') continue;
            // Levenshtein score (menor es mejor)
            $lev = levenshtein($inputN, $txt);
            $sound = (soundex($inputN) === soundex($txt)) ? -2 : 0; // pequeño bonus si suena igual
            $score = $lev + $sound;
            if ($score < $bestScore) { $bestScore = $score; $best = $opt; }
        }
        return $best;
    }
}

/**
 * Detectar solapes simples y devolver explicaciones legibles.
 */
if (!function_exists('ai_detect_overlap')) {
    function ai_detect_overlap(PDO $pdo, string $fecha, string $habitacion, string $horaIni, ?string $horaFin): array {
        $toMin = function(string $hhmm): int {
            if ($hhmm === '') return 0;
            $parts = explode(':', $hhmm);
            $h = intval($parts[0] ?? 0);
            $m = intval($parts[1] ?? 0);
            return $h*60 + $m;
        };
        $iniMin = $toMin($horaIni);
        $finMin = $horaFin ? $toMin($horaFin) : ($iniMin + 120);

        $sql = "SELECT id, paciente, fecha, habitacion, h_cirugia, procedimiento 
                FROM programacion_quirofano
                WHERE fecha = :fecha AND habitacion = :hab";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':fecha'=>$fecha, ':hab'=>$habitacion]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $conflicts = [];
        $explain = [];
        foreach ($rows as $r) {
            $start = $toMin($r['h_cirugia'] ?? '00:00');
            $end   = $start + 120;
            $overlap = !($end <= $iniMin || $start >= $finMin);
            if ($overlap) {
                $conflicts[] = $r;
                $explain[] = sprintf(
                    "Solape con #%d (%s) %s–%s por %s",
                    $r['id'],
                    $r['paciente'],
                    $r['h_cirugia'],
                    sprintf('%02d:%02d', intdiv($end,60), $end%60),
                    $r['procedimiento']
                );
            }
        }

        return ['count'=>count($conflicts), 'conflicts'=>$conflicts, 'messages'=>$explain];
    }
}/**
 * Estimar duración típica de un procedimiento en minutos.
 */
if (!function_exists('ai_estimar_duracion')) {
    function ai_estimar_duracion(PDO $pdo, string $procedimiento): ?int {
        $t = mb_strtolower($procedimiento);
        $map = [
            'artroscopia' => 90,
            'colecistectom' => 75,
            'hernia' => 60,
            'cesarea' => 45,
            'apendic' => 60,
            'rodilla' => 90,
            'cadera' => 120,
            'columna' => 150,
        ];
        foreach ($map as $kw => $min) {
            if (mb_strpos($t, $kw) !== false) return $min;
        }
        return 120;
    }
}/**
 * Resumen de riesgo (heurística keywords).
 */
if (!function_exists('ai_riesgo_texto')) {
    function ai_riesgo_texto(string $texto): string {
        $t = mb_strtolower($texto);
        $score = 0;
        foreach (['emergencia','sangrado','shock','politrauma','sepsis'] as $kw) if (mb_strpos($t,$kw)!==false) $score += 2;
        foreach (['prioridad','urgente','inestable'] as $kw) if (mb_strpos($t,$kw)!==false) $score += 1;
        if ($score >= 3) return 'ALTO';
        if ($score == 2) return 'MEDIO';
        return 'BAJO';
    }
}


/**
 * Llamada a OpenAI en **modo JSON** para extraer campos estructurados.
 */
if (!function_exists('ai_call_openai_json')) {
    function ai_call_openai_json(string $systemPrompt, string $userPrompt, array $jsonSchema, int $timeout = 10): ?array {
        $apiKey = ai_getenv('OPENAI_API_KEY');
        if (!$apiKey) return null;
        $url = 'https://api.openai.com/v1/chat/completions';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role'=>'system','content'=>$systemPrompt],
                ['role'=>'user','content'=>$userPrompt],
            ],
            'temperature' => 0.1,
            'response_format' => ['type'=>'json_object'],
            'max_tokens' => 400,
        ];
        $tries = 0;
        $lastErr = null;
        while ($tries < 2) {
            $tries++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
            ]);
            $res = curl_exec($ch);
            if (curl_errno($ch)) { $lastErr = curl_error($ch); curl_close($ch); continue; }
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300 && $res) {
                $data = json_decode($res, true);
                $txt  = $data['choices'][0]['message']['content'] ?? null;
                if ($txt) {
                    $obj = json_decode($txt, true);
                    if (is_array($obj)) return $obj;
                    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $txt, $m)) {
                        $obj2 = json_decode($m[0], true);
                        if (is_array($obj2)) return $obj2;
                    }
                }
            } else {
                $lastErr = "HTTP $code";
            }
        }
        ai_log('openai_json_fail', ['err'=>$lastErr]);
        return ['error'=>$lastErr];
    }
}


/**
 * Proveedor LLM conmutables: OpenAI u OpenRouter (gratis).
 * Selección por variable de entorno AI_PROVIDER = "openrouter" | "openai" (default: openai)
 * API Keys:
 *   - OPENROUTER_API_KEY para OpenRouter
 *   - OPENAI_API_KEY    para OpenAI
 */
if (!function_exists('ai_call_llm_json')) {
    function ai_call_llm_json(string $systemPrompt, string $userPrompt, array $jsonSchema = [], int $timeout = 12): ?array {
        $provider = strtolower((string) (ai_getenv('AI_PROVIDER') ?: 'openai'));
        $headers = ['Content-Type: application/json'];
        $payload = [
            'messages' => [
                ['role'=>'system','content'=>$systemPrompt],
                ['role'=>'user','content'=>$userPrompt],
            ],
            'temperature' => 0.2,
            'max_tokens' => 400,
        ];

        if (!empty($jsonSchema)) {
            // Para ambos proveedores pedimos JSON "estricto"
            $payload['response_format'] = ['type'=>'json_object'];
        }

        if ($provider === 'openrouter') {
            $apiKey = ai_getenv('OPENROUTER_API_KEY');
            if (!$apiKey) { ai_log('llm_fail', ['err'=>'no_openrouter_key']); return ['error'=>'no_openrouter_key']; }
            $url = 'https://openrouter.ai/api/v1/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            // Modelo por defecto (ligero y gratuito)
            $payload['model'] = (string) (ai_getenv('OPENROUTER_MODEL') ?: 'mistralai/mistral-7b-instruct');
            // Encabezados opcionales recomendados por OpenRouter
            $ref = ai_getenv('APP_URL') ?: '';
            $title = ai_getenv('APP_NAME') ?: 'Realmedic IA';
            if ($ref) { $headers[] = 'HTTP-Referer: ' . $ref; }
            if ($title) { $headers[] = 'X-Title: ' . $title; }
        } else {
            // OpenAI por defecto
            $apiKey = ai_getenv('OPENAI_API_KEY');
            if (!$apiKey) { ai_log('llm_fail', ['err'=>'no_openai_key']); return ['error'=>'no_openai_key']; }
            $url = 'https://api.openai.com/v1/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            $payload['model'] = (string) (ai_getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');
        }

        $tries = 0;
        $lastErr = null;
        while ($tries < 2) {
            $tries++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
            ]);
            $res = curl_exec($ch);
            if (curl_errno($ch)) { $lastErr = curl_error($ch); curl_close($ch); continue; }
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300 && $res) {
                $data = json_decode($res, true);
                $txt  = $data['choices'][0]['message']['content'] ?? null;
                if ($txt) {
                    $obj = json_decode($txt, true);
                    if (is_array($obj)) return $obj;
                    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $txt, $m)) {
                        $obj2 = json_decode($m[0], true);
                        if (is_array($obj2)) return $obj2;
                    }
                }
                // Si el proveedor devuelve directamente un objeto (algunos proxys lo hacen)
                if (isset($data['choices'][0]['message']['parsed'])) {
                    $obj = $data['choices'][0]['message']['parsed'];
                    if (is_array($obj)) return $obj;
                }
                $lastErr = 'invalid_json_response';
            } else {
                $lastErr = 'HTTP ' . $code . ' ' . substr((string)$res, 0, 200);
            }
        }
        ai_log('llm_fail', ['provider'=>$provider, 'err'=>$lastErr]);
        return ['error'=>$lastErr];
    }
}
