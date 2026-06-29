<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';

$formValues = [
    'event_name' => '',
    'host_name' => '',
    'host_discord_user_id' => '',
    'event_date' => (string) ($_GET['date'] ?? ''),
    'event_time' => '',
    'event_start_utc_input' => '',
    'event_time_source' => 'local',
    'duration_minutes' => '',
    'discord_channel_id' => '',
    'preferred_roles' => [],
    'discord_mention_role_id' => '',
    'event_location' => '',
    'image_url' => '',
    'thumbnail_url' => '',
    'event_description' => '',
    'create_voice_chat_for_event' => 0,
    'is_active' => 1,
    'is_recurring_weekly' => 0,
    'recurrence_interval' => 1,
    'recurrence_unit' => 'weeks',
    'recurring_until_date' => '',
    'recurring_edit_scope' => 'single',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues = array_merge($formValues, $_POST);

    try {
        $service = new EventService();
        $normalised = $service->normaliseFormData($_POST);
        $service->createFromForm(new EventRepository(), $_POST);
        $redirectDate = utcToClanLocal((string) $normalised['event_start_utc'])->format('Y-m-d');
        setFlash('success', !empty($_POST['is_recurring_weekly']) ? 'Recurring event series created successfully.' : 'Event created successfully.');
        redirect('index.php?date=' . urlencode($redirectDate));
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('event_create.php?date=' . urlencode((string) ($_POST['event_date'] ?? '')));
    }
}

renderHeader('Add Event');
require __DIR__ . '/event_form.php';
renderFooter();
