<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

loadEnv(dirname(__DIR__, 2) . '/.env');

define('BASE_PATH', dirname(__DIR__, 2));
define('PUBLIC_PATH', BASE_PATH . '/public');

function appConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $adminRoleIds = array_values(array_filter(array_map(
        static fn ($value) => trim((string) $value),
        explode(',', (string) env('ADMIN_ROLE_IDS', ''))
    ), static fn ($value) => $value !== ''));

    $adminUserIds = array_values(array_filter(array_map(
        static fn ($value) => trim((string) $value),
        explode(',', (string) env('ADMIN_USER_IDS', ''))
    ), static fn ($value) => $value !== ''));

    $config = [
        'app' => [
            'name' => (string) env('APP_NAME', 'Clan Event Scheduler'),
            'url' => (string) env('APP_URL', ''),
            'env' => (string) env('APP_ENV', 'production'),
            'debug' => (bool) env('APP_DEBUG', false),
        ],
        'db' => [
            'host' => (string) env('DB_HOST', '127.0.0.1'),
            'port' => (string) env('DB_PORT', '3306'),
            'database' => (string) env('DB_DATABASE', ''),
            'username' => (string) env('DB_USERNAME', ''),
            'password' => (string) env('DB_PASSWORD', ''),
            'charset' => (string) env('DB_CHARSET', 'utf8mb4'),
        ],
        'clan' => [
            'id' => (int) env('CLAN_ID', 0),
            'name' => (string) env('CLAN_NAME', 'Clan'),
            'timezone' => (string) env('DEFAULT_TIMEZONE', 'UTC'),
            'default_discord_channel_id' => (string) env('DEFAULT_DISCORD_CHANNEL_ID', ''),
        ],
        'branding' => [
            'logo_url' => (string) env('BRAND_LOGO_URL', ''),
            'header_image_url' => (string) env('BRAND_HEADER_IMAGE_URL', ''),
            'embed_colour' => (string) env('BRAND_EMBED_COLOUR', '#5865F2'),
            'footer_text' => (string) env('BRAND_FOOTER_TEXT', ''),
            'background_image_url' => (string) env('BRAND_BACKGROUND_IMAGE_URL', ''),
        ],
        'discord' => [
            'bot_token' => (string) env('DISCORD_BOT_TOKEN', ''),
            'guild_id' => (string) env('DISCORD_GUILD_ID', ''),
            'weekly_summary_channel_id' => (string) env('DISCORD_WEEKLY_SUMMARY_CHANNEL_ID', ''),
            'daily_event_channel_id' => (string) env('DISCORD_DAILY_EVENT_CHANNEL_ID', ''),
            'enable_weekly_summary' => (bool) env('DISCORD_ENABLE_WEEKLY_SUMMARY', true),
            'enable_daily_event_posts' => (bool) env('DISCORD_ENABLE_DAILY_EVENT_POSTS', true),
            'enable_scheduled_events' => (bool) env('DISCORD_ENABLE_SCHEDULED_EVENTS', true),
            'event_location_default' => (string) env('DISCORD_EVENT_LOCATION_DEFAULT', 'RuneScape - In Game'),
            'default_event_duration_minutes' => (int) env('DISCORD_DEFAULT_EVENT_DURATION_MINUTES', 60),
            'event_voice_category_id' => (string) env('DISCORD_EVENT_VOICE_CATEGORY_ID', ''),
            'event_voice_create_before_minutes' => (int) env('DISCORD_EVENT_VOICE_CREATE_BEFORE_MINUTES', 30),
            'event_voice_delete_after_end_minutes' => (int) env('DISCORD_EVENT_VOICE_DELETE_AFTER_END_MINUTES', 60),
        ],
        'discord_oauth' => [
            'client_id' => (string) env('DISCORD_CLIENT_ID', ''),
            'client_secret' => (string) env('DISCORD_CLIENT_SECRET', ''),
            'redirect_uri' => (string) env('DISCORD_REDIRECT_URI', ''),
            'scope' => (string) env('DISCORD_OAUTH_SCOPE', 'identify guilds.members.read'),
            'guild_id' => (string) env('DISCORD_GUILD_ID', ''),
            'admin_role_ids' => $adminRoleIds,
            'admin_user_ids' => $adminUserIds,
        ],
    ];

    return $config;
}
