<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';

$selectedDate = isset($_GET['date']) ? (string) $_GET['date'] : null;
$range = weekRangeFromDate($selectedDate);
$events = (new EventRepository())->getForWeek($range['week_start_utc'], $range['week_end_utc']);

$groupedUpcoming = [];
$groupedPast = [];
$nowLocal = new DateTimeImmutable('now', clanTimezone());
foreach ($events as $event) {
    $local = utcToClanLocal($event['event_start_utc']);
    $key = $local->format('Y-m-d');
    if ($local < $nowLocal) {
        $groupedPast[$key]['label'] = $local->format('l, j F Y');
        $groupedPast[$key]['events'][] = $event;
    } else {
        $groupedUpcoming[$key]['label'] = $local->format('l, j F Y');
        $groupedUpcoming[$key]['events'][] = $event;
    }
}

$pastEventCount = 0;
foreach ($groupedPast as $pastDay) {
    $pastEventCount += count($pastDay['events'] ?? []);
}

$prev = $range['week_start_local']->modify('-7 days')->format('Y-m-d');
$next = $range['week_start_local']->modify('+7 days')->format('Y-m-d');
$currentWeekDate = $range['week_start_local']->format('Y-m-d');
$canManage = isAuthenticated();

renderHeader('Weekly Schedule');
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

<?php
$renderEventDay = static function (string $dateKey, array $day, bool $isPastDay = false) use ($canManage): void {
    ?>
    <div class="card day-group <?= $isPastDay ? 'is-past-group' : '' ?>">
        <div class="actions space-between mb-14">
            <div>
                <h3 class="page-title"><?= e($day['label']) ?></h3>
                <div class="muted"><?= count($day['events']) ?> event<?= count($day['events']) === 1 ? '' : 's' ?><?= $isPastDay ? ' already completed' : ' scheduled' ?></div>
            </div>
            <?php if ($canManage): ?><a class="btn secondary" href="event_create.php?date=<?= e($dateKey) ?>">Add Event</a><?php endif; ?>
        </div>
        <?php foreach ($day['events'] as $event): $local = utcToClanLocal($event['event_start_utc']); ?>
            <div class="event-card-row <?= $isPastDay ? 'is-past' : '' ?>">
                <div class="event-card-image-wrap">
                    <?php $imageUrl = eventDisplayImageUrl($event); ?>
                    <?php if ($imageUrl !== ''): ?>
                        <img class="event-card-image" src="<?= e($imageUrl) ?>" alt="<?= e($event['event_name']) ?>">
                    <?php else: ?>
                        <div class="event-card-image placeholder">No image</div>
                    <?php endif; ?>
                </div>
                <div class="event-card-body">
                    <div class="event-card-header">
                        <div>
                            <strong><?= e($event['event_name']) ?></strong><?php if (!empty($event['recurring_series_id'])): ?><span class="pill">Recurring</span><?php endif; ?>
                            <?php if (!empty($event['is_recurring_weekly'])): ?>
                                <span class="badge">Weekly</span>
                            <?php endif; ?>
                            <?php if ($isPastDay): ?>
                                <span class="status-chip past">Past</span>
                            <?php endif; ?>
                            <?php if (($event['status'] ?? 'scheduled') === 'cancelled'): ?>
                                <span class="status-chip cancelled">Cancelled</span>
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
                    <div class="event-meta-grid">
                        <div><span class="muted">Event Date/Time:</span> <?= e($local->format('D j M Y, g:i A')) ?></div>
                        <div><span class="muted">Gametime:</span> <?= e((new DateTimeImmutable($event['event_start_utc'], new DateTimeZone('UTC')))->format('D j M Y, H:i')) ?> UTC</div>
                        <div><span class="muted">Event Host:</span> <?= e($event['host_name'] ?: 'TBC') ?></div>
                        <div><span class="muted">Location:</span> <?= e(($event['event_location'] ?? '') !== '' ? $event['event_location'] : (appConfig()['discord']['event_location_default'] ?? 'RuneScape - In Game')) ?></div>
                    </div>
                    <?php $preferredRoles = array_values(array_filter((array) ($event['preferred_roles'] ?? []), static function (array $role): bool {
                        return trim((string) ($role['role_name'] ?? '')) !== '' && trim((string) ($role['reaction_emoji'] ?? '')) !== '';
                    })); ?>
                    <?php if ($preferredRoles !== []): ?>
                        <div class="event-role-block">
                            <div class="event-role-label">Preferred roles</div>
                            <div class="event-role-list">
                                <?php foreach ($preferredRoles as $role): ?>
                                    <span class="role-chip"><span class="role-chip-emoji"><?= e((string) $role['reaction_emoji']) ?></span><span><?= e((string) $role['role_name']) ?></span></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($event['event_description'])): ?>
                        <div class="event-description"><?= nl2br(e($event['event_description'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
};
?>

<?php if (empty($groupedUpcoming) && empty($groupedPast)): ?>
    <div class="card">
        <p>No events scheduled for this week yet.</p>
    </div>
<?php else: ?>
    <?php if (!empty($groupedUpcoming)): ?>
        <?php foreach ($groupedUpcoming as $dateKey => $day): ?>
            <?php $renderEventDay($dateKey, $day, false); ?>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="card mb-24">
            <p class="mb-0">There are no upcoming events left for this week.</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($groupedPast)): ?>
        <details class="archive-panel">
            <summary>Past events <span class="archive-panel-count">(<?= (int) $pastEventCount ?>)</span></summary>
            <div class="archive-panel-body">
                <?php foreach ($groupedPast as $dateKey => $day): ?>
                    <?php $renderEventDay($dateKey, $day, true); ?>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
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
