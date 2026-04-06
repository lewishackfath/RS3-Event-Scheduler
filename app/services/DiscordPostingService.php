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
            $channelId = trim((string) ($event['discord_channel_id'] ?? ''));
            if ($channelId === '') {
                $channelId = trim((string) (appConfig()['discord']['daily_event_channel_id'] ?? ''));
            }

            if ($channelId === '') {
                $results[] = [
                    'event_name' => $event['event_name'],
                    'status' => 'skipped',
                    'message' => 'No Discord channel configured.',
                ];
                continue;
            }

            $embed = buildEventEmbed($event);
            $response = postDiscordMessage($channelId, '', [$embed]);
            $messageId = (string) ($response['id'] ?? '');

            if ($messageId !== '') {
                $this->events->markDailyPost((int) $event['id'], $channelId, $messageId);
            }

            $results[] = [
                'event_name' => $event['event_name'],
                'status' => 'posted',
                'message' => 'Posted successfully.',
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
                    } else {
                        $eventResults[] = 'Native Discord event was not created';
                    }
                }
            } else {
                $eventResults[] = 'Scheduled event creation disabled';
            }

            if ((bool) $config['enable_daily_event_posts']) {
                if (!empty($event['discord_daily_message_id'])) {
                    $eventResults[] = 'Daily event embed already exists';
                } else {
                    $channelId = trim((string) ($event['discord_channel_id'] ?? ''));
                    if ($channelId === '') {
                        $channelId = trim((string) $config['daily_event_channel_id']);
                    }
                    if ($channelId === '') {
                        $channelId = trim((string) appConfig()['clan']['default_discord_channel_id']);
                    }

                    if ($channelId === '') {
                        $eventResults[] = 'Skipped daily post: no channel configured';
                    } else {
                        $content = $scheduledEventUrl !== '' ? 'Discord event: ' . $scheduledEventUrl : '';
                        $response = postDiscordMessage($channelId, $content, [buildEventEmbed($event)]);
                        $messageId = (string) ($response['id'] ?? '');
                        if ($messageId !== '') {
                            $this->events->markDailyPost((int) $event['id'], $channelId, $messageId);
                            $eventResults[] = 'Posted daily event embed';
                        } else {
                            $eventResults[] = 'Daily event embed was not posted';
                        }
                    }
                }
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

}