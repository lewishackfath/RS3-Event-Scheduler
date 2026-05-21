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

        $html[] = '<span class="event-role-line"><span class="role-emoji">' . e($emoji) . '</span><span class="role-name">' . e($name) . '</span></span>';
    }

    return implode('', $html);
}

$selectedDate = isset($_GET['date']) ? (string) $_GET['date'] : null;
$range = weekRangeFromDate($selectedDate);
$events = (new EventRepository())->getForWeek($range['week_start_utc'], $range['week_end_utc']);

$nowLocal = new DateTimeImmutable('now', clanTimezone());
$todayKey = $nowLocal->format('Y-m-d');
$displayEvents = [];

foreach ($events as $event) {
    $local = utcToClanLocal($event['event_start_utc']);
    $utc = new DateTimeImmutable($event['event_start_utc'], new DateTimeZone('UTC'));
    $dateKey = $local->format('Y-m-d');

    $displayEvents[] = [
        'event' => $event,
        'local' => $local,
        'utc' => $utc,
        'date_key' => $dateKey,
        'day_label' => $local->format('l'),
        'short_date' => $local->format('j M'),
        'full_date' => $local->format('l, j F Y'),
        'is_today' => $dateKey === $todayKey,
        'is_past' => $local < $nowLocal,
    ];
}

usort($displayEvents, static function (array $a, array $b): int {
    return strcmp($a['event']['event_start_utc'], $b['event']['event_start_utc']);
});

$upcomingEvents = [];
$pastEvents = [];
foreach ($displayEvents as $item) {
    if ($item['is_past']) {
        $pastEvents[] = $item;
    } else {
        $upcomingEvents[] = $item;
    }
}

$showPastDefault = empty($upcomingEvents) && !empty($pastEvents);
$prev = $range['week_start_local']->modify('-7 days')->format('Y-m-d');
$next = $range['week_start_local']->modify('+7 days')->format('Y-m-d');
$currentWeekDate = $range['week_start_local']->format('Y-m-d');
$canManage = isAuthenticated();
$tearClasses = ['tear-1', 'tear-2', 'tear-3', 'tear-4', 'tear-5', 'tear-6'];

renderHeader('Weekly Schedule');
?>
<section class="journal-cover mb-24">
    <div class="journal-cover-copy">
        <span class="journal-kicker">Arcane Chronicle</span>
        <h2 class="journal-title">Week of <?= e($range['week_start_local']->format('j F Y')) ?></h2>
        <p class="journal-intro">Each event is now recorded as its own torn page from an ancient clan journal. Browse the week below as a spread of arcane notices, rituals, gatherings and battle plans.</p>
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

