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
    $affectedWeekDates = [];

    if ($seriesId !== '' && $scope !== 'single') {
        $fromUtc = $scope === 'future' ? (string) $event['event_start_utc'] : null;
        foreach ($repo->getSeriesEvents($seriesId, $fromUtc) as $seriesEvent) {
            $affectedWeekDates[] = weekStartDateFromUtc((string) $seriesEvent['event_start_utc']);
        }
    } else {
        $affectedWeekDates[] = weekStartDateFromUtc((string) $event['event_start_utc']);
    }

    $deletedCount = (new EventService())->deleteEvent($repo, $event, $scope);
    (new DiscordPostingService())->refreshWeeklySummariesForDates($affectedWeekDates);

    if ($seriesId !== '' && $scope !== 'single') {
        setFlash('success', 'Deleted ' . $deletedCount . ' events from the recurring series. Weekly summary refreshed.');
    } else {
        setFlash('success', 'Event deleted successfully. Weekly summary refreshed.');
    }

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
