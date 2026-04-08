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
                $message = $e->getMessage();
                $isUnknownMessage = str_contains($message, 'Unknown Message') || str_contains($message, '10008');
                if (!$isUnknownMessage) {
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
            $results = array_merge($results, $this->postWeeklySummaryForWeek($date));
        }

        return $results;
    }

    public function syncPendingDiscordItemsForToday(?string $date = null): array
    {
        $config = appConfig()['discord'];
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
            $eventResults = [];
            $scheduledEventUrl = '';
            $eventStartUtc = new DateTimeImmutable((string) $event['event_start_utc'], utcTimezone());
            $nowUtc = new DateTimeImmutable('now', utcTimezone());
            $canCreateScheduledEvent = $eventStartUtc > $nowUtc;

            if ((bool) $config['enable_scheduled_events']) {
                if (!empty($event['discord_scheduled_event_id'])) {
                    $scheduledEventUrl = buildDiscordScheduledEventUrl((string) $event['discord_scheduled_event_id']);
                    $eventResults[] = 'Native Discord event already exists';
                } elseif (!$canCreateScheduledEvent) {
                    $eventResults[] = 'Skipped native Discord event: event start time is already in the past';
                } else {
                    $scheduled = createExternalScheduledEvent($event);
                    $scheduledEventId = (string) ($scheduled['id'] ?? '');
                    if ($scheduledEventId !== '') {
                        $this->events->markScheduledEvent((int) $event['id'], $scheduledEventId);
                        $scheduledEventUrl = buildDiscordScheduledEventUrl($scheduledEventId);
                        $eventResults[] = 'Created native Discord event';
                    } else {
                        $eventResults[] = 'Native Discord event was not created';
                    }
                }
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

        $results = array_merge($results, $this->refreshWeeklySummariesForDates([$range['day_start_local']->format('Y-m-d')]));

        return $results;
    }

    public function publishDayOfEvents(?string $date = null): array
    {
        $config = appConfig()['discord'];
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
            $eventResults = [];
            $scheduledEventUrl = '';

            if ((bool) $config['enable_scheduled_events']) {
                if (!empty($event['discord_scheduled_event_id'])) {
                    $scheduledEventUrl = buildDiscordScheduledEventUrl((string) $event['discord_scheduled_event_id']);
                    $eventResults[] = 'Native Discord event already exists';
                } else {
                    $scheduled = createExternalScheduledEvent($event);
                    $scheduledEventId = (string) ($scheduled['id'] ?? '');
                    if ($scheduledEventId !== '') {
                        $this->events->markScheduledEvent((int) $event['id'], $scheduledEventId);
                        $scheduledEventUrl = buildDiscordScheduledEventUrl($scheduledEventId);
                        $eventResults[] = 'Created native Discord event';
                    }
                }
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
        $channelId = $this->resolveDailyChannelId($event);
        if ($channelId === '') {
            return 'Skipped daily post: no channel configured';
        }

        $content = $this->buildDailyMessageContent($scheduledEventUrl);
        $embed = buildEventEmbed($event);
        $existingChannelId = trim((string) ($event['discord_daily_channel_id'] ?? ''));
        $existingMessageId = trim((string) ($event['discord_daily_message_id'] ?? ''));

        if ($existingMessageId !== '' && $existingChannelId !== '' && $existingChannelId === $channelId) {
            try {
                editDiscordMessage($existingChannelId, $existingMessageId, $content, [$embed]);
                $this->syncPreferredRoleReactions($existingChannelId, $existingMessageId, (array) ($event['preferred_roles'] ?? []));
                $this->events->markDailyPost((int) $event['id'], $existingChannelId, $existingMessageId);
                return $mode === 'publish' ? 'Updated existing daily event embed' : 'Updated daily event embed';
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $isUnknownMessage = str_contains($message, 'Unknown Message') || str_contains($message, '10008');
                if (!$isUnknownMessage) {
                    throw $e;
                }
                $this->events->clearDiscordTracking((int) $event['id']);
            }
        }

        if ($existingMessageId !== '' && $existingChannelId !== '' && $existingChannelId !== $channelId) {
            try {
                deleteDiscordMessage($existingChannelId, $existingMessageId);
            } catch (Throwable $e) {
                // Ignore missing old messages and just recreate in the correct channel.
            }
            $this->events->clearDiscordTracking((int) $event['id']);
        }

        $response = postDiscordMessage($channelId, $content, [$embed]);
        $messageId = (string) ($response['id'] ?? '');
        if ($messageId === '') {
            return 'Daily event embed was not posted';
        }

        $this->events->markDailyPost((int) $event['id'], $channelId, $messageId);
        $this->syncPreferredRoleReactions($channelId, $messageId, (array) ($event['preferred_roles'] ?? []));

        return $mode === 'sync' ? 'Posted daily event embed' : 'Posted daily event embed';
    }
}
