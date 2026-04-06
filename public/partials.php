<?php
declare(strict_types=1);

function renderHeader(string $title): void
{
    $brand = branding();
    $bgStyle = trim((string) ($brand['background_image_url'] ?? '')) !== ''
        ? 'background-image:url(' . e($brand['background_image_url']) . ');background-size:cover;background-position:center;'
        : 'background:linear-gradient(135deg,#1f2937,#111827);';
    $user = function_exists('currentUser') ? currentUser() : null;
    $currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . e($title) . ' - ' . e(appConfig()['app']['name']) . '</title>';
    echo '<style>
        body{font-family:Arial,sans-serif;margin:0;background:#0f172a;color:#e5e7eb}
        a{color:#93c5fd;text-decoration:none}
        a:hover{text-decoration:underline}
        .wrap{max-width:1200px;margin:0 auto;padding:24px}
        .hero{padding:24px;border-bottom:1px solid rgba(255,255,255,.08);' . $bgStyle . '}
        .hero-inner{max-width:1200px;margin:0 auto;display:flex;gap:16px;align-items:center;justify-content:space-between;padding:12px 24px;flex-wrap:wrap}
        .hero-left{display:flex;gap:16px;align-items:center}
        .hero img.logo{max-height:56px;max-width:56px;border-radius:12px;background:#fff;padding:4px}
        .nav{display:flex;gap:16px;flex-wrap:wrap;margin-top:12px}
        .nav .active{font-weight:700;text-decoration:underline}
        .userbox{display:flex;align-items:center;gap:10px;background:rgba(17,24,39,.75);padding:10px 14px;border:1px solid rgba(255,255,255,.08);border-radius:14px}
        .avatar{width:36px;height:36px;border-radius:999px;object-fit:cover;background:#1f2937}
        .card{background:#111827;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:18px;box-shadow:0 10px 25px rgba(0,0,0,.2)}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
        .grid-2{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
        .field-full{grid-column:1 / -1}
        .btn{display:inline-block;background:#2563eb;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
        .btn.secondary{background:#374151}
        .btn.danger{background:#b91c1c}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:top}
        input,textarea,select,button{font:inherit}
        input,textarea,select{width:100%;padding:10px;border-radius:10px;border:1px solid #374151;background:#0b1220;color:#e5e7eb;box-sizing:border-box}
        label{display:block;margin-bottom:6px;font-weight:700}
        .field{margin-bottom:14px;position:relative}
        .flash{padding:12px 14px;border-radius:12px;margin-bottom:16px}
        .flash.success{background:#064e3b}
        .flash.error{background:#7f1d1d}
        .muted{color:#94a3b8}
        .day-group{margin-bottom:24px}
        .actions{display:flex;gap:8px;flex-wrap:wrap}
        .event-card-row{display:grid;grid-template-columns:180px 1fr;gap:16px;padding:14px 0;border-bottom:1px dashed rgba(255,255,255,.08)}
        .event-card-row:last-child{border-bottom:none}
        .event-card-image-wrap{display:flex;align-items:flex-start;justify-content:flex-start}
        .event-card-image{width:180px;height:100px;object-fit:cover;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:#0b1220}
        .event-card-image.placeholder{display:flex;align-items:center;justify-content:center;color:#94a3b8}
        .event-card-body{min-width:0}
        .event-card-header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px;flex-wrap:wrap}
        .event-meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
        .event-description{margin-top:12px;line-height:1.5}
        .badge{display:inline-block;font-size:12px;background:#1d4ed8;color:#fff;padding:4px 8px;border-radius:999px;margin-left:8px;vertical-align:middle}
        .search-results{position:absolute;left:0;right:0;top:100%;margin-top:6px;background:#0b1220;border:1px solid #374151;border-radius:12px;overflow:hidden;z-index:30;box-shadow:0 12px 32px rgba(0,0,0,.35)}
        .search-result-item{display:block;width:100%;background:none;border:none;border-bottom:1px solid rgba(255,255,255,.06);padding:10px 12px;text-align:left;color:#e5e7eb;cursor:pointer}
        .search-result-item:last-child{border-bottom:none}
        .search-result-item:hover{background:#111827}
        .search-result-item span{display:block;margin-top:4px;font-size:12px}
        .preview-thumb-wrap .event-card-image{width:140px;height:140px}
        @media (max-width: 700px){
            .event-card-row{grid-template-columns:1fr}
            .event-card-image{width:100%;height:180px}
            .preview-thumb-wrap .event-card-image{width:100%;height:180px}
        }
    </style>';
    echo '</head><body>';
    echo '<div class="hero"><div class="hero-inner">';
    echo '<div class="hero-left">';
    if (($brand['logo_url'] ?? '') !== '') {
        echo '<img class="logo" src="' . e((string) $brand['logo_url']) . '" alt="' . e(currentClanName()) . '">';
    }
    echo '<div>';
    echo '<h1 style="margin:0 0 6px 0;">' . e(appConfig()['app']['name']) . '</h1>';
    echo '<div class="muted">' . e(currentClanName()) . '</div>';
    echo '<div class="nav">';
    echo '<a class="' . ($currentPage === 'index.php' ? 'active' : '') . '" href="index.php">Schedule</a>';
    if (isAuthenticated()) {
        echo '<a class="' . ($currentPage === 'post_schedule.php' ? 'active' : '') . '" href="post_schedule.php">Discord Publishing</a>';
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

function renderFooter(): void
{
    echo '</div></body></html>';
}
