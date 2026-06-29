<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/time.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';

function discordBotToken(): string
{
    $token = trim((string) appConfig()['discord']['bot_token']);

    // The Discord API expects the raw bot token after the "Bot " prefix.
    // This allows either raw tokens or accidentally pasted "Bot xxxxx" values
    // in .env without turning the header into "Bot Bot xxxxx".
    if (stripos($token, 'Bot ') === 0) {
        $token = trim(substr($token, 4));
    }

    return $token;
}


function discordLookupTokenSecret(): string
{
    $parts = [
        discordBotToken(),
        (string) (appConfig()['discord_oauth']['client_secret'] ?? ''),
        (string) (appConfig()['app']['url'] ?? ''),
        'ClanEventScheduler:DiscordLookup:v1',
    ];

    return hash('sha256', implode('|', $parts));
}

function discordLookupBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function discordLookupBase64UrlDecode(string $value): string
{
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($value, true);
    return $decoded === false ? '' : $decoded;
}

function issueDiscordLookupToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $sessionId = session_id();
    if ($sessionId === '') {
        throw new RuntimeException('Cannot issue Discord lookup token without an active PHP session.');
    }

    $issuedAt = time();
    $nonce = bin2hex(random_bytes(8));
    $signaturePayload = $issuedAt . '|' . $sessionId . '|' . $nonce;
    $signature = hash_hmac('sha256', $signaturePayload, discordLookupTokenSecret());

    return discordLookupBase64UrlEncode(json_encode([
        'iat' => $issuedAt,
        'sid' => $sessionId,
        'nonce' => $nonce,
        'sig' => $signature,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
}

function verifyDiscordLookupToken(string $token, int $maxAgeSeconds = 21600): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $sessionId = session_id();
    if ($sessionId === '') {
        return false;
    }

    $decoded = json_decode(discordLookupBase64UrlDecode($token), true);
    if (!is_array($decoded)) {
        return false;
    }

    $issuedAt = (int) ($decoded['iat'] ?? 0);
    $tokenSessionId = (string) ($decoded['sid'] ?? '');
    $nonce = (string) ($decoded['nonce'] ?? '');
    $signature = (string) ($decoded['sig'] ?? '');

    if ($issuedAt <= 0 || $tokenSessionId === '' || $nonce === '' || $signature === '') {
        return false;
    }

    if (!hash_equals($sessionId, $tokenSessionId)) {
        return false;
    }

    $now = time();
    if ($issuedAt > $now + 60 || ($now - $issuedAt) > $maxAgeSeconds) {
        return false;
    }

    $expected = hash_hmac('sha256', $issuedAt . '|' . $tokenSessionId . '|' . $nonce, discordLookupTokenSecret());
    return hash_equals($expected, $signature);
}



/** @return array<string, array{permissions:array<int,string>,context:string}> */
function discordPermissionCatalogue(): array
{
    return [
        'lookup_bot_user' => [
            'permissions' => [],
            'context' => 'Validates the bot token with Discord.',
        ],
        'lookup_guild' => [
            'permissions' => [],
            'context' => 'Requires the bot to be in the configured guild.',
        ],
        'lookup_guild_channels' => [
            'permissions' => ['View Channels'],
            'context' => 'Used to populate channel dropdowns. Channel/category overwrites can still hide individual channels from the bot.',
        ],
        'lookup_guild_roles' => [
            'permissions' => [],
            'context' => 'Used to populate role dropdowns. Manage Roles is not required just to list roles.',
        ],
        'lookup_guild_members' => [
            'permissions' => [],
            'context' => 'Used for host search. This also depends on the bot/app being allowed to search guild members.',
        ],
        'post_channel_message' => [
            'permissions' => ['View Channel', 'Send Messages', 'Embed Links'],
            'context' => 'Posts weekly summaries, daily event embeds, voice warnings, and other bot messages.',
        ],
        'post_channel_message_with_files' => [
            'permissions' => ['View Channel', 'Send Messages', 'Embed Links', 'Attach Files'],
            'context' => 'Posts the weekly poster gallery as grouped image attachments.',
        ],
        'edit_channel_message' => [
            'permissions' => ['View Channel', 'Send Messages', 'Embed Links'],
            'context' => 'Edits messages created by this bot. Manage Messages is not required for the bot to edit its own messages.',
        ],
        'edit_channel_message_with_files' => [
            'permissions' => ['View Channel', 'Send Messages', 'Embed Links', 'Attach Files'],
            'context' => 'Updates the weekly poster gallery message and replaces stale attachments.',
        ],
        'delete_channel_message' => [
            'permissions' => ['View Channel'],
            'context' => 'Deletes messages created by this bot. Manage Messages is only required if deleting messages created by someone else.',
        ],
        'fetch_channel_message' => [
            'permissions' => ['View Channel', 'Read Message History'],
            'context' => 'Reads existing reactions on bot-created event posts before syncing preferred role reactions.',
        ],
        'add_reaction' => [
            'permissions' => ['View Channel', 'Read Message History', 'Add Reactions'],
            'context' => 'Adds preferred-role reaction emojis to event posts. Use External Emoji is also required for external custom emojis.',
        ],
        'remove_own_reaction' => [
            'permissions' => ['View Channel', 'Read Message History'],
            'context' => 'Removes this bot’s own stale reactions from event posts.',
        ],
        'clear_reactions' => [
            'permissions' => ['View Channel', 'Read Message History', 'Manage Messages'],
            'context' => 'Only needed if the app clears all reactions from a message rather than just the bot’s own reaction.',
        ],
        'create_scheduled_event' => [
            'permissions' => ['Create Events'],
            'context' => 'Creates native Discord scheduled events for clan events.',
        ],
        'edit_scheduled_event' => [
            'permissions' => ['Create Events'],
            'context' => 'Updates native Discord scheduled events created by the bot. Manage Events is only needed for events not created by this bot.',
        ],
        'delete_scheduled_event' => [
            'permissions' => ['Create Events'],
            'context' => 'Deletes native Discord scheduled events created by the bot. Manage Events is only needed for events not created by this bot.',
        ],
        'read_scheduled_event_users' => [
            'permissions' => [],
            'context' => 'Reads users interested in a scheduled event so temporary voice channel speaking permissions can be synced.',
        ],
        'create_voice_channel' => [
            'permissions' => ['Manage Channels'],
            'context' => 'Creates temporary event voice channels. Category overwrites can still block this.',
        ],
        'edit_channel_permissions' => [
            'permissions' => ['Manage Roles'],
            'context' => 'Sets temporary voice channel permission overwrites for everyone, the host, and event subscribers.',
        ],
        'delete_channel_permission' => [
            'permissions' => ['Manage Roles'],
            'context' => 'Removes stale temporary voice channel permission overwrites.',
        ],
        'delete_channel' => [
            'permissions' => ['Manage Channels'],
            'context' => 'Deletes temporary event voice channels after the event ends.',
        ],
        'get_voice_state' => [
            'permissions' => [],
            'context' => 'Checks whether tracked users are still in a temporary voice channel before cleanup.',
        ],
        'mention_roles' => [
            'permissions' => ['Mention @everyone, @here, and All Roles'],
            'context' => 'Only required when the bot mentions roles that are not already mentionable.',
        ],
    ];
}

/** @return array{action_key:string,endpoint_template:string,required_permissions:array<int,string>,permission_context:string,channel_id:?string,guild_id:?string} */
function discordPermissionMetadata(string $method, string $endpoint, array $payload = [], bool $hasFiles = false): array
{
    $method = strtoupper($method);
    $catalogue = discordPermissionCatalogue();
    $action = 'discord_api_request';
    $template = $endpoint;
    $channelId = null;
    $guildId = null;

    $set = static function (string $key) use (&$action, $catalogue): array {
        $action = $key;
        return $catalogue[$key] ?? ['permissions' => [], 'context' => 'Discord API request.'];
    };

    $entry = ['permissions' => [], 'context' => 'Discord API request.'];

    if (preg_match('#^/users/@me$#', $endpoint)) {
        $entry = $set('lookup_bot_user');
    } elseif (preg_match('#^/guilds/(\d+)$#', $endpoint, $m)) {
        $guildId = $m[1];
        $template = '/guilds/{guild_id}';
        $entry = $set('lookup_guild');
    } elseif (preg_match('#^/guilds/(\d+)/channels$#', $endpoint, $m)) {
        $guildId = $m[1];
        $template = '/guilds/{guild_id}/channels';
        $entry = $method === 'POST' ? $set('create_voice_channel') : $set('lookup_guild_channels');
    } elseif (preg_match('#^/guilds/(\d+)/roles$#', $endpoint, $m)) {
        $guildId = $m[1];
        $template = '/guilds/{guild_id}/roles';
        $entry = $set('lookup_guild_roles');
    } elseif (preg_match('#^/guilds/(\d+)/members(?:/search|/\d+)$#', $endpoint, $m)) {
        $guildId = $m[1];
        $template = str_contains($endpoint, '/search') ? '/guilds/{guild_id}/members/search' : '/guilds/{guild_id}/members/{user_id}';
        $entry = $set('lookup_guild_members');
    } elseif (preg_match('#^/guilds/(\d+)/scheduled-events$#', $endpoint, $m)) {
        $guildId = $m[1];
        $template = '/guilds/{guild_id}/scheduled-events';
        $entry = $method === 'POST' ? $set('create_scheduled_event') : ['permissions' => [], 'context' => 'Lists guild scheduled events.'];
    } elseif (preg_match('#^/guilds/(\d+)/scheduled-events/(\d+)$#', $endpoint, $m)) {
        $guildId = $m[1];
        $template = '/guilds/{guild_id}/scheduled-events/{scheduled_event_id}';
        $entry = $method === 'PATCH' ? $set('edit_scheduled_event') : ($method === 'DELETE' ? $set('delete_scheduled_event') : ['permissions' => [], 'context' => 'Reads a native Discord scheduled event.']);
    } elseif (preg_match('#^/guilds/(\d+)/scheduled-events/(\d+)/users$#', $endpoint, $m)) {
        $guildId = $m[1];
        $template = '/guilds/{guild_id}/scheduled-events/{scheduled_event_id}/users';
        $entry = $set('read_scheduled_event_users');
    } elseif (preg_match('#^/guilds/(\d+)/voice-states/(\d+)$#', $endpoint, $m)) {
        $guildId = $m[1];
        $template = '/guilds/{guild_id}/voice-states/{user_id}';
        $entry = $set('get_voice_state');
    } elseif (preg_match('#^/channels/(\d+)$#', $endpoint, $m)) {
        $channelId = $m[1];
        $template = '/channels/{channel_id}';
        $entry = $method === 'DELETE' ? $set('delete_channel') : ['permissions' => ['View Channel'], 'context' => 'Reads channel metadata such as category and permission overwrites.'];
    } elseif (preg_match('#^/channels/(\d+)/messages$#', $endpoint, $m)) {
        $channelId = $m[1];
        $template = '/channels/{channel_id}/messages';
        $entry = $hasFiles ? $set('post_channel_message_with_files') : $set('post_channel_message');
    } elseif (preg_match('#^/channels/(\d+)/messages/(\d+)$#', $endpoint, $m)) {
        $channelId = $m[1];
        $template = '/channels/{channel_id}/messages/{message_id}';
        if ($method === 'GET') {
            $entry = $set('fetch_channel_message');
        } elseif ($method === 'PATCH') {
            $entry = $hasFiles ? $set('edit_channel_message_with_files') : $set('edit_channel_message');
        } elseif ($method === 'DELETE') {
            $entry = $set('delete_channel_message');
        }
    } elseif (preg_match('#^/channels/(\d+)/messages/(\d+)/reactions/([^/]+)/@me$#', $endpoint, $m)) {
        $channelId = $m[1];
        $template = '/channels/{channel_id}/messages/{message_id}/reactions/{emoji}/@me';
        $entry = $method === 'PUT' ? $set('add_reaction') : $set('remove_own_reaction');
    } elseif (preg_match('#^/channels/(\d+)/messages/(\d+)/reactions$#', $endpoint, $m)) {
        $channelId = $m[1];
        $template = '/channels/{channel_id}/messages/{message_id}/reactions';
        $entry = $set('clear_reactions');
    } elseif (preg_match('#^/channels/(\d+)/permissions/(\d+)$#', $endpoint, $m)) {
        $channelId = $m[1];
        $template = '/channels/{channel_id}/permissions/{overwrite_id}';
        $entry = $method === 'DELETE' ? $set('delete_channel_permission') : $set('edit_channel_permissions');
    }

    $permissions = array_values(array_unique(array_map('strval', $entry['permissions'] ?? [])));
    $context = (string) ($entry['context'] ?? 'Discord API request.');

    $allowedMentions = (array) ($payload['allowed_mentions'] ?? []);
    $mentionedRoles = (array) ($allowedMentions['roles'] ?? []);
    $content = (string) ($payload['content'] ?? '');
    if ($mentionedRoles !== [] || preg_match('/<@&\d{15,32}>/', $content)) {
        $mention = $catalogue['mention_roles'];
        $permissions = array_values(array_unique(array_merge($permissions, $mention['permissions'])));
        $context .= ' ' . $mention['context'];
    }

    return [
        'action_key' => $action,
        'endpoint_template' => $template,
        'required_permissions' => $permissions,
        'permission_context' => $context,
        'channel_id' => $channelId,
        'guild_id' => $guildId,
    ];
}

function logDiscordPermissionObservation(string $method, string $endpoint, array $metadata, bool $success, int $statusCode = 0, ?array $decoded = null, string $rawError = ''): void
{
    try {
        if (!function_exists('db') || !function_exists('currentClanId')) {
            return;
        }

        $errorCode = null;
        $errorMessage = $rawError;
        if (is_array($decoded)) {
            if (isset($decoded['code'])) {
                $errorCode = (string) $decoded['code'];
            }
            if (isset($decoded['message'])) {
                $errorMessage = (string) $decoded['message'];
            }
        }

        $stmt = db()->prepare(
            'INSERT INTO discord_permission_audit (
                clan_id, action_key, http_method, endpoint, endpoint_template,
                required_permissions, permission_context, channel_id, guild_id,
                success, status_code, discord_error_code, error_message, created_at_utc
             ) VALUES (
                :clan_id, :action_key, :http_method, :endpoint, :endpoint_template,
                :required_permissions, :permission_context, :channel_id, :guild_id,
                :success, :status_code, :discord_error_code, :error_message, UTC_TIMESTAMP()
             )'
        );
        $stmt->execute([
            'clan_id' => currentClanId() > 0 ? currentClanId() : null,
            'action_key' => (string) ($metadata['action_key'] ?? 'discord_api_request'),
            'http_method' => strtoupper($method),
            'endpoint' => mb_substr($endpoint, 0, 255),
            'endpoint_template' => mb_substr((string) ($metadata['endpoint_template'] ?? $endpoint), 0, 255),
            'required_permissions' => implode(', ', (array) ($metadata['required_permissions'] ?? [])),
            'permission_context' => (string) ($metadata['permission_context'] ?? ''),
            'channel_id' => $metadata['channel_id'] ?? null,
            'guild_id' => $metadata['guild_id'] ?? null,
            'success' => $success ? 1 : 0,
            'status_code' => $statusCode > 0 ? $statusCode : null,
            'discord_error_code' => $errorCode,
            'error_message' => $errorMessage !== '' ? mb_substr($errorMessage, 0, 65535) : null,
        ]);
    } catch (Throwable $e) {
        // Permission logging should never break the actual Discord action.
    }
}

