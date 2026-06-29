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
        $eventTimeSource = strtolower(trim((string) ($input['event_time_source'] ?? 'local')));
        $isRecurring = isset($input['is_recurring_weekly']) ? 1 : 0;
        $recurringUntilDate = trim((string) ($input['recurring_until_date'] ?? ''));
        $recurrenceInterval = $this->normaliseRecurrenceInterval($input['recurrence_interval'] ?? 1);
        $recurrenceUnit = $this->normaliseRecurrenceUnit((string) ($input['recurrence_unit'] ?? 'weeks'));

        if (!in_array($eventTimeSource, ['local', 'utc'], true)) {
            $eventTimeSource = 'local';
        }

        $eventStartUtc = null;
        if ($eventTimeSource === 'utc' && $eventStartUtcInput !== '') {
            $eventStartUtc = utcInputToUtc($eventStartUtcInput);
            $eventLocal = utcToClanLocal($eventStartUtc);
            $eventDate = $eventLocal->format('Y-m-d');
            $eventTime = $eventLocal->format('H:i');
        }

        if ($eventName === '') {
            throw new InvalidArgumentException('Event name is required.');
        }
        if ($eventDate === '' || $eventTime === '') {
            throw new InvalidArgumentException('Event date and time are required.');
        }
        if ($isRecurring === 1 && $recurringUntilDate === '') {
            throw new InvalidArgumentException('Recurring events require a recurring until date.');
        }
        if ($isRecurring === 1 && $recurringUntilDate < $eventDate) {
            throw new InvalidArgumentException('Recurring end date cannot be earlier than the event date.');
        }

        if ($isRecurring !== 1) {
            $recurrenceInterval = 1;
            $recurrenceUnit = 'weeks';
        }

        // The browser mirrors the local and UTC fields for convenience, but the
        // backend must only trust the side the user actively edited. This avoids
        // saving a browser-calculated UTC value that can be wrong during daylight
        // saving transitions.
        if ($eventStartUtc === null) {
            $eventStartUtc = clanLocalToUtc($eventDate, $eventTime);
        }

        return [
            'event_name' => $eventName,
            'event_description' => trim((string) ($input['event_description'] ?? '')),
            'event_location' => trim((string) ($input['event_location'] ?? '')),
            'host_name' => $hostName,
            'host_discord_user_id' => $hostDiscordUserId,
            'event_start_utc' => $eventStartUtc,
            'duration_minutes' => trim((string) ($input['duration_minutes'] ?? '')),
            'image_url' => trim((string) ($input['image_url'] ?? '')),
            'thumbnail_url' => trim((string) ($input['thumbnail_url'] ?? '')),
            'discord_channel_id' => trim((string) ($input['discord_channel_id'] ?? '')),
            'discord_mention_role_id' => $this->normaliseDiscordSnowflake((string) ($input['discord_mention_role_id'] ?? '')),
            'preferred_roles' => $this->normalisePreferredRoles(is_array($input['preferred_roles'] ?? null) ? $input['preferred_roles'] : []),
            'create_voice_chat_for_event' => isset($input['create_voice_chat_for_event']) ? 1 : 0,
            'is_active' => isset($input['is_active']) ? 1 : 0,
            // Column name kept for backwards compatibility. It now means "is recurring".
            'is_recurring_weekly' => $isRecurring,
            'recurrence_interval' => $recurrenceInterval,
            'recurrence_unit' => $recurrenceUnit,
            'recurring_until_utc' => $isRecurring === 1
                ? clanLocalToUtc($recurringUntilDate, '23:59')
                : null,
            'recurring_series_id' => trim((string) ($input['recurring_series_id'] ?? '')),
        ];
    }

    private function normaliseRecurrenceInterval(mixed $value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 1;
        }
        if (!preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException('Recurring interval must be a whole number.');
        }

        $interval = (int) $value;
        if ($interval < 1) {
            throw new InvalidArgumentException('Recurring interval must be at least 1.');
        }
        if ($interval > 365) {
            throw new InvalidArgumentException('Recurring interval cannot be greater than 365.');
        }

        return $interval;
    }

    private function normaliseRecurrenceUnit(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === 'day') {
            $value = 'days';
        }
        if ($value === 'week') {
            $value = 'weeks';
        }

        if (!in_array($value, ['days', 'weeks'], true)) {
            throw new InvalidArgumentException('Recurring unit must be days or weeks.');
        }

        return $value;
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
            $data['recurrence_interval'] = 1;
            $data['recurrence_unit'] = 'weeks';
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
        $data['recurrence_interval'] = 1;
        $data['recurrence_unit'] = 'weeks';
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
        $interval = max(1, (int) ($data['recurrence_interval'] ?? 1));
        $unit = $this->normaliseRecurrenceUnit((string) ($data['recurrence_unit'] ?? 'weeks'));
        $step = '+' . $interval . ' ' . $unit;
        $guard = 0;

        while ($cursor <= $untilLocal) {
            $eventData = $data;
            $eventData['event_start_utc'] = $cursor->setTimezone(utcTimezone())->format('Y-m-d H:i:s');
            $eventData['is_recurring_weekly'] = 1;
            $eventData['recurrence_interval'] = $interval;
            $eventData['recurrence_unit'] = $unit;
            $eventData['recurring_until_utc'] = $data['recurring_until_utc'];
            $eventData['recurring_series_id'] = $seriesId;
            $events[] = $eventData;

            $cursor = $cursor->modify($step);
            $guard++;
            if ($guard > 500) {
                throw new RuntimeException('Recurring series generated too many events. Please use a shorter date range or a larger interval.');
            }
        }

        return $events;
    }

    private function newSeriesId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
