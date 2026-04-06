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
        $hostName = trim((string) ($input['host_name'] ?? ''));
        $hostDiscordUserId = trim((string) ($input['host_discord_user_id'] ?? ''));
        $isRecurringWeekly = isset($input['is_recurring_weekly']) ? 1 : 0;
        $recurringUntilDate = trim((string) ($input['recurring_until_date'] ?? ''));

        if ($eventName === '') {
            throw new InvalidArgumentException('Event name is required.');
        }
        if ($eventDate === '' || $eventTime === '') {
            throw new InvalidArgumentException('Event date and time are required.');
        }
        if ($isRecurringWeekly === 1 && $recurringUntilDate !== '' && $recurringUntilDate < $eventDate) {
            throw new InvalidArgumentException('Recurring end date cannot be earlier than the event date.');
        }

        return [
            'event_name' => $eventName,
            'event_description' => trim((string) ($input['event_description'] ?? '')),
            'host_name' => $hostName,
            'host_discord_user_id' => $hostDiscordUserId,
            'event_start_utc' => clanLocalToUtc($eventDate, $eventTime),
            'duration_minutes' => trim((string) ($input['duration_minutes'] ?? '')),
            'image_url' => trim((string) ($input['image_url'] ?? '')),
            'discord_channel_id' => trim((string) ($input['discord_channel_id'] ?? appConfig()['clan']['default_discord_channel_id'] ?? '')),
            'is_active' => isset($input['is_active']) ? 1 : 0,
            'is_recurring_weekly' => $isRecurringWeekly,
            'recurring_until_utc' => $isRecurringWeekly === 1 && $recurringUntilDate !== ''
                ? clanLocalToUtc($recurringUntilDate, '23:59')
                : null,
        ];
    }
}
