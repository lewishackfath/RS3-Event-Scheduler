<?php
declare(strict_types=1);

require_once __DIR__ . '/time.php';
require_once __DIR__ . '/helpers.php';

function hexColourToInt(string $hex): int
{
    $hex = ltrim(trim($hex), '#');
    if ($hex === '' || !ctype_xdigit($hex)) {
        return hexdec('5865F2');
    }
    return hexdec($hex);
}

function formatPreferredRolesForDisplay(array $roles, string $separator = "\n"): string
{
    $lines = [];

    foreach ($roles as $role) {
        $name = trim((string) ($role['role_name'] ?? ''));
        $emoji = trim((string) ($role['reaction_emoji'] ?? ''));
        if ($name === '' || $emoji === '') {
            continue;
        }
        $lines[] = $emoji . ' ' . $name;
    }

    return $lines === [] ? '' : implode($separator, $lines);
}

function formatEventDurationLabel(array $event): string
{
    $durationMinutes = (int) ($event['duration_minutes'] ?? 0);
    if ($durationMinutes <= 0) {
        $durationMinutes = max(1, (int) appConfig()['discord']['default_event_duration_minutes']);
    }

    $hours = intdiv($durationMinutes, 60);
    $minutes = $durationMinutes % 60;

    if ($hours > 0 && $minutes > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h';
    }
    return $minutes . 'm';
}

function buildEventEmbed(array $event): array
{
    $brand = branding();
    $timestamp = discordUnixTimestamp($event['event_start_utc']);
    $utc = new DateTimeImmutable($event['event_start_utc'], new DateTimeZone('UTC'));
    $local = utcToClanLocal((string) $event['event_start_utc']);
    $thumbUrl = eventDisplayImageUrl($event);
    $preferredRolesText = formatPreferredRolesForDisplay((array) ($event['preferred_roles'] ?? []), ' | ');

    $host = trim((string) ($event['host_name'] ?? '')) !== ''
        ? (string) $event['host_name']
        : 'TBC';

    $location = trim((string) ($event['event_location'] ?? '')) !== ''
        ? (string) $event['event_location']
        : (string) (appConfig()['discord']['event_location_default'] ?? 'RuneScape - In Game');

    $embed = [
        'title' => (string) $event['event_name'],
        'color' => hexColourToInt((string) ($brand['embed_colour'] ?? '#5865F2')),
        'fields' => [
            [
                'name' => 'Event Date',
                'value' => $local->format('l, j F Y'),
                'inline' => false,
            ],
            [
                'name' => 'Event Time',
                'value' => 'Local - <t:' . $timestamp . ':F>'
                    . "\n" . 'Relative - <t:' . $timestamp . ':R>'
                    . "\n" . 'Game - ' . $utc->format('H:i') . ' UTC',
                'inline' => false,
            ],
            [
                'name' => 'Event Host',
                'value' => $host,
                'inline' => false,
            ],
            [
                'name' => 'Event Location',
                'value' => $location,
                'inline' => false,
            ],
        ],
        'footer' => [
            'text' => (string) ($brand['footer_text'] ?: currentClanName() . ' Events'),
        ],
        'timestamp' => $utc->format(DateTimeInterface::ATOM),
    ];

    if ($preferredRolesText !== '') {
        $embed['fields'][] = [
            'name' => 'Roles',
            'value' => $preferredRolesText,
            'inline' => false,
        ];
    }

    $description = trim((string) ($event['event_description'] ?? ''));
    if ($description !== '') {
        $embed['fields'][] = [
            'name' => 'Event Description',
            'value' => $description,
            'inline' => false,
        ];
    }

    // Keep duration visible in the Event Time field.
    $embed['fields'][1]['value'] .= "\n" . 'Duration - ' . formatEventDurationLabel($event);

    if ($thumbUrl !== '') {
        $embed['thumbnail'] = ['url' => $thumbUrl];
    }

    if (($brand['logo_url'] ?? '') !== '') {
        $embed['author'] = [
            'name' => currentClanName(),
            'icon_url' => (string) $brand['logo_url'],
        ];
        $embed['footer']['icon_url'] = (string) $brand['logo_url'];
    }

    return $embed;
}

function buildWeeklySummaryEmbed(array $events, DateTimeImmutable $weekStartLocal): array
{
    $brand = branding();
    $grouped = [];

    foreach ($events as $event) {
        $local = utcToClanLocal((string) $event['event_start_utc']);
        $key = $local->format('Y-m-d');
        $grouped[$key]['label'] = $local->format('l j M');
        $grouped[$key]['items'][] = $event;
    }

    $fields = [];
    foreach ($grouped as $day) {
        $lines = [];
        foreach ($day['items'] as $event) {
            $host = trim((string) ($event['host_name'] ?? ''));
            $timestamp = discordUnixTimestamp((string) $event['event_start_utc']);
            $line = '• **' . (string) $event['event_name'] . '** — Local: <t:' . $timestamp . ':F>';
            if ($host !== '') {
                $line .= ' — Host: ' . $host;
            }
            $lines[] = $line;
        }

        $fields[] = [
            'name' => $day['label'],
            'value' => implode("\n", $lines),
            'inline' => false,
        ];
    }

    if ($fields === []) {
        $fields[] = [
            'name' => 'No events',
            'value' => 'No events are scheduled for this week yet.',
            'inline' => false,
        ];
    }

    $embed = [
        'title' => currentClanName() . ' Weekly Schedule',
        'description' => 'Week commencing ' . $weekStartLocal->format('j F Y') . '. All event times below use Discord timestamps, so members will see them in their own local timezone.',
        'color' => hexColourToInt((string) ($brand['embed_colour'] ?? '#5865F2')),
        'fields' => $fields,
        'footer' => [
            'text' => (string) ($brand['footer_text'] ?: currentClanName() . ' Events'),
        ],
        'timestamp' => (new DateTimeImmutable('now', utcTimezone()))->format(DateTimeInterface::ATOM),
    ];

    if (($brand['header_image_url'] ?? '') !== '') {
        $embed['image'] = ['url' => (string) $brand['header_image_url']];
    }

    if (($brand['logo_url'] ?? '') !== '') {
        $embed['author'] = [
            'name' => currentClanName(),
            'icon_url' => (string) $brand['logo_url'],
        ];
        $embed['footer']['icon_url'] = (string) $brand['logo_url'];
    }

    return $embed;
}
