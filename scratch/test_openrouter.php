<?php
require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Bootstrap Laravel to use HTTP wrapper
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$apiKey = env('OPENROUTER_API_KEY');
$baseUrl = env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1');
$model = env('OPENROUTER_DEFAULT_MODEL', 'google/gemini-2.5-flash');

echo "API Key: " . substr($apiKey, 0, 15) . "...\n";
echo "Base URL: " . $baseUrl . "\n";
echo "Model: " . $model . "\n\n";

try {
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $apiKey,
        'HTTP-Referer' => 'https://aitrading.com',
        'X-Title' => 'AI Trading Platform Test'
    ])->withoutVerifying()->post("{$baseUrl}/chat/completions", [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => 'Hello, respond with exactly "OpenRouter is working!"']
        ],
        'temperature' => 0.1,
        'max_tokens' => 1000
    ]);

    echo "Status Code: " . $response->status() . "\n";
    if ($response->successful()) {
        echo "Response Content:\n";
        print_r($response->json());
    } else {
        echo "Error Response:\n";
        echo $response->body() . "\n";
    }
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
