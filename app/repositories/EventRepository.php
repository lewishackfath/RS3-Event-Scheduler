<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/time.php';

final class EventRepository
{
    private ?bool $hasRecurringSeriesId = null;

    public function supportsRecurringSeries(): bool
    {
        return $this->hasRecurringSeriesColumn();
    }

    public function getById(int $id): ?array
    {
        $sql = 'SELECT * FROM clan_events WHERE id = :id AND clan_id = :clan_id LIMIT 1';
        $stmt = db()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'clan_id' => currentClanId(),
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        if (!$this->hasRecurringSeriesColumn()) {
            $row['recurring_series_id'] = null;
        }
        if (!array_key_exists('status', $row)) {
            $row['status'] = 'scheduled';
        }

        return $this->attachRolesToRows([$row])[0] ?? null;
    }

    public function getForWeek(string $weekStartUtc, string $weekEndUtc): array
    {
        if ($this->hasRecurringSeriesColumn()) {
            $stmt = db()->prepare(
                'SELECT * FROM clan_events
                 WHERE clan_id = :clan_id
                   AND is_active = 1
                   AND (
                        (event_start_utc >= :week_start_utc_1 AND event_start_utc < :week_end_utc_1)
                        OR
                        (is_recurring_weekly = 1 AND recurring_series_id IS NULL AND event_start_utc < :week_end_utc_2 AND (recurring_until_utc IS NULL OR recurring_until_utc >= :week_start_utc_2))
                   )
                 ORDER BY event_start_utc ASC, id ASC'
            );

            $stmt->execute([
                'clan_id' => currentClanId(),
                'week_start_utc_1' => $weekStartUtc,
                'week_end_utc_1' => $weekEndUtc,
                'week_end_utc_2' => $weekEndUtc,
                'week_start_utc_2' => $weekStartUtc,
            ]);
        } else {
            $stmt = db()->prepare(
                'SELECT * FROM clan_events
                 WHERE clan_id = :clan_id
                   AND is_active = 1
                   AND (
                        (is_recurring_weekly = 0 AND event_start_utc >= :week_start_utc_1 AND event_start_utc < :week_end_utc_1)
                        OR
                        (is_recurring_weekly = 1 AND event_start_utc < :week_end_utc_2 AND (recurring_until_utc IS NULL OR recurring_until_utc >= :week_start_utc_2))
                   )
                 ORDER BY event_start_utc ASC, id ASC'
            );

            $stmt->execute([
                'clan_id' => currentClanId(),
                'week_start_utc_1' => $weekStartUtc,
                'week_end_utc_1' => $weekEndUtc,
                'week_end_utc_2' => $weekEndUtc,
                'week_start_utc_2' => $weekStartUtc,
            ]);
        }

        $rows = $stmt->fetchAll() ?: [];
        if (!$this->hasRecurringSeriesColumn()) {
            foreach ($rows as &$row) {
                $row['recurring_series_id'] = null;
            }
            unset($row);
        }
        foreach ($rows as &$row) {
            if (!array_key_exists('status', $row)) {
                $row['status'] = 'scheduled';
            }
        }
        unset($row);

        return $this->attachRolesToRows($this->expandRecurringForWeek($rows, $weekStartUtc, $weekEndUtc));
    }

    public function getForDay(string $dayStartUtc, string $dayEndUtc): array
    {
        $stmt = db()->prepare(
            'SELECT * FROM clan_events
             WHERE clan_id = :clan_id
               AND is_active = 1
               AND event_start_utc >= :day_start_utc
               AND event_start_utc < :day_end_utc
             ORDER BY event_start_utc ASC, id ASC'
        );

        $stmt->execute([
            'clan_id' => currentClanId(),
            'day_start_utc' => $dayStartUtc,
            'day_end_utc' => $dayEndUtc,
        ]);

        $rows = $stmt->fetchAll() ?: [];
        if (!$this->hasRecurringSeriesColumn()) {
            foreach ($rows as &$row) {
                $row['recurring_series_id'] = null;
            }
            unset($row);
        }
        foreach ($rows as &$row) {
            if (!array_key_exists('status', $row)) {
                $row['status'] = 'scheduled';
            }
        }
        unset($row);

        return $this->attachRolesToRows($rows);
    }

