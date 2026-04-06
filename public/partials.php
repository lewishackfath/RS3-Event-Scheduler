<?php
declare(strict_types=1);

function renderHeader(string $title): void
{
    $brand = branding();
    $bgStyle = trim((string) ($brand['background_image_url'] ?? '')) !== ''
        ? 'background-image:url(' . e($brand['background_image_url']) . ');background-size:cover;background-position:center;'
        : 'background:linear-gradient(135deg,#1f2937,#111827);';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . e($title) . ' - ' . e(appConfig()['app']['name']) . '</title>';
    echo '<style>
        body{font-family:Arial,sans-serif;margin:0;background:#0f172a;color:#e5e7eb}
        a{color:#93c5fd;text-decoration:none}
        a:hover{text-decoration:underline}
        .wrap{max-width:1200px;margin:0 auto;padding:24px}
        .hero{padding:24px;border-bottom:1px solid rgba(255,255,255,.08);' . $bgStyle . '}
        .hero-inner{max-width:1200px;margin:0 auto;display:flex;gap:16px;align-items:center;padding:12px 24px}
        .hero img.logo{max-height:56px;max-width:56px;border-radius:12px;background:#fff;padding:4px}
        .nav{display:flex;gap:16px;flex-wrap:wrap;margin-top:12px}
        .card{background:#111827;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:18px;box-shadow:0 10px 25px rgba(0,0,0,.2)}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
        .btn{display:inline-block;background:#2563eb;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
        .btn.secondary{background:#374151}
        .btn.danger{background:#b91c1c}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:top}
        input,textarea,select{width:100%;padding:10px;border-radius:10px;border:1px solid #374151;background:#0b1220;color:#e5e7eb;box-sizing:border-box}
        label{display:block;margin-bottom:6px;font-weight:700}
        .field{margin-bottom:14px}
        .flash{padding:12px 14px;border-radius:12px;margin-bottom:16px}
        .flash.success{background:#064e3b}
        .flash.error{background:#7f1d1d}
        .muted{color:#94a3b8}
        .day-group{margin-bottom:24px}
        .event-row{display:flex;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px dashed rgba(255,255,255,.08)}
        .actions{display:flex;gap:8px;flex-wrap:wrap}
    </style>';
    echo '</head><body>';
    echo '<div class="hero"><div class="hero-inner">';
    if (($brand['logo_url'] ?? '') !== '') {
        echo '<img class="logo" src="' . e($brand['logo_url']) . '" alt="Logo">';
    }
    echo '<div><h1 style="margin:0 0 6px 0">' . e(currentClanName()) . '</h1>';
    echo '<div class="muted">' . e(appConfig()['app']['name']) . '</div>';
    echo '<div class="nav">';
    echo '<a href="index.php">Weekly Schedule</a>';
    echo '<a href="event_create.php">Add Event</a>';
    echo '<a href="post_schedule.php">Post to Discord</a>';
    echo '</div></div></div></div>';
    echo '<div class="wrap">';

    $flash = flashMessage();
    if ($flash) {
        echo '<div class="flash ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function renderFooter(): void
{
    echo '</div></body></html>';
}
