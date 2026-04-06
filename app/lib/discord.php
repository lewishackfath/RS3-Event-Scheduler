<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function discordApiRequest(string $method, string $endpoint, array $payload = []): array
{
    $token = appConfig()['discord']['bot_token'];
    if ($token === '') {
        throw new RuntimeException('DISCORD_BOT_TOKEN is not configured.');
    }

    $url = 'https://discord.com/api/v10' . $endpoint;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialise cURL.');
    }

    $headers = [
        'Authorization: Bot ' . $token,
        'Content-Type: application/json',
        'User-Agent: ClanEventScheduler/1.0',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);

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
