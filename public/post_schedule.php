<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';
require_once dirname(__DIR__) . '/app/services/DiscordPostingService.php';
require_once dirname(__DIR__) . '/app/lib/event_embeds.php';

$selectedDate = isset($_GET['date']) ? (string) $_GET['date'] : null;
$range = weekRangeFromDate($selectedDate);
$events = (new EventRepository())->getForWeek($range['week_start_utc'], $range['week_end_utc']);
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $results = (new DiscordPostingService())->postEvents($events);
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
            <h3 style="margin-top:0;"><?= e($event['event_name']) ?></h3>
            <div class="muted" style="margin-bottom:10px;">Preview only · <?= e($local->format('l j F Y g:i A')) ?></div>
            <table>
                <tr><th>Event Date/Time</th><td><code><?= e($embed['fields'][0]['value']) ?></code></td></tr>
                <tr><th>Gametime</th><td><?= e($embed['fields'][1]['value']) ?></td></tr>
                <tr><th>Local Time</th><td><code><?= e($embed['fields'][2]['value']) ?></code></td></tr>
                <tr><th>Event Host</th><td><?= e($embed['fields'][3]['value']) ?></td></tr>
                <tr><th>Image</th><td><?= e($embed['image']['url'] ?? 'None') ?></td></tr>
                <tr><th>Discord Channel</th><td><?= e($event['discord_channel_id'] ?: appConfig()['clan']['default_discord_channel_id']) ?></td></tr>
            </table>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php renderFooter(); ?>
