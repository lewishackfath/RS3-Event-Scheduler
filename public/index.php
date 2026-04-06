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

renderHeader('Weekly Schedule');
?>
<div class="card" style="margin-bottom:16px;">
    <div class="actions" style="justify-content:space-between;align-items:center;">
        <div>
            <h2 style="margin:0;">Week of <?= e($range['week_start_local']->format('j F Y')) ?></h2>
            <div class="muted">Timezone: <?= e(appConfig()['clan']['timezone']) ?></div>
        </div>
        <div class="actions">
            <a class="btn secondary" href="?date=<?= e($prev) ?>">Previous Week</a>
            <a class="btn secondary" href="?date=<?= e((new DateTimeImmutable('now', clanTimezone()))->format('Y-m-d')) ?>">Current Week</a>
            <a class="btn secondary" href="?date=<?= e($next) ?>">Next Week</a>
            <a class="btn" href="event_create.php?date=<?= e($range['week_start_local']->format('Y-m-d')) ?>">Add Event</a>
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
            <div class="actions" style="justify-content:space-between;align-items:center;">
                <h3 style="margin:0;"><?= e($day['label']) ?></h3>
                <a class="btn secondary" href="event_create.php?date=<?= e($dateKey) ?>">Add Event</a>
            </div>
            <?php foreach ($day['events'] as $event): $local = utcToClanLocal($event['event_start_utc']); ?>
                <div class="event-row">
                    <div>
                        <strong><?= e($event['event_name']) ?></strong><br>
                        <span class="muted"><?= e($local->format('g:i A')) ?> · Host: <?= e($event['host_name'] ?: 'TBC') ?></span>
                        <?php if (!empty($event['event_description'])): ?>
                            <div style="margin-top:6px;"><?= nl2br(e($event['event_description'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="actions">
                        <a class="btn secondary" href="event_edit.php?id=<?= (int) $event['id'] ?>">Edit</a>
                        <a class="btn danger" href="event_delete.php?id=<?= (int) $event['id'] ?>" onclick="return confirm('Delete this event?');">Delete</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php renderFooter(); ?>
