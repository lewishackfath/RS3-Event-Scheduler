<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id > 0) {
    (new EventRepository())->delete($id);
    setFlash('success', 'Event deleted successfully.');
}

redirect('index.php');
