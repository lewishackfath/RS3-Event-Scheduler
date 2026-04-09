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
        'User-Agent: ClanEventScheduler/1.7',
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

function fetchDiscordChannel(string $channelId): array
{
    return discordApiRequest('GET', '/channels/' . rawurlencode($channelId));
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

    $allowedTypes = [0, 2, 4, 5];
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
            'parent_id' => isset($channel['parent_id']) ? (string) $channel['parent_id'] : null,
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

function discordPermissionValue(string $name): string
{
    static $map = [
        'VIEW_CHANNEL' => '1024',
        'CONNECT' => '1048576',
        'SPEAK' => '2097152',
    ];

    if (!isset($map[$name])) {
        throw new InvalidArgumentException('Unsupported Discord permission: ' . $name);
    }

    return $map[$name];
}

function discordPermissionSum(array $permissions): string
{
    $sum = 0;
    foreach ($permissions as $permission) {
        $sum += (int) discordPermissionValue((string) $permission);
    }
    return (string) $sum;
}

function buildDiscordScheduledEventPayload(array $event, ?string $locationOverride = null): array
{
    $durationMinutes = (int) ($event['duration_minutes'] ?? 0);
    if ($durationMinutes <= 0) {
        $durationMinutes = max(1, (int) appConfig()['discord']['default_event_duration_minutes']);
    }

    $startUtc = new DateTimeImmutable((string) $event['event_start_utc'], utcTimezone());
    $endUtc = $startUtc->modify('+' . $durationMinutes . ' minutes');
    $voiceChannelId = trim((string) ($event['discord_voice_channel_id'] ?? ''));

    $payload = [
        'name' => (string) $event['event_name'],
        'privacy_level' => 2,
        'scheduled_start_time' => $startUtc->format(DateTimeInterface::ATOM),
        'scheduled_end_time' => $endUtc->format(DateTimeInterface::ATOM),
        'description' => trim((string) ($event['event_description'] ?? '')),
    ];

    if ($voiceChannelId !== '') {
        $payload['entity_type'] = 2;
        $payload['channel_id'] = $voiceChannelId;
        $payload['entity_metadata'] = null;
    } else {
        $location = trim((string) ($locationOverride ?? ($event['event_location'] ?? '') ?? appConfig()['discord']['event_location_default'] ?? ''));
        if ($location === '') {
            $location = 'RuneScape - In Game';
        }

        $payload['entity_type'] = 3;
        $payload['channel_id'] = null;
        $payload['entity_metadata'] = [
            'location' => $location,
        ];
    }

    return $payload;
}

function createScheduledEvent(array $event, ?string $locationOverride = null): array
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

function editScheduledEvent(string $scheduledEventId, array $event, ?string $locationOverride = null, array $extraPayload = []): array
{
    $guildId = trim((string) appConfig()['discord']['guild_id']);
    if ($guildId === '') {
        throw new RuntimeException('DISCORD_GUILD_ID is not configured.');
    }

    return discordApiRequest(
        'PATCH',
        '/guilds/' . rawurlencode($guildId) . '/scheduled-events/' . rawurlencode($scheduledEventId),
        array_merge(buildDiscordScheduledEventPayload($event, $locationOverride), $extraPayload)
    );
}

function fetchScheduledEvent(string $scheduledEventId, bool $withUserCount = false): array
{
    $guildId = trim((string) appConfig()['discord']['guild_id']);
    if ($guildId === '') {
        throw new RuntimeException('DISCORD_GUILD_ID is not configured.');
    }

    return discordApiRequest(
        'GET',
        '/guilds/' . rawurlencode($guildId) . '/scheduled-events/' . rawurlencode($scheduledEventId),
        [],
        ['with_user_count' => $withUserCount ? 'true' : 'false']
    );
}

function fetchScheduledEventUsers(string $scheduledEventId): array
{
    $guildId = trim((string) appConfig()['discord']['guild_id']);
    if ($guildId === '') {
        throw new RuntimeException('DISCORD_GUILD_ID is not configured.');
    }

    $users = [];
    $after = null;

    do {
        $query = [
            'limit' => 100,
            'with_member' => 'true',
        ];
        if ($after !== null) {
            $query['after'] = $after;
        }

        $page = discordApiRequest(
            'GET',
            '/guilds/' . rawurlencode($guildId) . '/scheduled-events/' . rawurlencode($scheduledEventId) . '/users',
            [],
            $query
        );

        if (!is_array($page) || $page === []) {
            break;
        }

        foreach ($page as $row) {
            if (!is_array($row) || empty($row['user']['id'])) {
                continue;
            }
            $userId = (string) $row['user']['id'];
            $users[$userId] = $userId;
            $after = $userId;
        }
    } while (count($page) === 100);

    return array_values($users);
}

function deleteScheduledEvent(string $scheduledEventId): void
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

function createGuildVoiceChannel(string $name, array $permissionOverwrites = [], ?string $parentId = null, int $userLimit = 0): array
{
    $guildId = trim((string) appConfig()['discord']['guild_id']);
    if ($guildId === '') {
        throw new RuntimeException('DISCORD_GUILD_ID is not configured.');
    }

    $payload = [
        'name' => mb_substr(trim($name) !== '' ? trim($name) : 'Event Voice', 0, 100),
        'type' => 2,
        'user_limit' => max(0, $userLimit),
        'permission_overwrites' => array_values($permissionOverwrites),
    ];
    if ($parentId !== null && trim($parentId) !== '') {
        $payload['parent_id'] = trim($parentId);
    }

    return discordApiRequest('POST', '/guilds/' . rawurlencode($guildId) . '/channels', $payload);
}

function editDiscordChannelPermissions(string $channelId, string $overwriteId, string $overwriteType, array $allowPermissions = [], array $denyPermissions = []): void
{
    discordApiRequest('PUT', '/channels/' . rawurlencode($channelId) . '/permissions/' . rawurlencode($overwriteId), [
        'type' => $overwriteType,
        'allow' => discordPermissionSum($allowPermissions),
        'deny' => discordPermissionSum($denyPermissions),
    ]);
}

function deleteDiscordChannelPermission(string $channelId, string $overwriteId): void
{
    discordApiRequest('DELETE', '/channels/' . rawurlencode($channelId) . '/permissions/' . rawurlencode($overwriteId));
}

function deleteDiscordChannel(string $channelId): void
{
    discordApiRequest('DELETE', '/channels/' . rawurlencode($channelId));
}

function getUserVoiceState(string $guildId, string $userId): ?array
{
    try {
        $state = discordApiRequest('GET', '/guilds/' . rawurlencode($guildId) . '/voice-states/' . rawurlencode($userId));
    } catch (Throwable $e) {
        return null;
    }

    return is_array($state) ? $state : null;
}

function discordEditMessage(string $channelId, string $messageId, array $payload): array
{
    return discordApiRequest('PATCH', '/channels/' . rawurlencode($channelId) . '/messages/' . rawurlencode($messageId), $payload);
}

function discordDeleteScheduledEvent(string $guildId, string $scheduledEventId): void
{
    discordApiRequest('DELETE', '/guilds/' . rawurlencode($guildId) . '/scheduled-events/' . rawurlencode($scheduledEventId));
}
