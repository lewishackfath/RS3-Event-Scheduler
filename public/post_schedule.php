<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';
require_once dirname(__DIR__) . '/app/services/DiscordPostingService.php';
require_once dirname(__DIR__) . '/app/lib/event_embeds.php';

$selectedDate = isset($_GET['date']) ? (string) $_GET['date'] : (isset($_GET['day']) ? (string) $_GET['day'] : null);
$weekRange = weekRangeFromDate($selectedDate);
$dayRange = dayRangeFromDate($selectedDate);

$repo = new EventRepository();
$events = $repo->getForWeek($weekRange['week_start_utc'], $weekRange['week_end_utc']);
$todayEvents = $repo->getForDay($dayRange['day_start_utc'], $dayRange['day_end_utc']);

$service = new DiscordPostingService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        $postDate = (string) ($_POST['date'] ?? $dayRange['day_start_local']->format('Y-m-d'));

        if ($action === 'sync_discord') {
            $results = $service->syncPendingDiscordItemsForToday($postDate);
            $messages = [];

            foreach ($results as $row) {
                $label = (string) ($row['event_name'] ?? $row['scope'] ?? 'Discord');
                $message = trim((string) ($row['message'] ?? 'Completed.'));
                if ($message === '') {
                    $message = 'Completed.';
                }
                $messages[] = $label . ': ' . $message;
            }

            setFlash('success', $messages !== [] ? implode(' | ', $messages) : 'Discord sync completed.');
            redirect('post_schedule.php?date=' . urlencode($postDate));
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('post_schedule.php?date=' . urlencode($dayRange['day_start_local']->format('Y-m-d')));
    }
}

function publishingStatusText(array $event): string
{
    $daily = !empty($event['discord_daily_message_id']) ? 'Daily post created' : 'Daily post pending';
    $native = !empty($event['discord_scheduled_event_id']) ? 'Discord event created' : 'Discord event pending';

    return $daily . ' • ' . $native;
}

renderHeader('Discord Publishing');
?>
<div class="card mb-16">
    <div class="actions space-between">
        <div>
            <h2 class="mt-0 mb-8">Discord Publishing</h2>
            <p class="muted mb-0">Manual control for the consolidated Discord sync. The sync creates today’s daily posts and native Discord events, refreshes host names, performs cleanup, and posts the weekly summary during the Monday midnight window.</p>
        </div>
    </div>
</div>

<div class="grid mb-16">
    <div class="card">
        <h3 class="mt-0">Run Manual Sync</h3>
        <p class="muted">Use this if you need to manually run the same Discord sync handled by cron.</p>
        <form method="post">
            <input type="hidden" name="action" value="sync_discord">
            <div class="field">
                <label for="date">Sync Date</label>
                <input class="date-picker" type="date" id="date" name="date" value="<?= e($dayRange['day_start_local']->format('Y-m-d')) ?>" required>
            </div>
            <div class="actions">
                <button class="btn" type="submit">Run Discord Sync</button>
                <a class="btn secondary" href="index.php?date=<?= e($dayRange['day_start_local']->format('Y-m-d')) ?>">View Schedule</a>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 class="mt-0">Cron Command</h3>
        <p class="muted">Run the consolidated sync directly from server cron. No web token is required.</p>
        <?php $cronDir = dirname(__DIR__) . '/cron'; ?>
        <div class="break-all mb-10"><code>php <?= e($cronDir . '/cron_sync_discord.php') ?></code></div>
        <div class="muted">Optional date argument for manual testing:</div>
        <div class="break-all mt-6"><code>php <?= e($cronDir . '/cron_sync_discord.php') ?> <?= e($dayRange['day_start_local']->format('Y-m-d')) ?></code></div>
    </div>
</div>

<div class="grid">
    <div class="card">
        <h3 class="mt-0">Selected Day Status</h3>
        <form method="get" class="mb-16">
            <div class="field mb-10">
                <label for="view-date">View Date</label>
                <input class="date-picker" type="date" id="view-date" name="date" value="<?= e($dayRange['day_start_local']->format('Y-m-d')) ?>" onchange="this.form.submit()">
            </div>
            <noscript><button class="btn secondary" type="submit">View Date</button></noscript>
        </form>
        <p class="muted mb-16"><?= e($dayRange['day_start_local']->format('l j F Y')) ?></p>
        <?php if (empty($todayEvents)): ?>
            <p>No events for this day.</p>
        <?php else: ?>
            <?php foreach ($todayEvents as $event): ?>
                <div class="mb-10" style="padding:10px 0;border-bottom:1px dashed rgba(255,255,255,.08);">
                    <strong><?= e((string) $event['event_name']) ?></strong><br>
                    <span class="muted"><?= e(utcToClanLocal((string) $event['event_start_utc'])->format('D j M • g:i A')) ?></span><br>
                    <span class="muted"><?= e(publishingStatusText($event)) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

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
</div>
<style>
.date-picker {
    min-height: 42px;
    cursor: pointer;
}
.date-picker::-webkit-calendar-picker-indicator {
    cursor: pointer;
}
</style>
<?php renderFooter(); ?>
