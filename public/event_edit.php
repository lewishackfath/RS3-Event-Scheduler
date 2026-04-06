<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$repo = new EventRepository();
$event = $repo->getById($id);
if (!$event) {
    setFlash('error', 'Event not found.');
    redirect('index.php');
}

$local = utcToClanLocal($event['event_start_utc']);
$recurringUntilDate = '';
if (!empty($event['recurring_until_utc'])) {
    $recurringUntilDate = utcToClanLocal($event['recurring_until_utc'])->format('Y-m-d');
}

$formValues = [
    'event_name' => $event['event_name'],
    'host_name' => $event['host_name'],
    'host_discord_user_id' => $event['host_discord_user_id'] ?? '',
    'event_date' => $local->format('Y-m-d'),
    'event_time' => $local->format('H:i'),
    'duration_minutes' => $event['duration_minutes'],
    'discord_channel_id' => $event['discord_channel_id'],
    'event_location' => $event['event_location'] ?? '',
    'image_url' => $event['image_url'],
    'event_description' => $event['event_description'],
    'is_active' => (int) $event['is_active'],
    'is_recurring_weekly' => (int) ($event['is_recurring_weekly'] ?? 0),
    'recurring_until_date' => $recurringUntilDate,
    'recurring_edit_scope' => 'single',
];

$isSeriesEvent = trim((string) ($event['recurring_series_id'] ?? '')) !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues = array_merge($formValues, $_POST);

    try {
        $service = new EventService();
        $service->updateFromForm($repo, $event, $_POST);
        $scope = (string) ($_POST['recurring_edit_scope'] ?? 'single');
        if ($isSeriesEvent && $scope !== 'single') {
            setFlash('success', 'Recurring event series updated successfully.');
        } else {
            setFlash('success', 'Event updated successfully.');
        }
        redirect('index.php?date=' . urlencode((string) $_POST['event_date']));
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('event_edit.php?id=' . $id);
    }
}

renderHeader('Edit Event');
require __DIR__ . '/event_form.php';
renderFooter();
