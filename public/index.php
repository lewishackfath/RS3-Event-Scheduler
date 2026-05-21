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
foreach ($events as $event) {
    $local = utcToClanLocal($event['event_start_utc']);
    $key = $local->format('Y-m-d');
    $grouped[$key][] = $event;
}

$nowLocal = new DateTimeImmutable('now', clanTimezone());
$todayKey = $nowLocal->format('Y-m-d');
$journalDays = [];
for ($i = 0; $i < 7; $i++) {
    $dayLocal = $range['week_start_local']->modify('+' . $i . ' days');
    $dateKey = $dayLocal->format('Y-m-d');
    $journalDays[$dateKey] = [
        'date' => $dayLocal,
        'label' => $dayLocal->format('l'),
        'short_date' => $dayLocal->format('j M'),
        'full_date' => $dayLocal->format('l, j F Y'),
        'is_today' => $dateKey === $todayKey,
        'is_past_day' => $dayLocal < $nowLocal->setTime(0, 0),
        'events' => $grouped[$dateKey] ?? [],
    ];
}

$prev = $range['week_start_local']->modify('-7 days')->format('Y-m-d');
$next = $range['week_start_local']->modify('+7 days')->format('Y-m-d');
$currentWeekDate = $range['week_start_local']->format('Y-m-d');
$canManage = isAuthenticated();

renderHeader('Weekly Schedule');
?>
<section class="journal-cover mb-24">
    <div class="journal-cover-copy">
        <span class="journal-kicker">Arcane Chronicle</span>
        <h2 class="journal-title">Week of <?= e($range['week_start_local']->format('j F Y')) ?></h2>
        <p class="journal-intro">Seven pages of clan happenings, raids, gatherings and rituals. Each parchment below holds the knowledge for one day of the week.</p>
        <div class="journal-runes" aria-hidden="true">✦ ✧ ◈ ✧ ✦</div>
        <div class="muted">Timezone: <?= e(appConfig()['clan']['timezone']) ?></div>
        <?php if (!$canManage): ?>
            <div class="muted mt-6">This journal is public. Login with Discord to scribe or manage events.</div>
        <?php endif; ?>
    </div>
    <div class="journal-controls">
        <form method="get" class="journal-jump-form">
            <label for="week_date" class="mb-0">Turn to Week</label>
            <div class="journal-jump-row">
                <input type="date" id="week_date" name="date" value="<?= e($currentWeekDate) ?>" class="w-auto minw-180">
                <button class="btn secondary" type="submit">Go</button>
            </div>
        </form>
        <div class="journal-nav-buttons">
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
</section>

<section class="journal-days-grid" aria-label="Weekly event journal">
    <?php foreach ($journalDays as $dateKey => $day): ?>
        <?php
        $eventsForDay = (array) ($day['events'] ?? []);
        $isToday = (bool) ($day['is_today'] ?? false);
        $isPastDay = (bool) ($day['is_past_day'] ?? false);
        $hasEvents = !empty($eventsForDay);
        ?>
        <article class="journal-day-page<?= $isToday ? ' today-page' : '' ?><?= $isPastDay ? ' past-day-page' : '' ?><?= !$hasEvents ? ' empty-day-page' : '' ?>">
            <header class="journal-page-header">
                <div>
                    <div class="day-rune" aria-hidden="true"><?= $isToday ? '✦' : '✧' ?></div>
                    <h3 class="page-title"><?= e((string) $day['label']) ?></h3>
                    <div class="journal-page-date"><?= e((string) $day['short_date']) ?></div>
                </div>
                <div class="journal-page-status">
                    <?php if ($isToday): ?>
                        <span class="badge">Today</span>
                    <?php elseif ($isPastDay): ?>
                        <span class="pill">Past Page</span>
                    <?php else: ?>
                        <span class="pill">Future Page</span>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                        <a class="btn secondary journal-page-add" href="event_create.php?date=<?= e($dateKey) ?>">Add</a>
                    <?php endif; ?>
                </div>
            </header>

            <?php if (!$hasEvents): ?>
                <div class="empty-day-copy">
                    <div class="empty-rune" aria-hidden="true">☽</div>
                    <p>No event has been inked onto this page yet.</p>
                </div>
            <?php else: ?>
                <div class="day-events-list">
                    <?php foreach ($eventsForDay as $event): ?>
                        <?php
                        $local = utcToClanLocal($event['event_start_utc']);
                        $utc = new DateTimeImmutable($event['event_start_utc'], new DateTimeZone('UTC'));
                        $durationLabel = formatEventDurationLabel(isset($event['duration_minutes']) ? (int) $event['duration_minutes'] : null);
                        $rolesHtml = formatPreferredRolesHtml((array) ($event['preferred_roles'] ?? []));
                        $imageUrl = eventDisplayImageUrl($event);
                        ?>
                        <article class="event-card-row journal-entry">
                            <?php if ($imageUrl !== ''): ?>
                                <div class="event-card-image-wrap">
                                    <img class="event-card-image" src="<?= e($imageUrl) ?>" alt="<?= e($event['event_name']) ?>">
                                </div>
                            <?php endif; ?>
                            <div class="event-card-body">
                                <div class="event-card-header">
                                    <div>
                                        <strong class="event-card-title"><?= e($event['event_name']) ?></strong><?php if (!empty($event['recurring_series_id'])): ?><span class="pill">Recurring</span><?php endif; ?>
                                        <?php if (!empty($event['is_recurring_weekly'])): ?>
                                            <span class="badge">Weekly</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($canManage): ?>
                                    <div class="actions event-entry-actions">
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
                                        <div class="event-meta-label">Time</div>
                                        <div class="event-meta-value"><?= e($local->format('g:i A T')) ?> <span class="event-meta-sep">•</span> Game <?= e($utc->format('H:i')) ?> UTC <span class="event-meta-sep">•</span> <?= e($durationLabel) ?></div>
                                    </div>
                                    <div class="event-meta-item">
                                        <div class="event-meta-label">Host</div>
                                        <div class="event-meta-value"><?= e($event['host_name'] ?: 'TBC') ?></div>
                                    </div>
                                    <div class="event-meta-item">
                                        <div class="event-meta-label">Location</div>
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
                                            <div class="event-meta-label">Description</div>
                                            <div class="event-meta-value event-description"><?= nl2br(e($event['event_description'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
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
