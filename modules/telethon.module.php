<?php

declare(strict_types=1);

/**
 * @param array<int, int> $peerTelethonMap VK from_id → telethon profile id
 */
function telethonDispatchCommand(
    string $text,
    int $fromId,
    array $peerTelethonMap,
    string $vkomanderUrl,
    string $vkomanderToken
): ?string {
    $tokens = bitrixTokenizeCommand($text);
    if (count($tokens) === 0) {
        return null;
    }

    $module = mb_strtolower($tokens[0], 'UTF-8');
    if ($module !== 'telethon') {
        return null;
    }

    $telethonId = $peerTelethonMap[$fromId] ?? null;
    if (!is_int($telethonId) || $telethonId <= 0) {
        return 'telethon error: profile not linked (PEER_VKOMANDER)';
    }

    if ($vkomanderUrl === '') {
        return 'telethon error: VKOMANDER_URL missing';
    }
    if ($vkomanderToken === '') {
        return 'telethon error: VKOMANDER_TOKEN missing';
    }

    if (count($tokens) === 1) {
        return telethonUsage();
    }

    $sub = mb_strtolower($tokens[1], 'UTF-8');

    if ($sub === 'status') {
        return telethonHandleStatus($vkomanderUrl, $vkomanderToken, $telethonId, $fromId);
    }

    if ($sub === 'relays') {
        if (isset($tokens[2])) {
            $enabled = telethonParseRelayFlag($tokens[2]);
            if ($enabled === null) {
                return 'telethon error: use relays on|off|true|false';
            }
            return telethonHandleProfileRelays($vkomanderUrl, $vkomanderToken, $telethonId, $fromId, $enabled);
        }
        return telethonHandleRelaysList($vkomanderUrl, $vkomanderToken, $telethonId, $fromId);
    }

    if ($sub === 'get') {
        if (!isset($tokens[2]) || !ctype_digit($tokens[2])) {
            return telethonUsage();
        }
        $contactId = (int) $tokens[2];
        $count = 5;
        if (isset($tokens[3]) && ctype_digit($tokens[3])) {
            $count = max(1, min(50, (int) $tokens[3]));
        }
        return telethonHandleGet($vkomanderUrl, $vkomanderToken, $telethonId, $fromId, $contactId, $count);
    }

    if ($sub === 'contacts') {
        $query = trim(implode(' ', array_slice($tokens, 2)));
        if (mb_strlen($query) < 3) {
            return 'telethon error: contacts query min 3 chars';
        }
        return telethonHandleContactsSearch($vkomanderUrl, $vkomanderToken, $telethonId, $fromId, $query);
    }

    if ($sub === 'send') {
        if (!isset($tokens[2]) || !ctype_digit($tokens[2])) {
            return telethonUsage();
        }
        $contactId = (int) $tokens[2];
        if ($contactId <= 0) {
            return telethonUsage();
        }
        $message = trim(implode(' ', array_slice($tokens, 3)));
        if ($message === '') {
            return 'telethon error: message required after send';
        }
        return telethonHandleSend($vkomanderUrl, $vkomanderToken, $telethonId, $fromId, $contactId, $message);
    }

    if ($sub === 'relay') {
        if (!isset($tokens[2]) || !ctype_digit($tokens[2]) || (int)$tokens[2] <= 0 || count($tokens) > 4) {
            return telethonUsage();
        }
        $contactId = (int)$tokens[2];
        $enabled = null;
        if (isset($tokens[3])) {
            $enabled = telethonParseRelayFlag($tokens[3]);
            if ($enabled === null) {
                return 'telethon error: use relay {contact_id} [on|off|true|false]';
            }
        }
        return telethonHandleRelay(
            $vkomanderUrl,
            $vkomanderToken,
            $telethonId,
            $fromId,
            $contactId,
            $enabled
        );
    }

    return telethonUsage();
}

function telethonUsage(): string
{
    return implode("\n", [
        'telethon — команды профиля Telegram (vkomander):',
        'telethon',
        'telethon status',
        'telethon relays',
        'telethon relays on|off|true|false',
        'telethon get {contact_id} [count]',
        'telethon contacts {query}',
        'telethon send {contact_id} {text}',
        'telethon relay {contact_id} [on|off|true|false]',
        '',
        'contact_id — ID контакта vkomander (тот же номер в интерфейсе и пересылке).',
        'get — последние сообщения (count по умолчанию 5, макс. 50).',
        'contacts — поиск в БД (от 3 символов, до 20 результатов).',
        'relay без флага — переключить пересылку контакта; on/off — задать состояние.',
        'relays без флага — контакты с пересылкой; on/off — глобальный переключатель.',
    ]);
}

