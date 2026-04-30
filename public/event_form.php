<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/discord.php';

$localDate = $formValues['event_date'] ?? '';
$localTime = $formValues['event_time'] ?? '';
$selectedHostName = (string) ($formValues['host_name'] ?? '');
$selectedHostId = (string) ($formValues['host_discord_user_id'] ?? '');
$selectedChannelId = (string) ($formValues['discord_channel_id'] ?? '');
$selectedMentionRoleId = (string) ($formValues['discord_mention_role_id'] ?? '');
$utcStartInput = (string) ($formValues['event_start_utc_input'] ?? '');
$preferredRoles = is_array($formValues['preferred_roles'] ?? null) ? $formValues['preferred_roles'] : [];
$currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
$isEditPage = $currentPage === 'event_edit.php';
$isSeriesEvent = $isEditPage && isset($event) && trim((string) ($event['recurring_series_id'] ?? '')) !== '';
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
                <div class="muted" style="margin-top:6px;">Click the field to open the calendar picker.</div>
            </div>
            <div class="field">
                <label for="event_time">Event Time (<?= e(appConfig()['clan']['timezone']) ?>)</label>
                <input type="time" id="event_time" name="event_time" value="<?= e($localTime) ?>" required>
            </div>
            <div class="field">
                <label for="event_start_utc_input">Start Time in UTC (Game Time)</label>
                <input type="datetime-local" id="event_start_utc_input" name="event_start_utc_input" value="<?= e($utcStartInput) ?>">
                <div class="muted" style="margin-top:6px;">Optional shortcut. Enter the UTC game time here and the local date/time fields will update automatically.</div>
            </div>
            <div class="field">
                <label for="duration_minutes">Duration Minutes</label>
                <input type="number" id="duration_minutes" name="duration_minutes" min="0" value="<?= e((string) ($formValues['duration_minutes'] ?? '')) ?>">
            </div>
            <div class="field">
                <label for="discord_channel_id">Discord Channel Override</label>
                <select id="discord_channel_id" name="discord_channel_id" data-selected-channel="<?= e($selectedChannelId) ?>">
                    <option value="">Use default daily channel</option>
                </select>
                <div id="channel-picker-status" class="muted" style="margin-top:6px;">Loading available channels…</div><div class="muted" style="margin-top:6px;">Leave blank to use <code>DISCORD_DAILY_EVENT_CHANNEL_ID</code> from your .env file.</div>
            </div>
            <div class="field">
                <label for="discord_mention_role_id">Role to Mention in Daily Listing</label>
                <select id="discord_mention_role_id" name="discord_mention_role_id" data-selected-role="<?= e($selectedMentionRoleId) ?>">
                    <option value="">No role mention</option>
                </select>
                <div id="role-picker-status" class="muted" style="margin-top:6px;">Loading available roles…</div>
                <div class="muted" style="margin-top:6px;">Optional. The selected Discord role will be mentioned in the daily event listing.</div>
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
                <div class="muted" style="margin-top:6px;">Required for weekly recurring events. The app now creates each weekly occurrence in advance.</div>
            </div>
        </div>

        <?php if ($isSeriesEvent): ?>
            <div class="field">
                <label>When saving this recurring event, apply changes to:</label>
                <label><input type="radio" name="recurring_edit_scope" value="single" <?= (($formValues['recurring_edit_scope'] ?? 'single') === 'single') ? 'checked' : '' ?>> This event only</label>
                <label><input type="radio" name="recurring_edit_scope" value="future" <?= (($formValues['recurring_edit_scope'] ?? '') === 'future') ? 'checked' : '' ?>> This event and future events</label>
                <label><input type="radio" name="recurring_edit_scope" value="all" <?= (($formValues['recurring_edit_scope'] ?? '') === 'all') ? 'checked' : '' ?>> Entire series</label>
                <div class="muted" style="margin-top:6px;">Selecting “this event only” detaches this occurrence from the recurring series so it can be customised on its own.</div>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="event_location">Location</label>
            <input type="text" id="event_location" name="event_location" value="<?= e($formValues['event_location'] ?? '') ?>" placeholder="Optional. Falls back to DISCORD_EVENT_LOCATION_DEFAULT if left blank.">
        </div>
        <div class="field">
            <label><input type="checkbox" name="create_voice_chat_for_event" value="1" <?= !empty($formValues['create_voice_chat_for_event']) ? 'checked' : '' ?>> Create Voice Chat for Event</label>
            <div class="muted" style="margin-top:6px;">When enabled, the Discord sync will create a temporary voice channel roughly <?= (int) appConfig()['discord']['event_voice_create_before_minutes'] ?> minutes before the event starts, allow everyone to view/join it, and only subscribed event attendees will be able to speak.</div>
        </div>
        <div class="field">
            <label for="image_url">Custom Image URL</label>
            <input type="url" id="image_url" name="image_url" value="<?= e($formValues['image_url'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="event_description">Notes / Description</label>
            <textarea id="event_description" name="event_description" rows="5"><?= e($formValues['event_description'] ?? '') ?></textarea>
        </div>

        <div class="field field-full">
            <label>Preferred Roles / Reactions</label>
            <div id="preferred-roles-list">
                <?php foreach ($preferredRoles as $index => $preferredRole): ?>
                    <div class="preferred-role-row" data-role-row style="display:grid;grid-template-columns:minmax(180px,2fr) minmax(100px,1fr) auto;gap:12px;align-items:end;margin-bottom:12px;">
                        <div>
                            <label for="preferred_role_name_<?= (int) $index ?>">Role Name</label>
                            <input type="text" id="preferred_role_name_<?= (int) $index ?>" name="preferred_roles[<?= (int) $index ?>][role_name]" value="<?= e((string) ($preferredRole['role_name'] ?? '')) ?>" placeholder="e.g. Base/Tank">
                        </div>
                        <div>
                            <label for="preferred_role_emoji_<?= (int) $index ?>">Reaction Emoji</label>
                            <input type="text" id="preferred_role_emoji_<?= (int) $index ?>" name="preferred_roles[<?= (int) $index ?>][reaction_emoji]" value="<?= e((string) ($preferredRole['reaction_emoji'] ?? '')) ?>" placeholder="e.g. 🛡️">
                        </div>
                        <div>
                            <button class="btn secondary" type="button" data-remove-role>Remove</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="btn secondary" type="button" id="add-preferred-role">Add Role Option</button>
            <div class="muted" style="margin-top:6px;">These options will be shown on the daily Discord event post, and the matching reactions will be added to the message for members to choose from.</div>
            <template id="preferred-role-template">
                <div class="preferred-role-row" data-role-row style="display:grid;grid-template-columns:minmax(180px,2fr) minmax(100px,1fr) auto;gap:12px;align-items:end;margin-bottom:12px;">
                    <div>
                        <label>Role Name</label>
                        <input type="text" data-role-name placeholder="e.g. Woodcutting">
                    </div>
                    <div>
                        <label>Reaction Emoji</label>
                        <input type="text" data-role-emoji placeholder="e.g. 🪓">
                    </div>
                    <div>
                        <button class="btn secondary" type="button" data-remove-role>Remove</button>
                    </div>
                </div>
            </template>
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
    const channelSelect = document.getElementById('discord_channel_id');
    const channelStatus = document.getElementById('channel-picker-status');
    const roleSelect = document.getElementById('discord_mention_role_id');
    const roleStatus = document.getElementById('role-picker-status');
    const eventDateInput = document.getElementById('event_date');
    const eventTimeInput = document.getElementById('event_time');
    const utcStartInput = document.getElementById('event_start_utc_input');
    const preferredRolesList = document.getElementById('preferred-roles-list');
    const preferredRoleTemplate = document.getElementById('preferred-role-template');
    const addPreferredRoleButton = document.getElementById('add-preferred-role');
    const clanTimezone = <?= json_encode(appConfig()['clan']['timezone']) ?>;
    let controller = null;
    let isSyncingTimeFields = false;

    if (eventDateInput) {
        ['focus', 'click'].forEach(function (evt) {
            eventDateInput.addEventListener(evt, function () {
                if (typeof eventDateInput.showPicker === 'function') {
                    try { eventDateInput.showPicker(); } catch (e) {}
                }
            });
        });
        eventDateInput.addEventListener('change', syncUtcFromLocal);
    }

    if (eventTimeInput) {
        eventTimeInput.addEventListener('change', syncUtcFromLocal);
        eventTimeInput.addEventListener('input', syncUtcFromLocal);
    }

    if (utcStartInput) {
        utcStartInput.addEventListener('change', syncLocalFromUtc);
        utcStartInput.addEventListener('input', syncLocalFromUtc);
    }


    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function getTimeZoneOffsetMinutes(date, timeZone) {
        const formatter = new Intl.DateTimeFormat('en-CA', {
            timeZone: timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        });
        const parts = formatter.formatToParts(date);
        const map = {};
        parts.forEach(function (part) {
            if (part.type !== 'literal') {
                map[part.type] = part.value;
            }
        });
        const asUtc = Date.UTC(
            Number(map.year),
            Number(map.month) - 1,
            Number(map.day),
            Number(map.hour),
            Number(map.minute),
            Number(map.second)
        );
        return Math.round((asUtc - date.getTime()) / 60000);
    }

    function syncUtcFromLocal() {
        if (!eventDateInput || !eventTimeInput || !utcStartInput) return;
        if (!eventDateInput.value || !eventTimeInput.value) return;
        if (isSyncingTimeFields) return;

        isSyncingTimeFields = true;
        try {
            const parts = eventDateInput.value.split('-').map(Number);
            const timeParts = eventTimeInput.value.split(':').map(Number);
            if (parts.length !== 3 || timeParts.length < 2) return;

            const utcGuessMs = Date.UTC(parts[0], parts[1] - 1, parts[2], timeParts[0], timeParts[1], 0);
            const offsetMinutes = getTimeZoneOffsetMinutes(new Date(utcGuessMs), clanTimezone);
            const utcDate = new Date(utcGuessMs - (offsetMinutes * 60000));
            utcStartInput.value = utcDate.getUTCFullYear() + '-' + pad(utcDate.getUTCMonth() + 1) + '-' + pad(utcDate.getUTCDate()) + 'T' + pad(utcDate.getUTCHours()) + ':' + pad(utcDate.getUTCMinutes());
        } finally {
            isSyncingTimeFields = false;
        }
    }

    function syncLocalFromUtc() {
        if (!eventDateInput || !eventTimeInput || !utcStartInput) return;
        if (!utcStartInput.value) return;
        if (isSyncingTimeFields) return;

        const dt = new Date(utcStartInput.value + ':00Z');
        if (Number.isNaN(dt.getTime())) return;

        isSyncingTimeFields = true;
        try {
            const formatter = new Intl.DateTimeFormat('en-CA', {
                timeZone: clanTimezone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            });
            const parts = formatter.formatToParts(dt);
            const map = {};
            parts.forEach(function (part) {
                if (part.type !== 'literal') {
                    map[part.type] = part.value;
                }
            });
            eventDateInput.value = map.year + '-' + map.month + '-' + map.day;
            eventTimeInput.value = map.hour + ':' + map.minute;
        } finally {
            isSyncingTimeFields = false;
        }
    }


    function reindexPreferredRoleRows() {
        if (!preferredRolesList) return;
        Array.prototype.forEach.call(preferredRolesList.querySelectorAll('[data-role-row]'), function (row, index) {
            const roleInput = row.querySelector('[data-role-name], input[name*="[role_name]"]');
            const emojiInput = row.querySelector('[data-role-emoji], input[name*="[reaction_emoji]"]');
            const roleLabel = row.querySelector('label');
            const emojiLabel = row.querySelectorAll('label')[1];
            const roleId = 'preferred_role_name_' + index;
            const emojiId = 'preferred_role_emoji_' + index;
            if (roleInput) {
                roleInput.name = 'preferred_roles[' + index + '][role_name]';
                roleInput.id = roleId;
            }
            if (emojiInput) {
                emojiInput.name = 'preferred_roles[' + index + '][reaction_emoji]';
                emojiInput.id = emojiId;
            }
            if (roleLabel) roleLabel.setAttribute('for', roleId);
            if (emojiLabel) emojiLabel.setAttribute('for', emojiId);
        });
    }

    function addPreferredRoleRow(roleName, emoji) {
        if (!preferredRolesList || !preferredRoleTemplate) return;
        const fragment = preferredRoleTemplate.content.cloneNode(true);
        const row = fragment.querySelector('[data-role-row]');
        const roleInput = fragment.querySelector('[data-role-name]');
        const emojiInput = fragment.querySelector('[data-role-emoji]');
        if (roleInput) roleInput.value = roleName || '';
        if (emojiInput) emojiInput.value = emoji || '';
        preferredRolesList.appendChild(fragment);
        reindexPreferredRoleRows();
        if (row && roleInput && !roleName) roleInput.focus();
    }

    if (preferredRolesList) {
        preferredRolesList.addEventListener('click', function (event) {
            const button = event.target.closest('[data-remove-role]');
            if (!button) return;
            const row = button.closest('[data-role-row]');
            if (row) {
                row.remove();
                reindexPreferredRoleRows();
            }
        });
        reindexPreferredRoleRows();
    }

    if (addPreferredRoleButton) {
        addPreferredRoleButton.addEventListener('click', function () {
            addPreferredRoleRow('', '');
        });
    }

    function hideResults() {
        results.hidden = true;
        results.innerHTML = '';
    }

    function escapeHtml(value) {
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
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
                '<strong>' + escapeHtml(item.display_name) + '</strong><span class="muted">' + escapeHtml(meta.join(' · ')) + '</span>' +
                '</button>';
        }).join('');
        results.hidden = false;
    }

    function loadMentionRoles() {
        const selectedRole = roleSelect ? (roleSelect.getAttribute('data-selected-role') || '') : '';
        fetch('api/discord_lookup.php?type=roles')
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!roleSelect) return;
                const roles = Array.isArray(data.items) ? data.items : [];
                roles.forEach(function (role) {
                    if (!role || !role.id) return;
                    const option = document.createElement('option');
                    option.value = role.id;
                    option.textContent = '@' + (role.name || role.id);
                    if (String(role.id) === selectedRole) option.selected = true;
                    roleSelect.appendChild(option);
                });
                if (roleStatus) roleStatus.textContent = roles.length ? 'Loaded ' + roles.length + ' roles.' : 'No selectable roles found.';
            })
            .catch(function () {
                if (roleStatus) roleStatus.textContent = 'Could not load roles. You can still save the event without a role mention.';
            });
    }

    function loadChannels() {
        const selected = channelSelect.getAttribute('data-selected-channel') || '';
        fetch('api/discord_lookup.php?type=channels')
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (payload) {
                if (!payload.ok) {
                    throw new Error(payload.data && payload.data.error ? payload.data.error : 'Unable to load channels.');
                }
                const items = Array.isArray(payload.data.items) ? payload.data.items : [];
                items.forEach(function (item) {
                    const opt = document.createElement('option');
                    opt.value = item.id;
                    opt.textContent = '#' + item.name;
                    if (selected !== '' && selected === String(item.id)) {
                        opt.selected = true;
                    }
                    channelSelect.appendChild(opt);
                });
                channelStatus.textContent = items.length ? 'Loaded ' + items.length + ' channels.' : 'No channels found. You can still use the default channel.';
            })
            .catch(function (err) {
                const opt = document.createElement('option');
                opt.value = selected;
                opt.textContent = selected !== '' ? selected : 'Use default daily channel';
                if (selected !== '') {
                    opt.selected = true;
                    channelSelect.appendChild(opt);
                }
                channelStatus.textContent = 'Could not load channels automatically: ' + err.message;
            });
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
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (payload) {
                if (!payload.ok) {
                    throw new Error(payload.data && payload.data.error ? payload.data.error : 'Could not search Discord users.');
                }
                renderResults(Array.isArray(payload.data.items) ? payload.data.items : []);
            })
            .catch(function (err) {
                if (err.name !== 'AbortError') {
                    results.innerHTML = '<div class="search-result-item muted">' + escapeHtml(err.message || 'Could not search Discord users.') + '</div>';
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

    if (utcStartInput && utcStartInput.value) {
        syncLocalFromUtc();
    } else {
        syncUtcFromLocal();
    }

    if (channelSelect) {
        loadChannels();
    }
    if (roleSelect) {
        loadMentionRoles();
    }
})();
</script>
