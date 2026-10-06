<?php

return [
    'claude_api_key' => env('CLAUDE_API_KEY', ''),
    'claude_api_url' => env('CLAUDE_API_URL', 'https://api.anthropic.com/v1/messages'),
    'model' => env('CLAUDE_MODEL', 'claude-haiku-4-5'),
];
