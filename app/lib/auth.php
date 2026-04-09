<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';

function discordOauthConfig(): array
{
    return appConfig()['discord_oauth'];
}

function isDiscordAuthConfigured(): bool
{
    $cfg = discordOauthConfig();
    return trim((string) ($cfg['client_id'] ?? '')) !== ''
        && trim((string) ($cfg['client_secret'] ?? '')) !== ''
        && trim((string) ($cfg['redirect_uri'] ?? '')) !== ''
        && trim((string) ($cfg['guild_id'] ?? '')) !== ''
        && (!empty($cfg['admin_role_ids']) || !empty($cfg['admin_user_ids']));
}

function authBaseUrl(): string
{
    return 'https://discord.com/api/v10';
}

function currentUser(): ?array
{
    return $_SESSION['auth_user'] ?? null;
}

function isAuthenticated(): bool
{
    return currentUser() !== null;
}

function authGuildId(): string
{
    return (string) (discordOauthConfig()['guild_id'] ?? '');
}

function authorisedRoleIds(): array
{
    return array_values(array_map('strval', discordOauthConfig()['admin_role_ids'] ?? []));
}

function authorisedUserIds(): array
{
    return array_values(array_map('strval', discordOauthConfig()['admin_user_ids'] ?? []));
}

function userIsDirectlyAuthorised(array $discordUser): bool
{
    $discordUserId = (string) ($discordUser['id'] ?? '');
    return $discordUserId !== '' && in_array($discordUserId, authorisedUserIds(), true);
}

function memberHasRequiredRole(array $member): bool
{
    $memberRoles = array_map('strval', (array) ($member['roles'] ?? []));
    if ($memberRoles === []) {
        return false;
    }

    return count(array_intersect($memberRoles, authorisedRoleIds())) > 0;
}

function userCanAccessApp(array $discordUser, array $member): bool
{
    return userIsDirectlyAuthorised($discordUser) || memberHasRequiredRole($member);
}

function setAuthenticatedUser(array $discordUser, array $member): void
{
    $discordUserId = (string) ($discordUser['id'] ?? '');
    $displayName = trim((string) (($discordUser['global_name'] ?? '') !== '' ? $discordUser['global_name'] : ($discordUser['username'] ?? 'Discord User')));
    $avatarUrl = '';
    if (!empty($discordUser['avatar'])) {
        $avatarUrl = sprintf('https://cdn.discordapp.com/avatars/%s/%s.png', $discordUserId, $discordUser['avatar']);
    }

    $_SESSION['auth_user'] = [
        'discord_user_id' => $discordUserId,
        'username' => (string) ($discordUser['username'] ?? ''),
        'display_name' => $displayName,
        'avatar_url' => $avatarUrl,
        'guild_id' => authGuildId(),
        'roles' => array_values(array_map('strval', (array) ($member['roles'] ?? []))),
    ];
}

function logoutUser(): void
{
    unset($_SESSION['auth_user'], $_SESSION['oauth_state'], $_SESSION['return_to']);
}

function rememberReturnTo(string $path): void
{
    $_SESSION['return_to'] = $path;
}

function consumeReturnTo(): string
{
    $returnTo = (string) ($_SESSION['return_to'] ?? 'index.php');
    unset($_SESSION['return_to']);
    return $returnTo !== '' ? $returnTo : 'index.php';
}

function buildDiscordLoginUrl(): string
{
    if (!isDiscordAuthConfigured()) {
        throw new RuntimeException('Discord OAuth is not configured.');
    }

    $cfg = discordOauthConfig();
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = [
        'client_id' => $cfg['client_id'],
        'redirect_uri' => $cfg['redirect_uri'],
        'response_type' => 'code',
        'scope' => $cfg['scope'],
        'prompt' => 'consent',
        'state' => $state,
    ];

    return 'https://discord.com/oauth2/authorize?' . http_build_query($params);
}

function oauthRequest(string $method, string $url, array $headers = [], ?array $payload = null, bool $formEncoded = false): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialise cURL.');
    }

    $finalHeaders = $headers;
    $postFields = null;

    if ($payload !== null) {
        if ($formEncoded) {
            $postFields = http_build_query($payload);
            $finalHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $postFields = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $finalHeaders[] = 'Content-Type: application/json';
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Discord OAuth request failed: ' . $error);
    }

    $decoded = json_decode($response, true);
    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $response;
        throw new RuntimeException('Discord OAuth error (' . $statusCode . '): ' . $message);
    }

    return is_array($decoded) ? $decoded : [];
}

function exchangeDiscordCodeForToken(string $code): array
{
    $cfg = discordOauthConfig();

    return oauthRequest('POST', authBaseUrl() . '/oauth2/token', [], [
        'client_id' => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $cfg['redirect_uri'],
    ], true);
}

function fetchDiscordUser(string $accessToken): array
{
    return oauthRequest('GET', authBaseUrl() . '/users/@me', [
        'Authorization: Bearer ' . $accessToken,
        'User-Agent: ClanEventScheduler/1.2',
    ]);
}

function fetchDiscordCurrentUserGuildMember(string $accessToken, string $guildId): array
{
    return oauthRequest('GET', authBaseUrl() . '/users/@me/guilds/' . rawurlencode($guildId) . '/member', [
        'Authorization: Bearer ' . $accessToken,
        'User-Agent: ClanEventScheduler/1.2',
    ]);
}

function requireAuth(): void
{
    if (isAuthenticated()) {
        return;
    }

    $path = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    rememberReturnTo($path . ($query !== '' ? '?' . $query : ''));
    redirect('login.php');
}
