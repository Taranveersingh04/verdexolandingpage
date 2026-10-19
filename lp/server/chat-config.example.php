<?php
// Copy this file to ONE of these places and paste your free Groq API key below:
//   - lp-chat-config.php, one folder ABOVE public_html (best: not reachable from the web), or
//   - public_html/lp-assets/chat-config.php
// Get a key at https://console.groq.com/keys (free tier, no card). Groq doesn't train on API data; to also stop it
// keeping chats for troubleshooting, turn on Zero Data Retention in the Groq console under Settings > Data Controls.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
return [
    'groq_key' => 'YOUR-GROQ-API-KEY',
    'model' => 'openai/gpt-oss-120b',
    'fallback_model' => 'openai/gpt-oss-20b',   // answers when the main model hits Groq's free limit
];
