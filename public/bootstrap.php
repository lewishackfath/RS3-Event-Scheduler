<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/lib/helpers.php';
require_once dirname(__DIR__) . '/app/lib/auth.php';
require_once dirname(__DIR__) . '/app/lib/time.php';
require_once dirname(__DIR__) . '/app/repositories/EventRepository.php';
require_once dirname(__DIR__) . '/app/services/EventService.php';

$publicAuthPages = ['index.php', 'login.php', 'oauth_callback.php', 'logout.php'];
$currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));

if (!in_array($currentPage, $publicAuthPages, true)) {
    requireAuth();
}
