<?php
require __DIR__.'/ai_helper.php';
var_dump([
  'OPENAI_API_KEY' => ai_getenv('OPENAI_API_KEY') ? 'OK' : 'MISSING',
  'AI_ENABLED'     => ai_getenv('AI_ENABLED'),
]);
