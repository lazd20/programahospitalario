<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/ai_helper.php'; // para usar ai_getenv()

$diag = [
  'php_version' => PHP_VERSION,
  'extensions' => [
    'curl' => extension_loaded('curl'),
    'json' => extension_loaded('json'),
    'pdo'  => extension_loaded('PDO'),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
  ],
  'env' => [
    'AI_PROVIDER' => ai_getenv('AI_PROVIDER') ?: '(default: openai)',
    'OPENAI_API_KEY_set' => ai_getenv('OPENAI_API_KEY') ? true : false,
    'OPENROUTER_API_KEY_set' => ai_getenv('OPENROUTER_API_KEY') ? true : false,
    'OPENROUTER_MODEL' => ai_getenv('OPENROUTER_MODEL') ?: null,
    'AI_ENABLED' => ai_getenv('AI_ENABLED') ?: null,
  ],
  'paths' => [
    '__DIR__' => __DIR__,
    'writable_logs' => is_writable(__DIR__ . '/logs') ? 'writable' : (is_dir(__DIR__ . '/logs') ? 'not_writable' : 'absent'),
  ],
  'db' => null,
  'errors' => []
];

// Try create logs dir
$logs = __DIR__ . '/logs';
if (!is_dir($logs)) {
  @mkdir($logs, 0775, true);
}
if (!is_writable($logs)) {
  $diag['errors'][] = 'logs_not_writable';
} else {
  @file_put_contents($logs.'/healthcheck.log', date('c')." ping\n", FILE_APPEND);
}

// DB test
$host = 'localhost';
$dbname = 'sitiosnuevos_hospital';
$username = 'sitiosnuevos_cirtugia';
$password = 'Realmedic2020';
try {
  $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $stmt = $pdo->query("SELECT 1");
  $diag['db'] = ['ok'=>true, 'select1'=> (int)$stmt->fetchColumn()];
} catch (Throwable $e) {
  $diag['db'] = ['ok'=>false, 'error'=>$e->getMessage()];
}

echo json_encode(['ok'=>true,'diag'=>$diag], JSON_UNESCAPED_UNICODE);
