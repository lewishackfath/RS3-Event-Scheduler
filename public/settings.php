<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials.php';
require_once dirname(__DIR__) . '/app/lib/discord.php';

$settingsRepo = new SettingsRepository();
$definitions = $settingsRepo->definitions();
$values = $settingsRepo->getValues();
$discordLookupToken = issueDiscordLookupToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $user = currentUser();
        $settingsRepo->saveValues($_POST, is_array($user) ? (string) ($user['discord_user_id'] ?? '') : null);
        setFlash('success', 'Settings updated successfully.');
        redirect('settings.php');
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('settings.php');
    }
}

$sections = [];
foreach ($definitions as $key => $definition) {
    $section = (string) ($definition['section'] ?? 'Settings');
    $sections[$section][$key] = $definition;
}

function settingValueForInput(array $values, string $key): string
{
    $value = $values[$key] ?? '';
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    return (string) $value;
}

renderHeader('Settings');
?>
<div class="card mb-16">
    <div class="actions space-between">
        <div>
            <h2 class="mt-0 mb-8">Event & Discord Settings</h2>
            <p class="muted mb-0">These settings override the matching .env defaults for this clan. Leave channel fields blank if you want to keep the fallback unset.</p>
        </div>
        <div class="actions">
            <a class="btn secondary" href="discord_permissions.php">Discord Permissions</a>
            <a class="btn secondary" href="post_schedule.php">Discord Publishing</a>
        </div>
    </div>
</div>

<form method="post" class="settings-form">
    <?php foreach ($sections as $sectionName => $sectionDefinitions): ?>
        <section class="card mb-16 settings-section">
            <h3 class="mt-0"><?= e($sectionName) ?></h3>
            <div class="grid">
                <?php foreach ($sectionDefinitions as $key => $definition): ?>
                    <?php
                    $type = (string) ($definition['type'] ?? 'text');
                    $label = (string) ($definition['label'] ?? $key);
                    $value = settingValueForInput($values, $key);
                    ?>
                    <div class="field <?= $type === 'text' ? 'field-full' : '' ?>">
                        <?php if ($type === 'bool'): ?>
                            <label>
                                <input type="checkbox" name="<?= e($key) ?>" value="1" <?= !empty($values[$key]) ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                        <?php elseif ($type === 'int'): ?>
                            <label for="<?= e($key) ?>"><?= e($label) ?></label>
                            <input
                                type="number"
                                id="<?= e($key) ?>"
                                name="<?= e($key) ?>"
                                value="<?= e($value) ?>"
                                min="<?= e((string) ($definition['min'] ?? 0)) ?>"
                                max="<?= e((string) ($definition['max'] ?? 999999)) ?>"
                                step="1"
                            >
                        <?php elseif ($type === 'channel'): ?>
                            <label for="<?= e($key) ?>_picker"><?= e($label) ?></label>
                            <div class="inline-combobox" data-settings-channel-combobox>
                                <input type="hidden" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>">
                                <input
                                    type="text"
                                    id="<?= e($key) ?>_picker"
                                    class="inline-combobox-input"
                                    placeholder="No channel selected"
                                    autocomplete="off"
                                    spellcheck="false"
                                    role="combobox"
                                    aria-autocomplete="list"
                                    aria-expanded="false"
                                    aria-controls="<?= e($key) ?>_options"
                                    data-channel-display-for="<?= e($key) ?>"
                                >
                                <div id="<?= e($key) ?>_options" class="inline-combobox-options" role="listbox" hidden></div>
                            </div>
                            <div class="muted mt-6" data-channel-status-for="<?= e($key) ?>">Loading channels…</div>
                        <?php else: ?>
                            <label for="<?= e($key) ?>"><?= e($label) ?></label>
                            <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="actions">
        <button class="btn" type="submit">Save Settings</button>
        <a class="btn secondary" href="index.php">Back to Schedule</a>
    </div>
</form>

