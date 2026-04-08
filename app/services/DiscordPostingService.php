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
            $sync = $this->syncDiscordArtifactsForEvent($event, true);
            $results[] = [
                'event_name' => (string) ($event['event_name'] ?? 'Event'),
                'status' => 'posted',
                'message' => implode(' · ', $sync),
            ];
        }

        return $results;
    }

    public function postWeeklySummaryForWeek(?string $date = null): array
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
            }
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

    public function syncPendingDiscordItemsForToday(?string $date = null): array
    {
        $range = dayRangeFromDate($date);
        $events = $this->events->getForDay($range['day_start_utc'], $range['day_end_utc']);
        $results = [];

        if ($events === []) {
            return [[
                'scope' => 'day_of_events',
                'status' => 'skipped',
                'message' => 'No events found for ' . $range['day_start_local']->format('j M Y') . '.',
            ]];
        }

        foreach ($events as $event) {
            $eventResults = $this->syncDiscordArtifactsForEvent($event, false);
            $results[] = [
                'scope' => 'day_of_events',
                'event_name' => (string) $event['event_name'],
                'status' => 'processed',
                'message' => implode(' · ', $eventResults),
            ];
        }

        return $results;
    }

    public function publishDayOfEvents(?string $date = null): array
    {
        $range = dayRangeFromDate($date);
        $events = $this->events->getForDay($range['day_start_utc'], $range['day_end_utc']);
        $results = [];

        if ($events === []) {
            return [[
                'scope' => 'day_of_events',
                'status' => 'skipped',
                'message' => 'No events found for ' . $range['day_start_local']->format('j M Y') . '.',
            ]];
        }

        foreach ($events as $event) {
            $eventResults = $this->syncDiscordArtifactsForEvent($event, true);
            $results[] = [
                'scope' => 'day_of_events',
                'event_name' => (string) $event['event_name'],
                'status' => 'processed',
                'message' => implode(' · ', $eventResults),
            ];
        }

        return $results;
    }

    public function syncEventById(int $id): array
    {
        $event = $this->events->getById($id);
        if ($event === null) {
            throw new RuntimeException('Event not found.');
        }

        return $this->syncDiscordArtifactsForEvent($event, true);
    }

    public function cancelEventById(int $id): array
    {
        $event = $this->events->getById($id);
        if ($event === null) {
            throw new RuntimeException('Event not found.');
        }

        $results = $this->removeDiscordArtifactsForEvent($event, true, true, true);
        $this->events->updateStatus($id, 'cancelled');
        $results[] = 'Marked event as cancelled';

        return $results;
    }

    public function deleteEventArtifactsById(int $id): array
    {
        $event = $this->events->getById($id);
        if ($event === null) {
            return [];
        }

        return $this->removeDiscordArtifactsForEvent($event, false, true, true);
    }

    public function deleteEventArtifactsForSeries(string $seriesId, ?string $fromUtc = null): array
    {
        $events = $this->events->getSeriesEvents($seriesId, $fromUtc);
        $results = [];

        foreach ($events as $event) {
            $parts = $this->removeDiscordArtifactsForEvent($event, false, true, true);
            if ($parts === []) {
                $parts[] = 'No Discord items to delete';
            }
            $results[] = (string) $event['event_name'] . ': ' . implode(' · ', $parts);
        }

        return $results;
    }

    private function syncDiscordArtifactsForEvent(array $event, bool $allowCreateScheduledEvent): array
    {
        $config = appConfig()['discord'];
        $eventResults = [];
        $scheduledEventUrl = '';
        $eventStartUtc = new DateTimeImmutable((string) $event['event_start_utc'], utcTimezone());
        $nowUtc = new DateTimeImmutable('now', utcTimezone());
        $canCreateScheduledEvent = $allowCreateScheduledEvent && $eventStartUtc > $nowUtc;

        if ((bool) $config['enable_scheduled_events']) {
            $scheduledResult = $this->syncScheduledEvent($event, $canCreateScheduledEvent);
            $eventResults[] = $scheduledResult['message'];
            $scheduledEventUrl = $scheduledResult['url'];
        } else {
            $eventResults[] = 'Scheduled event creation disabled';
        }

        if ((bool) $config['enable_daily_event_posts']) {
            $eventResults[] = $this->syncDailyMessage($event, $scheduledEventUrl);
        } else {
            $eventResults[] = 'Daily event posting disabled';
        }

        return $eventResults;
    }

    private function syncScheduledEvent(array $event, bool $canCreateScheduledEvent): array
    {
        $scheduledEventId = trim((string) ($event['discord_scheduled_event_id'] ?? ''));

        if ($scheduledEventId !== '') {
            try {
                editExternalScheduledEvent($scheduledEventId, $event);
                return [
                    'message' => 'Updated native Discord event',
                    'url' => buildDiscordScheduledEventUrl($scheduledEventId),
                ];
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }

                $scheduledEventId = '';
                $this->events->clearDiscordTracking((int) $event['id']);
                $event['discord_daily_channel_id'] = null;
                $event['discord_daily_message_id'] = null;
            }
        }

        if (!$canCreateScheduledEvent) {
            return [
                'message' => 'Skipped native Discord event: event start time is already in the past',
                'url' => '',
            ];
        }

        $scheduled = createExternalScheduledEvent($event);
        $scheduledEventId = (string) ($scheduled['id'] ?? '');
        if ($scheduledEventId !== '') {
            $this->events->markScheduledEvent((int) $event['id'], $scheduledEventId);
            return [
                'message' => 'Created native Discord event',
                'url' => buildDiscordScheduledEventUrl($scheduledEventId),
            ];
        }

        return [
            'message' => 'Native Discord event was not created',
            'url' => '',
        ];
    }

    private function syncDailyMessage(array $event, string $scheduledEventUrl): string
    {
        $preferredChannelId = $this->resolveDailyPostChannelId($event);
        if ($preferredChannelId === '') {
            return 'Skipped daily post: no channel configured';
        }

        $content = $scheduledEventUrl !== '' ? 'Discord event: ' . $scheduledEventUrl : '';
        $embed = buildEventEmbed($event);
        $existingChannelId = trim((string) ($event['discord_daily_channel_id'] ?? ''));
        $existingMessageId = trim((string) ($event['discord_daily_message_id'] ?? ''));

        if ($existingChannelId !== '' && $existingMessageId !== '' && $existingChannelId !== $preferredChannelId) {
            try {
                deleteDiscordMessage($existingChannelId, $existingMessageId);
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
            }
            $existingChannelId = '';
            $existingMessageId = '';
        }

        if ($existingChannelId !== '' && $existingMessageId !== '') {
            try {
                editDiscordMessage($existingChannelId, $existingMessageId, $content, [$embed]);
                $this->events->markDailyPost((int) $event['id'], $existingChannelId, $existingMessageId);
                return 'Updated daily event embed';
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
                $existingChannelId = '';
                $existingMessageId = '';
            }
        }

        $response = postDiscordMessage($preferredChannelId, $content, [$embed]);
        $messageId = (string) ($response['id'] ?? '');
        if ($messageId !== '') {
            $this->events->markDailyPost((int) $event['id'], $preferredChannelId, $messageId);
            return 'Posted daily event embed';
        }

        return 'Daily event embed was not posted';
    }

    private function removeDiscordArtifactsForEvent(array $event, bool $cancelScheduledEvent, bool $deleteDailyMessage, bool $clearTracking): array
    {
        $results = [];

        $scheduledEventId = trim((string) ($event['discord_scheduled_event_id'] ?? ''));
        if ($scheduledEventId !== '') {
            try {
                if ($cancelScheduledEvent) {
                    cancelExternalScheduledEvent($scheduledEventId);
                    $results[] = 'Cancelled native Discord event';
                } else {
                    $guildId = trim((string) appConfig()['discord']['guild_id']);
                    if ($guildId !== '') {
                        discordDeleteScheduledEvent($guildId, $scheduledEventId);
                    }
                    $results[] = 'Deleted native Discord event';
                }
            } catch (Throwable $e) {
                if ($this->isUnknownDiscordResourceError($e)) {
                    $results[] = $cancelScheduledEvent ? 'Native Discord event already missing' : 'Native Discord event already deleted';
                } else {
                    throw $e;
                }
            }
        }

        $dailyChannelId = trim((string) ($event['discord_daily_channel_id'] ?? ''));
        $dailyMessageId = trim((string) ($event['discord_daily_message_id'] ?? ''));
        if ($deleteDailyMessage && $dailyChannelId !== '' && $dailyMessageId !== '') {
            try {
                deleteDiscordMessage($dailyChannelId, $dailyMessageId);
                $results[] = 'Deleted daily event message';
            } catch (Throwable $e) {
                if ($this->isUnknownDiscordResourceError($e)) {
                    $results[] = 'Daily event message already missing';
                } else {
                    throw $e;
                }
            }
        }

        if ($clearTracking) {
            $this->events->clearDiscordTracking((int) $event['id']);
        }

        return $results;
    }

    private function resolveDailyPostChannelId(array $event): string
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

    private function isUnknownDiscordResourceError(Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'Unknown Message')
            || str_contains($message, 'Unknown Channel')
            || str_contains($message, 'Unknown Guild Scheduled Event')
            || str_contains($message, '10003')
            || str_contains($message, '10008')
            || str_contains($message, '10070');
    }
}
