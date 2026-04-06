<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/time.php';

final class EventService
{
    public function normaliseFormData(array $input): array
    {
        $eventName = trim((string) ($input['event_name'] ?? ''));
        $eventDate = trim((string) ($input['event_date'] ?? ''));
        $eventTime = trim((string) ($input['event_time'] ?? ''));

        if ($eventName === '') {
            throw new InvalidArgumentException('Event name is required.');
        }
        if ($eventDate === '' || $eventTime === '') {
            throw new InvalidArgumentException('Event date and time are required.');
        }

        return [
            'event_name' => $eventName,
            'event_description' => trim((string) ($input['event_description'] ?? '')),
            'host_name' => trim((string) ($input['host_name'] ?? '')),
            'event_start_utc' => clanLocalToUtc($eventDate, $eventTime),
            'duration_minutes' => trim((string) ($input['duration_minutes'] ?? '')),
            'image_url' => trim((string) ($input['image_url'] ?? '')),
            'discord_channel_id' => trim((string) ($input['discord_channel_id'] ?? appConfig()['clan']['default_discord_channel_id'] ?? '')),
            'is_active' => isset($input['is_active']) ? 1 : 0,
        ];
    }
}
