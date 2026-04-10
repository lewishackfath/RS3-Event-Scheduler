<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';

$selectedDate = isset($_GET['date']) ? (string) $_GET['date'] : null;
$range = weekRangeFromDate($selectedDate);
$events = (new EventRepository())->getForWeek($range['week_start_utc'], $range['week_end_utc']);

$grouped = [];
foreach ($events as $event) {
    $local = utcToClanLocal($event['event_start_utc']);
    $key = $local->format('Y-m-d');
    $grouped[$key]['label'] = $local->format('l, j F Y');
    $grouped[$key]['events'][] = $event;
}

$prev = $range['week_start_local']->modify('-7 days')->format('Y-m-d');
$next = $range['week_start_local']->modify('+7 days')->format('Y-m-d');
$currentWeekDate = $range['week_start_local']->format('Y-m-d');
$canManage = isAuthenticated();

renderHeader('Weekly Schedule');
?>
<?php
function formatDurationLabel(?int $minutes): string
{
    if ($minutes === null || $minutes <= 0) {
        $minutes = max(1, (int) appConfig()['discord']['default_event_duration_minutes']);
    }

    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    $parts = [];

    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($mins > 0) {
        $parts[] = $mins . 'm';
    }

    return implode(' ', $parts) ?: ($minutes . 'm');
}
?>

<div class="card mb-16">
    <div class="actions space-between">
        <div>
            <h2 class="page-title">Week of <?= e($range['week_start_local']->format('j F Y')) ?></h2>
            <div class="muted">Timezone: <?= e(appConfig()['clan']['timezone']) ?></div>
            <?php if (!$canManage): ?>
                <div class="muted mt-6">This page is public. Login with Discord to add or manage events.</div>
            <?php endif; ?>
        </div>
        <div class="actions align-end">
            <form method="get" class="actions align-end">
                <div>
                    <label for="week_date" class="mb-0">Jump to Week</label>
                    <input type="date" id="week_date" name="date" value="<?= e($currentWeekDate) ?>" class="w-auto minw-180">
                </div>
                <button class="btn secondary" type="submit">Go</button>
            </form>
            <a class="btn secondary" href="?date=<?= e($prev) ?>">Previous Week</a>
            <a class="btn secondary" href="?date=<?= e((new DateTimeImmutable('now', clanTimezone()))->format('Y-m-d')) ?>">Current Week</a>
            <a class="btn secondary" href="?date=<?= e($next) ?>">Next Week</a>
            <?php if ($canManage): ?>
                <a class="btn" href="event_create.php?date=<?= e($range['week_start_local']->format('Y-m-d')) ?>">Add Event</a>
            <?php else: ?>
                <a class="btn" href="login.php">Login to Manage</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (empty($grouped)): ?>
    <div class="card">
        <p>No events scheduled for this week yet.</p>
    </div>
<?php else: ?>
    <?php foreach ($grouped as $dateKey => $day): ?>
        <div class="card day-group">
            <div class="actions space-between mb-14">
                <h3 class="page-title"><?= e($day['label']) ?></h3>
                <?php if ($canManage): ?><a class="btn secondary" href="event_create.php?date=<?= e($dateKey) ?>">Add Event</a><?php endif; ?>
            </div>
            <?php foreach ($day['events'] as $event):
                $local = utcToClanLocal($event['event_start_utc']);
                $utc = new DateTimeImmutable($event['event_start_utc'], new DateTimeZone('UTC'));
                $imageUrl = eventDisplayImageUrl($event);
                $durationLabel = formatDurationLabel(isset($event['duration_minutes']) ? (int) $event['duration_minutes'] : null);
                $timezoneLabel = appConfig()['clan']['timezone'];
                $roles = array_values(array_filter((array) ($event['preferred_roles'] ?? []), static function (array $role): bool {
                    return trim((string) ($role['role_name'] ?? '')) !== '' && trim((string) ($role['reaction_emoji'] ?? '')) !== '';
                }));
            ?>
                <div class="event-card-row">
                    <?php if ($imageUrl !== ''): ?>
                        <div class="event-card-image-wrap">
                            <img class="event-card-image" src="<?= e($imageUrl) ?>" alt="<?= e($event['event_name']) ?>">
                        </div>
                    <?php endif; ?>
                    <div class="event-card-body">
                        <div class="event-card-header">
                            <div>
                                <strong><?= e($event['event_name']) ?></strong><?php if (!empty($event['recurring_series_id'])): ?><span class="pill">Recurring</span><?php endif; ?>
                                <?php if (!empty($event['is_recurring_weekly'])): ?>
                                    <span class="badge">Weekly</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($canManage): ?>
                            <div class="actions">
                                <a class="btn secondary" href="event_edit.php?id=<?= (int) $event['id'] ?>">Edit</a>
                                <a class="btn secondary" href="sync_event.php?id=<?= (int) $event['id'] ?>" onclick="return confirm('Sync this event to Discord now?');">Sync</a>
                                <?php if (($event['status'] ?? 'scheduled') !== 'cancelled'): ?>
                                    <a class="btn danger" href="cancel_event.php?id=<?= (int) $event['id'] ?>" onclick="return confirm('Cancel this event and update Discord?');">Cancel</a>
                                <?php endif; ?>
                                <a class="btn danger" href="event_delete.php?id=<?= (int) $event['id'] ?>" onclick="return confirm('Delete this event?');">Delete</a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="event-detail-list stack-md">
                            <div><span class="muted">Event Date:</span> <?= e($local->format('D j M Y')) ?></div>
                            <div><span class="muted">Event Start Time <?= e('(' . $timezoneLabel . ')') ?>:</span> <?= e($local->format('g:i A')) ?> <span class="event-inline-separator">•</span> <span class="muted">Game Time:</span> <?= e($utc->format('H:i')) ?> UTC <span class="event-inline-separator">•</span> <span class="muted">Duration:</span> <?= e($durationLabel) ?></div>
                            <div><span class="muted">Event Host:</span> <?= e($event['host_name'] ?: 'TBC') ?></div>
                            <div><span class="muted">Event Location:</span> <?= e(($event['event_location'] ?? '') !== '' ? $event['event_location'] : (appConfig()['discord']['event_location_default'] ?? 'RuneScape - In Game')) ?></div>
                            <div><span class="muted">Event Description:</span> <?= !empty($event['event_description']) ? nl2br(e($event['event_description'])) : '<span class="muted">No description provided.</span>' ?></div>
                        </div>
                        <?php if ($roles !== []): ?>
                            <div class="event-roles">
                                <div class="event-roles-label">Roles</div>
                                <div class="event-role-list">
                                    <?php foreach ($roles as $role): ?>
                                        <span class="event-role-pill"><span class="role-emoji"><?= e((string) $role['reaction_emoji']) ?></span><span><?= e((string) $role['role_name']) ?></span></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<script>
(function () {
    const dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(function (input) {
        input.addEventListener('focus', function () {
            if (typeof input.showPicker === 'function') {
                try { input.showPicker(); } catch (e) {}
            }
        });
        input.addEventListener('click', function () {
            if (typeof input.showPicker === 'function') {
                try { input.showPicker(); } catch (e) {}
            }
        });
    });
})();
</script>
<?php renderFooter(); ?>