    public function create(array $data): int
    {
        if ($this->hasRecurringSeriesColumn()) {
            $stmt = db()->prepare(
                'INSERT INTO clan_events (
                    clan_id, event_name, event_description, event_location, host_name, host_discord_user_id, event_start_utc,
                    duration_minutes, image_url, discord_channel_id, create_voice_chat_for_event, is_active, is_recurring_weekly, recurring_until_utc, recurring_series_id
                ) VALUES (
                    :clan_id, :event_name, :event_description, :event_location, :host_name, :host_discord_user_id, :event_start_utc,
                    :duration_minutes, :image_url, :discord_channel_id, :create_voice_chat_for_event, :is_active, :is_recurring_weekly, :recurring_until_utc, :recurring_series_id
                )'
            );

            $stmt->execute([
                'clan_id' => currentClanId(),
                'event_name' => $data['event_name'],
                'event_description' => $data['event_description'],
                'event_location' => $data['event_location'] ?? null,
                'host_name' => $data['host_name'],
                'host_discord_user_id' => $data['host_discord_user_id'] ?: null,
                'event_start_utc' => $data['event_start_utc'],
                'duration_minutes' => $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
                'image_url' => $data['image_url'],
                'discord_channel_id' => $data['discord_channel_id'],
                'create_voice_chat_for_event' => $data['create_voice_chat_for_event'] ?? 0,
                'is_active' => $data['is_active'],
                'is_recurring_weekly' => $data['is_recurring_weekly'],
                'recurring_until_utc' => $data['recurring_until_utc'],
                'recurring_series_id' => $data['recurring_series_id'] ?: null,
            ]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO clan_events (
                    clan_id, event_name, event_description, event_location, host_name, host_discord_user_id, event_start_utc,
                    duration_minutes, image_url, discord_channel_id, create_voice_chat_for_event, is_active, is_recurring_weekly, recurring_until_utc
                ) VALUES (
                    :clan_id, :event_name, :event_description, :event_location, :host_name, :host_discord_user_id, :event_start_utc,
                    :duration_minutes, :image_url, :discord_channel_id, :create_voice_chat_for_event, :is_active, :is_recurring_weekly, :recurring_until_utc
                )'
            );

            $stmt->execute([
                'clan_id' => currentClanId(),
                'event_name' => $data['event_name'],
                'event_description' => $data['event_description'],
                'event_location' => $data['event_location'] ?? null,
                'host_name' => $data['host_name'],
                'host_discord_user_id' => $data['host_discord_user_id'] ?: null,
                'event_start_utc' => $data['event_start_utc'],
                'duration_minutes' => $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
                'image_url' => $data['image_url'],
                'discord_channel_id' => $data['discord_channel_id'],
                'create_voice_chat_for_event' => $data['create_voice_chat_for_event'] ?? 0,
                'is_active' => $data['is_active'],
                'is_recurring_weekly' => $data['is_recurring_weekly'],
                'recurring_until_utc' => $data['recurring_until_utc'],
            ]);
        }

        $eventId = (int) db()->lastInsertId();
        $this->saveEventRoles($eventId, $data['preferred_roles'] ?? []);

