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

        return $row;
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

        return $this->expandRecurringForWeek($rows, $weekStartUtc, $weekEndUtc);
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

        return $rows;
    }

    public function create(array $data): int
    {
        if ($this->hasRecurringSeriesColumn()) {
            $stmt = db()->prepare(
                'INSERT INTO clan_events (
                    clan_id, event_name, event_description, host_name, host_discord_user_id, event_start_utc,
                    duration_minutes, image_url, discord_channel_id, is_active, is_recurring_weekly, recurring_until_utc, recurring_series_id
                ) VALUES (
                    :clan_id, :event_name, :event_description, :host_name, :host_discord_user_id, :event_start_utc,
                    :duration_minutes, :image_url, :discord_channel_id, :is_active, :is_recurring_weekly, :recurring_until_utc, :recurring_series_id
                )'
            );

            $stmt->execute([
                'clan_id' => currentClanId(),
                'event_name' => $data['event_name'],
                'event_description' => $data['event_description'],
                'host_name' => $data['host_name'],
                'host_discord_user_id' => $data['host_discord_user_id'] ?: null,
                'event_start_utc' => $data['event_start_utc'],
                'duration_minutes' => $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
                'image_url' => $data['image_url'],
                'discord_channel_id' => $data['discord_channel_id'],
                'is_active' => $data['is_active'],
                'is_recurring_weekly' => $data['is_recurring_weekly'],
                'recurring_until_utc' => $data['recurring_until_utc'],
                'recurring_series_id' => $data['recurring_series_id'] ?: null,
            ]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO clan_events (
                    clan_id, event_name, event_description, host_name, host_discord_user_id, event_start_utc,
                    duration_minutes, image_url, discord_channel_id, is_active, is_recurring_weekly, recurring_until_utc
                ) VALUES (
                    :clan_id, :event_name, :event_description, :host_name, :host_discord_user_id, :event_start_utc,
                    :duration_minutes, :image_url, :discord_channel_id, :is_active, :is_recurring_weekly, :recurring_until_utc
                )'
            );

            $stmt->execute([
                'clan_id' => currentClanId(),
                'event_name' => $data['event_name'],
                'event_description' => $data['event_description'],
                'host_name' => $data['host_name'],
                'host_discord_user_id' => $data['host_discord_user_id'] ?: null,
                'event_start_utc' => $data['event_start_utc'],
                'duration_minutes' => $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
                'image_url' => $data['image_url'],
                'discord_channel_id' => $data['discord_channel_id'],
                'is_active' => $data['is_active'],
                'is_recurring_weekly' => $data['is_recurring_weekly'],
                'recurring_until_utc' => $data['recurring_until_utc'],
            ]);
        }

        return (int) db()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($this->hasRecurringSeriesColumn()) {
            $stmt = db()->prepare(
                'UPDATE clan_events SET
                    event_name = :event_name,
                    event_description = :event_description,
                    host_name = :host_name,
                    host_discord_user_id = :host_discord_user_id,
                    event_start_utc = :event_start_utc,
                    duration_minutes = :duration_minutes,
                    image_url = :image_url,
                    discord_channel_id = :discord_channel_id,
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
                'host_name' => $data['host_name'],
                'host_discord_user_id' => $data['host_discord_user_id'] ?: null,
                'event_start_utc' => $data['event_start_utc'],
                'duration_minutes' => $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
                'image_url' => $data['image_url'],
                'discord_channel_id' => $data['discord_channel_id'],
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
                    host_name = :host_name,
                    host_discord_user_id = :host_discord_user_id,
                    event_start_utc = :event_start_utc,
                    duration_minutes = :duration_minutes,
                    image_url = :image_url,
                    discord_channel_id = :discord_channel_id,
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
                'host_name' => $data['host_name'],
                'host_discord_user_id' => $data['host_discord_user_id'] ?: null,
                'event_start_utc' => $data['event_start_utc'],
                'duration_minutes' => $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
                'image_url' => $data['image_url'],
                'discord_channel_id' => $data['discord_channel_id'],
                'is_active' => $data['is_active'],
                'is_recurring_weekly' => $data['is_recurring_weekly'],
                'recurring_until_utc' => $data['recurring_until_utc'],
            ]);
        }
    }

    public function delete(int $id): void
    {
        $stmt = db()->prepare('DELETE FROM clan_events WHERE id = :id AND clan_id = :clan_id');
        $stmt->execute([
            'id' => $id,
            'clan_id' => currentClanId(),
        ]);
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

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function recordDiscordPost(int $eventId, string $channelId, string $messageId): void
    {
        $stmt = db()->prepare(
            'INSERT INTO discord_event_posts (clan_id, event_id, discord_channel_id, discord_message_id, posted_at_utc)
             VALUES (:clan_id, :event_id, :discord_channel_id, :discord_message_id, UTC_TIMESTAMP())'
        );

        $stmt->execute([
            'clan_id' => currentClanId(),
            'event_id' => $eventId,
            'discord_channel_id' => $channelId,
            'discord_message_id' => $messageId,
        ]);
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
