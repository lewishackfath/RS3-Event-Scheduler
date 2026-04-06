<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';
require_once dirname(__DIR__) . '/app/services/DiscordPostingService.php';
require_once dirname(__DIR__) . '/app/lib/event_embeds.php';

$selectedDate = isset($_GET['date']) ? (string) $_GET['date'] : null;
$selectedDay = isset($_GET['day']) ? (string) $_GET['day'] : ($selectedDate ?? null);
$weekRange = weekRangeFromDate($selectedDate);
$dayRange = dayRangeFromDate($selectedDay);
$events = (new EventRepository())->getForWeek($weekRange['week_start_utc'], $weekRange['week_end_utc']);
$todayEvents = (new EventRepository())->getForDay($dayRange['day_start_utc'], $dayRange['day_end_utc']);

$service = new DiscordPostingService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'weekly_summary') {
            $results = $service->postWeeklySummaryForWeek((string) ($_POST['week_date'] ?? ''));
            $message = implode(' | ', array_map(static fn(array $r): string => (string) ($r['message'] ?? ''), $results));
            setFlash('success', $message !== '' ? $message : 'Weekly summary completed.');
            redirect('post_schedule.php?date=' . urlencode((string) ($_POST['week_date'] ?? $weekRange['week_start_local']->format('Y-m-d'))));
        }
        if ($action === 'sync_pending') {
            $results = $service->syncPendingDiscordItemsForToday((string) ($_POST['day_date'] ?? ''));
            $message = implode(' | ', array_map(static fn(array $r): string => (string) ($r['event_name'] ?? $r['scope'] ?? 'Result') . ': ' . (string) ($r['message'] ?? ''), $results));
            setFlash('success', $message !== '' ? $message : 'Discord sync completed.');
            redirect('post_schedule.php?day=' . urlencode((string) ($_POST['day_date'] ?? $dayRange['day_start_local']->format('Y-m-d'))));
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('post_schedule.php?date=' . urlencode($weekRange['week_start_local']->format('Y-m-d')) . '&day=' . urlencode($dayRange['day_start_local']->format('Y-m-d')));
    }
}

renderHeader('Discord Publishing');
?>
<div class="grid mb-16">
    <div class="card">
        <h2 class="mt-0">Weekly Summary</h2>
        <p class="muted">Posts or updates one summary embed for the selected week.</p>
        <form method="post">
            <input type="hidden" name="action" value="weekly_summary">
            <div class="field">
                <label for="week_date">Week Date</label>
                <input type="date" id="week_date" name="week_date" value="<?= e($weekRange['week_start_local']->format('Y-m-d')) ?>">
            </div>
            <div class="actions">
                <button class="btn" type="submit">Post Weekly Summary</button>
                <a class="btn secondary" href="index.php?date=<?= e($weekRange['week_start_local']->format('Y-m-d')) ?>">View Week</a>
            </div>
        </form>
    </div>

</div>

    <div class="card">
        <h2 class="mt-0">Sync Missing Discord Items</h2>
        <p class="muted">Creates any missing daily embeds or native Discord events for the selected day, while preserving your existing .env Discord channel configuration.</p>
        <form method="post">
            <input type="hidden" name="action" value="sync_pending">
            <div class="field">
                <label for="sync_day_date">Event Date</label>
                <input type="date" id="sync_day_date" name="day_date" value="<?= e($dayRange['day_start_local']->format('Y-m-d')) ?>">
            </div>
            <div class="actions">
                <button class="btn secondary" type="submit">Run Discord Sync</button>
            </div>
        </form>
    </div>

<div class="card mb-16">
    <h3 class="mt-0">Cron Commands</h3>
    <div class="muted mb-8">Run these directly from your server cron. No web token is required.</div>
    <?php $cronDir = dirname(__DIR__) . '/cron'; ?>
    <div class="break-all mb-10"><strong>Weekly:</strong> <code>php <?= e($cronDir . '/cron_weekly_summary.php') ?></code></div>
    <div class="break-all"><strong>Discord Sync:</strong> <code>php <?= e($cronDir . '/cron_sync_discord.php') ?></code></div>
<div class="muted mt-8">Optional date argument for weekly and sync scripts: <code>php /full/path/to/script.php 2026-04-07</code></div>
</div>

<div class="grid">
    <div class="card">
        <h3 class="mt-0">Weekly Summary Preview</h3>
        <?php $summaryEmbed = buildWeeklySummaryEmbed($events, $weekRange['week_start_local']); ?>
        <div><strong><?= e($summaryEmbed['title']) ?></strong></div>
        <div class="muted mt-6 mb-12"><?= e($summaryEmbed['description']) ?></div>
        <?php foreach (($summaryEmbed['fields'] ?? []) as $field): ?>
            <div class="mb-10">
                <strong><?= e((string) $field['name']) ?></strong><br>
                <?= nl2br(e((string) $field['value'])) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h3 class="mt-0">Events for <?= e($dayRange['day_start_local']->format('l j F Y')) ?></h3>
        <?php if (empty($todayEvents)): ?>
            <p>No events for this day.</p>
        <?php else: ?>
            <?php foreach ($todayEvents as $event): ?>
                <div class="mb-10" style="padding:10px 0;border-bottom:1px dashed rgba(255,255,255,.08);">
                    <strong><?= e((string) $event['event_name']) ?></strong><br>
                    <span class="muted"><?= e(utcToClanLocal((string) $event['event_start_utc'])->format('g:i A')) ?></span><br>
                    <span class="muted">Daily Post: <?= !empty($event['discord_daily_message_id']) ? 'Created' : 'Pending' ?></span><br>
                    <span class="muted">Native Discord Event: <?= !empty($event['discord_scheduled_event_id']) ? 'Created' : 'Pending' ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php renderFooter(); ?>
