<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

final class EventRepository
{
    public function getById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM clan_events WHERE id = :id AND clan_id = :clan_id LIMIT 1');
        $stmt->execute([
            'id' => $id,
            'clan_id' => currentClanId(),
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getForWeek(string $weekStartUtc, string $weekEndUtc): array
    {
        $stmt = db()->prepare(
            'SELECT * FROM clan_events
             WHERE clan_id = :clan_id
               AND is_active = 1
               AND event_start_utc BETWEEN :week_start_utc AND :week_end_utc
             ORDER BY event_start_utc ASC, id ASC'
        );

        $stmt->execute([
            'clan_id' => currentClanId(),
            'week_start_utc' => $weekStartUtc,
            'week_end_utc' => $weekEndUtc,
        ]);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = db()->prepare(
            'INSERT INTO clan_events (
                clan_id, event_name, event_description, host_name, event_start_utc,
                duration_minutes, image_url, discord_channel_id, is_active
            ) VALUES (
                :clan_id, :event_name, :event_description, :host_name, :event_start_utc,
                :duration_minutes, :image_url, :discord_channel_id, :is_active
            )'
        );

        $stmt->execute([
            'clan_id' => currentClanId(),
            'event_name' => $data['event_name'],
            'event_description' => $data['event_description'],
            'host_name' => $data['host_name'],
            'event_start_utc' => $data['event_start_utc'],
            'duration_minutes' => $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
            'image_url' => $data['image_url'],
            'discord_channel_id' => $data['discord_channel_id'],
            'is_active' => $data['is_active'],
        ]);

        return (int) db()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = db()->prepare(
            'UPDATE clan_events SET
                event_name = :event_name,
                event_description = :event_description,
                host_name = :host_name,
                event_start_utc = :event_start_utc,
                duration_minutes = :duration_minutes,
                image_url = :image_url,
                discord_channel_id = :discord_channel_id,
                is_active = :is_active
             WHERE id = :id AND clan_id = :clan_id'
        );

        $stmt->execute([
            'id' => $id,
            'clan_id' => currentClanId(),
            'event_name' => $data['event_name'],
            'event_description' => $data['event_description'],
            'host_name' => $data['host_name'],
            'event_start_utc' => $data['event_start_utc'],
            'duration_minutes' => $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
            'image_url' => $data['image_url'],
            'discord_channel_id' => $data['discord_channel_id'],
            'is_active' => $data['is_active'],
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = db()->prepare('DELETE FROM clan_events WHERE id = :id AND clan_id = :clan_id');
        $stmt->execute([
            'id' => $id,
            'clan_id' => currentClanId(),
        ]);
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
}
