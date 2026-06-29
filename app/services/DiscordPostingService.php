<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/discord.php';
require_once __DIR__ . '/../lib/event_embeds.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../repositories/EventRepository.php';

final class DiscordPostingService
{
    private EventRepository $events;

    public function __construct()
    {
        $this->events = new EventRepository();
    }


    /**
     * Build stable hashes for Discord artefacts so cron can skip PATCH calls when
     * the rendered Discord payload has not changed since the last successful sync.
     */
    private function syncPayloadHash(array $payload): string
    {
        return hash('sha256', json_encode($this->normaliseForHash($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normaliseForHash(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $keys = array_keys($value);
        $isList = $keys === range(0, count($value) - 1);
        if ($isList) {
            return array_map(fn (mixed $item): mixed => $this->normaliseForHash($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normaliseForHash($item);
        }

        return $value;
    }

    private function normalisedPreferredRolesForHash(array $roles): array
    {
        $normalised = [];
        foreach ($roles as $role) {
            if (!is_array($role)) {
                continue;
            }
            $roleName = trim((string) ($role['role_name'] ?? ''));
            $emoji = trim((string) ($role['reaction_emoji'] ?? ''));
            if ($roleName === '' || $emoji === '') {
                continue;
            }
            $normalised[] = [
                'role_name' => $roleName,
                'reaction_emoji' => $emoji,
            ];
        }

        return $normalised;
    }

    private function dailyEventSyncHash(array $event, string $channelId, string $content, array $embed, array $allowedMentionRoleIds): string
    {
        sort($allowedMentionRoleIds, SORT_STRING);

        return $this->syncPayloadHash([
            'type' => 'daily_event_message_v1',
            'channel_id' => $channelId,
            'content' => $content,
            'embeds' => [$embed],
            'allowed_mention_role_ids' => $allowedMentionRoleIds,
            'preferred_role_reactions' => $this->normalisedPreferredRolesForHash((array) ($event['preferred_roles'] ?? [])),
        ]);
    }

    private function endedDailyEventSyncHash(array $event): string
    {
        return $this->syncPayloadHash([
            'type' => 'daily_event_ended_message_v1',
            'embeds' => [buildEndedEventEmbed($event)],
        ]);
    }

    private function scheduledEventSyncHash(array $event): string
    {
        return $this->syncPayloadHash([
            'type' => 'native_discord_scheduled_event_v1',
            'payload' => buildDiscordScheduledEventPayload($event),
        ]);
    }

    private function weeklySummarySyncHash(string $channelId, array $embed): string
    {
        return $this->syncPayloadHash([
            'type' => 'weekly_summary_message_v1',
            'channel_id' => $channelId,
            'embeds' => [$embed],
        ]);
    }

    private function weeklyPosterGalleryItems(array $events): array
    {
        $items = [];
        $seenUrls = [];

        foreach ($events as $event) {
            if (count($items) >= 10) {
                break;
            }

            $event = (array) $event;
            $url = trim((string) eventPosterImageUrl($event));
            if ($url === '') {
                continue;
            }

            $normalisedUrl = strtolower($url);
            if (isset($seenUrls[$normalisedUrl])) {
                continue;
            }
            $seenUrls[$normalisedUrl] = true;

            $items[] = [
                'url' => $url,
                'event_name' => trim((string) ($event['event_name'] ?? '')),
                'event_start_utc' => trim((string) ($event['event_start_utc'] ?? '')),
            ];
        }

        return $items;
    }

    private function weeklyPosterGallerySyncHash(string $channelId, array $items, array $embed): string
    {
        return $this->syncPayloadHash([
            'type' => 'weekly_poster_gallery_message_v1',
            'channel_id' => $channelId,
            'poster_items' => $items,
            'embeds' => [$embed],
        ]);
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

    private function syncWeeklySummaryDuringDiscordSync(?string $date, array $affectedWeeklyDates): array
    {
        $nowLocal = $date !== null && trim($date) !== ''
            ? new DateTimeImmutable(trim($date) . ' 00:00:00', clanTimezone())
            : new DateTimeImmutable('now', clanTimezone());

        $todayLocal = $nowLocal->format('Y-m-d');
        $results = [];
        $createdOrUpdatedCurrentWeek = false;

        if ($this->isMondayMidnightSyncWindow($nowLocal, $date)) {
            $results = array_merge($results, $this->postOrUpdateWeeklySummaryForWeek($todayLocal, true));
            $createdOrUpdatedCurrentWeek = true;
        }

        $refreshDates = array_values(array_filter(
            array_unique($affectedWeeklyDates),
            static fn (string $value): bool => trim($value) !== '' && (!$createdOrUpdatedCurrentWeek || $value !== $todayLocal)
        ));

        if ($refreshDates !== []) {
            $results = array_merge($results, $this->refreshWeeklySummariesForDates($refreshDates));
        }

        return $results;
    }

    private function isMondayMidnightSyncWindow(DateTimeImmutable $nowLocal, ?string $date): bool
    {
        if ($nowLocal->format('N') !== '1') {
            return false;
        }

        // A CLI date argument is treated as a manual/test run for that clan-local day.
        // Normal cron runs must occur during the first minute of Monday in the clan timezone.
        if ($date !== null && trim($date) !== '') {
            return true;
        }

        return $nowLocal->format('H:i') === '00:00';
    }

    public function syncPendingDiscordItemsForToday(?string $date = null): array
    {
        $range = dayRangeFromDate($date);
        $hostSync = $this->syncDiscordHostNamesForUpcomingEvents();
        $affectedWeeklyDates = array_values(array_unique(array_merge(
            [$range['day_start_local']->format('Y-m-d')],
            $hostSync['affected_dates']
        )));
        $events = $this->events->getForDay($range['day_start_utc'], $range['day_end_utc']);
        $results = array_merge(
            $hostSync['results'],
            $this->cleanupExpiredVoiceChannels(),
            $this->cleanupExpiredDailyPosts()
        );

        if ($events === []) {
            $results[] = [
                'scope' => 'day_of_events',
                'status' => 'skipped',
                'message' => 'No events found for ' . $range['day_start_local']->format('j M Y') . '.',
            ];
        } else {
            foreach ($events as $event) {
                $results[] = $this->syncSingleEvent($event, true);
            }
        }

        return array_merge($results, $this->syncWeeklySummaryDuringDiscordSync($date, $affectedWeeklyDates));
    }

    public function publishDayOfEvents(?string $date = null): array
    {
        $range = dayRangeFromDate($date);
        $hostSync = $this->syncDiscordHostNamesForUpcomingEvents();
        $events = $this->events->getForDay($range['day_start_utc'], $range['day_end_utc']);
        $results = array_merge(
            $hostSync['results'],
            $this->cleanupExpiredVoiceChannels(),
            $this->cleanupExpiredDailyPosts()
        );

        if ($events === []) {
            $results[] = [
                'scope' => 'day_of_events',
                'status' => 'skipped',
                'message' => 'No events found for ' . $range['day_start_local']->format('j M Y') . '.',
            ];
            return $results;
        }

        foreach ($events as $event) {
            $results[] = $this->syncSingleEvent($event, false);
        }

        return $results;
    }

    public function syncEventById(int $eventId, bool $createMissingDailyArtifacts = false): array
    {
        $event = $this->events->getById($eventId);
        if ($event === null) {
            return [[
                'scope' => 'day_of_events',
                'status' => 'skipped',
                'message' => 'Event not found for Discord sync.',
            ]];
        }

        // Manual event saves should refresh existing Discord artefacts only.
        // Missing daily posts/native Discord events are created by the daily cron,
        // otherwise saving a future event creates duplicate day-of content.
        return [$this->syncSingleEvent($event, $createMissingDailyArtifacts)];
    }


    public function cancelEventById(int $eventId): array
    {
        $event = $this->events->getById($eventId);
        if ($event === null) {
            return ['Event not found.'];
        }

        $this->events->updateStatus($eventId, 'cancelled');
        $cancelledEvent = $this->events->getById($eventId) ?? $event;

        $results = [];
        foreach ($this->syncEventById($eventId) as $result) {
            $message = trim((string) ($result['message'] ?? ''));
            if ($message !== '') {
                $results[] = $message;
            }
        }

        foreach ($this->refreshWeeklySummariesForDates([weekStartDateFromUtc((string) $cancelledEvent['event_start_utc'])]) as $result) {
            $message = trim((string) ($result['message'] ?? ''));
            if ($message !== '') {
                $results[] = $message;
            }
        }

        array_unshift($results, 'Event cancelled');

        return array_values(array_unique($results));
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

        $voiceChannelId = trim((string) ($event['discord_voice_channel_id'] ?? ''));
        if ($voiceChannelId !== '') {
            try {
                deleteDiscordChannel($voiceChannelId);
                $results[] = 'Deleted event voice channel';
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
                $results[] = 'Event voice channel was already missing';
            }
        }

        if (!empty($event['id'])) {
            $this->events->clearVoiceChannelTracking((int) $event['id']);
        }

        $scheduledEventId = trim((string) ($event['discord_scheduled_event_id'] ?? ''));
        if ($scheduledEventId !== '') {
            try {
                deleteScheduledEvent($scheduledEventId);
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

    private function syncDiscordHostNamesForUpcomingEvents(): array
    {
        $guildId = trim((string) appConfig()['discord']['guild_id']);
        if ($guildId === '') {
            return ['results' => [], 'affected_dates' => []];
        }

        $events = $this->events->getActiveEventsWithHostDiscordIds();
        if ($events === []) {
            return ['results' => [], 'affected_dates' => []];
        }

        $memberCache = [];
        $results = [];
        $affectedDates = [];

        foreach ($events as $event) {
            $eventId = (int) ($event['id'] ?? 0);
            $userId = trim((string) ($event['host_discord_user_id'] ?? ''));
            if ($eventId <= 0 || $userId === '') {
                continue;
            }

            if (!array_key_exists($userId, $memberCache)) {
                $memberCache[$userId] = fetchGuildMember($guildId, $userId);
            }

            $member = $memberCache[$userId];
            if (!is_array($member)) {
                continue;
            }

            $newHostName = trim((string) ($member['display_name'] ?? ''));
            $oldHostName = trim((string) ($event['host_name'] ?? ''));
            if ($newHostName === '' || $newHostName === $oldHostName) {
                continue;
            }

            $this->events->updateHostName($eventId, $newHostName);
            $affectedDates[] = utcToClanLocal((string) $event['event_start_utc'])->format('Y-m-d');
            $results[] = [
                'scope' => 'host_name_sync',
                'event_name' => (string) ($event['event_name'] ?? 'Event'),
                'status' => 'updated',
                'message' => 'Updated host name from ' . ($oldHostName !== '' ? $oldHostName : 'blank') . ' to ' . $newHostName . '.',
            ];
        }

        return [
            'results' => $results,
            'affected_dates' => array_values(array_unique($affectedDates)),
        ];
    }

    private function syncSingleEvent(array $event, bool $allowDailyCronCreation): array
    {
        $config = discordSettings();
        $eventResults = [];
        $scheduledEventUrl = '';
        $hasExistingScheduledEvent = trim((string) ($event['discord_scheduled_event_id'] ?? '')) !== '';
        $hasExistingDailyPost = trim((string) ($event['discord_daily_channel_id'] ?? '')) !== ''
            && trim((string) ($event['discord_daily_message_id'] ?? '')) !== '';

        $eventAllowsScheduledEvent = (int) ($event['create_discord_scheduled_event'] ?? 1) === 1;

        if ((bool) $config['enable_scheduled_events'] && $eventAllowsScheduledEvent) {
            if ($allowDailyCronCreation || $hasExistingScheduledEvent || $this->hasEventEnded($event) || (string) ($event['status'] ?? 'scheduled') === 'cancelled') {
                [$scheduledEventUrl, $scheduledMessage] = $this->syncScheduledEvent($event);
                $eventResults[] = $scheduledMessage;
                $event = $this->events->getById((int) $event['id']) ?? $event;
            } else {
                $eventResults[] = 'Skipped native Discord event: only created by daily cron';
            }
        } elseif ($hasExistingScheduledEvent) {
            $eventResults[] = $this->removeTrackedScheduledEvent(
                $event,
                $eventAllowsScheduledEvent ? 'global scheduled event creation is disabled' : 'disabled for this event'
            );
            $event = $this->events->getById((int) $event['id']) ?? $event;
        } else {
            $eventResults[] = $eventAllowsScheduledEvent
                ? 'Scheduled event creation disabled globally'
                : 'Native Discord event disabled for this event';
        }

        [$voiceMessage, $requiresScheduledResync] = $this->syncEventVoiceChannel($event);
        if ($voiceMessage !== '') {
            $eventResults[] = $voiceMessage;
        }
        if ($requiresScheduledResync && (bool) $config['enable_scheduled_events'] && $eventAllowsScheduledEvent) {
            $event = $this->events->getById((int) $event['id']) ?? $event;
            [$scheduledEventUrl, $scheduledMessage] = $this->syncScheduledEvent($event);
            $eventResults[] = $scheduledMessage;
            $event = $this->events->getById((int) $event['id']) ?? $event;
        }

        if ((bool) $config['enable_daily_event_posts']) {
            if ($allowDailyCronCreation || $hasExistingDailyPost || $this->hasEventEnded($event) || (string) ($event['status'] ?? 'scheduled') === 'cancelled') {
                $eventResults[] = $this->postOrUpdateDailyEventMessage($event, $allowDailyCronCreation ? 'sync' : 'publish', $scheduledEventUrl);
            } else {
                $eventResults[] = 'Skipped daily event embed: only created by daily cron';
            }
        } else {
            $eventResults[] = 'Daily event posting disabled';
        }

        return [
            'scope' => 'day_of_events',
            'event_name' => (string) $event['event_name'],
            'status' => 'processed',
            'message' => implode(' · ', array_values(array_filter($eventResults, static fn (string $value): bool => trim($value) !== ''))),
        ];
    }

    private function postOrUpdateWeeklySummaryForWeek(?string $date, bool $createIfMissing): array
    {
        $config = discordSettings();
        if (!(bool) $config['enable_weekly_summary']) {
            return [[
                'scope' => 'weekly_summary',
                'status' => 'skipped',
                'message' => 'Weekly summary posting is disabled in Settings.',
            ]];
        }

        $channelId = trim((string) $config['weekly_summary_channel_id']);
        if ($channelId === '') {
            throw new RuntimeException('Weekly summary channel is not configured in Settings or .env.');
        }

        $range = weekRangeFromDate($date);
        $events = $this->events->getForWeek($range['week_start_utc'], $range['week_end_utc']);
        $existing = $this->events->getWeeklyPost($range['week_start_utc']);
        $embed = buildWeeklySummaryEmbed($events, $range['week_start_local']);
        $results = [];

        if ($existing && !empty($existing['discord_message_id'])) {
            try {
                $summaryChannelId = (string) $existing['discord_channel_id'];
                $summaryHash = $this->weeklySummarySyncHash($summaryChannelId, $embed);
                $existingSummaryHash = trim((string) ($existing['discord_summary_sync_hash'] ?? ''));

                if ($existingSummaryHash === $summaryHash) {
                    $results[] = [
                        'scope' => 'weekly_summary',
                        'status' => 'skipped',
                        'message' => 'Skipped weekly summary edit: no changes detected.',
                    ];
                } else {
                    editDiscordMessage($summaryChannelId, (string) $existing['discord_message_id'], '', [$embed]);
                    $this->events->recordWeeklyPost($range['week_start_utc'], $summaryChannelId, (string) $existing['discord_message_id'], $summaryHash);
                    $existing = $this->events->getWeeklyPost($range['week_start_utc']) ?? $existing;

                    $results[] = [
                        'scope' => 'weekly_summary',
                        'status' => 'updated',
                        'message' => 'Updated existing weekly summary message.',
                    ];
                }

                $results[] = $this->syncWeeklyPosterGalleryMessage(
                    $range['week_start_utc'],
                    $summaryChannelId,
                    $events,
                    $range['week_start_local'],
                    $existing
                );

                return $results;
            } catch (Throwable $e) {
                if ($this->isDiscordOldMessageEditLimitError($e)) {
                    return [[
                        'scope' => 'weekly_summary',
                        'status' => 'skipped',
                        'message' => 'Skipped weekly summary edit because Discord has temporarily limited edits on this older message. It will be retried by the next sync.',
                    ]];
                }

                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }

                $this->deleteWeeklyGalleryIfTracked($existing);
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
        if ($messageId === '') {
            return [[
                'scope' => 'weekly_summary',
                'status' => 'failed',
                'message' => 'Weekly summary message was not posted.',
            ]];
        }

        $summaryHash = $this->weeklySummarySyncHash($channelId, $embed);
        $this->events->recordWeeklyPost($range['week_start_utc'], $channelId, $messageId, $summaryHash);
        $existing = $this->events->getWeeklyPost($range['week_start_utc']);

        $results[] = [
            'scope' => 'weekly_summary',
            'status' => 'posted',
            'message' => 'Posted weekly summary for week of ' . $range['week_start_local']->format('j M Y') . '.',
        ];

        $results[] = $this->syncWeeklyPosterGalleryMessage(
            $range['week_start_utc'],
            $channelId,
            $events,
            $range['week_start_local'],
            $existing
        );

        return $results;
    }

    private function syncWeeklyPosterGalleryMessage(string $weekStartUtc, string $channelId, array $events, DateTimeImmutable $weekStartLocal, ?array $weeklyPost): array
    {
        $files = [];
        $galleryItems = $this->weeklyPosterGalleryItems($events);
        $existingGalleryMessageId = trim((string) ($weeklyPost['discord_gallery_message_id'] ?? ''));

        try {
            if ($galleryItems === []) {
                if ($existingGalleryMessageId !== '') {
                    try {
                        deleteDiscordMessage($channelId, $existingGalleryMessageId);
                    } catch (Throwable $e) {
                        if (!$this->isUnknownDiscordResourceError($e)) {
                            throw $e;
                        }
                    }
                    $this->events->clearWeeklyGalleryPost($weekStartUtc);

                    return [
                        'scope' => 'weekly_poster_gallery',
                        'status' => 'removed',
                        'message' => 'Removed weekly poster gallery because no poster images are configured.',
                    ];
                }

                return [
                    'scope' => 'weekly_poster_gallery',
                    'status' => 'skipped',
                    'message' => 'Skipped weekly poster gallery because no poster images are configured.',
                ];
            }

            $embed = buildWeeklyPosterGalleryEmbed($weekStartLocal, count($galleryItems));
            $galleryHash = $this->weeklyPosterGallerySyncHash($channelId, $galleryItems, $embed);
            $existingGalleryHash = trim((string) ($weeklyPost['discord_gallery_sync_hash'] ?? ''));

            if ($existingGalleryMessageId !== '' && $existingGalleryHash === $galleryHash) {
                return [
                    'scope' => 'weekly_poster_gallery',
                    'status' => 'skipped',
                    'message' => 'Skipped weekly poster gallery edit: no changes detected.',
                ];
            }

            $files = $this->buildWeeklyPosterGalleryFiles($events);
            if ($files === []) {
                return [
                    'scope' => 'weekly_poster_gallery',
                    'status' => 'skipped',
                    'message' => 'Skipped weekly poster gallery because no poster images could be downloaded.',
                ];
            }

            $embed = buildWeeklyPosterGalleryEmbed($weekStartLocal, count($files));
            $galleryHash = $this->weeklyPosterGallerySyncHash($channelId, $galleryItems, $embed);

            if ($existingGalleryMessageId !== '') {
                try {
                    editDiscordMessageWithFiles($channelId, $existingGalleryMessageId, '', [$embed], $files);
                    $this->events->recordWeeklyGalleryPost($weekStartUtc, $existingGalleryMessageId, $galleryHash);

                    return [
                        'scope' => 'weekly_poster_gallery',
                        'status' => 'updated',
                        'message' => 'Updated weekly poster gallery with ' . count($files) . ' poster' . (count($files) === 1 ? '' : 's') . '.',
                    ];
                } catch (Throwable $e) {
                    if (!$this->isDiscordOldMessageEditLimitError($e) && !$this->isUnknownDiscordResourceError($e)) {
                        throw $e;
                    }

                    if (!$this->isUnknownDiscordResourceError($e)) {
                        try {
                            deleteDiscordMessage($channelId, $existingGalleryMessageId);
                        } catch (Throwable $deleteError) {
                            if (!$this->isUnknownDiscordResourceError($deleteError)) {
                                throw $deleteError;
                            }
                        }
                    }
                    $this->events->clearWeeklyGalleryPost($weekStartUtc);
                    $existingGalleryMessageId = '';
                }
            }

            $response = postDiscordMessageWithFiles($channelId, '', [$embed], $files);
            $messageId = (string) ($response['id'] ?? '');
            if ($messageId === '') {
                return [
                    'scope' => 'weekly_poster_gallery',
                    'status' => 'failed',
                    'message' => 'Weekly poster gallery was not posted.',
                ];
            }

            $this->events->recordWeeklyGalleryPost($weekStartUtc, $messageId, $galleryHash);

            return [
                'scope' => 'weekly_poster_gallery',
                'status' => 'posted',
                'message' => 'Posted weekly poster gallery with ' . count($files) . ' poster' . (count($files) === 1 ? '' : 's') . '.',
            ];
        } catch (Throwable $e) {
            return [
                'scope' => 'weekly_poster_gallery',
                'status' => 'failed',
                'message' => 'Failed to update weekly poster gallery: ' . $e->getMessage(),
            ];
        } finally {
            $this->cleanupDiscordUploadFiles($files);
        }
    }

    private function deleteWeeklyGalleryIfTracked(?array $weeklyPost): void
    {
        if (!$weeklyPost) {
            return;
        }

        $channelId = trim((string) ($weeklyPost['discord_channel_id'] ?? ''));
        $messageId = trim((string) ($weeklyPost['discord_gallery_message_id'] ?? ''));
        if ($channelId === '' || $messageId === '') {
            return;
        }

        try {
            deleteDiscordMessage($channelId, $messageId);
        } catch (Throwable $e) {
            if (!$this->isUnknownDiscordResourceError($e)) {
                throw $e;
            }
        }
    }

    private function buildWeeklyPosterGalleryFiles(array $events): array
    {
        $files = [];
        $seenUrls = [];

        foreach ($events as $event) {
            if (count($files) >= 10) {
                break;
            }

            $url = trim((string) eventPosterImageUrl((array) $event));
            if ($url === '') {
                continue;
            }

            $normalisedUrl = strtolower($url);
            if (isset($seenUrls[$normalisedUrl])) {
                continue;
            }
            $seenUrls[$normalisedUrl] = true;

            $file = $this->downloadPosterForDiscordGallery($url, (array) $event, count($files) + 1);
            if ($file !== null) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function downloadPosterForDiscordGallery(string $url, array $event, int $position): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rs3_discord_poster_');
        if ($tmp === false) {
            return null;
        }

        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            @unlink($tmp);
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($handle);
            @unlink($tmp);
            return null;
        }

        $maxBytes = 24 * 1024 * 1024;
        $options = [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_USERAGENT => 'ClanEventScheduler/1.7',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_MAXFILESIZE_LARGE')) {
            $options[CURLOPT_MAXFILESIZE_LARGE] = $maxBytes;
        }

        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = strtolower(trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
        curl_close($ch);
        fclose($handle);

        $size = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($ok !== true || $statusCode < 200 || $statusCode >= 300 || $size <= 0 || $size > $maxBytes) {
            @unlink($tmp);
            return null;
        }

        [$mime, $extension] = $this->resolveDiscordGalleryImageType($contentType, $url);
        if ($mime === '' || $extension === '') {
            @unlink($tmp);
            return null;
        }

        $eventName = trim((string) ($event['event_name'] ?? 'poster'));
        $datePart = '';
        if (!empty($event['event_start_utc'])) {
            try {
                $datePart = utcToClanLocal((string) $event['event_start_utc'])->format('Y-m-d');
            } catch (Throwable $e) {
                $datePart = '';
            }
        }

        $base = strtolower($eventName . ($datePart !== '' ? '-' . $datePart : '') . '-' . $position);
        $base = preg_replace('/[^a-z0-9._-]+/', '-', $base) ?: 'poster-' . $position;
        $base = trim($base, '-_.');
        if ($base === '') {
            $base = 'poster-' . $position;
        }

        return [
            'path' => $tmp,
            'filename' => mb_substr($base, 0, 80) . '.' . $extension,
            'content_type' => $mime,
            'description' => mb_substr($eventName, 0, 1024),
        ];
    }

    private function resolveDiscordGalleryImageType(string $contentType, string $url): array
    {
        $mime = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        $mimeToExtension = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        if (isset($mimeToExtension[$mime])) {
            return [$mime === 'image/jpg' ? 'image/jpeg' : $mime, $mimeToExtension[$mime]];
        }

        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        $extensionToMime = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        if (isset($extensionToMime[$extension])) {
            return [$extensionToMime[$extension], $extension === 'jpeg' ? 'jpg' : $extension];
        }

        return ['', ''];
    }

    private function cleanupDiscordUploadFiles(array $files): void
    {
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function removeTrackedScheduledEvent(array $event, string $reason): string
    {
        $scheduledEventId = trim((string) ($event['discord_scheduled_event_id'] ?? ''));
        if ($scheduledEventId === '') {
            return 'No native Discord event to remove';
        }

        try {
            deleteScheduledEvent($scheduledEventId);
        } catch (Throwable $e) {
            if (!$this->isUnknownDiscordResourceError($e)) {
                throw $e;
            }
        }

        $this->events->clearScheduledEventTracking((int) $event['id']);
        return 'Removed native Discord event: ' . $reason;
    }

    private function syncScheduledEvent(array $event): array
    {
        $scheduledEventId = trim((string) ($event['discord_scheduled_event_id'] ?? ''));
        $scheduledEventUrl = '';
        $eventStartUtc = new DateTimeImmutable((string) $event['event_start_utc'], utcTimezone());
        $durationMinutes = (int) ($event['duration_minutes'] ?? 0);
        if ($durationMinutes <= 0) {
            $durationMinutes = max(1, (int) discordSettings()['default_event_duration_minutes']);
        }
        $eventEndUtc = $eventStartUtc->modify('+' . $durationMinutes . ' minutes');
        $nowUtc = new DateTimeImmutable('now', utcTimezone());

        // Keep the native Discord scheduled event available while it is in progress.
        // Previously this used the start time, which removed the Discord event as soon as it began.
        $canExist = $eventEndUtc > $nowUtc && (string) ($event['status'] ?? 'scheduled') !== 'cancelled';
        $hasStarted = $eventStartUtc <= $nowUtc;

        if ($scheduledEventId !== '' && !$canExist) {
            try {
                deleteScheduledEvent($scheduledEventId);
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
            }
            $this->events->clearScheduledEventTracking((int) $event['id']);

            return ['', 'Removed native Discord event'];
        }

        if (!$canExist) {
            return ['', 'Skipped native Discord event: event has ended'];
        }

        $scheduledEventHash = $this->scheduledEventSyncHash($event);

        if ($scheduledEventId !== '') {
            if ($hasStarted) {
                // Once an event has started, Discord keeps it live until its scheduled end time.
                // Do not PATCH the schedule after start, as some schedule fields can no longer be changed.
                $scheduledEventUrl = buildDiscordScheduledEventUrl($scheduledEventId);
                return [$scheduledEventUrl, 'Kept native Discord event until event end time'];
            }

            $existingScheduledHash = trim((string) ($event['discord_scheduled_event_sync_hash'] ?? ''));
            if ($existingScheduledHash === $scheduledEventHash) {
                $scheduledEventUrl = buildDiscordScheduledEventUrl($scheduledEventId);
                return [$scheduledEventUrl, 'Skipped native Discord event update: no changes detected'];
            }

            try {
                editScheduledEvent($scheduledEventId, $event);
                $scheduledEventUrl = buildDiscordScheduledEventUrl($scheduledEventId);
                $this->events->markScheduledEvent((int) $event['id'], $scheduledEventId, $scheduledEventHash);
                return [$scheduledEventUrl, 'Updated native Discord event'];
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
                $this->events->clearScheduledEventTracking((int) $event['id']);
            }
        }

        if ($hasStarted) {
            return ['', 'Skipped native Discord event: event has already started'];
        }

        $scheduled = createScheduledEvent($event);
        $newScheduledEventId = (string) ($scheduled['id'] ?? '');
        if ($newScheduledEventId === '') {
            return ['', 'Native Discord event was not created'];
        }

        $this->events->markScheduledEvent((int) $event['id'], $newScheduledEventId, $scheduledEventHash);
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

            $message = $this->postOrUpdateDailyEventMessage($event, 'ended');
            $results[] = [
                'scope' => 'day_of_events',
                'event_name' => (string) ($event['event_name'] ?? 'Event'),
                'status' => 'updated',
                'message' => $message,
            ];
        }

        return $results;
    }

    private function cleanupExpiredVoiceChannels(): array
    {
        $results = [];
        $stmt = db()->prepare(
            'SELECT *
               FROM clan_events
              WHERE clan_id = :clan_id
                AND is_active = 1
                AND create_voice_chat_for_event = 1
                AND discord_voice_channel_id IS NOT NULL
                AND discord_voice_channel_id <> ""
                AND status <> "cancelled"'
        );
        $stmt->execute(['clan_id' => currentClanId()]);

        $rows = $stmt->fetchAll() ?: [];
        $deleteAfterMinutes = max(0, (int) discordSettings()['event_voice_delete_after_end_minutes']);
        $nowUtc = new DateTimeImmutable('now', utcTimezone());

        foreach ($rows as $event) {
            $eventName = (string) ($event['event_name'] ?? 'Event');
            $voiceChannelId = trim((string) ($event['discord_voice_channel_id'] ?? ''));
            if ($voiceChannelId === '') {
                continue;
            }

            $deleteAtUtc = $this->getEventVoiceDeleteAtUtc($event, $deleteAfterMinutes);
            $warningResult = $this->sendVoiceDeleteWarningMessageIfDue($event, $deleteAtUtc, $nowUtc);
            if ($warningResult !== null) {
                $results[] = $warningResult;
            }
            if ($deleteAtUtc > $nowUtc) {
                continue;
            }

            try {
                deleteDiscordChannel($voiceChannelId);
                $results[] = [
                    'scope' => 'voice_channel',
                    'event_name' => $eventName,
                    'status' => 'cleaned_up',
                    'message' => 'Deleted event voice channel after the post-event grace period.',
                ];
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
                $results[] = [
                    'scope' => 'voice_channel',
                    'event_name' => $eventName,
                    'status' => 'cleaned_up',
                    'message' => 'Event voice channel was already missing after the post-event grace period.',
                ];
            }

            $this->events->clearVoiceChannelTracking((int) $event['id']);
        }

        return $results;
    }

    private function syncEventVoiceChannel(array $event): array
    {
        $voiceEnabled = (int) ($event['create_voice_chat_for_event'] ?? 0) === 1;
        $voiceChannelId = trim((string) ($event['discord_voice_channel_id'] ?? ''));
        $scheduledEventId = trim((string) ($event['discord_scheduled_event_id'] ?? ''));

        if (!$voiceEnabled || (string) ($event['status'] ?? 'scheduled') === 'cancelled') {
            if ($voiceChannelId !== '') {
                try {
                    deleteDiscordChannel($voiceChannelId);
                } catch (Throwable $e) {
                    if (!$this->isUnknownDiscordResourceError($e)) {
                        throw $e;
                    }
                }
                $this->events->clearVoiceChannelTracking((int) $event['id']);
                return ['Removed event voice channel', true];
            }
            return ['', false];
        }

        if ($scheduledEventId === '') {
            return ['Skipped event voice channel: scheduled event not available yet', false];
        }

        if (!$this->shouldVoiceChannelExist($event)) {
            if ($voiceChannelId !== '' && $this->hasEventEnded($event)) {
                $deleteAfterMinutes = max(0, (int) discordSettings()['event_voice_delete_after_end_minutes']);
                return ['Kept event voice channel open until ' . $deleteAfterMinutes . ' minutes after the event ends', false];
            }
            return ['Waiting until voice channel window opens', false];
        }

        $subscriberIds = $this->getScheduledEventSubscriberIds($event);
        if ($voiceChannelId === '') {
            $channel = createGuildVoiceChannel(
                $this->buildVoiceChannelName($event),
                $this->buildBaseVoicePermissionOverwrites(),
                $this->resolveVoiceCategoryId($event)
            );
            $voiceChannelId = trim((string) ($channel['id'] ?? ''));
            if ($voiceChannelId === '') {
                return ['Event voice channel was not created', false];
            }
            $this->events->markVoiceChannel((int) $event['id'], $voiceChannelId);
            $this->syncVoiceChannelPermissions($voiceChannelId, $subscriberIds, (string) ($event['host_discord_user_id'] ?? ''));
            return ['Created event voice channel', true];
        }

        $this->syncVoiceChannelPermissions($voiceChannelId, $subscriberIds, (string) ($event['host_discord_user_id'] ?? ''));
        return ['Synced event voice channel permissions', false];
    }


private function sendVoiceDeleteWarningMessageIfDue(array $event, DateTimeImmutable $deleteAtUtc, DateTimeImmutable $nowUtc): ?array
{
    $warningBeforeMinutes = max(0, (int) discordSettings()['event_voice_warning_before_delete_minutes']);
    if ($warningBeforeMinutes <= 0) {
        return null;
    }

    $warningAtUtc = $deleteAtUtc->modify('-' . $warningBeforeMinutes . ' minutes');
    if ($nowUtc < $warningAtUtc || $nowUtc >= $deleteAtUtc) {
        return null;
    }

    if (!empty($event['discord_voice_warning_queued_at_utc'])) {
        return null;
    }

    $voiceChannelId = trim((string) ($event['discord_voice_channel_id'] ?? ''));
    if ($voiceChannelId === '') {
        return null;
    }

    $warningTimestamp = $deleteAtUtc->getTimestamp();
    $minuteLabel = $warningBeforeMinutes === 1 ? 'minute' : 'minutes';
    $message = sprintf('Chat will end in %d %s @ <t:%d:t>', $warningBeforeMinutes, $minuteLabel, $warningTimestamp);

    try {
        postDiscordMessage($voiceChannelId, $message);
    } catch (Throwable $e) {
        if ($this->isUnknownDiscordResourceError($e)) {
            return [
                'scope' => 'voice_channel_warning',
                'event_name' => (string) ($event['event_name'] ?? 'Event'),
                'status' => 'skipped',
                'message' => 'Skipped voice channel chat warning because the voice channel was already missing.',
            ];
        }

        return [
            'scope' => 'voice_channel_warning',
            'event_name' => (string) ($event['event_name'] ?? 'Event'),
            'status' => 'failed',
            'message' => 'Failed to post voice channel chat warning: ' . $e->getMessage(),
        ];
    }

    $this->events->markVoiceWarningSent((int) $event['id']);

    return [
        'scope' => 'voice_channel_warning',
        'event_name' => (string) ($event['event_name'] ?? 'Event'),
        'status' => 'posted',
        'message' => 'Posted voice channel chat warning for scheduled deletion.',
    ];
}

    private function shouldVoiceChannelExist(array $event): bool
    {
        $startUtc = new DateTimeImmutable((string) $event['event_start_utc'], utcTimezone());
        $leadMinutes = max(0, (int) discordSettings()['event_voice_create_before_minutes']);
        $createAtUtc = $startUtc->modify('-' . $leadMinutes . ' minutes');
        $nowUtc = new DateTimeImmutable('now', utcTimezone());

        return $nowUtc >= $createAtUtc;
    }

    private function buildVoiceChannelName(array $event): string
    {
        $base = trim((string) ($event['event_name'] ?? 'Event Voice'));
        if ($base === '') {
            $base = 'Event Voice';
        }
        return mb_substr($base, 0, 100);
    }

    private function buildBaseVoicePermissionOverwrites(): array
    {
        $guildId = trim((string) appConfig()['discord']['guild_id']);
        return [[
            'id' => $guildId,
            'type' => 0,
            'allow' => discordPermissionSum(['VIEW_CHANNEL', 'CONNECT']),
            'deny' => discordPermissionSum(['SPEAK']),
        ]];
    }

    private function resolveVoiceCategoryId(array $event): ?string
    {
        $configured = trim((string) discordSettings()['event_voice_category_id']);
        if ($configured !== '') {
            return $configured;
        }

        $dailyChannelId = $this->resolveDailyChannelId($event);
        if ($dailyChannelId === '') {
            return null;
        }

        try {
            $channel = fetchDiscordChannel($dailyChannelId);
        } catch (Throwable $e) {
            return null;
        }

        $parentId = trim((string) ($channel['parent_id'] ?? ''));
        return $parentId !== '' ? $parentId : null;
    }

    private function getScheduledEventSubscriberIds(array $event): array
    {
        $scheduledEventId = trim((string) ($event['discord_scheduled_event_id'] ?? ''));
        if ($scheduledEventId === '') {
            return [];
        }

        try {
            return fetchScheduledEventUsers($scheduledEventId);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function syncVoiceChannelPermissions(string $voiceChannelId, array $subscriberIds, ?string $hostDiscordUserId = null): void
    {
        $channel = fetchDiscordChannel($voiceChannelId);
        $overwrites = [];
        foreach ((array) ($channel['permission_overwrites'] ?? []) as $overwrite) {
            if (!is_array($overwrite) || empty($overwrite['id'])) {
                continue;
            }
            $overwrites[(string) $overwrite['id']] = $overwrite;
        }

        $guildId = trim((string) appConfig()['discord']['guild_id']);
        editDiscordChannelPermissions($voiceChannelId, $guildId, '0', ['VIEW_CHANNEL', 'CONNECT'], ['SPEAK']);

        $desiredUserIds = [];

        $hostDiscordUserId = trim((string) $hostDiscordUserId);
        if ($hostDiscordUserId !== '') {
            $desiredUserIds[$hostDiscordUserId] = true;
        }

        foreach ($subscriberIds as $userId) {
            $userId = trim((string) $userId);
            if ($userId === '') {
                continue;
            }
            $desiredUserIds[$userId] = true;
        }

        foreach (array_keys($desiredUserIds) as $userId) {
            editDiscordChannelPermissions($voiceChannelId, (string) $userId, '1', ['SPEAK'], []);
        }

        foreach ($overwrites as $overwriteId => $overwrite) {
            if ($overwriteId === $guildId) {
                continue;
            }
            if ((int) ($overwrite['type'] ?? -1) !== 1) {
                continue;
            }
            if (isset($desiredUserIds[$overwriteId])) {
                continue;
            }
            try {
                deleteDiscordChannelPermission($voiceChannelId, $overwriteId);
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
            }
        }
    }

    private function isAnyTrackedUserStillInVoiceChannel(string $voiceChannelId, array $userIds): bool
    {
        $guildId = trim((string) appConfig()['discord']['guild_id']);
        foreach ($userIds as $userId) {
            $state = getUserVoiceState($guildId, (string) $userId);
            if (!is_array($state)) {
                continue;
            }
            if ((string) ($state['channel_id'] ?? '') === $voiceChannelId) {
                return true;
            }
        }
        return false;
    }

    private function hasEventEnded(array $event): bool
    {
        $durationMinutes = (int) ($event['duration_minutes'] ?? 0);
        if ($durationMinutes <= 0) {
            $durationMinutes = max(1, (int) discordSettings()['default_event_duration_minutes']);
        }

        $endUtc = (new DateTimeImmutable((string) $event['event_start_utc'], utcTimezone()))
            ->modify('+' . $durationMinutes . ' minutes');

        return $endUtc <= new DateTimeImmutable('now', utcTimezone());
    }


    private function getEventVoiceDeleteAtUtc(array $event, int $deleteAfterMinutes): DateTimeImmutable
    {
        $durationMinutes = (int) ($event['duration_minutes'] ?? 0);
        if ($durationMinutes <= 0) {
            $durationMinutes = max(1, (int) discordSettings()['default_event_duration_minutes']);
        }

        return (new DateTimeImmutable((string) $event['event_start_utc'], utcTimezone()))
            ->modify('+' . $durationMinutes . ' minutes')
            ->modify('+' . max(0, $deleteAfterMinutes) . ' minutes');
    }

    private function resolveDailyChannelId(array $event): string
    {
        $channelId = trim((string) ($event['discord_channel_id'] ?? ''));
        if ($channelId === '') {
            $channelId = trim((string) (discordSettings()['daily_event_channel_id'] ?? ''));
        }
        if ($channelId === '') {
            $channelId = trim((string) (appConfig()['clan']['default_discord_channel_id'] ?? ''));
        }
        return $channelId;
    }

    private function buildDailyMessageContent(array $event, string $scheduledEventUrl): string
    {
        $lines = [];
        $mentionRoleId = trim((string) ($event['discord_mention_role_id'] ?? ''));
        if (preg_match('/^\d{15,32}$/', $mentionRoleId)) {
            $lines[] = '<@&' . $mentionRoleId . '>';
        }
        if ($scheduledEventUrl !== '') {
            $lines[] = 'Discord event: ' . $scheduledEventUrl;
        }
        return implode("\n", $lines);
    }

    private function dailyAllowedMentionRoleIds(array $event): array
    {
        $mentionRoleId = trim((string) ($event['discord_mention_role_id'] ?? ''));
        return preg_match('/^\d{15,32}$/', $mentionRoleId) ? [$mentionRoleId] : [];
    }

    private function syncPreferredRoleReactions(string $channelId, string $messageId, array $roles): void
    {
        $desiredByKey = [];
        foreach ($roles as $role) {
            $emoji = trim((string) ($role['reaction_emoji'] ?? ''));
            $emojiKey = encodeDiscordEmojiForUrl($emoji);
            if ($emoji === '' || $emojiKey === '' || isset($desiredByKey[$emojiKey])) {
                continue;
            }
            $desiredByKey[$emojiKey] = $emoji;
        }

        try {
            $message = fetchDiscordMessage($channelId, $messageId);
        } catch (Throwable $e) {
            if ($this->isUnknownDiscordResourceError($e)) {
                return;
            }
            throw $e;
        }

        $existingBotReactionKeys = [];
        foreach ((array) ($message['reactions'] ?? []) as $reaction) {
            if (!is_array($reaction) || empty($reaction['me'])) {
                continue;
            }

            $emojiData = (array) ($reaction['emoji'] ?? []);
            $emojiKey = '';

            if (!empty($emojiData['id'])) {
                $emojiName = trim((string) ($emojiData['name'] ?? ''));
                if ($emojiName !== '') {
                    $emojiKey = rawurlencode($emojiName . ':' . (string) $emojiData['id']);
                }
            } else {
                $emojiKey = encodeDiscordEmojiForUrl((string) ($emojiData['name'] ?? ''));
            }

            if ($emojiKey === '') {
                continue;
            }

            $existingBotReactionKeys[$emojiKey] = true;
        }

        foreach ($existingBotReactionKeys as $emojiKey => $_) {
            if (isset($desiredByKey[$emojiKey])) {
                continue;
            }

            try {
                removeDiscordOwnReaction($channelId, $messageId, rawurldecode($emojiKey));
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
            }
        }

        foreach ($desiredByKey as $emojiKey => $emoji) {
            if (isset($existingBotReactionKeys[$emojiKey])) {
                continue;
            }

            try {
                addDiscordReaction($channelId, $messageId, $emoji);
            } catch (Throwable $e) {
                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
            }
        }
    }

    private function postOrUpdateDailyEventMessage(array $event, string $mode, string $scheduledEventUrl = ''): string
    {
        $existingChannelId = trim((string) ($event['discord_daily_channel_id'] ?? ''));
        $existingMessageId = trim((string) ($event['discord_daily_message_id'] ?? ''));

        if ((string) ($event['status'] ?? 'scheduled') === 'cancelled') {
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
            return 'Removed cancelled daily event embed';
        }

        if ($this->hasEventEnded($event)) {
            if ($existingChannelId === '' || $existingMessageId === '') {
                return 'Skipped ended daily post update: no existing daily post found';
            }

            try {
                editDiscordMessage($existingChannelId, $existingMessageId, '', [buildEndedEventEmbed($event)], []);
                // Leave the ended post in Discord, but clear tracking so cron does not keep editing it forever.
                $this->events->clearDailyPostTracking((int) $event['id']);
                return 'Updated daily event embed to ended state';
            } catch (Throwable $e) {
                if ($this->isDiscordOldMessageEditLimitError($e)) {
                    // Discord limits repeated edits to messages older than one hour.
                    // Clear tracking so the cron does not keep retrying and fatal-looping.
                    $this->events->clearDailyPostTracking((int) $event['id']);
                    return 'Skipped ended daily post edit: Discord old-message edit limit reached';
                }

                if (!$this->isUnknownDiscordResourceError($e)) {
                    throw $e;
                }
                $this->events->clearDailyPostTracking((int) $event['id']);
                return 'Ended daily event post was already missing';
            }
        }

        $channelId = $this->resolveDailyChannelId($event);
        if ($channelId === '') {
            return 'Skipped daily post: no channel configured';
        }

        $content = $this->buildDailyMessageContent($event, $scheduledEventUrl);
        $allowedMentionRoleIds = $this->dailyAllowedMentionRoleIds($event);
        $embed = buildEventEmbed($event);
        $dailyHash = $this->dailyEventSyncHash($event, $channelId, $content, $embed, $allowedMentionRoleIds);

        if ($existingMessageId !== '' && $existingChannelId !== '' && $existingChannelId === $channelId) {
            $existingDailyHash = trim((string) ($event['discord_daily_sync_hash'] ?? ''));
            if ($existingDailyHash === $dailyHash) {
                return 'Skipped daily event embed update: no changes detected';
            }

            try {
                editDiscordMessage($existingChannelId, $existingMessageId, $content, [$embed], $allowedMentionRoleIds);
                $this->syncPreferredRoleReactions($existingChannelId, $existingMessageId, (array) ($event['preferred_roles'] ?? []));
                $this->events->markDailyPost((int) $event['id'], $existingChannelId, $existingMessageId, $dailyHash);
                return $mode === 'publish' ? 'Updated existing daily event embed' : 'Updated daily event embed';
            } catch (Throwable $e) {
                if ($this->isDiscordOldMessageEditLimitError($e)) {
                    return 'Skipped daily event edit: Discord old-message edit limit reached';
                }

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

        $response = postDiscordMessage($channelId, $content, [$embed], $allowedMentionRoleIds);
        $messageId = (string) ($response['id'] ?? '');
        if ($messageId === '') {
            return 'Daily event embed was not posted';
        }

        $this->events->markDailyPost((int) $event['id'], $channelId, $messageId, $dailyHash);
        $this->syncPreferredRoleReactions($channelId, $messageId, (array) ($event['preferred_roles'] ?? []));

        return 'Posted daily event embed';
    }

    private function isDiscordOldMessageEditLimitError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Maximum number of edits to messages older than 1 hour reached')
            || str_contains($message, '30046');
    }

    private function isUnknownDiscordResourceError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Unknown Message')
            || str_contains($message, 'Unknown Guild Scheduled Event')
            || str_contains($message, 'Unknown Channel')
            || str_contains($message, 'Unknown Overwrite')
            || str_contains($message, '10003')
            || str_contains($message, '10008')
            || str_contains($message, '10070');
    }
}
