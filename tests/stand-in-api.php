<?php

// A stand-in for the ZeroCaptcha REST API, as a router for PHP's built-in server, so the tests make
// no real task. Each test calls it under its own prefix, /<scenario>-<run>/v1/…, and its state (how
// many creates and polls, and every request) lives in a JSON file in ZC_STAND_IN_STATE.
//
// - success: the task runs on the first poll and succeeds on the next.
// - failed: the task fails with ERROR_CAPTCHA_UNSOLVABLE.
// - rate-limited: the first create is answered 429 with Retry-After: 0, then as success.
// - insufficient-funds: every create is refused 402 with the code insufficient_funds.

declare(strict_types=1);

const KEY = 'zc_live_test_key';
const TASK_ID = '0192f3a4-7b1c-7d2e-9f10-3c4d5e6f7a8b';
const TOKEN = '0.stand-in-turnstile-token';

function reply(int $status, array $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
}

function problem(int $status, string $code, array $headers = []): void
{
    reply($status, [
        'type' => 'about:blank',
        'title' => $code,
        'status' => $status,
        'detail' => "The stand-in answered {$code}.",
        'code' => $code,
        'request_id' => '0192f3a4-0000-7000-8000-000000000000',
    ], $headers);
}

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/([a-z-]+)-([0-9a-f]+)(/.*)$#', $path, $match) !== 1) {
    problem(404, 'not_found');
    return true;
}
[, $scenario, $run, $route] = $match;
$stateFile = getenv('ZC_STAND_IN_STATE') . "/{$scenario}-{$run}.json";
$state = is_file($stateFile)
    ? json_decode((string) file_get_contents($stateFile), true)
    : ['creates' => 0, 'polls' => 0, 'requests' => []];

$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string) file_get_contents('php://input'), true);
$state['requests'][] = [
    'method' => $method,
    'path' => $route,
    'authorization' => $headers['authorization'] ?? null,
    'idempotency_key' => $headers['idempotency-key'] ?? null,
    'body' => $body,
];

$task = static fn (string $status): array => ['id' => TASK_ID, 'status' => $status, 'kind' => 'turnstile'];

if (($headers['authorization'] ?? '') !== 'Bearer ' . KEY) {
    problem(401, 'unauthorized');
} elseif ($method === 'POST' && $route === '/v1/tasks') {
    $state['creates']++;
    if (!is_array($body) || empty($body['websiteURL']) || empty($body['websiteKey'])) {
        problem(422, 'validation_failed');
    } elseif ($scenario === 'rate-limited' && $state['creates'] === 1) {
        problem(429, 'rate_limited', ['Retry-After' => '0']);
    } elseif ($scenario === 'insufficient-funds') {
        problem(402, 'insufficient_funds');
    } else {
        reply(201, $task('queued'));
    }
} elseif ($method === 'GET' && $route === '/v1/tasks/' . TASK_ID) {
    $state['polls']++;
    if ($scenario === 'failed') {
        reply(200, $task('failed') + ['errorCode' => 'ERROR_CAPTCHA_UNSOLVABLE', 'errorDescription' => 'Every attempt failed.']);
    } elseif ($state['polls'] === 1) {
        reply(200, $task('running'));
    } else {
        reply(200, $task('succeeded') + ['solution' => ['token' => TOKEN]]);
    }
} else {
    problem(404, 'not_found');
}

file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES));
return true;
