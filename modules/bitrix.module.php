<?php

declare(strict_types=1);

/**
 * @param array<int, int> $peerBitrixMap
 */
function bitrixDispatchCommand(
    string $text,
    int $fromId,
    array $peerBitrixMap,
    string $bitrix24Url,
    string $bitrix24Token
): ?string {
    $tokens = bitrixTokenizeCommand($text);
    if (count($tokens) === 0) {
        return null;
    }

    $module = mb_strtolower($tokens[0], 'UTF-8');
    if ($module !== 'bitrix') {
        return null;
    }

    if (count($tokens) > 3) {
        return bitrixUsage();
    }
    $time = null;
    $action = isset($tokens[1]) ? mb_strtolower($tokens[1], 'UTF-8') : '';
    if ($action === 'timeman') {
        $date = isset($tokens[2]) ? trim($tokens[2]) : '';
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return bitrixUsage();
        }
    } elseif (!in_array($action, ['start', 'pause', 'resume', 'finish'], true)) {
        return bitrixUsage();
    } elseif (isset($tokens[2])) {
        if (!preg_match('/^(\d{1,2})(?::([0-5]\d))?$/', $tokens[2], $matches) || (int) $matches[1] > 23) {
            return 'Некорректное время. Используйте час от 0 до 23: например, 9, 9:00 или 09:00.';
        }
        $time = sprintf('%02d:%02d', (int) $matches[1], (int) ($matches[2] ?? 0));
    }

    $bitrixUserId = $peerBitrixMap[$fromId] ?? null;
    if (!is_int($bitrixUserId) || $bitrixUserId <= 0) {
        return 'bitrix error: bitrix user not linked';
    }

    if ($action === 'timeman') {
        $date = isset($tokens[2]) ? trim($tokens[2]) : '';
        $result = bitrixFetchTimeman($bitrix24Url, $bitrix24Token, $bitrixUserId, $date === '' ? null : $date);
        if (!$result['ok']) {
            return (string) ($result['error'] ?? 'bitrix error');
        }
        $data = $result['data'] ?? [];
        if (!is_array($data)) {
            return 'invalid_response';
        }
        if (isset($data['error'])) {
            return (string) $data['error'];
        }
        return bitrixFormatTimeman($data);
    }

    $result = bitrixSendTimeman($bitrix24Url, $bitrix24Token, $bitrixUserId, $action, $time);
    if (!$result['ok']) {
        return (string) ($result['error'] ?? 'bitrix error');
    }
    $status = bitrixFetchTimeman($bitrix24Url, $bitrix24Token, $bitrixUserId, null);
    if (!$status['ok']) {
        return 'Команда выполнена, но не удалось получить табель: ' . ($status['error'] ?? 'неизвестная ошибка');
    }
    return bitrixFormatTimeman($status['data']);
}

function bitrixUsage(): string
{
    return "Команды: bitrix start|pause|resume|finish [H или HH:MM] (сегодня)\nbitrix timeman [YYYY-MM-DD]";
}

/**
 * @return array<string, mixed>
 */
function bitrixSendTimeman(string $bitrix24Url, string $bitrix24Token, int $bitrixUserId, string $action, ?string $time = null): array
{
    if ($bitrix24Url === '') {
        return ['ok' => false, 'error' => 'bitrix_url_missing'];
    }
    if ($bitrixUserId <= 0) {
        return ['ok' => false, 'error' => 'bitrix_user_id_missing'];
    }

    $payload = json_encode([
        'userId' => $bitrixUserId,
        'do' => $action,
        'time' => $time,
    ], JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return ['ok' => false, 'error' => 'encode_failed'];
    }

    $url = rtrim($bitrix24Url, '/') . '/admins/api.php?action=postTimeman';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $headers = ['Content-Type: application/json'];
    if ($bitrix24Token !== '') {
        $headers[] = 'X-Authorization: ' . $bitrix24Token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        return ['ok' => false, 'error' => 'curl_error: ' . $curlError];
    }
    return bitrixDecodeTimemanResponse((string) ($response ?? ''), $httpCode, true);
}

/**
 * @return array<string, mixed>
 */
function bitrixFetchTimeman(string $bitrix24Url, string $bitrix24Token, int $bitrixUserId, ?string $date): array
{
    if ($bitrix24Url === '') {
        return ['ok' => false, 'error' => 'bitrix_url_missing'];
    }
    if ($bitrixUserId <= 0) {
        return ['ok' => false, 'error' => 'bitrix_user_id_missing'];
    }

    $query = http_build_query([
        'action' => 'getTimeman',
        'userId' => $bitrixUserId,
        'date' => $date ?: null,
    ]);
    $url = rtrim($bitrix24Url, '/') . '/admins/api.php?' . $query;
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $headers = [];
    if ($bitrix24Token !== '') {
        $headers[] = 'X-Authorization: ' . $bitrix24Token;
    }
    if ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        return ['ok' => false, 'error' => 'curl_error: ' . $curlError];
    }
    return bitrixDecodeTimemanResponse((string) ($response ?? ''), $httpCode, false);
}