function discordApiRequest(string $method, string $endpoint, array $payload = [], array $query = []): array
{
    $metadata = discordPermissionMetadata($method, $endpoint, $payload, false);
    $token = discordBotToken();
    if ($token === '') {
        logDiscordPermissionObservation($method, $endpoint, $metadata, false, 0, null, 'DISCORD_BOT_TOKEN is not configured.');
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
    $errorNumber = curl_errno($ch);
    curl_close($ch);

    if ($response === false) {
        logDiscordPermissionObservation($method, $endpoint, $metadata, false, 0, null, 'cURL error ' . $errorNumber . ': ' . $error);
        throw new RuntimeException('Discord API request failed: cURL error ' . $errorNumber . ': ' . $error);
    }

    if ($statusCode === 0) {
        logDiscordPermissionObservation($method, $endpoint, $metadata, false, 0, null, 'No HTTP response from Discord. cURL error ' . $errorNumber . ': ' . $error);
        throw new RuntimeException('Discord API request failed: no HTTP response from Discord. cURL error ' . $errorNumber . ': ' . $error);
    }

    $decoded = json_decode($response, true);
    logDiscordPermissionObservation($method, $endpoint, $metadata, $statusCode >= 200 && $statusCode < 300, $statusCode, is_array($decoded) ? $decoded : null, is_array($decoded) ? '' : $response);
    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $response;
        throw new RuntimeException('Discord API error (' . $statusCode . '): ' . $message);
    }

    return is_array($decoded) ? $decoded : [];
}


