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

    $imageUrl = trim((string) ($event['image_url'] ?? '')) !== ''
        ? $event['image_url']
        : ($brand['header_image_url'] ?? '');

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
                'value' => '<t:' . $timestamp . ':f>\n(<t:' . $timestamp . ':R>)',
                'inline' => true,
            ],
            [
                'name' => 'Event Host',
                'value' => $host,
                'inline' => false,
            ],
        ],
        'footer' => [
            'text' => (string) ($brand['footer_text'] ?: currentClanName() . ' Events'),
        ],
        'timestamp' => $utc->format(DateTimeInterface::ATOM),
    ];

    if ($imageUrl !== '') {
        $embed['image'] = ['url' => $imageUrl];
    }

    if (($brand['logo_url'] ?? '') !== '') {
        $embed['thumbnail'] = ['url' => $brand['logo_url']];
    }

    return $embed;
}
