<?php

// The solver and the command against a stand-in API on PHP's built-in server: no key, no real
// task, nothing spent. Run it with `php tests/run.php`; it exits 1 if any test fails.

declare(strict_types=1);

require __DIR__ . '/../src/TurnstileSolver.php';

const KEY = 'zc_live_test_key';
const TOKEN = '0.stand-in-turnstile-token';
const PAGE = 'https://shop.example.com/login';
const SITEKEY = '0x4AAAAAAAB1cD2eF3gH4iJ5';

$state = sys_get_temp_dir() . '/zc-stand-in-' . bin2hex(random_bytes(6));
mkdir($state);

// A free port, then the built-in server on it with the stand-in as its router.
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/stand-in-api.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
    $pipes,
    null,
    ['ZC_STAND_IN_STATE' => $state] + getenv(),
);
for ($tries = 0; $tries < 50; $tries++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($socket !== false) {
        fclose($socket);
        break;
    }
    usleep(100_000);
}

/** A stand-in address for one scenario, with state of its own. */
function standIn(string $scenario): array
{
    global $port;
    $run = bin2hex(random_bytes(4));
    return ["http://127.0.0.1:{$port}/{$scenario}-{$run}", "{$scenario}-{$run}"];
}

/** Every request the stand-in saw under a prefix. */
function requests(string $prefix): array
{
    global $state;
    return json_decode((string) file_get_contents("{$state}/{$prefix}.json"), true)['requests'];
}

function check(bool $condition, string $what): void
{
    if (!$condition) {
        throw new LogicException($what);
    }
}

$tests = [
    'returns the token and sends the task as the API expects' => function (): void {
        [$api, $prefix] = standIn('success');
        $token = (new TurnstileSolver($api, KEY, 0.01))->solve(PAGE, SITEKEY, action: 'login', cdata: 'session-7f3a9c2e');
        check($token === TOKEN, "the token was {$token}");
        $create = requests($prefix)[0];
        check($create['method'] === 'POST' && $create['path'] === '/v1/tasks', 'the first call creates');
        check($create['authorization'] === 'Bearer ' . KEY, 'the key is a bearer token');
        check(is_string($create['idempotency_key']) && $create['idempotency_key'] !== '', 'an Idempotency-Key is sent');
        check($create['body'] === [
            'type' => 'TurnstileTaskProxyless',
            'websiteURL' => PAGE,
            'websiteKey' => SITEKEY,
            // The widget's action and cData reach the API, so a site that checks them accepts the token.
            'action' => 'login',
            'cdata' => 'session-7f3a9c2e',
        ], 'the body is ' . json_encode($create['body']));
        check(count(requests($prefix)) === 3, 'a create and two polls');
    },
    'a proxy makes a proxied task' => function (): void {
        [$api, $prefix] = standIn('success');
        $proxy = 'http://user:pass@proxy.example.net:8080';
        (new TurnstileSolver($api, KEY, 0.01))->solve(PAGE, SITEKEY, proxy: $proxy);
        $body = requests($prefix)[0]['body'];
        check($body['type'] === 'TurnstileTask' && $body['proxy'] === $proxy, 'the body is ' . json_encode($body));
    },
    'a failed task throws its code' => function (): void {
        [$api] = standIn('failed');
        try {
            (new TurnstileSolver($api, KEY, 0.01))->solve(PAGE, SITEKEY);
            check(false, 'no exception');
        } catch (ZeroCaptchaException $error) {
            check($error->errorCode === 'ERROR_CAPTCHA_UNSOLVABLE', "the code was {$error->errorCode}");
        }
    },
    'a rate-limited create is retried with the same Idempotency-Key' => function (): void {
        [$api, $prefix] = standIn('rate-limited');
        $token = (new TurnstileSolver($api, KEY, 0.01))->solve(PAGE, SITEKEY);
        check($token === TOKEN, "the token was {$token}");
        [$first, $second] = requests($prefix);
        check($first['method'] === 'POST' && $second['method'] === 'POST', 'two creates');
        check($first['idempotency_key'] === $second['idempotency_key'], 'with the same key');
    },
    'a refusal is thrown at once, with its request ID' => function (): void {
        [$api, $prefix] = standIn('insufficient-funds');
        try {
            (new TurnstileSolver($api, KEY, 0.01))->solve(PAGE, SITEKEY);
            check(false, 'no exception');
        } catch (ZeroCaptchaException $error) {
            check($error->errorCode === 'insufficient_funds', "the code was {$error->errorCode}");
            check($error->requestId !== null, 'the request ID is kept');
        }
        check(count(requests($prefix)) === 1, 'no retry');
    },
    'the deadline stops the wait' => function (): void {
        [$api] = standIn('success');
        try {
            (new TurnstileSolver($api, KEY, 1.0))->solve(PAGE, SITEKEY, timeout: 0.3);
            check(false, 'no exception');
        } catch (ZeroCaptchaException $error) {
            check($error->errorCode === 'timeout', "the code was {$error->errorCode}");
        }
    },
    'the command prints the token' => function (): void {
        [$api] = standIn('success');
        $command = proc_open(
            [PHP_BINARY, __DIR__ . '/../solve-turnstile.php', PAGE, SITEKEY],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['ZEROCAPTCHA_API' => $api, 'ZEROCAPTCHA_KEY' => KEY] + getenv(),
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $status = proc_close($command);
        check($status === 0, "it exited {$status}: {$err}");
        check(trim((string) $out) === TOKEN, "it printed {$out}");
    },
];

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "ok     {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAILED {$name}: {$error->getMessage()}\n";
    }
}

proc_terminate($server);
proc_close($server);
array_map('unlink', glob("{$state}/*.json") ?: []);
rmdir($state);

echo count($tests) - $failed, ' passed, ', $failed, " failed\n";
exit($failed === 0 ? 0 : 1);
