<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function clanTimezone(): DateTimeZone
{
    return new DateTimeZone(appConfig()['clan']['timezone']);
}

function utcTimezone(): DateTimeZone
{
    return new DateTimeZone('UTC');
}

function clanLocalToUtc(string $date, string $time): string
{
    $dt = new DateTimeImmutable($date . ' ' . $time . ':00', clanTimezone());
    return $dt->setTimezone(utcTimezone())->format('Y-m-d H:i:s');
}

function utcToClanLocal(string $utcDateTime): DateTimeImmutable
{
    $dt = new DateTimeImmutable($utcDateTime, utcTimezone());
    return $dt->setTimezone(clanTimezone());
}

function discordUnixTimestamp(string $utcDateTime): int
{
    $dt = new DateTimeImmutable($utcDateTime, utcTimezone());
    return $dt->getTimestamp();
}

function weekRangeFromDate(?string $date = null): array
{
    $tz = clanTimezone();
    $target = $date ? new DateTimeImmutable($date . ' 00:00:00', $tz) : new DateTimeImmutable('now', $tz);
    $monday = $target->modify('monday this week')->setTime(0, 0, 0);
    $sundayEnd = $monday->modify('+6 days')->setTime(23, 59, 59);

    return [
        'week_start_local' => $monday,
        'week_end_local' => $sundayEnd,
        'week_start_utc' => $monday->setTimezone(utcTimezone())->format('Y-m-d H:i:s'),
        'week_end_utc' => $sundayEnd->setTimezone(utcTimezone())->format('Y-m-d H:i:s'),
    ];
}
