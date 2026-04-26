<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/DiscordPostingService.php';


$date = $argv[1] ?? null;

try {
    $service = new DiscordPostingService();
    $results = $service->syncPendingDiscordItemsForToday(is_string($date) ? $date : null);

    echo 'OK';
    if (!empty($results)) {
        echo PHP_EOL;
        foreach ($results as $row) {
            echo '- ' . ($row['event_name'] ?? 'Event') . ': ' . ($row['message'] ?? '') . PHP_EOL;
        }
    } else {
        echo PHP_EOL . 'No pending Discord sync items for this run.' . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
