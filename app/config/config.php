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
        ],
    ];

    return $config;
}
