<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/services/DiscordPostingService.php';

$token = (string) ($_GET['token'] ?? '');
if ($token === '' || $token !== (string) appConfig()['app']['cron_token']) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

try {
    $service = new DiscordPostingService();
    $results = $service->syncPendingDiscordItemsForToday();
    echo 'OK';
    if (!empty($results)) {
        echo "\n";
        foreach ($results as $row) {
            echo '- ' . ($row['event_name'] ?? 'Event') . ': ' . ($row['message'] ?? '') . "\n";
        }
    } else {
        echo "\nNo pending Discord sync items for today.";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage();
}
