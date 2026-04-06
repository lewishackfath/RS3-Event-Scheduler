<?php
declare(strict_types=1);

$localDate = $formValues['event_date'] ?? '';
$localTime = $formValues['event_time'] ?? '';
?>
<div class="card">
    <form method="post">
        <div class="grid">
            <div class="field">
                <label for="event_name">Event Name</label>
                <input type="text" id="event_name" name="event_name" value="<?= e($formValues['event_name'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="host_name">Event Host</label>
                <input type="text" id="host_name" name="host_name" value="<?= e($formValues['host_name'] ?? '') ?>">
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
                <input type="number" id="duration_minutes" name="duration_minutes" min="0" value="<?= e($formValues['duration_minutes'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="discord_channel_id">Discord Channel ID</label>
                <input type="text" id="discord_channel_id" name="discord_channel_id" value="<?= e($formValues['discord_channel_id'] ?? appConfig()['clan']['default_discord_channel_id']) ?>">
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
