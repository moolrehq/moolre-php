<?php

// Router for `php -S`: a scriptable mock Moolre API used by tests/run.php.
//   POST /__setup  body: JSON list of [httpStatus, body (array|string), headers?]  -> resets state
//   GET  /__calls  -> JSON list of recorded calls
// Every other request records itself and answers with the next scripted response (the last one repeats).

$stateFile = getenv('MOOLRE_MOCK_STATE') ?: sys_get_temp_dir() . '/moolre_mock_state.json';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');

if ($path === '/__setup') {
    file_put_contents($stateFile, json_encode(['responses' => json_decode($raw, true), 'calls' => []]));
    echo 'ok';

    return true;
}

$state = json_decode((string) @file_get_contents($stateFile), true) ?: ['responses' => [], 'calls' => []];

if ($path === '/__calls') {
    header('Content-Type: application/json');
    echo json_encode($state['calls']);

    return true;
}

$headers = [];
foreach (getallheaders() as $k => $v) {
    $headers[strtolower($k)] = $v;
}
$n = count($state['calls']);
$state['calls'][] = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'url' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'raw' => $raw,
    'body' => $raw !== '' ? json_decode($raw, true) : null,
];
file_put_contents($stateFile, json_encode($state));

$responses = $state['responses'];
$r = $responses[min($n, count($responses) - 1)] ?? [200, ['status' => 1, 'code' => 'X01', 'message' => 'ok', 'data' => null, 'go' => null]];
http_response_code($r[0]);
header('Content-Type: application/json');
foreach ($r[2] ?? [] as $k => $v) {
    header($k . ': ' . $v);
}
echo is_string($r[1]) ? $r[1] : json_encode($r[1]);

return true;
