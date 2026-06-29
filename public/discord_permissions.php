<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';
require_once dirname(__DIR__) . '/app/lib/discord.php';

$catalogue = discordPermissionCatalogue();

$observed = [];
$recent = [];
try {
    $stmt = db()->prepare(
        'SELECT action_key,
                endpoint_template,
                required_permissions,
                permission_context,
                COUNT(*) AS seen_count,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failure_count,
                MAX(created_at_utc) AS last_seen_utc
           FROM discord_permission_audit
          WHERE clan_id = :clan_id
          GROUP BY action_key, endpoint_template, required_permissions, permission_context
          ORDER BY last_seen_utc DESC, action_key ASC'
    );
    $stmt->execute(['clan_id' => currentClanId()]);
    $observed = $stmt->fetchAll() ?: [];

    $stmt = db()->prepare(
        'SELECT action_key,
                http_method,
                endpoint_template,
                required_permissions,
                success,
                status_code,
                discord_error_code,
                error_message,
                created_at_utc
           FROM discord_permission_audit
          WHERE clan_id = :clan_id
          ORDER BY created_at_utc DESC
          LIMIT 100'
    );
    $stmt->execute(['clan_id' => currentClanId()]);
    $recent = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $observed = [];
    $recent = [];
}

$observedPermissionSet = [];
foreach ($observed as $row) {
    foreach (array_map('trim', explode(',', (string) ($row['required_permissions'] ?? ''))) as $permission) {
        if ($permission !== '') {
            $observedPermissionSet[$permission] = true;
        }
    }
}
ksort($observedPermissionSet);

$cataloguePermissionSet = [];
foreach ($catalogue as $entry) {
    foreach ((array) ($entry['permissions'] ?? []) as $permission) {
        $permission = trim((string) $permission);
        if ($permission !== '') {
            $cataloguePermissionSet[$permission] = true;
        }
    }
}
ksort($cataloguePermissionSet);

function permissionListText(array $permissions): string
{
    $permissions = array_values(array_filter(array_map('trim', $permissions), static fn (string $value): bool => $value !== ''));
    return $permissions === [] ? 'None logged' : implode(', ', $permissions);
}

renderHeader('Discord Permissions');
?>
<div class="card mb-16">
    <div class="actions space-between">
        <div>
            <h2 class="mt-0 mb-8">Discord Permission Audit</h2>
            <p class="muted mb-0">The app now records every Discord API action it attempts, the permissions that action is expected to need, and whether Discord accepted or rejected the request.</p>
        </div>
        <div class="actions">
            <a class="btn secondary" href="settings.php">Settings</a>
            <a class="btn secondary" href="post_schedule.php">Discord Publishing</a>
        </div>
    </div>
</div>

<div class="grid mb-16">
    <section class="card">
        <h3 class="mt-0">Observed Permissions</h3>
        <p class="muted">These are the permissions seen in real requests from this installation so far.</p>
        <div class="permission-chip-list">
            <?php foreach (array_keys($observedPermissionSet) as $permission): ?>
                <span class="permission-chip"><?= e($permission) ?></span>
            <?php endforeach; ?>
            <?php if ($observedPermissionSet === []): ?>
                <span class="muted">No Discord API actions have been logged yet. Run a Discord sync or load the dropdowns to populate this list.</span>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <h3 class="mt-0">Known App Permission Catalogue</h3>
        <p class="muted">This is the full permission set the current code can require when all optional features are used.</p>
        <div class="permission-chip-list">
            <?php foreach (array_keys($cataloguePermissionSet) as $permission): ?>
                <span class="permission-chip"><?= e($permission) ?></span>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="card mb-16">
    <h3 class="mt-0">Observed Actions</h3>
    <?php if ($observed === []): ?>
        <p class="muted">No observations have been logged yet.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Endpoint</th>
                        <th>Required Permissions</th>
                        <th>Seen</th>
                        <th>Failures</th>
                        <th>Last Seen UTC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($observed as $row): ?>
                        <tr>
                            <td><strong><?= e((string) $row['action_key']) ?></strong><br><span class="muted"><?= e((string) $row['permission_context']) ?></span></td>
                            <td><code><?= e((string) $row['endpoint_template']) ?></code></td>
                            <td><?= e((string) ($row['required_permissions'] !== '' ? $row['required_permissions'] : 'None')) ?></td>
                            <td><?= (int) $row['seen_count'] ?></td>
                            <td><?= (int) $row['failure_count'] ?></td>
                            <td><?= e((string) $row['last_seen_utc']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h3 class="mt-0">Recent Discord API Attempts</h3>
    <?php if ($recent === []): ?>
        <p class="muted">No recent attempts have been logged yet.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>UTC</th>
                        <th>Result</th>
                        <th>Action</th>
                        <th>Method</th>
                        <th>Endpoint</th>
                        <th>Permissions</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $row): ?>
                        <?php $ok = (int) ($row['success'] ?? 0) === 1; ?>
                        <tr>
                            <td><?= e((string) $row['created_at_utc']) ?></td>
                            <td><span class="status-pill <?= $ok ? 'status-ok' : 'status-fail' ?>"><?= $ok ? 'OK' : 'Failed' ?></span></td>
                            <td><?= e((string) $row['action_key']) ?></td>
                            <td><?= e((string) $row['http_method']) ?></td>
                            <td><code><?= e((string) $row['endpoint_template']) ?></code></td>
                            <td><?= e((string) ($row['required_permissions'] !== '' ? $row['required_permissions'] : 'None')) ?></td>
                            <td>
                                <?php if ($ok): ?>
                                    <span class="muted">—</span>
                                <?php else: ?>
                                    <strong><?= e((string) ($row['status_code'] ?? '')) ?></strong>
                                    <?php if (!empty($row['discord_error_code'])): ?>
                                        <span class="muted">Code <?= e((string) $row['discord_error_code']) ?></span>
                                    <?php endif; ?>
                                    <div class="muted"><?= e((string) ($row['error_message'] ?? '')) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php renderFooter(); ?>
