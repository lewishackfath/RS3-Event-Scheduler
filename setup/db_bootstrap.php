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

$pdo = db();
$pdo->exec('SET NAMES utf8mb4');

echo "Running database bootstrap...
";

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
    echo "Created clans table.
";
} else {
    if (!columnExists($pdo, 'clans', 'timezone')) {
        $pdo->exec('ALTER TABLE clans ADD COLUMN timezone VARCHAR(100) NOT NULL DEFAULT "UTC" AFTER name');
        echo "Added clans.timezone column.
";
    }
    if (!columnExists($pdo, 'clans', 'created_at_utc')) {
        $pdo->exec('ALTER TABLE clans ADD COLUMN created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        echo "Added clans.created_at_utc column.
";
    }
    if (!columnExists($pdo, 'clans', 'updated_at_utc')) {
        $pdo->exec('ALTER TABLE clans ADD COLUMN updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        echo "Added clans.updated_at_utc column.
";
    }
}

if (!tableExists($pdo, 'clan_events')) {
    $pdo->exec(
        'CREATE TABLE clan_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            clan_id INT UNSIGNED NOT NULL,
            event_name VARCHAR(255) NOT NULL,
            event_description TEXT NULL,
            host_name VARCHAR(255) NULL,
            host_discord_user_id VARCHAR(32) NULL,
            event_start_utc DATETIME NOT NULL,
            duration_minutes INT UNSIGNED NULL,
            image_url VARCHAR(1000) NULL,
            discord_channel_id VARCHAR(32) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_recurring_weekly TINYINT(1) NOT NULL DEFAULT 0,
            recurring_until_utc DATETIME NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_clan_events_clan FOREIGN KEY (clan_id) REFERENCES clans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    echo "Created clan_events table.
";
} else {
    $requiredColumns = [
        'clan_id' => 'ALTER TABLE clan_events ADD COLUMN clan_id INT UNSIGNED NOT NULL AFTER id',
        'event_name' => 'ALTER TABLE clan_events ADD COLUMN event_name VARCHAR(255) NOT NULL AFTER clan_id',
        'event_description' => 'ALTER TABLE clan_events ADD COLUMN event_description TEXT NULL AFTER event_name',
        'host_name' => 'ALTER TABLE clan_events ADD COLUMN host_name VARCHAR(255) NULL AFTER event_description',
        'host_discord_user_id' => 'ALTER TABLE clan_events ADD COLUMN host_discord_user_id VARCHAR(32) NULL AFTER host_name',
        'event_start_utc' => 'ALTER TABLE clan_events ADD COLUMN event_start_utc DATETIME NOT NULL AFTER host_discord_user_id',
        'duration_minutes' => 'ALTER TABLE clan_events ADD COLUMN duration_minutes INT UNSIGNED NULL AFTER event_start_utc',
        'image_url' => 'ALTER TABLE clan_events ADD COLUMN image_url VARCHAR(1000) NULL AFTER duration_minutes',
        'discord_channel_id' => 'ALTER TABLE clan_events ADD COLUMN discord_channel_id VARCHAR(32) NULL AFTER image_url',
        'is_active' => 'ALTER TABLE clan_events ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER discord_channel_id',
        'is_recurring_weekly' => 'ALTER TABLE clan_events ADD COLUMN is_recurring_weekly TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active',
        'recurring_until_utc' => 'ALTER TABLE clan_events ADD COLUMN recurring_until_utc DATETIME NULL AFTER is_recurring_weekly',
        'created_at_utc' => 'ALTER TABLE clan_events ADD COLUMN created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER recurring_until_utc',
        'updated_at_utc' => 'ALTER TABLE clan_events ADD COLUMN updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at_utc',
    ];

    foreach ($requiredColumns as $column => $sql) {
        if (!columnExists($pdo, 'clan_events', $column)) {
            $pdo->exec($sql);
            echo "Added clan_events.{$column} column.
";
        }
    }
}

if (!indexExists($pdo, 'clan_events', 'idx_clan_start')) {
    $pdo->exec('CREATE INDEX idx_clan_start ON clan_events (clan_id, event_start_utc)');
    echo "Created idx_clan_start index.
";
}

if (!indexExists($pdo, 'clan_events', 'idx_clan_active_start')) {
    $pdo->exec('CREATE INDEX idx_clan_active_start ON clan_events (clan_id, is_active, event_start_utc)');
    echo "Created idx_clan_active_start index.
";
}

if (!indexExists($pdo, 'clan_events', 'idx_clan_recurring')) {
    $pdo->exec('CREATE INDEX idx_clan_recurring ON clan_events (clan_id, is_recurring_weekly, recurring_until_utc)');
    echo "Created idx_clan_recurring index.
";
}

if (!tableExists($pdo, 'discord_event_posts')) {
    $pdo->exec(
        'CREATE TABLE discord_event_posts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            clan_id INT UNSIGNED NOT NULL,
            event_id INT UNSIGNED NOT NULL,
            discord_channel_id VARCHAR(32) NOT NULL,
            discord_message_id VARCHAR(32) NOT NULL,
            posted_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NULL,
            CONSTRAINT fk_discord_event_posts_clan FOREIGN KEY (clan_id) REFERENCES clans(id) ON DELETE CASCADE,
            CONSTRAINT fk_discord_event_posts_event FOREIGN KEY (event_id) REFERENCES clan_events(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    echo "Created discord_event_posts table.
";
} else {
    $requiredColumns = [
        'clan_id' => 'ALTER TABLE discord_event_posts ADD COLUMN clan_id INT UNSIGNED NOT NULL AFTER id',
        'event_id' => 'ALTER TABLE discord_event_posts ADD COLUMN event_id INT UNSIGNED NOT NULL AFTER clan_id',
        'discord_channel_id' => 'ALTER TABLE discord_event_posts ADD COLUMN discord_channel_id VARCHAR(32) NOT NULL AFTER event_id',
        'discord_message_id' => 'ALTER TABLE discord_event_posts ADD COLUMN discord_message_id VARCHAR(32) NOT NULL AFTER discord_channel_id',
        'posted_at_utc' => 'ALTER TABLE discord_event_posts ADD COLUMN posted_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER discord_message_id',
        'updated_at_utc' => 'ALTER TABLE discord_event_posts ADD COLUMN updated_at_utc DATETIME NULL AFTER posted_at_utc',
    ];

    foreach ($requiredColumns as $column => $sql) {
        if (!columnExists($pdo, 'discord_event_posts', $column)) {
            $pdo->exec($sql);
            echo "Added discord_event_posts.{$column} column.
";
        }
    }
}

if (!indexExists($pdo, 'discord_event_posts', 'idx_clan_event')) {
    $pdo->exec('CREATE INDEX idx_clan_event ON discord_event_posts (clan_id, event_id)');
    echo "Created idx_clan_event index.
";
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
        echo "Updated clan record for CLAN_ID={$clanId}.
";
    } else {
        $insert = $pdo->prepare('INSERT INTO clans (id, name, timezone) VALUES (:id, :name, :timezone)');
        $insert->execute([
            'id' => $clanId,
            'name' => $clanName,
            'timezone' => $clanTimezone,
        ]);
        echo "Inserted clan record for CLAN_ID={$clanId}.
";
    }
}

echo "Bootstrap complete.
";
