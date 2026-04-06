<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/lib/helpers.php';
require_once dirname(__DIR__) . '/app/lib/time.php';
require_once dirname(__DIR__) . '/app/repositories/EventRepository.php';
require_once dirname(__DIR__) . '/app/services/EventService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
