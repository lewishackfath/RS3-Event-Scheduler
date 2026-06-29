<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

final class SettingsRepository
{
    /** @return array<string, array{label:string,type:string,section:string,default:mixed,min?:int,max?:int}> */
    public function definitions(): array
    {
        $discord = appConfig()['discord'];

        return [
            'enable_weekly_summary' => [
                'label' => 'Post weekly summary',
                'type' => 'bool',
                'section' => 'Discord Publishing',
                'default' => (bool) ($discord['enable_weekly_summary'] ?? true),
            ],
            'weekly_summary_channel_id' => [
                'label' => 'Weekly summary channel',
                'type' => 'channel',
                'section' => 'Discord Publishing',
                'default' => (string) ($discord['weekly_summary_channel_id'] ?? ''),
            ],
            'enable_daily_event_posts' => [
                'label' => 'Post daily event embeds',
                'type' => 'bool',
                'section' => 'Discord Publishing',
                'default' => (bool) ($discord['enable_daily_event_posts'] ?? true),
            ],
            'daily_event_channel_id' => [
                'label' => 'Default daily event channel',
                'type' => 'channel',
                'section' => 'Discord Publishing',
                'default' => (string) ($discord['daily_event_channel_id'] ?? ''),
            ],
            'enable_scheduled_events' => [
                'label' => 'Allow native Discord scheduled events globally',
                'type' => 'bool',
                'section' => 'Discord Publishing',
                'default' => (bool) ($discord['enable_scheduled_events'] ?? true),
            ],
            'create_discord_scheduled_event_default' => [
                'label' => 'Create native Discord scheduled event by default',
                'type' => 'bool',
                'section' => 'Event Defaults',
                'default' => true,
            ],
            'event_location_default' => [
                'label' => 'Default Discord event location',
                'type' => 'text',
                'section' => 'Event Defaults',
                'default' => (string) ($discord['event_location_default'] ?? 'RuneScape - In Game'),
            ],
            'default_event_duration_minutes' => [
                'label' => 'Default event duration in minutes',
                'type' => 'int',
                'section' => 'Event Defaults',
                'default' => max(1, (int) ($discord['default_event_duration_minutes'] ?? 60)),
                'min' => 1,
                'max' => 1440,
            ],
            'create_voice_chat_for_event_default' => [
                'label' => 'Create temporary voice chat by default',
                'type' => 'bool',
                'section' => 'Voice Channels',
                'default' => false,
            ],
            'event_voice_category_id' => [
                'label' => 'Voice channel category',
                'type' => 'channel',
                'section' => 'Voice Channels',
                'default' => (string) ($discord['event_voice_category_id'] ?? ''),
            ],
            'event_voice_create_before_minutes' => [
                'label' => 'Create voice channel before event starts',
                'type' => 'int',
                'section' => 'Voice Channels',
                'default' => max(0, (int) ($discord['event_voice_create_before_minutes'] ?? 30)),
                'min' => 0,
                'max' => 1440,
            ],
            'event_voice_delete_after_end_minutes' => [
                'label' => 'Delete voice channel after event ends',
                'type' => 'int',
                'section' => 'Voice Channels',
                'default' => max(0, (int) ($discord['event_voice_delete_after_end_minutes'] ?? 60)),
                'min' => 0,
                'max' => 1440,
            ],
            'event_voice_warning_before_delete_minutes' => [
                'label' => 'Voice channel deletion warning time',
                'type' => 'int',
                'section' => 'Voice Channels',
                'default' => max(0, (int) ($discord['event_voice_warning_before_delete_minutes'] ?? 15)),
                'min' => 0,
                'max' => 1440,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function getValues(): array
    {
        $definitions = $this->definitions();
        $values = [];

        foreach ($definitions as $key => $definition) {
            $values[$key] = $definition['default'];
        }

        $stmt = db()->prepare(
            'SELECT setting_key, setting_value
               FROM clan_event_settings
              WHERE clan_id = :clan_id'
        );
        $stmt->execute(['clan_id' => currentClanId()]);

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (!isset($definitions[$key])) {
                continue;
            }
            $values[$key] = $this->castValue((string) ($row['setting_value'] ?? ''), $definitions[$key]);
        }

        return $values;
    }

    /** @param array<string, mixed> $input */
    public function saveValues(array $input, ?string $updatedByDiscordUserId = null): void
    {
        $definitions = $this->definitions();
        $normalised = [];

        foreach ($definitions as $key => $definition) {
            $type = (string) $definition['type'];
            if ($type === 'bool') {
                $normalised[$key] = isset($input[$key]) ? '1' : '0';
                continue;
            }

            $value = trim((string) ($input[$key] ?? ''));
            if ($type === 'int') {
                $intValue = $value === '' ? (int) $definition['default'] : (int) $value;
                if (isset($definition['min'])) {
                    $intValue = max((int) $definition['min'], $intValue);
                }
                if (isset($definition['max'])) {
                    $intValue = min((int) $definition['max'], $intValue);
                }
                $normalised[$key] = (string) $intValue;
                continue;
            }

            if ($type === 'channel' && $value !== '' && !preg_match('/^\d{15,32}$/', $value)) {
                throw new InvalidArgumentException($definition['label'] . ' must be a valid Discord channel ID or left blank.');
            }

            $normalised[$key] = mb_substr($value, 0, 1000);
        }

        $stmt = db()->prepare(
            'INSERT INTO clan_event_settings (clan_id, setting_key, setting_value, updated_by_discord_user_id, created_at_utc, updated_at_utc)
             VALUES (:clan_id, :setting_key, :setting_value, :updated_by_discord_user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                 setting_value = VALUES(setting_value),
                 updated_by_discord_user_id = VALUES(updated_by_discord_user_id),
                 updated_at_utc = UTC_TIMESTAMP()'
        );

        foreach ($normalised as $key => $value) {
            $stmt->execute([
                'clan_id' => currentClanId(),
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_by_discord_user_id' => $updatedByDiscordUserId,
            ]);
        }
    }

    /** @param array{type:string,default:mixed,min?:int,max?:int} $definition */
    private function castValue(string $value, array $definition): mixed
    {
        $type = (string) $definition['type'];

        if ($type === 'bool') {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        if ($type === 'int') {
            $intValue = (int) $value;
            if (isset($definition['min'])) {
                $intValue = max((int) $definition['min'], $intValue);
            }
            if (isset($definition['max'])) {
                $intValue = min((int) $definition['max'], $intValue);
            }
            return $intValue;
        }

        return $value;
    }
}
