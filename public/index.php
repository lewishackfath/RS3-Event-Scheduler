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
<div class="card" style="margin-bottom:16px;">
    <div class="actions" style="justify-content:space-between;align-items:center;gap:16px;">
        <div>
            <h2 style="margin:0;">Week of <?= e($range['week_start_local']->format('j F Y')) ?></h2>
            <div class="muted">Timezone: <?= e(appConfig()['clan']['timezone']) ?></div>
            <?php if (!$canManage): ?>
                <div class="muted" style="margin-top:6px;">This page is public. Login with Discord to add or manage events.</div>
            <?php endif; ?>
        </div>
        <div class="actions" style="align-items:flex-end;">
            <form method="get" class="actions" style="align-items:flex-end;">
                <div>
                    <label for="week_date" style="margin-bottom:4px;">Jump to Week</label>
                    <input type="date" id="week_date" name="date" value="<?= e($currentWeekDate) ?>" style="width:auto;min-width:180px;">
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
            <div class="actions" style="justify-content:space-between;align-items:center; margin-bottom:14px;">
                <h3 style="margin:0;"><?= e($day['label']) ?></h3>
                <?php if ($canManage): ?><a class="btn secondary" href="event_create.php?date=<?= e($dateKey) ?>">Add Event</a><?php endif; ?>
            </div>
            <?php foreach ($day['events'] as $event): $local = utcToClanLocal($event['event_start_utc']); ?>
                <div class="event-card-row">
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
                                <strong><?= e($event['event_name']) ?></strong><?php if (!empty($event['recurring_series_id'])): ?><span style="display:inline-block;margin-left:8px;padding:3px 8px;border-radius:999px;background:rgba(37,99,235,.18);color:#93c5fd;font-size:12px;vertical-align:middle;">Recurring</span><?php endif; ?>
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
                        <div class="event-meta-grid">
                            <div><span class="muted">Event Date/Time:</span> <?= e($local->format('D j M Y, g:i A')) ?></div>
                            <div><span class="muted">Gametime:</span> <?= e((new DateTimeImmutable($event['event_start_utc'], new DateTimeZone('UTC')))->format('D j M Y, H:i')) ?> UTC</div>
                            <div><span class="muted">Event Host:</span> <?= e($event['host_name'] ?: 'TBC') ?></div>
                            <div><span class="muted">Location:</span> <?= e(($event['event_location'] ?? '') !== '' ? $event['event_location'] : (appConfig()['discord']['event_location_default'] ?? 'RuneScape - In Game')) ?></div>
                        </div>
                        <?php if (!empty($event['event_description'])): ?>
                            <div class="event-description"><?= nl2br(e($event['event_description'])) ?></div>
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