        return $eventId;
    }

    public function update(int $id, array $data): void
    {
        if ($this->hasRecurringSeriesColumn()) {
            $stmt = db()->prepare(
                'UPDATE clan_events SET
                    event_name = :event_name,
                    event_description = :event_description,
                    event_location = :event_location,
                    host_name = :host_name,
                    host_discord_user_id = :host_discord_user_id,
                    event_start_utc = :event_start_utc,
                    duration_minutes = :duration_minutes,
                    image_url = :image_url,
                    discord_channel_id = :discord_channel_id,
                    create_voice_chat_for_event = :create_voice_chat_for_event,
                    is_active = :is_active,
                    is_recurring_weekly = :is_recurring_weekly,
                    recurring_until_utc = :recurring_until_utc,
                    recurring_series_id = :recurring_series_id
                 WHERE id = :id AND clan_id = :clan_id'
            );

            $stmt->execute([
                'id' => $id,
                'clan_id' => currentClanId(),
                'event_name' => $data['event_name'],
                'event_description' => $data['event_description'],
                'event_location' => $data['event_location'] ?? null,
                'host_name' => $data['host_name'],
                'host_discord_user_id' => $data['host_discord_user_id'] ?: null,
                'event_start_utc' => $data['event_start_utc'],
                'duration_minutes' => $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
                'image_url' => $data['image_url'],
                'discord_channel_id' => $data['discord_channel_id'],
                'create_voice_chat_for_event' => $data['create_voice_chat_for_event'] ?? 0,
                'is_active' => $data['is_active'],
                'is_recurring_weekly' => $data['is_recurring_weekly'],
                'recurring_until_utc' => $data['recurring_until_utc'],
                'recurring_series_id' => $data['recurring_series_id'] ?: null,
            ]);
        } else {
            $stmt = db()->prepare(
                'UPDATE clan_events SET
                    event_name = :event_name,
                    event_description = :event_description,
                    event_location = :event_location,
                    host_name = :host_name,
                    host_discord_user_id = :host_discord_user_id,
                    event_start_utc = :event_start_utc,
                    duration_minutes = :duration_minutes,
                    image_url = :image_url,
                    discord_channel_id = :discord_channel_id,
                    create_voice_chat_for_event = :create_voice_chat_for_event,
                    is_active = :is_active,
                    is_recurring_weekly = :is_recurring_weekly,
                    recurring_until_utc = :recurring_until_utc
                 WHERE id = :id AND clan_id = :clan_id'
            );

            $stmt->execute([
                'id' => $id,
                'clan_id' => currentClanId(),
                'event_name' => $data['event_name'],
                'event_description' => $data['event_description'],
                'event_location' => $data['event_location'] ?? null,
                'host_name' => $data['host_name'],
                'host_discord_user_id' => $data['host_discord_user_id'] ?: null,
                'event_start_utc' => $data['event_start_utc'],
                'duration_minutes' => $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
                'image_url' => $data['image_url'],
                'discord_channel_id' => $data['discord_channel_id'],
                'create_voice_chat_for_event' => $data['create_voice_chat_for_event'] ?? 0,
                'is_active' => $data['is_active'],
                'is_recurring_weekly' => $data['is_recurring_weekly'],
                'recurring_until_utc' => $data['recurring_until_utc'],
            ]);
        }

        $this->saveEventRoles($id, $data['preferred_roles'] ?? []);
    }

    public function delete(int $id): void
    {
        $this->deleteEventRoles($id);
        $stmt = db()->prepare('DELETE FROM clan_events WHERE id = :id AND clan_id = :clan_id');
        $stmt->execute([
            'id' => $id,
            'clan_id' => currentClanId(),
        ]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $stmt = db()->prepare(
            'UPDATE clan_events
             SET status = :status
             WHERE id = :id AND clan_id = :clan_id'
        );
        $stmt->execute([
            'id' => $id,
            'clan_id' => currentClanId(),
            'status' => $status,
        ]);
    }

    public function clearDiscordTracking(int $id): void
    {
        $stmt = db()->prepare(
            'UPDATE clan_events
             SET discord_daily_channel_id = NULL,
                 discord_daily_message_id = NULL,
                 discord_daily_posted_at_utc = NULL,
                 discord_scheduled_event_id = NULL,
                 discord_scheduled_event_created_at_utc = NULL,
                 discord_voice_channel_id = NULL,
                 discord_voice_channel_created_at_utc = NULL
             WHERE id = :id AND clan_id = :clan_id'
        );
        $stmt->execute([
            'id' => $id,
            'clan_id' => currentClanId(),
        ]);
    }

    public function clearDailyPostTracking(int $id): void
    {
        $stmt = db()->prepare(
            'UPDATE clan_events
             SET discord_daily_channel_id = NULL,
                 discord_daily_message_id = NULL,
                 discord_daily_posted_at_utc = NULL
             WHERE id = :id AND clan_id = :clan_id'
        );
        $stmt->execute([
            'id' => $id,
            'clan_id' => currentClanId(),
        ]);
    }

    public function clearScheduledEventTracking(int $id): void
    {
        $stmt = db()->prepare(
            'UPDATE clan_events
             SET discord_scheduled_event_id = NULL,
                 discord_scheduled_event_created_at_utc = NULL
             WHERE id = :id AND clan_id = :clan_id'
        );
        $stmt->execute([
            'id' => $id,
            'clan_id' => currentClanId(),
        ]);
    }

    public function getEventsWithDailyPosts(): array
    {
        $stmt = db()->prepare(
            'SELECT * FROM clan_events
             WHERE clan_id = :clan_id
               AND discord_daily_channel_id IS NOT NULL
               AND discord_daily_channel_id <> ""
               AND discord_daily_message_id IS NOT NULL
               AND discord_daily_message_id <> ""
             ORDER BY event_start_utc ASC, id ASC'
        );
        $stmt->execute([
            'clan_id' => currentClanId(),
        ]);

        $rows = $stmt->fetchAll() ?: [];
        if (!$this->hasRecurringSeriesColumn()) {
            foreach ($rows as &$row) {
                $row['recurring_series_id'] = null;
            }
            unset($row);
        }
        foreach ($rows as &$row) {
            if (!array_key_exists('status', $row)) {
                $row['status'] = 'scheduled';
            }
        }
        unset($row);

        return $this->attachRolesToRows($rows);
    }


    public function getSeriesEvents(string $seriesId, ?string $fromUtc = null): array
    {
        if (!$this->hasRecurringSeriesColumn()) {
            return [];
        }

        $sql = 'SELECT * FROM clan_events WHERE clan_id = :clan_id AND recurring_series_id = :series_id';
        $params = [
            'clan_id' => currentClanId(),
            'series_id' => $seriesId,
        ];

        if ($fromUtc !== null) {
            $sql .= ' AND event_start_utc >= :from_utc';
            $params['from_utc'] = $fromUtc;
        }

        $sql .= ' ORDER BY event_start_utc ASC, id ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function deleteSeriesEvents(string $seriesId, ?string $fromUtc = null): int
    {
        if (!$this->hasRecurringSeriesColumn()) {
            return 0;
        }

        $sql = 'DELETE FROM clan_events WHERE clan_id = :clan_id AND recurring_series_id = :series_id';
        $params = [
            'clan_id' => currentClanId(),
            'series_id' => $seriesId,
        ];

        if ($fromUtc !== null) {
            $sql .= ' AND event_start_utc >= :from_utc';
            $params['from_utc'] = $fromUtc;
        }

        $selectSql = str_replace('DELETE FROM clan_events', 'SELECT id FROM clan_events', $sql);
        $selectStmt = db()->prepare($selectSql);
        $selectStmt->execute($params);
        $eventIds = array_map('intval', array_column($selectStmt->fetchAll() ?: [], 'id'));

        foreach ($eventIds as $eventId) {
            $this->deleteEventRoles($eventId);
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function getEventRoles(int $eventId): array
    {
        $stmt = db()->prepare(
            'SELECT role_name, reaction_emoji, sort_order
             FROM clan_event_roles
             WHERE event_id = :event_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['event_id' => $eventId]);

        return array_map(static function (array $row): array {
            return [
                'role_name' => trim((string) ($row['role_name'] ?? '')),
                'reaction_emoji' => trim((string) ($row['reaction_emoji'] ?? '')),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }, $stmt->fetchAll() ?: []);
    }

    public function saveEventRoles(int $eventId, array $roles): void
    {
        $this->deleteEventRoles($eventId);

        if ($roles === []) {
            return;
        }

        $stmt = db()->prepare(
            'INSERT INTO clan_event_roles (event_id, role_name, reaction_emoji, sort_order)
             VALUES (:event_id, :role_name, :reaction_emoji, :sort_order)'
        );

        foreach (array_values($roles) as $index => $role) {
            $roleName = trim((string) ($role['role_name'] ?? ''));
            $emoji = trim((string) ($role['reaction_emoji'] ?? ''));
            if ($roleName === '' || $emoji === '') {
                continue;
            }

            $stmt->execute([
                'event_id' => $eventId,
                'role_name' => $roleName,
                'reaction_emoji' => $emoji,
                'sort_order' => $index + 1,
            ]);
        }
    }

    public function deleteEventRoles(int $eventId): void
    {
        $stmt = db()->prepare('DELETE FROM clan_event_roles WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $eventId]);
    }

    public function recordDiscordPost(int $eventId, string $channelId, string $messageId): void
    {
        // Legacy table removed. No-op retained for backwards compatibility.
    }

    public function markDailyPost(int $eventId, string $channelId, string $messageId): void
    {
        $stmt = db()->prepare(
            'UPDATE clan_events
             SET discord_daily_channel_id = :channel_id,
                 discord_daily_message_id = :message_id,
                 discord_daily_posted_at_utc = UTC_TIMESTAMP()
             WHERE id = :id AND clan_id = :clan_id'
        );
        $stmt->execute([
            'id' => $eventId,
            'clan_id' => currentClanId(),
            'channel_id' => $channelId,
            'message_id' => $messageId,
        ]);
    }

    public function markScheduledEvent(int $eventId, string $scheduledEventId): void
    {
        $stmt = db()->prepare(
            'UPDATE clan_events
             SET discord_scheduled_event_id = :scheduled_event_id,
                 discord_scheduled_event_created_at_utc = UTC_TIMESTAMP()
             WHERE id = :id AND clan_id = :clan_id'
        );
        $stmt->execute([
            'id' => $eventId,
            'clan_id' => currentClanId(),
            'scheduled_event_id' => $scheduledEventId,
        ]);
    }

    public function markVoiceChannel(int $eventId, string $channelId): void
    {
        $stmt = db()->prepare(
            'UPDATE clan_events
             SET discord_voice_channel_id = :channel_id,
                 discord_voice_channel_created_at_utc = UTC_TIMESTAMP()
             WHERE id = :id AND clan_id = :clan_id'
        );
        $stmt->execute([
            'id' => $eventId,
            'clan_id' => currentClanId(),
            'channel_id' => $channelId,
        ]);
    }

    public function clearVoiceChannelTracking(int $eventId): void
    {
        $stmt = db()->prepare(
            'UPDATE clan_events
             SET discord_voice_channel_id = NULL,
                 discord_voice_channel_created_at_utc = NULL
             WHERE id = :id AND clan_id = :clan_id'
        );
        $stmt->execute([
            'id' => $eventId,
            'clan_id' => currentClanId(),
        ]);
    }

    public function getWeeklyPost(string $weekStartUtc): ?array
    {
        $stmt = db()->prepare(
            'SELECT * FROM discord_weekly_posts
             WHERE clan_id = :clan_id AND week_start_utc = :week_start_utc
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'clan_id' => currentClanId(),
            'week_start_utc' => $weekStartUtc,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function recordWeeklyPost(string $weekStartUtc, string $channelId, string $messageId): void
    {
        $existing = $this->getWeeklyPost($weekStartUtc);
        if ($existing) {
            $stmt = db()->prepare(
                'UPDATE discord_weekly_posts
                 SET discord_channel_id = :channel_id,
                     discord_message_id = :message_id,
                     updated_at_utc = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => (int) $existing['id'],
                'channel_id' => $channelId,
                'message_id' => $messageId,
            ]);
            return;
        }

        $stmt = db()->prepare(
            'INSERT INTO discord_weekly_posts (clan_id, week_start_utc, discord_channel_id, discord_message_id, posted_at_utc)
             VALUES (:clan_id, :week_start_utc, :channel_id, :message_id, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'clan_id' => currentClanId(),
            'week_start_utc' => $weekStartUtc,
            'channel_id' => $channelId,
            'message_id' => $messageId,
        ]);
    }

    public function deleteWeeklyPost(string $weekStartUtc): void
    {
        $stmt = db()->prepare(
            'DELETE FROM discord_weekly_posts
             WHERE clan_id = :clan_id AND week_start_utc = :week_start_utc'
        );
        $stmt->execute([
            'clan_id' => currentClanId(),
            'week_start_utc' => $weekStartUtc,
        ]);
    }


    private function attachRolesToRows(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $eventIds = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows)));
        $eventIds = array_values(array_filter($eventIds, static fn (int $id): bool => $id > 0));
        if ($eventIds === []) {
            foreach ($rows as &$row) {
                $row['preferred_roles'] = [];
            }
            unset($row);
            return $rows;
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = db()->prepare('SELECT event_id, role_name, reaction_emoji, sort_order FROM clan_event_roles WHERE event_id IN (' . $placeholders . ') ORDER BY event_id ASC, sort_order ASC, id ASC');
        $stmt->execute($eventIds);

        $rolesByEventId = [];
        foreach (($stmt->fetchAll() ?: []) as $roleRow) {
            $eventId = (int) ($roleRow['event_id'] ?? 0);
            if ($eventId <= 0) {
                continue;
            }
            $rolesByEventId[$eventId][] = [
                'role_name' => trim((string) ($roleRow['role_name'] ?? '')),
                'reaction_emoji' => trim((string) ($roleRow['reaction_emoji'] ?? '')),
                'sort_order' => (int) ($roleRow['sort_order'] ?? 0),
            ];
        }

        foreach ($rows as &$row) {
            $row['preferred_roles'] = $rolesByEventId[(int) ($row['id'] ?? 0)] ?? [];
        }
        unset($row);

        return $rows;
    }

    private function hasRecurringSeriesColumn(): bool
    {
        if ($this->hasRecurringSeriesId !== null) {
            return $this->hasRecurringSeriesId;
        }

        $stmt = db()->prepare("SHOW COLUMNS FROM clan_events LIKE 'recurring_series_id'");
        $stmt->execute();
        $this->hasRecurringSeriesId = (bool) $stmt->fetch();

        return $this->hasRecurringSeriesId;
    }

    private function expandRecurringForWeek(array $rows, string $weekStartUtc, string $weekEndUtc): array
    {
        $weekStartLocal = utcToClanLocal($weekStartUtc);
        $expanded = [];

        foreach ($rows as $row) {
            $isRecurring = (int) ($row['is_recurring_weekly'] ?? 0) === 1;
            $seriesId = trim((string) ($row['recurring_series_id'] ?? ''));

            if (!$isRecurring || $seriesId !== '') {
                if ((string) $row['event_start_utc'] >= $weekStartUtc && (string) $row['event_start_utc'] < $weekEndUtc) {
                    $expanded[] = $row;
                }
                continue;
            }

            $anchorLocal = utcToClanLocal((string) $row['event_start_utc']);
            $occurrenceLocal = $weekStartLocal
                ->modify('+' . ($anchorLocal->format('N') - 1) . ' days')
                ->setTime((int) $anchorLocal->format('H'), (int) $anchorLocal->format('i'), 0);
            $occurrenceUtc = $occurrenceLocal->setTimezone(utcTimezone())->format('Y-m-d H:i:s');

            if ($occurrenceUtc < $weekStartUtc || $occurrenceUtc >= $weekEndUtc) {
                continue;
            }
            if ($occurrenceUtc < (string) $row['event_start_utc']) {
                continue;
            }
            if (!empty($row['recurring_until_utc']) && $occurrenceUtc > (string) $row['recurring_until_utc']) {
                continue;
            }

            $row['event_start_utc'] = $occurrenceUtc;
            $row['recurring_series_id'] = null;
            $expanded[] = $row;
        }

        usort($expanded, static function (array $a, array $b): int {
            $cmp = strcmp((string) $a['event_start_utc'], (string) $b['event_start_utc']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        return $expanded;
    }
}
