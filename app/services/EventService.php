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
        $eventStartUtcInput = trim((string) ($input['event_start_utc_input'] ?? ''));
        $isRecurringWeekly = isset($input['is_recurring_weekly']) ? 1 : 0;
        $recurringUntilDate = trim((string) ($input['recurring_until_date'] ?? ''));

        if ($eventName === '') {
            throw new InvalidArgumentException('Event name is required.');
        }
        if ($eventDate === '' || $eventTime === '') {
            throw new InvalidArgumentException('Event date and time are required.');
        }
        if ($isRecurringWeekly === 1 && $recurringUntilDate === '') {
            throw new InvalidArgumentException('Recurring weekly events now require a recurring until date.');
        }
        if ($isRecurringWeekly === 1 && $recurringUntilDate < $eventDate) {
            throw new InvalidArgumentException('Recurring end date cannot be earlier than the event date.');
        }

        $eventStartUtc = $eventStartUtcInput !== ''
            ? utcInputToUtc($eventStartUtcInput)
            : clanLocalToUtc($eventDate, $eventTime);

        return [
            'event_name' => $eventName,
            'event_description' => trim((string) ($input['event_description'] ?? '')),
            'event_location' => trim((string) ($input['event_location'] ?? '')),
            'host_name' => $hostName,
            'host_discord_user_id' => $hostDiscordUserId,
            'event_start_utc' => $eventStartUtc,
            'duration_minutes' => trim((string) ($input['duration_minutes'] ?? '')),
            'image_url' => trim((string) ($input['image_url'] ?? '')),
            'discord_channel_id' => trim((string) ($input['discord_channel_id'] ?? '')),
            'discord_mention_role_id' => $this->normaliseDiscordSnowflake((string) ($input['discord_mention_role_id'] ?? '')),
            'preferred_roles' => $this->normalisePreferredRoles(is_array($input['preferred_roles'] ?? null) ? $input['preferred_roles'] : []),
            'create_voice_chat_for_event' => isset($input['create_voice_chat_for_event']) ? 1 : 0,
            'is_active' => isset($input['is_active']) ? 1 : 0,
            'is_recurring_weekly' => $isRecurringWeekly,
            'recurring_until_utc' => $isRecurringWeekly === 1
                ? clanLocalToUtc($recurringUntilDate, '23:59')
                : null,
            'recurring_series_id' => trim((string) ($input['recurring_series_id'] ?? '')),
        ];
    }


    private function normaliseDiscordSnowflake(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^\d{15,32}$/', $value)) {
            throw new InvalidArgumentException('Selected Discord mention role is invalid.');
        }
        return $value;
    }

    private function normalisePreferredRoles(array $roles): array
    {
        $normalised = [];

        foreach ($roles as $role) {
            if (!is_array($role)) {
                continue;
            }

            $roleName = trim((string) ($role['role_name'] ?? ''));
            $emoji = trim((string) ($role['reaction_emoji'] ?? ''));
            if ($roleName === '' && $emoji === '') {
                continue;
            }
            if ($roleName === '' || $emoji === '') {
                throw new InvalidArgumentException('Each preferred role needs both a role name and a reaction emoji.');
            }

            $normalised[] = [
                'role_name' => mb_substr($roleName, 0, 100),
                'reaction_emoji' => mb_substr($emoji, 0, 100),
            ];
        }

        return $normalised;
    }

    public function createFromForm(EventRepository $repo, array $input): int
    {
        $data = $this->normaliseFormData($input);

        if ((int) $data['is_recurring_weekly'] === 1) {
            if (!$repo->supportsRecurringSeries()) {
                $data['recurring_series_id'] = null;
                return $repo->create($data);
            }

            $seriesId = $this->newSeriesId();
            $events = $this->buildRecurringInstances($data, $seriesId);
            $firstId = 0;
            foreach ($events as $eventData) {
                $createdId = $repo->create($eventData);
                if ($firstId === 0) {
                    $firstId = $createdId;
                }
            }
            return $firstId;
        }

        $data['recurring_series_id'] = null;
        return $repo->create($data);
    }

    public function updateFromForm(EventRepository $repo, array $event, array $input): void
    {
        $data = $this->normaliseFormData($input);
        $scope = (string) ($input['recurring_edit_scope'] ?? 'single');
        $seriesId = trim((string) ($event['recurring_series_id'] ?? ''));

        if (!$repo->supportsRecurringSeries()) {
            $data['recurring_series_id'] = null;
            $repo->update((int) $event['id'], $data);
            return;
        }

        if ($seriesId === '') {
            if ((int) $data['is_recurring_weekly'] === 1) {
                $newSeriesId = $this->newSeriesId();
                $events = $this->buildRecurringInstances($data, $newSeriesId);
                $first = array_shift($events);
                if ($first === null) {
                    throw new RuntimeException('No recurring events were generated.');
                }

                $repo->update((int) $event['id'], $first);

                foreach ($events as $eventData) {
                    $repo->create($eventData);
                }
                return;
            }

            $data['recurring_series_id'] = null;
            $repo->update((int) $event['id'], $data);
            return;
        }

        if ($scope === 'single') {
            $data['is_recurring_weekly'] = 0;
            $data['recurring_until_utc'] = null;
            $data['recurring_series_id'] = null;
            $repo->update((int) $event['id'], $data);
            return;
        }

        $replaceFromUtc = $scope === 'future'
            ? (string) $event['event_start_utc']
            : null;

        $existingSeriesEvents = $repo->getSeriesEvents($seriesId, $replaceFromUtc);

        if ((int) $data['is_recurring_weekly'] === 1) {
            $reuseSeriesId = $scope === 'all' ? $seriesId : $this->newSeriesId();
            $this->replaceSeriesEventsInPlace(
                $repo,
                $existingSeriesEvents,
                $this->buildRecurringInstances($data, $reuseSeriesId)
            );
            return;
        }

        $data['recurring_series_id'] = null;
        $data['is_recurring_weekly'] = 0;
        $data['recurring_until_utc'] = null;
        $this->replaceSeriesEventsInPlace($repo, $existingSeriesEvents, [$data]);
    }

    /**
     * Replaces a recurring series without deleting and recreating rows unnecessarily.
     *
     * This preserves existing occurrence rows in chronological order so stored Discord
     * daily message IDs, native Discord event IDs, voice channel IDs, and other sync
     * metadata remain attached and can be edited instead of duplicated.
     */
    private function replaceSeriesEventsInPlace(EventRepository $repo, array $existingEvents, array $newEvents): void
    {
        $existingEvents = array_values($existingEvents);
        $newEvents = array_values($newEvents);
        $countToUpdate = min(count($existingEvents), count($newEvents));

        for ($index = 0; $index < $countToUpdate; $index++) {
            $repo->update((int) $existingEvents[$index]['id'], $newEvents[$index]);
        }

        for ($index = $countToUpdate; $index < count($newEvents); $index++) {
            $repo->create($newEvents[$index]);
        }

        for ($index = $countToUpdate; $index < count($existingEvents); $index++) {
            $repo->delete((int) $existingEvents[$index]['id']);
        }
    }

    public function deleteEvent(EventRepository $repo, array $event, string $scope): int
    {
        $seriesId = trim((string) ($event['recurring_series_id'] ?? ''));

        if ($seriesId === '') {
            $repo->delete((int) $event['id']);
            return 1;
        }

        if ($scope === 'future') {
            return $repo->deleteSeriesEvents($seriesId, (string) $event['event_start_utc']);
        }

        if ($scope === 'all') {
            return $repo->deleteSeriesEvents($seriesId);
        }

        $repo->delete((int) $event['id']);
        return 1;
    }

    private function buildRecurringInstances(array $data, string $seriesId): array
    {
        $startLocal = utcToClanLocal((string) $data['event_start_utc']);
        $untilLocal = utcToClanLocal((string) $data['recurring_until_utc']);
        $events = [];
        $cursor = $startLocal;

        while ($cursor <= $untilLocal) {
            $eventData = $data;
            $eventData['event_start_utc'] = $cursor->setTimezone(utcTimezone())->format('Y-m-d H:i:s');
            $eventData['is_recurring_weekly'] = 1;
            $eventData['recurring_until_utc'] = $data['recurring_until_utc'];
            $eventData['recurring_series_id'] = $seriesId;
            $events[] = $eventData;
            $cursor = $cursor->modify('+7 days');
        }

        return $events;
    }

    private function newSeriesId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
