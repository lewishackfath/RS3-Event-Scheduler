<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/lib/discord.php';

header('Content-Type: application/json; charset=UTF-8');

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$guildId = trim((string) appConfig()['discord']['guild_id']);

function discordLookupErrorMessage(Throwable $e): string
{
    $message = $e->getMessage();

    if (str_contains($message, 'Discord API error (401)')) {
        return 'Discord rejected the bot token. Check DISCORD_BOT_TOKEN. Use the raw token only; do not include the Bot prefix.';
    }

    if (str_contains($message, 'Discord API error (403)')) {
        return 'Discord rejected the request with Missing Access/Missing Permissions. Confirm the bot is in this server and has permission to view the server/channels.';
    }

    if (str_contains($message, 'Discord API error (404)')) {
        return 'Discord could not find that guild. Check DISCORD_GUILD_ID and confirm the bot has been invited to that exact server.';
    }

    return $message;
}

try {
    if ($guildId === '') {
        throw new RuntimeException('DISCORD_GUILD_ID is not configured.');
    }

    if ($type === 'members') {
        $query = trim((string) ($_GET['q'] ?? ''));
        echo json_encode(['items' => searchGuildMembers($guildId, $query, 20)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($type === 'channels') {
        echo json_encode(['items' => fetchGuildChannels($guildId)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($type === 'roles') {
        echo json_encode(['items' => fetchGuildRoles($guildId)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($type === 'diagnostics') {
        $bot = fetchDiscordCurrentBotUser();
        $guild = fetchDiscordGuild($guildId);
        $channels = fetchGuildChannels($guildId);
        $roles = fetchGuildRoles($guildId);

        echo json_encode([
            'ok' => true,
            'bot' => [
                'id' => (string) ($bot['id'] ?? ''),
                'username' => (string) ($bot['username'] ?? ''),
            ],
            'guild' => [
                'id' => (string) ($guild['id'] ?? $guildId),
                'name' => (string) ($guild['name'] ?? ''),
            ],
            'counts' => [
                'channels' => count($channels),
                'roles' => count($roles),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid lookup type.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => discordLookupErrorMessage($e)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