<?php if (empty($displayEvents)): ?>
    <section class="journal-empty-state card">
        <div class="empty-rune" aria-hidden="true">☽</div>
        <h3 class="mt-0">No pages have been torn from the journal this week.</h3>
        <p class="muted">There are currently no events inked into the selected week.</p>
        <?php if ($canManage): ?>
            <div class="mt-14">
                <a class="btn" href="event_create.php?date=<?= e($range['week_start_local']->format('Y-m-d')) ?>">Scribe an Event</a>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="journal-filter-bar mb-24">
        <div class="journal-filter-copy">
            <div class="journal-filter-title">Weekly Pages</div>
            <div class="muted"><?= count($upcomingEvents) ?> current/upcoming <?= count($upcomingEvents) === 1 ? 'entry' : 'entries' ?><?php if (!empty($pastEvents)): ?> • <?= count($pastEvents) ?> past <?= count($pastEvents) === 1 ? 'entry' : 'entries' ?><?php endif; ?></div>
        </div>
        <?php if (!empty($pastEvents)): ?>
            <label class="journal-toggle">
                <input type="checkbox" id="togglePastEvents"<?= $showPastDefault ? ' checked' : '' ?>>
                <span>Show past events</span>
            </label>
        <?php endif; ?>
    </section>

    <section class="journal-event-section">
        <div class="journal-section-heading">
            <h3><?= !empty($upcomingEvents) ? 'Current & Upcoming Entries' : 'No Current or Upcoming Entries' ?></h3>
            <?php if (!empty($upcomingEvents)): ?><span class="pill"><?= count($upcomingEvents) ?></span><?php endif; ?>
        </div>

        <?php if (empty($upcomingEvents)): ?>
            <div class="journal-empty-inline">
                <div class="empty-rune" aria-hidden="true">✧</div>
                <p>There are no current or upcoming events in this selected week.</p>
            </div>
        <?php else: ?>
            <div class="journal-event-grid" id="currentEventGrid">
                <?php foreach ($upcomingEvents as $index => $item): ?>
                    <?php
                    $event = $item['event'];
                    $durationLabel = formatEventDurationLabel(isset($event['duration_minutes']) ? (int) $event['duration_minutes'] : null);
                    $rolesHtml = formatPreferredRolesHtml((array) ($event['preferred_roles'] ?? []));
                    $imageUrl = eventDisplayImageUrl($event);
                    $tearClass = $tearClasses[$index % count($tearClasses)];
                    ?>
                    <article class="journal-event-card <?= e($tearClass) ?><?= $item['is_today'] ? ' event-is-today' : '' ?>" data-equalize-card>
                        <div class="journal-event-card-inner">
                            <div class="journal-event-media<?= $imageUrl === '' ? ' no-image' : '' ?>">
                                <?php if ($imageUrl !== ''): ?>
                                    <img class="journal-event-image" src="<?= e($imageUrl) ?>" alt="<?= e($event['event_name']) ?>">
                                <?php else: ?>
                                    <div class="journal-event-placeholder" aria-hidden="true">✦</div>
                                <?php endif; ?>
                                <div class="journal-event-date-seal">
                                    <span class="seal-day"><?= e($item['day_label']) ?></span>
                                    <span class="seal-date"><?= e($item['short_date']) ?></span>
                                </div>
                            </div>

                            <div class="journal-event-content">
                                <div class="journal-event-header">
                                    <div>
                                        <strong class="event-card-title"><?= e($event['event_name']) ?></strong>
                                        <div class="journal-event-flags">
                                            <?php if ($item['is_today']): ?><span class="badge">Today</span><?php endif; ?>
                                            <?php if (!empty($event['recurring_series_id'])): ?><span class="pill">Recurring</span><?php endif; ?>
                                            <?php if (!empty($event['is_recurring_weekly'])): ?><span class="badge">Weekly</span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="event-meta-stack journal-event-meta-stack">
                                    <div class="event-meta-item">
                                        <div class="event-meta-label">Time</div>
                                        <div class="event-meta-value"><?= e($item['local']->format('g:i A T')) ?> <span class="event-meta-sep">•</span> Game <?= e($item['utc']->format('H:i')) ?> UTC <span class="event-meta-sep">•</span> <?= e($durationLabel) ?></div>
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
                                            <div class="event-meta-value event-description journal-event-description"><?= nl2br(e($event['event_description'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($canManage): ?>
                                    <div class="actions event-entry-actions journal-event-actions mt-auto">
                                        <a class="btn secondary" href="event_edit.php?id=<?= (int) $event['id'] ?>">Edit</a>
                                        <a class="btn secondary" href="sync_event.php?id=<?= (int) $event['id'] ?>" onclick="return confirm('Sync this event to Discord now?');">Sync</a>
                                        <?php if (($event['status'] ?? 'scheduled') !== 'cancelled'): ?>
                                            <a class="btn danger" href="cancel_event.php?id=<?= (int) $event['id'] ?>" onclick="return confirm('Cancel this event and update Discord?');">Cancel</a>
                                        <?php endif; ?>
                                        <a class="btn danger" href="event_delete.php?id=<?= (int) $event['id'] ?>" onclick="return confirm('Delete this event?');">Delete</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if (!empty($pastEvents)): ?>
        <section class="journal-event-section journal-past-section<?= $showPastDefault ? '' : ' is-hidden' ?>" id="pastEventSection">
            <div class="journal-section-heading">
                <h3>Past Entries</h3>
                <span class="pill"><?= count($pastEvents) ?></span>
            </div>

            <div class="journal-event-grid" id="pastEventGrid">
                <?php foreach ($pastEvents as $index => $item): ?>
                    <?php
                    $event = $item['event'];
                    $durationLabel = formatEventDurationLabel(isset($event['duration_minutes']) ? (int) $event['duration_minutes'] : null);
                    $rolesHtml = formatPreferredRolesHtml((array) ($event['preferred_roles'] ?? []));
                    $imageUrl = eventDisplayImageUrl($event);
                    $tearClass = $tearClasses[$index % count($tearClasses)];
                    ?>
                    <article class="journal-event-card <?= e($tearClass) ?> is-past-event" data-equalize-card>
                        <div class="journal-event-card-inner">
                            <div class="journal-event-media<?= $imageUrl === '' ? ' no-image' : '' ?>">
                                <?php if ($imageUrl !== ''): ?>
                                    <img class="journal-event-image" src="<?= e($imageUrl) ?>" alt="<?= e($event['event_name']) ?>">
                                <?php else: ?>
                                    <div class="journal-event-placeholder" aria-hidden="true">☽</div>
                                <?php endif; ?>
                                <div class="journal-event-date-seal">
                                    <span class="seal-day"><?= e($item['day_label']) ?></span>
                                    <span class="seal-date"><?= e($item['short_date']) ?></span>
                                </div>
                            </div>

                            <div class="journal-event-content">
                                <div class="journal-event-header">
                                    <div>
                                        <strong class="event-card-title"><?= e($event['event_name']) ?></strong>
                                        <div class="journal-event-flags">
                                            <span class="pill">Past Event</span>
                                            <?php if (!empty($event['recurring_series_id'])): ?><span class="pill">Recurring</span><?php endif; ?>
                                            <?php if (!empty($event['is_recurring_weekly'])): ?><span class="badge">Weekly</span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="event-meta-stack journal-event-meta-stack">
                                    <div class="event-meta-item">
                                        <div class="event-meta-label">Time</div>
                                        <div class="event-meta-value"><?= e($item['local']->format('g:i A T')) ?> <span class="event-meta-sep">•</span> Game <?= e($item['utc']->format('H:i')) ?> UTC <span class="event-meta-sep">•</span> <?= e($durationLabel) ?></div>
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
                                            <div class="event-meta-value event-description journal-event-description"><?= nl2br(e($event['event_description'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($canManage): ?>
                                    <div class="actions event-entry-actions journal-event-actions mt-auto">
                                        <a class="btn secondary" href="event_edit.php?id=<?= (int) $event['id'] ?>">Edit</a>
                                        <a class="btn secondary" href="sync_event.php?id=<?= (int) $event['id'] ?>" onclick="return confirm('Sync this event to Discord now?');">Sync</a>
                                        <?php if (($event['status'] ?? 'scheduled') !== 'cancelled'): ?>
                                            <a class="btn danger" href="cancel_event.php?id=<?= (int) $event['id'] ?>" onclick="return confirm('Cancel this event and update Discord?');">Cancel</a>
                                        <?php endif; ?>
                                        <a class="btn danger" href="event_delete.php?id=<?= (int) $event['id'] ?>" onclick="return confirm('Delete this event?');">Delete</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
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

    const equalizeCards = function () {
        const groups = ['currentEventGrid', 'pastEventGrid'];
        groups.forEach(function (id) {
            const grid = document.getElementById(id);
            if (!grid || grid.offsetParent === null) {
                return;
            }

            const cards = Array.prototype.slice.call(grid.querySelectorAll('[data-equalize-card]'));
            if (!cards.length) {
                return;
            }

            cards.forEach(function (card) {
                card.style.height = 'auto';
                card.style.minHeight = '0';
            });

            let maxHeight = 0;
            cards.forEach(function (card) {
                maxHeight = Math.max(maxHeight, card.offsetHeight);
            });

            cards.forEach(function (card) {
                card.style.minHeight = maxHeight + 'px';
            });
        });
    };

    const pastToggle = document.getElementById('togglePastEvents');
    const pastSection = document.getElementById('pastEventSection');
    if (pastToggle && pastSection) {
        const syncPastVisibility = function () {
            pastSection.classList.toggle('is-hidden', !pastToggle.checked);
            window.requestAnimationFrame(equalizeCards);
        };
        pastToggle.addEventListener('change', syncPastVisibility);
        syncPastVisibility();
    }

    window.addEventListener('load', equalizeCards);
    window.addEventListener('resize', equalizeCards);

    document.querySelectorAll('.journal-event-image').forEach(function (img) {
        if (img.complete) {
            return;
        }
        img.addEventListener('load', equalizeCards, { once: true });
        img.addEventListener('error', equalizeCards, { once: true });
    });
})();
</script>
<?php renderFooter(); ?>
