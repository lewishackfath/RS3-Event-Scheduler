<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/time.php';
require_once __DIR__ . '/helpers.php';

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
        'User-Agent: ClanEventScheduler/1.6',
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

function editDiscordMessage(string $channelId, string $messageId, string $content, array $embeds = []): array
{
    return discordApiRequest('PATCH', '/channels/' . $channelId . '/messages/' . $messageId, [
        'content' => $content,
        'embeds' => $embeds,
        'allowed_mentions' => ['parse' => []],
    ]);
}


function encodeDiscordEmojiForUrl(string $emoji): string
{
    $emoji = trim($emoji);
    if ($emoji === '') {
        return '';
    }

    if (preg_match('/^<?a?:[A-Za-z0-9_~]+:(\d+)>?$/', $emoji, $matches)) {
        $name = preg_replace('/^<?a?:([^:]+):(\d+)>?$/', '$1', $emoji);
        return rawurlencode($name . ':' . $matches[1]);
    }

    return rawurlencode($emoji);
}

function addDiscordReaction(string $channelId, string $messageId, string $emoji): void
{
    $encodedEmoji = encodeDiscordEmojiForUrl($emoji);
    if ($encodedEmoji === '') {
        return;
    }

    discordApiRequest('PUT', '/channels/' . rawurlencode($channelId) . '/messages/' . rawurlencode($messageId) . '/reactions/' . $encodedEmoji . '/@me');
}

function removeDiscordOwnReaction(string $channelId, string $messageId, string $emoji): void
{
    $encodedEmoji = encodeDiscordEmojiForUrl($emoji);
    if ($encodedEmoji === '') {
        return;
    }

    discordApiRequest('DELETE', '/channels/' . rawurlencode($channelId) . '/messages/' . rawurlencode($messageId) . '/reactions/' . $encodedEmoji . '/@me');
}

function clearDiscordReactions(string $channelId, string $messageId): void
{
    discordApiRequest('DELETE', '/channels/' . rawurlencode($channelId) . '/messages/' . rawurlencode($messageId) . '/reactions');
}

function fetchDiscordMessage(string $channelId, string $messageId): array
{
    return discordApiRequest('GET', '/channels/' . rawurlencode($channelId) . '/messages/' . rawurlencode($messageId));
}

function deleteDiscordMessage(string $channelId, string $messageId): void
{
    discordApiRequest('DELETE', '/channels/' . rawurlencode($channelId) . '/messages/' . rawurlencode($messageId));
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

function buildDiscordScheduledEventPayload(array $event, ?string $locationOverride = null): array
{
    $durationMinutes = (int) ($event['duration_minutes'] ?? 0);
    if ($durationMinutes <= 0) {
        $durationMinutes = max(1, (int) appConfig()['discord']['default_event_duration_minutes']);
    }

    $startUtc = new DateTimeImmutable((string) $event['event_start_utc'], utcTimezone());
    $endUtc = $startUtc->modify('+' . $durationMinutes . ' minutes');
    $location = trim((string) ($locationOverride ?? ($event['event_location'] ?? '') ?? appConfig()['discord']['event_location_default'] ?? ''));
    if ($location === '') {
        $location = 'RuneScape - In Game';
    }

    return [
        'name' => (string) $event['event_name'],
        'privacy_level' => 2,
        'scheduled_start_time' => $startUtc->format(DateTimeInterface::ATOM),
        'scheduled_end_time' => $endUtc->format(DateTimeInterface::ATOM),
        'description' => trim((string) ($event['event_description'] ?? '')),
        'entity_type' => 3,
        'entity_metadata' => [
            'location' => $location,
        ],
    ];
}

function createExternalScheduledEvent(array $event, ?string $locationOverride = null): array
{
    $guildId = trim((string) appConfig()['discord']['guild_id']);
    if ($guildId === '') {
        throw new RuntimeException('DISCORD_GUILD_ID is not configured.');
    }

    return discordApiRequest(
        'POST',
        '/guilds/' . rawurlencode($guildId) . '/scheduled-events',
        buildDiscordScheduledEventPayload($event, $locationOverride)
    );
}

function editExternalScheduledEvent(string $scheduledEventId, array $event, ?string $locationOverride = null): array
{
    $guildId = trim((string) appConfig()['discord']['guild_id']);
    if ($guildId === '') {
        throw new RuntimeException('DISCORD_GUILD_ID is not configured.');
    }

    return discordApiRequest(
        'PATCH',
        '/guilds/' . rawurlencode($guildId) . '/scheduled-events/' . rawurlencode($scheduledEventId),
        buildDiscordScheduledEventPayload($event, $locationOverride)
    );
}

function deleteExternalScheduledEvent(string $scheduledEventId): void
{
    $guildId = trim((string) appConfig()['discord']['guild_id']);
    if ($guildId === '') {
        throw new RuntimeException('DISCORD_GUILD_ID is not configured.');
    }

    discordApiRequest('DELETE', '/guilds/' . rawurlencode($guildId) . '/scheduled-events/' . rawurlencode($scheduledEventId));
}

function buildDiscordScheduledEventUrl(string $eventId): string
{
    $guildId = trim((string) appConfig()['discord']['guild_id']);
    if ($guildId === '' || trim($eventId) === '') {
        return '';
    }

    return 'https://discord.com/events/' . rawurlencode($guildId) . '/' . rawurlencode($eventId);
}


function discordEditMessage(string $channelId, string $messageId, array $payload): array
{
    return discordApiRequest('PATCH', '/channels/' . rawurlencode($channelId) . '/messages/' . rawurlencode($messageId), $payload);
}

function discordDeleteScheduledEvent(string $guildId, string $scheduledEventId): void
{
    discordApiRequest('DELETE', '/guilds/' . rawurlencode($guildId) . '/scheduled-events/' . rawurlencode($scheduledEventId));
}