function discordApiMultipartRequest(string $method, string $endpoint, array $payload = [], array $files = [], array $query = []): array
{
    $metadata = discordPermissionMetadata($method, $endpoint, $payload, $files !== []);
    $token = discordBotToken();
    if ($token === '') {
        logDiscordPermissionObservation($method, $endpoint, $metadata, false, 0, null, 'DISCORD_BOT_TOKEN is not configured.');
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
        'User-Agent: ClanEventScheduler/1.7',
    ];

    $postFields = [
        'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];

    foreach (array_values($files) as $index => $file) {
        $path = (string) ($file['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            continue;
        }

        $filename = trim((string) ($file['filename'] ?? 'poster-' . ($index + 1) . '.jpg'));
        if ($filename === '') {
            $filename = 'poster-' . ($index + 1) . '.jpg';
        }

        $mime = trim((string) ($file['content_type'] ?? 'application/octet-stream'));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        $postFields['files[' . $index . ']'] = new CURLFile($path, $mime, $filename);
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => $postFields,
    ];

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errorNumber = curl_errno($ch);
    curl_close($ch);

    if ($response === false) {
        logDiscordPermissionObservation($method, $endpoint, $metadata, false, 0, null, 'cURL error ' . $errorNumber . ': ' . $error);
        throw new RuntimeException('Discord API request failed: cURL error ' . $errorNumber . ': ' . $error);
    }

    if ($statusCode === 0) {
        logDiscordPermissionObservation($method, $endpoint, $metadata, false, 0, null, 'No HTTP response from Discord. cURL error ' . $errorNumber . ': ' . $error);
        throw new RuntimeException('Discord API request failed: no HTTP response from Discord. cURL error ' . $errorNumber . ': ' . $error);
    }

    $decoded = json_decode($response, true);
    logDiscordPermissionObservation($method, $endpoint, $metadata, $statusCode >= 200 && $statusCode < 300, $statusCode, is_array($decoded) ? $decoded : null, is_array($decoded) ? '' : $response);
    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $response;
        throw new RuntimeException('Discord API error (' . $statusCode . '): ' . $message);
    }

    return is_array($decoded) ? $decoded : [];
}

function buildDiscordAttachmentPayload(array $files): array
{
    $attachments = [];
    foreach (array_values($files) as $index => $file) {
        $filename = trim((string) ($file['filename'] ?? 'poster-' . ($index + 1) . '.jpg'));
        if ($filename === '') {
            $filename = 'poster-' . ($index + 1) . '.jpg';
        }

        $attachment = [
            'id' => $index,
            'filename' => $filename,
        ];

        $description = trim((string) ($file['description'] ?? ''));
        if ($description !== '') {
            $attachment['description'] = mb_substr($description, 0, 1024);
        }

        $attachments[] = $attachment;
    }

    return $attachments;
}

function buildAllowedMentionsPayload(array $roleIds = []): array
{
    $roleIds = array_values(array_unique(array_filter(array_map(static function ($roleId): string {
        return trim((string) $roleId);
    }, $roleIds), static fn (string $roleId): bool => preg_match('/^\d{15,32}$/', $roleId) === 1)));

    $payload = ['parse' => []];
    if ($roleIds !== []) {
        $payload['roles'] = $roleIds;
    }

    return $payload;
}

function postDiscordMessage(string $channelId, string $content, array $embeds = [], array $allowedRoleIds = []): array
{
    return discordApiRequest('POST', '/channels/' . $channelId . '/messages', [
        'content' => $content,
        'embeds' => $embeds,
        'allowed_mentions' => buildAllowedMentionsPayload($allowedRoleIds),
    ]);
}

function postDiscordMessageWithFiles(string $channelId, string $content, array $embeds = [], array $files = [], array $allowedRoleIds = []): array
{
    $payload = [
        'content' => $content,
        'embeds' => $embeds,
        'allowed_mentions' => buildAllowedMentionsPayload($allowedRoleIds),
    ];

    if ($files !== []) {
        $payload['attachments'] = buildDiscordAttachmentPayload($files);
    }

    return discordApiMultipartRequest('POST', '/channels/' . rawurlencode($channelId) . '/messages', $payload, $files);
}

function editDiscordMessage(string $channelId, string $messageId, string $content, array $embeds = [], array $allowedRoleIds = []): array
{
    return discordApiRequest('PATCH', '/channels/' . $channelId . '/messages/' . $messageId, [
        'content' => $content,
        'embeds' => $embeds,
        'allowed_mentions' => buildAllowedMentionsPayload($allowedRoleIds),
    ]);
}

function editDiscordMessageWithFiles(string $channelId, string $messageId, string $content, array $embeds = [], array $files = [], array $allowedRoleIds = []): array
{
    $payload = [
        'content' => $content,
        'embeds' => $embeds,
        'allowed_mentions' => buildAllowedMentionsPayload($allowedRoleIds),
        // Supplying only the new attachment list replaces any stale files on the message.
        'attachments' => buildDiscordAttachmentPayload($files),
    ];

    return discordApiMultipartRequest('PATCH', '/channels/' . rawurlencode($channelId) . '/messages/' . rawurlencode($messageId), $payload, $files);
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

function fetchDiscordCurrentBotUser(): array
{
    return discordApiRequest('GET', '/users/@me');
}

function fetchDiscordGuild(string $guildId): array
{
    return discordApiRequest('GET', '/guilds/' . rawurlencode($guildId));
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

function fetchGuildRoles(string $guildId): array
{
    $roles = discordApiRequest('GET', '/guilds/' . rawurlencode($guildId) . '/roles');
    if (!is_array($roles)) {
        return [];
    }

    $filtered = array_values(array_filter($roles, static function ($role): bool {
        if (!is_array($role) || empty($role['id']) || empty($role['name'])) {
            return false;
        }
        if (!empty($role['managed'])) {
            return false;
        }
        return (string) $role['name'] !== '';
    }));

    usort($filtered, static function (array $a, array $b): int {
        $posA = (int) ($a['position'] ?? 0);
        $posB = (int) ($b['position'] ?? 0);
        if ($posA === $posB) {
            return strcasecmp((string) $a['name'], (string) $b['name']);
        }
        return $posB <=> $posA;
    });

    return array_map(static function (array $role): array {
        return [
            'id' => (string) $role['id'],
            'name' => (string) $role['name'],
            'mentionable' => !empty($role['mentionable']),
            'position' => (int) ($role['position'] ?? 0),
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
        $durationMinutes = max(1, (int) discordSettings()['default_event_duration_minutes']);
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
        $location = trim((string) ($locationOverride ?? ($event['event_location'] ?? '') ?? discordSettings()['event_location_default'] ?? ''));
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
