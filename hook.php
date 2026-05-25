<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/modules/bitrix.module.php';
require_once __DIR__ . '/modules/telethon.module.php';

if (class_exists(Dotenv::class) && file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->safeLoad();
}

$vkToken = readEnvString('VK_BOT_TOKEN', '');
$vkApiVersion = readEnvString('VK_API_VERSION', '5.199');
$vkSecretKey = readEnvString('VK_SECRET_KEY', '');
$vkConfirmationToken = readEnvString('VK_CONFIRMATION_TOKEN', '');
$vkTimeout = readEnvInt('VK_TIMEOUT', 10);
$nonceTtl = readEnvInt('VK_NONCE_TTL', 300);
$bitrix24Url = readEnvString('BITRIX24_URL', '');
$bitrix24Token = readEnvString('BITRIX24_TOKEN', '');
$peerBitrixMap = bitrixParsePeerBitrixMap(readEnvString('PEER_BITRIX', ''));
$myTelethonUrl = readEnvString('MY_TELETHON_URL', '');
$myTelethonToken = readEnvString('MY_TELETHON_TOKEN', '');
$peerTelethonMap = telethonParsePeerMap(readEnvString('PEER_MY_TELETHON', ''));

$proxyEnabled = readEnvBool('VK_PROXY_ENABLED', false);
$proxyList = parseProxyList(readEnvString('VK_PROXY_LIST', '[]'));

$loggingEnabled = readEnvBool('WEBHOOK_LOG_ENABLED', false);
$logFile = readEnvString('WEBHOOK_LOG_FILE', __DIR__ . '/logs/webhook.log');
$nonceStorageDir = __DIR__ . '/storage/nonces';

if ($vkSecretKey === '') {
    logWebhook($loggingEnabled, $logFile, [
        'event' => 'startup_error',
        'error' => 'VK_SECRET_KEY is not set',
    ]);
    http_response_code(500);
    echo 'VK_SECRET_KEY is not set';
    exit;
}

$input = file_get_contents('php://input');
if ($input === false || trim($input) === '') {
    logWebhook($loggingEnabled, $logFile, [
        'event' => 'request_error',
        'error' => 'Empty payload',
    ]);
    http_response_code(400);
    echo 'Empty payload';
    exit;
}

try {
    /** @var array<string, mixed> $payload */
    $payload = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    logWebhook($loggingEnabled, $logFile, [
        'event' => 'request_error',
        'error' => 'Invalid JSON payload',
        'exception' => $exception->getMessage(),
    ]);
    http_response_code(400);
    echo 'Invalid JSON payload';
    exit;
}

logWebhook($loggingEnabled, $logFile, [
    'event' => 'incoming_callback',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    'payload' => $payload,
]);

$type = (string) ($payload['type'] ?? '');
if ($type === 'confirmation') {
    if ($vkConfirmationToken === '') {
        logWebhook($loggingEnabled, $logFile, [
            'event' => 'confirmation_error',
            'error' => 'VK_CONFIRMATION_TOKEN is not set',
        ]);
        http_response_code(500);
        echo 'VK_CONFIRMATION_TOKEN is not set';
        exit;
    }

    http_response_code(200);
    echo $vkConfirmationToken;
    exit;
}

$receivedSecret = (string) ($payload['secret'] ?? '');
if ($receivedSecret === '' || !hash_equals($vkSecretKey, $receivedSecret)) {
    logWebhook($loggingEnabled, $logFile, [
        'event' => 'request_forbidden',
        'reason' => 'Invalid callback secret',
    ]);
    http_response_code(403);
    echo 'forbidden';
    exit;
}

if ($type !== 'message_new') {
    http_response_code(200);
    echo 'ok';
    exit;
}

$message = is_array($payload['object']['message'] ?? null) ? $payload['object']['message'] : [];
$text = trim((string) ($message['text'] ?? ''));
$peerId = (int) ($message['peer_id'] ?? 0);
$fromId = (int) ($message['from_id'] ?? 0);

$nonce = buildNonce($payload, $message);
if (isNonceAlreadyProcessed($nonceStorageDir, $nonce, $nonceTtl)) {
    logWebhook($loggingEnabled, $logFile, [
        'event' => 'duplicate_ignored',
        'nonce' => $nonce,
    ]);
    http_response_code(200);
    echo 'ok';
    exit;
}

$responseText = null;
if (str_starts_with($text, '/help')) {
    $responseText = buildHelpText($fromId, $peerBitrixMap, $peerTelethonMap);
} elseif (str_starts_with($text, '/whoami')) {
    $responseText = buildWhoAmIText($payload, $message);
} else {
    $responseText = bitrixDispatchCommand($text, $fromId, $peerBitrixMap, $bitrix24Url, $bitrix24Token);
    if ($responseText === null) {
        $responseText = telethonDispatchCommand(
            $text,
            $fromId,
            $peerTelethonMap,
            $myTelethonUrl,
            $myTelethonToken
        );
    }
}

if ($responseText !== null && $responseText !== '' && $peerId > 0) {
    if ($vkToken === '') {
        logWebhook($loggingEnabled, $logFile, [
            'event' => 'send_error',
            'error' => 'VK_BOT_TOKEN is not set',
            'command_text' => $text,
        ]);
    } else {
        $sendResult = sendVkMessage(
            $vkToken,
            $vkApiVersion,
            $peerId,
            $responseText,
            $vkTimeout,
            $proxyEnabled,
            $proxyList
        );
        logWebhook($loggingEnabled, $logFile, [
            'event' => 'command_handled',
            'command_text' => $text,
            'peer_id' => $peerId,
            'nonce' => $nonce,
            'send_result' => $sendResult,
        ]);
    }
}

rememberNonce($nonceStorageDir, $nonce);