/** @return array<string, mixed> */
function bitrixDecodeTimemanResponse(string $response, int $httpCode, bool $mutation): array
{
    $trimmed = trim($response);
    $data = json_decode($trimmed, true);
    if (is_array($data)) {
        $error = $data['ERROR'] ?? $data['error'] ?? null;
        if ($error === null && ($httpCode < 200 || $httpCode >= 300 || ($mutation && empty($data['ok']) && empty($data['OK'])))) {
            $error = $data['message'] ?? null;
        }
        if (is_string($error) && $error !== '') {
            $messages = [
                'No timeman entry for this date; start the work day first' => 'Рабочий день сегодня ещё не начат. Сначала выполните bitrix start [время].',
                'No timeman entry for this date' => 'За указанную дату нет отчётного периода.',
                'Work day for this date is already closed' => 'Рабочий день за эту дату уже завершён.',
                'Entry is not paused; pause first before resume' => 'Рабочий день не на паузе. Сначала выполните bitrix pause [время].',
                'Finish time must be after start time' => 'Время завершения должно быть позже времени начала рабочего дня.',
                'Break duration (TIME_LEAKS) exceeds work day duration' => 'Длительность перерывов превышает длительность рабочего дня.',
            ];
            return ['ok' => false, 'error' => $messages[$error] ?? $error];
        }
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'error' => 'Ошибка API Bitrix: HTTP ' . $httpCode . '.'];
    }
    if ($mutation && ((is_array($data) && (!empty($data['ok']) || !empty($data['OK']))) || strcasecmp($trimmed, 'ok') === 0)) {
        return ['ok' => true];
    }
    if (!$mutation && is_array($data)) {
        return ['ok' => true, 'data' => $data];
    }
    return ['ok' => false, 'error' => $trimmed === '' ? 'empty_response' : 'invalid_response'];
}

function bitrixFormatTimeman(array $data): string
{
    $date = (string) ($data['DATE'] ?? '');
    $start = (string) ($data['START'] ?? '');
    $finish = $data['FINISH'] ?? null;
    $finish = $finish === null ? '' : (string) $finish;
    $breakSeconds = (int) ($data['BREAK_SECONDS'] ?? 0);
    $status = strtoupper((string) ($data['STATUS'] ?? ''));

    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    $parts = [];
    if ($date !== '' && $date !== $today) {
        $parts[] = 'Дата: ' . $date;
    }

    $startText = '';
    if ($start !== '') {
        if ($date !== '' && strpos($start, $date) === 0) {
            $startText = substr($start, 11);
        } else {
            $startText = $start;
        }
    }
    if ($startText !== '') {
        $parts[] = 'Начало: ' . $startText;
    }

    if ($start !== '') {
        $startAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start);
        if ($startAt) {
            $finishAt = null;
            if ($finish !== '' && $status === 'CLOSED') {
                $finishAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $finish);
            }
            if (!$finishAt) {
                $finishAt = new DateTimeImmutable('now');
            }
            $duration = $finishAt->getTimestamp() - $startAt->getTimestamp();
            if ($duration < 0) {
                $duration = 0;
            }
            $hours = intdiv($duration, 3600);
            $minutes = intdiv($duration % 3600, 60);
            $seconds = $duration % 60;
            $parts[] = sprintf('Длительность: %02d:%02d:%02d', $hours, $minutes, $seconds);
        }
    }

    $hours = intdiv($breakSeconds, 3600);
    $minutes = intdiv($breakSeconds % 3600, 60);
    $seconds = $breakSeconds % 60;
    $parts[] = sprintf('Перерыв: %02d:%02d:%02d', $hours, $minutes, $seconds);

    $statusText = 'Неизвестно';
    if ($status === 'OPENED') {
        $statusText = 'Работаю';
    } elseif ($status === 'PAUSED') {
        $statusText = 'Пауза';
    } elseif ($status === 'CLOSED') {
        $statusText = 'Завершён';
    } elseif ($status !== '') {
        $statusText = $status;
    }
    $parts[] = 'Статус: ' . $statusText;

    $finishText = '';
    if ($finish !== '') {
        if ($date !== '' && strpos($finish, $date) === 0) {
            $finishText = substr($finish, 11);
        } else {
            $finishText = $finish;
        }
    }
    if ($finishText !== '') {
        $parts[] = ($status === 'PAUSED' ? 'Пауза с: ' : 'Завершение: ') . $finishText;
    }

    return implode(', ', $parts);
}

/**
 * @return array<int, string>
 */
function bitrixTokenizeCommand(string $text): array
{
    $tokens = [];
    $normalized = str_replace(['«', '»', '“', '”'], '"', $text);
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    $normalized = trim($normalized);

    $current = '';
    $inQuote = false;
    $length = strlen($normalized);
    for ($i = 0; $i < $length; $i++) {
        $char = $normalized[$i];
        if ($char === '"') {
            $inQuote = !$inQuote;
            continue;
        }
        if (!$inQuote && $char === ' ') {
            if ($current !== '') {
                $tokens[] = $current;
                $current = '';
            }
            continue;
        }
        $current .= $char;
    }

    if ($current !== '') {
        $tokens[] = $current;
    }

    return $tokens;
}

/**
 * @return array<int, int>
 */
function bitrixParsePeerBitrixMap(string $raw): array
{
    $map = [];
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return $map;
    }

    $pairs = explode(',', $trimmed);
    foreach ($pairs as $pair) {
        $candidate = trim($pair);
        if ($candidate === '') {
            continue;
        }
        $chunks = explode(':', $candidate, 2);
        if (count($chunks) !== 2) {
            continue;
        }

        $fromIdRaw = trim($chunks[0]);
        $bitrixIdRaw = trim($chunks[1]);
        if ($fromIdRaw === '' || $bitrixIdRaw === '' || !ctype_digit($fromIdRaw) || !ctype_digit($bitrixIdRaw)) {
            continue;
        }

        $fromId = (int) $fromIdRaw;
        $bitrixId = (int) $bitrixIdRaw;
        if ($fromId > 0 && $bitrixId > 0) {
            $map[$fromId] = $bitrixId;
        }
    }

    return $map;
}
