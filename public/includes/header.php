<?php
declare(strict_types=1);

function renderHeader(string $title): void
{
    $brand = branding();
    $bgValue = trim((string) ($brand['background_image_url'] ?? '')) !== ''
        ? 'url(' . e((string) $brand['background_image_url']) . ')'
        : 'linear-gradient(135deg,#1f2937,#111827)';
    $user = function_exists('currentUser') ? currentUser() : null;
    $currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . e($title) . ' - ' . e(appConfig()['app']['name']) . '</title>';
    echo '<link rel="stylesheet" href="assets/css/app.css">';
    echo '</head><body>';
    echo '<div class="hero" style="--hero-background:' . $bgValue . ';"><div class="hero-inner">';
    echo '<div class="hero-left">';
    if (($brand['logo_url'] ?? '') !== '') {
        echo '<img class="logo" src="' . e((string) $brand['logo_url']) . '" alt="' . e(currentClanName()) . '">';
    }
    echo '<div class="hero-brand">';
    echo '<div>'; 
    echo '<h1 class="hero-title">' . e(appConfig()['app']['name']) . '</h1>';
    echo '<div class="hero-subtitle">' . e(currentClanName()) . '</div>';
    echo '</div>';
    echo '<div class="nav">';
    echo '<a class="nav-link ' . ($currentPage === 'index.php' ? 'active' : '') . '" href="index.php">Schedule</a>';
    if (isAuthenticated()) {
        echo '<a class="nav-link ' . ($currentPage === 'post_schedule.php' ? 'active' : '') . '" href="post_schedule.php">Discord Publishing</a>';
    }
    echo '</div>';
    echo '</div></div>';

    echo '<div>';
    if ($user) {
        echo '<div class="userbox">';
        if (!empty($user['avatar_url'])) {
            echo '<img class="avatar" src="' . e((string) $user['avatar_url']) . '" alt="' . e((string) $user['display_name']) . '">';
        }
        echo '<div><div>' . e((string) $user['display_name']) . '</div><div class="muted">Discord Admin</div></div>';
        echo '<a class="btn secondary" href="logout.php">Logout</a>';
        echo '</div>';
    } else {
        echo '<div class="userbox"><div><div>Public View</div><div class="muted">Login with Discord to manage events</div></div><a class="btn" href="login.php">Login</a></div>';
    }
    echo '</div>';

    echo '</div></div>';
    echo '<div class="wrap">';

    $flash = flashMessage();
    if ($flash) {
        echo '<div class="flash ' . e((string) $flash['type']) . '">' . e((string) $flash['message']) . '</div>';
    }
}