http_response_code(200);
echo 'ok';

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $message
 */
function buildWhoAmIText(array $payload, array $message): string
{
    $context = [
        'command' => '/whoami',
        'type' => $payload['type'] ?? null,
        'group_id' => $payload['group_id'] ?? null,
        'event_id' => $payload['event_id'] ?? null,
        'from_id' => $message['from_id'] ?? null,
        'peer_id' => $message['peer_id'] ?? null,
        'conversation_message_id' => $message['conversation_message_id'] ?? null,
        'date' => $message['date'] ?? null,
        'text' => $message['text'] ?? null,
        'payload' => $message['payload'] ?? null,
    ];

    $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return "Session info\n" . ($json ?: '{}');
}

/**
 * @param array<int, int> $peerBitrixMap
 */
/**
 * @param array<int, int> $peerBitrixMap
 * @param array<int, int> $peerTelethonMap
 */
function buildHelpText(int $fromId, array $peerBitrixMap, array $peerTelethonMap): string
{
    $lines = [
        "Available commands:",
        "/help - show available commands",
        "/whoami - show current VK callback session details",
    ];

    if (isset($peerBitrixMap[$fromId])) {
        $lines[] = "bitrix start|pause|resume|finish|timeman [YYYY-MM-DD]";
    }

    if (isset($peerTelethonMap[$fromId])) {
        $lines[] = "telethon";
        $lines[] = "telethon status";
        $lines[] = "telethon relays | relays on|off";
        $lines[] = "telethon get {contact_id} [count]";
        $lines[] = "telethon contacts {query}";
        $lines[] = "telethon {contact_id} send {message}";
        $lines[] = "telethon {contact_id} relay on|off|true|false";
    }

    return implode("\n", $lines);
}

/**
 * @return array<string, mixed>
 */
function sendVkMessage(
    string $token,
    string $apiVersion,
    int $peerId,
    string $text,
    int $timeout,
    bool $proxyEnabled,
    array $proxyList
): array {
    $url = 'https://api.vk.com/method/messages.send';
    $randomId = random_int(1, PHP_INT_MAX);
    $postFields = http_build_query([
        'access_token' => $token,
        'peer_id' => $peerId,
        'random_id' => $randomId,
        'message' => $text,
        'v' => $apiVersion,
    ]);

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POSTFIELDS => $postFields,
    ]);

    $selectedProxy = null;
    if ($proxyEnabled && count($proxyList) > 0) {
        $selectedProxy = (string) $proxyList[0];
        curl_setopt_array($ch, buildCurlProxyOptions($selectedProxy));
    }

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [
        'ok' => $curlError === '' && is_string($responseBody),
        'http_code' => $httpCode,
        'curl_error' => $curlError !== '' ? $curlError : null,
        'used_proxy' => $selectedProxy,
        'response_body' => is_string($responseBody) ? $responseBody : null,
    ];
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $message
 */
function buildNonce(array $payload, array $message): string
{
    $eventId = (string) ($payload['event_id'] ?? '');
    if ($eventId !== '') {
        return 'event:' . $eventId;
    }

    $parts = [
        (string) ($payload['type'] ?? ''),
        (string) ($payload['group_id'] ?? ''),
        (string) ($message['from_id'] ?? ''),
        (string) ($message['peer_id'] ?? ''),
        (string) ($message['conversation_message_id'] ?? ''),
        (string) ($message['date'] ?? ''),
        (string) ($message['text'] ?? ''),
    ];

    return 'fallback:' . sha1(implode('|', $parts));
}

function isNonceAlreadyProcessed(string $storageDir, string $nonce, int $ttl): bool
{
    cleanupExpiredNonces($storageDir, $ttl);
    $path = buildNoncePath($storageDir, $nonce);
    return is_file($path);
}

function rememberNonce(string $storageDir, string $nonce): void
{
    ensureDir($storageDir);
    $path = buildNoncePath($storageDir, $nonce);
    file_put_contents($path, (string) time(), LOCK_EX);
}

function cleanupExpiredNonces(string $storageDir, int $ttl): void
{
    if (!is_dir($storageDir)) {
        return;
    }

    $now = time();
    $files = scandir($storageDir);
    if (!is_array($files)) {
        return;
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $storageDir . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        $mtime = filemtime($path);
        if (!is_int($mtime)) {
            continue;
        }
        if (($now - $mtime) > $ttl) {
            @unlink($path);
        }
    }
}

function buildNoncePath(string $storageDir, string $nonce): string
{
    $safeName = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $nonce);
    if (!is_string($safeName) || $safeName === '') {
        $safeName = sha1($nonce);
    }
    return $storageDir . '/' . $safeName . '.nonce';
}

function readEnvString(string $name, string $default): string
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    if (!is_string($value) || trim($value) === '') {
        return $default;
    }
    return trim($value);
}

function readEnvBool(string $name, bool $default): bool
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    if (!is_string($value)) {
        return $default;
    }
    $normalized = strtolower(trim($value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return $default;
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
        $normalized = preg_replace("/'([^']*)'/", '"$1"', $trimmed);
        if (is_string($normalized)) {
            $normalized = preg_replace('/\\\\([^"\\\\\\/bfnrtu])/', '\\\\\\\\$1', $normalized);
            $decoded = json_decode($normalized, true);
        }
    }

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

    $options = [
        CURLOPT_PROXY => $port > 0 ? sprintf('%s:%d', $host, $port) : $host,
    ];
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

/**
 * @param array<string, mixed> $context
 */
function logWebhook(bool $enabled, string $logFile, array $context): void
{
    if (!$enabled) {
        return;
    }

    ensureDir(dirname($logFile));
    $entry = ['timestamp' => date(DATE_ATOM)] + $context;
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($line)) {
        file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}
