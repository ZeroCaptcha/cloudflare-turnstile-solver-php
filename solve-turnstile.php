<?php

// Prints a Cloudflare Turnstile token for a page and its sitekey:
//
//   export ZEROCAPTCHA_API=https://api.zerocaptcha.io ZEROCAPTCHA_KEY=zc_live_...
//   php solve-turnstile.php https://shop.example.com/login 0x4AAAAAAAB1cD2eF3gH4iJ5 [action] [cdata]
//
// action and cdata are the widget's data-action and data-cdata (or turnstile.render()'s action and
// cData options): pass them whenever the widget sets them, since many sites check both when they
// verify the token. Set PROXY_URL to solve through your own proxy.

declare(strict_types=1);

require __DIR__ . '/src/TurnstileSolver.php';

if ($argc < 3 || $argc > 5) {
    fwrite(STDERR, "Usage: php solve-turnstile.php <page URL> <sitekey> [action] [cdata]\n");
    exit(2);
}
$api = getenv('ZEROCAPTCHA_API');
$key = getenv('ZEROCAPTCHA_KEY');
if ($api === false || $api === '' || $key === false || $key === '') {
    fwrite(STDERR, "Set ZEROCAPTCHA_API and ZEROCAPTCHA_KEY first.\n");
    exit(2);
}
$proxy = getenv('PROXY_URL');

try {
    $solver = new TurnstileSolver($api, $key);
    echo $solver->solve(
        $argv[1],
        $argv[2],
        action: $argv[3] ?? null,
        cdata: $argv[4] ?? null,
        proxy: $proxy === false || $proxy === '' ? null : $proxy,
    ), PHP_EOL;
} catch (ZeroCaptchaException $error) {
    $request = $error->requestId === null ? '' : " (request {$error->requestId})";
    fwrite(STDERR, $error->getMessage() . $request . PHP_EOL);
    exit(1);
}
