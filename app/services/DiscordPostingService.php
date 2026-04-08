<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/discord.php';
require_once __DIR__ . '/../lib/event_embeds.php';
require_once __DIR__ . '/../repositories/EventRepository.php';

final class DiscordPostingService
{
    private EventRepository $events;

    public function __construct()
    {
        $this->events = new EventRepository();
    }

    public function postEvents(array $events): array
    {
        $results = [];

        foreach ($events as $event) {
            $message = $this->postOrUpdateDailyEventMessage($event, 'post_only');
            $results[] = [
                'event_name' => (string) $event['event_name'],
                'status' => 'posted',
                'message' => $message,
            ];
        }

        return $results;
    }

    public function postWeeklySummaryForWeek(?string $date = null): array
    {
        return $this->postOrUpdateWeeklySummaryForWeek($date, true);
    }

    public function refreshWeeklySummariesForDates(array $localDates): array
    {
        $results = [];
        $seen = [];

        foreach ($localDates as $date) {
            $date = trim((string) $date);
            if ($date === '') {
                continue;
            }

            $range = weekRangeFromDate($date);
            $weekKey = (string) $range['week_start_utc'];
            if (isset($seen[$weekKey])) {
                continue;
            }

            $seen[$weekKey] = true;
            $results = array_merge($results, $this->postOrUpdateWeeklySummaryForWeek($date, false));
        }

        return $results;
    }

    public function syncPendingDiscordItemsForToday(?string $date = null): array
    {
        $config = appConfig()['discord'];
        $range = dayRangeFromDate($date);
        $events = $this->events->getForDay($range['day_start_utc'], $range['day_end_utc']);
        $results = $this->cleanupExpiredDailyPosts();

        if ($events === []) {
            $results[] = [
                'scope' => 'day_of_events',
                'status' => 'skipped',
                'message' => 'No events found for ' . $range['day_start_local']->format('j M Y') . '.',
            ];

            return array_merge($results, $this->refreshWeeklySummariesForDates([$range['day_start_local']->format('Y-m-d')]));
        }

        foreach ($events as $event) {
            $eventResults = [];
            $scheduledEventUrl = '';

            if ((bool) $config['enable_scheduled_events']) {
                [$scheduledEventUrl, $scheduledMessage] = $this->syncScheduledEvent($event);
                $eventResults[] = $scheduledMessage;
            } else {
                $eventResults[] = 'Scheduled event creation disabled';
            }

            if ((bool) $config['enable_daily_event_posts']) {
                $eventResults[] = $this->postOrUpdateDailyEventMessage($event, 'sync', $scheduledEventUrl);
            } else {
                $eventResults[] = 'Daily event posting disabled';
            }

            $results[] = [
                'scope' => 'day_of_events',
                'event_name' => (string) $event['event_name'],
                'status' => 'processed',
                'message' => implode(' · ', $eventResults),
            ];
        }

        return array_merge($results, $this->refreshWeeklySummariesForDates([$range['day_start_local']->format('Y-m-d')]));
    }

    public function publishDayOfEvents(?string $date = null): array
    {
        $config = appConfig()['discord'];
        $range = dayRangeFromDate($date);
        $events = $this->events->getForDay($range['day_start_utc'], $range['day_end_utc']);
        $results = $this->cleanupExpiredDailyPosts();

        if ($events === []) {
            $results[] = [
                'scope' => 'day_of_events',
                'status' => 'skipped',
                'message' => 'No events found for ' . $range['day_start_local']->format('j M Y') . '.',
            ];
            return $results;
        }

        foreach ($events as $event) {
            $eventResults = [];
            $scheduledEventUrl = '';

            if ((bool) $config['enable_scheduled_events']) {
                [$scheduledEventUrl, $scheduledMessage] = $this->syncScheduledEvent($event);
                $eventResults[] = $scheduledMessage;
            } else {
                $eventResults[] = 'Scheduled event creation disabled';
            }

            if ((bool) $config['enable_daily_event_posts']) {
                $eventResults[] = $this->postOrUpdateDailyEventMessage($event, 'publish', $scheduledEventUrl);
            } else {
                $eventResults[] = 'Daily event posting disabled';
            }

            $results[] = [
                'scope' => 'day_of_events',
                'event_name' => (string) $event['event_name'],
                'status' => 'processed',
                'message' => implode(' · ', $eventResults),
            ];
        }

        return $results;
    }


