<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

if (class_exists(Dotenv::class) && file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->safeLoad();
}

$rawProxyList = (string) (
    $_ENV['VK_PROXY_LIST']
    ?? $_SERVER['VK_PROXY_LIST']
    ?? getenv('VK_PROXY_LIST')
    ?: '[]'
);
$timeout = readEnvInt('VK_TIMEOUT', 10);
$proxies = parseProxyList($rawProxyList);

$results = [];
foreach ($proxies as $proxy) {
    $results[] = checkVkReachability($proxy, $timeout);
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'checked_at' => date(DATE_ATOM),
    'target_url' => 'https://api.vk.com',
    'timeout_seconds' => $timeout,
    'proxy_count' => count($proxies),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

/**
 * @return array<string, mixed>
 */
function checkVkReachability(string $proxy, int $timeout): array
{
    $ch = curl_init('https://api.vk.com');
    if ($ch === false) {
        return ['proxy' => $proxy, 'ok' => false, 'error' => 'curl_init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_setopt_array($ch, buildCurlProxyOptions($proxy));

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    return [
        'proxy' => $proxy,
        'ok' => $curlError === '' && $httpCode > 0,
        'http_code' => $httpCode,
        'curl_error' => $curlError !== '' ? $curlError : null,
        'primary_ip' => is_string($primaryIp) ? $primaryIp : null,
        'total_time_sec' => is_float($totalTime) ? $totalTime : null,
        'response_body_present' => is_string($responseBody),
    ];
}

function readEnvInt(string $name, int $default): int
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    if ($value === false || $value === null || $value === '' || !is_numeric((string) $value)) {
        return $default;
    }
    $parsed = (int) $value;
    return $parsed > 0 ? $parsed : $default;
}

/**
 * @return array<int, string>
 */
function parseProxyList(string $raw): array
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return [];
    }

    $decoded = json_decode($trimmed, true);
    if (!is_array($decoded)) {
        return [];
    }

    $proxies = [];
    foreach ($decoded as $item) {
        if (is_string($item) && trim($item) !== '') {
            $proxies[] = trim($item);
        }
    }

    return $proxies;
}

/**
 * @return array<int, mixed>
 */
function buildCurlProxyOptions(string $proxy): array
{
    $proxyUri = parse_url($proxy);
    if ($proxyUri === false || !isset($proxyUri['host'])) {
        return [CURLOPT_PROXY => $proxy];
    }

    $scheme = strtolower((string) ($proxyUri['scheme'] ?? ''));
    $host = (string) $proxyUri['host'];
    $port = (int) ($proxyUri['port'] ?? 0);
    $user = isset($proxyUri['user']) ? urldecode((string) $proxyUri['user']) : null;
    $pass = isset($proxyUri['pass']) ? urldecode((string) $proxyUri['pass']) : null;

    $options = [CURLOPT_PROXY => $port > 0 ? sprintf('%s:%d', $host, $port) : $host];
    if ($scheme === 'socks5h') {
        $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
    } elseif ($scheme === 'socks5') {
        $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
    }
    if (is_string($user)) {
        $options[CURLOPT_PROXYUSERPWD] = $user . ':' . (string) $pass;
    }

    return $options;
}
