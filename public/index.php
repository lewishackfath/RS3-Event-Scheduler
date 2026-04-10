<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';

function formatEventDurationLabel(?int $minutes): string
{
    $minutes = (int) $minutes;
    if ($minutes <= 0) {
        $minutes = max(1, (int) (appConfig()['discord']['default_event_duration_minutes'] ?? 60));
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    if ($hours > 0 && $remainingMinutes > 0) {
        return $hours . 'h ' . $remainingMinutes . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h';
    }
    return $remainingMinutes . 'm';
}

function formatPreferredRolesHtml(array $roles): string
{
    $html = [];

    foreach ($roles as $role) {
        $name = trim((string) ($role['role_name'] ?? ''));
        $emoji = trim((string) ($role['reaction_emoji'] ?? ''));
        if ($name === '' || $emoji === '') {
            continue;
        }

        $html[] = '<span class="event-role-pill"><span class="role-emoji">' . e($emoji) . '</span><span class="role-name">' . e($name) . '</span></span>';
    }

    return implode('', $html);
}

$selectedDate = isset($_GET['date']) ? (string) $_GET['date'] : null;
$range = weekRangeFromDate($selectedDate);
$events = (new EventRepository())->getForWeek($range['week_start_utc'], $range['week_end_utc']);

$grouped = [];
$nowLocal = new DateTimeImmutable('now', clanTimezone());
foreach ($events as $event) {
    $local = utcToClanLocal($event['event_start_utc']);
    $key = $local->format('Y-m-d');
    $grouped[$key]['label'] = $local->format('l, j F Y');
    $grouped[$key]['is_past_day'] = $local < $nowLocal->setTime(0, 0);
    $grouped[$key]['events'][] = $event;
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

<?php if (empty($grouped)): ?>
    <div class="card">
        <p>No events scheduled for this week yet.</p>
    </div>
<?php else: ?>
    <?php foreach ($grouped as $dateKey => $day): ?>
        <?php $isPastDay = (bool) ($day['is_past_day'] ?? false); ?>
        <<?= $isPastDay ? 'details' : 'div' ?> class="card day-group<?= $isPastDay ? ' past-day-group' : '' ?>"<?= $isPastDay ? '' : '' ?>>
            <?php if ($isPastDay): ?>
                <summary class="day-group-summary">
                    <div class="actions space-between mb-0 day-group-summary-inner">
                        <h3 class="page-title"><?= e($day['label']) ?></h3>
                        <span class="muted">Past events</span>
                    </div>
                </summary>
            <?php else: ?>
                <div class="actions space-between mb-14">
                    <h3 class="page-title"><?= e($day['label']) ?></h3>
                    <?php if ($canManage): ?><a class="btn secondary" href="event_create.php?date=<?= e($dateKey) ?>">Add Event</a><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="day-events-list<?= $isPastDay ? ' mt-14' : '' ?>">
                <?php foreach ($day['events'] as $event): ?>
                    <?php
                    $local = utcToClanLocal($event['event_start_utc']);
                    $utc = new DateTimeImmutable($event['event_start_utc'], new DateTimeZone('UTC'));
                    $durationLabel = formatEventDurationLabel(isset($event['duration_minutes']) ? (int) $event['duration_minutes'] : null);
                    $rolesHtml = formatPreferredRolesHtml((array) ($event['preferred_roles'] ?? []));
                    ?>
                    <article class="event-card-row">
                        <div class="event-card-image-wrap">
                            <?php $imageUrl = eventDisplayImageUrl($event); ?>
                            <?php if ($imageUrl !== ''): ?>
                                <img class="event-card-image" src="<?= e($imageUrl) ?>" alt="<?= e($event['event_name']) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="event-card-body">
                            <div class="event-card-header">
                                <div>
                                    <strong class="event-card-title"><?= e($event['event_name']) ?></strong><?php if (!empty($event['recurring_series_id'])): ?><span class="pill">Recurring</span><?php endif; ?>
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

                            <div class="event-meta-stack">
                                <div class="event-meta-item">
                                    <div class="event-meta-label">Event Date</div>
                                    <div class="event-meta-value"><?= e($local->format('l, j F Y')) ?></div>
                                </div>
                                <div class="event-meta-item">
                                    <div class="event-meta-label">Event Times</div>
                                    <div class="event-meta-value"><?= e($local->format('g:i A T')) ?> <span class="event-meta-sep">•</span> Game Time: <?= e($utc->format('H:i')) ?> UTC <span class="event-meta-sep">•</span> Duration: <?= e($durationLabel) ?></div>
                                </div>
                                <div class="event-meta-item">
                                    <div class="event-meta-label">Event Host</div>
                                    <div class="event-meta-value"><?= e($event['host_name'] ?: 'TBC') ?></div>
                                </div>
                                <div class="event-meta-item">
                                    <div class="event-meta-label">Event Location</div>
                                    <div class="event-meta-value"><?= e(($event['event_location'] ?? '') !== '' ? $event['event_location'] : (appConfig()['discord']['event_location_default'] ?? 'RuneScape - In Game')) ?></div>
                                </div>
                                <?php if ($rolesHtml !== ''): ?>
                                    <div class="event-meta-item event-roles-section">
                                        <div class="event-meta-label">Roles</div>
                                        <div class="event-role-list"><?= $rolesHtml ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($event['event_description'])): ?>
                                    <div class="event-meta-item">
                                        <div class="event-meta-label">Event Description</div>
                                        <div class="event-meta-value event-description"><?= nl2br(e($event['event_description'])) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </<?= $isPastDay ? 'details' : 'div' ?>>
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
