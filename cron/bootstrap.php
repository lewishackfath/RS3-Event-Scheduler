<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/setup/db_bootstrap.php';
require_once dirname(__DIR__) . '/app/lib/helpers.php';
require_once dirname(__DIR__) . '/app/lib/auth.php';
require_once dirname(__DIR__) . '/app/lib/time.php';
require_once dirname(__DIR__) . '/app/repositories/EventRepository.php';
require_once dirname(__DIR__) . '/app/services/EventService.php';

runDatabaseBootstrap(false);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}
