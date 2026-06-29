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
$eventTimeSource = strtolower(trim((string) ($formValues['event_time_source'] ?? 'local')));
if (!in_array($eventTimeSource, ['local', 'utc'], true)) {
    $eventTimeSource = 'local';
}
$preferredRoles = is_array($formValues['preferred_roles'] ?? null) ? $formValues['preferred_roles'] : [];
$currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
$isEditPage = $currentPage === 'event_edit.php';
$isSeriesEvent = $isEditPage && isset($event) && trim((string) ($event['recurring_series_id'] ?? '')) !== '';
$recurrenceInterval = (int) ($formValues['recurrence_interval'] ?? 1);
if ($recurrenceInterval < 1) {
    $recurrenceInterval = 1;
}
$recurrenceUnit = strtolower(trim((string) ($formValues['recurrence_unit'] ?? 'weeks')));
if (!in_array($recurrenceUnit, ['days', 'weeks'], true)) {
    $recurrenceUnit = 'weeks';
}
$discordLookupToken = issueDiscordLookupToken();
$discordSettings = discordSettings();
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
                <input type="hidden" id="event_time_source" name="event_time_source" value="<?= e($eventTimeSource) ?>">
                <div class="muted" style="margin-top:6px;">Optional shortcut. Enter the UTC game time here and the local date/time fields will update automatically using the configured clan timezone.</div>
            </div>
            <div class="field">
                <label for="duration_minutes">Duration Minutes</label>
                <input type="number" id="duration_minutes" name="duration_minutes" min="0" value="<?= e((string) ($formValues['duration_minutes'] ?? '')) ?>">
            </div>
            <div class="field">
                <label for="discord_channel_picker">Discord Channel Override</label>
                <div class="inline-combobox" data-inline-combobox>
                    <input type="hidden" id="discord_channel_id" name="discord_channel_id" value="<?= e($selectedChannelId) ?>" data-selected-channel="<?= e($selectedChannelId) ?>">
                    <input type="text" id="discord_channel_picker" class="inline-combobox-input" placeholder="Use default daily channel" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="discord_channel_options">
                    <div id="discord_channel_options" class="inline-combobox-options" role="listbox" hidden></div>
                </div>
                <div id="channel-picker-status" class="muted" style="margin-top:6px;">Loading available channels…</div><div class="muted" style="margin-top:6px;">Leave blank to use the default daily event channel from Settings.</div>
            </div>
            <div class="field">
                <label for="discord_role_picker">Role to Mention in Daily Listing</label>
                <div class="inline-combobox" data-inline-combobox>
                    <input type="hidden" id="discord_mention_role_id" name="discord_mention_role_id" value="<?= e($selectedMentionRoleId) ?>" data-selected-role="<?= e($selectedMentionRoleId) ?>">
                    <input type="text" id="discord_role_picker" class="inline-combobox-input" placeholder="No role mention" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="discord_role_options">
                    <div id="discord_role_options" class="inline-combobox-options" role="listbox" hidden></div>
                </div>
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
                <label><input type="checkbox" id="is_recurring_weekly" name="is_recurring_weekly" value="1" <?= !empty($formValues['is_recurring_weekly']) ? 'checked' : '' ?>> Recurring event</label>
                <div class="muted" style="margin-top:6px;">The app creates each recurrence in advance so Discord posts and scheduled events can be edited instead of duplicated.</div>
            </div>
            <div class="field" id="recurrence-options-wrap" style="<?= !empty($formValues['is_recurring_weekly']) ? '' : 'display:none;' ?>">
                <label for="recurrence_interval">Repeat Every</label>
                <div style="display:grid;grid-template-columns:minmax(90px,120px) minmax(120px,1fr);gap:12px;align-items:center;">
                    <input type="number" id="recurrence_interval" name="recurrence_interval" min="1" max="365" step="1" value="<?= e((string) $recurrenceInterval) ?>">
                    <select id="recurrence_unit" name="recurrence_unit">
                        <option value="weeks" <?= $recurrenceUnit === 'weeks' ? 'selected' : '' ?>>Weeks</option>
                        <option value="days" <?= $recurrenceUnit === 'days' ? 'selected' : '' ?>>Days</option>
                    </select>
                </div>
                <div class="muted" style="margin-top:6px;">Examples: 1 week = weekly, 2 weeks = fortnightly, 3 days = every third day.</div>
            </div>
            <div class="field" id="recurring-until-wrap" style="<?= !empty($formValues['is_recurring_weekly']) ? '' : 'display:none;' ?>">
                <label for="recurring_until_date">Recurring Until</label>
                <input type="date" id="recurring_until_date" name="recurring_until_date" value="<?= e((string) ($formValues['recurring_until_date'] ?? '')) ?>">
                <div class="muted" style="margin-top:6px;">Required for recurring events. Occurrences are generated up to and including this date.</div>
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
            <input type="text" id="event_location" name="event_location" value="<?= e($formValues['event_location'] ?? '') ?>" placeholder="Optional. Falls back to the default event location from Settings if left blank.">
        </div>
        <div class="grid grid-2">
            <div class="field">
                <label><input type="checkbox" name="create_discord_scheduled_event" value="1" <?= !empty($formValues['create_discord_scheduled_event']) ? 'checked' : '' ?>> Create Native Discord Scheduled Event</label>
                <div class="muted" style="margin-top:6px;">Untick this for events that should still appear on the website and daily embed, but should not create a native Discord scheduled event.</div>
            </div>
            <div class="field">
                <label><input type="checkbox" name="create_voice_chat_for_event" value="1" <?= !empty($formValues['create_voice_chat_for_event']) ? 'checked' : '' ?>> Create Voice Chat for Event</label>
                <div class="muted" style="margin-top:6px;">When enabled, the Discord sync will create a temporary voice channel roughly <?= (int) $discordSettings['event_voice_create_before_minutes'] ?> minutes before the event starts. Voice access still depends on the native Discord scheduled event subscriber list.</div>
            </div>
        </div>
        <div class="field">
            <label for="image_url">Event Poster Image URL</label>
            <input type="url" id="image_url" name="image_url" value="<?= e($formValues['image_url'] ?? '') ?>">
            <div class="muted" style="margin-top:6px;">Shown in the poster gallery on the schedule page and as the full-size image at the bottom of the Discord embed.</div>
        </div>
        <div class="field">
            <label for="thumbnail_url">Thumbnail Image URL</label>
            <input type="url" id="thumbnail_url" name="thumbnail_url" value="<?= e($formValues['thumbnail_url'] ?? '') ?>">
            <div class="muted" style="margin-top:6px;">Optional. Shown at the top of the website event card and as the small thumbnail in the Discord embed.</div>
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
    const recurrenceOptionsWrap = document.getElementById('recurrence-options-wrap');
    const channelValueInput = document.getElementById('discord_channel_id');
    const channelPickerInput = document.getElementById('discord_channel_picker');
    const channelOptionsMenu = document.getElementById('discord_channel_options');
    const channelStatus = document.getElementById('channel-picker-status');
    const roleValueInput = document.getElementById('discord_mention_role_id');
    const rolePickerInput = document.getElementById('discord_role_picker');
    const roleOptionsMenu = document.getElementById('discord_role_options');
    const roleStatus = document.getElementById('role-picker-status');
    const eventDateInput = document.getElementById('event_date');
    const eventTimeInput = document.getElementById('event_time');
    const utcStartInput = document.getElementById('event_start_utc_input');
    const eventTimeSourceInput = document.getElementById('event_time_source');
    const preferredRolesList = document.getElementById('preferred-roles-list');
    const preferredRoleTemplate = document.getElementById('preferred-role-template');
    const addPreferredRoleButton = document.getElementById('add-preferred-role');
    const clanTimezone = <?= json_encode(appConfig()['clan']['timezone']) ?>;
    const discordLookupToken = <?= json_encode($discordLookupToken) ?>;
    let controller = null;
    let isSyncingTimeFields = false;
    let channelOptions = [];
    let roleOptions = [];

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


    let timeConversionRequestId = 0;

    function markTimeSource(source) {
        if (eventTimeSourceInput) {
            eventTimeSourceInput.value = source === 'utc' ? 'utc' : 'local';
        }
    }

    function convertTime(params) {
        const requestId = ++timeConversionRequestId;
        const query = new URLSearchParams(params);
        return fetch('api/timezone_convert.php?' + query.toString())
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data, requestId: requestId };
                });
            })
            .then(function (payload) {
                if (payload.requestId !== timeConversionRequestId) {
                    return null;
                }
                if (!payload.ok) {
                    throw new Error(payload.data && payload.data.error ? payload.data.error : 'Could not convert time.');
                }
                return payload.data;
            });
    }

    function syncUtcFromLocal() {
        if (!eventDateInput || !eventTimeInput || !utcStartInput) return;
        if (!eventDateInput.value || !eventTimeInput.value) return;
        if (isSyncingTimeFields) return;

        markTimeSource('local');
        convertTime({
            direction: 'local_to_utc',
            date: eventDateInput.value,
            time: eventTimeInput.value,
        }).then(function (data) {
            if (!data || !data.utc_input) return;
            isSyncingTimeFields = true;
            try {
                utcStartInput.value = data.utc_input;
            } finally {
                isSyncingTimeFields = false;
            }
        }).catch(function () {
            // Saving still remains safe: when the local fields were edited, PHP
            // converts them with DateTimeZone on submit instead of trusting this
            // mirrored UTC display field.
        });
    }

    function syncLocalFromUtc() {
        if (!eventDateInput || !eventTimeInput || !utcStartInput) return;
        if (!utcStartInput.value) return;
        if (isSyncingTimeFields) return;

        markTimeSource('utc');
        convertTime({
            direction: 'utc_to_local',
            utc: utcStartInput.value,
        }).then(function (data) {
            if (!data || !data.local_date || !data.local_time) return;
            isSyncingTimeFields = true;
            try {
                eventDateInput.value = data.local_date;
                eventTimeInput.value = data.local_time;
            } finally {
                isSyncingTimeFields = false;
            }
        }).catch(function () {});
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

    function fetchJsonWithStatus(url) {
        return fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Discord-Lookup-Token': discordLookupToken
            }
        }).then(function (response) {
            return response.text().then(function (text) {
                let data = {};
                try {
                    data = text ? JSON.parse(text) : {};
                } catch (err) {
                    const excerpt = text ? text.replace(/\s+/g, ' ').trim().slice(0, 260) : '';
                    const suffix = excerpt ? ' Non-JSON response: ' + excerpt : ' Empty response body.';
                    throw new Error('Discord lookup endpoint returned HTTP ' + response.status + '.' + suffix);
                }
                return { ok: response.ok, status: response.status, data: data, redirected: response.redirected };
            });
        }).then(function (payload) {
            if (!payload.ok) {
                throw new Error(payload.data && payload.data.error ? payload.data.error : 'Discord lookup failed with HTTP ' + payload.status + '.');
            }
            return payload.data;
        });
    }

    function channelTypeLabel(type) {
        switch (Number(type)) {
            case 0: return 'Text';
            case 2: return 'Voice';
            case 4: return 'Category';
            case 5: return 'Announcement';
            default: return 'Channel';
        }
    }

    function normaliseSearchText(value) {
        return String(value || '').toLowerCase().replace(/[#@]/g, '').trim();
    }

    function createInlineCombobox(config) {
        const valueInput = config.valueInput;
        const textInput = config.textInput;
        const menu = config.menu;
        const emptyLabel = config.emptyLabel || 'None';
        let options = [];
        let activeIndex = -1;
        let closeTimer = null;

        function currentValue() {
            return valueInput ? String(valueInput.value || '') : '';
        }

        function selectedOption() {
            const selected = currentValue();
            const match = options.find(function (option) {
                return String(option.value || '') === selected;
            });
            return match || options[0] || { value: '', label: emptyLabel, search: emptyLabel };
        }

        function setExpanded(expanded) {
            if (textInput) {
                textInput.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }
        }

        function closeMenu() {
            if (!menu) return;
            menu.hidden = true;
            menu.innerHTML = '';
            activeIndex = -1;
            setExpanded(false);
        }

        function optionMatches(option, query) {
            if (query === '') return true;
            return normaliseSearchText(option.search || option.label || option.value).indexOf(query) !== -1;
        }

        function visibleOptions(query) {
            const normalisedQuery = normaliseSearchText(query);
            return options.filter(function (option) {
                return optionMatches(option, normalisedQuery);
            });
        }

        function syncTextFromValue() {
            if (!textInput) return;
            const selected = selectedOption();
            textInput.value = selected.label || emptyLabel;
        }

        function selectOption(option) {
            if (!option || option.disabled) return;
            if (valueInput) {
                valueInput.value = String(option.value || '');
                valueInput.setAttribute('data-selected-value', valueInput.value);
            }
            if (textInput) {
                textInput.value = option.label || emptyLabel;
            }
            closeMenu();
        }

        function renderMenu(query) {
            if (!menu || !textInput) return;
            const matches = visibleOptions(query);
            menu.innerHTML = '';

            if (!matches.length) {
                const empty = document.createElement('div');
                empty.className = 'inline-combobox-empty muted';
                empty.textContent = 'No matching options found.';
                menu.appendChild(empty);
                menu.hidden = false;
                setExpanded(true);
                return;
            }

            matches.forEach(function (option, index) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'inline-combobox-option';
                button.setAttribute('role', 'option');
                button.setAttribute('data-option-index', String(index));
                button.textContent = option.label || emptyLabel;
                if (String(option.value || '') === currentValue()) {
                    button.classList.add('is-selected');
                    button.setAttribute('aria-selected', 'true');
                    activeIndex = index;
                }
                button.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    selectOption(option);
                });
                menu.appendChild(button);
            });

            if (activeIndex < 0 || activeIndex >= matches.length) {
                activeIndex = 0;
            }
            updateActiveOption(matches);
            menu.hidden = false;
            setExpanded(true);
        }

        function updateActiveOption(matches) {
            if (!menu) return;
            const buttons = Array.prototype.slice.call(menu.querySelectorAll('.inline-combobox-option'));
            buttons.forEach(function (button, index) {
                const isActive = index === activeIndex;
                button.classList.toggle('is-active', isActive);
                if (isActive) {
                    button.setAttribute('aria-current', 'true');
                } else {
                    button.removeAttribute('aria-current');
                }
            });
            if (buttons[activeIndex]) {
                buttons[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        if (textInput) {
            textInput.addEventListener('focus', function () {
                window.clearTimeout(closeTimer);
                textInput.select();
                renderMenu('');
            });

            textInput.addEventListener('input', function () {
                activeIndex = -1;
                renderMenu(textInput.value);
            });

            textInput.addEventListener('blur', function () {
                closeTimer = window.setTimeout(function () {
                    syncTextFromValue();
                    closeMenu();
                }, 120);
            });

            textInput.addEventListener('keydown', function (event) {
                const matches = visibleOptions(textInput.value);
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    if (menu.hidden) renderMenu(textInput.value);
                    activeIndex = Math.min(matches.length - 1, activeIndex + 1);
                    updateActiveOption(matches);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    if (menu.hidden) renderMenu(textInput.value);
                    activeIndex = Math.max(0, activeIndex - 1);
                    updateActiveOption(matches);
                } else if (event.key === 'Enter') {
                    if (!menu.hidden && matches[activeIndex]) {
                        event.preventDefault();
                        selectOption(matches[activeIndex]);
                    }
                } else if (event.key === 'Escape') {
                    syncTextFromValue();
                    closeMenu();
                }
            });
        }

        return {
            setOptions: function (newOptions) {
                options = Array.isArray(newOptions) ? newOptions.slice() : [];
                const selected = currentValue();
                if (selected !== '' && !options.some(function (option) { return String(option.value || '') === selected; })) {
                    options.push({ value: selected, label: selected + ' · previously selected', search: selected });
                }
                syncTextFromValue();
                closeMenu();
            },
            refresh: function () {
                syncTextFromValue();
            }
        };
    }

    const channelCombobox = createInlineCombobox({
        valueInput: channelValueInput,
        textInput: channelPickerInput,
        menu: channelOptionsMenu,
        emptyLabel: 'Use default daily channel'
    });
    const roleCombobox = createInlineCombobox({
        valueInput: roleValueInput,
        textInput: rolePickerInput,
        menu: roleOptionsMenu,
        emptyLabel: 'No role mention'
    });

    function loadMentionRoles() {
        fetchJsonWithStatus('api/discord_lookup.php?type=roles')
            .then(function (data) {
                const roles = Array.isArray(data.items) ? data.items : [];
                roleOptions = [{ value: '', label: 'No role mention', search: 'no role mention none' }].concat(roles.map(function (role) {
                    role = role || {};
                    const name = role.name ? String(role.name) : String(role.id || '');
                    return {
                        value: String(role.id || ''),
                        label: '@' + name,
                        search: [name, role.id || '', role.mentionable ? 'mentionable' : 'not mentionable'].join(' ')
                    };
                }).filter(function (role) { return role.value !== ''; }));
                roleCombobox.setOptions(roleOptions);
                if (roleStatus) roleStatus.textContent = roles.length ? 'Loaded ' + roles.length + ' roles. Type in the field to search.' : 'No selectable roles found.';
            })
            .catch(function (err) {
                roleOptions = [{ value: '', label: 'No role mention', search: 'no role mention none' }];
                roleCombobox.setOptions(roleOptions);
                if (roleStatus) roleStatus.textContent = 'Could not load roles automatically: ' + (err.message || 'Discord lookup failed.');
            });
    }

    function loadChannels() {
        fetchJsonWithStatus('api/discord_lookup.php?type=channels')
            .then(function (data) {
                const items = Array.isArray(data.items) ? data.items : [];
                channelOptions = [{ value: '', label: 'Use default daily channel', search: 'default daily channel' }].concat(items.map(function (item) {
                    item = item || {};
                    const typeLabel = channelTypeLabel(item.type);
                    const name = item.name ? String(item.name) : String(item.id || '');
                    return {
                        value: String(item.id || ''),
                        label: '#' + name + ' · ' + typeLabel,
                        search: [name, item.id || '', typeLabel, item.parent_id || ''].join(' ')
                    };
                }).filter(function (channel) { return channel.value !== ''; }));
                channelCombobox.setOptions(channelOptions);
                if (channelStatus) channelStatus.textContent = items.length ? 'Loaded ' + items.length + ' channels. Type in the field to search.' : 'No channels found. You can still use the default channel.';
            })
            .catch(function (err) {
                channelOptions = [{ value: '', label: 'Use default daily channel', search: 'default daily channel' }];
                channelCombobox.setOptions(channelOptions);
                if (channelStatus) channelStatus.textContent = 'Could not load channels automatically: ' + err.message;
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
        fetch('api/discord_lookup.php?type=members&q=' + encodeURIComponent(q), {
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Discord-Lookup-Token': discordLookupToken
            }
        })
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
        const displayValue = recurringToggle.checked ? '' : 'none';
        recurringWrap.style.display = displayValue;
        if (recurrenceOptionsWrap) {
            recurrenceOptionsWrap.style.display = displayValue;
        }
    });

    if (utcStartInput && utcStartInput.value) {
        syncLocalFromUtc();
    } else {
        syncUtcFromLocal();
    }

    if (channelValueInput && channelPickerInput) {
        loadChannels();
    }
    if (roleValueInput && rolePickerInput) {
        loadMentionRoles();
    }
})();
</script>
