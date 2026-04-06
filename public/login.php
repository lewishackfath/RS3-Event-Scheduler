<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';

if (isAuthenticated()) {
    redirect('index.php');
}

renderHeader('Login');
?>
<div class="card" style="max-width:720px;margin:40px auto;">
    <h2 style="margin-top:0;">Discord Login Required</h2>
    <p>This scheduler is restricted to users who hold one of the configured Discord admin roles in this clan's Discord server.</p>

    <?php if (!isDiscordAuthConfigured()): ?>
        <div class="flash error">
            Discord OAuth is not fully configured. Please set <code>DISCORD_CLIENT_ID</code>, <code>DISCORD_CLIENT_SECRET</code>, <code>DISCORD_REDIRECT_URI</code>, <code>DISCORD_GUILD_ID</code>, and <code>ADMIN_ROLE_IDS</code> in your <code>.env</code> file.
        </div>
    <?php else: ?>
        <p><a class="btn" href="<?= e(buildDiscordLoginUrl()) ?>">Login with Discord</a></p>
        <p class="muted">Required OAuth scopes: <?= e(discordOauthConfig()['scope']) ?></p>
    <?php endif; ?>
</div>
<?php renderFooter(); ?>
