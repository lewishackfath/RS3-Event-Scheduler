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


function buildEndedEventEmbed(array $event): array
{
    $brand = branding();
    $local = utcToClanLocal((string) $event['event_start_utc']);
    $thumbUrl = eventDisplayImageUrl($event);

    $eventName = trim((string) ($event['event_name'] ?? 'Event'));
    if ($eventName === '') {
        $eventName = 'Event';
    }

    $embed = [
        'description' => '*' . $eventName . '* - ' . $local->format('j F Y') . "\n" . 'This event has ended.',
        'color' => hexColourToInt((string) ($brand['embed_colour'] ?? '#5865F2')),
        'footer' => [
            'text' => (string) ($brand['footer_text'] ?: currentClanName() . ' Events'),
        ],
        'timestamp' => (new DateTimeImmutable('now', utcTimezone()))->format(DateTimeInterface::ATOM),
    ];

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

function ordinalDaySuffix(int $day): string
{
    if ($day >= 11 && $day <= 13) {
        return 'th';
    }

    return match ($day % 10) {
        1 => 'st',
        2 => 'nd',
        3 => 'rd',
        default => 'th',
    };
}

function formatWeeklySummaryDayHeader(DateTimeImmutable $local): string
{
    $dayNumber = (int) $local->format('j');
    return $local->format('l ') . $dayNumber . ordinalDaySuffix($dayNumber) . $local->format(' F');
}

function formatWeeklySummaryEventTime(DateTimeImmutable $local): string
{
    return $local->format('D j M') . ' • ' . $local->format('g:i A');
}

function buildWeeklySummaryEmbed(array $events, DateTimeImmutable $weekStartLocal): array
{
    $brand = branding();
    $grouped = [];

    foreach ($events as $event) {
        $local = utcToClanLocal((string) $event['event_start_utc']);
        $key = $local->format('Y-m-d');

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'label' => formatWeeklySummaryDayHeader($local),
                'items' => [],
            ];
        }

        $grouped[$key]['items'][] = [
            'event' => $event,
            'local' => $local,
        ];
    }

    ksort($grouped);

    $fields = [];
    foreach ($grouped as $day) {
        usort($day['items'], static function (array $a, array $b): int {
            return ((string) $a['event']['event_start_utc']) <=> ((string) $b['event']['event_start_utc']);
        });

        $lines = [];
        foreach ($day['items'] as $item) {
            $event = $item['event'];
            $local = $item['local'];
            $eventName = trim((string) ($event['event_name'] ?? 'Event'));
            if ($eventName === '') {
                $eventName = 'Event';
            }

            $lines[] = '- **' . $eventName . '** - ' . formatWeeklySummaryEventTime($local);
        }

        $fields[] = [
            'name' => '**' . $day['label'] . '**',
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
        'description' => 'Week commencing ' . $weekStartLocal->format('j F Y') . '.',
        'color' => hexColourToInt((string) ($brand['embed_colour'] ?? '#5865F2')),
        'fields' => $fields,
        'footer' => [
            'text' => (string) ($brand['footer_text'] ?: currentClanName() . ' Events'),
        ],
        'timestamp' => (new DateTimeImmutable('now', utcTimezone()))->format(DateTimeInterface::ATOM),
    ];

    if (($brand['header_image_url'] ?? '') !== '') {
        //$embed['image'] = ['url' => (string) $brand['header_image_url']];
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