function telethonParseRelayFlag(string $raw): ?bool
{
    $value = mb_strtolower(trim($raw), 'UTF-8');
    if (in_array($value, ['on', 'true', '1', 'yes'], true)) {
        return true;
    }
    if (in_array($value, ['off', 'false', '0', 'no'], true)) {
        return false;
    }
    return null;
}

function telethonHandleRelaysList(string $baseUrl, string $token, int $telethonId, int $vkFromId): string
{
    $result = telethonApiRequest($baseUrl, $token, 'relays_list', [
        'telethon_id' => $telethonId,
        'vk_from_id' => $vkFromId,
    ]);
    if (!$result['ok']) {
        return 'telethon error: ' . telethonFormatError($result);
    }
    $data = $result['data'] ?? [];
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    if ($items === []) {
        return 'telethon relays: (none)';
    }
    $lines = ['telethon relays (' . count($items) . '):'];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        $kind = trim((string) ($row['kind'] ?? ''));
        if ($name === '') {
            $name = '—';
        }
        $label = '[' . $id . '] ' . $name;
        if ($kind !== '') {
            $label .= ' (' . $kind . ')';
        }
        $lines[] = '- ' . $label;
    }
    return implode("\n", $lines);
}

function telethonHandleProfileRelays(
    string $baseUrl,
    string $token,
    int $telethonId,
    int $vkFromId,
    bool $enabled
): string {
    $result = telethonApiRequest($baseUrl, $token, 'profile_relays', [
        'telethon_id' => $telethonId,
        'vk_from_id' => $vkFromId,
        'enabled' => $enabled,
    ]);
    if (!$result['ok']) {
        return 'telethon error: ' . telethonFormatError($result);
    }
    $state = $enabled ? 'on' : 'off';
    return 'telethon: global relays ' . $state;
}

function telethonHandleGet(
    string $baseUrl,
    string $token,
    int $telethonId,
    int $vkFromId,
    int $contactId,
    int $count
): string {
    $result = telethonApiRequest($baseUrl, $token, 'messages', [
        'telethon_id' => $telethonId,
        'vk_from_id' => $vkFromId,
        'contact_id' => $contactId,
        'count' => $count,
    ]);
    if (!$result['ok']) {
        return 'telethon error: ' . telethonFormatError($result);
    }
    $data = $result['data'] ?? [];
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    if ($items === []) {
        return 'telethon get [' . $contactId . ']: (no messages)';
    }
    $lines = ['telethon get [' . $contactId . '] (' . count($items) . '):'];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $msgId = (int) ($row['id'] ?? 0);
        $date = (string) ($row['date'] ?? '');
        if ($date !== '' && strlen($date) >= 16) {
            $date = substr($date, 0, 16);
        }
        $text = trim((string) ($row['text'] ?? ''));
        if ($text === '') {
            $text = trim((string) ($row['media_label'] ?? ''));
        }
        if ($text === '' && !empty($row['media'])) {
            $text = '[' . (string) $row['media'] . ']';
        }
        if ($text === '') {
            $text = '(пусто)';
        }
        if (mb_strlen($text) > 200) {
            $text = mb_substr($text, 0, 197) . '…';
        }
        $prefix = '#' . $msgId;
        if ($date !== '') {
            $prefix .= ' ' . $date;
        }
        $lines[] = $prefix . ': ' . $text;
    }
    return implode("\n", $lines);
}

function telethonHandleContactsSearch(
    string $baseUrl,
    string $token,
    int $telethonId,
    int $vkFromId,
    string $query
): string {
    $result = telethonApiRequest($baseUrl, $token, 'contacts_search', [
        'telethon_id' => $telethonId,
        'vk_from_id' => $vkFromId,
        'query' => $query,
    ]);
    if (!$result['ok']) {
        return 'telethon error: ' . telethonFormatError($result);
    }
    $data = $result['data'] ?? [];
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    if ($items === []) {
        return 'telethon contacts "' . $query . '": (none)';
    }
    $lines = ['telethon contacts "' . $query . '" (' . count($items) . '):'];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        $kind = trim((string) ($row['kind'] ?? ''));
        $relay = !empty($row['vk_relay_enabled']) ? ' relay' : '';
        if ($name === '') {
            $name = '—';
        }
        $label = '[' . $id . '] ' . $name;
        if ($kind !== '') {
            $label .= ' (' . $kind . ')';
        }
        $label .= $relay;
        $lines[] = '- ' . $label;
    }
    return implode("\n", $lines);
}

