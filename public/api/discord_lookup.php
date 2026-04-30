<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/lib/discord.php';

header('Content-Type: application/json; charset=UTF-8');

$type = (string) ($_GET['type'] ?? '');
$guildId = (string) appConfig()['discord_oauth']['guild_id'];

try {
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

    http_response_code(400);
    echo json_encode(['error' => 'Invalid lookup type.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
