<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/DiscordPostingService.php';

$date = $argv[1] ?? null;

try {
    $results = (new DiscordPostingService())->postWeeklySummaryForWeek(is_string($date) ? $date : null);
    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}