function telethonHandleStatus(string $baseUrl, string $token, int $telethonId, int $vkFromId): string
{
    $result = telethonApiRequest($baseUrl, $token, 'status', [
        'telethon_id' => $telethonId,
        'vk_from_id' => $vkFromId,
    ]);
    if (!$result['ok']) {
        return 'telethon error: ' . telethonFormatError($result);
    }
    $data = $result['data'] ?? [];
    if (!is_array($data)) {
        return 'telethon error: invalid_response';
    }
    if (!empty($data['authorized'])) {
        return 'telethon: profile #' . $telethonId . ' — authorized';
    }
    $status = (string) ($data['status'] ?? 'unknown');
    return 'telethon: profile #' . $telethonId . ' — ' . $status;
}

function telethonHandleSend(
    string $baseUrl,
    string $token,
    int $telethonId,
    int $vkFromId,
    int $contactId,
    string $message
): string {
    $result = telethonApiRequest($baseUrl, $token, 'send', [
        'telethon_id' => $telethonId,
        'vk_from_id' => $vkFromId,
        'contact_id' => $contactId,
        'message' => $message,
    ]);
    if (!$result['ok']) {
        return 'telethon error: ' . telethonFormatError($result);
    }
    $data = $result['data'] ?? [];
    $reply = 'telethon: sent to contact #' . $contactId;
    if (!empty($data['relay_enabled'])) {
        $reply .= ', relay on';
    }
    return $reply;
}

function telethonHandleRelay(
    string $baseUrl,
    string $token,
    int $telethonId,
    int $vkFromId,
    int $contactId,
    ?bool $enabled
): string {
    $payload = [
        'telethon_id' => $telethonId,
        'vk_from_id' => $vkFromId,
        'contact_id' => $contactId,
    ];
    if ($enabled !== null) {
        $payload['enabled'] = $enabled;
    }
    $result = telethonApiRequest($baseUrl, $token, 'relay', $payload);
    if (!$result['ok']) {
        return 'telethon error: ' . telethonFormatError($result);
    }
    $state = !empty($result['data']['relay_enabled']) ? 'on' : 'off';
    return 'telethon: relay [' . $contactId . '] ' . $state;
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok: bool, data?: array<string, mixed>, error?: string, http?: int}
 */
function telethonApiRequest(string $baseUrl, string $token, string $action, array $payload): array
{
    $payload['action'] = $action;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        return ['ok' => false, 'error' => 'encode_failed'];
    }

    $url = rtrim($baseUrl, '/') . '/bot_api.php';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Authorization: ' . $token,
    ]);

    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        return ['ok' => false, 'error' => 'curl_error: ' . $curlError];
    }

    $data = json_decode((string) ($response ?? ''), true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'invalid_response', 'http' => $httpCode];
    }

    if ($httpCode < 200 || $httpCode >= 300 || empty($data['ok'])) {
        $err = (string) ($data['error'] ?? 'http_' . $httpCode);
        return ['ok' => false, 'error' => $err, 'http' => $httpCode, 'data' => $data];
    }

    return ['ok' => true, 'data' => $data];
}

/**
 * @param array<string, mixed> $result
 */
function telethonFormatError(array $result): string
{
    if (isset($result['error']) && is_string($result['error'])) {
        return $result['error'];
    }
    if (isset($result['http'])) {
        return 'http_' . (string) $result['http'];
    }
    return 'unknown';
}

/**
 * @return array<int, int>
 */
function telethonParsePeerMap(string $raw): array
{
    $map = [];
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return $map;
    }

    foreach (explode(',', $trimmed) as $pair) {
        $candidate = trim($pair);
        if ($candidate === '') {
            continue;
        }
        $chunks = explode(':', $candidate, 2);
        if (count($chunks) !== 2) {
            continue;
        }
        $fromIdRaw = trim($chunks[0]);
        $profileIdRaw = trim($chunks[1]);
        if ($fromIdRaw === '' || $profileIdRaw === '' || !ctype_digit($fromIdRaw) || !ctype_digit($profileIdRaw)) {
            continue;
        }
        $fromId = (int) $fromIdRaw;
        $profileId = (int) $profileIdRaw;
        if ($fromId > 0 && $profileId > 0) {
            $map[$fromId] = $profileId;
        }
    }

    return $map;
}
