<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';
require_once dirname(__DIR__) . '/app/services/DiscordPostingService.php';
require_once dirname(__DIR__) . '/app/lib/event_embeds.php';

$selectedDate = isset($_GET['date']) ? (string) $_GET['date'] : null;
$range = weekRangeFromDate($selectedDate);
$events = (new EventRepository())->getForWeek($range['week_start_utc'], $range['week_end_utc']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        (new DiscordPostingService())->postEvents($events);
        setFlash('success', 'Posting complete.');
        redirect('post_schedule.php?date=' . urlencode($range['week_start_local']->format('Y-m-d')));
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('post_schedule.php?date=' . urlencode($range['week_start_local']->format('Y-m-d')));
    }
}

renderHeader('Post to Discord');
?>
<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Post Week of <?= e($range['week_start_local']->format('j F Y')) ?></h2>
    <p class="muted">This posts one embed per event in chronological order.</p>
    <form method="post">
        <div class="actions">
            <button class="btn" type="submit">Post This Week to Discord</button>
            <a class="btn secondary" href="index.php?date=<?= e($range['week_start_local']->format('Y-m-d')) ?>">Back to Schedule</a>
        </div>
    </form>
</div>

<?php if (empty($events)): ?>
    <div class="card"><p>No events found for this week.</p></div>
<?php else: ?>
    <?php foreach ($events as $event): $embed = buildEventEmbed($event); $local = utcToClanLocal($event['event_start_utc']); ?>
        <div class="card" style="margin-bottom:16px;">
            <div class="event-card-row" style="border-bottom:none;padding:0;">
                <div class="event-card-image-wrap preview-thumb-wrap">
                    <?php $thumb = eventDisplayImageUrl($event); ?>
                    <?php if ($thumb !== ''): ?>
                        <img class="event-card-image" src="<?= e($thumb) ?>" alt="<?= e($event['event_name']) ?>">
                    <?php else: ?>
                        <div class="event-card-image placeholder">No image</div>
                    <?php endif; ?>
                </div>
                <div class="event-card-body">
                    <h3 style="margin-top:0;"><?= e($event['event_name']) ?></h3>
                    <div class="muted" style="margin-bottom:10px;">Embed preview · <?= e($local->format('l j F Y g:i A')) ?></div>
                    <div class="event-meta-grid" style="margin-bottom:12px;">
                        <?php foreach ($embed['fields'] as $field): ?>
                            <div><strong><?= e($field['name']) ?>:</strong><br><?= nl2br(e($field['value'])) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="muted">Discord embed will show the event image as a side thumbnail.</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php renderFooter(); ?>