    public function syncEventById(int $eventId): array
    {
        $event = $this->events->getById($eventId);
        if ($event === null) {
            return [[
                'scope' => 'day_of_events',
                'status' => 'skipped',
                'message' => 'Event not found for Discord sync.',
            ]];
        }

        $config = appConfig()['discord'];
        $eventResults = [];
        $scheduledEventUrl = '';

        if ((bool) $config['enable_scheduled_events']) {
            [$scheduledEventUrl, $scheduledMessage] = $this->syncScheduledEvent($event);
            $eventResults[] = $scheduledMessage;
        } else {
            $eventResults[] = 'Scheduled event creation disabled';
        }

        if ((bool) $config['enable_daily_event_posts']) {
            $eventResults[] = $this->postOrUpdateDailyEventMessage($event, 'sync', $scheduledEventUrl);
        } else {
            $eventResults[] = 'Daily event posting disabled';
        }

        return [[
            'scope' => 'day_of_events',
            'event_name' => (string) $event['event_name'],
            'status' => 'processed',
            'message' => implode(' · ', $eventResults),
        ]];
    }

    public function deleteDiscordArtifactsForEvent(array $event): array
    {
        $results = [];
        $existingChannelId = trim((string) ($event['discord_daily_channel_id'] ?? ''));
        $existingMessageId = trim((string) ($event['discord_daily_message_id'] ?? ''));

        if ($existingChannelId !== '' && $existingMessageId !== '') {
            try {
                deleteDiscordMessage($existingChannelId, $existingMessageId);
                $results[] = 'Deleted daily event post';
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
                $results[] = 'Daily event post was already missing';
            }
        }

        if (!empty($event['id'])) {
            $this->events->clearDailyPostTracking((int) $event['id']);
        }

        $scheduledEventId = trim((string) ($event['discord_scheduled_event_id'] ?? ''));
        if ($scheduledEventId !== '') {
            try {
                deleteExternalScheduledEvent($scheduledEventId);
                $results[] = 'Deleted native Discord event';
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
                $results[] = 'Native Discord event was already missing';
            }
        }

        if (!empty($event['id'])) {
            $this->events->clearScheduledEventTracking((int) $event['id']);
        }

        return $results;
    }

    private function postOrUpdateWeeklySummaryForWeek(?string $date, bool $createIfMissing): array
    {
        $config = appConfig()['discord'];
        if (!(bool) $config['enable_weekly_summary']) {
            return [[
                'scope' => 'weekly_summary',
                'status' => 'skipped',
                'message' => 'Weekly summary posting is disabled in .env.',
            ]];
        }

        $channelId = trim((string) $config['weekly_summary_channel_id']);
        if ($channelId === '') {
            throw new RuntimeException('DISCORD_WEEKLY_SUMMARY_CHANNEL_ID is not configured.');
        }

        $range = weekRangeFromDate($date);
        $events = $this->events->getForWeek($range['week_start_utc'], $range['week_end_utc']);
        $existing = $this->events->getWeeklyPost($range['week_start_utc']);
        $embed = buildWeeklySummaryEmbed($events, $range['week_start_local']);

        if ($existing && !empty($existing['discord_message_id'])) {
            try {
                editDiscordMessage((string) $existing['discord_channel_id'], (string) $existing['discord_message_id'], '', [$embed]);
                $this->events->recordWeeklyPost($range['week_start_utc'], (string) $existing['discord_channel_id'], (string) $existing['discord_message_id']);

                return [[
                    'scope' => 'weekly_summary',
                    'status' => 'updated',
                    'message' => 'Updated existing weekly summary message.',
                ]];
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }

                $this->events->deleteWeeklyPost($range['week_start_utc']);
                $existing = null;
            }
        }

