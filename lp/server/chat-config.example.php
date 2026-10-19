<?php
// Copy this file to ONE of these places and paste your free Google AI Studio key below:
//   - lp-chat-config.php, one folder ABOVE public_html (best: not reachable from the web), or
//   - public_html/lp-assets/chat-config.php
// Get a key at https://aistudio.google.com/apikey (free tier; Google may use free-tier chats to improve its products).
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
return [
    'gemini_key' => 'YOUR-GEMINI-API-KEY',
    'model' => 'gemini-flash-latest',
];
