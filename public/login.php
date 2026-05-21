<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';

if (isAuthenticated()) {
    redirect('index.php');
}

if (isDiscordAuthConfigured()) {
    redirect(buildDiscordLoginUrl());
}

renderHeader('Login');
?>
<div class="card mt-40" style="max-width:720px;margin-left:auto;margin-right:auto;">
    <h2 style="margin-top:0;">Discord Login Not Configured</h2>
    <p>This scheduler is restricted to configured Discord admins, but Discord OAuth is not fully configured yet.</p>
    <div class="flash error">
        Please set <code>DISCORD_CLIENT_ID</code>, <code>DISCORD_CLIENT_SECRET</code>, <code>DISCORD_REDIRECT_URI</code>, <code>DISCORD_GUILD_ID</code>, and at least one of <code>ADMIN_ROLE_IDS</code> or <code>ADMIN_USER_IDS</code> in your <code>.env</code> file.
    </div>
</div>
<?php renderFooter(); ?>
