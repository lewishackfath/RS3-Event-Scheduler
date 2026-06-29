<?php
declare(strict_types=1);

require_once __DIR__ . '/../repositories/SettingsRepository.php';

/** @return array<string, mixed> */
function eventSchedulerSettings(): array
{
    static $settings = null;

    if (is_array($settings)) {
        return $settings;
    }

    try {
        $settings = (new SettingsRepository())->getValues();
    } catch (Throwable $e) {
        // Settings are convenience overrides. If the database is not reachable yet,
        // keep the app functional by falling back to .env-backed defaults.
        $repo = new SettingsRepository();
        $settings = [];
        foreach ($repo->definitions() as $key => $definition) {
            $settings[$key] = $definition['default'];
        }
    }

    return $settings;
}

/** @return array<string, mixed> */
function discordSettings(): array
{
    $config = appConfig()['discord'];
    $settings = eventSchedulerSettings();

    foreach ($settings as $key => $value) {
        $config[$key] = $value;
    }

    return $config;
}

/** @return array<string, mixed> */
function eventDefaultSettings(): array
{
    $settings = eventSchedulerSettings();

    return [
        'create_discord_scheduled_event' => !empty($settings['create_discord_scheduled_event_default']) ? 1 : 0,
        'create_voice_chat_for_event' => !empty($settings['create_voice_chat_for_event_default']) ? 1 : 0,
        'duration_minutes' => (int) ($settings['default_event_duration_minutes'] ?? 60),
        'event_location' => (string) ($settings['event_location_default'] ?? ''),
    ];
}

function resetEventSchedulerSettingsCache(): void
{
    // This function intentionally exists as a future extension point. The current
    // request lifecycle only reads settings once after saving redirects the user.
}
