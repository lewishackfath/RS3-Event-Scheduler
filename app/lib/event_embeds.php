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

function buildEventEmbed(array $event): array
{
    $brand = branding();
    $timestamp = discordUnixTimestamp($event['event_start_utc']);
    $utc = new DateTimeImmutable($event['event_start_utc'], new DateTimeZone('UTC'));
    $thumbUrl = eventDisplayImageUrl($event);

    $host = trim((string) ($event['host_name'] ?? '')) !== ''
        ? $event['host_name']
        : 'TBC';

    $embed = [
        'title' => (string) $event['event_name'],
        'description' => trim((string) ($event['event_description'] ?? '')),
        'color' => hexColourToInt((string) ($brand['embed_colour'] ?? '#5865F2')),
        'fields' => [
            [
                'name' => 'Event Date/Time',
                'value' => '<t:' . $timestamp . ':F>',
                'inline' => false,
            ],
            [
                'name' => 'Gametime',
                'value' => $utc->format('D j M Y, H:i') . ' UTC',
                'inline' => true,
            ],
            [
                'name' => 'Local Time',
                'value' => '<t:' . $timestamp . ':f>' . "\n" . '(<t:' . $timestamp . ':R>)',
                'inline' => true,
            ],
            [
                'name' => 'Event Host',
                'value' => $host,
                'inline' => false,
            ],
            [
                'name' => 'Location',
                'value' => trim((string) ($event['event_location'] ?? '')) !== '' ? (string) $event['event_location'] : (string) (appConfig()['discord']['event_location_default'] ?? 'RuneScape - In Game'),
                'inline' => false,
            ],
        ],
        'footer' => [
            'text' => (string) ($brand['footer_text'] ?: currentClanName() . ' Events'),
        ],
        'timestamp' => $utc->format(DateTimeInterface::ATOM),
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
