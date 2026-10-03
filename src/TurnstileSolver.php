<?php

declare(strict_types=1);

/**
 * The API refused a request, the task ended without a token, or the wait ran out.
 */
final class ZeroCaptchaException extends RuntimeException
{
    /**
     * @param string      $errorCode the API's code, such as insufficient_funds or
     *                               ERROR_CAPTCHA_UNSOLVABLE, or timeout
     * @param string|null $requestId what to quote when you ask support about the request
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct("{$errorCode}: {$message}");
    }
}

/**
 * Solves Cloudflare Turnstile widgets with the ZeroCaptcha REST API. Needs PHP 8.1 or later with
 * the curl and json extensions, and nothing from Composer.
 */
final class TurnstileSolver
{
    /** Answers worth another try after a wait: too many requests, or a server busy or away. */
    private const RETRYABLE = [429, 502, 503, 504];
    private const ATTEMPTS = 3;

    /**
     * @param string $api      the API's address, such as https://api.zerocaptcha.io
     * @param string $key      your API key, zc_live_…
     * @param float  $interval how often to ask for the result, in seconds
     */
    public function __construct(
        private readonly string $api,
        private readonly string $key,
        private readonly float $interval = 2.0,
    ) {
    }

    /**
     * Creates a Cloudflare Turnstile task, waits for it, and returns the token. The token works
     * once, for 300 seconds. A task that fails or expires throws ZeroCaptchaException with its
     * errorCode, and nothing is charged.
     */
    public function solve(
        string $websiteUrl,
        string $websiteKey,
        ?string $action = null,
        ?string $cdata = null,
        ?string $proxy = null,
        float $timeout = 180.0,
    ): string {
        $deadline = microtime(true) + $timeout;
        $task = [
            'type' => $proxy === null ? 'TurnstileTaskProxyless' : 'TurnstileTask',
            'websiteURL' => $websiteUrl,
            'websiteKey' => $websiteKey,
        ];
        if ($action !== null) {
            $task['action'] = $action;
        }
        if ($cdata !== null) {
            $task['cdata'] = $cdata;
        }
        if ($proxy !== null) {
            $task['proxy'] = $proxy; // such as http://user:pass@proxy.example.net:8080
        }
        // One key per task: a retry after a lost reply returns this task instead of making another.
        $current = $this->request('POST', '/v1/tasks', $deadline, $task, self::uuid());
        while (in_array($current['status'] ?? null, ['queued', 'running'], true)) {
            if (microtime(true) + $this->interval >= $deadline) {
                throw new ZeroCaptchaException('timeout', "Task {$current['id']} was still {$current['status']}.");
            }
            usleep((int) ($this->interval * 1_000_000));
            $current = $this->request('GET', '/v1/tasks/' . rawurlencode((string) $current['id']), $deadline);
        }
        $status = (string) ($current['status'] ?? 'unknown');
        $token = $current['solution']['token'] ?? null;
        if ($status === 'succeeded' && is_string($token) && $token !== '') {
            return $token;
        }
        throw new ZeroCaptchaException(
            (string) ($current['errorCode'] ?? $status),
            (string) ($current['errorDescription'] ?? "The task {$status}; nothing was charged."),
        );
    }

    /**
     * Sends one request, trying it up to three times with the same Idempotency-Key.
     *
     * @param array<string, string>|null $body
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        float $deadline,
        ?array $body = null,
        ?string $idempotencyKey = null,
    ): array {
        $headers = ['Authorization: Bearer ' . $this->key, 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        for ($attempt = 1; ; $attempt++) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                throw new ZeroCaptchaException('timeout', "{$method} {$path} ran past the deadline.");
            }
            $answer = [];
            $curl = curl_init(rtrim($this->api, '/') . $path);
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => (int) (min(30.0, $left) * 1000),
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$answer): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $answer[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            }
            $text = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            unset($curl);

            $wait = (float) $attempt;
            if (is_string($text) && $status >= 200 && $status < 300) {
                $reply = json_decode($text, true);
                if (is_array($reply)) {
                    return $reply;
                }
                // An answer cut short: the same Idempotency-Key makes a retry safe.
                $failure = new ZeroCaptchaException('network', 'The answer was cut short.');
            } elseif (is_string($text) && $status !== 0) {
                $problem = json_decode($text, true);
                $problem = is_array($problem) ? $problem : [];
                $failure = new ZeroCaptchaException(
                    (string) ($problem['code'] ?? "http_{$status}"),
                    (string) ($problem['detail'] ?? $problem['title'] ?? "HTTP {$status}"),
                    $problem['request_id'] ?? $answer['x-request-id'] ?? null,
                );
                $retryable = in_array($status, self::RETRYABLE, true)
                    || ($status === 409 && $failure->errorCode === 'idempotency_key_in_use');
                if (!$retryable) {
                    throw $failure;
                }
                $asked = $answer['retry-after'] ?? '';
                if ($asked !== '' && ctype_digit($asked)) {
                    $wait = (float) $asked;
                }
            } else {
                // No answer: the same Idempotency-Key makes a retry safe.
                $failure = new ZeroCaptchaException('network', $error !== '' ? $error : 'No answer.');
            }
            if ($attempt >= self::ATTEMPTS) {
                throw $failure;
            }
            if (microtime(true) + $wait >= $deadline) {
                throw new ZeroCaptchaException('timeout', "{$method} {$path} ran past the deadline.");
            }
            usleep((int) ($wait * 1_000_000));
        }
    }

    /** A random UUID, version 4, for the Idempotency-Key. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
