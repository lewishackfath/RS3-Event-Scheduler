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

function formatPreferredRolesForDisplay(array $roles): string
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

    return $lines === [] ? '' : implode("\n", $lines);
}

function formatDurationForDisplay(?int $minutes): string
{
    if ($minutes === null || $minutes <= 0) {
        $minutes = max(1, (int) appConfig()['discord']['default_event_duration_minutes']);
    }

    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    $parts = [];

    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($mins > 0) {
        $parts[] = $mins . 'm';
    }

    return $parts === [] ? $minutes . 'm' : implode(' ', $parts);
}

function buildEventEmbed(array $event): array
{
    $brand = branding();
    $timestamp = discordUnixTimestamp($event['event_start_utc']);
    $utc = new DateTimeImmutable($event['event_start_utc'], new DateTimeZone('UTC'));
    $imageUrl = eventDisplayImageUrl($event);
    $preferredRolesText = formatPreferredRolesForDisplay((array) ($event['preferred_roles'] ?? []));
    $durationText = formatDurationForDisplay(isset($event['duration_minutes']) ? (int) $event['duration_minutes'] : null);
    $timezoneLabel = (string) appConfig()['clan']['timezone'];

    $host = trim((string) ($event['host_name'] ?? '')) !== ''
        ? $event['host_name']
        : 'TBC';

    $embed = [
        'title' => (string) $event['event_name'],
        'description' => '',
        'color' => hexColourToInt((string) ($brand['embed_colour'] ?? '#5865F2')),
        'fields' => [
            [
                'name' => 'Event Date',
                'value' => '<t:' . $timestamp . ':D>',
                'inline' => false,
            ],
            [
                'name' => 'Event Start Time (' . $timezoneLabel . ')',
                'value' => '<t:' . $timestamp . ':t>' . "
" . '(<t:' . $timestamp . ':R>)',
                'inline' => true,
            ],
            [
                'name' => 'Game Time / Duration',
                'value' => $utc->format('H:i') . ' UTC' . "
" . $durationText,
                'inline' => true,
            ],
            [
                'name' => 'Event Host',
                'value' => $host,
                'inline' => false,
            ],
            [
                'name' => 'Event Location',
                'value' => trim((string) ($event['event_location'] ?? '')) !== '' ? (string) $event['event_location'] : (string) (appConfig()['discord']['event_location_default'] ?? 'RuneScape - In Game'),
                'inline' => false,
            ],
            [
                'name' => 'Event Description',
                'value' => trim((string) ($event['event_description'] ?? '')) !== '' ? trim((string) ($event['event_description'] ?? '')) : 'No description provided.',
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

    if ($imageUrl !== '') {
        $embed['image'] = ['url' => $imageUrl];
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
            $line = '• **' . (string) $event['event_name'] . '** — <t:' . discordUnixTimestamp((string) $event['event_start_utc']) . ':t>';
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
