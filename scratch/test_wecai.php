<?php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://ai.worldelitecircle.com/api/chat');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer sk-my-super-secret-key-12345'
]);
$body = json_encode([
    'model' => 'qwen2.5:3b',
    'messages' => [
        ['role' => 'user', 'content' => 'hi, how are you?']
    ]
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "=== HTTP Status: " . $code . " ===" . PHP_EOL;
if ($err) echo "CURL Error: " . $err . PHP_EOL;
echo "=== Full Response ===" . PHP_EOL;
echo $res . PHP_EOL;

// Try to parse as JSON and pretty print
$json = json_decode($res, true);
if ($json) {
    echo PHP_EOL . "=== Parsed JSON ===" . PHP_EOL;
    echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
