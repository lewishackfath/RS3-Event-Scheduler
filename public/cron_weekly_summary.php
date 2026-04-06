<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/DiscordPostingService.php';

requireCronToken();

$date = isset($_GET['date']) ? (string) $_GET['date'] : null;
header('Content-Type: application/json');

try {
    $results = (new DiscordPostingService())->postWeeklySummaryForWeek($date);
    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
