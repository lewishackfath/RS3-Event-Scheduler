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

    return $lines === [] ? '' : implode("
", $lines);
}

function formatEventDurationForDisplay(?int $minutes): string
{
    $minutes = (int) $minutes;
    if ($minutes <= 0) {
        $minutes = max(1, (int) (appConfig()['discord']['default_event_duration_minutes'] ?? 60));
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    if ($hours > 0 && $remainingMinutes > 0) {
        return $hours . 'h ' . $remainingMinutes . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h';
    }

    return $remainingMinutes . 'm';
}

function buildEventEmbed(array $event): array
{
    $brand = branding();
    $timestamp = discordUnixTimestamp($event['event_start_utc']);
    $utc = new DateTimeImmutable($event['event_start_utc'], new DateTimeZone('UTC'));
    $local = utcToClanLocal($event['event_start_utc']);
    $imageUrl = eventDisplayImageUrl($event);
    $preferredRolesText = formatPreferredRolesForDisplay((array) ($event['preferred_roles'] ?? []));
    $durationText = formatEventDurationForDisplay(isset($event['duration_minutes']) ? (int) $event['duration_minutes'] : null);
    $location = trim((string) ($event['event_location'] ?? '')) !== ''
        ? (string) $event['event_location']
        : (string) (appConfig()['discord']['event_location_default'] ?? 'RuneScape - In Game');

    $host = trim((string) ($event['host_name'] ?? '')) !== ''
        ? $event['host_name']
        : 'TBC';

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
                'name' => 'Event Times',
                'value' => $local->format('g:i A T') . "
" . 'Game Time: ' . $utc->format('H:i') . ' UTC' . "
" . 'Duration: ' . $durationText,
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
            'value' => implode("
", $lines),
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
