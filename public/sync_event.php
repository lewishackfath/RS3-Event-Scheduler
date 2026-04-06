<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/services/DiscordPostingService.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    setFlash('error', 'Invalid event.');
    redirect('index.php');
}

try {
    $service = new DiscordPostingService();
    $results = $service->syncEventById($id);
    setFlash('success', !empty($results) ? implode(' | ', $results) : 'Event synced.');
} catch (Throwable $e) {
    setFlash('error', $e->getMessage());
}

redirect('index.php');
