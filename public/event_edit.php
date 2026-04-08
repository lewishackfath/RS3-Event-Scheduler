<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/../app/services/DiscordPostingService.php';

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
    'event_start_utc_input' => utcToInputValue($event['event_start_utc']),
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
        $discordService = new DiscordPostingService();
        $scope = (string) ($_POST['recurring_edit_scope'] ?? 'single');
        $normalised = $service->normaliseFormData($_POST);
        $affectedWeekDates = [];

        if ($isSeriesEvent && $scope !== 'single') {
            $fromUtc = $scope === 'future' ? (string) $event['event_start_utc'] : null;
            foreach ($repo->getSeriesEvents((string) $event['recurring_series_id'], $fromUtc) as $seriesEvent) {
                $affectedWeekDates[] = weekStartDateFromUtc((string) $seriesEvent['event_start_utc']);
            }

            $newRangeEndUtc = (string) ($normalised['recurring_until_utc'] ?: $normalised['event_start_utc']);
            $affectedWeekDates = array_merge(
                $affectedWeekDates,
                weekDatesCoveredByUtcRange((string) $normalised['event_start_utc'], $newRangeEndUtc)
            );
        } else {
            $affectedWeekDates[] = weekStartDateFromUtc((string) $event['event_start_utc']);
            $affectedWeekDates[] = weekStartDateFromUtc((string) $normalised['event_start_utc']);
        }

        $service->updateFromForm($repo, $event, $_POST);
        $discordService->refreshWeeklySummariesForDates($affectedWeekDates);

        if ($isSeriesEvent && $scope !== 'single') {
            setFlash('success', 'Recurring event series updated successfully. Weekly summary refreshed.');
        } else {
            setFlash('success', 'Event updated successfully. Weekly summary refreshed.');
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
