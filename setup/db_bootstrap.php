<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/db.php';

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name');
    $stmt->execute([
        'table_name' => $table,
        'index_name' => $index,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function safeDropLegacyTable(PDO $pdo, string $table): void
{
    if (tableExists($pdo, $table)) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
    }
}

function runDatabaseBootstrap(bool $verbose = false): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $pdo = db();
    $pdo->exec('SET NAMES utf8mb4');

    $log = static function (string $message) use ($verbose): void {
        if ($verbose) {
            echo $message . "\n";
        }
    };

    $log('Running database bootstrap...');

    if (!tableExists($pdo, 'clans')) {
        $pdo->exec(
            'CREATE TABLE clans (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                timezone VARCHAR(100) NOT NULL DEFAULT "UTC",
                created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log('Created clans table.');
    } else {
        if (!columnExists($pdo, 'clans', 'timezone')) {
            $pdo->exec('ALTER TABLE clans ADD COLUMN timezone VARCHAR(100) NOT NULL DEFAULT "UTC" AFTER name');
            $log('Added clans.timezone column.');
        }
        if (!columnExists($pdo, 'clans', 'created_at_utc')) {
            $pdo->exec('ALTER TABLE clans ADD COLUMN created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
            $log('Added clans.created_at_utc column.');
        }
        if (!columnExists($pdo, 'clans', 'updated_at_utc')) {
            $pdo->exec('ALTER TABLE clans ADD COLUMN updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
            $log('Added clans.updated_at_utc column.');
        }
    }

    if (!tableExists($pdo, 'clan_events')) {
        $pdo->exec(
            'CREATE TABLE clan_events (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                clan_id INT UNSIGNED NOT NULL,
                event_name VARCHAR(255) NOT NULL,
                event_description TEXT NULL,
                event_location VARCHAR(255) NULL,
                host_name VARCHAR(255) NULL,
                host_discord_user_id VARCHAR(32) NULL,
                event_start_utc DATETIME NOT NULL,
                duration_minutes INT UNSIGNED NULL,
                image_url VARCHAR(1000) NULL,
                discord_channel_id VARCHAR(32) NULL,
                discord_mention_role_id VARCHAR(32) NULL,
                status VARCHAR(20) NOT NULL DEFAULT "scheduled",
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_recurring_weekly TINYINT(1) NOT NULL DEFAULT 0,
                recurring_until_utc DATETIME NULL,
                recurring_series_id VARCHAR(64) NULL,
                discord_daily_channel_id VARCHAR(32) NULL,
                discord_daily_message_id VARCHAR(32) NULL,
                discord_daily_posted_at_utc DATETIME NULL,
                discord_scheduled_event_id VARCHAR(32) NULL,
                discord_scheduled_event_created_at_utc DATETIME NULL,
                create_voice_chat_for_event TINYINT(1) NOT NULL DEFAULT 0,
                discord_voice_channel_id VARCHAR(32) NULL,
                discord_voice_channel_created_at_utc DATETIME NULL,
                discord_voice_warning_queued_at_utc DATETIME NULL,
                created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_clan_events_clan FOREIGN KEY (clan_id) REFERENCES clans(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log('Created clan_events table.');
    } else {
        $requiredColumns = [
            'clan_id' => 'ALTER TABLE clan_events ADD COLUMN clan_id INT UNSIGNED NOT NULL AFTER id',
            'event_name' => 'ALTER TABLE clan_events ADD COLUMN event_name VARCHAR(255) NOT NULL AFTER clan_id',
            'event_description' => 'ALTER TABLE clan_events ADD COLUMN event_description TEXT NULL AFTER event_name',
            'event_location' => 'ALTER TABLE clan_events ADD COLUMN event_location VARCHAR(255) NULL AFTER event_description',
            'host_name' => 'ALTER TABLE clan_events ADD COLUMN host_name VARCHAR(255) NULL AFTER event_location',
            'host_discord_user_id' => 'ALTER TABLE clan_events ADD COLUMN host_discord_user_id VARCHAR(32) NULL AFTER host_name',
            'event_start_utc' => 'ALTER TABLE clan_events ADD COLUMN event_start_utc DATETIME NOT NULL AFTER host_discord_user_id',
            'duration_minutes' => 'ALTER TABLE clan_events ADD COLUMN duration_minutes INT UNSIGNED NULL AFTER event_start_utc',
            'image_url' => 'ALTER TABLE clan_events ADD COLUMN image_url VARCHAR(1000) NULL AFTER duration_minutes',
            'discord_channel_id' => 'ALTER TABLE clan_events ADD COLUMN discord_channel_id VARCHAR(32) NULL AFTER image_url',
            'discord_mention_role_id' => 'ALTER TABLE clan_events ADD COLUMN discord_mention_role_id VARCHAR(32) NULL AFTER discord_channel_id',
            'status' => 'ALTER TABLE clan_events ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT "scheduled" AFTER discord_mention_role_id',
            'is_active' => 'ALTER TABLE clan_events ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status',
            'is_recurring_weekly' => 'ALTER TABLE clan_events ADD COLUMN is_recurring_weekly TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active',
            'recurring_until_utc' => 'ALTER TABLE clan_events ADD COLUMN recurring_until_utc DATETIME NULL AFTER is_recurring_weekly',
            'recurring_series_id' => 'ALTER TABLE clan_events ADD COLUMN recurring_series_id VARCHAR(64) NULL AFTER recurring_until_utc',
            'discord_daily_channel_id' => 'ALTER TABLE clan_events ADD COLUMN discord_daily_channel_id VARCHAR(32) NULL AFTER recurring_series_id',
            'discord_daily_message_id' => 'ALTER TABLE clan_events ADD COLUMN discord_daily_message_id VARCHAR(32) NULL AFTER discord_daily_channel_id',
            'discord_daily_posted_at_utc' => 'ALTER TABLE clan_events ADD COLUMN discord_daily_posted_at_utc DATETIME NULL AFTER discord_daily_message_id',
            'discord_scheduled_event_id' => 'ALTER TABLE clan_events ADD COLUMN discord_scheduled_event_id VARCHAR(32) NULL AFTER discord_daily_posted_at_utc',
            'discord_scheduled_event_created_at_utc' => 'ALTER TABLE clan_events ADD COLUMN discord_scheduled_event_created_at_utc DATETIME NULL AFTER discord_scheduled_event_id',
            'create_voice_chat_for_event' => 'ALTER TABLE clan_events ADD COLUMN create_voice_chat_for_event TINYINT(1) NOT NULL DEFAULT 0 AFTER discord_scheduled_event_created_at_utc',
            'discord_voice_channel_id' => 'ALTER TABLE clan_events ADD COLUMN discord_voice_channel_id VARCHAR(32) NULL AFTER create_voice_chat_for_event',
            'discord_voice_channel_created_at_utc' => 'ALTER TABLE clan_events ADD COLUMN discord_voice_channel_created_at_utc DATETIME NULL AFTER discord_voice_channel_id',
            'discord_voice_warning_queued_at_utc' => 'ALTER TABLE clan_events ADD COLUMN discord_voice_warning_queued_at_utc DATETIME NULL AFTER discord_voice_channel_created_at_utc',
            'created_at_utc' => 'ALTER TABLE clan_events ADD COLUMN created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER discord_voice_warning_queued_at_utc',
            'updated_at_utc' => 'ALTER TABLE clan_events ADD COLUMN updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at_utc',
        ];

        foreach ($requiredColumns as $column => $sql) {
            if (!columnExists($pdo, 'clan_events', $column)) {
                $pdo->exec($sql);
                $log('Added clan_events.' . $column . ' column.');
            }
        }
    }

    if (!indexExists($pdo, 'clan_events', 'idx_clan_start')) {
        $pdo->exec('CREATE INDEX idx_clan_start ON clan_events (clan_id, event_start_utc)');
        $log('Created idx_clan_start index.');
    }

    if (!indexExists($pdo, 'clan_events', 'idx_clan_active_start')) {
        $pdo->exec('CREATE INDEX idx_clan_active_start ON clan_events (clan_id, is_active, event_start_utc)');
        $log('Created idx_clan_active_start index.');
    }

    if (!indexExists($pdo, 'clan_events', 'idx_clan_recurring')) {
        $pdo->exec('CREATE INDEX idx_clan_recurring ON clan_events (clan_id, is_recurring_weekly, recurring_until_utc)');
        $log('Created idx_clan_recurring index.');
    }

    if (!indexExists($pdo, 'clan_events', 'idx_clan_events_series')) {
        $pdo->exec('CREATE INDEX idx_clan_events_series ON clan_events (clan_id, recurring_series_id, event_start_utc)');
        $log('Created idx_clan_events_series index.');
    }

    if (!indexExists($pdo, 'clan_events', 'idx_clan_status_start')) {
        $pdo->exec('CREATE INDEX idx_clan_status_start ON clan_events (clan_id, status, event_start_utc)');
        $log('Created idx_clan_status_start index.');
    }

    if (!indexExists($pdo, 'clan_events', 'idx_voice_channel_cleanup')) {
        $pdo->exec('CREATE INDEX idx_voice_channel_cleanup ON clan_events (clan_id, create_voice_chat_for_event, discord_voice_channel_id, status, event_start_utc)');
        $log('Created idx_voice_channel_cleanup index.');
    }


    if (!tableExists($pdo, 'clan_event_roles')) {
        $pdo->exec(
            'CREATE TABLE clan_event_roles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id INT UNSIGNED NOT NULL,
                role_name VARCHAR(100) NOT NULL,
                reaction_emoji VARCHAR(100) NOT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 1,
                created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_clan_event_roles_event FOREIGN KEY (event_id) REFERENCES clan_events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log('Created clan_event_roles table.');
    } else {
        $requiredColumns = [
            'event_id' => 'ALTER TABLE clan_event_roles ADD COLUMN event_id INT UNSIGNED NOT NULL AFTER id',
            'role_name' => 'ALTER TABLE clan_event_roles ADD COLUMN role_name VARCHAR(100) NOT NULL AFTER event_id',
            'reaction_emoji' => 'ALTER TABLE clan_event_roles ADD COLUMN reaction_emoji VARCHAR(100) NOT NULL AFTER role_name',
            'sort_order' => 'ALTER TABLE clan_event_roles ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 1 AFTER reaction_emoji',
            'created_at_utc' => 'ALTER TABLE clan_event_roles ADD COLUMN created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER sort_order',
        ];

        foreach ($requiredColumns as $column => $sql) {
            if (!columnExists($pdo, 'clan_event_roles', $column)) {
                $pdo->exec($sql);
                $log('Added clan_event_roles.' . $column . ' column.');
            }
        }
    }

    if (!indexExists($pdo, 'clan_event_roles', 'idx_event_role_order')) {
        $pdo->exec('CREATE INDEX idx_event_role_order ON clan_event_roles (event_id, sort_order)');
        $log('Created idx_event_role_order index.');
    }

    // Legacy cleanup requested by user: remove old discord_event_posts entirely.
    safeDropLegacyTable($pdo, 'discord_event_posts');

    if (!tableExists($pdo, 'discord_weekly_posts')) {
        $pdo->exec(
            'CREATE TABLE discord_weekly_posts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                clan_id INT UNSIGNED NOT NULL,
                week_start_utc DATETIME NOT NULL,
                discord_channel_id VARCHAR(32) NOT NULL,
                discord_message_id VARCHAR(32) NOT NULL,
                posted_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at_utc DATETIME NULL,
                CONSTRAINT fk_discord_weekly_posts_clan FOREIGN KEY (clan_id) REFERENCES clans(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log('Created discord_weekly_posts table.');
    } else {
        $requiredColumns = [
            'clan_id' => 'ALTER TABLE discord_weekly_posts ADD COLUMN clan_id INT UNSIGNED NOT NULL AFTER id',
            'week_start_utc' => 'ALTER TABLE discord_weekly_posts ADD COLUMN week_start_utc DATETIME NOT NULL AFTER clan_id',
            'discord_channel_id' => 'ALTER TABLE discord_weekly_posts ADD COLUMN discord_channel_id VARCHAR(32) NOT NULL AFTER week_start_utc',
            'discord_message_id' => 'ALTER TABLE discord_weekly_posts ADD COLUMN discord_message_id VARCHAR(32) NOT NULL AFTER discord_channel_id',
            'posted_at_utc' => 'ALTER TABLE discord_weekly_posts ADD COLUMN posted_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER discord_message_id',
            'updated_at_utc' => 'ALTER TABLE discord_weekly_posts ADD COLUMN updated_at_utc DATETIME NULL AFTER posted_at_utc',
        ];

        foreach ($requiredColumns as $column => $sql) {
            if (!columnExists($pdo, 'discord_weekly_posts', $column)) {
                $pdo->exec($sql);
                $log('Added discord_weekly_posts.' . $column . ' column.');
            }
        }
    }

    if (!indexExists($pdo, 'discord_weekly_posts', 'idx_clan_week')) {
        $pdo->exec('CREATE INDEX idx_clan_week ON discord_weekly_posts (clan_id, week_start_utc)');
        $log('Created idx_clan_week index.');
    }


if (!tableExists($pdo, 'bot_commands')) {
    $pdo->exec(
        'CREATE TABLE bot_commands (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            clan_id INT UNSIGNED NULL,
            command_key VARCHAR(191) NULL,
            command_type VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "pending",
            payload_json LONGTEXT NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 20,
            available_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            picked_up_at_utc DATETIME NULL,
            completed_at_utc DATETIME NULL,
            failed_at_utc DATETIME NULL,
            expires_at_utc DATETIME NULL,
            last_error TEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $log('Created bot_commands table.');
} else {
    $requiredColumns = [
        'clan_id' => 'ALTER TABLE bot_commands ADD COLUMN clan_id INT UNSIGNED NULL AFTER id',
        'command_key' => 'ALTER TABLE bot_commands ADD COLUMN command_key VARCHAR(191) NULL AFTER clan_id',
        'command_type' => 'ALTER TABLE bot_commands ADD COLUMN command_type VARCHAR(100) NOT NULL AFTER command_key',
        'status' => 'ALTER TABLE bot_commands ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT "pending" AFTER command_type',
        'payload_json' => 'ALTER TABLE bot_commands ADD COLUMN payload_json LONGTEXT NULL AFTER status',
        'attempt_count' => 'ALTER TABLE bot_commands ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER payload_json',
        'max_attempts' => 'ALTER TABLE bot_commands ADD COLUMN max_attempts INT UNSIGNED NOT NULL DEFAULT 20 AFTER attempt_count',
        'available_at_utc' => 'ALTER TABLE bot_commands ADD COLUMN available_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER max_attempts',
        'picked_up_at_utc' => 'ALTER TABLE bot_commands ADD COLUMN picked_up_at_utc DATETIME NULL AFTER available_at_utc',
        'completed_at_utc' => 'ALTER TABLE bot_commands ADD COLUMN completed_at_utc DATETIME NULL AFTER picked_up_at_utc',
        'failed_at_utc' => 'ALTER TABLE bot_commands ADD COLUMN failed_at_utc DATETIME NULL AFTER completed_at_utc',
        'expires_at_utc' => 'ALTER TABLE bot_commands ADD COLUMN expires_at_utc DATETIME NULL AFTER failed_at_utc',
        'last_error' => 'ALTER TABLE bot_commands ADD COLUMN last_error TEXT NULL AFTER expires_at_utc',
        'created_at_utc' => 'ALTER TABLE bot_commands ADD COLUMN created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER last_error',
        'updated_at_utc' => 'ALTER TABLE bot_commands ADD COLUMN updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at_utc',
    ];

    foreach ($requiredColumns as $column => $sql) {
        if (!columnExists($pdo, 'bot_commands', $column)) {
            $pdo->exec($sql);
            $log('Added bot_commands.' . $column . ' column.');
        }
    }
}

if (!indexExists($pdo, 'bot_commands', 'uniq_bot_commands_key')) {
    $pdo->exec('CREATE UNIQUE INDEX uniq_bot_commands_key ON bot_commands (command_key)');
    $log('Created uniq_bot_commands_key index.');
}

if (!indexExists($pdo, 'bot_commands', 'idx_bot_commands_poll')) {
    $pdo->exec('CREATE INDEX idx_bot_commands_poll ON bot_commands (status, available_at_utc, command_type)');
    $log('Created idx_bot_commands_poll index.');
}

if (!indexExists($pdo, 'bot_commands', 'idx_bot_commands_clan')) {
    $pdo->exec('CREATE INDEX idx_bot_commands_clan ON bot_commands (clan_id, created_at_utc)');
    $log('Created idx_bot_commands_clan index.');
}

    $clanId = (int) env('CLAN_ID', 0);
    $clanName = (string) env('CLAN_NAME', 'Clan');
    $clanTimezone = (string) env('DEFAULT_TIMEZONE', 'UTC');

    if ($clanId > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clans WHERE id = :id');
        $stmt->execute(['id' => $clanId]);
        $exists = (int) $stmt->fetchColumn() > 0;

        if ($exists) {
            $update = $pdo->prepare('UPDATE clans SET name = :name, timezone = :timezone WHERE id = :id');
            $update->execute([
                'id' => $clanId,
                'name' => $clanName,
                'timezone' => $clanTimezone,
            ]);
            $log('Updated clan record for CLAN_ID=' . $clanId . '.');
        } else {
            $insert = $pdo->prepare('INSERT INTO clans (id, name, timezone) VALUES (:id, :name, :timezone)');
            $insert->execute([
                'id' => $clanId,
                'name' => $clanName,
                'timezone' => $clanTimezone,
            ]);
            $log('Inserted clan record for CLAN_ID=' . $clanId . '.');
        }
    }

    $log('Bootstrap complete.');
}

if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
    runDatabaseBootstrap(true);
}
