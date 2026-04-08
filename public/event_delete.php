<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/../app/services/DiscordPostingService.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
$repo = new EventRepository();
$event = $id > 0 ? $repo->getById($id) : null;

if (!$event) {
    setFlash('error', 'Event not found.');
    redirect('index.php');
}

$seriesId = trim((string) ($event['recurring_series_id'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scope = (string) ($_POST['delete_scope'] ?? 'single');
    $discord = new DiscordPostingService();
    $discordMessages = [];

    if ($seriesId !== '' && $scope === 'future') {
        $discordMessages = $discord->deleteEventArtifactsForSeries($seriesId, (string) $event['event_start_utc']);
    } elseif ($seriesId !== '' && $scope === 'all') {
        $discordMessages = $discord->deleteEventArtifactsForSeries($seriesId, null);
    } else {
        $discordMessages = $discord->deleteEventArtifactsById((int) $event['id']);
    }

    $deletedCount = (new EventService())->deleteEvent($repo, $event, $scope);

    if ($seriesId !== '' && $scope !== 'single') {
        $message = 'Deleted ' . $deletedCount . ' events from the recurring series.';
    } else {
        $message = 'Event deleted successfully.';
    }

    if ($discordMessages !== []) {
        $message .= ' ' . implode(' | ', $discordMessages);
    }

    setFlash('success', trim($message));
    redirect('index.php');
}

renderHeader('Delete Event');
?>
<div class="card">
    <h2 style="margin-top:0;">Delete Event</h2>
    <p>You're about to delete <strong><?= e($event['event_name']) ?></strong>.</p>

    <form method="post">
        <input type="hidden" name="id" value="<?= (int) $event['id'] ?>">

        <?php if ($seriesId !== ''): ?>
            <div class="field">
                <label>What would you like to delete?</label>
                <label><input type="radio" name="delete_scope" value="single" checked> This event only</label>
                <label><input type="radio" name="delete_scope" value="future"> This event and future events</label>
                <label><input type="radio" name="delete_scope" value="all"> Entire series</label>
            </div>
        <?php else: ?>
            <input type="hidden" name="delete_scope" value="single">
        <?php endif; ?>

        <div class="actions">
            <button class="btn danger" type="submit">Delete</button>
            <a class="btn secondary" href="index.php">Cancel</a>
        </div>
    </form>
</div>
<?php
renderFooter();
