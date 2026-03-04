<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/ai_helper.php';

$key = ai_getenv('OPENROUTER_API_KEY');
$model = ai_getenv('OPENROUTER_MODEL') ?: 'mistralai/mistral-7b-instruct';

$out = [
  'has_key' => $key ? true : false,
  'curl' => extension_loaded('curl'),
  'model' => $model,
  'http_code' => null,
  'curl_error' => null,
  'response' => null,
];

if (!$key) { echo json_encode(['ok'=>false,'why'=>'no_openrouter_key','diag'=>$out]); exit; }

$url = 'https://openrouter.ai/api/v1/chat/completions';
$headers = [
  'Content-Type: application/json',
  'Authorization: Bearer ' . $key,
  'X-Title: Realmedic IA',
];
$payload = json_encode([
  'model' => $model,
  'messages' => [
    ['role'=>'system','content'=>'Devuelve "pong" y nada más.'],
    ['role'=>'user','content'=>'ping']
  ],
  'max_tokens' => 5,
  'temperature' => 0
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($url);
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
