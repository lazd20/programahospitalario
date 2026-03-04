<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/ai_helper.php';

$apiKey = ai_getenv('OPENAI_API_KEY');
$out = [
  'has_key' => $apiKey ? true : false,
  'curl' => extension_loaded('curl'),
  'request' => null,
  'response' => null,
  'http_code' => null,
  'curl_error' => null,
];

if (!$apiKey) {
  echo json_encode(['ok'=>false,'why'=>'no_api_key','diag'=>$out]); exit;
}

$ch = curl_init('https://api.openai.com/v1/chat/completions');
$headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
];
$payload = json_encode([
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role'=>'system','content'=>'Devuelve "pong" y nada más.'],
        ['role'=>'user','content'=>'ping']
    ],
    'max_tokens' => 5,
    'temperature' => 0
], JSON_UNESCAPED_UNICODE);

$out['request'] = ['headers'=>$headers, 'body_len'=>strlen($payload)];
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
]);
$res = curl_exec($ch);
if (curl_errno($ch)) { $out['curl_error'] = curl_error($ch); }
$out['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$out['response'] = $res;
$ok = $out['http_code'] >= 200 && $out['http_code'] < 300;

echo json_encode(['ok'=>$ok,'diag'=>$out], JSON_UNESCAPED_UNICODE);
