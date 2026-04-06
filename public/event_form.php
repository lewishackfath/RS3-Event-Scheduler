<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/discord.php';

$localDate = $formValues['event_date'] ?? '';
$localTime = $formValues['event_time'] ?? '';
$selectedHostName = (string) ($formValues['host_name'] ?? '');
$selectedHostId = (string) ($formValues['host_discord_user_id'] ?? '');
$channelFetchError = null;
$channels = [];

try {
    $channels = fetchGuildChannels((string) appConfig()['discord_oauth']['guild_id']);
} catch (Throwable $e) {
    $channelFetchError = $e->getMessage();
}
?>
<div class="card">
    <form method="post">
        <div class="grid">
            <div class="field">
                <label for="event_name">Event Name</label>
                <input type="text" id="event_name" name="event_name" value="<?= e($formValues['event_name'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="event_date">Event Date</label>
                <input type="date" id="event_date" name="event_date" value="<?= e($localDate) ?>" required>
            </div>
            <div class="field">
                <label for="event_time">Event Time (<?= e(appConfig()['clan']['timezone']) ?>)</label>
                <input type="time" id="event_time" name="event_time" value="<?= e($localTime) ?>" required>
            </div>
            <div class="field">
                <label for="duration_minutes">Duration Minutes</label>
                <input type="number" id="duration_minutes" name="duration_minutes" min="0" value="<?= e((string) ($formValues['duration_minutes'] ?? '')) ?>">
            </div>
            <div class="field">
                <label for="discord_channel_id">Discord Channel</label>
                <?php if ($channels !== []): ?>
                    <select id="discord_channel_id" name="discord_channel_id">
                        <option value="">Use default channel</option>
                        <?php foreach ($channels as $channel): ?>
                            <option value="<?= e($channel['id']) ?>" <?= (string) ($formValues['discord_channel_id'] ?? '') === (string) $channel['id'] ? 'selected' : '' ?>>
                                #<?= e($channel['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" id="discord_channel_id" name="discord_channel_id" value="<?= e((string) ($formValues['discord_channel_id'] ?? appConfig()['clan']['default_discord_channel_id'])) ?>">
                    <?php if ($channelFetchError): ?>
                        <div class="muted" style="margin-top:6px;">Could not load channels automatically: <?= e($channelFetchError) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="field field-full">
                <label for="host_search">Event Host</label>
                <input type="hidden" id="host_discord_user_id" name="host_discord_user_id" value="<?= e($selectedHostId) ?>">
                <input type="text" id="host_name" name="host_name" value="<?= e($selectedHostName) ?>" placeholder="Search Discord members by nickname or username" autocomplete="off">
                <div id="host-picker-results" class="search-results" hidden></div>
                <div class="muted" style="margin-top:6px;">Start typing to search members in the configured Discord server.</div>
            </div>
        </div>

        <div class="grid grid-2">
            <div class="field">
                <label><input type="checkbox" id="is_recurring_weekly" name="is_recurring_weekly" value="1" <?= !empty($formValues['is_recurring_weekly']) ? 'checked' : '' ?>> Weekly recurring event</label>
                <div class="muted" style="margin-top:6px;">Recurring events repeat each week on the same weekday and time.</div>
            </div>
            <div class="field" id="recurring-until-wrap" style="<?= !empty($formValues['is_recurring_weekly']) ? '' : 'display:none;' ?>">
                <label for="recurring_until_date">Recurring Until</label>
                <input type="date" id="recurring_until_date" name="recurring_until_date" value="<?= e((string) ($formValues['recurring_until_date'] ?? '')) ?>">
            </div>
        </div>

        <div class="field">
            <label for="image_url">Custom Image URL</label>
            <input type="url" id="image_url" name="image_url" value="<?= e($formValues['image_url'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="event_description">Notes / Description</label>
            <textarea id="event_description" name="event_description" rows="5"><?= e($formValues['event_description'] ?? '') ?></textarea>
        </div>
        <div class="field">
            <label><input type="checkbox" name="is_active" value="1" <?= !empty($formValues['is_active']) ? 'checked' : '' ?>> Active</label>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Save Event</button>
            <a class="btn secondary" href="index.php">Cancel</a>
        </div>
    </form>
</div>
<script>
(function () {
    const hostInput = document.getElementById('host_name');
    const hostIdInput = document.getElementById('host_discord_user_id');
    const results = document.getElementById('host-picker-results');
    const recurringToggle = document.getElementById('is_recurring_weekly');
    const recurringWrap = document.getElementById('recurring-until-wrap');
    let controller = null;

    function hideResults() {
        results.hidden = true;
        results.innerHTML = '';
    }

    function renderResults(items) {
        if (!items.length) {
            results.innerHTML = '<div class="search-result-item muted">No matching members found.</div>';
            results.hidden = false;
            return;
        }

        results.innerHTML = items.map(function (item) {
            const meta = [item.username];
            if (item.nick && item.nick !== item.display_name) meta.unshift('Nick: ' + item.nick);
            if (item.global_name && item.global_name !== item.display_name) meta.push('Global: ' + item.global_name);
            return '<button type="button" class="search-result-item" data-id="' + encodeURIComponent(item.id) + '" data-name="' + encodeURIComponent(item.display_name) + '">' +
                '<strong>' + item.display_name.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</strong><span class="muted">' + meta.join(' · ').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>' +
                '</button>';
        }).join('');
        results.hidden = false;
    }

    hostInput.addEventListener('input', function () {
        hostIdInput.value = '';
        const q = hostInput.value.trim();
        if (q.length < 2) {
            hideResults();
            return;
        }
        if (controller) controller.abort();
        controller = new AbortController();
        fetch('api/discord_lookup.php?type=members&q=' + encodeURIComponent(q), {signal: controller.signal})
            .then(function (r) { return r.json(); })
            .then(function (payload) {
                renderResults(Array.isArray(payload.items) ? payload.items : []);
            })
            .catch(function (err) {
                if (err.name !== 'AbortError') {
                    results.innerHTML = '<div class="search-result-item muted">Could not search Discord users.</div>';
                    results.hidden = false;
                }
            });
    });

    results.addEventListener('click', function (event) {
        const button = event.target.closest('.search-result-item[data-id]');
        if (!button) return;
        hostIdInput.value = decodeURIComponent(button.getAttribute('data-id'));
        hostInput.value = decodeURIComponent(button.getAttribute('data-name'));
        hideResults();
    });

    document.addEventListener('click', function (event) {
        if (!results.contains(event.target) && event.target !== hostInput) {
            hideResults();
        }
    });

    recurringToggle.addEventListener('change', function () {
        recurringWrap.style.display = recurringToggle.checked ? '' : 'none';
    });
})();
</script>
