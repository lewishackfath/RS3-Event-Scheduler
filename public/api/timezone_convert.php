<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $direction = trim((string) ($_GET['direction'] ?? ''));

    if ($direction === 'local_to_utc') {
        $date = trim((string) ($_GET['date'] ?? ''));
        $time = trim((string) ($_GET['time'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            throw new InvalidArgumentException('A valid local date and time are required.');
        }

        $utc = clanLocalToUtc($date, $time);
        $local = utcToClanLocal($utc);

        echo json_encode([
            'timezone' => appConfig()['clan']['timezone'],
            'local_date' => $local->format('Y-m-d'),
            'local_time' => $local->format('H:i'),
            'local_timezone_abbr' => $local->format('T'),
            'utc' => $utc,
            'utc_input' => utcToInputValue($utc),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($direction === 'utc_to_local') {
        $utcInput = trim((string) ($_GET['utc'] ?? ''));
        if ($utcInput === '') {
            throw new InvalidArgumentException('A valid UTC date and time are required.');
        }

        $utc = utcInputToUtc($utcInput);
        $local = utcToClanLocal($utc);

        echo json_encode([
            'timezone' => appConfig()['clan']['timezone'],
            'local_date' => $local->format('Y-m-d'),
            'local_time' => $local->format('H:i'),
            'local_timezone_abbr' => $local->format('T'),
            'utc' => $utc,
            'utc_input' => utcToInputValue($utc),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid conversion direction.']);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    echo json_encode(['error' => $e->getMessage()]);
}
