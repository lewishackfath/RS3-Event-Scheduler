<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function discordApiRequest(string $method, string $endpoint, array $payload = [], array $query = []): array
{
    $token = appConfig()['discord']['bot_token'];
    if ($token === '') {
        throw new RuntimeException('DISCORD_BOT_TOKEN is not configured.');
    }

    $url = 'https://discord.com/api/v10' . $endpoint;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialise cURL.');
    }

    $headers = [
        'Authorization: Bot ' . $token,
        'Content-Type: application/json',
        'User-Agent: ClanEventScheduler/1.3',
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ];

    if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Discord API request failed: ' . $error);
    }

    $decoded = json_decode($response, true);
    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $response;
        throw new RuntimeException('Discord API error (' . $statusCode . '): ' . $message);
    }

    return is_array($decoded) ? $decoded : [];
}

function postDiscordMessage(string $channelId, string $content, array $embeds = []): array
{
    return discordApiRequest('POST', '/channels/' . $channelId . '/messages', [
        'content' => $content,
        'embeds' => $embeds,
        'allowed_mentions' => ['parse' => []],
    ]);
}

function fetchGuildChannels(string $guildId): array
{
    $channels = discordApiRequest('GET', '/guilds/' . rawurlencode($guildId) . '/channels');
    if (!is_array($channels)) {
        return [];
    }

    $allowedTypes = [0, 5];
    $filtered = array_values(array_filter($channels, static function ($channel) use ($allowedTypes): bool {
        return is_array($channel)
            && isset($channel['id'], $channel['name'], $channel['type'])
            && in_array((int) $channel['type'], $allowedTypes, true);
    }));

    usort($filtered, static function (array $a, array $b): int {
        $posA = (int) ($a['position'] ?? 0);
        $posB = (int) ($b['position'] ?? 0);
        if ($posA === $posB) {
            return strcmp((string) $a['name'], (string) $b['name']);
        }
        return $posA <=> $posB;
    });

    return array_map(static function (array $channel): array {
        return [
            'id' => (string) $channel['id'],
            'name' => (string) $channel['name'],
            'type' => (int) $channel['type'],
        ];
    }, $filtered);
}

function searchGuildMembers(string $guildId, string $query, int $limit = 20): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $members = discordApiRequest('GET', '/guilds/' . rawurlencode($guildId) . '/members/search', [], [
        'query' => $query,
        'limit' => max(1, min(1000, $limit)),
    ]);

    if (!is_array($members)) {
        return [];
    }

    return array_values(array_map(static function (array $member): array {
        $user = (array) ($member['user'] ?? []);
        $username = (string) ($user['username'] ?? 'Unknown');
        $globalName = trim((string) ($user['global_name'] ?? ''));
        $nick = trim((string) ($member['nick'] ?? ''));
        $displayName = $nick !== '' ? $nick : ($globalName !== '' ? $globalName : $username);

        return [
            'id' => (string) ($user['id'] ?? ''),
            'username' => $username,
            'display_name' => $displayName,
            'global_name' => $globalName,
            'nick' => $nick,
        ];
    }, array_filter($members, static fn ($member): bool => is_array($member) && !empty($member['user']['id']))));
}

function fetchGuildMember(string $guildId, string $userId): ?array
{
    $userId = trim($userId);
    if ($userId === '') {
        return null;
    }

    try {
        $member = discordApiRequest('GET', '/guilds/' . rawurlencode($guildId) . '/members/' . rawurlencode($userId));
    } catch (Throwable $e) {
        return null;
    }

    if (!is_array($member) || empty($member['user']['id'])) {
        return null;
    }

    $user = (array) ($member['user'] ?? []);
    $username = (string) ($user['username'] ?? 'Unknown');
    $globalName = trim((string) ($user['global_name'] ?? ''));
    $nick = trim((string) ($member['nick'] ?? ''));
    $displayName = $nick !== '' ? $nick : ($globalName !== '' ? $globalName : $username);

    return [
        'id' => (string) $user['id'],
        'username' => $username,
        'display_name' => $displayName,
        'global_name' => $globalName,
        'nick' => $nick,
    ];
}