        if (!$createIfMissing) {
            return [[
                'scope' => 'weekly_summary',
                'status' => 'skipped',
                'message' => 'Skipped weekly summary refresh for week of ' . $range['week_start_local']->format('j M Y') . ' because no summary post exists yet.',
            ]];
        }

        $response = postDiscordMessage($channelId, '', [$embed]);
        $messageId = (string) ($response['id'] ?? '');
        if ($messageId !== '') {
            $this->events->recordWeeklyPost($range['week_start_utc'], $channelId, $messageId);
        }

        return [[
            'scope' => 'weekly_summary',
            'status' => 'posted',
            'message' => 'Posted weekly summary for week of ' . $range['week_start_local']->format('j M Y') . '.',
        ]];
    }

    private function syncScheduledEvent(array $event): array
    {
        $scheduledEventId = trim((string) ($event['discord_scheduled_event_id'] ?? ''));
        $scheduledEventUrl = '';
        $eventStartUtc = new DateTimeImmutable((string) $event['event_start_utc'], utcTimezone());
        $nowUtc = new DateTimeImmutable('now', utcTimezone());
        $canExist = $eventStartUtc > $nowUtc && (string) ($event['status'] ?? 'scheduled') !== 'cancelled';

        if ($scheduledEventId !== '' && !$canExist) {
            try {
                deleteExternalScheduledEvent($scheduledEventId);
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
            }
            $this->events->clearScheduledEventTracking((int) $event['id']);

            return ['', 'Removed native Discord event'];
        }

        if (!$canExist) {
            return ['', 'Skipped native Discord event: event start time is already in the past'];
        }

        if ($scheduledEventId !== '') {
            try {
                editExternalScheduledEvent($scheduledEventId, $event);
                $scheduledEventUrl = buildDiscordScheduledEventUrl($scheduledEventId);
                $this->events->markScheduledEvent((int) $event['id'], $scheduledEventId);
                return [$scheduledEventUrl, 'Updated native Discord event'];
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
                $this->events->clearScheduledEventTracking((int) $event['id']);
            }
        }

        $scheduled = createExternalScheduledEvent($event);
        $newScheduledEventId = (string) ($scheduled['id'] ?? '');
        if ($newScheduledEventId === '') {
            return ['', 'Native Discord event was not created'];
        }

        $this->events->markScheduledEvent((int) $event['id'], $newScheduledEventId);
        $scheduledEventUrl = buildDiscordScheduledEventUrl($newScheduledEventId);

        return [$scheduledEventUrl, 'Created native Discord event'];
    }

    private function cleanupExpiredDailyPosts(): array
    {
        $results = [];

        foreach ($this->events->getEventsWithDailyPosts() as $event) {
            if (!$this->hasEventEnded($event)) {
                continue;
            }

            $existingChannelId = trim((string) ($event['discord_daily_channel_id'] ?? ''));
            $existingMessageId = trim((string) ($event['discord_daily_message_id'] ?? ''));
            if ($existingChannelId !== '' && $existingMessageId !== '') {
                try {
                    deleteDiscordMessage($existingChannelId, $existingMessageId);
                    $results[] = [
                        'scope' => 'day_of_events',
                        'event_name' => (string) ($event['event_name'] ?? 'Event'),
                        'status' => 'cleaned_up',
                        'message' => 'Deleted expired daily event post.',
                    ];
                } catch (Throwable $e) {
                    if (!$this->isUnknownDiscordResourceError($e)) {
                        throw $e;
                    }
                    $results[] = [
                        'scope' => 'day_of_events',
                        'event_name' => (string) ($event['event_name'] ?? 'Event'),
                        'status' => 'cleaned_up',
                        'message' => 'Expired daily event post was already missing.',
                    ];
                }
            }

            $this->events->clearDailyPostTracking((int) $event['id']);
        }

        return $results;
    }

    private function hasEventEnded(array $event): bool
    {
        $durationMinutes = (int) ($event['duration_minutes'] ?? 0);
        if ($durationMinutes <= 0) {
            $durationMinutes = max(1, (int) appConfig()['discord']['default_event_duration_minutes']);
        }

        $endUtc = (new DateTimeImmutable((string) $event['event_start_utc'], utcTimezone()))
            ->modify('+' . $durationMinutes . ' minutes');

        return $endUtc <= new DateTimeImmutable('now', utcTimezone());
    }

    private function resolveDailyChannelId(array $event): string
    {
        $channelId = trim((string) ($event['discord_channel_id'] ?? ''));
        if ($channelId === '') {
            $channelId = trim((string) (appConfig()['discord']['daily_event_channel_id'] ?? ''));
        }
        if ($channelId === '') {
            $channelId = trim((string) (appConfig()['clan']['default_discord_channel_id'] ?? ''));
        }
        return $channelId;
    }

    private function buildDailyMessageContent(string $scheduledEventUrl): string
    {
        return $scheduledEventUrl !== '' ? 'Discord event: ' . $scheduledEventUrl : '';
    }

    private function syncPreferredRoleReactions(string $channelId, string $messageId, array $roles): void
    {
        clearDiscordReactions($channelId, $messageId);

        $seen = [];
        foreach ($roles as $role) {
            $emoji = trim((string) ($role['reaction_emoji'] ?? ''));
            if ($emoji === '' || isset($seen[$emoji])) {
                continue;
            }
            $seen[$emoji] = true;
            addDiscordReaction($channelId, $messageId, $emoji);
        }
    }

    private function postOrUpdateDailyEventMessage(array $event, string $mode, string $scheduledEventUrl = ''): string
    {
        $existingChannelId = trim((string) ($event['discord_daily_channel_id'] ?? ''));
        $existingMessageId = trim((string) ($event['discord_daily_message_id'] ?? ''));

        if ($this->hasEventEnded($event) || (string) ($event['status'] ?? 'scheduled') === 'cancelled') {
            if ($existingChannelId !== '' && $existingMessageId !== '') {
                try {
                    deleteDiscordMessage($existingChannelId, $existingMessageId);
                } catch (Throwable $e) {
                    if (!$this->isUnknownDiscordResourceError($e)) {
                        throw $e;
                    }
                }
            }
            $this->events->clearDailyPostTracking((int) $event['id']);
            return 'Removed daily event embed';
        }

        $channelId = $this->resolveDailyChannelId($event);
        if ($channelId === '') {
            return 'Skipped daily post: no channel configured';
        }

        $content = $this->buildDailyMessageContent($scheduledEventUrl);
        $embed = buildEventEmbed($event);

        if ($existingMessageId !== '' && $existingChannelId !== '' && $existingChannelId === $channelId) {
            try {
                editDiscordMessage($existingChannelId, $existingMessageId, $content, [$embed]);
                $this->syncPreferredRoleReactions($existingChannelId, $existingMessageId, (array) ($event['preferred_roles'] ?? []));
                $this->events->markDailyPost((int) $event['id'], $existingChannelId, $existingMessageId);
                return $mode === 'publish' ? 'Updated existing daily event embed' : 'Updated daily event embed';
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
                $this->events->clearDailyPostTracking((int) $event['id']);
                $existingChannelId = '';
                $existingMessageId = '';
            }
        }

        if ($existingMessageId !== '' && $existingChannelId !== '' && $existingChannelId !== $channelId) {
            try {
                deleteDiscordMessage($existingChannelId, $existingMessageId);
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
            }
            $this->events->clearDailyPostTracking((int) $event['id']);
        }

        $response = postDiscordMessage($channelId, $content, [$embed]);
        $messageId = (string) ($response['id'] ?? '');
        if ($messageId === '') {
            return 'Daily event embed was not posted';
        }

        $this->events->markDailyPost((int) $event['id'], $channelId, $messageId);
        $this->syncPreferredRoleReactions($channelId, $messageId, (array) ($event['preferred_roles'] ?? []));

        return 'Posted daily event embed';
    }

    private function isUnknownDiscordResourceError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Unknown Message')
            || str_contains($message, 'Unknown Guild Scheduled Event')
            || str_contains($message, '10008')
            || str_contains($message, '10070');
    }
}