<script>
(function () {
    const discordLookupToken = <?= json_encode($discordLookupToken) ?>;
    const channelControls = Array.prototype.map.call(document.querySelectorAll('[data-settings-channel-combobox]'), function (wrap) {
        return {
            wrap: wrap,
            valueInput: wrap.querySelector('input[type="hidden"]'),
            textInput: wrap.querySelector('.inline-combobox-input'),
            menu: wrap.querySelector('.inline-combobox-options'),
            status: document.querySelector('[data-channel-status-for="' + (wrap.querySelector('input[type="hidden"]') || {}).id + '"]'),
            options: [],
            activeIndex: -1,
            closeTimer: null
        };
    });

    function escapeHtml(value) {
        return String(value).replace(/[&<>'"]/g, function (char) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char];
        });
    }

    function channelTypeLabel(type) {
        const map = {0: 'Text', 2: 'Voice', 4: 'Category', 5: 'Announcement'};
        return map[Number(type)] || 'Channel';
    }

    function currentOption(control) {
        const selected = control.valueInput ? String(control.valueInput.value || '') : '';
        return control.options.find(function (option) { return String(option.value || '') === selected; }) || null;
    }

    function visibleOptions(control, query) {
        const q = String(query || '').trim().toLowerCase();
        if (!q) return control.options.slice(0, 80);
        return control.options.filter(function (option) {
            return String(option.search || option.label || '').toLowerCase().indexOf(q) !== -1;
        }).slice(0, 80);
    }

    function closeMenu(control) {
        if (control.menu) control.menu.hidden = true;
        if (control.textInput) control.textInput.setAttribute('aria-expanded', 'false');
        control.activeIndex = -1;
    }

    function syncTextFromValue(control) {
        const option = currentOption(control);
        if (control.textInput) {
            control.textInput.value = option ? option.label : (control.valueInput && control.valueInput.value ? control.valueInput.value + ' · previously selected' : '');
        }
    }

    function selectOption(control, option) {
        if (control.valueInput) control.valueInput.value = String(option.value || '');
        if (control.textInput) control.textInput.value = String(option.label || '');
        closeMenu(control);
    }

    function renderMenu(control, query) {
        if (!control.menu) return;
        const matches = visibleOptions(control, query);
        control.menu.innerHTML = matches.length
            ? matches.map(function (option, index) {
                return '<button type="button" class="inline-combobox-option" role="option" data-index="' + index + '">' + escapeHtml(option.label) + '</button>';
            }).join('')
            : '<div class="inline-combobox-empty">No matches found</div>';
        control.menu.hidden = false;
        if (control.textInput) control.textInput.setAttribute('aria-expanded', 'true');
        control.menu.querySelectorAll('[data-index]').forEach(function (button) {
            button.addEventListener('mousedown', function (event) { event.preventDefault(); });
            button.addEventListener('click', function () {
                const option = matches[Number(button.getAttribute('data-index'))] || null;
                if (option) selectOption(control, option);
            });
        });
    }

    channelControls.forEach(function (control) {
        if (!control.textInput) return;
        control.textInput.addEventListener('focus', function () {
            window.clearTimeout(control.closeTimer);
            control.textInput.select();
            renderMenu(control, '');
        });
        control.textInput.addEventListener('input', function () {
            control.activeIndex = -1;
            renderMenu(control, control.textInput.value);
        });
        control.textInput.addEventListener('blur', function () {
            control.closeTimer = window.setTimeout(function () {
                syncTextFromValue(control);
                closeMenu(control);
            }, 120);
        });
        control.textInput.addEventListener('keydown', function (event) {
            const matches = visibleOptions(control, control.textInput.value);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (control.menu.hidden) renderMenu(control, control.textInput.value);
                control.activeIndex = Math.min(matches.length - 1, control.activeIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (control.menu.hidden) renderMenu(control, control.textInput.value);
                control.activeIndex = Math.max(0, control.activeIndex - 1);
            } else if (event.key === 'Enter') {
                if (!control.menu.hidden && matches[control.activeIndex]) {
                    event.preventDefault();
                    selectOption(control, matches[control.activeIndex]);
                }
            } else if (event.key === 'Escape') {
                syncTextFromValue(control);
                closeMenu(control);
            }
        });
    });

    fetch('api/discord_lookup.php?type=channels', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Discord-Lookup-Token': discordLookupToken
        }
    })
        .then(function (response) { return response.json().then(function (data) { return { ok: response.ok, data: data }; }); })
        .then(function (payload) {
            if (!payload.ok) throw new Error(payload.data && payload.data.error ? payload.data.error : 'Could not load channels.');
            const items = Array.isArray(payload.data.items) ? payload.data.items : [];
            const options = [{ value: '', label: 'No channel selected', search: 'none blank default' }].concat(items.map(function (item) {
                item = item || {};
                const name = item.name ? String(item.name) : String(item.id || '');
                const typeLabel = channelTypeLabel(item.type);
                return {
                    value: String(item.id || ''),
                    label: (Number(item.type) === 4 ? '' : '#') + name + ' · ' + typeLabel,
                    search: [name, item.id || '', typeLabel, item.parent_id || ''].join(' ')
                };
            }).filter(function (option) { return option.value !== ''; }));

            channelControls.forEach(function (control) {
                control.options = options.slice();
                const selected = control.valueInput ? String(control.valueInput.value || '') : '';
                if (selected && !control.options.some(function (option) { return option.value === selected; })) {
                    control.options.push({ value: selected, label: selected + ' · previously selected', search: selected });
                }
                syncTextFromValue(control);
                if (control.status) control.status.textContent = items.length ? 'Loaded ' + items.length + ' channels. Type in the field to search.' : 'No channels found.';
            });
        })
        .catch(function (err) {
            channelControls.forEach(function (control) {
                control.options = [{ value: '', label: 'No channel selected', search: 'none blank default' }];
                syncTextFromValue(control);
                if (control.status) control.status.textContent = 'Could not load channels automatically: ' + (err.message || 'Discord lookup failed.');
            });
        });
})();
</script>
<?php renderFooter(); ?>
