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
    'duration_minutes' => '',
    'discord_channel_id' => appConfig()['clan']['default_discord_channel_id'],
    'image_url' => '',
    'event_description' => '',
    'is_active' => 1,
    'is_recurring_weekly' => 0,
    'recurring_until_date' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues = array_merge($formValues, $_POST);

    try {
        $service = new EventService();
        $data = $service->normaliseFormData($_POST);
        (new EventRepository())->create($data);
        setFlash('success', 'Event created successfully.');
        redirect('index.php?date=' . urlencode((string) $_POST['event_date']));
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('event_create.php?date=' . urlencode((string) ($_POST['event_date'] ?? '')));
    }
}

renderHeader('Add Event');
require __DIR__ . '/event_form.php';
renderFooter();
