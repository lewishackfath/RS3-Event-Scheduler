<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

ob_start();

function discordLookupJsonResponse(array $payload, int $statusCode = 200): void
{
    if (ob_get_length() !== false) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) $error['type'], $fatalTypes, true)) {
        return;
    }

    if (!headers_sent()) {
        discordLookupJsonResponse([
            'error' => 'PHP fatal error while loading Discord lookup: ' . (string) ($error['message'] ?? 'Unknown fatal error.'),
            'file' => basename((string) ($error['file'] ?? '')),
            'line' => (int) ($error['line'] ?? 0),
        ], 500);
    }
});

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    require_once dirname(__DIR__, 2) . '/app/config/config.php';
    require_once dirname(__DIR__, 2) . '/app/lib/helpers.php';
    require_once dirname(__DIR__, 2) . '/app/lib/auth.php';
    require_once dirname(__DIR__, 2) . '/app/lib/discord.php';
} catch (Throwable $e) {
    discordLookupJsonResponse([
        'error' => 'Failed to initialise Discord lookup: ' . $e->getMessage(),
    ], 500);
}

function discordLookupErrorMessage(Throwable $e): string
{
    $message = $e->getMessage();

    if (str_contains($message, 'Discord API error (401)')) {
        return 'Discord rejected the bot token. Check DISCORD_BOT_TOKEN. Use the raw bot token; the app will add the Bot prefix automatically.';
    }

    if (str_contains($message, 'Discord API error (403)')) {
        return 'Discord rejected the request with Missing Access or Missing Permissions. Confirm the bot is in the server set by DISCORD_GUILD_ID and can view the server/channels.';
    }

    if (str_contains($message, 'Discord API error (404)')) {
        return 'Discord could not find that guild. Check DISCORD_GUILD_ID and confirm the bot has been invited to that exact server.';
    }

    if (str_contains($message, 'cURL error')) {
        return 'The server could not reach Discord over HTTPS: ' . $message;
    }

    return $message;
}

function discordLookupDiagnosticStep(string $name, callable $callback): array
{
    try {
        $data = $callback();
        return [
            'name' => $name,
            'ok' => true,
            'data' => $data,
        ];
    } catch (Throwable $e) {
        return [
            'name' => $name,
            'ok' => false,
            'error' => discordLookupErrorMessage($e),
            'raw_error' => $e->getMessage(),
        ];
    }
}

function discordLookupRequestToken(): string
{
    $headerToken = trim((string) ($_SERVER['HTTP_X_DISCORD_LOOKUP_TOKEN'] ?? ''));
    if ($headerToken !== '') {
        return $headerToken;
    }

    return trim((string) ($_GET['lookup_token'] ?? ''));
}

function discordLookupTokenIsValid(): bool
{
    return verifyDiscordLookupToken(discordLookupRequestToken());
}

function discordLookupSessionDiagnostics(): array
{
    $cookieName = session_name();
    $cookieNames = array_keys($_COOKIE);
    $token = discordLookupRequestToken();

    return [
        'session_name' => $cookieName,
        'session_id_present' => session_id() !== '',
        'session_cookie_received' => isset($_COOKIE[$cookieName]),
        'auth_user_present' => isset($_SESSION['auth_user']),
        'lookup_token_present' => $token !== '',
        'lookup_token_valid' => $token !== '' && verifyDiscordLookupToken($token),
        'received_cookie_names' => $cookieNames,
        'request' => [
            'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
            'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'referer' => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            'origin' => (string) ($_SERVER['HTTP_ORIGIN'] ?? ''),
            'is_ajax' => strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest',
        ],
    ];
}

try {
    $type = strtolower(trim((string) ($_GET['type'] ?? '')));
    $isAuthorisedLookup = isAuthenticated() || discordLookupTokenIsValid() || $type === 'diagnostics';

    if (!$isAuthorisedLookup) {
        discordLookupJsonResponse([
            'error' => 'You are not logged in, or your lookup token has expired. Refresh the event form and retry the Discord lookup.',
            'session' => discordLookupSessionDiagnostics(),
        ], 401);
    }

    $guildId = trim((string) appConfig()['discord']['guild_id']);

    if ($guildId === '') {
        discordLookupJsonResponse([
            'error' => 'DISCORD_GUILD_ID is not configured.',
        ], 500);
    }

    if ($type === 'members') {
        $query = trim((string) ($_GET['q'] ?? ''));
        discordLookupJsonResponse(['items' => searchGuildMembers($guildId, $query, 20)]);
    }

    if ($type === 'channels') {
        discordLookupJsonResponse(['items' => fetchGuildChannels($guildId)]);
    }

    if ($type === 'roles') {
        discordLookupJsonResponse(['items' => fetchGuildRoles($guildId)]);
    }

    if ($type === 'diagnostics') {
        $token = discordBotToken();

        $botStep = discordLookupDiagnosticStep('bot_user', static fn (): array => fetchDiscordCurrentBotUser());
        $guildStep = discordLookupDiagnosticStep('guild', static fn (): array => fetchDiscordGuild($guildId));
        $channelStep = discordLookupDiagnosticStep('channels', static fn (): array => fetchGuildChannels($guildId));
        $roleStep = discordLookupDiagnosticStep('roles', static fn (): array => fetchGuildRoles($guildId));

        $channels = [];
        if (!empty($channelStep['ok']) && is_array($channelStep['data'])) {
            $channels = $channelStep['data'];
        }

        $roles = [];
        if (!empty($roleStep['ok']) && is_array($roleStep['data'])) {
            $roles = $roleStep['data'];
        }

        $botData = !empty($botStep['ok']) && is_array($botStep['data']) ? $botStep['data'] : [];
        $guildData = !empty($guildStep['ok']) && is_array($guildStep['data']) ? $guildStep['data'] : [];

        discordLookupJsonResponse([
            'ok' => !empty($botStep['ok']) && !empty($guildStep['ok']) && !empty($channelStep['ok']) && !empty($roleStep['ok']),
            'environment' => [
                'php_version' => PHP_VERSION,
                'curl_loaded' => function_exists('curl_init'),
                'guild_id_configured' => $guildId !== '',
                'bot_token_configured' => $token !== '',
                'bot_token_prefix_handled' => true,
                'logged_in_discord_user_id' => (string) (currentUser()['discord_user_id'] ?? ''),
            ],
            'session' => discordLookupSessionDiagnostics(),
            'bot' => [
                'id' => (string) ($botData['id'] ?? ''),
                'username' => (string) ($botData['username'] ?? ''),
            ],
            'guild' => [
                'configured_id' => $guildId,
                'resolved_id' => (string) ($guildData['id'] ?? ''),
                'name' => (string) ($guildData['name'] ?? ''),
            ],
            'counts' => [
                'channels' => count($channels),
                'roles' => count($roles),
            ],
            'steps' => [
                'bot_user' => $botStep,
                'guild' => $guildStep,
                'channels' => $channelStep,
                'roles' => $roleStep,
            ],
        ]);
    }

    discordLookupJsonResponse(['error' => 'Invalid lookup type.'], 400);
} catch (Throwable $e) {
    discordLookupJsonResponse([
        'error' => discordLookupErrorMessage($e),
        'raw_error' => $e->getMessage(),
    ], 500);
}
